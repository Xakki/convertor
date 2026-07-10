<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UserRepository;
use App\Service\Auth\RefreshCookieFactory;
use App\Service\Auth\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly LoggerInterface $logger,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RefreshCookieFactory $refreshCookie,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/refresh', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(
        summary: 'Обновить access-токен по refresh-cookie',
        description: 'Использует httpOnly refresh-cookie; ротирует семейство refresh-токенов.',
        security: [],
    )]
    #[OA\Response(
        response: 200,
        description: 'Новый JWT-токен',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'token', type: 'string'),
        ]),
    )]
    #[OA\Response(response: 401, description: 'Недействительный refresh-токен')]
    public function refresh(Request $request): Response
    {
        $cookie = $request->cookies->get($this->refreshCookie->name());
        if (! is_string($cookie) || $cookie === '') {
            return $this->unauthorizedClearingCookie();
        }

        $result = $this->refreshTokens->rotate($cookie);
        if (! $result->valid || $result->userId === null) {
            return $this->unauthorizedClearingCookie();
        }

        $user = $this->users->find($result->userId);
        if ($user === null || ! $user->isActive()) {
            if ($user !== null) {
                // Deactivated account: kill the whole family, not just this token.
                $this->refreshTokens->revoke($cookie);
            }

            return $this->unauthorizedClearingCookie();
        }

        $response = $this->json(['token' => $this->jwtManager->create($user)]);
        if ($result->cookieValue !== null) {
            $response->headers->setCookie($this->refreshCookie->create($result->cookieValue));
        }

        return $response;
    }

    #[Route('/logout', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(summary: 'Выход: отзыв refresh-токена и очистка cookie', security: [])]
    #[OA\Response(response: 204, description: 'Успешный выход')]
    public function logout(Request $request): Response
    {
        $cookie = $request->cookies->get($this->refreshCookie->name());
        if (is_string($cookie) && $cookie !== '') {
            $this->refreshTokens->revoke($cookie);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($this->refreshCookie->clear());

        return $response;
    }

    private function unauthorizedClearingCookie(): JsonResponse
    {
        $response = $this->json(['error' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        $response->headers->setCookie($this->refreshCookie->clear());

        return $response;
    }

    #[Route('/sms/request', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(
        summary: 'Запросить SMS OTP (резервный метод)',
        description: 'Заглушка — полная интеграция SMSC в Phase 6.',
        security: [],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['phone'],
            properties: [new OA\Property(property: 'phone', type: 'string', example: '+79991234567')],
        ),
    )]
    #[OA\Response(response: 200, description: 'OTP отправлен')]
    #[OA\Response(response: 400, description: 'Не указан номер телефона')]
    public function smsRequest(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true) ?? [];
        $phone = $data['phone']                            ?? null;

        if (! $phone) {
            return $this->json(['error' => 'Phone number required'], Response::HTTP_BAD_REQUEST);
        }

        // OTP generation and SMS sending will be implemented in Phase 6 (SMSC integration)
        $this->logger->info('SMS OTP requested', ['phone' => $phone]);

        return $this->json(['message' => 'OTP sent']);
    }

    #[Route('/sms/verify', methods: ['POST'])]
    #[OA\Tag(name: 'Auth')]
    #[OA\Post(
        summary: 'Проверить SMS OTP (резервный метод)',
        description: 'Заглушка — полная реализация в Phase 6.',
        security: [],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['phone', 'code'],
            properties: [
                new OA\Property(property: 'phone', type: 'string', example: '+79991234567'),
                new OA\Property(property: 'code', type: 'string', example: '1234'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Не указан телефон или код')]
    #[OA\Response(response: 501, description: 'SMS-аутентификация ещё не реализована')]
    public function smsVerify(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true) ?? [];
        $phone = $data['phone']                            ?? null;
        $code  = $data['code']                             ?? null;

        if (! $phone || ! $code) {
            return $this->json(['error' => 'Phone and code required'], Response::HTTP_BAD_REQUEST);
        }

        // SMS verification stub — full implementation in Phase 6
        $this->logger->info('SMS OTP verify attempt', ['phone' => $phone]);

        return $this->json(['error' => 'SMS auth not yet implemented'], Response::HTTP_NOT_IMPLEMENTED);
    }
}
