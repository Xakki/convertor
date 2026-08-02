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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Telegram bot-login по МОДЕЛИ PAIRING + POLL (same-device).
 *
 * Флоу: сайт зовёт /start → получает CODE + deep_link + httpOnly-cookie
 * `tg_login_nonce` в браузер-инициатор → пользователь жмёт «Войти» в боте
 * (webhook помечает CODE authorized) → исходная вкладка поллит
 * `GET /poll?code=...` с nonce-cookie → при совпадении выдаём refresh-cookie.
 * Под firewall `auth` (public).
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
        #[Autowire(service: 'limiter.anon_telegram_poll')]
        private readonly RateLimiterFactory $anonTelegramPollLimiter,
    ) {
    }

    #[Route('/start', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Инициировать Telegram bot-login (pairing + poll)', security: [])]
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

    #[Route('/poll', methods: ['GET'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Опрос статуса Telegram-логина (исходная вкладка)', security: [])]
    #[OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'authorized — refresh-cookie выставлена, код погашен')]
    #[OA\Response(response: 204, description: 'pending — апрува в боте ещё нет')]
    #[OA\Response(response: 403, description: 'nonce-cookie не совпал → fixation отбита')]
    #[OA\Response(response: 400, description: 'Нет code/cookie')]
    #[OA\Response(response: 410, description: 'Код истёк / уже погашен')]
    #[OA\Response(response: 429, description: 'Слишком много запросов')]
    public function poll(Request $request): Response
    {
        $limit = $this->anonTelegramPollLimiter->create((string) $request->getClientIp())->consume(1);
        if (! $limit->isAccepted()) {
            return $this->json(
                ['error' => 'Too many requests, please try later'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code  = (string) $request->query->get('code', '');
        $nonce = (string) $request->cookies->get(TelegramLoginNonceCookieFactory::NAME, '');

        // Нужны оба: публичный code и nonce-cookie браузера-инициатора.
        if ($code === '' || $nonce === '') {
            return $this->json(['error' => 'code and tg_login_nonce required'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->codeStore->redeem($code, $nonce);

        if ($result['status'] === TelegramLoginCodeStore::STATUS_PENDING) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($result['status'] === TelegramLoginCodeStore::STATUS_MISMATCH) {
            // nonce mismatch → не тот браузер (fixation отбита). Код НЕ сожжён.
            return $this->json(['error' => 'mismatch'], Response::HTTP_FORBIDDEN);
        }

        if ($result['status'] !== TelegramLoginCodeStore::STATUS_AUTHORIZED) {
            // expired / unknown → начать вход заново.
            return $this->json(['error' => 'expired'], Response::HTTP_GONE);
        }

        $user = $result['userId'] !== null ? $this->users->find($result['userId']) : null;
        if ($user === null || ! $user->isActive()) {
            return $this->json(['error' => 'expired'], Response::HTTP_GONE);
        }

        // Merge guest-истории (у poll есть и nonce-, и guest-cookie).
        $guestClear = $this->mergeGuestHistory($request, $user);

        // JWT фронту НЕ отдаём в теле — выставляем refresh-cookie; SPA тянет
        // access-token через POST /auth/refresh.
        $response = $this->json(['status' => 'authorized']);
        $response->headers->setCookie($this->refreshCookie->create($this->refreshTokens->issueFamily($user)));
        // Одноразовый nonce погашен (код тоже сожжён внутри redeem).
        $response->headers->setCookie($this->nonceCookie->clear());
        if ($guestClear !== null) {
            $response->headers->setCookie($guestClear);
        }

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
