<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Личный кабинет (view-only): история конвертаций, квоты/лимиты, данные
 * аккаунта. Повтор/удаление конверсий — вне скоупа (отдельная карточка
 * dashboard-conversion-actions).
 *
 * Как и `/` (HomeController), это открытая оболочка: firewall `main` не
 * имеет authenticator'а, поэтому серверный гейт по роли здесь невозможен —
 * вся auth-логика (аккаунт vs гость vs аноним) клиентская, см.
 * `partials/_dashboard_app_script.html.twig` (тот же паттерн, что и
 * `headerNav()`/`converterApp()`: silent POST /api/v1/auth/refresh →
 * GET /api/v1/me).
 */
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
