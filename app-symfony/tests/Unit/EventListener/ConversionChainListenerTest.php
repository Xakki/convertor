<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Event\ConversionCompleted;
use App\Event\ConversionFailed;
use App\EventListener\ConversionChainListener;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionChainFailPropagator;
use App\Service\Conversion\ConversionManager;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Input\CopyObjectRequest;
use AsyncAws\S3\Result\CopyObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class ConversionChainListenerTest extends TestCase
{
    public function testAdvanceCopiesResultsToInputsAndDispatchesNextPendingHop(): void
    {
        $user = new User();

        $doneInput = new FileStorage();
        $doneInput->setOriginalName('book.epub');
        $doneInput->setStoragePath('inputs/a.epub');
        $doneInput->setMimeType('application/epub+zip');
        $doneInput->setSizeBytes(10);

        $doneOutput = new FileStorage();
        $doneOutput->setOriginalName('book.docx');
        $doneOutput->setStoragePath('results/a.docx');
        $doneOutput->setMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $doneOutput->setSizeBytes(20);

        $done = new Conversion();
        $done->setUser($user);
        $done->setInputFile($doneInput);
        $done->setOutputFile($doneOutput);
        $done->setFromFormat('epub');
        $done->setToFormat('docx');
        $done->setCategory(FileCategory::Document);
        $done->setIsAi(false);
        $done->setStatus(ConversionStatus::Completed);
        $done->setChainId('chain-1');
        $done->setSequence(1);
        $done->setFinalToFormat('pdf');
        $done->setBillingMode(BillingMode::PlanQuota);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($done, 1);

        $nextPlaceholder = new FileStorage();
        $nextPlaceholder->setOriginalName('pending');
        $nextPlaceholder->setStoragePath(ConversionChainListener::PENDING_INPUT_PREFIX . 'chain-1/2');
        $nextPlaceholder->setMimeType('application/octet-stream');
        $nextPlaceholder->setSizeBytes(0);

        $next = new Conversion();
        $next->setUser($user);
        $next->setInputFile($nextPlaceholder);
        $next->setFromFormat('docx');
        $next->setToFormat('pdf');
        $next->setCategory(FileCategory::Document);
        $next->setIsAi(false);
        $next->setStatus(ConversionStatus::Pending);
        $next->setChainId('chain-1');
        $next->setSequence(2);
        $next->setFinalToFormat('pdf');
        $next->setBillingMode(BillingMode::PlanQuota);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($next, 2);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())
            ->method('findNextPendingHop')
            ->with('chain-1', 1)
            ->willReturn($next);

        $copied   = null;
        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('copyObject')->willReturnCallback(
            function (CopyObjectRequest $req) use (&$copied): CopyObjectOutput {
                $copied = $req;

                return ResultMockFactory::create(CopyObjectOutput::class);
            },
        );
        $s3 = new S3Storage($s3Client, 'convertor');

        $manager = $this->createMock(ConversionManager::class);
        $manager->expects($this->once())->method('dispatch')->with($next);
        $manager->method('assertSafeObjectKey')->willReturnCallback(
            static function (string $key, string $prefix): void {
                if ($key === '' || ! str_starts_with($key, $prefix) || str_contains($key, '..')) {
                    throw new \RuntimeException('Invalid storage path');
                }
            },
        );

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('chargeHop')->with(
            $user,
            FileCategory::Document,
            false,
            BillingMode::PlanQuota,
            2,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');

        $listener = $this->makeListener($repo, $manager, $quota, $s3, $em);
        $listener->onConversionCompleted(new ConversionCompleted($done));

        self::assertInstanceOf(CopyObjectRequest::class, $copied);
        self::assertSame('convertor-results/results/a.docx', $copied->getCopySource());
        self::assertSame('convertor-inputs', $copied->getBucket());
        self::assertNotNull($copied->getKey());
        self::assertStringEndsWith('.docx', (string) $copied->getKey());
        self::assertFalse(str_starts_with($next->getInputFile()->getStoragePath(), ConversionChainListener::PENDING_INPUT_PREFIX));
        self::assertSame(20, $next->getInputFile()->getSizeBytes());
    }

    public function testAdvanceRejectsInvalidSourceKeyAndFailPropagates(): void
    {
        $done = $this->completedHop(1, 'chain-bad');
        $done->getOutputFile()?->setStoragePath('../evil/results/x.docx');

        $next = new Conversion();
        $next->setUser($done->getUser());
        $next->setInputFile($this->placeholder('chain-bad', 2));
        $next->setFromFormat('docx');
        $next->setToFormat('pdf');
        $next->setCategory(FileCategory::Document);
        $next->setIsAi(false);
        $next->setStatus(ConversionStatus::Pending);
        $next->setChainId('chain-bad');
        $next->setSequence(2);
        $next->setBillingMode(BillingMode::PlanQuota);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($next, 2);

        $hop3 = new Conversion();
        $hop3->setUser($done->getUser());
        $hop3->setInputFile($this->placeholder('chain-bad', 3));
        $hop3->setFromFormat('pdf');
        $hop3->setToFormat('txt');
        $hop3->setCategory(FileCategory::Document);
        $hop3->setStatus(ConversionStatus::Pending);
        $hop3->setChainId('chain-bad');
        $hop3->setSequence(3);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($hop3, 3);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->method('findNextPendingHop')->willReturn($next);
        $repo->method('findByChainIdOrdered')->willReturn([$done, $next, $hop3]);

        $manager = $this->createMock(ConversionManager::class);
        $manager->expects($this->never())->method('dispatch');
        $manager->method('assertSafeObjectKey')->willReturnCallback(
            static function (string $key, string $prefix): void {
                if (
                    $key === ''
                    || ! str_starts_with($key, $prefix)
                    || str_contains($key, '..')
                    || str_contains($key, "\0")
                    || str_contains($key, '\\')
                    || str_starts_with($key, '/')
                ) {
                    throw new \RuntimeException('Invalid storage path');
                }
            },
        );

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('copyObject');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');

        $listener = $this->makeListener(
            $repo,
            $manager,
            $this->createStub(QuotaService::class),
            new S3Storage($s3Client, 'convertor'),
            $em,
        );
        $listener->onConversionCompleted(new ConversionCompleted($done));

        self::assertSame(ConversionStatus::Failed, $next->getStatus());
        self::assertStringContainsString('invalid storage path', (string) $next->getErrorMessage());
        self::assertSame(ConversionStatus::Failed, $hop3->getStatus());
    }

    public function testAdvanceSkipsDispatchWhenNextNoLongerPending(): void
    {
        $done = $this->completedHop(1, 'chain-x');

        $next = new Conversion();
        $next->setUser($done->getUser());
        $next->setInputFile($this->placeholder('chain-x', 2));
        $next->setFromFormat('docx');
        $next->setToFormat('pdf');
        $next->setCategory(FileCategory::Document);
        $next->setStatus(ConversionStatus::Processing);
        $next->setChainId('chain-x');
        $next->setSequence(2);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->method('findNextPendingHop')->willReturn($next);

        $manager = $this->createMock(ConversionManager::class);
        $manager->expects($this->never())->method('dispatch');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('copyObject');

        $listener = $this->makeListener(
            $repo,
            $manager,
            $this->createStub(QuotaService::class),
            new S3Storage($s3Client, 'convertor'),
            $this->createStub(EntityManagerInterface::class),
        );
        $listener->onConversionCompleted(new ConversionCompleted($done));
    }

    public function testAdvanceNoopsWithoutChainId(): void
    {
        $done = new Conversion();
        $done->setStatus(ConversionStatus::Completed);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->never())->method('findNextPendingHop');

        $listener = $this->makeListener(
            $repo,
            $this->createStub(ConversionManager::class),
            $this->createStub(QuotaService::class),
            new S3Storage($this->createStub(S3Client::class), 'convertor'),
            $this->createStub(EntityManagerInterface::class),
        );
        $listener->onConversionCompleted(new ConversionCompleted($done));
    }

    public function testFailPropagateMarksPendingAndRefundsCompleted(): void
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 7);

        $hop1 = new Conversion();
        $hop1->setUser($user);
        $hop1->setInputFile($this->realInput('a.epub'));
        $hop1->setFromFormat('epub');
        $hop1->setToFormat('docx');
        $hop1->setCategory(FileCategory::Document);
        $hop1->setIsAi(false);
        $hop1->setStatus(ConversionStatus::Completed);
        $hop1->setBillingMode(BillingMode::PlanQuota);
        $hop1->setChainId('c-fail');
        $hop1->setSequence(1);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($hop1, 10);

        $hop2 = new Conversion();
        $hop2->setUser($user);
        $hop2->setInputFile($this->realInput('a.docx'));
        $hop2->setFromFormat('docx');
        $hop2->setToFormat('pdf');
        $hop2->setCategory(FileCategory::Document);
        $hop2->setIsAi(false);
        $hop2->setStatus(ConversionStatus::Failed);
        $hop2->setErrorMessage('worker boom');
        $hop2->setBillingMode(BillingMode::PlanQuota);
        $hop2->setChainId('c-fail');
        $hop2->setSequence(2);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($hop2, 11);

        $hop3 = new Conversion();
        $hop3->setUser($user);
        $hop3->setInputFile($this->placeholder('c-fail', 3));
        $hop3->setFromFormat('pdf');
        $hop3->setToFormat('txt');
        $hop3->setCategory(FileCategory::Document);
        $hop3->setIsAi(false);
        $hop3->setStatus(ConversionStatus::Pending);
        $hop3->setBillingMode(BillingMode::PlanQuota);
        $hop3->setChainId('c-fail');
        $hop3->setSequence(3);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($hop3, 12);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())
            ->method('findByChainIdOrdered')
            ->with('c-fail')
            ->willReturn([$hop1, $hop2, $hop3]);

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('refundHops')->with(
            $user,
            $this->callback(static function (array $hops): bool {
                return count($hops)             === 1
                    && $hops[0]['conversionId'] === 10
                    && $hops[0]['billingMode']  === BillingMode::PlanQuota;
            }),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $listener = $this->makeListener(
            $repo,
            $this->createStub(ConversionManager::class),
            $quota,
            new S3Storage($this->createStub(S3Client::class), 'convertor'),
            $em,
        );
        $listener->onConversionFailed(new ConversionFailed($hop2));

        self::assertSame(ConversionStatus::Failed, $hop3->getStatus());
        self::assertStringContainsString('hop 2', (string) $hop3->getErrorMessage());
        self::assertSame(ConversionStatus::Completed, $hop1->getStatus());
    }

    public function testFailNoopsWithoutChainId(): void
    {
        $failed = new Conversion();
        $failed->setStatus(ConversionStatus::Failed);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->never())->method('findByChainIdOrdered');

        $listener = $this->makeListener(
            $repo,
            $this->createStub(ConversionManager::class),
            $this->createStub(QuotaService::class),
            new S3Storage($this->createStub(S3Client::class), 'convertor'),
            $this->createStub(EntityManagerInterface::class),
        );
        $listener->onConversionFailed(new ConversionFailed($failed));
    }

    public function testAdvanceDoesNotChargeAgainWhenInputAlreadyWired(): void
    {
        $done = $this->completedHop(1, 'chain-r');

        $wired = new FileStorage();
        $wired->setOriginalName('book.docx');
        $wired->setStoragePath('inputs/already-wired.docx');
        $wired->setMimeType('application/octet-stream');
        $wired->setSizeBytes(20);

        $next = new Conversion();
        $next->setUser($done->getUser());
        $next->setInputFile($wired);
        $next->setFromFormat('docx');
        $next->setToFormat('pdf');
        $next->setCategory(FileCategory::Document);
        $next->setIsAi(false);
        $next->setStatus(ConversionStatus::Pending);
        $next->setChainId('chain-r');
        $next->setSequence(2);
        $next->setBillingMode(BillingMode::PlanQuota);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($next, 22);

        $repo = $this->createMock(ConversionRepository::class);
        $repo->method('findNextPendingHop')->willReturn($next);

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('copyObject');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('chargeHop');

        $manager = $this->createMock(ConversionManager::class);
        $manager->expects($this->once())->method('dispatch')->with($next);

        $listener = $this->makeListener(
            $repo,
            $manager,
            $quota,
            new S3Storage($s3Client, 'convertor'),
            $this->createStub(EntityManagerInterface::class),
        );
        $listener->onConversionCompleted(new ConversionCompleted($done));
    }

    private function makeListener(
        ConversionRepository $repo,
        ConversionManager $manager,
        QuotaService $quota,
        S3Storage $s3,
        EntityManagerInterface $em,
    ): ConversionChainListener {
        return new ConversionChainListener(
            $repo,
            $manager,
            $quota,
            $s3,
            $em,
            new NullLogger(),
            new ConversionChainFailPropagator($repo, $em, $quota),
        );
    }

    private function completedHop(int $id, string $chainId): Conversion
    {
        $user   = new User();
        $input  = $this->realInput('book.epub');
        $output = new FileStorage();
        $output->setOriginalName('book.docx');
        $output->setStoragePath('results/a.docx');
        $output->setMimeType('application/octet-stream');
        $output->setSizeBytes(20);

        $done = new Conversion();
        $done->setUser($user);
        $done->setInputFile($input);
        $done->setOutputFile($output);
        $done->setFromFormat('epub');
        $done->setToFormat('docx');
        $done->setCategory(FileCategory::Document);
        $done->setStatus(ConversionStatus::Completed);
        $done->setChainId($chainId);
        $done->setSequence(1);
        $done->setBillingMode(BillingMode::PlanQuota);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($done, $id);

        return $done;
    }

    private function placeholder(string $chainId, int $seq): FileStorage
    {
        $f = new FileStorage();
        $f->setOriginalName('pending');
        $f->setStoragePath(ConversionChainListener::PENDING_INPUT_PREFIX . $chainId . '/' . $seq);
        $f->setMimeType('application/octet-stream');
        $f->setSizeBytes(0);

        return $f;
    }

    private function realInput(string $name): FileStorage
    {
        $f = new FileStorage();
        $f->setOriginalName($name);
        $f->setStoragePath('inputs/' . $name);
        $f->setMimeType('application/octet-stream');
        $f->setSizeBytes(10);

        return $f;
    }
}
