<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная веб-страница загрузки и конвертации файлов.
 *
 * Отдаёт SPA-подобную страницу на Alpine.js + HTMX (CDN). Аноним конвертит без
 * логина (guest-cookie); ai/video требуют входа через Telegram bot-login
 * (magic-link). Фронт получает deep-link из POST /api/v1/auth/telegram/start,
 * поэтому имя бота на странице не нужно.
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('conversion/index.html.twig');
    }
}
