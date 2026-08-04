<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Веб-страницы панели администратора (Twig + HTMX + Alpine).
 *
 * Option B (JSON-API + client-side render): страницы — ОТКРЫТЫЕ оболочки,
 * достижимые обычной навигацией браузера (Bearer при навигации не передаётся),
 * секретных данных не содержат. Единственная реальная граница безопасности —
 * `^/api/v1/admin` (JWT, ROLE_ADMIN). На страницах — client-guard (см.
 * base.html.twig): тянет access-JWT через /api/v1/auth/refresh, при отсутствии
 * ROLE_ADMIN редиректит на `/`.
 *
 * Каждая секция — отдельный GET-роут; JSON-API не трогаем.
 */
class AdminController extends AbstractController
{
    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    #[Route('/admin/users', name: 'admin_users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->render('admin/users_page.html.twig');
    }

    #[Route('/admin/queues', name: 'admin_queues', methods: ['GET'])]
    public function queues(): Response
    {
        return $this->render('admin/queues_page.html.twig');
    }

    #[Route('/admin/workers', name: 'admin_workers', methods: ['GET'])]
    public function workers(): Response
    {
        return $this->render('admin/workers_page.html.twig');
    }

    #[Route('/admin/logs', name: 'admin_logs', methods: ['GET'])]
    public function logs(): Response
    {
        return $this->render('admin/logs_page.html.twig');
    }

    #[Route('/admin/conversions', name: 'admin_conversions', methods: ['GET'])]
    public function conversions(): Response
    {
        return $this->render('admin/conversions_page.html.twig');
    }

    #[Route('/admin/examples', name: 'admin_examples', methods: ['GET'])]
    public function examples(): Response
    {
        return $this->render('admin/examples_page.html.twig');
    }
}
