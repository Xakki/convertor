<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Легал-страницы (home-06-legal-docs): privacy policy и terms of use.
 *
 * Статичный Twig-контент через i18n-ключи (namespace `legal.*`), НЕ CMS/БД —
 * MVP. Публичны как `/`: firewall `main` (config/packages/security.yaml) не
 * имеет authenticator'а и не гейтится access_control — доступны анонимно,
 * гостю и залогиненному одинаково.
 */
class LegalController extends AbstractController
{
    #[Route('/privacy', name: 'app_legal_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    #[Route('/terms', name: 'app_legal_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }
}
