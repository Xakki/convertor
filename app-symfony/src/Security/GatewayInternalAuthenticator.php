<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Stateless bearer-аутентификатор для внутреннего relay WS-Gateway.
 *
 * Firewall `internal_api` (pattern ^/api/v1/internal) — отдельный токен
 * GATEWAY_INTERNAL_TOKEN, чтобы публичный worker_api-токен сюда НЕ проходил.
 * Fail-closed: пустой/отсутствующий токен отклоняет все запросы. Сравнение
 * constant-time (hash_equals).
 */
class GatewayInternalAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire('%env(GATEWAY_INTERNAL_TOKEN)%')]
        private readonly string $token,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if ($this->token === '') {
            throw new CustomUserMessageAuthenticationException('Internal worker API is not configured.');
        }

        $header   = $request->headers->get('Authorization', '');
        $expected = 'Bearer ' . $this->token;

        if (! is_string($header) || ! hash_equals($expected, $header)) {
            throw new CustomUserMessageAuthenticationException('Invalid or missing internal token.');
        }

        return new SelfValidatingPassport(
            new UserBadge('gateway', static fn () => new InMemoryUser('gateway', null, ['ROLE_GATEWAY'])),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
