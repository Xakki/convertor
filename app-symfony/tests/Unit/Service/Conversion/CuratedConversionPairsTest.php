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

    /**
     * CNV-71-02: `CuratedConversionPairs` is a hand-maintained editorial
     * SUBSET of the committed catalog (`config/catalog/conversion_pairs.json`)
     * — used for the header dropdown / SEO pages, kept manually in sync rather
     * than generated. This guards against that manual sync silently drifting:
     * every curated pair must exist in the real catalog-backed registry AND
     * its hand-written `category` must match what the registry actually
     * routes it to.
     */
    public function testEveryCuratedPairCategoryMatchesTheCatalog(): void
    {
        $registry = $this->newSeedRegistry();

        foreach (CuratedConversionPairs::all() as $pair) {
            self::assertSame(
                $pair['category'],
                $registry->getCategory($pair['from'], $pair['to'])->value,
                "Curated pair {$pair['from']} → {$pair['to']} declares category "
                . "\"{$pair['category']}\" but the catalog disagrees",
            );
        }
    }

    public function testGroupedKeepsCategoriesInFirstAppearanceOrderAndDropsCategoryKey(): void
    {
        $grouped = CuratedConversionPairs::grouped();

        self::assertSame(
            ['document', 'image', 'data', 'audio', 'video'],
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
