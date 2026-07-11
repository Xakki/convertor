<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Service\Admin\QueueStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Мониторинг очередей admin-панели (эпик admin-panel, подзадача queues).
 *
 * Отдаёт размеры стримов `conv.<type>`, dead-letter и мульти-сигналы зависших
 * задач одним JSON'ом; UI поллит клиентом (Alpine + window.admin.fetch). Данные
 * — из `metrics_exporter` (Prometheus-sidecar) + БД; недоступность exporter'а не
 * роняет эндпоинт (`exporterAvailable=false`, HTTP 200). Реальная граница —
 * ROLE_ADMIN на JWT-firewall (Option B): для не-админа 403.
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class QueueController extends AbstractController
{
    public function __construct(
        private readonly QueueStatsProvider $queues,
    ) {
    }

    #[Route('/queues', name: 'admin_api_queues', methods: ['GET'])]
    public function queues(): JsonResponse
    {
        return $this->json($this->queues->collect());
    }
}
