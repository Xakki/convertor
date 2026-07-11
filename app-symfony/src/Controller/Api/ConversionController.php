<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\AuthRequiredException;
use App\Exception\ConversionDisabledException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionRegistry;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1')]
class ConversionController extends AbstractController
{
    public function __construct(
        private readonly ConversionManager $conversionManager,
        private readonly ConversionRegistry $registry,
        private readonly ConversionRepository $conversionRepository,
        private readonly QuotaService $quotaService,
        private readonly S3Storage $s3,
        #[Autowire(service: 'limiter.anon_convert')]
        private readonly RateLimiterFactory $anonConvertLimiter,
    ) {
    }

    #[Route('/convert', methods: ['POST'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Post(
        summary: 'Поставить файл в очередь на конвертацию',
        description: 'Загружает файл (multipart/form-data) и создаёт задачу конвертации. Списывает квоту и ставит задачу в очередь.',
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['file', 'to_format'],
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'Исходный файл'),
                    new OA\Property(property: 'to_format', type: 'string', example: 'pdf', description: 'Целевой формат'),
                    new OA\Property(property: 'ocr', type: 'boolean', default: false, description: 'Использовать OCR (для неоднозначных пар, напр. pdf→txt)'),
                ],
            ),
        ),
    )]
    #[OA\Response(
        response: 202,
        description: 'Задача принята в обработку',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversion_id', type: 'integer', example: 123),
            new OA\Property(property: 'status', type: 'string', example: 'pending'),
        ]),
    )]
    #[OA\Response(response: 400, description: 'Некорректный запрос (нет файла / to_format)')]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 409, description: 'Конвертация отключена админом')]
    #[OA\Response(response: 413, description: 'Файл превышает лимит размера')]
    #[OA\Response(response: 415, description: 'Неподдерживаемый тип содержимого')]
    #[OA\Response(response: 422, description: 'Неподдерживаемая конвертация')]
    #[OA\Response(response: 429, description: 'Превышена квота / слишком много запросов')]
    public function convert(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $file     = $request->files->get('file');
        $toFormat = $request->request->get('to_format');
        $ocr      = $request->request->getBoolean('ocr');

        if ($file === null) {
            return $this->json(['error' => 'File required'], Response::HTTP_BAD_REQUEST);
        }

        if (! $toFormat) {
            return $this->json(['error' => 'to_format required'], Response::HTTP_BAD_REQUEST);
        }

        // ROLE_USER = полный логин; гость его не имеет (role_hierarchy даёт
        // залогиненному пройти guest-роуты, но НЕ наоборот).
        $privileged = $this->isGranted('ROLE_USER');

        // Rate-limit гостя по IP (залогиненные ограничены только квотой).
        if (! $privileged) {
            $limit = $this->anonConvertLimiter->create($request->getClientIp())->consume(1);
            if (! $limit->isAccepted()) {
                return $this->json(
                    ['error' => 'Too many anonymous conversions, please try later or log in'],
                    Response::HTTP_TOO_MANY_REQUESTS,
                );
            }
        }

        try {
            // createConversion now enqueues (dispatch) + charges quota internally,
            // so the whole charge→submit→enqueue path is atomic in one place.
            $conversion = $this->conversionManager->createConversion($user, $file, strtolower((string) $toFormat), $ocr, $privileged);

            return $this->json([
                'conversion_id' => $conversion->getId(),
                'status'        => $conversion->getStatus()->value,
            ], Response::HTTP_ACCEPTED);
        } catch (AuthRequiredException $e) {
            // Гейт ai/video для гостя.
            return $this->json(
                ['error' => 'auth_required', 'message' => $e->getMessage()],
                Response::HTTP_FORBIDDEN,
            );
        } catch (ConversionDisabledException $e) {
            // Пара отключена админом (валидна, но временно выключена) → 409.
            return $this->json(
                ['error' => 'conversion_disabled', 'message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TooManyRequestsHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (HttpException $e) {
            // Size (413) + content-type (415) gates from ConversionManager. The
            // specific catches above already handled 422/429; this maps the
            // remaining HTTP exceptions to their own status code.
            return $this->json(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    #[Route('/convert/{id}/status', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Статус задачи конвертации')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Текущий статус',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversion_id', type: 'integer', example: 123),
            new OA\Property(property: 'status', type: 'string', example: 'processing'),
            new OA\Property(property: 'error', type: 'string', nullable: true),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача не найдена')]
    public function status(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->conversionManager->getStatus($id, $user);

            return $this->json([
                'conversion_id' => $result->conversionId,
                'status'        => $result->status->value,
                'error'         => $result->errorMessage,
            ]);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/convert/{id}/download', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Скачать результат конвертации')]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID задачи', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Бинарный файл результата',
        content: new OA\MediaType(
            mediaType: 'application/octet-stream',
            schema: new OA\Schema(type: 'string', format: 'binary'),
        ),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    #[OA\Response(response: 404, description: 'Задача или файл результата не найдены')]
    public function download(int $id, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Conversion not found');
        }

        $outputFile = $conversion->getOutputFile();
        if ($outputFile === null) {
            return $this->json(['error' => 'Output file not available'], Response::HTTP_NOT_FOUND);
        }

        // storagePath holds the S3 object key for results; bucket is config-derived.
        return $this->s3->downloadResponse(
            $outputFile->getStoragePath(),
            $outputFile->getOriginalName(),
            $outputFile->getMimeType(),
        );
    }

    #[Route('/convert/history', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'История конвертаций пользователя')]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Кол-во записей (макс. 100)', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Parameter(name: 'offset', in: 'query', required: false, description: 'Смещение', schema: new OA\Schema(type: 'integer', default: 0))]
    #[OA\Response(
        response: 200,
        description: 'Список задач',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'from_format', type: 'string'),
                new OA\Property(property: 'to_format', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'is_ai', type: 'boolean'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            ])),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    public function history(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $limit  = min((int) $request->query->get('limit', 20), 100);
        $offset = (int) $request->query->get('offset', 0);

        $conversions = $this->conversionRepository->findByUser($user, $limit, $offset);

        return $this->json([
            'items' => array_map(
                static fn ($c) => [
                    'id'          => $c->getId(),
                    'from_format' => $c->getFromFormat(),
                    'to_format'   => $c->getToFormat(),
                    'status'      => $c->getStatus()->value,
                    'is_ai'       => $c->isAi(),
                    'created_at'  => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $conversions,
            ),
        ]);
    }

    #[Route('/formats', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Список поддерживаемых конвертаций', security: [])]
    #[OA\Response(
        response: 200,
        description: 'Матрица поддерживаемых пар форматов',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'formats', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'from', type: 'string', example: 'docx'),
                new OA\Property(property: 'to', type: 'string', example: 'pdf'),
                new OA\Property(property: 'category', type: 'string', example: 'document'),
                new OA\Property(property: 'isAi', type: 'boolean'),
            ])),
        ]),
    )]
    public function formats(): JsonResponse
    {
        return $this->json([
            'formats' => $this->registry->getSupportedFormats(),
        ]);
    }

    #[Route('/quota', methods: ['GET'])]
    #[OA\Tag(name: 'Conversion')]
    #[OA\Get(summary: 'Остаток квоты пользователя')]
    #[OA\Response(
        response: 200,
        description: 'Остаток дневной квоты',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'conversions', type: 'integer', description: '-1 = безлимит', example: 42),
            new OA\Property(property: 'ai_conversions', type: 'integer', description: '-1 = безлимит', example: 5),
            new OA\Property(property: 'plan', type: 'string', example: 'free'),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Требуется аутентификация')]
    public function quota(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $quota = $this->quotaService->getRemainingQuota($user);

        // Для гостя переопределяем: ai недоступен (0), план — "guest". Не полагаемся
        // на User.plan гостя (free-fallback дал бы ai_conversions:1).
        if (! $this->isGranted('ROLE_USER')) {
            $quota['ai_conversions'] = 0;
            $quota['plan']           = 'guest';
        }

        return $this->json($quota);
    }
}
