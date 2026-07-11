<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Entity\Conversion;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Лог конвертаций admin-панели (эпик admin-panel, подзадача logs).
 *
 * DB-backed searchable/filterable view по бизнес-состоянию `Conversion`. Рантайм-
 * логи воркеров (стектрейсы, кросс-сервис) в БД не тянем — они в Graylog; UI даёт
 * линк-аут по conversion id. Реальная граница — ROLE_ADMIN на JWT-firewall
 * (Option B): для не-админа 403, как и у остальных admin-API.
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class ConversionLogController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly ConversionRepository $conversions,
        // Базовый URL веб-интерфейса Graylog для линк-аута из UI. Пусто по
        // умолчанию (плейсхолдер, не секрет) → в API meta отдаём пустую строку,
        // UI прячет ссылку. Задаётся env GRAYLOG_URL.
        private readonly string $graylogUrl = '',
    ) {
    }

    #[Route('/conversions', name: 'admin_api_conversions', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query  = $request->query;
        $page   = max(1, (int) $query->get('page', '1'));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $filters = [
            'status'     => ConversionStatus::tryFrom((string) $query->get('status', '')),
            'user'       => $this->str($query->get('user')),
            'fromFormat' => $this->str($query->get('fromFormat')),
            'toFormat'   => $this->str($query->get('toFormat')),
            'category'   => FileCategory::tryFrom((string) $query->get('category', '')),
            'isAi'       => $this->bool($query->get('isAi')),
            'isOcr'      => $this->bool($query->get('isOcr')),
            'from'       => $this->day($query->get('from')),
            'to'         => $this->day($query->get('to')),
        ];

        $result = $this->conversions->searchPaginated($filters, self::PAGE_SIZE, $offset);
        $total  = $result['total'];

        return $this->json([
            'items'    => array_map($this->serialize(...), $result['items']),
            'page'     => $page,
            'pageSize' => self::PAGE_SIZE,
            'total'    => $total,
            'pages'    => $total > 0 ? (int) ceil($total / self::PAGE_SIZE) : 1,
            // Конфигурируемый база-URL Graylog (пусто = ссылка скрыта в UI).
            'graylogUrl' => $this->graylogUrl,
        ]);
    }

    /** Непустая trim'нутая строка либо null. */
    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** `1/0/true/false` → bool; всё прочее → null (не фильтровать). */
    private function bool(mixed $value): ?bool
    {
        return match (is_string($value) ? strtolower($value) : $value) {
            '1', 'true'  => true,
            '0', 'false' => false,
            default      => null,
        };
    }

    /** `Y-m-d` → начало суток (immutable); мусор/пусто → null. */
    private function day(mixed $value): ?\DateTimeImmutable
    {
        $value = $this->str($value);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value . ' 00:00:00');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Conversion $conversion): array
    {
        $user   = $conversion->getUser();
        $output = $conversion->getOutputFile();

        return [
            'id'           => $conversion->getId(),
            'user'         => ['id' => $user->getId(), 'email' => $user->getEmail()],
            'status'       => $conversion->getStatus()->value,
            'fromFormat'   => $conversion->getFromFormat(),
            'toFormat'     => $conversion->getToFormat(),
            'category'     => $conversion->getCategory()->value,
            'isAi'         => $conversion->isAi(),
            'isOcr'        => $conversion->isOcr(),
            'processingMs' => $conversion->getProcessingMs(),
            // errorMessage присутствует в каждой строке (не только у Failed) —
            // тумблер «только ошибки» = фильтр status=failed.
            'errorMessage' => $conversion->getErrorMessage(),
            'inputKey'     => $conversion->getInputFile()->getStoragePath(),
            'outputKey'    => $output?->getStoragePath(),
            'createdAt'    => $conversion->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt'    => $conversion->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
