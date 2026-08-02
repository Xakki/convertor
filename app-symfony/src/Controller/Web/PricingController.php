<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Страница тарифов и prepaid top-up (CNV-59).
 *
 * Открытая оболочка (как DashboardController): auth клиентский через Alpine.
 * Подписки Basic/Pro — CTA «скоро»; живое пополнение — Telegram packs API.
 */
class PricingController extends AbstractController
{
    #[Route('/pricing', name: 'app_pricing', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('pricing/index.html.twig');
    }
}
