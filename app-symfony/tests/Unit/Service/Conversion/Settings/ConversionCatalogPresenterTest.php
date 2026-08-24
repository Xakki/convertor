<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion\Settings;

use App\Service\Conversion\Settings\ConversionCatalogPresenter;
use App\Service\Conversion\Settings\ConversionSettingsCatalog;
use App\Service\Conversion\Settings\SettingsAccessLevel;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * CNV-85 — форма тела `GET /api/v1/formats`: версионированные ДЕДУПЛИЦИРОВАННЫЕ
 * профили + явная ссылка (или явный `null`) у КАЖДОЙ пары, персонализация по
 * уровню доступа.
 */
final class ConversionCatalogPresenterTest extends TestCase
{
    use SeedsConversionRegistry;

    private function productionPresenter(): ConversionCatalogPresenter
    {
        return new ConversionCatalogPresenter($this->newSeedRegistry(), new ConversionSettingsCatalog());
    }

    public function testEveryPairEitherReferencesAProfileOrExplicitlyDeclaresNone(): void
    {
        $payload = $this->productionPresenter()->present(SettingsAccessLevel::Guest);

        self::assertNotSame([], $payload['formats']);

        foreach ($payload['formats'] as $pair) {
            self::assertArrayHasKey(
                'settingsProfile',
                $pair,
                "Пара {$pair['from']}→{$pair['to']} обязана НЕСТИ ключ settingsProfile (пусть и null)",
            );

            $profileId = $pair['settingsProfile'];
            if ($profileId !== null) {
                self::assertArrayHasKey($profileId, $payload['settings']['profiles']);
            }
        }
    }

    public function testProfilesAreDeduplicatedAndVersioned(): void
    {
        $payload = $this->productionPresenter()->present(SettingsAccessLevel::Guest);

        self::assertNotSame('', $payload['settings']['version']);

        $referenced = array_filter(array_column($payload['formats'], 'settingsProfile'));
        self::assertGreaterThan(
            count($payload['settings']['profiles']),
            count($referenced),
            'Дедупликация: ссылок на профили должно быть кратно больше, чем самих профилей',
        );
        foreach (array_unique($referenced) as $id) {
            self::assertArrayHasKey($id, $payload['settings']['profiles']);
        }
    }

