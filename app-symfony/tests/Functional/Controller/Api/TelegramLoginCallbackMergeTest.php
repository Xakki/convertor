<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use App\Service\Auth\GuestCookieFactory;
use App\Service\Auth\GuestTokenService;
use App\Service\Auth\TelegramLoginCodeStore;
use App\Service\Auth\TelegramLoginNonceCookieFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Merge-seam end-to-end против РЕАЛЬНОГО store (KeyDB test db) и РЕАЛЬНОЙ БД
 * (convertor-test). Прогоняет весь путь callback'а: real mint → capture nonce →
 * real authorize(code, userId) → GET /callback с nonce-cookie + guest-cookie
 * настоящего guest-User, у которого есть Conversion-строки. Проверяет, что
 * строки ДЕЙСТВИТЕЛЬНО перевешиваются на залогиненного пользователя И guest-
 * cookie гасится.
 *
 * Отличие от прежнего теста (guest, которого нет в БД → mergeInto no-op):
 * здесь guest реально существует, так что регресс merge-шва провалит тест,
 * а не пройдёт вхолостую.
 */
#[Group('integration')]
final class TelegramLoginCallbackMergeTest extends WebTestCase
{
    public function testCallbackReassignsRealGuestConversionsAndClearsGuestCookie(): void
    {
        $client    = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var ConversionRepository $conversions */
        $conversions = $container->get(ConversionRepository::class);
        /** @var TelegramLoginCodeStore $store */
        $store = $container->get(TelegramLoginCodeStore::class);
        /** @var GuestTokenService $guestTokens */
        $guestTokens = $container->get(GuestTokenService::class);

        // Реальный guest-User с двумя конвертациями + реальный залогиненный User.
        $guestId = 'seam-' . bin2hex(random_bytes(8));
        $guest   = (new User())->setIsGuest(true)->setGuestId($guestId);
        $real    = (new User())->setTelegramId((string) random_int(10_000_000, 99_999_999));
        $em->persist($guest);
        $em->persist($real);
        $c1 = $this->makeConversion($em, $guest);
        $c2 = $this->makeConversion($em, $guest);
        $em->flush();

        $realPk  = $real->getId();
        $guestPk = $guest->getId();
        $c1Id    = $c1->getId();
        $c2Id    = $c2->getId();

        // Реальный store: mint → nonce → authorize(code, realUser.id) → linkSecret.
        $minted     = $store->mint();
        $linkSecret = $store->authorize($minted['code'], $realPk);
        self::assertNotNull($linkSecret, 'authorize должен вернуть linkSecret');

        // Против РЕАЛЬНОГО Lua (не in-memory дубля) — обе критичные защиты, и обе
        // НЕ гасят код (легитимный логин ниже всё ещё проходит):
        //  - FIXATION: чужой браузер (неверный nonce, верный linkSecret) → mismatch.
        $fixation = $store->redeem($minted['code'], 'nonce-of-another-browser', $linkSecret);
        self::assertSame(TelegramLoginCodeStore::STATUS_MISMATCH, $fixation['status']);
        //  - TAKEOVER: атакующий с code+nonce, но БЕЗ linkSecret из чата → mismatch.
        $takeover = $store->redeem($minted['code'], $minted['nonce'], 'attacker-guessed-secret');
        self::assertSame(TelegramLoginCodeStore::STATUS_MISMATCH, $takeover['status']);

        // Ставим оба cookie браузера-инициатора: nonce (совпадёт) + guest_id.
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(TelegramLoginNonceCookieFactory::NAME, $minted['nonce']),
        );
        $client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie(GuestCookieFactory::NAME, $guestTokens->sign($guestId)),
        );

        // Полный успех: code + nonce-cookie + linkSecret (query `s`) — все совпали.
        $client->request(
            'GET',
            '/api/v1/auth/telegram/callback?code=' . $minted['code'] . '&s=' . rawurlencode($linkSecret),
        );

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/', $client->getResponse()->headers->get('Location'));

        // guest_id погашен на ответе — доказывает, что взята merge-ветка.
        $guestClear = null;
        foreach ($client->getResponse()->headers->getCookies() as $c) {
            if ($c->getName() === GuestCookieFactory::NAME) {
                $guestClear = $c;
            }
        }
        self::assertNotNull($guestClear, 'guest_id clearing cookie must be set');
        self::assertLessThan(time(), $guestClear->getExpiresTime());

        // Главное: Conversion-строки реально перевешены на залогиненного user'а.
        $em->clear();
        $reloaded1 = $conversions->find($c1Id);
        $reloaded2 = $conversions->find($c2Id);
        self::assertNotNull($reloaded1);
        self::assertNotNull($reloaded2);
        self::assertSame($realPk, $reloaded1->getUser()->getId(), 'c1 перепривязана к real');
        self::assertSame($realPk, $reloaded2->getUser()->getId(), 'c2 перепривязана к real');

        // Гость деактивирован, guestId занулён.
        $reloadedGuest = $em->getRepository(User::class)->find($guestPk);
        self::assertNotNull($reloadedGuest);
        self::assertFalse($reloadedGuest->isActive(), 'гость деактивирован');
        self::assertNull($reloadedGuest->getGuestId(), 'guestId занулён');

        // cleanup
        $em->remove($conversions->find($c1Id));
        $em->remove($conversions->find($c2Id));
        $em->remove($em->getRepository(User::class)->find($guestPk));
        $em->remove($em->getRepository(User::class)->find($realPk));
        $em->flush();
    }

    private function makeConversion(EntityManagerInterface $em, User $owner): Conversion
    {
        $input = (new FileStorage())
            ->setOriginalName('in.jpg')
            ->setStoragePath('inputs/test/' . bin2hex(random_bytes(8)) . '.jpg')
            ->setMimeType('image/jpeg')
            ->setSizeBytes(123);
        $em->persist($input);

        $c = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image)
            ->setIsAi(false)
            ->setIsOcr(false);
        $em->persist($c);

        return $c;
    }
}
