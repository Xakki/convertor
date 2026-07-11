<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Базовый admin-API под `^/api/v1/admin` (stateless JWT, firewall `api`).
 *
 * Реальные панели (stats/users/queues/logs/toggle) добавляют подзадачи эпика.
 * Здесь только ping — health-эндпоинт, подтверждающий рабочий ROLE_ADMIN-гейт
 * на JWT-firewall (для не-админа 403, для админа 200).
 */
#[Route('/api/v1/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminApiController extends AbstractController
{
    #[Route('/ping', name: 'admin_api_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return $this->json(['ok' => true]);
    }
}
