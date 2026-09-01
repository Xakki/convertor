<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConversionOpenApiTest extends WebTestCase
{
    public function testConvertDocumentsMultipartModelOptionAndValidationErrors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $openApi   = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $operation = $openApi['paths']['/api/v1/convert']['post'];
        $multipart = $operation['requestBody']['content']['multipart/form-data'];

        self::assertSame(
            [
                'style'   => 'form',
                'explode' => true,
            ],
            $multipart['encoding']['options[model]'],
        );
        self::assertSame('string', $multipart['schema']['properties']['options[model]']['type']);
        self::assertStringContainsString(
            'GET /api/v1/formats',
            $multipart['schema']['properties']['options[model]']['description'],
        );
        self::assertStringContainsString(
            'settings.profiles',
            $multipart['schema']['properties']['options[model]']['description'],
        );

        $validationResponse = $operation['responses']['422'];
        self::assertSame(
            ['error'],
            $validationResponse['content']['application/json']['schema']['required'],
        );
        self::assertSame('string', $validationResponse['content']['application/json']['schema']['properties']['error']['type']);
        self::assertSame('string', $validationResponse['content']['application/json']['schema']['properties']['message']['type']);
        self::assertStringContainsString(
            'invalid_option_value',
            $validationResponse['content']['application/json']['schema']['properties']['error']['description'],
        );
    }

    public function testConvertAndRetryDocumentWorkerUnavailableSemantics(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $openApi             = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $expectedDescription = 'Требуемая возможность воркера временно недоступна. Для API-задач нужны свежие активные регистрации: каждая должна соответствовать `ApiCapabilityContract`, а общее множество валидированных моделей должно быть непустым. Для остальных типов действует сохранённая регистрация возможности независимо от кратковременной потери активности.';

        foreach (['/api/v1/convert', '/api/v1/convert/{id}/retry'] as $path) {
            $description = $openApi['paths'][$path]['post']['responses']['503']['description'];

            self::assertSame($expectedDescription, $description);
            self::assertStringNotContainsString('никогда не регистрировался', $description);
        }
    }
}
