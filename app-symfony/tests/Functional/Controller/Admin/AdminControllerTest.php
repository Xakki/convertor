<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональный тест HTML-оболочки админки (Option B — открытая оболочка,
 * реальная граница безопасности на JSON-API, см. `AdminController`).
 *
 * Каждая секция — отдельный GET-роут; `/admin` отдаёт только overview.
 * Это НЕ замена визуальной проверки — не проверяет Alpine-реактивность,
 * HTMX-поллинг или вёрстку глазами.
 */
final class AdminControllerTest extends WebTestCase
{
    /** Section root ids that live only in their own page partials. */
    private const OTHER_SECTION_IDS = [
        'workers',
        'queues',
        'users',
        'logs',
        'toggle',
        'examples',
    ];

    public function testDashboardRendersOverviewOnly(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="overview"', $html, 'overview section present');
        self::assertStringContainsString('adminStats()', $html, 'Alpine stats hook present');
        self::assertStringContainsString('chart.js', $html, 'Chart.js loaded on overview');

        foreach (self::OTHER_SECTION_IDS as $id) {
            self::assertStringNotContainsString(
                'id="' . $id . '"',
                $html,
                sprintf('section id="%s" must not appear on /admin', $id),
            );
        }

        self::assertPathNavPresent($html);
        self::assertStringNotContainsString('href="#workers"', $html);
        self::assertStringNotContainsString('href="#queues"', $html);
    }

    /**
     * Proves the Workers page actually renders its partial:
     * section anchor, Alpine hook, path nav, and JSON endpoint URL.
     */
    public function testWorkersPageIncludesWorkersSection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/workers');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="workers"', $html, 'section anchor present');
        self::assertStringContainsString('adminWorkers()', $html, 'Alpine component wired via x-data');
        self::assertStringContainsString('href="/admin/workers"', $html, 'sidebar path nav link present');
        self::assertStringNotContainsString('href="#workers"', $html);
        self::assertStringContainsString('/api/v1/admin/workers', $html, 'JSON endpoint URL present in the poll code');
        self::assertStringNotContainsString('id="overview"', $html);
        self::assertStringNotContainsString('chart.js', $html);
    }

    public function testQueuesPageIncludesQueuesSection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/queues');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="queues"', $html);
        self::assertStringContainsString('adminQueues()', $html);
        self::assertStringContainsString('href="/admin/queues"', $html);
        self::assertStringNotContainsString('href="#queues"', $html);
        self::assertStringNotContainsString('id="overview"', $html);
    }

    public function testConversionsPageIncludesToggleSection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/conversions');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // nav_active key remains `toggle` while the route is /admin/conversions
        self::assertStringContainsString('id="toggle"', $html);
        self::assertStringContainsString('adminToggle()', $html);
        self::assertStringContainsString('href="/admin/conversions"', $html);
        self::assertStringContainsString('/api/v1/admin/conversions-toggle', $html);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function sectionPageProvider(): iterable
    {
        yield 'users' => ['/admin/users', 'id="users"', 'adminUsers()'];
        yield 'logs' => ['/admin/logs', 'id="logs"', 'adminLogs()'];
        yield 'examples' => ['/admin/examples', 'id="examples"', 'adminExamples()'];
    }

    #[DataProvider('sectionPageProvider')]
    public function testSectionPageRendersOwnPartial(string $path, string $sectionIdNeedle, string $alpineHook): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString($sectionIdNeedle, $html);
        self::assertStringContainsString($alpineHook, $html);
        self::assertPathNavPresent($html);
        self::assertStringNotContainsString('id="overview"', $html);
    }

    private static function assertPathNavPresent(string $html): void
    {
        self::assertStringContainsString('href="/admin"', $html);
        self::assertStringContainsString('href="/admin/users"', $html);
        self::assertStringContainsString('href="/admin/queues"', $html);
        self::assertStringContainsString('href="/admin/workers"', $html);
        self::assertStringContainsString('href="/admin/logs"', $html);
        self::assertStringContainsString('href="/admin/conversions"', $html);
        self::assertStringContainsString('href="/admin/examples"', $html);
    }
}
