<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Examples;

use App\Service\Examples\ExampleCatalog;
use App\Service\Examples\ExampleDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты каталога примеров (home-04): целостность набора, whitelist-поиск
 * и построение стабильных S3-ключей/MIME. Чистый сервис — без БД/S3/пайплайна.
 */
final class ExampleCatalogTest extends TestCase
{
    public function testAllCoversRequiredCategories(): void
    {
        $catalog    = new ExampleCatalog();
        $categories = array_map(static fn (ExampleDefinition $d): string => $d->category, $catalog->all());

        foreach ($catalog->requiredCategories() as $required) {
            self::assertContains($required, $categories, "Обязательная категория {$required} отсутствует в каталоге");
        }
    }

    public function testSampleFilesExistOnDisk(): void
    {
        $dir = \dirname(__DIR__, 4) . '/resources/seed-examples/';

        foreach ((new ExampleCatalog())->all() as $def) {
            self::assertFileExists($dir . $def->sampleFile, "Нет файла-сэмпла для {$def->category}");
        }
    }

    public function testDefinitionBuildsStableKeyAndName(): void
    {
        $def = new ExampleDefinition('image', 'png', 'jpg', 'image.png', false);

        self::assertSame('png-to-jpg', $def->slug());
        self::assertSame('png-to-jpg.jpg', $def->objectName());
        self::assertSame('examples/image/png-to-jpg.jpg', $def->s3Key());
        self::assertSame('image/jpeg', $def->mime());
        // admin-managed-examples: исходник теперь тоже дублируется в S3.
        self::assertSame('png-to-jpg-source.png', $def->sourceObjectName());
        self::assertSame('examples/image/png-to-jpg-source.png', $def->sourceS3Key());
    }

    public function testFindMatchesWhitelistOnly(): void
    {
        $catalog = new ExampleCatalog();

        self::assertNull($catalog->find('image', '../secret.txt'), 'Произвольное имя не должно резолвиться');
        self::assertNull($catalog->find('bogus', 'png-to-jpg.jpg'), 'Неизвестная категория не должна резолвиться');

        $found = $catalog->find('image', 'png-to-jpg.jpg');
        self::assertInstanceOf(ExampleDefinition::class, $found);
        self::assertSame('image', $found->category);
    }

    public function testMimeFallsBackToOctetStream(): void
    {
        $def = new ExampleDefinition('data', 'csv', 'unknownformat', 'data.csv', false);

        self::assertSame('application/octet-stream', $def->mime());
    }

    public function testSourceMimeUsesFromFormat(): void
    {
        $def = new ExampleDefinition('image', 'png', 'jpg', 'image.png', false);

        self::assertSame('image/png', $def->sourceMime());
        self::assertSame('image/jpeg', $def->mime());
    }

    public function testFindBySourceMatchesWhitelistOnly(): void
    {
        $catalog = new ExampleCatalog();

        self::assertNull($catalog->findBySource('document', '../../etc/passwd'), 'Произвольное имя не должно резолвиться');
        self::assertNull($catalog->findBySource('bogus', 'document.txt'), 'Неизвестная категория не должна резолвиться');
        self::assertNull($catalog->findBySource('image', 'document.txt'), 'Категория и sampleFile должны совпадать оба');

        $found = $catalog->findBySource('document', 'document.txt');
        self::assertInstanceOf(ExampleDefinition::class, $found);
        self::assertSame('document', $found->category);
    }
}
