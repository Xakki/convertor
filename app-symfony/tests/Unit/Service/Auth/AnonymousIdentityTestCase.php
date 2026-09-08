<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

abstract class AnonymousIdentityTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Request::setTrustedProxies([], 0);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }
}
