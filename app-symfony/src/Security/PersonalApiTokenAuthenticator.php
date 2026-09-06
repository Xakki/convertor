<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Auth\PersonalApiTokenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class PersonalApiTokenAuthenticator extends AbstractAuthenticator
{
    /** @var list<array{pattern: string, methods: list<string>}> */
    private const ALLOWED_ROUTES = [
        ['pattern' => '#^/api/v1/convert$#D', 'methods' => ['POST']],
        ['pattern' => '#^/api/v1/convert/history$#D', 'methods' => ['GET']],
        ['pattern' => '#^/api/v1/convert/[0-9]+/status$#D', 'methods' => ['GET']],
        ['pattern' => '#^/api/v1/convert/[0-9]+/download$#D', 'methods' => ['GET']],
        ['pattern' => '#^/api/v1/convert/[0-9]+/preview$#D', 'methods' => ['GET']],
        ['pattern' => '#^/api/v1/quota$#D', 'methods' => ['GET']],
        ['pattern' => '#^/api/v1/audit/history$#D', 'methods' => ['GET']],
    ];

    public function __construct(private readonly PersonalApiTokenService $tokens)
    {
    }

    public function supports(Request $request): ?bool
    {
        $header = $request->headers->get('Authorization', '');

        return is_string($header)
            && preg_match('/^Bearer cnv_[A-Za-z0-9_-]{43}$/D', $header) === 1
            && $this->isAllowedRoute($request->getMethod(), $request->getPathInfo());
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');
        $secret = is_string($header) && str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        $token  = $this->tokens->authenticate($secret);
        if ($token === null) {
            throw new CustomUserMessageAuthenticationException('Invalid or revoked API token.');
        }
        $user = $token->getUser();
        $request->attributes->set('api_audit_owner_id', $user->getId());
        $request->attributes->set('api_audit_token_label', $token->getLabel());
        $request->attributes->set('api_audit_started_at', microtime(true));

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function isAllowedRoute(string $method, string $path): bool
    {
        foreach (self::ALLOWED_ROUTES as $route) {
            if (preg_match($route['pattern'], $path) === 1 && in_array($method, $route['methods'], true)) {
                return true;
            }
        }

        return false;
    }
}
