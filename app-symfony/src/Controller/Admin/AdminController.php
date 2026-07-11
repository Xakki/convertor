<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Веб-страница панели администратора (Twig + HTMX + Alpine).
 *
 * Option B (JSON-API + client-side render): страница — ОТКРЫТАЯ оболочка,
 * достижимая обычной навигацией браузера (Bearer при навигации не передаётся),
 * секретных данных не содержит. Единственная реальная граница безопасности —
 * `^/api/v1/admin` (JWT, ROLE_ADMIN). На странице — client-guard (см.
 * base.html.twig): тянет access-JWT через /api/v1/auth/refresh, при отсутствии
 * ROLE_ADMIN редиректит на `/`. Дизайн портирован из app-front/admin/index.html.
 * Панели (stats/users/queues/logs/toggle) — отдельные подзадачи эпика.
 */
class AdminController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }
}
