<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\CleanTestDataCommand;
use App\Entity\BalanceTransaction;
use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\Payment;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Enum\BalanceTransactionSource;
use App\Enum\BalanceTransactionType;
use App\Enum\FileCategory;
use App\Enum\PaymentGateway;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Input\DeleteObjectRequest;
use AsyncAws\S3\Result\DeleteObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Живой прогон `app:clean-test-data` против РЕАЛЬНОЙ тест-БД (convertor-test —
 * bootstrap.php форсирует APP_ENV=test для любого PHPUnit-прогона, см. класс-
 * докблок). Эта БД предназначена именно для такого разрушительного теста —
 * `convertor` (live dev-стенд) здесь НИКОГДА не участвует.
 *
 * S3Storage — final (не мокается), но реальный инстанс поверх мок-клиента —
 * тот же приём, что и в {@see \App\Tests\Functional\Service\Storage\FileCleanupServiceTest}.
 */
final class CleanTestDataCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<int> id админов, созданных тестом (не вайпаются командой — чистим сами) */
    private array $adminIdsToRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->adminIdsToRemove as $id) {
            $admin = $this->em->find(User::class, $id);
            if ($admin !== null) {
                $this->em->remove($admin);
            }
        }
        if ($this->adminIdsToRemove !== []) {
            $this->em->flush();
        }
        $this->adminIdsToRemove = [];

        parent::tearDown();
    }

    public function testDryRunDeletesNothing(): void
    {
        $fixture = $this->seedFixture('dry-run');

        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ничего не удалено', $tester->getDisplay());

        // Ничего не удалено — все сущности fixture всё ещё в БД.
        self::assertNotNull($this->em->find(Conversion::class, $fixture['conversionId']));
        self::assertNotNull($this->em->find(FileStorage::class, $fixture['inputFileId']));
        self::assertNotNull($this->em->find(FileStorage::class, $fixture['outputFileId']));
        self::assertNotNull($this->em->find(Payment::class, $fixture['paymentId']));
        self::assertNotNull($this->em->find(BalanceTransaction::class, $fixture['balanceTransactionId']));
        self::assertNotNull($this->em->find(SocialIdentity::class, $fixture['socialIdentityId']));
        self::assertNotNull($this->em->find(User::class, $fixture['userId']));
        self::assertNotNull($this->em->find(User::class, $fixture['adminId']));

        $this->cleanupFixture($fixture);
    }

    public function testForceWipesTransactionalDataPreservesAdminAndConfig(): void
    {
        $fixture = $this->seedFixture('force-wipe');

        /** @var list<array{bucket: string, key: string}> $deletedS3 */
        $deletedS3 = [];
        $s3Client  = $this->createStub(S3Client::class);
        $s3Client->method('deleteObject')->willReturnCallback(
            function (DeleteObjectRequest $req) use (&$deletedS3): DeleteObjectOutput {
                $deletedS3[] = ['bucket' => (string) $req->getBucket(), 'key' => (string) $req->getKey()];

                return ResultMockFactory::create(DeleteObjectOutput::class);
            },
        );
        static::getContainer()->set(S3Storage::class, new S3Storage($s3Client, 'test'));

        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        // ORM identity map держит закешированные объекты, а команда бьёт мимо
        // него сырым DELETE — clear() обязателен, иначе find() вернёт stale-снимок.
        $this->em->clear();

        $connection = $this->em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM conversions'), 'conversions вайпается целиком');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payments'), 'payments вайпается целиком');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM balance_transactions'), 'ledger вайпается до users');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM social_identities'), 'social_identities вайпается целиком');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM file_storage'), 'file_storage вайпается целиком');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM users WHERE is_admin = 0'), 'non-admin users вайпаются');

        // Preserve: админ, config-таблицы, миграции.
        self::assertNotNull($this->em->find(User::class, $fixture['adminId']), 'admin-пользователь сохраняется');
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM plans'), 'plans (config) не трогаем');
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'), 'миграции не трогаем НИКОГДА');

        // S3: оба объекта фикстуры реально ушли в deleteObject нужных бакетов.
        self::assertContains(['bucket' => 'test-inputs', 'key' => $fixture['inputKey']], $deletedS3);
        self::assertContains(['bucket' => 'test-results', 'key' => $fixture['outputKey']], $deletedS3);

        $this->adminIdsToRemove[] = $fixture['adminId'];
    }

    private function buildCommand(): CleanTestDataCommand
    {
        /** @var CleanTestDataCommand $command */
        $command = static::getContainer()->get(CleanTestDataCommand::class);

        return $command;
    }

    /**
     * @return array{adminId: int, userId: int, conversionId: int, inputFileId: int,
     *     outputFileId: int, inputKey: string, outputKey: string, paymentId: int,
     *     balanceTransactionId: int, socialIdentityId: int}
     */
    private function seedFixture(string $label): array
    {
        $admin = (new User())->setIsAdmin(true)->setEmail("admin-{$label}-" . bin2hex(random_bytes(4)) . '@example.test');
        $this->em->persist($admin);

        $user = (new User())->setPlan('free');
        $this->em->persist($user);

        $suffix   = bin2hex(random_bytes(8));
        $inputKey = 'inputs/test/' . $suffix . '.bin';
        $outKey   = 'results/test/' . $suffix . '.bin';

        $input = (new FileStorage())
            ->setOriginalName('in.bin')
            ->setStoragePath($inputKey)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(111);
        $this->em->persist($input);

        $output = (new FileStorage())
            ->setOriginalName('out.bin')
            ->setStoragePath($outKey)
            ->setMimeType('application/octet-stream')
            ->setSizeBytes(222);
        $this->em->persist($output);

        $conversion = (new Conversion())
            ->setUser($user)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('bin')
            ->setToFormat('bin')
            ->setCategory(FileCategory::Document)
            ->setIsAi(false)
            ->setIsOcr(false);
        $this->em->persist($conversion);

        $payment = (new Payment())
            ->setUser($user)
            ->setAmount(1.5)
            ->setCurrency('USD')
            ->setGateway(PaymentGateway::TelegramStars);
        $this->em->persist($payment);

        $balanceTransaction = (new BalanceTransaction())
            ->setUser($user)
            ->setAmountCents(50)
            ->setType(BalanceTransactionType::Credit)
            ->setSource(BalanceTransactionSource::Other);
        $this->em->persist($balanceTransaction);

        $social = (new SocialIdentity())
            ->setUser($user)
            ->setProvider('google')
            ->setProviderUid('uid-' . $suffix)
            ->setEmail("social-{$suffix}@example.test");
        $this->em->persist($social);

        $this->em->flush();

        return [
            'adminId'              => (int) $admin->getId(),
            'userId'               => (int) $user->getId(),
            'conversionId'         => $conversion->getId(),
            'inputFileId'          => $input->getId(),
            'outputFileId'         => $output->getId(),
            'inputKey'             => $inputKey,
            'outputKey'            => $outKey,
            'paymentId'            => $payment->getId(),
            'balanceTransactionId' => $balanceTransaction->getId(),
            'socialIdentityId'     => (int) $social->getId(),
        ];
    }

    /**
     * @param array{adminId: int, userId: int, conversionId: int, inputFileId: int,
     *     outputFileId: int, inputKey: string, outputKey: string, paymentId: int,
     *     balanceTransactionId: int, socialIdentityId: int} $fixture
     */
    private function cleanupFixture(array $fixture): void
    {
        foreach ([
            [BalanceTransaction::class, $fixture['balanceTransactionId']],
            [Conversion::class, $fixture['conversionId']],
            [Payment::class, $fixture['paymentId']],
            [SocialIdentity::class, $fixture['socialIdentityId']],
            [FileStorage::class, $fixture['inputFileId']],
            [FileStorage::class, $fixture['outputFileId']],
            [User::class, $fixture['userId']],
            [User::class, $fixture['adminId']],
        ] as [$class, $id]) {
            $entity = $this->em->find($class, $id);
            if ($entity !== null) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
    }
}
