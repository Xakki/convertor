<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Auth;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\FileCategory;
use App\Repository\ConversionRepository;
use App\Repository\UserRepository;
use App\Service\Auth\GuestUserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * mergeInto против РЕАЛЬНОЙ БД (convertor-test): проверяет, что bulk-DQL
 * `UPDATE Conversion c SET c.user=:real WHERE c.user=:guest` действительно
 * парсится и переносит владельца. Юнит-тест мокает Query целиком, поэтому DQL
 * там не проверяется — а этот метод вызывает backend-B на интеграции.
 */
#[Group('integration')]
final class GuestUserServiceDbTest extends KernelTestCase
{
    public function testMergeReassignsRealConversionsAndDeactivatesGuest(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var ConversionRepository $conversions */
        $conversions = $container->get(ConversionRepository::class);

        // GuestUserService — приватный сервис (пока не инжектится ниоткуда: его
        // подключит backend-B). Собираем из реальных зависимостей, чтобы всё же
        // прогнать настоящий DQL против БД.
        $service = new GuestUserService($users, $em);

        // Реальный гость + реальный пользователь.
        $guestId = 'itest-' . bin2hex(random_bytes(8));
        $guest   = (new User())->setIsGuest(true)->setGuestId($guestId);
        $real    = (new User())->setTelegramId((string) random_int(10_000_000, 99_999_999));
        $em->persist($guest);
        $em->persist($real);

        // Две конвертации, принадлежащие гостю.
        $c1 = $this->makeConversion($em, $guest);
        $c2 = $this->makeConversion($em, $guest);
        $em->flush();

        $guestPk = $guest->getId();
        $c1Id    = $c1->getId();
        $c2Id    = $c2->getId();

        // Мержим.
        $service->mergeInto($real, $guestId);

        // После bulk-UPDATE очищаем identity map, чтобы читать свежие строки из БД.
        $em->clear();

        $reloaded1 = $conversions->find($c1Id);
        $reloaded2 = $conversions->find($c2Id);
        self::assertNotNull($reloaded1);
        self::assertNotNull($reloaded2);
        self::assertSame($real->getId(), $reloaded1->getUser()->getId(), 'c1 перепривязана к real');
        self::assertSame($real->getId(), $reloaded2->getUser()->getId(), 'c2 перепривязана к real');

        $reloadedGuest = $em->getRepository(User::class)->find($guestPk);
        self::assertNotNull($reloadedGuest);
        self::assertFalse($reloadedGuest->isActive(), 'гость деактивирован');
        self::assertNull($reloadedGuest->getGuestId(), 'guestId занулён');

        // cleanup
        $em->remove($conversions->find($c1Id));
        $em->remove($conversions->find($c2Id));
        $em->remove($em->getRepository(User::class)->find($guestPk));
        $em->remove($em->getRepository(User::class)->find($real->getId()));
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
