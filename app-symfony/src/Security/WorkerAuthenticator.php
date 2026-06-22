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
 * Stateless bearer-token authenticator for the universal worker pull-API.
 *
 * Registered under the `worker_api` firewall (pattern ^/api/v1/worker) so it
 * applies automatically to all worker routes — no per-action checks needed.
 * Fail-closed: an empty or missing WORKER_API_TOKEN rejects every request.
 */
class WorkerAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        #[Autowire('%env(WORKER_API_TOKEN)%')]
        private readonly string $token,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Always run on every request reaching this firewall.
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if ($this->token === '') {
            throw new CustomUserMessageAuthenticationException('Worker API is not configured.');
        }

        $header   = $request->headers->get('Authorization', '');
        $expected = 'Bearer ' . $this->token;

        if (! is_string($header) || ! hash_equals($expected, $header)) {
            throw new CustomUserMessageAuthenticationException('Invalid or missing worker token.');
        }

        return new SelfValidatingPassport(
            new UserBadge('worker', static fn () => new InMemoryUser('worker', null, ['ROLE_WORKER'])),
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