    public function testKnownPairsCarryTheExpectedProfile(): void
    {
        $payload = $this->productionPresenter()->present(SettingsAccessLevel::Guest);

        $byPair = [];
        foreach ($payload['formats'] as $pair) {
            $byPair["{$pair['from']}->{$pair['to']}"] = $pair['settingsProfile'];
        }

        self::assertSame('image.jpeg', $byPair['png->jpg'] ?? 'missing');
        self::assertSame('image.lossy', $byPair['png->webp'] ?? 'missing');
        self::assertSame('image.raster', $byPair['jpg->png'] ?? 'missing');
        // ?? здесь НЕЛЬЗЯ: у пары без настроек значение и есть null.
        self::assertArrayHasKey('docx->pdf', $byPair);
        self::assertNull($byPair['docx->pdf']);

        // CNV-97 — PDF/TXT/Markdown триангль.
        self::assertSame('document.pdf', $byPair['txt->pdf'] ?? 'missing');
        self::assertSame('document.pdf', $byPair['md->pdf'] ?? 'missing');
        self::assertSame('document.txt', $byPair['pdf->txt'] ?? 'missing');
        self::assertSame('document.txt', $byPair['md->txt'] ?? 'missing');
        // CNV-98: markdownDialect не рекламируется для pdf→md — отдельный профиль.
        self::assertSame('document.markdown.verbatim', $byPair['pdf->md'] ?? 'missing');
        self::assertSame('document.markdown', $byPair['txt->md'] ?? 'missing');
        // DOCX/ODT делят category=document с PDF/TXT/MD, но профиля не получают.
        self::assertArrayHasKey('odt->pdf', $byPair);
        self::assertNull($byPair['odt->pdf']);
        self::assertArrayHasKey('docx->txt', $byPair);
        self::assertNull($byPair['docx->txt']);
        self::assertArrayHasKey('odt->md', $byPair);
        self::assertNull($byPair['odt->md']);

        // CNV-100 — media (audio/video) профили.
        self::assertSame('media.video', $byPair['mp4->mkv'] ?? 'missing');
        // Главный риск карточки: video source, audio-only TARGET — audio, не video.
        self::assertSame('media.audio', $byPair['mp4->mp3'] ?? 'missing');
        self::assertSame('media.audio', $byPair['mp3->wav'] ?? 'missing');
        // TTS/transcription (isAi) остаются без профиля этой карточки.
        self::assertArrayHasKey('md->mp3', $byPair);
        self::assertNull($byPair['md->mp3']);
        self::assertArrayHasKey('flac->txt', $byPair);
        self::assertNull($byPair['flac->txt']);

        // CNV-103 — data (CSV/JSON) профили.
        self::assertSame('data.json', $byPair['csv->json'] ?? 'missing');
        self::assertSame('data.csv', $byPair['json->csv'] ?? 'missing');
        // YAML/TOML/XML как target — вне scope, профиля нет.
        self::assertArrayHasKey('csv->yaml', $byPair);
        self::assertNull($byPair['csv->yaml']);
        self::assertArrayHasKey('json->toml', $byPair);
        self::assertNull($byPair['json->toml']);
        // document AI-экстракция делит `to=json` с data.json, но не category.
        self::assertArrayHasKey('txt->json', $byPair);
        self::assertNull($byPair['txt->json']);

        // CNV-95 — static SVG (bmp/gif/ico/tiff) профили.
        self::assertSame('image.raster', $byPair['svg->gif'] ?? 'missing');
        self::assertSame('image.raster', $byPair['svg->tiff'] ?? 'missing');
        self::assertSame('image.bmp', $byPair['svg->bmp'] ?? 'missing');
        // Главный риск карточки: svg→ico остаётся без профиля — width/height
        // инертны для этой пары by design (CNV-75).
        self::assertArrayHasKey('svg->ico', $byPair);
        self::assertNull($byPair['svg->ico']);
        // Не-svg источники в ico/bmp не затронуты этой карточкой.
        self::assertSame('image.raster', $byPair['jpg->ico'] ?? 'missing');
        self::assertSame('image.raster', $byPair['jpg->bmp'] ?? 'missing');
    }

    /**
     * Персонализация проверяется на синтетическом профиле со СМЕШАННЫМИ
     * `minPlan` — у боевых image-профилей все поля объявлены `guest`
     * (обратная совместимость, см. карточку), поэтому на них разницу между
     * уровнями не увидеть.
     */
    public function testFieldsArePersonalizedByAccessLevel(): void
    {
        $presenter = new ConversionCatalogPresenter(
            $this->newSeedRegistry(),
            new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath()),
        );

        $expected = [
            'guest' => ['scale' => true, 'preset' => true, 'dpi' => false, 'title' => false, 'flatten' => false, 'tint' => false, 'model' => false],
            'free'  => ['scale' => true, 'preset' => true, 'dpi' => true,  'title' => false, 'flatten' => false, 'tint' => false, 'model' => false],
            'basic' => ['scale' => true, 'preset' => true, 'dpi' => true,  'title' => true,  'flatten' => true,  'tint' => false, 'model' => false],
            'pro'   => ['scale' => true, 'preset' => true, 'dpi' => true,  'title' => true,  'flatten' => true,  'tint' => true,  'model' => false],
        ];

