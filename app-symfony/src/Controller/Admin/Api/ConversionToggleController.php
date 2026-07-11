<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Service\Conversion\ConversionRegistry;
use App\Service\Conversion\ConversionToggleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Тумблер конвертаций admin-панели (эпик admin-panel, подзадача conv-toggle).
 *
 * Гранулярность = пара (from, to): реестр так и выбирает конвертор. Список
 * известных пар берём из реестра ({@see ConversionRegistry::getSupportedFormats()})
 * и накладываем состояние enabled из {@see ConversionToggleService}. Реестр
 * остаётся toggle-слепым — отключённую пару всегда можно включить обратно.
 *
 * Реальная граница — ROLE_ADMIN на JWT-firewall (Option B): не-админ 403.
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class ConversionToggleController extends AbstractController
{
    public function __construct(
        private readonly ConversionRegistry $registry,
        private readonly ConversionToggleService $toggles,
    ) {
    }

    #[Route('/conversions-toggle', name: 'admin_api_conversions_toggle_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(
            fn (array $f): array => [
                'from'     => $f['from'],
                'to'       => $f['to'],
                'category' => $f['category'],
                'isAi'     => $f['isAi'],
                'enabled'  => $this->toggles->isEnabled($f['from'], $f['to']),
            ],
            $this->registry->getSupportedFormats(),
        );

        return $this->json(['items' => $items]);
    }

    #[Route('/conversions-toggle', name: 'admin_api_conversions_toggle_set', methods: ['POST'])]
    public function set(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?: [];

        $from    = is_string($body['from'] ?? null) ? strtolower(trim($body['from'])) : '';
        $to      = is_string($body['to'] ?? null) ? strtolower(trim($body['to'])) : '';
        $enabled = (bool) ($body['enabled'] ?? false);

        if ($from === '' || $to === '') {
            return $this->json(['error' => 'from и to обязательны'], Response::HTTP_BAD_REQUEST);
        }

        // Только известная реестру пара — чтобы не плодить мусорные ряды.
        if (! $this->registry->isSupported($from, $to)) {
            return $this->json(['error' => 'Неизвестная конвертация'], Response::HTTP_NOT_FOUND);
        }

        $this->toggles->setEnabled($from, $to, $enabled);

        return $this->json(['from' => $from, 'to' => $to, 'enabled' => $enabled]);
    }
}
