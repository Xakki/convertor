<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service\Storage;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Service\Storage\FileCleanupService;
use App\Service\Storage\S3Storage;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Input\DeleteObjectRequest;
use AsyncAws\S3\Result\DeleteObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Живой прогон авто-очистки против реальной тест-БД (convertor-test). Проверяет
 * то, что чистый unit-мок пропустит: корректность запроса findExpiredCandidates,
 * пер-конверсия разрешение порога (status → tariff → category → fallback), гейт
 * Processing, FK-порядок удаления (Conversion → FileStorage) и оркестрацию.
 * S3Storage — реальный поверх мока S3Client (класс final), удаления перехватываем.
 *
 * Пороги (services.yaml defaults): status failed/expired=24, tariff guest=24 /
 * free=0(skip) / paid=720, category video=48, fallback 240. Свежие строки других
 * тестов (createdAt = now) под грубый пре-фильтр (min порог 24 ч) не попадают.
 */
final class FileCleanupServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<array{class: class-string, id: int}> строки к подчистке (по id) */
    private array $toRemove = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        // Чистим по id (сервис уже мог удалить строки + em->clear() → объекты
        // детачнуты; find по id надёжнее обращения к getId() детача).
        foreach (array_reverse($this->toRemove) as $ref) {
            $fresh = $this->em->find($ref['class'], $ref['id']);
            if ($fresh !== null) {
                $this->em->remove($fresh);
            }
        }
        $this->em->flush();
        $this->toRemove = [];

        parent::tearDown();
    }

    public function testPerConversionRetentionResolution(): void
    {
        $container = static::getContainer();

        /** @var list<array{bucket: string, key: string}> $deleted */
        $deleted  = [];
        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('deleteObject')->willReturnCallback(
            function (DeleteObjectRequest $req) use (&$deleted): DeleteObjectOutput {
                $deleted[] = ['bucket' => (string) $req->getBucket(), 'key' => (string) $req->getKey()];

                return ResultMockFactory::create(DeleteObjectOutput::class);
            },
        );
        $container->set(S3Storage::class, new S3Storage($s3Client, 'test_'));

        // [label, status, category, plan, isGuest, ageHours, expectDeleted]
        $paidVideoOld     = $this->seed('paid+video 300h', ConversionStatus::Completed, FileCategory::Video, 'paid', false, 300, false);
        $paidVideoAncient = $this->seed('paid+video 800h', ConversionStatus::Completed, FileCategory::Video, 'paid', false, 800, true);
        $freeVideoDel     = $this->seed('free+video 60h', ConversionStatus::Completed, FileCategory::Video, 'free', false, 60, true);
        $freeVideoKeep    = $this->seed('free+video 30h', ConversionStatus::Completed, FileCategory::Video, 'free', false, 30, false);
        $guestDoc         = $this->seed('guest+doc 30h', ConversionStatus::Completed, FileCategory::Document, 'free', true, 30, true);
        $failedPaid       = $this->seed('paid+failed 30h', ConversionStatus::Failed, FileCategory::Document, 'paid', false, 30, true);
        $processing       = $this->seed('processing 999h', ConversionStatus::Processing, FileCategory::Document, 'paid', false, 999, false);
        $freeImgKeep      = $this->seed('free+img 100h', ConversionStatus::Completed, FileCategory::Image, 'free', false, 100, false);
        $freeImgDel       = $this->seed('free+img 300h', ConversionStatus::Completed, FileCategory::Image, 'free', false, 300, true);

        $cases = [
            $paidVideoOld, $paidVideoAncient, $freeVideoDel, $freeVideoKeep,
            $guestDoc, $failedPaid, $processing, $freeImgKeep, $freeImgDel,
        ];

        // Сервис берётся из контейнера ПОСЛЕ подмены S3Storage — autowire подтянет мок.
        $container->get(FileCleanupService::class)->run();

        foreach ($cases as $c) {
            $conv = $this->em->find(Conversion::class, $c['convId']);
            if ($c['expectDeleted']) {
                self::assertNull($conv, $c['label'] . ' должен быть удалён');
                self::assertNull($this->em->find(FileStorage::class, $c['inputId']), $c['label'] . ' вход удалён');
                self::assertNull($this->em->find(FileStorage::class, $c['outputId']), $c['label'] . ' выход удалён');
            } else {
                self::assertNotNull($conv, $c['label'] . ' должен уцелеть');
            }
        }

        // S3: удалённый кейс — оба объекта из нужных бакетов; уцелевший — не трогаем.
        self::assertContains(['bucket' => 'test_-inputs', 'key' => $paidVideoAncient['inputKey']], $deleted);
        self::assertContains(['bucket' => 'test_-results', 'key' => $paidVideoAncient['outputKey']], $deleted);
        self::assertNotContains(['bucket' => 'test_-inputs', 'key' => $processing['inputKey']], $deleted);
        self::assertNotContains(['bucket' => 'test_-inputs', 'key' => $paidVideoOld['inputKey']], $deleted);
    }

    /**
     * @return array{label: string, convId: int, inputId: int, outputId: int, inputKey: string, outputKey: string, expectDeleted: bool}
     */
    private function seed(
        string $label,
        ConversionStatus $status,
        FileCategory $category,
        string $plan,
        bool $isGuest,
        int $ageHours,
        bool $expectDeleted,
    ): array {
        $user = (new User())->setPlan($plan)->setIsGuest($isGuest);
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

        $conv = (new Conversion())
            ->setUser($user)
            ->setInputFile($input)
            ->setOutputFile($output)
            ->setFromFormat('bin')
            ->setToFormat('bin')
            ->setCategory($category)
            ->setStatus($status)
            ->setIsAi(false)
            ->setIsOcr(false);

        // Бэкдейтим createdAt — конструктор ставит now().
        (new \ReflectionProperty(Conversion::class, 'createdAt'))
            ->setValue($conv, new \DateTimeImmutable('-' . $ageHours . ' hours'));

        $this->em->persist($conv);
        $this->em->flush();

        $this->toRemove[] = ['class' => User::class, 'id' => $user->getId()];
        $this->toRemove[] = ['class' => FileStorage::class, 'id' => $input->getId()];
        $this->toRemove[] = ['class' => FileStorage::class, 'id' => $output->getId()];
        $this->toRemove[] = ['class' => Conversion::class, 'id' => $conv->getId()];

        return [
            'label'         => $label,
            'convId'        => $conv->getId(),
            'inputId'       => $input->getId(),
            'outputId'      => $output->getId(),
            'inputKey'      => $inputKey,
            'outputKey'     => $outKey,
            'expectDeleted' => $expectDeleted,
        ];
    }
}