        foreach ($expected as $levelName => $editableByKey) {
            $level   = SettingsAccessLevel::from($levelName);
            $payload = $presenter->present($level);
            $fields  = $payload['settings']['profiles']['test.grammar']['fields'];

            $actual = [];
            foreach ($fields as $field) {
                $actual[$field['key']] = $field['editable'];
            }

            self::assertSame($editableByKey, $actual, "Неверная видимость полей для уровня {$levelName}");
        }
    }

    public function testGuestSeesDefaultsOfFieldsItCannotEdit(): void
    {
        $presenter = new ConversionCatalogPresenter(
            $this->newSeedRegistry(),
            new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath()),
        );

        $fields = $presenter->present(SettingsAccessLevel::Guest)['settings']['profiles']['test.grammar']['fields'];
        $byKey  = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        self::assertSame(20, $byKey['scale']['default']);
        self::assertFalse($byKey['tint']['editable']);
        self::assertArrayHasKey('default', $byKey['tint'], 'Недоступное поле всё равно показывает своё значение по умолчанию');
    }

    /**
     * CNV-100 — первое ЖИВОЕ (не синтетическое) per-value plan-гейтнутое поле:
     * `resolution` на `mp4→mkv`. Поле целиком закрыто для guest (минимум free —
     * видео стоит CPU даже на 480p), 1080p закрыт для free (нужен paid=basic).
     */
    public function testMediaVideoResolutionIsPersonalizedByRealPlan(): void
    {
        $presenter = $this->productionPresenter();

        // Guest: поле целиком не редактируемо (field-level minPlan=free).
        $guestField = $this->resolutionField($presenter, SettingsAccessLevel::Guest);
        self::assertFalse($guestField['editable']);

        // Free: поле редактируемо, 480p/720p доступны, 1080p — нет.
        $freeField = $this->resolutionField($presenter, SettingsAccessLevel::Free);
        self::assertTrue($freeField['editable']);
        $freeByValue = [];
        foreach ($freeField['options'] as $option) {
            $freeByValue[$option['value']] = $option['editable'];
        }
        self::assertSame(['480p' => true, '720p' => true, '1080p' => false], $freeByValue);

        // Basic (paid): 1080p становится доступен.
        $basicField   = $this->resolutionField($presenter, SettingsAccessLevel::Basic);
        $basicByValue = [];
        foreach ($basicField['options'] as $option) {
            $basicByValue[$option['value']] = $option['editable'];
        }
        self::assertSame(['480p' => true, '720p' => true, '1080p' => true], $basicByValue);
    }

    /** @return array<string, mixed> */
    private function resolutionField(ConversionCatalogPresenter $presenter, SettingsAccessLevel $level): array
    {
        $payload = $presenter->present($level);
        foreach ($payload['settings']['profiles']['media.video']['fields'] as $field) {
            if ($field['key'] === 'resolution') {
                return $field;
            }
        }

        self::fail('media.video profile is missing the resolution field');
    }

    /**
     * CNV-106 "not published" pin: `present()` always resolves profiles with
     * `animated=false` (it never reads any such request field — see
     * {@see ConversionCatalogPresenter::present()}), so svg→gif keeps
     * advertising the ORDINARY `image.raster` profile and the animated
     * profile never appears in `settings.profiles` at all, for ANY access
     * level. Can-fail proof (c) for the card.
     */
    public function testAnimatedSvgProfileIsNeverAdvertisedByFormats(): void
    {
        foreach (SettingsAccessLevel::cases() as $level) {
            $payload = $this->productionPresenter()->present($level);

            $byPair = [];
            foreach ($payload['formats'] as $pair) {
                $byPair["{$pair['from']}->{$pair['to']}"] = $pair['settingsProfile'];
            }

            self::assertSame('image.raster', $byPair['svg->gif'] ?? 'missing', "level={$level->value}");
            self::assertArrayNotHasKey(
                'image.svg.animated',
                $payload['settings']['profiles'],
                "level={$level->value}: animated profile must not leak into /formats today",
            );
        }
    }

    public function testSelectOptionsCarryTheirOwnPlanGate(): void
    {
        $presenter = new ConversionCatalogPresenter(
            $this->newSeedRegistry(),
            new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath()),
        );

        foreach (['guest' => false, 'free' => false, 'basic' => false, 'pro' => true] as $levelName => $ultraEditable) {
            $fields = $presenter->present(SettingsAccessLevel::from($levelName))['settings']['profiles']['test.grammar']['fields'];

            $preset = null;
            foreach ($fields as $field) {
                if ($field['key'] === 'preset') {
                    $preset = $field;
                }
            }
            self::assertNotNull($preset);

            $ultra = null;
            foreach ($preset['options'] as $option) {
                if ($option['value'] === 'ultra') {
                    $ultra = $option;
                }
            }
            self::assertNotNull($ultra);
            self::assertSame($ultraEditable, $ultra['editable'], "Вариант ultra на уровне {$levelName}");
        }
    }
}
