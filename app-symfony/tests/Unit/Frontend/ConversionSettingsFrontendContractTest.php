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

    public function testCatalogValidationCoversEveryRendererDereference(): void
    {
        $script = file_get_contents(self::SCRIPT);

        self::assertIsString($script);
        self::assertStringContainsString('this.isPlainRecord(payload.settings.profiles)', $script);
        self::assertStringContainsString('this.isValidFormatEntry(format)', $script);
        self::assertStringContainsString('profile.id !== profileId', $script);
        self::assertStringContainsString('field.default === undefined || field.default === null', $script);
        self::assertStringContainsString('typeof option.editable !== \'boolean\'', $script);
        self::assertStringContainsString('option.editable === true', $script);
    }

    public function testNumericSettingsMatchBackendIntegerAndStepContract(): void
    {
        $script = file_get_contents(self::SCRIPT);

        self::assertIsString($script);
        self::assertStringContainsString('Number.isSafeInteger(field.step) && field.step > 0', $script);
        self::assertStringContainsString('this.isStepAlignedNumber(value, field)', $script);
        self::assertStringContainsString('Number.isSafeInteger(number)', $script);
        self::assertStringContainsString('(number - field.min) % field.step === 0', $script);
    }

    public function testSavedStateIsPlainRecordAndStorageFailuresAreIgnored(): void
    {
        $script = file_get_contents(self::SCRIPT);

        self::assertIsString($script);
        self::assertStringContainsString('this.isPlainRecord(saved.values)', $script);
        self::assertStringContainsString('Array.isArray(saved.values)', $script);
        self::assertStringContainsString('this.isCompatibleSettingsValue(field, value)', $script);
        self::assertStringContainsString('localStorage.setItem(this.settingsStorageKey()', $script);
        self::assertStringContainsString('corrupted or unavailable local storage is safe to ignore', $script);
    }
}
