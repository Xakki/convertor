<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Auth\GuestUserService;
use App\Service\Auth\RefreshCookieFactory;
use App\Service\Auth\RefreshTokenService;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramLoginNonceCookieFactory;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Telegram bot-login по МОДЕЛИ MAGIC-LINK (same-device).
 *
 * Флоу: сайт зовёт /start → получает CODE + deep_link + httpOnly-cookie
 * `tg_login_nonce` в браузер-инициатор → пользователь жмёт «Войти» в боте
 * (webhook помечает CODE authorized + шлёт в чат magic-ссылку) → пользователь
 * открывает magic-ссылку `GET /callback?code=...` в ТОМ ЖЕ браузере → nonce-cookie
 * совпадает с сохранённым hash(nonce) → выдаём JWT + refresh-cookie и редиректим
 * в приложение. Под firewall `auth` (public).
 *
 * Nonce-привязка закрывает login-CSRF/session-fixation: атакующий, знающий
 * публичный CODE, не завершит вход (его браузер не несёт nonce-cookie → 403).
 */
#[Route('/api/v1/auth/telegram')]
class TelegramLoginController extends AbstractController
{
    public function __construct(
        private readonly TelegramLoginCodeStore $codeStore,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RefreshCookieFactory $refreshCookie,
        private readonly TelegramLoginNonceCookieFactory $nonceCookie,
        private readonly UserRepository $users,
        private readonly GuestUserService $guestUsers,
        private readonly GuestTokenService $guestTokens,
        private readonly GuestCookieFactory $guestCookie,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private readonly string $telegramBotUsername,
    ) {
    }

    #[Route('/start', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Инициировать Telegram bot-login (magic-link)', security: [])]
    #[OA\Response(
        response: 200,
        description: 'Публичный код + deep-link в бота (+ httpOnly-cookie tg_login_nonce)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'deep_link', type: 'string', example: 'https://t.me/anyconvertor_bot?start=<code>'),
            new OA\Property(property: 'expires_in', type: 'integer', example: 300),
        ]),
    )]
    public function start(): JsonResponse
    {
        $minted = $this->codeStore->mint();

        $response = $this->json([
            'code'       => $minted['code'],
            'deep_link'  => 'https://t.me/' . $this->telegramBotUsername . '?start=' . $minted['code'],
            'expires_in' => $this->codeStore->ttl(),
        ]);
        // Сырой nonce — только в httpOnly-cookie браузера-инициатора; в Redis лежит
        // лишь hash(nonce). Завершить вход сможет только этот браузер.
        $response->headers->setCookie($this->nonceCookie->create($minted['nonce']));

        return $response;
    }

    #[Route('/callback', methods: ['GET'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Завершение Telegram-логина (magic-ссылка из бота)', security: [])]
    #[OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 's', in: 'query', required: true, description: 'linkSecret из TG-чата', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 302, description: 'Успех → редирект на / залогиненным (refresh-cookie выставлена)')]
    #[OA\Response(response: 403, description: 'nonce-cookie ИЛИ linkSecret не совпал → fixation/takeover отбиты')]
    #[OA\Response(response: 400, description: 'Нет code/s/cookie, код неизвестен/не авторизован/истёк')]
    public function callback(Request $request): Response
    {
        $code       = (string) $request->query->get('code', '');
        $linkSecret = (string) $request->query->get('s', '');
        $nonce      = (string) $request->cookies->get(TelegramLoginNonceCookieFactory::NAME, '');

        // Нужны ВСЕ три: публичный code, linkSecret из TG-чата (query `s`) и
        // nonce-cookie браузера-инициатора. Любого нет → незавершаемая ссылка.
        if ($code === '' || $linkSecret === '' || $nonce === '') {
            return $this->failPage(Response::HTTP_BAD_REQUEST);
        }

        $result = $this->codeStore->redeem($code, $nonce, $linkSecret);

        if ($result['status'] === TelegramLoginCodeStore::STATUS_MISMATCH) {
            // nonce mismatch → не тот браузер (fixation отбита); linkSecret
            // mismatch → нет секрета из TG-чата (takeover отбит). Код НЕ сожжён.
            return $this->failPage(Response::HTTP_FORBIDDEN);
        }

        if ($result['status'] !== TelegramLoginCodeStore::STATUS_AUTHORIZED) {
            // pending / expired / unknown → начать вход заново.
            return $this->failPage(Response::HTTP_BAD_REQUEST);
        }

        $user = $result['userId'] !== null ? $this->users->find($result['userId']) : null;
        if ($user === null || ! $user->isActive()) {
            return $this->failPage(Response::HTTP_BAD_REQUEST);
        }

        // Merge guest-истории (у callback есть и nonce-, и guest-cookie).
        $guestClear = $this->mergeGuestHistory($request, $user);

        // JWT фронту НЕ отдаём в URL — выставляем refresh-cookie; SPA на загрузке
        // тянет access-token через POST /auth/refresh. Редирект на приложение.
        $response = new RedirectResponse('/');
        $response->headers->setCookie($this->refreshCookie->create($this->refreshTokens->issueFamily($user)));
        // Одноразовый nonce погашен (код тоже сожжён внутри redeem).
        $response->headers->setCookie($this->nonceCookie->clear());
        if ($guestClear !== null) {
            $response->headers->setCookie($guestClear);
        }

        return $response;
    }

    /**
     * Понятная страница провала логина (magic-ссылка недействительна). Гасим
     * nonce-cookie, чтобы протухший nonce не мешал следующей попытке.
     */
    private function failPage(int $status): Response
    {
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<title>Вход не завершён</title></head><body style="font-family:sans-serif;text-align:center;padding:3rem">'
            . '<h1>Ссылка недействительна</h1>'
            . '<p>Начните вход заново на сайте и откройте новую ссылку на том же устройстве.</p>'
            . '<p><a href="/">Вернуться на сайт</a></p></body></html>';

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->headers->setCookie($this->nonceCookie->clear());

        return $response;
    }

    /**
     * Перепривязка guest-истории при логине (интеграция карты A).
     *
     * Читает cookie `guest_id`, проверяет HMAC-подпись через GuestTokenService.
     * При валидном guest — перевешивает все Conversion.user гостя на реального
     * User (GuestUserService::mergeInto) и возвращает cookie-гашение, чтобы
     * устаревшая guest-cookie не воскресила удалённого гостя. Иначе — null.
     */
    private function mergeGuestHistory(Request $request, User $user): ?Cookie
    {
        $signed = $request->cookies->get(GuestCookieFactory::NAME);
        if (! is_string($signed) || $signed === '') {
            return null;
        }

        $guestId = $this->guestTokens->verify($signed);
        if ($guestId === null) {
            return null;
        }

        $this->guestUsers->mergeInto($user, $guestId);

        return $this->guestCookie->clear();
    }
}
