<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Auth\GuestUserService;
use App\Service\Auth\RefreshCookieFactory;
use App\Service\Auth\RefreshTokenService;
use App\Service\Oauth\InvalidOauthStateException;
use App\Service\Oauth\OauthProviderRegistry;
use App\Service\Oauth\OauthStateStore;
use App\Service\Oauth\SocialIdentityResolver;
use App\Service\Oauth\UnknownOauthProviderException;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Мультипровайдерный OAuth-логин (Google/GitHub/Yandex/VK) — фундамент эпика
 * oauth-00. Провайдер-агностичные start/callback: конкретные адаптеры приходят
 * в oauth-02…04 через {@see OauthProviderRegistry}, контроллер не меняется.
 *
 * Сессия выдаётся ТЕМ ЖЕ механизмом, что и Telegram-логин
 * ({@see TelegramLoginController}): JWT в URL НЕ уходит — ставим refresh-cookie
 * ({@see RefreshTokenService::issueFamily()}), SPA берёт access-токен через
 * POST /auth/refresh. Плюс тот же guest-merge ({@see GuestUserService::mergeInto()}).
 *
 * Под firewall `auth` (^/api/v1/auth, security: false) — start/callback публичны.
 */
#[Route('/api/v1/auth/oauth')]
class OauthController extends AbstractController
{
    public function __construct(
        private readonly OauthProviderRegistry $providers,
        private readonly OauthStateStore $stateStore,
        private readonly SocialIdentityResolver $resolver,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RefreshCookieFactory $refreshCookie,
        private readonly GuestUserService $guestUsers,
        private readonly GuestTokenService $guestTokens,
        private readonly GuestCookieFactory $guestCookie,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/{provider}/start', methods: ['GET'], requirements: ['provider' => '[a-z0-9]+'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Начать OAuth-логин: редирект на authorize-URL провайдера', security: [])]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'google'))]
    #[OA\Response(response: 302, description: 'Редирект на страницу авторизации провайдера')]
    #[OA\Response(response: 404, description: 'Провайдер неизвестен/не сконфигурирован')]
    public function start(string $provider): Response
    {
        $adapter = $this->resolveProvider($provider);

        // PKCE (VK ID): генерируем code_verifier, кладём в state-store, прокидываем
        // в authorize-URL (как code_challenge внутри адаптера). Иначе — null.
        $codeVerifier = $adapter->usesPkce() ? $this->newCodeVerifier() : null;
        $state        = $this->stateStore->mint($provider, $codeVerifier);

        return new RedirectResponse($adapter->getAuthorizationUrl($state, $codeVerifier));
    }

    #[Route('/{provider}/callback', methods: ['GET'], requirements: ['provider' => '[a-z0-9]+'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Callback OAuth-провайдера: выдать сессию', security: [])]
    #[OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'google'))]
    #[OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'state', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 302, description: 'Успех → редирект на / (refresh-cookie выставлена); ошибка → /login?oauth_error=...')]
    #[OA\Response(response: 404, description: 'Провайдер неизвестен/не сконфигурирован')]
    public function callback(string $provider, Request $request): Response
    {
        $adapter = $this->resolveProvider($provider);

        // 1. Одноразовый state (CSRF) — гасим атомарно; невалидный → отбой.
        try {
            $stateData = $this->stateStore->consume((string) $request->query->get('state', ''), $provider);
        } catch (InvalidOauthStateException) {
            return $this->errorRedirect('state');
        }

        // 2. Обмен code → нормализованный профиль. Любая ошибка провайдера/сети —
        // редирект на страницу логина с маркером (страница придёт в oauth-05).
        try {
            $info = $adapter->fetchUserInfo($request->query->all(), $stateData->codeVerifier);
        } catch (\Throwable $e) {
            $this->logger->warning('OAuth exchange failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $this->errorRedirect('exchange');
        }

        // 3. link/create User + SocialIdentity.
        try {
            $user = $this->resolver->findOrCreateUser($provider, $info);
        } catch (\Throwable $e) {
            $this->logger->error('OAuth user resolve failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $this->errorRedirect('internal');
        }

        // 4. Merge guest-истории (тот же сервис, что Telegram-callback).
        $guestClear = $this->mergeGuestHistory($request, $user);

        // 5. Сессия — refresh-cookie (JWT в URL НЕ уходит), редирект в приложение.
        $response = new RedirectResponse('/');
        $response->headers->setCookie($this->refreshCookie->create($this->refreshTokens->issueFamily($user)));
        if ($guestClear !== null) {
            $response->headers->setCookie($guestClear);
        }

        return $response;
    }

    private function resolveProvider(string $provider): \App\Service\Oauth\OauthProviderInterface
    {
        try {
            return $this->providers->get($provider);
        } catch (UnknownOauthProviderException) {
            throw new NotFoundHttpException(sprintf('Unknown OAuth provider: %s', $provider));
        }
    }

    private function errorRedirect(string $reason): RedirectResponse
    {
        // Страница /login (кнопки провайдеров + разбор oauth_error) — oauth-05.
        return new RedirectResponse('/login?oauth_error=' . $reason);
    }

    /**
     * Перепривязка guest-истории при логине — копия семантики Telegram-callback:
     * валидный guest_id → mergeInto + гашение cookie; иначе null.
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

    /**
     * PKCE code_verifier (RFC 7636): 43–128 симв. из unreserved-набора.
     * base64url(32 байта) = 43 симв. — валидный verifier.
     */
    private function newCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
