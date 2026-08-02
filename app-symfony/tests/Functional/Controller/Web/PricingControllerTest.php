<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** CNV-59: маршрут GET /pricing зарегистрирован. */
final class PricingControllerTest extends WebTestCase
{
    public function testPricingPageReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
