<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Реальный end-to-end по ПУБЛИЧНОМУ API: гоняет живой стек e2e (php+nginx+ws-gateway
 * +воркеры) через настоящий HTTP, а не in-process ядро. Причина выбора транспорта:
 *
 *  1. Изоляция БД: dama/doctrine-test-bundle НЕ подключён (нет config/packages/test/),
 *     но in-process WebTestCase всё равно неверен — результат конвертации пишет
 *     ВТОРОЙ php-процесс (воркер → relay), которого в тест-ядре нет.
 *  2. Транспорт Messenger в APP_ENV=test — настоящий (conv+redis:// → KeyDB db3,
 *     без sync/in-memory override), значит задачу забирает реальный воркер вне
 *     процесса. Только внешний HTTP-клиент это корректно упражняет.
 *
 * Для каждой категории (image / data / ffmpeg / document) — свой Telegram-юзер
 * (free-план = 2 конвертации/сутки), поэтому квота не мешает. Токен подписи пустой
 * (в APP_ENV=test Symfony Dotenv НЕ грузит .env.local → TELEGRAM_BOT_TOKEN=''),
 * ровно как и у обслуживающего php-fpm, так что hash совпадает.
 *
 * Тайминги (submit→first-processing, submit→download) логируются в STDERR;
 * жёстких верхних границ нет (флаки под нагрузкой) — проверяется корректность:
 * терминальный статус = completed, тело результата непустое и правдоподобное.
 */
#[Group('integration')]
final class ConversionApiIntegrationTest extends TestCase
{
    private const POLL_INTERVAL_US = 500_000; // 0.5s

    private static function baseUrl(): string
    {
        $url = getenv('API_BASE_URL');

        return \is_string($url) && $url !== '' ? rtrim($url, '/') : 'http://nginx';
    }

