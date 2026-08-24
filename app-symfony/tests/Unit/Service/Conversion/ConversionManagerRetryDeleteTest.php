<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Exception\ConversionDisabledException;
use App\Exception\InvalidConversionOptionException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionChainFailPropagator;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionToggleService;
use App\Service\Conversion\Settings\ConversionOptionsValidator;
use App\Service\Conversion\Settings\ConversionSettingsCatalog;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Tests\Support\SeedsConversionRegistry;
use App\Tests\Unit\Service\Conversion\Settings\ConversionSettingsCatalogTest;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Input\CopyObjectRequest;
use AsyncAws\S3\Input\DeleteObjectRequest;
use AsyncAws\S3\Result\CopyObjectOutput;
use AsyncAws\S3\Result\DeleteObjectOutput;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CNV-8: retry (новая строка + S3 copy + квота) и hard-delete (+ S3) в
 * ConversionManager. Owner-scope, 410 при отсутствии исходника, path-safe keys.
 */
final class ConversionManagerRetryDeleteTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testRetryCreatesNewConversionCopiesInputAndChargesQuota(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->once())->method('check')->with($owner, FileCategory::Image, false)
            ->willReturn(BillingMode::PlanQuota);
        $quota->expects($this->once())->method('charge')->with($owner, FileCategory::Image, false, BillingMode::PlanQuota);

        $copied   = [];
        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->once())->method('copyObject')->willReturnCallback(
            function (CopyObjectRequest $req) use (&$copied): CopyObjectOutput {
                $copied[] = [
                    'src' => (string) $req->getCopySource(),
                    'dst' => (string) $req->getKey(),
                ];

                return ResultMockFactory::create(CopyObjectOutput::class);
            },
        );

        $persisted = [];
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
                if ($entity instanceof Conversion) {
                    (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 99);
                }
            },
        );
        $em->expects($this->once())->method('flush');

        $dispatched = 0;
        $bus        = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps = []) use (&$dispatched): Envelope {
                ++$dispatched;

                return new Envelope($message, $stamps);
            },
        );

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($source);

        $manager = $this->buildManager($quota, $s3Client, $em, $repo, $bus);
        $retry   = $manager->retryConversion(42, $owner);

        self::assertSame(99, $retry->getId());
        self::assertNotSame($source, $retry);
        self::assertSame('jpg', $retry->getFromFormat());
        self::assertSame('png', $retry->getToFormat());
        self::assertSame(ConversionStatus::Pending, $retry->getStatus());
        self::assertCount(1, $copied);
        self::assertStringContainsString('inputs/2026/08/01/aabbccddeeff0011.jpg', $copied[0]['src']);
        self::assertStringStartsWith('inputs/', $copied[0]['dst']);
        self::assertNotSame($source->getInputFile()->getStoragePath(), $retry->getInputFile()->getStoragePath());
        self::assertSame(1, $dispatched);
    }

    public function testRetryThrows410WhenInputMissingInS3(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/deadbeefdeadbeef.jpg');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');
        $quota->expects($this->never())->method('charge');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturnCallback(
            static function (): never {
                throw new \AsyncAws\S3\Exception\NoSuchKeyException(
                    new \Symfony\Component\HttpClient\Response\MockResponse(
                        '<?xml version="1.0"?><Error><Code>NoSuchKey</Code></Error>',
                        ['http_code' => 404],
                    ),
                );
            },
        );
        $s3Client->expects($this->never())->method('copyObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
        );

        $this->expectException(GoneHttpException::class);
        $manager->retryConversion(42, $owner);
    }

    public function testRetryRejectsNonOwner(): void
    {
        $owner  = $this->userWithId(10);
        $other  = $this->userWithId(11);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('headObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversion not found');
        $manager->retryConversion(42, $other);
    }

    public function testRetryRejectsUnsafeStorageKeyBeforeS3(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, '../etc/passwd');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->never())->method('headObject');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid storage path');
        $manager->retryConversion(42, $owner);
    }

    public function testRetryRespectsToggleDisabled(): void
    {
        $owner  = $this->userWithId(10);
        $source = $this->seedSource($owner, 'inputs/2026/08/01/aabbccddeeff0011.jpg');

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->never())->method('copyObject');

        $toggle = $this->createMock(ConversionToggleService::class);
        $toggle->expects(self::any())->method('isEnabled')->with('jpg', 'png')->willReturn(false);

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $this->createStub(EntityManagerInterface::class),
            $repo,
            toggle: $toggle,
        );

        $this->expectException(ConversionDisabledException::class);
        $manager->retryConversion(42, $owner);
    }

    /**
     * CNV-85 repair round (the important fix): retry re-validates the STORED
     * options through the same validator POST /convert uses, against the
     * retrying user's CURRENT plan — a downgrade replays a value the user can
     * no longer set, so it must be rejected explicitly (422 via
     * InvalidConversionOptionException), never silently dropped to a default.
     * Uses the synthetic `test.grammar` profile (real pair csv→json, category
     * `data`) — no live field is plan-gated above guest yet (CNV-85 hard
     * constraint), so this is the same fixture approach the validator's own
     * tests use to exercise the grammar independently of today's live fields.
     */
    public function testRetryRejectsStoredOptionNoLongerAccessibleOnCurrentPlan(): void
    {
        $owner  = $this->userWithId(10)->setPlan('free'); // downgraded SINCE the original conversion
        $source = $this->seedSource(
            $owner,
            'inputs/2026/08/01/aabbccddeeff0011.csv',
            from: 'csv',
            to: 'json',
            category: FileCategory::Data,
            // `scale` (default: 20) — what creation-time validation would have
            // materialized regardless of client input; `tint` is minPlan:pro.
            options: ['scale' => 20, 'tint' => '#010203'],
        );

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->never())->method('copyObject');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        // $bus нарочно НЕ передаём (никакой кастомной проверки dispatch): его
        // не позовут физически, т.к. исключение бросается ДО dispatch() —
        // buildManager() сам подключает default-стаб к переданному/дефолтному
        // $bus через безусловный ->method('dispatch'), поэтому свой mock с
        // expects(never()) сюда подмешивать не нужно и небезопасно (двойная
        // конфигурация одного и того же матчера).
        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $em,
            $repo,
            optionsValidator: $this->grammarOptionsValidator(),
        );

        try {
            $manager->retryConversion(42, $owner);
            self::fail('Expected InvalidConversionOptionException');
        } catch (InvalidConversionOptionException $e) {
            self::assertSame(InvalidConversionOptionException::CODE_PLAN_REQUIRED, $e->getErrorCode());
            self::assertStringContainsString('tint', $e->getMessage(), 'Ошибка обязана называть поле, которое её вызвало');
        }
    }

    /**
     * Симметричный случай: план НЕ понизился (или значение и так на любом
     * плане доступно) — ретрай ведёт себя ровно как раньше, без изменения
     * payload'а (no extra normalization drift).
     */
    public function testRetryKeepsStillAccessibleOptionsUnchanged(): void
    {
        $owner  = $this->userWithId(10)->setPlan('pro');
        $source = $this->seedSource(
            $owner,
            'inputs/2026/08/01/aabbccddeeff0011.csv',
            from: 'csv',
            to: 'json',
            category: FileCategory::Data,
            // `scale` (default: 20) is what the ORIGINAL creation would have
            // materialized too (defaults always materialize — see
            // ConversionOptionsValidator's normalization rule), so a
            // realistically-seeded stored payload already carries it. Seeding
            // only `tint` here would make re-validation "add" scale and look
            // like drift, when it is really the fixture omitting what
            // creation-time validation would have written in the first place.
            options: ['scale' => 20, 'tint' => '#010203'],
        );

        $quota = $this->createStub(QuotaService::class);
        $quota->method('check')->willReturn(BillingMode::PlanQuota);

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $s3Client->method('copyObject')->willReturn(ResultMockFactory::create(CopyObjectOutput::class));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 100);
            }
        });

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        // $bus нарочно НЕ передаём — buildManager() сам подключает default-стаб
        // (см. комментарий в тесте выше), сообщение здесь не инспектируется.
        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $em,
            $repo,
            optionsValidator: $this->grammarOptionsValidator(),
        );

        $retry = $manager->retryConversion(42, $owner);

        self::assertSame(ConversionStatus::Pending, $retry->getStatus());
        self::assertSame(['scale' => 20, 'tint' => '#010203'], $retry->getOptions(), 'Доступная на текущем плане опция не должна меняться');
    }

    /**
     * CNV-100 — то же самое, что `testRetryRejectsStoredOptionNoLongerAccessibleOnCurrentPlan`
     * выше, но против БОЕВОГО каталога (`media.video`, поле `resolution`), а не
     * синтетического `test.grammar`: первый ЖИВОЙ plan-гейтнутый field на этом
     * пути (1080p, `minPlan: basic`).
     */
    public function testRetryRejectsMediaVideoOptionAfterDowngrade(): void
    {
        $owner  = $this->userWithId(10)->setPlan('free'); // downgraded SINCE the original conversion (was basic/pro)
        $source = $this->seedSource(
            $owner,
            'inputs/2026/08/01/aabbccddeeff0011.mp4',
            from: 'mp4',
            to: 'mkv',
            category: FileCategory::Video,
            options: ['resolution' => '1080p', 'fps' => '30'],
        );

        $quota = $this->createMock(QuotaService::class);
        $quota->expects($this->never())->method('check');

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('headObject')->willReturn(
            ResultMockFactory::create(HeadObjectOutput::class),
        );
        $s3Client->expects($this->never())->method('copyObject');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        // Тот же паттерн, что и у грамматического теста выше: $bus нарочно не
        // передаём — исключение бросается ДО dispatch().
        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $em,
            $repo,
            optionsValidator: $this->productionOptionsValidator(),
        );

        try {
            $manager->retryConversion(42, $owner);
            self::fail('Expected InvalidConversionOptionException');
        } catch (InvalidConversionOptionException $e) {
            self::assertSame(InvalidConversionOptionException::CODE_PLAN_REQUIRED, $e->getErrorCode());
            self::assertStringContainsString('resolution', $e->getMessage(), 'Ошибка обязана называть поле, которое её вызвало');
        }
    }

    /**
     * Симметрично `testRetryKeepsStillAccessibleOptionsUnchanged` — план НЕ
     * понизился (остался basic), боевой `media.video`-payload ретраится
     * побайтово неизменным.
     */
    public function testRetryKeepsMediaVideoOptionsUnchangedWithoutDowngrade(): void
    {
        $owner  = $this->userWithId(10)->setPlan('basic');
        $source = $this->seedSource(
            $owner,
            'inputs/2026/08/01/aabbccddeeff0011.mp4',
            from: 'mp4',
            to: 'mkv',
            category: FileCategory::Video,
            options: ['resolution' => '1080p', 'fps' => '30'],
        );

        $quota = $this->createStub(QuotaService::class);
        $quota->method('check')->willReturn(BillingMode::PlanQuota);

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('headObject')->willReturn(ResultMockFactory::create(HeadObjectOutput::class));
        $s3Client->method('copyObject')->willReturn(ResultMockFactory::create(CopyObjectOutput::class));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 101);
            }
        });

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($source);

        $manager = $this->buildManager(
            $quota,
            $s3Client,
            $em,
            $repo,
            optionsValidator: $this->productionOptionsValidator(),
        );

        $retry = $manager->retryConversion(42, $owner);

        self::assertSame(ConversionStatus::Pending, $retry->getStatus());
        self::assertSame(['resolution' => '1080p', 'fps' => '30'], $retry->getOptions());
    }

    public function testDeleteRemovesDbRowsAndS3Objects(): void
    {
        $owner = $this->userWithId(10);
        $input = (new FileStorage())
            ->setOriginalName('in.jpg')
            ->setStoragePath('inputs/2026/08/01/aabbccddeeff0011.jpg')
            ->setMimeType('image/jpeg')
            ->setSizeBytes(100);
        $output = (new FileStorage())
            ->setOriginalName('out.png')
            ->setStoragePath('results/2026/08/01/ffeeddccbbaa0011.png')
            ->setMimeType('image/png')
            ->setSizeBytes(200);
        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image)
            ->setStatus(ConversionStatus::Completed);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        $deleted  = [];
        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->exactly(2))->method('deleteObject')->willReturnCallback(
            function (DeleteObjectRequest $req) use (&$deleted): DeleteObjectOutput {
                $deleted[] = ['bucket' => (string) $req->getBucket(), 'key' => (string) $req->getKey()];

                return ResultMockFactory::create(DeleteObjectOutput::class);
            },
        );

        $removed = [];
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3))->method('remove')->willReturnCallback(
            static function (object $entity) use (&$removed): void {
                $removed[] = $entity::class;
            },
        );
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($conv);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $em,
            $repo,
        );
        $manager->deleteConversion(42, $owner);

        self::assertSame([
            ['bucket' => 'convertor-inputs', 'key' => 'inputs/2026/08/01/aabbccddeeff0011.jpg'],
            ['bucket' => 'convertor-results', 'key' => 'results/2026/08/01/ffeeddccbbaa0011.png'],
        ], $deleted);
        self::assertSame([Conversion::class, FileStorage::class, FileStorage::class], $removed);
    }

    public function testDeleteRejectsUnsafeResultKey(): void
    {
        $owner = $this->userWithId(10);
        $input = (new FileStorage())
            ->setOriginalName('in.jpg')
            ->setStoragePath('inputs/2026/08/01/aabbccddeeff0011.jpg')
            ->setMimeType('image/jpeg')
            ->setSizeBytes(100);
        $output = (new FileStorage())
            ->setOriginalName('evil')
            ->setStoragePath('../../secrets')
            ->setMimeType('image/png')
            ->setSizeBytes(1);
        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        $s3Client = $this->createMock(S3Client::class);
        // input удаляется до проверки output — один deleteObject допустим.
        $s3Client->expects($this->once())->method('deleteObject')->willReturn(
            ResultMockFactory::create(DeleteObjectOutput::class),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $repo = $this->createStub(ConversionRepository::class);
        $repo->method('find')->willReturn($conv);

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $em,
            $repo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid storage path');
        $manager->deleteConversion(42, $owner);
    }

    public function testDeleteLogsWarningAndRemovesDbWhenS3Fails(): void
    {
        $owner = $this->userWithId(10);
        $input = (new FileStorage())
            ->setOriginalName('in.jpg')
            ->setStoragePath('inputs/2026/08/01/aabbccddeeff0011.jpg')
            ->setMimeType('image/jpeg')
            ->setSizeBytes(100);
        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat('jpg')
            ->setToFormat('png')
            ->setCategory(FileCategory::Image)
            ->setStatus(ConversionStatus::Completed);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())->method('deleteObject')->willThrowException(
            new \RuntimeException('S3 down'),
        );

        $removed = [];
        $em      = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('remove')->willReturnCallback(
            static function (object $entity) use (&$removed): void {
                $removed[] = $entity::class;
            },
        );
        $em->expects($this->once())->method('flush');

        $repo = $this->createMock(ConversionRepository::class);
        $repo->expects($this->once())->method('find')->with(42)->willReturn($conv);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Не удалось удалить S3-объект при hard-delete конверсии; строка БД будет удалена',
            self::callback(static function (array $ctx): bool {
                return $ctx['bucket']       === 'convertor-inputs'
                    && $ctx['key']          === 'inputs/2026/08/01/aabbccddeeff0011.jpg'
                    && $ctx['conversionId'] === 42
                    && $ctx['error']        === 'S3 down';
            }),
        );

        $manager = $this->buildManager(
            $this->createStub(QuotaService::class),
            $s3Client,
            $em,
            $repo,
            logger: $logger,
        );
        $manager->deleteConversion(42, $owner);

        self::assertSame([Conversion::class, FileStorage::class], $removed);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /** @param array<string, bool|int|string> $options */
    private function seedSource(
        User $owner,
        string $inputKey,
        string $from = 'jpg',
        string $to = 'png',
        FileCategory $category = FileCategory::Image,
        array $options = [],
    ): Conversion {
        $input = (new FileStorage())
            ->setOriginalName("photo.{$from}")
            ->setStoragePath($inputKey)
            ->setMimeType('image/jpeg')
            ->setSizeBytes(1234);

        $conv = (new Conversion())
            ->setUser($owner)
            ->setInputFile($input)
            ->setFromFormat($from)
            ->setToFormat($to)
            ->setCategory($category)
            ->setStatus(ConversionStatus::Completed)
            ->setIsAi(false)
            ->setIsOcr(false)
            ->setOptions($options);
        (new \ReflectionProperty(Conversion::class, 'id'))->setValue($conv, 42);

        return $conv;
    }

    /**
     * Валидатор на синтетическом профиле `test.grammar` (реальная пара
     * csv→json, категория `data`) — тот же фикстурный подход, что и у
     * `ConversionOptionsValidatorTest`, см. `tests/Fixtures/settings_catalog_grammar.json`.
     */
    private function grammarOptionsValidator(): ConversionOptionsValidator
    {
        return new ConversionOptionsValidator(
            new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath()),
            $this->newSeedRegistry(),
        );
    }

    /**
     * CNV-100 — валидатор на БОЕВОМ каталоге (production `conversion_settings.json`),
     * а не синтетическом `test.grammar` — упражняет реальный `media.video`.
     */
    private function productionOptionsValidator(): ConversionOptionsValidator
    {
        return new ConversionOptionsValidator(new ConversionSettingsCatalog(), $this->newSeedRegistry());
    }

    private function buildManager(
        QuotaService $quota,
        S3Client $s3Client,
        EntityManagerInterface $em,
        ConversionRepository $repo,
        ?MessageBusInterface $bus = null,
        ?ConversionToggleService $toggle = null,
        ?LoggerInterface $logger = null,
        ?ConversionOptionsValidator $optionsValidator = null,
    ): ConversionManager {
        $bus ??= $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
        );

        return new ConversionManager(
            $this->newSeedRegistry(),
            $repo,
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            new ConversionChainFailPropagator(
                $this->createStub(ConversionRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(QuotaService::class),
            ),
            $toggle,
            $logger,
            optionsValidator: $optionsValidator,
        );
    }
}
