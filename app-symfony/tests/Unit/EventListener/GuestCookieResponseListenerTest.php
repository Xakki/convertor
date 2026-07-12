<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\User;
use App\EventListener\GuestCookieResponseListener;
use App\Security\GuestAuthenticator;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Листенер эмитит cookie `guest_id` ТОЛЬКО для материализованного гостя.
 *
 * Ленивая модель: GuestAuthenticator кладёт в атрибут транзиентного гостя;
 * cookie выставляется лишь если гость персистнулся за запрос (id!==null).
 */
final class GuestCookieResponseListenerTest extends TestCase
{
    private const GUEST_ID = 'deadbeefdeadbeefdeadbeefdeadbeef';

    public function testMaterializedGuestEmitsCookie(): void
    {
        $guest = (new User())->setIsGuest(true)->setGuestId(self::GUEST_ID);
        (new \ReflectionProperty(User::class, 'id'))->setValue($guest, 42);

        $cookie = $this->guestCookie($this->dispatch($guest));

        self::assertNotNull($cookie, 'materialized guest (id!==null) must emit a guest_id cookie');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/', $cookie->getPath());
        // Значение — подписанный guestId (`<guestId>.<hmac>`).
        self::assertStringStartsWith(self::GUEST_ID . '.', (string) $cookie->getValue());
    }

    public function testTransientGuestEmitsNoCookie(): void
    {
        // id===null → гость не материализовался (/quota, /history, отклонённый convert).
        $guest = (new User())->setIsGuest(true)->setGuestId(self::GUEST_ID);

        self::assertNull(
            $this->guestCookie($this->dispatch($guest)),
            'transient guest must NOT emit a cookie',
        );
    }

    public function testNoGuestAttributeEmitsNoCookie(): void
    {
        self::assertNull($this->guestCookie($this->dispatch(null)));
    }

    private function dispatch(?User $guest): Response
    {
        $request = new Request();
        if ($guest !== null) {
            $request->attributes->set(GuestAuthenticator::ATTR_GUEST_USER, $guest);
        }

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response(),
        );

        $listener = new GuestCookieResponseListener(
            new GuestCookieFactory(),
            new GuestTokenService('test-secret'),
        );
        $listener($event);

        return $event->getResponse();
    }

    private function guestCookie(Response $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestCookieFactory::NAME) {
                return $cookie;
            }
        }

        return null;
    }
}
