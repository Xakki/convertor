<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion;

use App\Service\Conversion\CuratedConversionPairs;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CuratedConversionPairs — курируемый (не сгенерированный) список для дропдауна
 * «Conversions» и SEO-страниц пар (home-09-seo-conversion-pages). Список
 * вручную синхронизирован с матрицей ConversionRegistry — этот тест ловит
 * рассинхрон, если DB-backed матрица (registry-05: единственный источник,
 * здесь — seed-фикстура registry-03) когда-нибудь изменится и "выбьет" одну
 * из курируемых пар.
 */
final class CuratedConversionPairsTest extends TestCase
{
    use SeedsConversionRegistry;

    public function testEveryCuratedPairIsSupportedByTheRegistry(): void
    {
        $registry = $this->newSeedRegistry();

        foreach (CuratedConversionPairs::all() as $pair) {
            self::assertTrue(
                $registry->isSupported($pair['from'], $pair['to']),
                "Curated pair {$pair['from']} → {$pair['to']} is not supported by ConversionRegistry",
            );
        }
    }

    public function testGroupedKeepsCategoriesInFirstAppearanceOrderAndDropsCategoryKey(): void
    {
        $grouped = CuratedConversionPairs::grouped();

        self::assertSame(
            ['document', 'image', 'data', 'audio', 'video', 'markup'],
            array_keys($grouped),
        );
        self::assertSame(['from' => 'pdf', 'to' => 'docx'], $grouped['document'][0]);
    }

    public function testEachCategoryHasAtLeastTwoPairs(): void
    {
        // Карточка требует "примерно по 2-3 пары на категорию" — не одну.
        foreach (CuratedConversionPairs::grouped() as $category => $pairs) {
            self::assertGreaterThanOrEqual(2, count($pairs), "Category {$category} has fewer than 2 curated pairs");
        }
    }
}
