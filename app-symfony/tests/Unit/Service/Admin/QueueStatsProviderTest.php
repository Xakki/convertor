<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Enum\WorkerType;
use App\Repository\ConversionRepository;
use App\Service\Admin\PrometheusMetricsParser;
use App\Service\Admin\QueueStatsProvider;
use App\Service\Conversion\ConversionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Юнит-тесты сборщика состояния очередей: скрейп фикстуры exporter'а через
 * MockHttpClient + застаб-репозиторий (без БД). Проверяет извлечение per-stream
 * метрик, исключение conv.dead из таблицы, присутствие ВСЕХ канонических типов,
 * dead-letter/keydbUp и деградацию при недоступном exporter'е.
 */
final class QueueStatsProviderTest extends TestCase
{
    private const FIXTURE = <<<'PROM'
        # HELP convertor_stream_length Total number of entries in a conv.* stream (XLEN).
        # TYPE convertor_stream_length gauge
        convertor_stream_length{stream="conv.document"} 5.0
        convertor_stream_length{stream="conv.image"} 0.0
        convertor_stream_length{stream="conv.dead"} 9.0
        convertor_stream_group_pending{stream="conv.document",group="convertor"} 3.0
        convertor_stream_group_lag{group="convertor",stream="conv.document"} 4.0
        convertor_stream_group_consumers{stream="conv.document",group="convertor"} 0.0
        convertor_stream_pending_max_idle_ms{stream="conv.document",group="convertor"} 600000.0
        convertor_dead_letter_messages 9.0
        convertor_exporter_up 1.0
        PROM;

    public function testExtractsPerStreamMetricsAndSignals(): void
    {
        $provider = $this->provider(new MockResponse(self::FIXTURE));
        $data     = $provider->collect();

        self::assertTrue($data['exporterAvailable']);
        self::assertTrue($data['keydbUp']);
        self::assertSame(9, $data['deadLetter']);

        // Все 6 канонических типов присутствуют, даже без своих gauge-строк (audio…).
        $byType = [];
        foreach ($data['streams'] as $s) {
            $byType[$s['type']] = $s;
        }
        self::assertSame(array_map(static fn (WorkerType $t): string => $t->value, WorkerType::cases()), array_keys($byType));

        // conv.dead НЕ попал в таблицу типов.
        self::assertArrayNotHasKey('dead', $byType);
        foreach ($data['streams'] as $s) {
            self::assertNotSame('conv.dead', $s['stream']);
        }

        // document: length/pending/lag/consumers/idle из фикстуры + оба сигнала.
        $doc = $byType['document'];
        self::assertSame(5, $doc['length']);
        self::assertSame(3, $doc['pending']);
        self::assertSame(4, $doc['lag']);
        self::assertSame(0, $doc['consumers']);
        self::assertSame(600000, $doc['maxIdleMs']);
        self::assertContains('idle', $doc['signals']);      // idle 600s > 5 мин
        self::assertContains('stalled', $doc['signals']);   // lag>0 при 0 consumers

        // Тип без метрик (audio) — нули, без сигналов.
        $audio = $byType['audio'];
        self::assertSame(0, $audio['length']);
        self::assertSame([], $audio['signals']);
    }

    public function testUnreachableExporterDegradesGracefully(): void
    {
        // MockResponse, бросающий транспортную ошибку при чтении тела.
        $provider = $this->provider(new MockResponse('', ['error' => 'connection refused']));
        $data     = $provider->collect();

        self::assertFalse($data['exporterAvailable']);
        self::assertNull($data['keydbUp']);
        self::assertNull($data['deadLetter']);
        self::assertNotNull($data['exporterError']);
        // Строки типов всё равно есть (значения null), DB-сигнал доступен.
        self::assertCount(\count(WorkerType::cases()), $data['streams']);
        self::assertNull($data['streams'][0]['length']);
        self::assertSame(0, $data['dbStuck']['count']);
    }

    /** Без ConversionRegistry (не передан в конструктор) — warnings пустой, ключ всё равно присутствует. */
    public function testWarningsEmptyWithoutRegistry(): void
    {
        $provider = $this->provider(new MockResponse(self::FIXTURE));
        $data     = $provider->collect();

        self::assertArrayHasKey('warnings', $data);
        self::assertSame([], $data['warnings']);
    }

    /** ConversionRegistry::getCapabilityWarnings() прокидывается в JSON как есть. */
    public function testWarningsPropagateFromRegistry(): void
    {
        $registryWarnings = [
            ['workerType' => 'ai', 'droppedFormats' => ['mp3'], 'droppedCount' => 1, 'totalFormats' => 2],
        ];

        $registry = $this->createStub(ConversionRegistry::class);
        $registry->method('getCapabilityWarnings')->willReturn($registryWarnings);

        $provider = $this->provider(new MockResponse(self::FIXTURE), $registry);
        $data     = $provider->collect();

        self::assertSame($registryWarnings, $data['warnings']);
    }

    private function provider(ResponseInterface $response, ?ConversionRegistry $registry = null): QueueStatsProvider
    {
        $conversions = $this->createStub(ConversionRepository::class);
        $conversions->method('countStuck')->willReturn(0);
        $conversions->method('findStuck')->willReturn([]);

        return new QueueStatsProvider(
            new MockHttpClient($response),
            new PrometheusMetricsParser(),
            $conversions,
            'http://metrics-exporter:9472/metrics',
            registry: $registry,
        );
    }
}
