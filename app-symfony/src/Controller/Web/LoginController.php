<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная страница `/login` — общая точка входа для соцвхода
 * (Google/GitHub/Yandex/VK, фундамент oauth-01…04) плюс переиспользованная
 * кнопка Telegram bot-login (тот же magic-link флоу, что на `/`, см.
 * `conversion/index.html.twig`).
 *
 * Кнопки провайдеров рендерятся ВСЕГДА, без гейтинга по «сконфигурирован ли
 * client_id»: {@see \App\Service\Oauth\OauthProviderRegistry} регистрирует
 * все 4 адаптера тегом `app.oauth_provider` независимо от того, пуст ли
 * env `<PROVIDER>_OAUTH_CLIENT_ID` (см. config/services.yaml) — `has()`
 * поэтому не отличает «сконфигурирован» от «плейсхолдер пуст», и гейтинг
 * через реестр был бы фиктивным. Оставлено как явное решение oauth-05
 * (см. Execution Log карточки); при желании фильтровать нужно будет сначала
 * завести признак «сконфигурирован» в самих провайдерах/реестре.
 *
 * Под firewall `main` (lazy, catch-all) — доступна анонимно, ни один
 * access_control в security.yaml не матчит `^/login`.
 */
class LoginController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('auth/login.html.twig', [
            'oauthError' => $request->query->get('oauth_error'),
        ]);
    }
}
