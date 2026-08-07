<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Repository\WorkerCapabilityRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CNV-71-02 — the whole point of the card: `GET /api/v1/formats` and
 * `GET /convert/{source}-to-{target}` must keep working even when
 * `worker_capabilities` is completely empty (no registered workers, no seed
 * rows). Before this card both endpoints were fed by
 * {@see \App\Service\Conversion\ConversionRegistry}'s DB-backed routing matrix
 * — an empty table meant an empty `/formats` response and a 404 on every SEO
 * conversion-pair page. After the card, the routing matrix comes from the
 * committed static catalog `config/catalog/conversion_pairs.json`, entirely
 * independent of the DB.
 *
 * Proof method: stub `WorkerCapabilityRepository::findAllCapabilities()` to
 * return `[]` in the test container (same `container->set()` pattern used by
 * `WorkerRegisterControllerTest`) and hit both routes for real over HTTP.
 */
final class FormatsCatalogIndependenceTest extends WebTestCase
{
    private const EXPECTED_CATALOG_PAIR_COUNT = 394;

    private function withEmptyWorkerCapabilities(): void
    {
        $container = static::getContainer();

        $repo = $this->createStub(WorkerCapabilityRepository::class);
        $repo->method('findAllCapabilities')->willReturn([]);
        $container->set(WorkerCapabilityRepository::class, $repo);
    }

    public function testFormatsEndpointReturnsFullCatalogWithEmptyWorkerCapabilitiesTable(): void
    {
        $client = static::createClient();
        $this->withEmptyWorkerCapabilities();

        $client->request('GET', '/api/v1/formats');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body['formats'] ?? null);
        self::assertCount(
            self::EXPECTED_CATALOG_PAIR_COUNT,
            $body['formats'],
            '/api/v1/formats must serve the full static catalog regardless of worker_capabilities content',
        );

        $pairs = array_map(
            static fn (array $f): string => "{$f['from']}->{$f['to']}",
            $body['formats'],
        );
        self::assertContains('docx->pdf', $pairs);
        self::assertContains('csv->json', $pairs);
    }

    public function testConversionPairPageRendersWithEmptyWorkerCapabilitiesTable(): void
    {
        $client = static::createClient();
        $this->withEmptyWorkerCapabilities();

        $client->request('GET', '/convert/csv-to-json');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('csv', strtolower($html));
        self::assertStringContainsString('json', strtolower($html));
    }
}
