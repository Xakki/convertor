<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion\Settings;

use App\Exception\InvalidConversionOptionException;
use App\Service\Conversion\Settings\ConversionOptionsValidator;
use App\Service\Conversion\Settings\ConversionSettingsCatalog;
use App\Service\Conversion\Settings\SettingsAccessLevel;
use App\Tests\Support\SeedsConversionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CNV-85 — server-side валидация и нормализация опций.
 *
 * Синтетический профиль `test.grammar` (фикстура
 * `tests/Fixtures/settings_catalog_grammar.json`, назначен реальной паре
 * csv→json) покрывает все шесть типов грамматики и все четыре уровня доступа —
 * так грамматика проверяется независимо от того, какие поля сегодня объявлены
 * у image-профилей. Боевые image-опции проверяются отдельно, в конце файла.
 */
final class ConversionOptionsValidatorTest extends TestCase
{
    use SeedsConversionRegistry;

    private function grammarValidator(): ConversionOptionsValidator
    {
        return new ConversionOptionsValidator(
            new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath()),
            $this->newSeedRegistry(),
        );
    }

    private function productionValidator(): ConversionOptionsValidator
    {
        return new ConversionOptionsValidator(new ConversionSettingsCatalog(), $this->newSeedRegistry());
    }

    // -----------------------------------------------------------------------
    // Грамматика: каждый тип принимает своё значение и нормализует его
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>            $raw
     * @param array<string, bool|int|string>  $expected
     */
    #[DataProvider('acceptedValueProvider')]
    public function testEachFieldTypeNormalizesItsValue(array $raw, array $expected): void
    {
        $normalized = $this->grammarValidator()->validate('csv', 'json', $raw, SettingsAccessLevel::Pro);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $normalized);
            self::assertSame($value, $normalized[$key], "Поле {$key} нормализовано неверно");
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, bool|int|string>}> */
    public static function acceptedValueProvider(): iterable
    {
        // multipart/form-data всегда приносит СТРОКИ — это боевой путь.
        yield 'range from string' => [['scale' => '35'], ['scale' => 35]];
        yield 'range as int' => [['scale' => 40], ['scale' => 40]];
        yield 'number from string' => [['dpi' => '300'], ['dpi' => 300]];
        yield 'select' => [['preset' => 'ultra'], ['preset' => 'ultra']];
        yield 'boolean from "1"' => [['flatten' => '1'], ['flatten' => true]];
        yield 'boolean from "false"' => [['flatten' => 'false'], ['flatten' => false]];
        yield 'boolean as bool' => [['flatten' => true], ['flatten' => true]];
        yield 'text within pattern' => [['title' => 'Report 42'], ['title' => 'Report 42']];
        yield 'color is upper-cased' => [['tint' => '#a1b2c3'], ['tint' => '#A1B2C3']];
    }

    // -----------------------------------------------------------------------
    // Отказы (все перечислены в AC карточки)
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $raw */
    #[DataProvider('rejectedValueProvider')]
    public function testRejections(string $from, string $to, array $raw, SettingsAccessLevel $level, string $expectedCode): void
    {
        try {
            $this->grammarValidator()->validate($from, $to, $raw, $level);
            self::fail("Ожидался отказ с кодом {$expectedCode}");
        } catch (InvalidConversionOptionException $e) {
            self::assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: SettingsAccessLevel, 4: string}> */
    public static function rejectedValueProvider(): iterable
    {
        yield 'unknown key' => ['csv', 'json', ['nope' => '1'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'raw engine argument is just an unknown key' => ['csv', 'json', ['ffmpegArgs' => '-vf scale=2'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'settings on a pair without a profile' => ['docx', 'pdf', ['scale' => '10'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'settings on a pair unknown to the registry' => ['zzz', 'yyy', ['scale' => '10'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'wrong type: float for range' => ['csv', 'json', ['scale' => '10.5'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'wrong type: word for number' => ['csv', 'json', ['dpi' => 'high'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'wrong type: array for text' => ['csv', 'json', ['title' => ['a']], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'wrong type: word for boolean' => ['csv', 'json', ['flatten' => 'maybe'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'out of bounds: above max' => ['csv', 'json', ['dpi' => '9000'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'out of bounds: below min' => ['csv', 'json', ['dpi' => '1'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'off-step range value' => ['csv', 'json', ['scale' => '33'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'text too long' => ['csv', 'json', ['title' => str_repeat('a', 41)], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'disallowed enum value' => ['csv', 'json', ['preset' => 'insane'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'text failing the pattern' => ['csv', 'json', ['title' => 'нельзя;'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'colour that is not #RRGGBB' => ['csv', 'json', ['tint' => 'red'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'plan-locked field' => ['csv', 'json', ['tint' => '#FFFFFF'], SettingsAccessLevel::Free, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'plan-locked enum value' => ['csv', 'json', ['preset' => 'ultra'], SettingsAccessLevel::Free, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'ai field is locked even for pro' => ['csv', 'json', ['model' => 'small'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
    }

    // -----------------------------------------------------------------------
    // Нормализация: default → effective value ДО сериализации задачи
    // -----------------------------------------------------------------------

    public function testDeclaredDefaultIsMaterializedWhenClientSendsNothing(): void
    {
        $normalized = $this->grammarValidator()->validate('csv', 'json', [], SettingsAccessLevel::Guest);

        self::assertSame(['scale' => 20], $normalized, 'В задачу уходит применённое значение, а не «пусто»');
    }

    public function testDeclaredDefaultIsMaterializedEvenForAPlanThatCannotEditTheField(): void
    {
        // Гость не может редактировать `dpi`/`tint`, но применённый default
        // `scale` он получает — «гость видит только значения по умолчанию».
        $normalized = $this->grammarValidator()->validate('csv', 'json', ['scale' => '45'], SettingsAccessLevel::Guest);

        self::assertSame(['scale' => 45], $normalized);
    }

    public function testExplicitValueWinsOverDeclaredDefault(): void
    {
        $normalized = $this->grammarValidator()->validate('csv', 'json', ['scale' => '0'], SettingsAccessLevel::Pro);

        // `0` — легальное значение диапазона [0..100], а не sentinel: оно
        // сохраняется как применённое, а не подменяется дефолтом.
        self::assertSame(['scale' => 0], $normalized);
    }

    public function testPairWithoutProfileNormalizesToEmptyOptions(): void
    {
        self::assertSame([], $this->grammarValidator()->validate('docx', 'pdf', [], SettingsAccessLevel::Pro));
    }

    // -----------------------------------------------------------------------
    // Инвариант каталога: `editable` в /formats == то, что принимает /convert
    // -----------------------------------------------------------------------

    /**
     * Главный инвариант CNV-85, на который опираются пять доменных карточек:
     * поле, отданное клиенту с `editable: true`, ОБЯЗАНО быть принято
     * `POST /convert` на том же уровне, а `editable: false` — отвергнуто с
     * `option_plan_required`. Расхождение «показали, но не приняли»
     * невозможно, потому что предикат один и тот же.
     */
    public function testEditableFlagMatchesWhatValidationAccepts(): void
    {
        $catalog   = new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath());
        $validator = $this->grammarValidator();
        $profile   = $catalog->resolveProfile('csv', 'json', 'data');
        self::assertNotNull($profile);

        $samples = [
            'scale'   => '5',
            'preset'  => 'fast',
            'dpi'     => '150',
            'title'   => 'Ok',
            'flatten' => '1',
            'tint'    => '#010203',
            'model'   => 'small',
        ];

        $checked = 0;
        foreach (SettingsAccessLevel::cases() as $level) {
            foreach ($profile->fields as $field) {
                $sample   = $samples[$field->key];
                $editable = $field->isEditableFor($level);

                // То же самое значение, что уходит клиенту в /formats.
                self::assertSame($editable, $field->toArray($level)['editable']);

                try {
                    $validator->validate('csv', 'json', [$field->key => $sample], $level);
                    self::assertTrue($editable, "{$field->key} принято на уровне {$level->value}, но отдано как editable:false");
                } catch (InvalidConversionOptionException $e) {
                    self::assertFalse($editable, "{$field->key} отвергнуто на уровне {$level->value}, но отдано как editable:true");
                    self::assertSame(InvalidConversionOptionException::CODE_PLAN_REQUIRED, $e->getErrorCode());
                }
                ++$checked;
            }
        }

        self::assertSame(count(SettingsAccessLevel::cases()) * count($profile->fields), $checked);
    }

    // -----------------------------------------------------------------------
    // Боевые image-опции: поведение до CNV-85 сохранено дословно
    // -----------------------------------------------------------------------

    public function testProductionImageOptionsKeepLegacySemantics(): void
    {
        $validator = $this->productionValidator();

        self::assertSame(
            ['width' => 100, 'height' => 200, 'quality' => 75, 'background' => '#FFFFFF'],
            $validator->validate('png', 'jpg', [
                'width'      => '100',
                'height'     => '200',
                'quality'    => '75',
                'background' => '#ffffff',
            ], SettingsAccessLevel::Guest),
        );

        // Ни одно image-поле не объявляет `default`, поэтому пустой запрос даёт
        // ПУСТЫЕ опции — payload задачи для боевых пар не изменился (hard
        // constraint карточки).
        self::assertSame([], $validator->validate('png', 'jpg', [], SettingsAccessLevel::Guest));
        self::assertSame([], $validator->validate('jpg', 'png', [], SettingsAccessLevel::Guest));
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('legacyImageRejectionProvider')]
    public function testProductionImageRejectionsKeepLegacyBoundaries(string $from, string $to, array $raw): void
    {
        $this->expectException(InvalidConversionOptionException::class);

        $this->productionValidator()->validate($from, $to, $raw, SettingsAccessLevel::Guest);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function legacyImageRejectionProvider(): iterable
    {
        yield 'width above 10000' => ['jpg', 'png', ['width' => '10001']];
        yield 'width below 1' => ['jpg', 'png', ['width' => '0']];
        yield 'height above 10000' => ['jpg', 'png', ['height' => '10001']];
        yield 'quality above 100' => ['png', 'jpg', ['quality' => '101']];
        yield 'quality below 1' => ['png', 'jpg', ['quality' => '0']];
        yield 'quality is not offered for png target' => ['jpg', 'png', ['quality' => '80']];
        yield 'background is not offered for webp target' => ['png', 'webp', ['background' => '#FFFFFF']];
        yield 'background must be #RRGGBB' => ['png', 'jpg', ['background' => '#FFF']];
        yield 'leading-zero integer is not an integer' => ['jpg', 'png', ['width' => '0100']];
        yield 'no options on a document pair' => ['docx', 'pdf', ['width' => '100']];
    }

    // -----------------------------------------------------------------------
    // CNV-97 — боевые document-опции (PDF page range/orientation, TXT/Markdown
    // fixed encoding, whitelisted Markdown dialect)
    // -----------------------------------------------------------------------

    public function testProductionDocumentOptionsAreValidatedAndNormalized(): void
    {
        $validator = $this->productionValidator();

        self::assertSame(
            ['pageRange' => '1-3,5', 'orientation' => 'landscape'],
            $validator->validate('txt', 'pdf', ['pageRange' => '1-3,5', 'orientation' => 'landscape'], SettingsAccessLevel::Guest),
        );
        self::assertSame(
            ['pageRange' => '7'],
            $validator->validate('md', 'pdf', ['pageRange' => '7'], SettingsAccessLevel::Guest),
        );
        self::assertSame(
            ['encoding' => 'utf-8'],
            $validator->validate('pdf', 'txt', ['encoding' => 'utf-8'], SettingsAccessLevel::Guest),
        );
        // CNV-98: markdownDialect убран из pdf→md профиля (не имеет эффекта на
        // этой паре — raw pdftotext -layout вывод, без прогона через pandoc).
        self::assertSame(
            ['encoding' => 'utf-8'],
            $validator->validate('pdf', 'md', ['encoding' => 'utf-8'], SettingsAccessLevel::Guest),
        );
        self::assertSame(
            ['encoding' => 'utf-8', 'markdownDialect' => 'commonmark'],
            $validator->validate('txt', 'md', ['encoding' => 'utf-8', 'markdownDialect' => 'commonmark'], SettingsAccessLevel::Guest),
        );

        // Ни одно document-поле CNV-97 не несёт `default` (та же причина, что у
        // image в CNV-85): payload УЖЕ существующих боевых пар pdf/txt/md не
        // меняется у пустого запроса.
        foreach ([['txt', 'pdf'], ['md', 'pdf'], ['pdf', 'txt'], ['md', 'txt'], ['pdf', 'md'], ['txt', 'md']] as [$from, $to]) {
            self::assertSame([], $validator->validate($from, $to, [], SettingsAccessLevel::Guest), "{$from}->{$to} без опций обязан давать пустые options");
        }
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('documentRejectionProvider')]
    public function testProductionDocumentRejectionsFollowClosedGrammar(string $from, string $to, array $raw, string $expectedCode): void
    {
        try {
            $this->productionValidator()->validate($from, $to, $raw, SettingsAccessLevel::Guest);
            self::fail("Ожидался отказ с кодом {$expectedCode}");
        } catch (InvalidConversionOptionException $e) {
            self::assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function documentRejectionProvider(): iterable
    {
        yield 'pageRange rejects disallowed characters' => ['txt', 'pdf', ['pageRange' => '1;3'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'pageRange rejects leading zero' => ['txt', 'pdf', ['pageRange' => '01-03'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'pageRange rejects page zero' => ['md', 'pdf', ['pageRange' => '0-3'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'pageRange too long' => ['txt', 'pdf', ['pageRange' => str_repeat('1,', 40)], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'pageRange wrong type' => ['txt', 'pdf', ['pageRange' => ['1-3']], InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'orientation rejects unknown enum value' => ['md', 'pdf', ['orientation' => 'diagonal'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'encoding rejects anything but utf-8' => ['pdf', 'txt', ['encoding' => 'latin1'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'markdownDialect rejects unknown enum value' => ['txt', 'md', ['markdownDialect' => 'rst'], InvalidConversionOptionException::CODE_INVALID_VALUE];

        // Pair-specific access: поле легально на ОДНОЙ паре триангля, но не на
        // соседней — резолвится другой профиль без этого ключа.
        yield 'markdownDialect is unknown on the pdf profile' => ['txt', 'pdf', ['markdownDialect' => 'gfm'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'pageRange is unknown on the txt profile' => ['pdf', 'txt', ['pageRange' => '1-2'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'orientation is unknown on the markdown profile' => ['pdf', 'md', ['orientation' => 'portrait'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // CNV-98: markdownDialect не имеет эффекта на pdf→md (raw pdftotext
        // -layout, без pandoc) — каталог больше НЕ рекламирует его для этой
        // пары (отдельный профиль document.markdown.verbatim без диалекта).
        yield 'markdownDialect is unknown on the pdf-source markdown profile' => ['pdf', 'md', ['markdownDialect' => 'gfm'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // DOCX/ODT делят category=document с PDF/TXT/MD, но профиля не получают —
        // те же document-ключи отклоняются как настройки без профиля вовсе.
        yield 'docx to pdf rejects settings as a pair without a profile' => ['docx', 'pdf', ['pageRange' => '1-2'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'odt to txt rejects settings as a pair without a profile' => ['odt', 'txt', ['encoding' => 'utf-8'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'docx to md rejects settings as a pair without a profile' => ['docx', 'md', ['markdownDialect' => 'gfm'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
    }

    // -----------------------------------------------------------------------
    // CNV-100 — боевые media (audio/video) опции
    // -----------------------------------------------------------------------

    public function testProductionMediaOptionsAreValidatedAndNormalized(): void
    {
        $validator = $this->productionValidator();

        // Аудио — дёшево по CPU, доступно с guest.
        self::assertSame(
            ['quality' => 'high'],
            $validator->validate('mp3', 'wav', ['quality' => 'high'], SettingsAccessLevel::Guest),
        );
        // Video source, audio-only TARGET (главный риск карточки) — тот же
        // audio-профиль, video-ключи physically не существуют для этой пары.
        self::assertSame(
            ['quality' => 'medium'],
            $validator->validate('mp4', 'mp3', ['quality' => 'medium'], SettingsAccessLevel::Guest),
        );

        // Video: free получает 480p/720p и 24/30 fps.
        self::assertSame(
            ['resolution' => '720p', 'fps' => '30'],
            $validator->validate('mp4', 'mkv', ['resolution' => '720p', 'fps' => '30'], SettingsAccessLevel::Free),
        );
        // 1080p требует paid (basic и выше) — принят и нормализован дословно.
        self::assertSame(
            ['resolution' => '1080p', 'fps' => '24'],
            $validator->validate('mp4', 'mkv', ['resolution' => '1080p', 'fps' => '24'], SettingsAccessLevel::Basic),
        );
        self::assertSame(
            ['resolution' => '1080p', 'fps' => '30'],
            $validator->validate('mp4', 'mkv', ['resolution' => '1080p', 'fps' => '30'], SettingsAccessLevel::Pro),
        );

        // Ни одно media-поле не несёт `default` (та же причина, что у image/
        // document): payload УЖЕ существующих боевых пар не меняется у пустого
        // запроса.
        self::assertSame([], $validator->validate('mp3', 'wav', [], SettingsAccessLevel::Guest));
        self::assertSame([], $validator->validate('mp4', 'mp3', [], SettingsAccessLevel::Guest));
        self::assertSame([], $validator->validate('mp4', 'mkv', [], SettingsAccessLevel::Free));
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('mediaRejectionProvider')]
    public function testProductionMediaRejectionsFollowClosedGrammar(
        string $from,
        string $to,
        array $raw,
        SettingsAccessLevel $level,
        string $expectedCode,
    ): void {
        try {
            $this->productionValidator()->validate($from, $to, $raw, $level);
            self::fail("Ожидался отказ с кодом {$expectedCode}");
        } catch (InvalidConversionOptionException $e) {
            self::assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: SettingsAccessLevel, 4: string}> */
    public static function mediaRejectionProvider(): iterable
    {
        // Raw FFmpeg args / codec — не существуют как ключи вообще.
        yield 'codec key is rejected as unknown' => ['mp4', 'mkv', ['codec' => 'h264'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'raw ffmpeg args key is rejected as unknown' => ['mp4', 'mkv', ['ffmpegArgs' => '-vf scale=1920:1080'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // Unknown preset values.
        yield 'unknown audio quality preset' => ['mp3', 'wav', ['quality' => 'ultra'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'unknown video resolution preset' => ['mp4', 'mkv', ['resolution' => '4k'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'unknown fps preset' => ['mp4', 'mkv', ['fps' => '60'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];

        // Главный риск карточки: video fields недоступны на audio-only target.
        yield 'resolution is unknown on an audio-only target (video source)' => ['mp4', 'mp3', ['resolution' => '720p'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'fps is unknown on an audio-only target (video source)' => ['mp4', 'mp3', ['fps' => '30'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // Симметрично: audio-поле недоступно на video-capable профиле.
        yield 'quality is unknown on the video profile' => ['mp4', 'mkv', ['quality' => 'low'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // Plan gating — free-plan boundary карточки CNV-100.
        yield 'guest cannot touch the resolution field at all' => ['mp4', 'mkv', ['resolution' => '480p'], SettingsAccessLevel::Guest, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'free-plan 1080p is rejected (needs paid/basic)' => ['mp4', 'mkv', ['resolution' => '1080p'], SettingsAccessLevel::Free, InvalidConversionOptionException::CODE_PLAN_REQUIRED];

        // Пары без media-профиля этой карточки (TTS / transcription, isAi).
        yield 'TTS document pair has no configurable settings' => ['md', 'mp3', ['quality' => 'low'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'transcription pair has no configurable settings' => ['flac', 'txt', ['quality' => 'low'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_NOT_SUPPORTED];
    }

    // -----------------------------------------------------------------------
    // CNV-103 — боевые data (CSV/JSON) опции
    // -----------------------------------------------------------------------

    public function testProductionDataOptionsAreValidatedAndNormalized(): void
    {
        $validator = $this->productionValidator();

        // CSV target — любой data-источник конфигурирует ЦЕЛЕВОЙ CSV.
        self::assertSame(
            ['delimiter' => ';', 'quote' => "'", 'encoding' => 'utf-8'],
            $validator->validate('json', 'csv', ['delimiter' => ';', 'quote' => "'", 'encoding' => 'utf-8'], SettingsAccessLevel::Guest),
        );
        self::assertSame(
            ['delimiter' => "\t"],
            $validator->validate('xml', 'csv', ['delimiter' => "\t"], SettingsAccessLevel::Guest),
        );

        // JSON target — pretty-print + indent.
        self::assertSame(
            ['pretty' => true, 'indent' => 4],
            $validator->validate('csv', 'json', ['pretty' => 'true', 'indent' => '4'], SettingsAccessLevel::Guest),
        );
        self::assertSame(
            ['pretty' => false],
            $validator->validate('yaml', 'json', ['pretty' => '0'], SettingsAccessLevel::Guest),
        );

        // Ни одно data-поле не несёт `default` (та же причина, что у image/
        // document/media): payload УЖЕ существующих боевых data-пар не
        // меняется у пустого запроса.
        foreach ([['json', 'csv'], ['toml', 'csv'], ['xml', 'csv'], ['yaml', 'csv'], ['yml', 'csv'], ['csv', 'json'], ['toml', 'json'], ['xml', 'json'], ['yaml', 'json'], ['yml', 'json']] as [$from, $to]) {
            self::assertSame([], $validator->validate($from, $to, [], SettingsAccessLevel::Guest), "{$from}->{$to} без опций обязан давать пустые options");
        }
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('dataRejectionProvider')]
    public function testProductionDataRejectionsFollowClosedGrammar(string $from, string $to, array $raw, string $expectedCode): void
    {
        try {
            $this->productionValidator()->validate($from, $to, $raw, SettingsAccessLevel::Guest);
            self::fail("Ожидался отказ с кодом {$expectedCode}");
        } catch (InvalidConversionOptionException $e) {
            self::assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function dataRejectionProvider(): iterable
    {
        // Whitelist delimiter/quote — не любой символ, а перечисленный enum.
        yield 'delimiter rejects a non-whitelisted character' => ['json', 'csv', ['delimiter' => ':'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'quote rejects a non-whitelisted character' => ['json', 'csv', ['quote' => '`'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        // Невалидный UTF-8 (карточка AC): любое значение encoding кроме
        // единственного легального `utf-8` отклоняется предсказуемо.
        yield 'encoding rejects anything but utf-8' => ['json', 'csv', ['encoding' => 'latin1'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'encoding rejects an empty string' => ['xml', 'csv', ['encoding' => ''], InvalidConversionOptionException::CODE_INVALID_VALUE];

        // indent — bounded number.
        yield 'indent above the boundary' => ['csv', 'json', ['indent' => '9'], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'indent below the boundary' => ['csv', 'json', ['indent' => '0'], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'indent wrong type' => ['csv', 'json', ['indent' => 'wide'], InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'pretty wrong type' => ['csv', 'json', ['pretty' => 'maybe'], InvalidConversionOptionException::CODE_INVALID_TYPE];

        // Cross-target key: поле легально на ОДНОМ target-профиле, но не на другом.
        yield 'indent is unknown on the csv profile' => ['json', 'csv', ['indent' => '2'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'pretty is unknown on the csv profile' => ['json', 'csv', ['pretty' => '1'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'delimiter is unknown on the json profile' => ['csv', 'json', ['delimiter' => ','], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'quote is unknown on the json profile' => ['csv', 'json', ['quote' => '"'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // Arbitrary serializer option — просто неизвестный ключ, тем же путём.
        yield 'raw serializer flag is rejected as unknown' => ['csv', 'json', ['phpArraySerializerFlags' => 'JSON_UNESCAPED_UNICODE'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // YAML/TOML/XML как target — карточка требует НЕ давать им профиль и
        // отклонять settings как «у пары нет профиля».
        yield 'yaml target rejects settings as a pair without a profile' => ['csv', 'yaml', ['pretty' => '1'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'toml target rejects settings as a pair without a profile' => ['json', 'toml', ['delimiter' => ','], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'xml target rejects settings as a pair without a profile' => ['csv', 'xml', ['encoding' => 'utf-8'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'yml target rejects settings as a pair without a profile' => ['json', 'yml', ['pretty' => '1'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];

        // document AI-экстракция (txt→json) делит `to=json` с data.json, но не
        // category — остаётся без профиля этой карточки.
        yield 'document AI extraction pair has no configurable data settings' => ['txt', 'json', ['pretty' => '1'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
    }

    // -----------------------------------------------------------------------
    // CNV-95 — боевые static SVG (bmp/gif/ico/tiff) опции
    // -----------------------------------------------------------------------

    public function testProductionSvgOptionsAreValidatedAndNormalized(): void
    {
        $validator = $this->productionValidator();

        // svg→gif/tiff/png — обычный image.raster, как и у остальных
        // не-svg источников (worker честно применяет width/height).
        self::assertSame(
            ['width' => 320, 'height' => 240],
            $validator->validate('svg', 'gif', ['width' => '320', 'height' => '240'], SettingsAccessLevel::Guest),
        );
        self::assertSame(
            ['width' => 100],
            $validator->validate('svg', 'tiff', ['width' => '100'], SettingsAccessLevel::Guest),
        );

        // svg→bmp — свой профиль: width/height + background (worker
        // композитит прозрачность на фон только для этой пары).
        self::assertSame(
            ['width' => 64, 'height' => 64, 'background' => '#00FF00'],
            $validator->validate('svg', 'bmp', ['width' => '64', 'height' => '64', 'background' => '#00ff00'], SettingsAccessLevel::Guest),
        );

        // Ни одно новое поле не несёт default — payload уже существующих
        // боевых svg-пар не меняется у пустого запроса.
        foreach ([['svg', 'gif'], ['svg', 'png'], ['svg', 'tiff'], ['svg', 'bmp']] as [$from, $to]) {
            self::assertSame([], $validator->validate($from, $to, [], SettingsAccessLevel::Guest), "{$from}->{$to} без опций обязан давать пустые options");
        }

        // svg→ico — БЕЗ профиля вовсе (главный риск карточки): пустой запрос
        // тоже даёт пустые options, как у любой пары без профиля.
        self::assertSame([], $validator->validate('svg', 'ico', [], SettingsAccessLevel::Guest));
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('svgRejectionProvider')]
    public function testProductionSvgRejectionsFollowClosedGrammar(string $from, string $to, array $raw, string $expectedCode): void
    {
        try {
            $this->productionValidator()->validate($from, $to, $raw, SettingsAccessLevel::Guest);
            self::fail("Ожидался отказ с кодом {$expectedCode}");
        } catch (InvalidConversionOptionException $e) {
            self::assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function svgRejectionProvider(): iterable
    {
        // Главный риск карточки: svg→ico не имеет НИКАКОГО профиля — width и
        // height (единственные image-ключи) отклоняются как «у пары нет
        // профиля вовсе», а не как «поле неизвестно этому профилю».
        yield 'svg to ico rejects width — no profile at all' => ['svg', 'ico', ['width' => '32'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'svg to ico rejects height — no profile at all' => ['svg', 'ico', ['height' => '32'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'svg to ico rejects background too — no profile at all' => ['svg', 'ico', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];

        // background не предложен на svg→gif/tiff/png — не композитится
        // worker'ом для этих target'ов (не applicable).
        yield 'background is unknown on svg to gif' => ['svg', 'gif', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'background is unknown on svg to tiff' => ['svg', 'tiff', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'background is unknown on svg to png' => ['svg', 'png', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // background НЕ предложен не-svg источникам в bmp — generic-путь
        // воркера не композитит прозрачность для этих пар (не catch-all).
        yield 'background is unknown on jpg to bmp (non-svg source)' => ['jpg', 'bmp', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'background is unknown on png to bmp (non-svg source)' => ['png', 'bmp', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // Обычные границы image.raster/image.bmp — те же, что и у боевых
        // image-полей до этой карточки.
        yield 'svg to bmp width above the boundary' => ['svg', 'bmp', ['width' => '10001'], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'svg to bmp background must be #RRGGBB' => ['svg', 'bmp', ['background' => 'green'], InvalidConversionOptionException::CODE_INVALID_VALUE];
    }

    // -----------------------------------------------------------------------
    // CNV-106 — animated SVG → GIF (browser execution kind, NOT published today)
    // -----------------------------------------------------------------------

    /**
     * `$animated=true` is the ONLY way to reach this profile — no live caller
     * passes it (see class docblocks of {@see ConversionRegistry}/
     * {@see ConversionSettingsCatalog}), so every assertion here goes through
     * the flag explicitly, exactly like the OCR-route tests above do for `ocr`.
     */
    public function testAnimatedSvgOptionsAreValidatedAndNormalized(): void
    {
        $validator = $this->productionValidator();

        self::assertSame(
            ['width' => 320, 'height' => 240, 'fps' => '15', 'loop' => 'infinite', 'background' => 'transparent'],
            $validator->validate(
                'svg',
                'gif',
                ['width' => '320', 'height' => '240', 'fps' => '15', 'loop' => 'infinite', 'background' => 'transparent'],
                // width/height need basic (see card AC: 640px is the guest/free
                // ceiling), fps=15 needs free — basic satisfies both.
                SettingsAccessLevel::Basic,
                ocr: false,
                animated: true,
            ),
        );

        // Cost-relevant caps (width/height/fps) materialize a default even
        // when the client sends nothing — the guest cap is enforced whether
        // or not the field is touched (see card AC: "guest fixed 640px/12fps").
        self::assertSame(
            ['width' => 640, 'height' => 640, 'fps' => '12'],
            $validator->validate('svg', 'gif', [], SettingsAccessLevel::Guest, ocr: false, animated: true),
        );
    }

    /**
     * `$animated` omitted (defaults to false, exactly what every live caller
     * does today) — svg→gif stays on the ORDINARY `image.raster` profile,
     * width/height only, no fps/loop/background. This is the "not published"
     * pin at the validator level: the animated grammar simply does not exist
     * for a default call.
     */
    public function testAnimatedSvgProfileIsUnreachableWithoutTheFlag(): void
    {
        $validator = $this->productionValidator();

        self::assertSame(
            ['width' => 320, 'height' => 240],
            $validator->validate('svg', 'gif', ['width' => '320', 'height' => '240'], SettingsAccessLevel::Pro),
        );

        try {
            $validator->validate('svg', 'gif', ['fps' => '12'], SettingsAccessLevel::Pro);
            self::fail('fps must be unknown on the default (non-animated) svg→gif profile');
        } catch (InvalidConversionOptionException $e) {
            self::assertSame(InvalidConversionOptionException::CODE_UNKNOWN_OPTION, $e->getErrorCode());
        }
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('animatedSvgRejectionProvider')]
    public function testAnimatedSvgRejectionsFollowClosedGrammar(array $raw, SettingsAccessLevel $level, string $expectedCode): void
    {
        try {
            $this->productionValidator()->validate('svg', 'gif', $raw, $level, ocr: false, animated: true);
            self::fail("Ожидался отказ с кодом {$expectedCode}");
        } catch (InvalidConversionOptionException $e) {
            self::assertSame($expectedCode, $e->getErrorCode());
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: SettingsAccessLevel, 2: string}> */
    public static function animatedSvgRejectionProvider(): iterable
    {
        // Карточка требует явно НЕ принимать эти ключи — их физически нет в
        // профиле, отклоняются как обычный unknown key.
        yield 'duration is not a field' => [['duration' => '5'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'palette is not a field' => [['palette' => 'web216'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'dither is not a field' => [['dither' => 'floyd-steinberg'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'raw renderer flag is just an unknown key' => [['chromiumFlags' => '--disable-gpu'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_UNKNOWN_OPTION];

        // fps: bounded three-tier select — invalid value entirely.
        yield 'fps rejects an unlisted value' => [['fps' => '30'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        // loop: closed select.
        yield 'loop rejects an unknown value' => [['loop' => 'twice'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        // background: text+pattern grammar — anything outside transparent/white/#RRGGBB.
        // "red"/"#FFF" are both SHORTER than any legal value here, so a naive
        // pattern-only test would hide behind the length pre-check — pick
        // values within [0, maxLength] that still fail the pattern itself.
        yield 'background rejects a bare colour name' => [['background' => 'yellow'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'background rejects a short hex' => [['background' => '#FFF00'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_INVALID_VALUE];

        // Can-fail proof (b): plan caps.
        yield 'guest cannot touch width at all' => [['width' => '640'], SettingsAccessLevel::Guest, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'guest cannot touch height at all' => [['height' => '640'], SettingsAccessLevel::Guest, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'free cannot exceed 640px width either (needs basic)' => [['width' => '1280'], SettingsAccessLevel::Free, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'guest fps is locked to the 12 option, 15 needs free' => [['fps' => '15'], SettingsAccessLevel::Guest, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'free fps 24 needs basic' => [['fps' => '24'], SettingsAccessLevel::Free, InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        yield 'width above the 1280 ceiling even for pro' => [['width' => '1281'], SettingsAccessLevel::Pro, InvalidConversionOptionException::CODE_OUT_OF_RANGE];
    }

    /**
     * Basic/pro plan actually gets 1280px/24fps once it explicitly asks —
     * the ceiling from the card AC, exercised positively (not just rejected
     * above the ceiling).
     */
    public function testAnimatedSvgBasicPlanReachesTheUpperCaps(): void
    {
        $normalized = $this->productionValidator()->validate(
            'svg',
            'gif',
            ['width' => '1280', 'height' => '1280', 'fps' => '24'],
            SettingsAccessLevel::Basic,
            ocr: false,
            animated: true,
        );

        self::assertSame(['width' => 1280, 'height' => 1280, 'fps' => '24'], $normalized);
    }
}
