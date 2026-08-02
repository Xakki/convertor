<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Auth\TelegramLinkCodeStore;
use App\Service\Auth\TelegramLinkNonceCookieFactory;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Привязка Telegram к текущему залогиненному User (CNV-59).
 *
 * Зеркало login pairing+poll, но:
 *  - требует ROLE_USER (не guest); nonce bound к UserId при mint;
 *  - poll НЕ выдаёт refresh-cookie и НЕ переключает сессию;
 *  - webhook `link:` пишет telegram_id на сохранённый UserId (без findOrCreateUser).
 *
 * Firewall: `auth_telegram_link` (JWT), см. security.yaml.
 */
#[Route('/api/v1/auth/telegram/link')]
class TelegramLinkController extends AbstractController
{
    public function __construct(
        private readonly TelegramLinkCodeStore $codeStore,
        private readonly TelegramLinkNonceCookieFactory $nonceCookie,
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private readonly string $telegramBotUsername,
        #[Autowire(service: 'limiter.anon_telegram_poll')]
        private readonly RateLimiterFactory $pollLimiter,
    ) {
    }

    #[Route('/start', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Инициировать привязку Telegram к текущему User')]
    #[OA\Response(
        response: 200,
        description: 'Публичный код + deep-link (+ httpOnly-cookie tg_link_nonce)',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'deep_link', type: 'string', example: 'https://t.me/anyconvertor_bot?start=link_<code>'),
            new OA\Property(property: 'expires_in', type: 'integer', example: 300),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Не аутентифицирован')]
    #[OA\Response(response: 403, description: 'Гость / нет ROLE_USER')]
    public function start(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        if ($user->isGuest()) {
            return $this->json(
                ['error' => 'auth_required', 'message' => 'Привязка Telegram доступна только зарегистрированным пользователям.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $userId = $user->getId();
        \assert($userId !== null);

        $minted = $this->codeStore->mint($userId);

        $response = $this->json([
            'code'       => $minted['code'],
            'deep_link'  => 'https://t.me/' . $this->telegramBotUsername . '?start=link_' . $minted['code'],
            'expires_in' => $this->codeStore->ttl(),
        ]);
        $response->headers->setCookie($this->nonceCookie->create($minted['nonce']));

        return $response;
    }

    #[Route('/poll', methods: ['GET'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Get(summary: 'Опрос статуса привязки Telegram (исходная вкладка)')]
    #[OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'linked — telegram_id привязан, сессия не меняется')]
    #[OA\Response(response: 204, description: 'pending — апрува в боте ещё нет')]
    #[OA\Response(response: 403, description: 'nonce mismatch / guest / чужой code')]
    #[OA\Response(response: 409, description: 'telegram_id уже занят другим User')]
    #[OA\Response(response: 400, description: 'Нет code/cookie')]
    #[OA\Response(response: 410, description: 'Код истёк / уже погашен')]
    #[OA\Response(response: 429, description: 'Слишком много запросов')]
    public function poll(Request $request, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        if ($user->isGuest()) {
            return $this->json(
                ['error' => 'auth_required', 'message' => 'Привязка Telegram доступна только зарегистрированным пользователям.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $limit = $this->pollLimiter->create((string) $request->getClientIp())->consume(1);
        if (! $limit->isAccepted()) {
            return $this->json(
                ['error' => 'Too many requests, please try later'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $code  = (string) $request->query->get('code', '');
        $nonce = (string) $request->cookies->get(TelegramLinkNonceCookieFactory::NAME, '');

        if ($code === '' || $nonce === '') {
            return $this->json(['error' => 'code and tg_link_nonce required'], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->codeStore->redeem($code, $nonce);

        if ($result['status'] === TelegramLinkCodeStore::STATUS_PENDING) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($result['status'] === TelegramLinkCodeStore::STATUS_MISMATCH) {
            $response = $this->json(['error' => 'mismatch'], Response::HTTP_FORBIDDEN);
            $response->headers->setCookie($this->nonceCookie->clear());

            return $response;
        }

        $currentUserId = $user->getId();
        // Код привязан к другому User → не подтверждаем чужую привязку в этой сессии.
        if ($result['userId'] !== null && $result['userId'] !== $currentUserId) {
            $response = $this->json(
                ['error' => 'forbidden', 'message' => 'Код привязки принадлежит другому пользователю.'],
                Response::HTTP_FORBIDDEN,
            );
            $response->headers->setCookie($this->nonceCookie->clear());

            return $response;
        }

        if ($result['status'] === TelegramLinkCodeStore::STATUS_COLLISION) {
            $response = $this->json(
                [
                    'error'   => 'telegram_already_linked',
                    'message' => 'Этот Telegram уже привязан к другому аккаунту Convertor.',
                ],
                Response::HTTP_CONFLICT,
            );
            $response->headers->setCookie($this->nonceCookie->clear());

            return $response;
        }

        if ($result['status'] !== TelegramLinkCodeStore::STATUS_AUTHORIZED) {
            $response = $this->json(['error' => 'expired'], Response::HTTP_GONE);
            $response->headers->setCookie($this->nonceCookie->clear());

            return $response;
        }

        // Успех: telegram_id уже записан webhook'ом. Сессию НЕ трогаем.
        $response = $this->json(['status' => 'linked']);
        $response->headers->setCookie($this->nonceCookie->clear());

        return $response;
    }
}
