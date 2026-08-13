<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\DTO\ConversionRequestDTO;
use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\EventListener\ConversionChainListener;
use App\Exception\InsufficientBalanceException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ChainEnablement;
use App\Service\Conversion\ConversionChainFailPropagator;
use App\Service\Conversion\ConversionManager;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Tests\Support\SeedsConversionRegistry;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CNV-5 Phase 1: ConversionManager::createConversion chain path (allowlist +
 * materialize hops + dispatch hop-1 only).
 */
#[AllowMockObjectsWithoutExpectations]
final class ConversionManagerChainTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testEmptyAllowlistRejectsChainablePairAsUnsupported(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('checkPlan');
        $quota->expects($this->never())->method('check');

        $manager = $this->buildManager($quota, new ChainEnablement(''));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported conversion: epub → pdf');

        $manager->createConversion(new ConversionRequestDTO(
            $this->makeUser(),
            $this->makeEpubUpload(),
            'pdf',
            false,
            true,
        ));
    }

    public function testEnabledChainMaterializesAllHopsAndReturnsHopOne(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())
            ->method('checkPlan')
            ->willReturn([BillingMode::PlanQuota, BillingMode::PlanQuota]);
        $quota->expects($this->once())
            ->method('chargeHop')
            ->with(
                $this->isInstanceOf(User::class),
                FileCategory::Document,
                false,
                BillingMode::PlanQuota,
                $this->anything(),
            );
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $persisted = [];
        $idSeq     = 100;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted, &$idSeq): void {
            $persisted[] = $entity;
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, $idSeq++);
            }
        });
        $em->expects($this->atLeastOnce())->method('flush');

        $dispatched = 0;
        $bus        = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []) use (&$dispatched): Envelope {
                ++$dispatched;

                return new Envelope($message, $stamps);
            },
        );

        $manager = $this->buildManager(
            $quota,
            new ChainEnablement('epub:pdf'),
            $em,
            $bus,
        );

        $hop1 = $manager->createConversion(new ConversionRequestDTO(
            $this->makeUser(),
            $this->makeEpubUpload(),
            'pdf',
            false,
            true,
        ));

        self::assertSame(1, $dispatched);
        self::assertSame(1, $hop1->getSequence());
        self::assertSame('epub', $hop1->getFromFormat());
        self::assertSame('docx', $hop1->getToFormat());
        self::assertSame('pdf', $hop1->getFinalToFormat());
        self::assertNotNull($hop1->getChainId());
        self::assertFalse(str_starts_with($hop1->getInputFile()->getStoragePath(), ConversionChainListener::PENDING_INPUT_PREFIX));

        $chainHops = array_values(array_filter(
            $persisted,
            static fn (object $e): bool => $e instanceof Conversion,
        ));
        self::assertCount(2, $chainHops);
        self::assertSame($hop1->getChainId(), $chainHops[1]->getChainId());
        self::assertSame(2, $chainHops[1]->getSequence());
        self::assertSame('docx', $chainHops[1]->getFromFormat());
        self::assertSame('pdf', $chainHops[1]->getToFormat());
        self::assertTrue(str_starts_with(
            $chainHops[1]->getInputFile()->getStoragePath(),
            ConversionChainListener::PENDING_INPUT_PREFIX,
        ));
    }

    public function testDirectSupportedPairIgnoresAllowlistAndDoesNotChain(): void
    {
        // jpg→png is direct; even with empty allowlist it must succeed as single-hop.
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())->method('check')->willReturn(BillingMode::PlanQuota);
        $quota->expects($this->once())->method('charge');
        $quota->expects($this->never())->method('checkPlan');

        $manager = $this->buildManager($quota, new ChainEnablement(''));

        $tmp = tempnam(sys_get_temp_dir(), 'jpg');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
        $file = new UploadedFile($tmp, 'x.jpg', null, null, true);

        $conversion = $manager->createConversion(new ConversionRequestDTO(
            $this->makeUser(),
            $file,
            'png',
            false,
            true,
        ));

        self::assertNull($conversion->getChainId());
        self::assertSame('png', $conversion->getToFormat());
    }

    public function testCreateChainAbortFailPropagatesSiblingPendingHops(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())
            ->method('checkPlan')
            ->willReturn([BillingMode::PrepaidBalance, BillingMode::PrepaidBalance]);
        $quota->expects($this->once())
            ->method('charge')
            ->willThrowException(new InsufficientBalanceException('insufficient_balance'));
        $quota->expects($this->never())->method('chargeHop');
        $quota->expects($this->never())->method('refundHops');

        /** @var list<Conversion> $chainHops */
        $chainHops = [];
        $idSeq     = 200;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$chainHops, &$idSeq): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, $idSeq++);
                $chainHops[] = $entity;
            }
        });
        $em->expects($this->atLeastOnce())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $repo = $this->createMock(ConversionRepository::class);
        $repo->method('findByChainIdOrdered')->willReturnCallback(
            static function () use (&$chainHops): array {
                return $chainHops;
            },
        );

        $propagator = new ConversionChainFailPropagator($repo, $em, $quota);

        $manager = $this->buildManager(
            $quota,
            new ChainEnablement('epub:pdf'),
            $em,
            $bus,
            $propagator,
        );

        try {
            $manager->createConversion(new ConversionRequestDTO(
                $this->makeUser(),
                $this->makeEpubUpload(),
                'pdf',
                false,
                true,
            ));
            self::fail('Expected InsufficientBalanceException');
        } catch (InsufficientBalanceException) {
            // expected
        }

        self::assertCount(2, $chainHops);
        self::assertSame(ConversionStatus::Failed, $chainHops[0]->getStatus());
        self::assertSame('insufficient_balance', $chainHops[0]->getErrorMessage());
        self::assertSame(ConversionStatus::Failed, $chainHops[1]->getStatus());
        self::assertStringContainsString('hop 1', (string) $chainHops[1]->getErrorMessage());
        self::assertStringContainsString('epub→docx', (string) $chainHops[1]->getErrorMessage());
    }

    public function testCreateChainPlanQuotaDispatchAbortFailPropagatesSiblings(): void
    {
        $quota = $this->createMock(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->expects($this->once())
            ->method('checkPlan')
            ->willReturn([BillingMode::PlanQuota, BillingMode::PlanQuota]);
        $quota->expects($this->never())->method('charge');
        $quota->expects($this->never())->method('chargeHop');
        $quota->expects($this->never())->method('refundHops');

        /** @var list<Conversion> $chainHops */
        $chainHops = [];
        $idSeq     = 300;
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$chainHops, &$idSeq): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, $idSeq++);
                $chainHops[] = $entity;
            }
        });
        $em->expects($this->atLeastOnce())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(
            new \RuntimeException('bus unavailable'),
        );

        $repo = $this->createMock(ConversionRepository::class);
        $repo->method('findByChainIdOrdered')->willReturnCallback(
            static function () use (&$chainHops): array {
                return $chainHops;
            },
        );

        $propagator = new ConversionChainFailPropagator($repo, $em, $quota);

        $manager = $this->buildManager(
            $quota,
            new ChainEnablement('epub:pdf'),
            $em,
            $bus,
            $propagator,
        );

        try {
            $manager->createConversion(new ConversionRequestDTO(
                $this->makeUser(),
                $this->makeEpubUpload(),
                'pdf',
                false,
                true,
            ));
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertSame('bus unavailable', $e->getMessage());
        }

        self::assertCount(2, $chainHops);
        self::assertSame(ConversionStatus::Failed, $chainHops[0]->getStatus());
        self::assertSame(ConversionStatus::Failed, $chainHops[1]->getStatus());
        self::assertStringContainsString('hop 1', (string) $chainHops[1]->getErrorMessage());
        self::assertStringContainsString('epub→docx', (string) $chainHops[1]->getErrorMessage());
    }

    private function buildManager(
        QuotaService $quota,
        ChainEnablement $enablement,
        ?EntityManagerInterface $em = null,
        ?MessageBusInterface $bus = null,
        ?ConversionChainFailPropagator $failPropagator = null,
    ): ConversionManager {
        if ($em === null) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('persist')->willReturnCallback(static function (object $entity): void {
                if ($entity instanceof Conversion) {
                    (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 42);
                }
            });
        }

        if ($bus === null) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(
                static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
            );
        }

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        return new ConversionManager(
            $this->newSeedRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            $failPropagator ?? new ConversionChainFailPropagator(
                $this->createStub(ConversionRepository::class),
                $em,
                $quota,
            ),
            null,
            null,
            $enablement,
        );
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setEmail('chain-test@example.com');
        $user->setPlan('free');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);

        return $user;
    }

    private function makeEpubUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epub');
        self::assertNotFalse($tmp);
        // Minimal zip-ish bytes so finfo may sniff application/* (document MIME gate).
        file_put_contents($tmp, "PK\x03\x04fake-epub-content");

        return new UploadedFile($tmp, 'book.epub', 'application/epub+zip', null, true);
    }
}
