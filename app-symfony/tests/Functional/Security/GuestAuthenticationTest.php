<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональные тесты guest-аутентификации через firewall `api`.
 *
 * Требуют реальную тест-БД (convertor-test) — GuestAuthenticator персистит
 * guest-User. Проверяем: cookie выставляется при первом анонимном запросе,
 * переиспользуется при повторном (тот же guest-User), guest-quota отдаёт
 * plan=guest/ai_conversions=0, а не 401.
 */
final class GuestAuthenticationTest extends WebTestCase
{
    public function testGuestQuotaSetsCookieOnFirstCall(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/quota');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        // Cookie guest_id выставлена в ответе.
        $setCookie = $this->guestCookieFromResponse($client);
        self::assertNotNull($setCookie, 'guest_id cookie must be set on first anonymous call');
        self::assertTrue($setCookie->isHttpOnly());
        self::assertSame('/', $setCookie->getPath());

        // Тело — гостевая квота, не 401.
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('guest', $data['plan']);
        self::assertSame(0, $data['ai_conversions']);
        self::assertArrayHasKey('conversions', $data);
    }

    public function testGuestCookieIsReusedAcrossRequests(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();
        /** @var GuestTokenService $tokens */
        $tokens = $container->get(GuestTokenService::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);

        // Заводим гостя заранее и подписываем его cookie.
        $guest = (new User())->setIsGuest(true)->setGuestId($tokens->generateGuestId());
        $em->persist($guest);
        $em->flush();
        $guestPk = $guest->getId();

        $signed = $tokens->sign((string) $guest->getGuestId());

        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(GuestCookieFactory::NAME, $signed),
        );

        $client->request('GET', '/api/v1/quota');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Никакой новый guest не создан — используется существующий (не выставляем
        // новую cookie: аутентификатор нашёл активного гостя по подписи).
        self::assertNull(
            $this->guestCookieFromResponse($client),
            'existing valid guest cookie must NOT trigger a new Set-Cookie',
        );

        // Кол-во гостей с этим guestId не выросло.
        $found = $users->findActiveGuestByGuestId((string) $guest->getGuestId());
        self::assertNotNull($found);
        self::assertSame($guestPk, $found->getId());

        // cleanup
        $em->remove($found);
        $em->flush();
    }

    public function testAnonymousConvertWithoutCookieCreatesGuest(): void
    {
        $client = static::createClient();

        // Пустой convert (без файла) — 400, но guest-аутентификация и cookie
        // отрабатывают ДО валидации тела (firewall + authenticator).
        $client->request('POST', '/api/v1/convert');

        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [400], 'guest passes firewall; body validation yields 400');

        self::assertNotNull(
            $this->guestCookieFromResponse($client),
            'anonymous convert must mint a guest cookie',
        );
    }

    public function testGuestAiConversionReturns403AuthRequired(): void
    {
        // mp3→txt = ai. Гость получает 403 auth_required (гейт в контроллере).
        $this->assertGuestGate403('mp3', 'txt', $this->mp3Bytes());
    }

    public function testGuestVideoConversionReturns403AuthRequired(): void
    {
        // mp4→mkv = video. Гость получает 403 auth_required.
        $this->assertGuestGate403('mp4', 'mkv', $this->mp4Bytes());
    }

    private function assertGuestGate403(string $from, string $to, string $bytes): void
    {
        $client = static::createClient();

        $path = tempnam(sys_get_temp_dir(), 'conv');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        $upload = new \Symfony\Component\HttpFoundation\File\UploadedFile($path, "sample.{$from}", null, null, true);

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => $to],
            ['file'      => $upload],
        );

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('auth_required', $data['error']);
        self::assertSame('Войдите через Telegram для ai/video конвертаций', $data['message']);
    }

    private function mp3Bytes(): string
    {
        return "\xFF\xFB\x90\x64" . str_repeat("\x00", 64);
    }

    private function mp4Bytes(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32);
    }

    private function guestCookieFromResponse(KernelBrowser $client): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestCookieFactory::NAME && $cookie->getValue() !== null && $cookie->getValue() !== '') {
                return $cookie;
            }
        }

        return null;
    }
}
