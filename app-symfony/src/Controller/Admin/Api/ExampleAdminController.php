<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Entity\Conversion;
use App\Entity\Example;
use App\Enum\ConversionStatus;
use App\Repository\ConversionRepository;
use App\Repository\ExampleRepository;
use App\Service\Examples\ExamplePromotionService;
use App\Service\Storage\S3Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-панель управления «живыми примерами» лендинга (карточка
 * admin-managed-examples, подход A: промо СУЩЕСТВУЮЩЕЙ конвертации, не аплоад с
 * нуля). Реальная граница — ROLE_ADMIN на JWT-firewall (Option B), как у
 * остальных admin-API; CSRF-токены НЕ используются — тот же паттерн, что и у
 * {@see UserController}/{@see ConversionToggleController}/{@see DlqController}:
 * это stateless Bearer-JWT API (никакой ambient cookie-аутентификации), CSRF
 * структурно неприменим (браузер не проставляет чужой Authorization-заголовок).
 *
 * `PAGE_SIZE`/пагинация кандидатов — тот же паттерн, что {@see UserController::list()}.
 */
#[Route('/api/v1/admin/examples')]
#[IsGranted('ROLE_ADMIN')]
final class ExampleAdminController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly ExampleRepository $examples,
        private readonly ConversionRepository $conversions,
        private readonly ExamplePromotionService $promotion,
        private readonly S3Storage $s3,
    ) {
    }

    /** Текущие примеры витрины (порядок = порядок отображения на лендинге). */
    #[Route('', name: 'admin_api_examples_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map($this->serializeExample(...), $this->examples->findAllOrdered());

        return $this->json(['items' => $items]);
    }

    /** Кандидаты на промо: завершённые конвертации с результатом. */
    #[Route('/candidates', name: 'admin_api_examples_candidates', methods: ['GET'])]
    public function candidates(Request $request): JsonResponse
    {
        $q      = $request->query->get('q');
        $q      = is_string($q) ? $q : null;
        $page   = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $result = $this->conversions->findPromotableCandidates($q, self::PAGE_SIZE, $offset);
        $total  = $result['total'];

        return $this->json([
            'items'    => array_map($this->serializeCandidate(...), $result['items']),
            'page'     => $page,
            'pageSize' => self::PAGE_SIZE,
            'total'    => $total,
            'pages'    => $total > 0 ? (int) ceil($total / self::PAGE_SIZE) : 1,
        ]);
    }

    #[Route('/{conversionId}/promote', name: 'admin_api_examples_promote', methods: ['POST'], requirements: ['conversionId' => '\d+'])]
    public function promote(int $conversionId): JsonResponse
    {
        $conversion = $this->conversions->find($conversionId);
        if ($conversion === null) {
            return $this->json(['error' => 'conversion_not_found'], Response::HTTP_NOT_FOUND);
        }

        if ($conversion->getStatus() !== ConversionStatus::Completed || $conversion->getOutputFile() === null) {
            return $this->json([
                'error'   => 'not_completed',
                'message' => 'Только завершённые конвертации с результатом можно сделать примером',
            ], Response::HTTP_CONFLICT);
        }

        // Gate BEFORE copying (симметрично DlqController::requeue's input_gone):
        // 24ч-очистка могла уже вычистить исходник/результат конвертации.
        if (! $this->s3->objectExists($this->s3->inputsBucket(), $conversion->getInputFile()->getStoragePath())
            || ! $this->s3->objectExists($this->s3->resultsBucket(), $conversion->getOutputFile()->getStoragePath())) {
            return $this->json([
                'error'   => 'files_gone',
                'message' => 'Исходник или результат этой конвертации уже вычищены (24ч-очистка) — промо невозможно',
            ], Response::HTTP_CONFLICT);
        }

        $example = $this->promotion->promote($conversion);

        return $this->json($this->serializeExample($example), Response::HTTP_CREATED);
    }

    #[Route('/{id}/remove', name: 'admin_api_examples_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(int $id): JsonResponse
    {
        $example = $this->examples->find($id);
        if ($example === null) {
            return $this->json(['error' => 'example_not_found'], Response::HTTP_NOT_FOUND);
        }

        $this->promotion->remove($example);

        return $this->json(['ok' => true, 'id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExample(Example $example): array
    {
        return [
            'id'           => $example->getId(),
            'category'     => $example->getCategory(),
            'from'         => $example->getFromFormat(),
            'to'           => $example->getToFormat(),
            'filename'     => $example->getFilename(),
            'mime'         => $example->getMime(),
            'size'         => $example->getSize(),
            'previewable'  => $example->isPreviewable(),
            'sourceFormat' => $example->getSourceFormat(),
            'sortOrder'    => $example->getSortOrder(),
            'conversionId' => $example->getConversion()?->getId(),
            'createdAt'    => $example->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCandidate(Conversion $c): array
    {
        $output = $c->getOutputFile();

        return [
            'id'         => $c->getId(),
            'userEmail'  => $c->getUser()->getEmail(),
            'category'   => $c->getCategory()->value,
            'from'       => $c->getFromFormat(),
            'to'         => $c->getToFormat(),
            'resultSize' => $output?->getSizeBytes(),
            'resultMime' => $output?->getMimeType(),
            'createdAt'  => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
