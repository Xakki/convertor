<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Функциональный тест SPA-оболочки `/admin` (Option B — открытая оболочка,
 * реальная граница безопасности на JSON-API, см. `AdminController`).
 *
 * registry-07: единственный практически доступный способ проверить, что
 * `templates/admin/workers.html.twig` реально КОМПИЛИРУЕТСЯ и встраивается
 * в SPA-шелл (не только "валидный Twig-синтаксис" — `lint:twig` уже
 * проверен отдельно, но он не гоняет секцию через реальный рендер с
 * реальными Twig-блоками/inheritance). Это НЕ замена визуальной проверки —
 * не проверяет Alpine-реактивность, HTMX-поллинг или вёрстку глазами; та
 * часть остаётся ручной (см. Execution Log карточки).
 */
final class AdminControllerTest extends WebTestCase
{
    public function testDashboardRendersSuccessfully(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Proves the Workers section actually renders as part of the shell:
     * its anchor id, its Alpine component hook, and the sidebar nav link
     * are all present in the compiled HTML.
     */
    public function testDashboardIncludesWorkersSection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="workers"', $html, 'section anchor present');
        self::assertStringContainsString('adminWorkers()', $html, 'Alpine component wired via x-data');
        self::assertStringContainsString('href="#workers"', $html, 'sidebar nav link present');
        self::assertStringContainsString('/api/v1/admin/workers', $html, 'JSON endpoint URL present in the poll code');
    }

    /** Existing /admin/queues section must remain present and unchanged (card AC). */
    public function testDashboardStillIncludesQueuesSection(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="queues"', $html);
        self::assertStringContainsString('adminQueues()', $html);
    }
}
