<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conversion\Settings;

use App\Service\Conversion\Settings\ConversionSettingsCatalog;
use App\Service\Conversion\Settings\SettingsAccessLevel;
use App\Service\Conversion\Settings\SettingsFieldType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CNV-85 — загрузка каталога профилей настроек.
 *
 * Две вещи проверяются здесь и больше нигде:
 *  1) БОЕВОЙ `config/catalog/conversion_settings.json` назначает image-парам
 *     ровно тот набор полей, который принимал старый inline-код
 *     `ConversionController::validateImageOptions()` (hard constraint карточки:
 *     семантика image-конвертаций не меняется);
 *  2) битый каталог падает ГРОМКО на загрузке. Это точка расширения для
 *     CNV-95/97/100/103/106 — она обязана отвергать некорректный профиль
 *     сразу, а не отдавать его клиенту.
 */
final class ConversionSettingsCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testProductionCatalogLoadsAndIsVersioned(): void
    {
        $catalog = new ConversionSettingsCatalog();

        self::assertNotSame('', $catalog->getVersion());
        self::assertArrayHasKey('image.raster', $catalog->getProfiles());
        self::assertArrayHasKey('image.lossy', $catalog->getProfiles());
        self::assertArrayHasKey('image.jpeg', $catalog->getProfiles());
        self::assertArrayHasKey('image.bmp', $catalog->getProfiles());
        self::assertArrayHasKey('image.svg.animated', $catalog->getProfiles());
    }

    /**
     * @param list<string> $expectedFields
     */
    #[DataProvider('imagePairProvider')]
    public function testProductionCatalogReproducesLegacyImageAllowlist(
        string $from,
        string $to,
        string $category,
        bool $ocr,
        ?string $expectedProfile,
        array $expectedFields,
    ): void {
        $catalog = new ConversionSettingsCatalog();

        self::assertSame($expectedProfile, $catalog->resolveProfileId($from, $to, $category, $ocr));

        $profile = $catalog->resolveProfile($from, $to, $category, $ocr);
        if ($expectedProfile === null) {
            self::assertNull($profile);

            return;
        }

        self::assertNotNull($profile);
        self::assertSame(
            $expectedFields,
            array_map(static fn ($f): string => $f->key, $profile->fields),
            'Набор полей обязан совпадать со старым $allowed из validateImageOptions()',
        );
    }

    /**
     * Старый inline-allowlist: width/height всегда, quality для jpg/jpeg/webp,
     * background только для jpg/jpeg. OCR-маршрут опций не принимал вовсе.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: bool, 4: ?string, 5: list<string>}>
     */
    public static function imagePairProvider(): iterable
    {
        yield 'jpeg target gets background too' => ['png', 'jpg', 'image', false, 'image.jpeg', ['width', 'height', 'quality', 'background']];
        yield 'jpeg alias' => ['png', 'jpeg', 'image', false, 'image.jpeg', ['width', 'height', 'quality', 'background']];
        yield 'webp target gets quality but no background' => ['png', 'webp', 'image', false, 'image.lossy', ['width', 'height', 'quality']];
        yield 'png target gets size only' => ['jpg', 'png', 'image', false, 'image.raster', ['width', 'height']];
        yield 'svg source is a normal image pair' => ['svg', 'png', 'image', false, 'image.raster', ['width', 'height']];
        yield 'image-category text target still size-only' => ['jpg', 'txt', 'image', false, 'image.raster', ['width', 'height']];
        yield 'ocr route has no profile' => ['jpg', 'txt', 'image', true, null, []];
        yield 'document pair has no profile' => ['docx', 'pdf', 'document', false, null, []];
        // CNV-103: csv→json now carries the data.json profile (see
        // testProductionCatalogAssignsDataProfiles below for the full sweep).
        yield 'data pair (csv to json) gets the JSON profile' => ['csv', 'json', 'data', false, 'data.json', ['pretty', 'indent']];
    }

    /**
     * CNV-97 — document profiles для PDF/TXT/Markdown. Правило матчит `from`/
     * `to` явными списками (а не одним `category`), поэтому DOCX/ODT как
     * контрагент НЕ получают профиль, хотя и разделяют `category: document`
     * с PDF/TXT/MD.
     *
     * @param list<string> $expectedFields
     */
    #[DataProvider('documentPairProvider')]
    public function testProductionCatalogAssignsDocumentProfiles(
        string $from,
        string $to,
        bool $ocr,
        ?string $expectedProfile,
        array $expectedFields,
    ): void {
        $catalog = new ConversionSettingsCatalog();

        self::assertSame($expectedProfile, $catalog->resolveProfileId($from, $to, 'document', $ocr));

        $profile = $catalog->resolveProfile($from, $to, 'document', $ocr);
        if ($expectedProfile === null) {
            self::assertNull($profile);

            return;
        }

        self::assertNotNull($profile);
        self::assertSame(
            $expectedFields,
            array_map(static fn ($f): string => $f->key, $profile->fields),
        );
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3: ?string, 4: list<string>}> */
    public static function documentPairProvider(): iterable
    {
        // Триангль PDF/TXT/MD — единственные пары этой карточки, получающие профиль.
        yield 'txt to pdf gets page range + orientation' => ['txt', 'pdf', false, 'document.pdf', ['pageRange', 'orientation']];
        yield 'md to pdf gets page range + orientation' => ['md', 'pdf', false, 'document.pdf', ['pageRange', 'orientation']];
        yield 'pdf to txt gets fixed encoding only' => ['pdf', 'txt', false, 'document.txt', ['encoding']];
        yield 'md to txt gets fixed encoding only' => ['md', 'txt', false, 'document.txt', ['encoding']];
        // CNV-98: markdownDialect не имеет эффекта на pdf→md (сырой pdftotext
        // -layout вывод, без прогона через pandoc) — каталог больше НЕ
        // рекламирует его для этой пары: отдельный профиль без диалекта.
        yield 'pdf to md gets encoding only, no dialect' => ['pdf', 'md', false, 'document.markdown.verbatim', ['encoding']];
        yield 'txt to md gets encoding + dialect' => ['txt', 'md', false, 'document.markdown', ['encoding', 'markdownDialect']];

        // DOCX/ODT — не получают профиль ни как источник, ни как цель этой карточки,
        // хотя и разделяют category=document с PDF/TXT/MD.
        yield 'docx to pdf stays without a profile' => ['docx', 'pdf', false, null, []];
        yield 'odt to pdf stays without a profile' => ['odt', 'pdf', false, null, []];
        yield 'docx to txt stays without a profile' => ['docx', 'txt', false, null, []];
        yield 'odt to txt stays without a profile' => ['odt', 'txt', false, null, []];
        yield 'docx to md stays without a profile' => ['docx', 'md', false, null, []];
        yield 'odt to md stays without a profile' => ['odt', 'md', false, null, []];

        // pdf-источник ocrCapable: правила требуют `ocr: false` явно, поэтому
        // ocr=true не резолвит никакого профиля — как и у OCR-маршрута image.
        yield 'ocr route from pdf to txt has no profile' => ['pdf', 'txt', true, null, []];
        yield 'ocr route from pdf to md has no profile' => ['pdf', 'md', true, null, []];
    }

    // -----------------------------------------------------------------------
    // CNV-100 — media (audio/video) profiles, full production-pair sweep
    // -----------------------------------------------------------------------

    /**
     * Проверяет ВСЕ 134 боевые audio/video пары `conversion_pairs.json` (а не
     * выборку) — это ровно тот инвариант, который карточка запрещает ослаблять
     * до "несколько примеров": video-поля НИКОГДА не попадают на audio-only
     * target (напр. `mp4→mp3`), а транскрипция/TTS-пары (isAi) остаются БЕЗ
     * профиля этой карточки.
     */
    public function testProductionCatalogAssignsMediaProfiles(): void
    {
        $catalog = new ConversionSettingsCatalog();

        $pairsRaw = file_get_contents(self::mediaPairsFixturePath());
        self::assertNotFalse($pairsRaw);
        /** @var list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $pairs */
        $pairs = json_decode($pairsRaw, true, 512, JSON_THROW_ON_ERROR);

        $videoContainers = ['avi', 'mkv', 'mov', 'mp4', 'webm'];
        $audioFormats    = ['aac', 'flac', 'm4a', 'mp3', 'ogg', 'opus', 'wav'];

        $videoCapableCount = 0;
        $audioTargetCount  = 0;
        $untouchedCount    = 0;

        foreach ($pairs as $pair) {
            if (! in_array($pair['category'], ['audio', 'video'], true)) {
                continue;
            }

            $profile = $catalog->resolveProfile($pair['from'], $pair['to'], $pair['category'], false);
            $where   = "{$pair['from']}->{$pair['to']} ({$pair['category']})";

            if ($pair['category'] === 'video' && in_array($pair['to'], $videoContainers, true)) {
                self::assertNotNull($profile, "{$where} must resolve to media.video");
                self::assertSame('media.video', $profile->id, $where);
                self::assertSame(
                    ['resolution', 'fps'],
                    array_map(static fn ($f): string => $f->key, $profile->fields),
                    "{$where} must carry ONLY resolution+fps",
                );
                ++$videoCapableCount;

                continue;
            }

            if (in_array($pair['to'], $audioFormats, true)) {
                self::assertNotNull($profile, "{$where} must resolve to media.audio");
                self::assertSame('media.audio', $profile->id, $where);
                self::assertSame(
                    ['quality'],
                    array_map(static fn ($f): string => $f->key, $profile->fields),
                    "{$where} must carry ONLY quality — NEVER a video field on an audio-only target",
                );
                ++$audioTargetCount;

                continue;
            }

            // AI transcription targets (srt/txt/vtt) — вне scope этой карточки.
            self::assertNull($profile, "{$where} unexpectedly carries a media profile");
            ++$untouchedCount;
        }

        // Числа зафиксированы python-подсчётом по боевому conversion_pairs.json
        // (см. Execution Log карточки CNV-100) — сумма итогов, а не отдельные
        // примеры, чтобы будущий сдвиг каталога пар не прошёл незамеченным.
        self::assertSame(35, $videoCapableCount, 'video-capable pairs (category=video, to = container format)');
        self::assertSame(81, $audioTargetCount, 'audio-target pairs (32 video-source extraction + 49 audio-source)');
        self::assertSame(18, $untouchedCount, 'AI transcription pairs (category=audio, to = srt/txt/vtt)');
    }

    /**
     * Явные читаемые примеры, включая ГЛАВНЫЙ риск карточки: `mp4→mp3`
     * (audio-only TARGET из video SOURCE) обязан получить audio, а не video
     * профиль.
     *
     * @param list<string> $expectedFields
     */
    #[DataProvider('mediaPairProvider')]
    public function testProductionCatalogAssignsMediaProfilesExamples(
        string $from,
        string $to,
        string $category,
        ?string $expectedProfile,
        array $expectedFields,
    ): void {
        $catalog = new ConversionSettingsCatalog();

        self::assertSame($expectedProfile, $catalog->resolveProfileId($from, $to, $category, false));

        $profile = $catalog->resolveProfile($from, $to, $category, false);
        if ($expectedProfile === null) {
            self::assertNull($profile);

            return;
        }

        self::assertNotNull($profile);
        self::assertSame($expectedFields, array_map(static fn ($f): string => $f->key, $profile->fields));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string, 4: list<string>}> */
    public static function mediaPairProvider(): iterable
    {
        yield 'video to video gets resolution+fps' => ['mp4', 'mkv', 'video', 'media.video', ['resolution', 'fps']];
        // Главный риск CNV-100: video source, audio-only TARGET — обязан
        // получить AUDIO профиль, а не video.
        yield 'video source, audio-only target gets quality only' => ['mp4', 'mp3', 'video', 'media.audio', ['quality']];
        yield 'audio to audio gets quality' => ['mp3', 'wav', 'audio', 'media.audio', ['quality']];
        // TTS (document→audio, isAi) делит `to` со media.audio, но НЕ делит
        // category — остаётся без профиля этой карточки.
        yield 'document TTS pair (md to mp3) stays without a media profile' => ['md', 'mp3', 'document', null, []];
        // Транскрипция (audio→text, isAi) остаётся без профиля этой карточки.
        yield 'audio transcription pair stays without a media profile' => ['flac', 'txt', 'audio', null, []];
    }

    private static function mediaPairsFixturePath(): string
    {
        return dirname(__DIR__, 5) . '/config/catalog/conversion_pairs.json';
    }

    // -----------------------------------------------------------------------
    // CNV-103 — data (CSV/JSON) profiles, full production-pair sweep
    // -----------------------------------------------------------------------

    /**
     * Проверяет ВСЕ 28 боевые `category=data` пары `conversion_pairs.json` (а
     * не выборку): CSV-target пары получают `data.csv` (delimiter/quote/
     * encoding), JSON-target — `data.json` (pretty/indent), а YAML/TOML/XML
     * как target ОСТАЮТСЯ без профиля этой карточки (карточка запрещает им
     * профиль и приём settings). Побочно проверяет отсутствие cross-category
     * утечки: `txt→json` (category=document, isAi=true, AI-экстракция
     * структурированных данных) делит `to=json` с `data.json`, но НЕ
     * `category` — правило `data.json` ЯВНО скоуплено `"category": "data"`,
     * поэтому document-пара сюда не попадает (см.
     * {@see testProductionCatalogAssignsDataProfilesExamples}, последний кейс
     * {@see dataPairProvider()}).
     */
    public function testProductionCatalogAssignsDataProfiles(): void
    {
        $catalog = new ConversionSettingsCatalog();

        $pairsRaw = file_get_contents(self::mediaPairsFixturePath());
        self::assertNotFalse($pairsRaw);
        /** @var list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $pairs */
        $pairs = json_decode($pairsRaw, true, 512, JSON_THROW_ON_ERROR);

        $csvTargetCount  = 0;
        $jsonTargetCount = 0;
        $untouchedCount  = 0;

        foreach ($pairs as $pair) {
            if ($pair['category'] !== 'data') {
                continue;
            }

            $profile = $catalog->resolveProfile($pair['from'], $pair['to'], $pair['category'], false);
            $where   = "{$pair['from']}->{$pair['to']} ({$pair['category']})";

            if ($pair['to'] === 'csv') {
                self::assertNotNull($profile, "{$where} must resolve to data.csv");
                self::assertSame('data.csv', $profile->id, $where);
                self::assertSame(
                    ['delimiter', 'quote', 'encoding'],
                    array_map(static fn ($f): string => $f->key, $profile->fields),
                    "{$where} must carry ONLY delimiter+quote+encoding",
                );
                ++$csvTargetCount;

                continue;
            }

            if ($pair['to'] === 'json') {
                self::assertNotNull($profile, "{$where} must resolve to data.json");
                self::assertSame('data.json', $profile->id, $where);
                self::assertSame(
                    ['pretty', 'indent'],
                    array_map(static fn ($f): string => $f->key, $profile->fields),
                    "{$where} must carry ONLY pretty+indent",
                );
                ++$jsonTargetCount;

                continue;
            }

            // yaml/toml/xml as target — carded out of this ticket's scope.
            self::assertNull($profile, "{$where} unexpectedly carries a data profile");
            ++$untouchedCount;
        }

        // Числа зафиксированы python-подсчётом по боевому conversion_pairs.json
        // (см. Execution Log карточки CNV-103) — сумма итогов, а не отдельные
        // примеры, чтобы будущий сдвиг каталога пар не прошёл незамеченным.
        self::assertSame(5, $csvTargetCount, 'CSV-target data pairs (json/toml/xml/yaml/yml -> csv)');
        self::assertSame(5, $jsonTargetCount, 'JSON-target data pairs (csv/toml/xml/yaml/yml -> json)');
        self::assertSame(18, $untouchedCount, 'yaml/toml/xml-target data pairs (out of CNV-103 scope, no profile)');
    }

    /**
     * Явные читаемые примеры, включая ГЛАВНЫЙ риск карточки: `txt→json`
     * (category=document, isAi=true — AI-извлечение структурированных данных
     * из текста) делит `to=json` с `data.json`, но обязан остаться БЕЗ
     * профиля этой карточки — правило `data.json` скоуплено `category: data`.
     *
     * @param list<string> $expectedFields
     */
    #[DataProvider('dataPairProvider')]
    public function testProductionCatalogAssignsDataProfilesExamples(
        string $from,
        string $to,
        string $category,
        ?string $expectedProfile,
        array $expectedFields,
    ): void {
        $catalog = new ConversionSettingsCatalog();

        self::assertSame($expectedProfile, $catalog->resolveProfileId($from, $to, $category, false));

        $profile = $catalog->resolveProfile($from, $to, $category, false);
        if ($expectedProfile === null) {
            self::assertNull($profile);

            return;
        }

        self::assertNotNull($profile);
        self::assertSame($expectedFields, array_map(static fn ($f): string => $f->key, $profile->fields));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string, 4: list<string>}> */
    public static function dataPairProvider(): iterable
    {
        yield 'json to csv gets delimiter+quote+encoding' => ['json', 'csv', 'data', 'data.csv', ['delimiter', 'quote', 'encoding']];
        yield 'csv to json gets pretty+indent' => ['csv', 'json', 'data', 'data.json', ['pretty', 'indent']];
        // Любой другой data-источник тоже конфигурирует ЦЕЛЕВОЙ формат.
        yield 'xml to csv gets delimiter+quote+encoding' => ['xml', 'csv', 'data', 'data.csv', ['delimiter', 'quote', 'encoding']];
        yield 'yaml to json gets pretty+indent' => ['yaml', 'json', 'data', 'data.json', ['pretty', 'indent']];
        // YAML/TOML/XML как target — вне scope карточки, профиля нет.
        yield 'csv to yaml stays without a profile' => ['csv', 'yaml', 'data', null, []];
        yield 'csv to toml stays without a profile' => ['csv', 'toml', 'data', null, []];
        yield 'json to xml stays without a profile' => ['json', 'xml', 'data', null, []];
        // Главный риск карточки: document AI-экстракция делит `to=json`, но НЕ
        // `category` — остаётся без data-профиля.
        yield 'document AI extraction pair (txt to json) stays without a data profile' => ['txt', 'json', 'document', null, []];
    }

    // -----------------------------------------------------------------------
    // CNV-95 — static SVG (bmp/gif/ico/tiff) profiles, full production-pair sweep
    // -----------------------------------------------------------------------

    /**
     * Проверяет ВСЕ 86 боевые `category=image` пары `conversion_pairs.json` (а
     * не выборку). Главный риск карточки: `image.raster` — общий catch-all для
     * ВСЕЙ категории — по конструкции ловил бы и `svg→ico`, если бы не был
     * ограничен явным `from`-списком НЕ-svg источников (см. `$comment` файла
     * каталога). `svg→ico` обязана остаться БЕЗ профиля (worker игнорирует
     * width/height by design, CNV-75), `svg→bmp` — получить отдельный профиль
     * с `background` (worker композитит прозрачность на фон, чего НЕ делает
     * generic-путь для остальных →bmp источников), `svg→gif`/`svg→png`/
     * `svg→tiff` — продолжить получать обычный `image.raster` как раньше.
     */
    public function testProductionCatalogAssignsImageProfiles(): void
    {
        $catalog = new ConversionSettingsCatalog();

        $pairsRaw = file_get_contents(self::mediaPairsFixturePath());
        self::assertNotFalse($pairsRaw);
        /** @var list<array{from: string, to: string, category: string, isAi: bool, ocrCapable: bool}> $pairs */
        $pairs = json_decode($pairsRaw, true, 512, JSON_THROW_ON_ERROR);

        $jpegTargetCount = 0;
        $webpTargetCount = 0;
        $svgBmpCount     = 0;
        $svgRasterCount  = 0;
        $svgIcoNullCount = 0;
        $catchAllCount   = 0;

        foreach ($pairs as $pair) {
            if ($pair['category'] !== 'image') {
                continue;
            }

            $from    = $pair['from'];
            $to      = $pair['to'];
            $profile = $catalog->resolveProfile($from, $to, 'image', false);
            $where   = "{$from}->{$to} (image)";

            if (in_array($to, ['jpg', 'jpeg'], true)) {
                self::assertNotNull($profile, "{$where} must resolve to image.jpeg");
                self::assertSame('image.jpeg', $profile->id, $where);
                self::assertSame(['width', 'height', 'quality', 'background'], array_map(static fn ($f): string => $f->key, $profile->fields), $where);
                ++$jpegTargetCount;

                continue;
            }

            if ($to === 'webp') {
                self::assertNotNull($profile, "{$where} must resolve to image.lossy");
                self::assertSame('image.lossy', $profile->id, $where);
                self::assertSame(['width', 'height', 'quality'], array_map(static fn ($f): string => $f->key, $profile->fields), $where);
                ++$webpTargetCount;

                continue;
            }

            if ($from === 'svg' && $to === 'bmp') {
                self::assertNotNull($profile, "{$where} must resolve to image.bmp");
                self::assertSame('image.bmp', $profile->id, $where);
                self::assertSame(
                    ['width', 'height', 'background'],
                    array_map(static fn ($f): string => $f->key, $profile->fields),
                    "{$where} must carry width+height+background — the worker composites alpha onto background for this pair only",
                );
                ++$svgBmpCount;

                continue;
            }

            if ($from === 'svg' && in_array($to, ['gif', 'png', 'tiff'], true)) {
                self::assertNotNull($profile, "{$where} must resolve to image.raster");
                self::assertSame('image.raster', $profile->id, $where);
                self::assertSame(['width', 'height'], array_map(static fn ($f): string => $f->key, $profile->fields), $where);
                ++$svgRasterCount;

                continue;
            }

            if ($from === 'svg' && $to === 'ico') {
                // CNV-75: _save_svg_ico() deliberately skips _apply_image_options() —
                // width/height would be silently inert if advertised here.
                self::assertNull($profile, "{$where} must stay WITHOUT a profile — width/height are inert for svg→ico by design");
                ++$svgIcoNullCount;

                continue;
            }

            // Every other (non-svg source) image pair — the catch-all.
            self::assertNotNull($profile, "{$where} must resolve to image.raster (catch-all)");
            self::assertSame('image.raster', $profile->id, $where);
            self::assertSame(['width', 'height'], array_map(static fn ($f): string => $f->key, $profile->fields), $where);
            ++$catchAllCount;
        }

        // Числа зафиксированы python-подсчётом по боевому conversion_pairs.json
        // (см. Execution Log карточки CNV-95) — сумма итогов, а не отдельные
        // примеры, чтобы будущий сдвиг каталога пар не прошёл незамеченным.
        self::assertSame(9, $jpegTargetCount, 'jpg/jpeg-target image pairs');
        self::assertSame(9, $webpTargetCount, 'webp-target image pairs');
        self::assertSame(1, $svgBmpCount, 'svg->bmp (own profile with background)');
        self::assertSame(3, $svgRasterCount, 'svg->{gif,png,tiff} (unchanged image.raster)');
        self::assertSame(1, $svgIcoNullCount, 'svg->ico (no profile — width/height inert by design)');
        self::assertSame(63, $catchAllCount, 'remaining non-svg-source image pairs on the image.raster catch-all');
    }

    /**
     * Явные читаемые примеры, включая ГЛАВНЫЙ риск карточки: `svg→ico`
     * обязан остаться БЕЗ профиля, а не тихо унаследовать `image.raster` от
     * catch-all'а (что и происходило ДО этой карточки).
     *
     * @param list<string> $expectedFields
     */
    #[DataProvider('svgPairProvider')]
    public function testProductionCatalogAssignsSvgProfilesExamples(
        string $from,
        string $to,
        ?string $expectedProfile,
        array $expectedFields,
    ): void {
        $catalog = new ConversionSettingsCatalog();

        self::assertSame($expectedProfile, $catalog->resolveProfileId($from, $to, 'image', false));

        $profile = $catalog->resolveProfile($from, $to, 'image', false);
        if ($expectedProfile === null) {
            self::assertNull($profile);

            return;
        }

        self::assertNotNull($profile);
        self::assertSame($expectedFields, array_map(static fn ($f): string => $f->key, $profile->fields));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: ?string, 3: list<string>}> */
    public static function svgPairProvider(): iterable
    {
        yield 'svg to gif keeps size-only image.raster' => ['svg', 'gif', 'image.raster', ['width', 'height']];
        yield 'svg to tiff keeps size-only image.raster' => ['svg', 'tiff', 'image.raster', ['width', 'height']];
        yield 'svg to png keeps size-only image.raster' => ['svg', 'png', 'image.raster', ['width', 'height']];
        yield 'svg to bmp gets its own profile with background' => ['svg', 'bmp', 'image.bmp', ['width', 'height', 'background']];
        // Главный риск: svg→ico остаётся БЕЗ профиля — width/height инертны
        // для этой пары (CNV-75, _save_svg_ico() их не применяет by design).
        yield 'svg to ico stays without a profile (width/height are inert)' => ['svg', 'ico', null, []];
        // Не-svg источники в ico — НЕ затронуты: worker честно применяет
        // width/height для них (generic _do_convert() → _apply_image_options()).
        yield 'jpg to ico is unaffected, still image.raster' => ['jpg', 'ico', 'image.raster', ['width', 'height']];
        yield 'png to ico is unaffected, still image.raster' => ['png', 'ico', 'image.raster', ['width', 'height']];
        // Не-svg источники в bmp — НЕ затронуты: не получают background
        // (generic _save_image() не композитит прозрачность для bmp).
        yield 'jpg to bmp is unaffected, still image.raster without background' => ['jpg', 'bmp', 'image.raster', ['width', 'height']];
    }

    // -----------------------------------------------------------------------
    // CNV-106 — animated SVG → GIF: `animated` request-scoped discriminator
    // -----------------------------------------------------------------------

    /**
     * Главный риск карточки (advisor review): каждое СУЩЕСТВУЮЩЕЕ правило
     * теперь несёт `animated: false` явно (конвенция файла с CNV-106) —
     * без этого `image.raster`/`image.jpeg`/... молча "впитали" бы
     * animated=true запросы (опущенный ключ = wildcard), т.к. они делят
     * `category`/`to` с animated-профилем ИЛИ идут по catch-all. Этот тест
     * проверяет ОБЕ стороны: animated=true резолвит ТОЛЬКО новый профиль на
     * svg→gif, а любая другая image-пара с animated=true не резолвит НИЧЕГО
     * (ни собственный обычный профиль, ни чужой).
     */
    #[DataProvider('animatedSvgPairProvider')]
    public function testProductionCatalogAssignsAnimatedSvgProfile(
        string $from,
        string $to,
        bool $animated,
        ?string $expectedProfile,
    ): void {
        $catalog = new ConversionSettingsCatalog();

        self::assertSame(
            $expectedProfile,
            $catalog->resolveProfileId($from, $to, 'image', false, $animated),
        );
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3: ?string}> */
    public static function animatedSvgPairProvider(): iterable
    {
        yield 'svg to gif, animated requested, gets its own profile' => ['svg', 'gif', true, 'image.svg.animated'];
        yield 'svg to gif, animated NOT requested, keeps image.raster' => ['svg', 'gif', false, 'image.raster'];
        // Ось не протекает на соседние svg-target'ы — там просто нет
        // animated-правила, и они не попадают под catch-all (animated:false
        // там тоже явный).
        yield 'svg to png with animated requested resolves to nothing' => ['svg', 'png', true, null];
        yield 'svg to tiff with animated requested resolves to nothing' => ['svg', 'tiff', true, null];
        yield 'svg to bmp with animated requested resolves to nothing' => ['svg', 'bmp', true, null];
        yield 'svg to ico with animated requested resolves to nothing' => ['svg', 'ico', true, null];
        // Не-svg источник с animated=true тоже не резолвит catch-all —
        // animated:false на catch-all явно исключает это.
        yield 'jpg to png with animated requested resolves to nothing' => ['jpg', 'png', true, null];
    }

    /**
     * `image.svg.animated` содержит РОВНО те поля, что описаны в карточке —
     * width/height/fps/loop/background, в этом порядке.
     */
    public function testAnimatedSvgProfileHasExpectedFields(): void
    {
        $catalog = new ConversionSettingsCatalog();
        $profile = $catalog->resolveProfile('svg', 'gif', 'image', false, true);

        self::assertNotNull($profile);
        self::assertSame('image.svg.animated', $profile->id);
        self::assertSame(
            ['width', 'height', 'fps', 'loop', 'background'],
            array_map(static fn ($f): string => $f->key, $profile->fields),
        );
    }

    public function testGrammarFixtureExposesEveryFieldTypeAndPlanLevel(): void
    {
        $catalog = new ConversionSettingsCatalog(self::grammarFixturePath());
        $profile = $catalog->resolveProfile('csv', 'json', 'data');

        self::assertNotNull($profile);

        $types = array_map(static fn ($f): SettingsFieldType => $f->type, $profile->fields);
        foreach (SettingsFieldType::cases() as $case) {
            self::assertContains($case, $types, "Фикстура обязана покрывать тип {$case->value}");
        }

        $plans = array_map(static fn ($f): SettingsAccessLevel => $f->minPlan, $profile->fields);
        foreach (SettingsAccessLevel::cases() as $case) {
            self::assertContains($case, $plans, "Фикстура обязана покрывать уровень {$case->value}");
        }
    }

    public function testDefaultIsNormalizedAtLoadTime(): void
    {
        $catalog = new ConversionSettingsCatalog(self::grammarFixturePath());
        $profile = $catalog->resolveProfile('csv', 'json', 'data');

        self::assertNotNull($profile);
        $scale = $profile->field('scale');
        self::assertNotNull($scale);
        self::assertSame(20, $scale->default);

        $dpi = $profile->field('dpi');
        self::assertNotNull($dpi);
        self::assertNull($dpi->default, 'Поле без объявленного default остаётся без него');
    }

    /**
     * @param array<string, mixed> $catalog
     */
    #[DataProvider('malformedCatalogProvider')]
    public function testMalformedCatalogFailsLoudly(array $catalog, string $expectedMessagePart): void
    {
        $service = new ConversionSettingsCatalog($this->writeCatalog($catalog));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessagePart, '/') . '/');

        $service->getProfiles();
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function malformedCatalogProvider(): iterable
    {
        yield 'unknown field type' => [
            self::catalogWithField(['key' => 'x', 'type' => 'slider', 'label' => 'X']),
            'unknown field type',
        ];
        yield 'range without bounds' => [
            self::catalogWithField(['key' => 'x', 'type' => 'range', 'label' => 'X', 'minPlan' => 'guest']),
            'requires integer `min` and `max`',
        ];
        yield 'number without bounds' => [
            self::catalogWithField(['key' => 'x', 'type' => 'number', 'label' => 'X', 'min' => 1, 'minPlan' => 'guest']),
            'requires integer `min` and `max`',
        ];
        yield 'select without options' => [
            self::catalogWithField(['key' => 'x', 'type' => 'select', 'label' => 'X', 'minPlan' => 'guest']),
            'requires a non-empty `options` list',
        ];
        yield 'text without maxLength' => [
            self::catalogWithField(['key' => 'x', 'type' => 'text', 'label' => 'X', 'minPlan' => 'guest']),
            'requires a positive integer `maxLength`',
        ];
        yield 'color default that is not #RRGGBB' => [
            self::catalogWithField(['key' => 'x', 'type' => 'color', 'label' => 'X', 'minPlan' => 'guest', 'default' => 'white']),
            'invalid `default`',
        ];
        yield 'range default out of bounds' => [
            self::catalogWithField(['key' => 'x', 'type' => 'range', 'label' => 'X', 'minPlan' => 'guest', 'min' => 1, 'max' => 10, 'default' => 99]),
            'invalid `default`',
        ];
        yield 'unknown minPlan' => [
            self::catalogWithField(['key' => 'x', 'type' => 'boolean', 'label' => 'X', 'minPlan' => 'enterprise']),
            'unknown `minPlan`',
        ];
        yield 'missing minPlan (guest-политика: обязателен, выбирается по стоимости)' => [
            self::catalogWithField(['key' => 'x', 'type' => 'boolean', 'label' => 'X']),
            '`minPlan` is required',
        ];
        yield 'select option missing minPlan' => [
            self::catalogWithField([
                'key'     => 'x', 'type' => 'select', 'label' => 'X', 'minPlan' => 'guest',
                'options' => [['value' => 'a', 'label' => 'A']],
            ]),
            '`minPlan` is required',
        ];
        yield 'select option minPlan below field minPlan (CNV-103 review guard)' => [
            self::catalogWithField([
                'key'     => 'x', 'type' => 'select', 'label' => 'X', 'minPlan' => 'free',
                'options' => [['value' => 'a', 'label' => 'A', 'minPlan' => 'guest']],
            ]),
            "which is below the field's own minPlan",
        ];
        yield 'unknown field key' => [
            self::catalogWithField(['key' => 'x', 'type' => 'boolean', 'label' => 'X', 'ffmpegArgs' => '-vf scale']),
            'unknown keys',
        ];
        yield 'invalid text pattern' => [
            self::catalogWithField(['key' => 'x', 'type' => 'text', 'label' => 'X', 'minPlan' => 'guest', 'maxLength' => 5, 'pattern' => '[a-']),
            'not a valid regular expression',
        ];
        yield 'assignment animated must be boolean (CNV-106)' => [
            [
                'version'     => '1',
                'profiles'    => ['a.b' => ['label' => 'L', 'fields' => [['key' => 'x', 'type' => 'boolean', 'label' => 'X', 'minPlan' => 'guest']]]],
                'assignments' => [['profile' => 'a.b', 'animated' => 'yes']],
            ],
            '`animated` must be a boolean',
        ];
        yield 'assignment to an unknown profile' => [
            [
                'version'     => '1',
                'profiles'    => ['a.b' => ['label' => 'L', 'fields' => [['key' => 'x', 'type' => 'boolean', 'label' => 'X', 'minPlan' => 'guest']]]],
                'assignments' => [['profile' => 'nope', 'category' => 'image']],
            ],
            'references an unknown profile',
        ];
        yield 'missing version' => [
            [
                'profiles'    => ['a.b' => ['label' => 'L', 'fields' => [['key' => 'x', 'type' => 'boolean', 'label' => 'X']]]],
                'assignments' => [['profile' => 'a.b']],
            ],
            '`version` must be a non-empty string',
        ];
        yield 'duplicate field key' => [
            [
                'version'  => '1',
                'profiles' => ['a.b' => ['label' => 'L', 'fields' => [
                    ['key' => 'x', 'type' => 'boolean', 'label' => 'X', 'minPlan' => 'guest'],
                    ['key' => 'x', 'type' => 'boolean', 'label' => 'X again', 'minPlan' => 'guest'],
                ]]],
                'assignments' => [['profile' => 'a.b']],
            ],
            'duplicate field key',
        ];
    }

    public function testMissingCatalogFileFailsLoudly(): void
    {
        $service = new ConversionSettingsCatalog('/nonexistent/conversion_settings.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing or unreadable/');

        $service->getVersion();
    }

    // -----------------------------------------------------------------------

    public static function grammarFixturePath(): string
    {
        return dirname(__DIR__, 4) . '/Fixtures/settings_catalog_grammar.json';
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private static function catalogWithField(array $field): array
    {
        return [
            'version'     => '1',
            'profiles'    => ['a.b' => ['label' => 'L', 'fields' => [$field]]],
            'assignments' => [['profile' => 'a.b']],
        ];
    }

    /** @param array<string, mixed> $catalog */
    private function writeCatalog(array $catalog): string
    {
        $path = sys_get_temp_dir() . '/cnv85_' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;

        return $path;
    }
}
