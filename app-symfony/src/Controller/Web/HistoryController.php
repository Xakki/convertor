<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Страница истории конвертаций («Мои конвертации»).
 *
 * Открытая оболочка (как HomeController / DashboardController): firewall `main`
 * без authenticator'а, auth-логика клиентская в Alpine (`historyApp()`).
 * Залогиненный — JWT; гость — guest-cookie только при cookie_consent=accepted
 * (тот же контракт, что был у блока истории на `/`).
 */
class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('history/index.html.twig');
    }
}
