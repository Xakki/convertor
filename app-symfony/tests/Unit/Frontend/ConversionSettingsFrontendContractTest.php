<?php

declare(strict_types=1);

namespace App\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * CNV-92: контракт Alpine/HTMX-формы остаётся управляемым версионированным
 * payload форматов вместо локального списка image-опций.
 */
final class ConversionSettingsFrontendContractTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../templates/partials/_converter_app_script.html.twig';
    private const FORM   = __DIR__ . '/../../../templates/conversion/index.html.twig';

    public function testRendererUsesEverySupportedFieldTypeAndApiBoundaries(): void
    {
        $script = file_get_contents(self::SCRIPT);
        $form   = file_get_contents(self::FORM);

        self::assertIsString($script);
        self::assertIsString($form);
        self::assertStringContainsString("['range', 'select', 'number', 'text', 'boolean', 'color']", $script);
        self::assertStringContainsString('field.min', $form);
        self::assertStringContainsString('field.max', $form);
        self::assertStringContainsString('field.options', $form);
        self::assertStringContainsString('field.editable === true', $script);
        self::assertStringContainsString('option.editable === true', $script);
        self::assertStringContainsString('settingsValue(field)', $form);
    }

    public function testStateIsVersionedAndKeyedByTargetFormat(): void
    {
        $script = file_get_contents(self::SCRIPT);

        self::assertIsString($script);
        self::assertStringContainsString("'convertor:settings:' + this.toFormat", $script);
        self::assertStringContainsString("saved.version === this.settingsVersion", $script);
        self::assertStringContainsString('this.persistSettings()', $script);
        self::assertStringContainsString('const settings = this.normalizedSettings()', $script);
    }

    public function testInvalidProfilesFailClosedAndOcrHidesSettings(): void
    {
        $script = file_get_contents(self::SCRIPT);
        $form   = file_get_contents(self::FORM);

        self::assertIsString($script);
        self::assertIsString($form);
        self::assertStringContainsString('if (!this.isValidFormatsPayload(payload))', $script);
        self::assertStringContainsString('this.settingsProfiles = {}', $script);
        self::assertStringContainsString('if (this.ocr) return {}', $script);
        self::assertStringContainsString('settingsFields.length > 0 && !ocr', $form);
    }

    public function testFormatsAreFetchedThroughAuthAwareTransportAfterRefresh(): void
    {
        $script = file_get_contents(self::SCRIPT);

        self::assertIsString($script);
        self::assertStringContainsString('this.tryRefresh().then((ok)', $script);
        self::assertStringContainsString('this.loadFormats()', $script);
        self::assertStringContainsString("this.authFetch('/api/v1/formats')", $script);
        self::assertStringNotContainsString("fetch('/api/v1/formats')", $script);
    }
}
