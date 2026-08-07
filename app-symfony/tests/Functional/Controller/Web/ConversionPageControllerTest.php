<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /convert/{source}-to-{target} (home-09-seo-conversion-pages) — SEO
 * landing per conversion pair. Слаг парсится по `-to-`, валидация через
 * ConversionRegistry::isSupported() (см. ConversionPageController).
 */
final class ConversionPageControllerTest extends WebTestCase
{
    public function testSupportedPairRendersUniqueTitleAndH1InEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/convert/csv-to-json');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Convert CSV to JSON');
        self::assertSelectorTextContains('h1', 'Convert CSV to JSON');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('name="description"', $html);
        self::assertStringContainsString('rel="canonical"', $html);
        self::assertStringContainsString('href="http://localhost/convert/csv-to-json"', $html);
        self::assertStringNotContainsString('{{', $html);
    }

    public function testSupportedPairRendersInRussian(): void
    {
        $client = static::createClient();
        $client->request('GET', '/convert/csv-to-json', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Конвертировать CSV в JSON');
        self::assertSelectorTextContains('h1', 'Конвертировать CSV в JSON');
    }

    public function testFormLocksSourceAndTargetFromUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/convert/csv-to-json');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // Зафиксированная пара — НЕ <select> с двумя опциями формата, а
        // статичная плашка + converterApp(...) c lockedFrom/lockedTo (AC карточки).
        self::assertStringContainsString("lockedFrom: 'csv'", $html);
        self::assertStringContainsString("lockedTo: 'json'", $html);
        self::assertStringContainsString('lockedCategory:', $html);
    }

    public function testUnsupportedPairIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/convert/foo-to-bar');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedPairSlugIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/convert/csvjson');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSelfConversionPairIs404(): void
    {
        // csv→csv не входит в матрицу (from===to пары отфильтрованы при
        // редукции каталога — ConversionRegistry::reduceCapabilities()) —
        // тоже 404, не 200 с "пустой" парой.
        $client = static::createClient();
        $client->request('GET', '/convert/csv-to-csv');

        self::assertResponseStatusCodeSame(404);
    }
}
