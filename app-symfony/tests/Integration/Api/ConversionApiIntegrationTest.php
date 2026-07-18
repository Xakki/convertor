<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

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
 * (free-план = 2 конвертации/сутки), поэтому квота не мешает. Логин — БЕЗ HTTP:
 * auth теперь magic-link (реальный Telegram round-trip в e2e не воспроизвести),
 * поэтому {@see self::login()} персистит User напрямую через EntityManager (в
 * ТОЙ ЖЕ test-БД, что обслуживающий php-fpm — общий DATABASE_URL) и минтит JWT
 * через тот же JWTTokenManagerInterface, что и прод-логин — HMAC-виджет
 * (`POST /api/v1/auth/telegram`, удалён вместе с картой upload-ui-bot-auth-rework)
 * тут больше не участвует.
 *
 * Тайминги (submit→first-processing, submit→download) логируются в STDERR;
 * жёстких верхних границ нет (флаки под нагрузкой) — проверяется корректность:
 * терминальный статус = completed, тело результата непустое и правдоподобное.
 */
#[Group('integration')]
final class ConversionApiIntegrationTest extends KernelTestCase
{
    private const POLL_INTERVAL_US = 500_000; // 0.5s

    private static function baseUrl(): string
    {
        $url = getenv('API_BASE_URL');

        return \is_string($url) && $url !== '' ? rtrim($url, '/') : 'http://nginx';
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
        $token = $this->login($tgId);

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

    /**
     * Mint a JWT directly for a fresh test User — no HTTP round-trip. Auth is
     * magic-link now (real Telegram round-trip, not reproducible in e2e); this
     * boots the kernel just to persist a User in the SAME test DB the serving
     * php-fpm process uses (shared DATABASE_URL) and issues a token via the
     * SAME JWTTokenManagerInterface the app's own login controllers use, so it
     * validates cross-process exactly like a real login would.
     */
    private function login(string $tgId): string
    {
        self::bootKernel();
        $container = self::getContainer();

        $user = (new User())
            ->setTelegramId($tgId)
            ->setFirstName('QaIntegration');
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
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
