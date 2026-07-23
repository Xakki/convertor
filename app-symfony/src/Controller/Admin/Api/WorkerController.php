<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Service\Admin\WorkerStatsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Обзор зарегистрированных воркеров admin-панели (registry-07, финальный шаг
 * эпика `registry-00-self-registration`).
 *
 * Отдаёт список capability-строк (`worker_capabilities`) одним JSON'ом; UI
 * поллит клиентом (Alpine + `window.admin.fetch`), как `/admin/queues`.
 * Реальная граница — ROLE_ADMIN на JWT-firewall (Option B): для не-админа 403.
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class WorkerController extends AbstractController
{
    public function __construct(
        private readonly WorkerStatsProvider $stats,
    ) {
    }

    #[Route('/workers', name: 'admin_api_workers', methods: ['GET'])]
    public function workers(): JsonResponse
    {
        return $this->json($this->stats->collect());
    }
}
