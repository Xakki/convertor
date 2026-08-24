<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api;

use App\Entity\Conversion;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Exception\InvalidConversionOptionException;
use App\Message\ConversionMessage;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionChainFailPropagator;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\Settings\ConversionSettingsCatalog;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Queue\RedisConnectionFactory;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use App\Tests\Support\SeedsConversionRegistry;
use App\Tests\Unit\Service\Conversion\Settings\ConversionSettingsCatalogTest;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * CNV-85 сквозь HTTP: `GET /api/v1/formats` отдаёт версионированные
 * дедуплицированные профили с явной ссылкой у каждой пары, а `POST /convert`
 * ПОВТОРНО валидирует опции на сервере — независимо от того, что каталог
 * показал клиенту.
 */
final class ConversionSettingsCatalogApiTest extends WebTestCase
{
    use SeedsConversionRegistry;

    /** @var list<User> */
    private array $toRemove = [];

    protected function tearDown(): void
    {
        if ($this->toRemove !== []) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->toRemove as $user) {
                $managed = $em->find(User::class, $user->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
            $this->toRemove = [];
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // GET /api/v1/formats
    // -----------------------------------------------------------------------

    public function testFormatsExposesVersionedDeduplicatedProfiles(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/formats');

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertIsArray($body['settings'] ?? null);
        self::assertIsString($body['settings']['version'] ?? null);
        self::assertNotSame('', $body['settings']['version']);
        self::assertIsArray($body['settings']['profiles'] ?? null);

        $byPair = [];
        foreach ($body['formats'] as $pair) {
            self::assertArrayHasKey('settingsProfile', $pair);
            $byPair["{$pair['from']}->{$pair['to']}"] = $pair['settingsProfile'];
        }

        self::assertSame('image.jpeg', $byPair['png->jpg']);
        self::assertSame('image.lossy', $byPair['png->webp']);
        self::assertSame('image.raster', $byPair['jpg->png']);
        self::assertNull($byPair['docx->pdf'], 'Пара без настроек обязана ЯВНО объявлять settingsProfile: null');

        // CNV-95 — static SVG (bmp/gif/ico/tiff) профили.
        self::assertSame('image.bmp', $byPair['svg->bmp']);
        self::assertSame('image.raster', $byPair['svg->gif']);
        self::assertSame('image.raster', $byPair['svg->tiff']);
        self::assertNull($byPair['svg->ico'], 'width/height инертны для svg→ico by design (CNV-75) — профиля быть не должно');
        // Не-svg источники в ico/bmp не затронуты.
        self::assertSame('image.raster', $byPair['jpg->ico']);
        self::assertSame('image.raster', $byPair['jpg->bmp']);

        // CNV-106 "not published" pin (can-fail proof (c)): svg→gif keeps
        // advertising the ORDINARY profile, and the animated one never
        // appears in `settings.profiles` — no browser worker consumes
        // conv.browser today, publishing it would route a real request into
        // a job nobody ever takes.
        self::assertSame('image.raster', $byPair['svg->gif']);
        self::assertArrayNotHasKey('image.svg.animated', $body['settings']['profiles']);

        // Дедупликация: профилей единицы, ссылок на них — сотни.
        self::assertLessThan(
            count(array_filter($byPair)),
            count($body['settings']['profiles']),
        );
    }

    public function testFormatsProfileFieldsFollowTheClosedGrammar(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/formats');

        $body   = json_decode((string) $client->getResponse()->getContent(), true);
        $fields = $body['settings']['profiles']['image.jpeg']['fields'];

        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        self::assertSame(['width', 'height', 'quality', 'background'], array_keys($byKey));
        self::assertSame('number', $byKey['width']['type']);
        self::assertSame(1, $byKey['width']['min']);
        self::assertSame(10000, $byKey['width']['max']);
        self::assertSame('range', $byKey['quality']['type']);
        self::assertSame(1, $byKey['quality']['min']);
        self::assertSame(100, $byKey['quality']['max']);
        self::assertSame('color', $byKey['background']['type']);

        // Ни одно image-поле не несёт default — payload задачи не меняется.
        foreach ($byKey as $key => $field) {
            self::assertNull($field['default'], "Поле {$key} не должно объявлять default в CNV-85");
        }
    }

    public function testFormatsIsNeverServedFromASharedCache(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/formats');

        self::assertStringContainsStringIgnoringCase('Authorization', (string) $client->getResponse()->headers->get('Vary'));

        $user = $this->persistUser('pro');
        $client->request('GET', '/api/v1/formats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($user),
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * Отклонение от AC карточки, зафиксированное осознанно: у image-профилей
     * все поля объявлены `minPlan: guest`, поэтому гость видит их
     * редактируемыми — ровно как принимал старый `validateImageOptions()`.
     * Механизм гейтинга при этом полноценный (см. unit-тесты презентера и
     * валидатора на синтетическом профиле).
     */
    public function testImageFieldsStayEditableForGuestsForBackwardCompatibility(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/formats');

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        foreach ($body['settings']['profiles']['image.jpeg']['fields'] as $field) {
            self::assertTrue($field['editable'], "Поле {$field['key']} обязано остаться доступным гостю");
            self::assertSame('guest', $field['minPlan']);
        }
    }

    // -----------------------------------------------------------------------
    // POST /api/v1/convert — повторная server-side проверка
    // -----------------------------------------------------------------------

    /** @param array<string, string> $options */
    #[DataProvider('rejectedRequestProvider')]
    public function testConvertRevalidatesOptionsServerSide(string $fileName, string $toFormat, array $options, string $expectedCode): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => $toFormat, 'options' => $options],
            ['file'      => $this->uploadedFile($fileName)],
        );

        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($expectedCode, $body['error'] ?? null);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, string>, 3: string}> */
    public static function rejectedRequestProvider(): iterable
    {
        yield 'unknown key' => ['photo.jpg', 'png', ['sharpen' => '3'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'raw engine argument' => ['photo.jpg', 'png', ['convertArgs' => '-resize 50%'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'quality is not offered for png' => ['photo.jpg', 'png', ['quality' => '80'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'background is not offered for webp' => ['photo.jpg', 'webp', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'width above the boundary' => ['photo.jpg', 'png', ['width' => '20000'], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'quality above the boundary' => ['photo.png', 'jpg', ['quality' => '101'], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        yield 'non-integer width' => ['photo.jpg', 'png', ['width' => 'wide'], InvalidConversionOptionException::CODE_INVALID_TYPE];
        yield 'background is not a colour' => ['photo.png', 'jpg', ['background' => 'white'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'pair without a profile' => ['doc.docx', 'pdf', ['width' => '100'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];

        // CNV-97 — PDF/TXT/Markdown document profiles, повторная валидация через HTTP.
        yield 'invalid pageRange characters' => ['source.txt', 'pdf', ['pageRange' => '1;3'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'invalid orientation value' => ['source.md', 'pdf', ['orientation' => 'diagonal'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'invalid encoding value' => ['source.pdf', 'txt', ['encoding' => 'latin1'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'invalid markdown dialect' => ['source.txt', 'md', ['markdownDialect' => 'rst'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'markdownDialect not offered on the pdf profile' => ['source.txt', 'pdf', ['markdownDialect' => 'gfm'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // CNV-98: markdownDialect не имеет эффекта на pdf→md (raw pdftotext
        // -layout, без pandoc) — каталог больше НЕ рекламирует его для этой
        // пары (отдельный профиль document.markdown.verbatim без диалекта).
        yield 'markdownDialect not offered on the pdf-source markdown profile' => ['source.pdf', 'md', ['markdownDialect' => 'gfm'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'docx to pdf still has no profile' => ['doc.docx', 'pdf', ['pageRange' => '1-2'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'odt to txt still has no profile' => ['doc.odt', 'txt', ['encoding' => 'utf-8'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];

        // CNV-100 — media (audio/video), повторная валидация через HTTP.
        // Опции валидируются ДО mime-гейта ConversionManager (см. ConversionController::convert()),
        // поэтому реальный контент файла здесь не важен — только имя
        // (fromFormat = client extension), ровно как и у document-кейсов выше.
        yield 'codec key is rejected as unknown' => ['clip.mp4', 'mkv', ['codec' => 'h264'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'raw ffmpeg args key is rejected as unknown' => ['clip.mp4', 'mkv', ['ffmpegArgs' => '-vf scale=1920:1080'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'unknown audio quality preset' => ['song.mp3', 'wav', ['quality' => 'ultra'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        // Главный риск карточки: video-only поля недоступны на audio-only target.
        yield 'resolution not offered on an audio-only target (video source)' => ['clip.mp4', 'mp3', ['resolution' => '720p'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        yield 'fps not offered on an audio-only target (video source)' => ['clip.mp4', 'mp3', ['fps' => '30'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // Симметрично: audio-поле недоступно на video-capable профиле.
        yield 'quality not offered on the video profile' => ['clip.mp4', 'mkv', ['quality' => 'low'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // Guest (запрос без Authorization) не может тронуть video-поле вовсе —
        // field-level minPlan:free, видео стоит CPU даже на 480p.
        yield 'guest cannot touch the resolution field at all' => ['clip.mp4', 'mkv', ['resolution' => '480p'], InvalidConversionOptionException::CODE_PLAN_REQUIRED];
        // TTS/transcription (isAi) делят `to` с media.audio, но НЕ category —
        // остаются без профиля этой карточки.
        yield 'TTS document pair has no configurable settings' => ['source.md', 'mp3', ['quality' => 'low'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];

        // CNV-103 — data (CSV/JSON), повторная валидация через HTTP.
        yield 'delimiter rejects a non-whitelisted character' => ['data.json', 'csv', ['delimiter' => ':'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'invalid encoding value on the csv profile' => ['data.json', 'csv', ['encoding' => 'latin1'], InvalidConversionOptionException::CODE_INVALID_VALUE];
        yield 'indent out of bounds on the json profile' => ['data.csv', 'json', ['indent' => '9'], InvalidConversionOptionException::CODE_OUT_OF_RANGE];
        // Cross-target key: delimiter belongs to the csv profile, not json.
        yield 'delimiter not offered on the json profile' => ['data.csv', 'json', ['delimiter' => ','], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // Arbitrary serializer option — just an unknown key, same path.
        yield 'raw serializer flag is rejected as unknown' => ['data.csv', 'json', ['phpArraySerializerFlags' => 'JSON_UNESCAPED_UNICODE'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // YAML/TOML/XML as target — card requires no profile, settings rejected.
        yield 'yaml target rejects settings as a pair without a profile' => ['data.csv', 'yaml', ['pretty' => '1'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'toml target rejects settings as a pair without a profile' => ['data.json', 'toml', ['delimiter' => ','], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'xml target rejects settings as a pair without a profile' => ['data.csv', 'xml', ['encoding' => 'utf-8'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];

        // CNV-95 — static SVG (bmp/gif/ico/tiff), повторная валидация через HTTP.
        // Главный риск карточки: svg→ico не имеет ВООБЩЕ никакого профиля —
        // width/height отклоняются как «пара без профиля», а не «поле
        // неизвестно этому профилю» (worker игнорирует их by design, CNV-75).
        yield 'svg to ico rejects width — no profile at all' => ['logo.svg', 'ico', ['width' => '32'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        yield 'svg to ico rejects height — no profile at all' => ['logo.svg', 'ico', ['height' => '32'], InvalidConversionOptionException::CODE_NOT_SUPPORTED];
        // background не предложен на svg→gif — worker не композитит фон для
        // этого target'а (нет alpha-проблемы у GIF-однокадрового вывода тут).
        yield 'background is unknown on svg to gif' => ['logo.svg', 'gif', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
        // background не предложен НЕ-svg источникам в bmp — не catch-all,
        // generic-путь воркера не композитит прозрачность для этих пар.
        yield 'background is unknown on jpg to bmp (non-svg source)' => ['photo.jpg', 'bmp', ['background' => '#FFFFFF'], InvalidConversionOptionException::CODE_UNKNOWN_OPTION];
    }

    /**
     * OCR-маршрут профиля не имеет: все правила `assignments` объявлены с
     * `"ocr": false`, поэтому та же пара jpg→txt БЕЗ флага настраиваема, а С
     * флагом — нет. Так же вёл себя старый guard в ConversionManager.
     */
    public function testOcrRouteHasNoSettingsProfile(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'txt', 'ocr' => '1', 'options' => ['width' => '100']],
            ['file'      => $this->uploadedFile('scan.jpg')],
        );

        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(InvalidConversionOptionException::CODE_NOT_SUPPORTED, $body['error'] ?? null);
    }

    public function testAcceptedOptionsReachTheWorkerMessageNormalized(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'jpg', 'options' => ['width' => '640', 'quality' => '82', 'background' => '#aabbcc']],
            ['file'      => $this->uploadedFile('photo.png')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        // Строки формы → int; цвет приведён к верхнему регистру; ничего лишнего.
        self::assertSame(['width' => 640, 'quality' => 82, 'background' => '#AABBCC'], $message->options);
    }

    /**
     * Payload боевых пар НЕ изменился: у image-полей нет `default`, поэтому
     * запрос без опций даёт пустые options — как и до CNV-85 (hard constraint
     * карточки).
     */
    public function testNoOptionsMeansEmptyOptionsForImagePairs(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'jpg'],
            ['file'      => $this->uploadedFile('photo.png')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame([], $message->options);
    }

    /**
     * CNV-95 — svg→bmp получает СВОЙ профиль (`image.bmp`, width/height +
     * background) — worker композитит прозрачность на фон только для этой
     * пары (`_save_svg_bmp()`), в отличие от generic-пути для остальных
     * →bmp источников.
     */
    public function testAcceptedSvgBmpOptionsReachTheWorkerMessageNormalized(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'bmp', 'options' => ['width' => '64', 'height' => '64', 'background' => '#00ff00']],
            ['file'      => $this->uploadedFile('logo.svg')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame(['width' => 64, 'height' => 64, 'background' => '#00FF00'], $message->options);
    }

    /**
     * CNV-95, главный риск: svg→ico не несёт НИКАКОГО профиля — запрос без
     * options по-прежнему успешен (202) и даёт пустой payload, как и любая
     * пара без профиля.
     */
    public function testNoOptionsMeansEmptyOptionsForSvgIcoPair(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'ico'],
            ['file'      => $this->uploadedFile('logo.svg')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame([], $message->options);
    }

    /**
     * CNV-106 "not published" pin, HTTP level (can-fail proof (c)): even a
     * client that TRIES to request the animated route (`animated=1` in the
     * POST body) gets ignored — `ConversionController` never reads such a
     * field (see class docblocks of {@see \App\Service\Conversion\ConversionRegistry}/
     * {@see \App\Service\Conversion\Settings\ConversionSettingsCatalog}), so
     * the job is dispatched on the ORDINARY `conv_image` transport, never
     * `conv_browser` — proven via the real `TransportNamesStamp`, not just
     * the message payload.
     */
    public function testAnimatedFieldInThePostBodyIsIgnoredJobStillRoutesToImage(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            // `animated` at the TOP level (not inside `options`) — mirrors how
            // a naive client might try to opt into the animated route. The
            // controller never reads this key at all (see docblocks above).
            ['to_format' => 'gif', 'animated' => '1'],
            ['file' => $this->uploadedFile('logo.svg')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame('image', $message->category, 'category stays image — unaffected either way');
        self::assertSame([], $message->options, 'no options field on image.raster is empty by default, unaffected by the stray top-level `animated` key');

        /** @var list<object> $stamps */
        $stamps         = $captured['stamps'] ?? [];
        $transportStamp = null;
        foreach ($stamps as $stamp) {
            if ($stamp instanceof TransportNamesStamp) {
                $transportStamp = $stamp;
            }
        }
        self::assertNotNull($transportStamp, 'dispatch must carry a TransportNamesStamp');
        self::assertSame(['conv_image'], $transportStamp->getTransportNames());
    }

    /**
     * CNV-97 — document-опции (PDF page range/orientation) сериализуются в
     * job payload точно так же, как уже делают image-опции: normalize() → те
     * же ключи, что прислал клиент, без ничего лишнего.
     */
    public function testAcceptedDocumentOptionsReachTheWorkerMessageNormalized(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'pdf', 'options' => ['pageRange' => '1-3,5', 'orientation' => 'landscape']],
            ['file'      => $this->documentUploadedFile('source.txt')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame(['pageRange' => '1-3,5', 'orientation' => 'landscape'], $message->options);
    }

    /**
     * Ни одно document-поле CNV-97 не несёт `default` (та же причина, что у
     * image в CNV-85) — payload УЖЕ существующих боевых pdf/txt/md-пар не
     * изменился у пустого запроса.
     */
    public function testNoOptionsMeansEmptyOptionsForDocumentPairs(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'txt'],
            ['file'      => $this->documentUploadedFile('source.pdf')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame([], $message->options);
    }

    /**
     * CNV-100 — аудио-опции (`quality`) сериализуются в job payload так же, как
     * document/image-опции: normalize() → те же ключи, что прислал клиент, без
     * ничего лишнего. Гость-доступно (category=audio не гейтится auth-слоем).
     */
    public function testAcceptedAudioOptionsReachTheWorkerMessageNormalized(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'wav', 'options' => ['quality' => 'low']],
            ['file'      => $this->audioUploadedFile('song.mp3')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame(['quality' => 'low'], $message->options);
    }

    /**
     * Ни одно media-поле CNV-100 не несёт `default` (та же причина, что у
     * image/document) — payload УЖЕ существующих боевых audio/video-пар не
     * изменился у пустого запроса. Video/audio-only-from-video (category=video)
     * требуют залогиненного пользователя — гость блокируется отдельным
     * auth-гейтом (403 `auth_required`, СНАРУЖИ этой карточки), не связанным с
     * настройками, поэтому проверяются через persisted-пользователя с планом free.
     */
    public function testNoOptionsMeansEmptyOptionsForMediaPairs(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $captured = ['message' => null];
        // ЕДИНОЖДЫ, до первого запроса — см. комментарий у CNV-85
        // multi-request тестов ниже; тот же $captured используется всеми
        // тремя запросами (по ссылке), читаем его СРАЗУ после каждого.
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'wav'],
            ['file'      => $this->audioUploadedFile('song.mp3')],
        );
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var ConversionMessage|null $audioMessage */
        $audioMessage = $captured['message'];
        self::assertNotNull($audioMessage);
        self::assertSame([], $audioMessage->options);

        $free = $this->persistUser('free');

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'mkv'],
            ['file'               => $this->videoUploadedFile('clip.mp4')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free)],
        );
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var ConversionMessage|null $videoMessage */
        $videoMessage = $captured['message'];
        self::assertNotNull($videoMessage);
        self::assertSame([], $videoMessage->options);

        // Audio-only target из video source (mp4→mp3) — category остаётся
        // video, тот же auth-гейт.
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'mp3'],
            ['file'               => $this->videoUploadedFile('clip.mp4')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free)],
        );
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var ConversionMessage|null $audioFromVideoMessage */
        $audioFromVideoMessage = $captured['message'];
        self::assertNotNull($audioFromVideoMessage);
        self::assertSame([], $audioFromVideoMessage->options);
    }

    /**
     * CNV-100, risk 1 (per-VALUE plan gating on the first LIVE gated field) —
     * end-to-end through the real HTTP stack with a persisted `User`/real plan
     * and a real JWT: free-plan `1080p` is rejected, free stays within its own
     * boundary (720p/30fps), a paid (basic) plan gets `1080p` accepted and
     * normalized in the dispatched `ConversionMessage`.
     */
    public function testMediaVideoResolutionPlanGatingThroughTheFullChain(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        // free-plan: GET /formats через ПОЛНУЮ цепочку #[CurrentUser] →
        // User::getPlan() → SettingsAccessLevel::fromPlanName() — 1080p ПОКАЗАН
        // как НЕредактируемый (480p/720p — да), т.е. «не selectable», а не
        // только «отклонён при отправке» (обе половины риска 1 карточки).
        $free = $this->persistUser('free');
        $client->request('GET', '/api/v1/formats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free),
        ]);
        self::assertResponseIsSuccessful();
        $freeResolutionField = $this->mediaVideoResolutionField(json_decode((string) $client->getResponse()->getContent(), true));
        self::assertTrue($freeResolutionField['editable'], 'resolution: field-level minPlan free — доступно free-плану');
        $freeOptionsByValue = [];
        foreach ($freeResolutionField['options'] as $option) {
            $freeOptionsByValue[$option['value']] = $option['editable'];
        }
        self::assertSame(['480p' => true, '720p' => true, '1080p' => false], $freeOptionsByValue);

        // free-plan: 1080p недоступен (нужен paid/basic) → 422 option_plan_required,
        // задача НЕ ставится (ConversionManager не вызывается).
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'mkv', 'options' => ['resolution' => '1080p', 'fps' => '30']],
            ['file'               => $this->videoUploadedFile('clip.mp4')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free)],
        );
        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $rejectedBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(InvalidConversionOptionException::CODE_PLAN_REQUIRED, $rejectedBody['error'] ?? null);
        self::assertStringContainsString('1080p', $rejectedBody['message'] ?? '');
        self::assertNull($captured['message'], 'Отказ ДО ConversionManager — задача не должна была ставиться');

        // free-plan остаётся в своих границах (720p/30fps) — принято.
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'mkv', 'options' => ['resolution' => '720p', 'fps' => '30']],
            ['file'               => $this->videoUploadedFile('clip.mp4')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free)],
        );
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var ConversionMessage|null $freeMessage */
        $freeMessage = $captured['message'];
        self::assertNotNull($freeMessage);
        self::assertSame(['resolution' => '720p', 'fps' => '30'], $freeMessage->options);

        // basic (paid) план: GET /formats показывает 1080p редактируемым.
        $basic = $this->persistUser('basic');
        $client->request('GET', '/api/v1/formats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($basic),
        ]);
        self::assertResponseIsSuccessful();
        $basicResolutionField = $this->mediaVideoResolutionField(json_decode((string) $client->getResponse()->getContent(), true));
        $basicOptionsByValue  = [];
        foreach ($basicResolutionField['options'] as $option) {
            $basicOptionsByValue[$option['value']] = $option['editable'];
        }
        self::assertSame(['480p' => true, '720p' => true, '1080p' => true], $basicOptionsByValue);

        // basic (paid) план: 1080p принят и нормализован в отправленном сообщении.
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'mkv', 'options' => ['resolution' => '1080p', 'fps' => '30']],
            ['file'               => $this->videoUploadedFile('clip.mp4')],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($basic)],
        );
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var ConversionMessage|null $basicMessage */
        $basicMessage = $captured['message'];
        self::assertNotNull($basicMessage);
        self::assertSame(['resolution' => '1080p', 'fps' => '30'], $basicMessage->options);
    }

    /**
     * CNV-103 — data (CSV/JSON) опции сериализуются в job payload так же, как
     * image/document/media-опции: normalize() → те же ключи, что прислал
     * клиент, без ничего лишнего. Гость-доступно (category=data не гейтится
     * auth-слоем, все поля minPlan: guest — дёшево по CPU).
     */
    public function testAcceptedDataOptionsReachTheWorkerMessageNormalized(): void
    {
        $client = static::createClient();
        // ДВА запроса в тесте (tab-delimiter round-trip ниже) — disableReboot()
        // ДО первого request(), тем же паттерном, что и остальные multi-request
        // тесты этого файла (см. комментарий у CNV-85 repair round выше).
        $client->disableReboot();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'csv', 'options' => ['delimiter' => ';', 'quote' => "'", 'encoding' => 'utf-8']],
            ['file'      => $this->jsonUploadedFile('source.json')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame(['delimiter' => ';', 'quote' => "'", 'encoding' => 'utf-8'], $message->options);

        // Tab-delimiter round-trip через РЕАЛЬНУЮ HTTP-форму (не прямой вызов
        // валидатора) — literal `"\t"` в multipart-значении обязан пережить
        // парсинг запроса и дойти до job payload как одиночный tab-байт.
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'csv', 'options' => ['delimiter' => "\t"]],
            ['file'      => $this->jsonUploadedFile('source2.json')],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var ConversionMessage|null $tabMessage */
        $tabMessage = $captured['message'];
        self::assertNotNull($tabMessage);
        self::assertSame(['delimiter' => "\t"], $tabMessage->options);
    }

    /**
     * Ни одно data-поле CNV-103 не несёт `default` (та же причина, что у
     * image/document/media) — payload УЖЕ существующих боевых data-пар не
     * изменился у пустого запроса.
     */
    public function testNoOptionsMeansEmptyOptionsForDataPairs(): void
    {
        $client   = static::createClient();
        $captured = ['message' => null];
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format' => 'json'],
            ['file'      => $this->csvUploadedFile()],
        );

        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame([], $message->options);
    }

    // -----------------------------------------------------------------------
    // CNV-85 repair round: the real chain #[CurrentUser] → User::getPlan() →
    // SettingsAccessLevel::fromPlanName() → GET /formats `editable` + POST
    // /convert accept/reject, exercised THROUGH HTTP with a persisted user and
    // a real JWT — every plan-gate test elsewhere passes SettingsAccessLevel
    // directly and never walks this chain. No live field is plan-gated above
    // guest yet (CNV-85 hard constraint), so — like ConversionOptionsValidatorTest
    // and ConversionManagerRetryDeleteTest — this uses the synthetic
    // `test.grammar` profile (real pair csv→json, category `data`) so the test
    // is real rather than vacuous. Both tests make MULTIPLE requests per
    // client — `KernelBrowser::doRequest()` reboots the kernel before every
    // request after the first, which silently drops any `set()` override —
    // so each calls `$client->disableReboot()` right after `createClient()`,
    // then `useGrammarCatalog()` (and any manager stub) EXACTLY ONCE before
    // the first request; re-`set()`-ing an already-initialized service throws.
    // -----------------------------------------------------------------------

    /**
     * Подменяет ТОЛЬКО лист `ConversionSettingsCatalog` (уже `public: true` в
     * services.yaml) — `ConversionCatalogPresenter`/`ConversionOptionsValidator`
     * его читают на каждый вызов через тот же контейнер, поэтому override листа
     * долетает до обоих. Между запросами внутри ОДНОГО теста контейнер не
     * пересоздаётся ТОЛЬКО если явно вызван `$client->disableReboot()` — иначе
     * `KernelBrowser::doRequest()` перезагружает kernel перед каждым запросом,
     * начиная со второго, и override слетает молча (задача не падает, просто
     * снова обслуживается боевым каталогом). Оба multi-request теста ниже
     * вызывают `disableReboot()` сразу после `createClient()`.
     */
    private function useGrammarCatalog(): void
    {
        $catalog = new ConversionSettingsCatalog(ConversionSettingsCatalogTest::grammarFixturePath());

        static::getContainer()->set(ConversionSettingsCatalog::class, $catalog);
    }

    /**
     * @param array<string, mixed> $body decoded `GET /formats` response
     *
     * @return array<string, array<string, mixed>> `test.grammar` fields keyed by field key
     */
    private function grammarFieldsByKey(array $body): array
    {
        $fields = $body['settings']['profiles']['test.grammar']['fields'] ?? null;
        self::assertIsArray($fields, 'Синтетический профиль test.grammar обязан присутствовать в ответе');

        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field['key']] = $field;
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $body decoded `GET /formats` response
     *
     * @return array<string, mixed> the `resolution` field of the BOEVOI `media.video` profile
     */
    private function mediaVideoResolutionField(array $body): array
    {
        foreach ($body['settings']['profiles']['media.video']['fields'] ?? [] as $field) {
            if ($field['key'] === 'resolution') {
                return $field;
            }
        }

        self::fail('media.video profile is missing the resolution field');
    }

    private function csvUploadedFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cnv85_');
        self::assertNotFalse($path);
        file_put_contents($path, "a,b\n1,2\n");

        return new UploadedFile($path, 'data.csv', null, null, true);
    }

    /**
     * CNV-103: `data` category allows `text/`/`application/` mime prefixes
     * (see `ConversionManager::assertMimeAllowed()`) — plain JSON text content
     * sniffs as `text/plain`, same as `csvUploadedFile()` above.
     */
    private function jsonUploadedFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cnv103_');
        self::assertNotFalse($path);
        file_put_contents($path, "{\"a\":1,\"b\":2}\n");

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * CNV-97: `uploadedFile()` above writes fixed JPEG magic bytes, which is
     * fine for image pairs but sniffs as `image/jpeg` — the real
     * `ConversionManager::assertMimeAllowed()` (application/*|text/* only for
     * `document` category) rejects that with a real 415 for the positive
     * document-pair tests below (Manager here is a REAL instance with stubbed
     * collaborators, not a mock — its gates genuinely run). Plain text content
     * sniffs as `text/plain`, which the document-category allowlist accepts.
     */
    private function documentUploadedFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cnv97_');
        self::assertNotFalse($path);
        file_put_contents($path, "Test document content.\n");

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testFormatsEditableFlagFollowsRealUserPlanThroughTheFullChain(): void
    {
        $client = static::createClient();
        // `KernelBrowser::doRequest()` перезагружает kernel перед каждым
        // запросом, начиная со второго, — override каталога слетал бы молча
        // без disableReboot(). Вызывается ДО первого request().
        $client->disableReboot();
        $this->useGrammarCatalog();

        // Гость: нет Bearer → #[CurrentUser] отдаёт null → accessLevelFor() = Guest.
        $client->request('GET', '/api/v1/formats');
        self::assertResponseIsSuccessful();
        $guestFields = $this->grammarFieldsByKey(json_decode((string) $client->getResponse()->getContent(), true));
        self::assertTrue($guestFields['scale']['editable'], 'scale: minPlan guest');
        self::assertFalse($guestFields['dpi']['editable'], 'dpi: minPlan free — ещё недоступно гостю');
        self::assertFalse($guestFields['tint']['editable'], 'tint: minPlan pro');

        // Реальный persisted User с планом free + реальный JWT.
        $free = $this->persistUser('free');
        $client->request('GET', '/api/v1/formats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free),
        ]);
        self::assertResponseIsSuccessful();
        $freeFields = $this->grammarFieldsByKey(json_decode((string) $client->getResponse()->getContent(), true));
        self::assertTrue($freeFields['dpi']['editable'], 'dpi: minPlan free — доступно free-плану');
        self::assertFalse($freeFields['title']['editable'], 'title: minPlan basic — ещё недоступно free');
        self::assertFalse($freeFields['tint']['editable'], 'tint: minPlan pro');

        // Реальный persisted User с планом pro.
        $pro = $this->persistUser('pro');
        $client->request('GET', '/api/v1/formats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($pro),
        ]);
        self::assertResponseIsSuccessful();
        $proFields = $this->grammarFieldsByKey(json_decode((string) $client->getResponse()->getContent(), true));
        self::assertTrue($proFields['tint']['editable'], 'tint: minPlan pro — доступно pro-плану');
        self::assertFalse($proFields['model']['editable'], 'AI-поле не даёт никому, включая pro (CNV-85)');
    }

    public function testConvertAcceptsOrRejectsOptionByRealUserPlanThroughTheFullChain(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $captured = ['message' => null];
        // ЕДИНОЖДЫ, до первого запроса — см. комментарий в тесте выше; тот же
        // $captured используется обоими запросами (по ссылке), поэтому
        // отдельный stub на каждый запрос не нужен.
        $this->useGrammarCatalog();
        static::getContainer()->set(ConversionManager::class, $this->stubbedManager($captured));

        // free-план: `tint` (minPlan: pro) не редактируем → 422 с машинным кодом.
        $free = $this->persistUser('free');
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'json', 'options' => ['tint' => '#010203']],
            ['file'               => $this->csvUploadedFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($free)],
        );
        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $rejectedBody = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(InvalidConversionOptionException::CODE_PLAN_REQUIRED, $rejectedBody['error'] ?? null);
        self::assertStringContainsString('tint', $rejectedBody['message'] ?? '');
        self::assertNull($captured['message'], 'Отказ ДО ConversionManager — задача не должна была ставиться');

        // pro-план: то же значение принято и доходит до сообщения нормализованным
        // (+ материализованный default `scale`).
        $pro = $this->persistUser('pro');
        $client->request(
            'POST',
            '/api/v1/convert',
            ['to_format'          => 'json', 'options' => ['tint' => '#010203']],
            ['file'               => $this->csvUploadedFile()],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtFor($pro)],
        );
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        /** @var ConversionMessage|null $message */
        $message = $captured['message'];
        self::assertNotNull($message);
        self::assertSame(['scale' => 20, 'tint' => '#010203'], $message->options);
    }

    // -----------------------------------------------------------------------

    private function persistUser(string $plan): User
    {
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setPlan($plan);
        $em->persist($user);
        $em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function jwtFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array{message: object|null, stamps?: list<object>} $captured заполняется отправленным
     *        ConversionMessage и (CNV-106) стампами dispatch — `stamps` нужен только тестам,
     *        которым важен реальный transport/stream (`TransportNamesStamp`), остальные его игнорируют.
     */
    private function stubbedManager(array &$captured): ConversionManager
    {
        $quota = $this->createStub(QuotaService::class);
        $quota->method('maxUploadBytes')->willReturn(500 * 1024 * 1024);
        $quota->method('check')->willReturn(BillingMode::PlanQuota);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof Conversion) {
                (new \ReflectionProperty(Conversion::class, 'id'))->setValue($entity, 999);
            }
        });

        $s3Client = $this->createStub(S3Client::class);
        $s3Client->method('putObject')->willReturn(ResultMockFactory::create(PutObjectOutput::class));

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []) use (&$captured): Envelope {
                $captured['message'] = $message;
                $captured['stamps']  = $stamps;

                return new Envelope($message, $stamps);
            },
        );

        return new ConversionManager(
            $this->newSeedRegistry(),
            $this->createStub(ConversionRepository::class),
            $quota,
            $em,
            $bus,
            new ConversionStatusReader(new RedisConnectionFactory('redis://localhost')),
            new S3Storage($s3Client, 'convertor'),
            new ConversionChainFailPropagator(
                $this->createStub(ConversionRepository::class),
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(QuotaService::class),
            ),
        );
    }

    private function uploadedFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cnv85_');
        self::assertNotFalse($path);
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * CNV-100: category=video требires a REAL `video/*` mime sniff
     * (`ConversionManager::assertMimeAllowed()` allows ONLY `video/` for
     * `FileCategory::Video`) for the tests that reach the real manager (accept
     * paths) — same minimal ISO-BMFF `ftyp` box already used by
     * `ConversionQuotaEnforcementTest`/`ConversionManagerGuestGateTest`.
     */
    private function videoUploadedFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cnv100_');
        self::assertNotFalse($path);
        file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 32));

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * category=audio allows `audio/*` (and `video/*`, for video→audio
     * extraction — see `assertMimeAllowed()`) — a minimal MPEG frame header
     * sniffs as `audio/mpeg`.
     */
    private function audioUploadedFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cnv100_');
        self::assertNotFalse($path);
        file_put_contents($path, "\xFF\xFB\x90\x64" . str_repeat("\x00", 64));

        return new UploadedFile($path, $name, null, null, true);
    }
}
