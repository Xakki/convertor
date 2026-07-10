<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Гостевой аутентификатор firewall'а `api` (ПОСЛЕ jwt).
 *
 * supports() → true ТОЛЬКО когда в запросе НЕТ `Authorization: Bearer`. Так JWT
 * и guest взаимоисключающи и порядок аутентификаторов не важен: валидный Bearer
 * обрабатывает JWT, невалидный Bearer → JWT падает 401 (guest НЕ спасает).
 *
 * Нет Bearer → читаем cookie `guest_id`, проверяем HMAC:
 *   - подпись валидна и найден активный гость → аутентифицируем его;
 *   - иначе → создаём нового guest-User (isGuest=true) и планируем выставить
 *     cookie в ответе (значение кладём в request-атрибут; сам Set-Cookie вешает
 *     {@see GuestCookieResponseListener} на kernel.response, т.к. из stateless
 *     firewall'а вернуть cookie напрямую из onAuthenticationSuccess нельзя —
 *     это оборвало бы контроллер).
 *
 * Скоуп supports() ограничен guest-релевантными путями, чтобы хиты на публичные
 * роуты (formats/auth) не плодили мусорных гостевых строк.
 */
class GuestAuthenticator extends AbstractAuthenticator
{
    /** Request-атрибут: подписанное значение cookie, которое надо выставить в ответе. */
    public const ATTR_SET_COOKIE = '_guest_set_cookie';

    /**
     * Пути, где guest-аутентификация уместна (без ведущего `/api/v1`, т.к.
     * firewall уже сузил до `^/api`). convert/history/quota + status/download.
     */
    private const GUEST_PATHS = [
        '/api/v1/convert',
        '/api/v1/quota',
    ];

    public function __construct(
        private readonly GuestTokenService $tokenService,
        private readonly GuestCookieFactory $cookieFactory,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Есть Bearer → это зона JWT, guest не вмешивается.
        $authHeader = $request->headers->get('Authorization', '');
        if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        return $this->isGuestPath($request->getPathInfo());
    }

    public function authenticate(Request $request): Passport
    {
        $signed = $request->cookies->get($this->cookieFactory->name());
        $guest  = null;

        if (is_string($signed) && $signed !== '') {
            $guestId = $this->tokenService->verify($signed);
            if ($guestId !== null) {
                $guest = $this->users->findActiveGuestByGuestId($guestId);
            }
        }

        if ($guest === null) {
            $guest = $this->createGuest();
            // Планируем Set-Cookie в ответе (см. GuestCookieResponseListener).
            $request->attributes->set(
                self::ATTR_SET_COOKIE,
                $this->tokenService->sign((string) $guest->getGuestId()),
            );
        }

        $userId = (string) $guest->getId();

        return new SelfValidatingPassport(
            new UserBadge($userId, fn () => $this->users->find((int) $userId) ?? throw new \RuntimeException('Guest user vanished')),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Возврат Response оборвал бы контроллер; cookie вешает kernel.response-листенер.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    private function createGuest(): User
    {
        $guest = new User();
        $guest->setIsGuest(true);
        $guest->setGuestId($this->tokenService->generateGuestId());

        $this->em->persist($guest);
        $this->em->flush();

        return $guest;
    }

    private function isGuestPath(string $path): bool
    {
        foreach (self::GUEST_PATHS as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
