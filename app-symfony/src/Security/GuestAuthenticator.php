<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
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
 *   - подпись валидна и найден активный гость → аутентифицируем его (уже в БД);
 *   - иначе → создаём ТРАНЗИЕНТНОГО guest-User (isGuest=true), но НЕ персистим.
 *
 * Ленивая материализация: строка в `users` НЕ создаётся при аутентификации.
 * Транзиентный гость живёт только в памяти запроса; строка в БД появляется
 * ТОЛЬКО когда первая конвертация проходит все гейты (ai/video, size, mime,
 * quota) — там {@see \App\Service\Conversion\ConversionManager::createConversion}
 * персистит гостя. Так unauth-флуд `/quota` и отклонённые `/convert` (400/403)
 * не плодят мусорных guest-строк.
 *
 * Cookie `guest_id` выставляет {@see GuestCookieResponseListener} на
 * kernel.response, и ТОЛЬКО если гость реально материализовался за этот запрос
 * (persist присвоил id). Мы лишь кладём созданного транзиентного гостя в
 * request-атрибут; листенер сам решает, эмитить cookie или нет.
 *
 * Скоуп supports() ограничен guest-релевантными путями, чтобы хиты на публичные
 * роуты (formats/auth) не плодили лишних гостевых объектов.
 */
class GuestAuthenticator extends AbstractAuthenticator
{
    /** Request-атрибут: созданный транзиентный guest-User (кандидат на cookie). */
    public const ATTR_GUEST_USER = '_guest_user';

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
            // Кладём транзиентного гостя в атрибут — кандидат на Set-Cookie.
            // Cookie эмитится листенером ТОЛЬКО если гость материализуется за
            // этот запрос (id!==null). Для существующего гостя атрибут не ставим.
            $request->attributes->set(self::ATTR_GUEST_USER, $guest);
        }

        // Loader возвращает уже разрешённого гостя (существующего из БД или
        // транзиентного). НЕ делаем find() по id — у транзиентного id===null.
        return new SelfValidatingPassport(
            new UserBadge($guest->getUserIdentifier(), fn () => $guest),
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

    /**
     * Создаёт ТРАНЗИЕНТНОГО гостя (без persist/flush). Строка в `users`
     * материализуется позже — при первой успешной постановке конвертации.
     */
    private function createGuest(): User
    {
        $guest = new User();
        $guest->setIsGuest(true);
        $guest->setGuestId($this->tokenService->generateGuestId());

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
