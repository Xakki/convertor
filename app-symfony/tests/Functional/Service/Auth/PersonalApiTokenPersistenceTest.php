<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Auth;

use App\Entity\PersonalApiToken;
use App\Entity\User;
use App\Repository\PersonalApiTokenRepository;
use App\Service\Auth\PersonalApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PersonalApiTokenPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->createQuery('DELETE FROM App\\Entity\\User user WHERE user.email = :email')
                ->setParameter('email', 'cnv83-persistence-regression@example.test')
                ->execute();
            $this->entityManager->clear();
        }

        parent::tearDown();
    }

    public function testIssueRejectsFourthActiveTokenWithoutPersistingIt(): void
    {
        $user = (new User())
            ->setEmail('cnv83-persistence-regression@example.test')
            ->setIsGuest(false);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $repository = static::getContainer()->get(PersonalApiTokenRepository::class);
        for ($index = 1; $index <= PersonalApiTokenService::MAX_ACTIVE_TOKENS; ++$index) {
            $repository->save(
                new PersonalApiToken($user, 'fixture-' . $index, 'cnv_fixture' . $index, hash('sha256', 'fixture-secret-' . $index)),
            );
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $persistedUser = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => 'cnv83-persistence-regression@example.test',
        ]);
        self::assertInstanceOf(User::class, $persistedUser);
        self::assertSame(PersonalApiTokenService::MAX_ACTIVE_TOKENS, $repository->countActiveForUser($persistedUser));

        $service = static::getContainer()->get(PersonalApiTokenService::class);

        try {
            $service->issue($persistedUser, 'fourth');
            self::fail('Issuing a fourth active token must fail.');
        } catch (ConflictHttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
            self::assertSame('Maximum active API tokens reached.', $exception->getMessage());
        }

        $this->entityManager->clear();
        $freshUser = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => 'cnv83-persistence-regression@example.test',
        ]);
        self::assertInstanceOf(User::class, $freshUser);
        self::assertSame(PersonalApiTokenService::MAX_ACTIVE_TOKENS, $repository->countActiveForUser($freshUser));
        self::assertCount(
            PersonalApiTokenService::MAX_ACTIVE_TOKENS,
            $repository->findActiveForUser($freshUser),
        );
    }
}