    private static function botToken(): string
    {
        $token = getenv('TELEGRAM_BOT_TOKEN');
        if ($token === false) {
            $token = $_SERVER['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        }

        return (string) $token;
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: string}>
     */
    public static function conversionCases(): iterable
    {
        // [fixture, upload filename, to_format, category, timeout(s), tgIdBase]
        yield 'data (csv→json)' => ['data.csv', 'data.csv', 'json', 'data', 30, '77010000000'];
        yield 'image (jpg→png)' => ['image.jpg', 'image.jpg', 'png', 'image', 60, '77020000000'];
        yield 'ffmpeg (mp3→wav)' => ['story.mp3', 'story.mp3', 'wav', 'audio', 120, '77030000000'];
        yield 'document (docx→pdf)' => ['document.docx', 'document.docx', 'pdf', 'document', 120, '77040000000'];
    }

    #[DataProvider('conversionCases')]
    public function testPublicApiConversionEndToEnd(
        string $fixture,
        string $filename,
        string $toFormat,
        string $category,
        int $timeoutSec,
        string $tgIdBase,
    ): void {
        $path = \dirname(__DIR__, 2) . '/Fixtures/' . $fixture;
        self::assertFileExists($path, "fixture missing: {$fixture}");

        $client = HttpClient::create(['timeout' => 30]);

        // Fresh user per run (suffix by time) → квота free-плана всегда свежая.
        $tgId  = $tgIdBase . str_pad((string) (time() % 100000), 5, '0', STR_PAD_LEFT);
        $token = $this->login($client, $tgId);

        $t0 = microtime(true);

        // --- submit ---------------------------------------------------------
        $formData = new FormDataPart([
            'file'      => DataPart::fromPath($path, $filename),
            'to_format' => $toFormat,
        ]);
        $headers = array_merge(
            ['Authorization: Bearer ' . $token],
            $formData->getPreparedHeaders()->toArray(),
        );
        $submit = $client->request('POST', self::baseUrl() . '/api/v1/convert', [
            'headers' => $headers,
            'body'    => $formData->bodyToIterable(),
        ]);

        $submitStatus = $submit->getStatusCode();
        $submitBody   = $submit->getContent(false);
        self::assertSame(202, $submitStatus, "[{$category}] POST /convert expected 202, got {$submitStatus}: {$submitBody}");

        $submitJson = json_decode($submitBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('conversion_id', $submitJson, "[{$category}] no conversion_id in submit response");
        $conversionId = (int) $submitJson['conversion_id'];
        self::assertGreaterThan(0, $conversionId, "[{$category}] invalid conversion_id");

        // --- poll status ----------------------------------------------------
        $deadline        = $t0 + $timeoutSec;
        $firstProcAt     = null;
        $lastStatus      = (string) ($submitJson['status'] ?? 'pending');
        $lastError       = null;
        $terminalReached = false;

        while (microtime(true) < $deadline) {
            $statusRes = $client->request('GET', self::baseUrl() . "/api/v1/convert/{$conversionId}/status", [
                'headers' => ['Authorization: Bearer ' . $token],
            ]);
            $code = $statusRes->getStatusCode();
            self::assertSame(200, $code, "[{$category}] status endpoint returned {$code}: " . $statusRes->getContent(false));

            $statusJson = json_decode($statusRes->getContent(false), true, 512, JSON_THROW_ON_ERROR);
            $lastStatus = (string) ($statusJson['status'] ?? '');
            $lastError  = $statusJson['error'] ?? null;

            if ($firstProcAt === null && $lastStatus !== 'pending' && $lastStatus !== '') {
                $firstProcAt = microtime(true);
            }

            if ($lastStatus === 'completed') {
                $terminalReached = true;
                break;
            }
            if ($lastStatus === 'failed' || $lastStatus === 'expired') {
                self::fail("[{$category}] conversion {$conversionId} terminal={$lastStatus}, error=" . var_export($lastError, true));
            }

            usleep(self::POLL_INTERVAL_US);
        }

        $doneAt = microtime(true);
        self::assertTrue(
            $terminalReached,
            "[{$category}] timed out after {$timeoutSec}s waiting for 'completed' (last status='{$lastStatus}', error=" . var_export($lastError, true) . ')',
        );

        // --- download + validate -------------------------------------------
        $download = $client->request('GET', self::baseUrl() . "/api/v1/convert/{$conversionId}/download", [
            'headers' => ['Authorization: Bearer ' . $token],
        ]);
        $dlCode = $download->getStatusCode();
        self::assertSame(200, $dlCode, "[{$category}] download expected 200, got {$dlCode}: " . $download->getContent(false));

        $result = $download->getContent();
        self::assertNotSame('', $result, "[{$category}] download body is empty");
        self::assertGreaterThan(16, \strlen($result), "[{$category}] download body implausibly small (" . \strlen($result) . ' bytes)');
        $this->assertPlausible($category, $toFormat, $result);

        // --- timings (log only, no tight asserts) ---------------------------
        $submitToProc = $firstProcAt !== null ? ($firstProcAt - $t0) : null;
        $submitToDone = $doneAt - $t0;
        fwrite(STDERR, sprintf(
            "\n[TIMING][%-9s] %s: submit→first-processing=%s  submit→download(terminal)=%.2fs  result=%d bytes  id=%d\n",
            $category,
            $toFormat,
            $submitToProc !== null ? sprintf('%.2fs', $submitToProc) : 'n/a (jumped straight to completed)',
            $submitToDone,
            \strlen($result),
            $conversionId,
        ));
    }

    private function login(HttpClientInterface $client, string $tgId): string
    {
        $payload = $this->signedTelegramPayload(self::botToken(), $tgId, time() - 30);

        $res = $client->request('POST', self::baseUrl() . '/api/v1/auth/telegram', [
            'headers' => ['Content-Type: application/json'],
            'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $code = $res->getStatusCode();
        $body = $res->getContent(false);
        self::assertSame(200, $code, "auth/telegram expected 200, got {$code}: {$body}");

        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('token', $json, 'login must return an access JWT');
        self::assertNotSame('', (string) $json['token'], 'empty JWT');

        return (string) $json['token'];
    }

    /**
     * Mirrors TelegramAuthService::buildCheckString: filter nulls, ksort,
     * "k=v"\n join, HMAC-SHA256 keyed by sha256(botToken).
     *
     * @return array<string, int|string>
     */
    private function signedTelegramPayload(string $botToken, string $tgId, int $authDate): array
    {
        $fields = [
            'auth_date'  => (string) $authDate,
            'first_name' => 'QaIntegration',
            'id'         => $tgId,
        ];
        ksort($fields);

        $checkString = implode("\n", array_map(
            static fn (string $k, string $v) => "{$k}={$v}",
            array_keys($fields),
            array_values($fields),
        ));
        $secretKey = hash('sha256', $botToken, true);
        $hash      = hash_hmac('sha256', $checkString, $secretKey);

        return [
            'id'         => $tgId,
            'first_name' => 'QaIntegration',
            'auth_date'  => $authDate,
            'hash'       => $hash,
        ];
    }

    private function assertPlausible(string $category, string $toFormat, string $body): void
    {
        switch ($toFormat) {
            case 'json':
                $decoded = json_decode($body, true);
                self::assertNotNull($decoded, "[{$category}] result is not valid JSON");
                break;
            case 'png':
                self::assertSame("\x89PNG\r\n\x1a\n", substr($body, 0, 8), "[{$category}] result lacks PNG signature");
                break;
            case 'wav':
                self::assertSame('RIFF', substr($body, 0, 4), "[{$category}] result lacks RIFF header");
                self::assertSame('WAVE', substr($body, 8, 4), "[{$category}] result lacks WAVE tag");
                break;
            case 'pdf':
                self::assertSame('%PDF', substr($body, 0, 4), "[{$category}] result lacks %PDF header");
                break;
            default:
                self::fail("no plausibility check defined for target format '{$toFormat}'");
        }
    }
}
