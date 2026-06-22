<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\DTO\TelegramAuthDTO;
use App\Repository\UserRepository;
use App\Service\Auth\RefreshCookieFactory;
use App\Service\Auth\RefreshTokenService;
use App\Service\Auth\TelegramAuthService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/v1/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly TelegramAuthService $telegramAuthService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RefreshCookieFactory $refreshCookie,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/telegram', methods: ['POST'])]
    public function telegram(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new TelegramAuthDTO(
            id: (string) ($data['id'] ?? ''),
            firstName: (string) ($data['first_name'] ?? ''),
            lastName: $data['last_name'] ?? null,
            username: $data['username']  ?? null,
            photoUrl: $data['photo_url'] ?? null,
            authDate: isset($data['auth_date']) ? (int) $data['auth_date'] : null,
            hash: $data['hash'] ?? null,
        );

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json(['error' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        if (! $this->telegramAuthService->verify($dto)) {
            return $this->json(['error' => 'Invalid Telegram auth data'], Response::HTTP_UNAUTHORIZED);
        }

        $user  = $this->telegramAuthService->findOrCreateUser($dto);
        $token = $this->jwtManager->create($user);

        $response = $this->json(['token' => $token]);
        $response->headers->setCookie($this->refreshCookie->create($this->refreshTokens->issueFamily($user)));

        return $response;
    }

    #[Route('/refresh', methods: ['POST'])]
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
