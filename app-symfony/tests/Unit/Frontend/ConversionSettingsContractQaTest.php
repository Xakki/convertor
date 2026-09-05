<?php

declare(strict_types=1);

namespace App\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * CNV-94: сквозные контракты каталога и лёгкого Alpine-тестового seam.
 *
 * Полноценного frontend harness в проекте нет, поэтому проверки намеренно
 * читают тот же JSON и Twig-скрипт, который публикует приложение: это ловит
 * дрейф схемы без новой тяжёлой зависимости.
 */
final class ConversionSettingsContractQaTest extends TestCase
{
    private const CATALOG = __DIR__ . '/../../../config/catalog/conversion_settings.json';
    private const SCRIPT  = __DIR__ . '/../../../templates/partials/_converter_app_script.html.twig';

    public function testProductionCatalogAssignmentsAreUniqueAndReferenceNonEmptyProfiles(): void
    {
        $catalog  = $this->catalog();
        $profiles = $catalog['profiles'];
        $keys     = [];

        foreach ($catalog['assignments'] as $assignment) {
            self::assertArrayHasKey($assignment['profile'], $profiles);
            self::assertNotSame([], $profiles[$assignment['profile']]['fields']);
            self::assertArrayHasKey('ocr', $assignment);
            self::assertArrayHasKey('animated', $assignment);
            self::assertIsBool($assignment['ocr']);
            self::assertIsBool($assignment['animated']);

            $key = json_encode([
                $assignment['profile'],
                $assignment['category'],
                $assignment['from'] ?? null,
                $assignment['to']   ?? null,
                $assignment['ocr'],
                $assignment['animated'],
            ], JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey($key, $keys, 'Точная assignment не должна дублироваться');
            $keys[$key] = true;
        }
    }

    public function testEveryPublishedFieldHasClosedTypeAndAccessMetadata(): void
    {
        $allowedTypes = ['range', 'select', 'number', 'text', 'boolean', 'color'];

        foreach ($this->catalog()['profiles'] as $profileId => $profile) {
            $fieldKeys = [];
            foreach ($profile['fields'] as $field) {
                self::assertArrayNotHasKey($field['key'], $fieldKeys, "Дубликат поля {$profileId}.{$field['key']}");
                self::assertContains($field['type'], $allowedTypes);
                self::assertMatchesRegularExpression('/^[a-z][a-zA-Z0-9_]*$/', $field['key']);
                self::assertContains($field['minPlan'], ['guest', 'free', 'basic', 'pro']);
                $fieldKeys[$field['key']] = true;

                if ($field['type'] === 'select') {
                    if (($field['dynamic'] ?? false) === true) {
                        self::assertArrayNotHasKey('options', $field);
                    } else {
                        self::assertNotEmpty($field['options']);
                        foreach ($field['options'] as $option) {
                            self::assertContains($option['minPlan'], ['guest', 'free', 'basic', 'pro']);
                        }
                    }
                }
            }
        }
    }

    public function testFrontendSettingsAreProfileDrivenAndNotImageOnly(): void
    {
        $script = $this->script();

        self::assertStringContainsString('selectedSettingsProfile', $script);
        self::assertStringContainsString('this.settingsFields', $script);
        self::assertStringContainsString('for (const field of this.settingsFields)', $script);
        self::assertStringContainsString('for (const [key, value] of Object.entries(settings))', $script);
        self::assertStringNotContainsString("options['width']", $script);
        self::assertStringNotContainsString("options['height']", $script);
        self::assertStringNotContainsString("options['quality']", $script);
    }

    public function testFrontendPersistenceIsVersionedPerTargetAndRejectsStaleValues(): void
    {
        $script = $this->script();

        self::assertStringContainsString("'convertor:settings:' + this.toFormat", $script);
        self::assertStringContainsString('saved.version === this.settingsVersion', $script);
        self::assertStringContainsString('this.isCompatibleSettingsValue(field, value)', $script);
        self::assertStringContainsString('this.persistSettings()', $script);
        self::assertStringContainsString('this.applySettingsDefaults()', $script);
    }

    public function testFrontendAuthAndOcrBoundariesArePreserved(): void
    {
        $script = $this->script();

        self::assertStringContainsString('this.tryRefresh().then((ok)', $script);
        self::assertStringContainsString("this.authFetch('/api/v1/formats')", $script);
        self::assertStringNotContainsString("fetch('/api/v1/formats')", $script);
        self::assertStringContainsString('if (this.ocr) return {}', $script);
        self::assertStringContainsString("if (this.showOcr && this.ocr) fd.append('ocr', '1')", $script);
    }

    /** @return array{version: string, profiles: array<string, array{fields: list<array<string, mixed>}>}, assignments: list<array<string, mixed>>} */
    private function catalog(): array
    {
        $decoded = json_decode((string) file_get_contents(self::CATALOG), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['version'] ?? null);
        self::assertIsArray($decoded['profiles'] ?? null);
        self::assertIsArray($decoded['assignments'] ?? null);

        return $decoded;
    }

    private function script(): string
    {
        $script = file_get_contents(self::SCRIPT);
        self::assertIsString($script);

        return $script;
    }
}
