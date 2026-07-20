<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Conversion;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionRegistry;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Сбор состояния очередей конвертации для admin-панели (эпик admin-panel,
 * подзадача queues).
 *
 * Источник метрик стримов — существующий `metrics_exporter` (Prometheus-sidecar,
 * `docker-compose.yml` сервис `metrics-exporter`, порт 9472): Symfony скрейпит его
 * текстовый `/metrics` по внутренней docker-сети `backend` (php ↔ metrics-exporter
 * общий сегмент). Прямого чтения KeyDB Streams из PHP НЕТ — `WorkerStreamGateway`
 * намеренно без XLEN/XPENDING. Недоступность exporter'а не роняет панель:
 * `exporterAvailable=false` + сигналы из БД остаются.
 *
 * «Зависшая задача» — мульти-сигнал, каждый сюрфейсится отдельно (не один флаг):
 *  - `idle`    — oldest-PEL idle > 5 мин (`convertor_stream_pending_max_idle_ms`);
 *  - `stalled` — есть backlog (`lag>0`) при 0 consumers группы;
 *  - dead-letter — рост `conv.dead` (`convertor_dead_letter_messages`);
 *  - db-stuck  — строки `Conversion` в Pending/Processing старше порога.
 *
 * `warnings` — доп. сигнал конфигурации (не про очередь-стрим, а про полноту
 * capability-данных воркера в БД): AI-воркер объявил в `matrix` from-формат,
 * для которого нет `matrix_categories` → пара молча дропается при построении
 * routing-матрицы ({@see ConversionRegistry::buildMatrixFromCapabilities()}).
 * Раньше это тонуло в `logger->warning`; теперь видно в admin. Считается «на
 * лету» через {@see ConversionRegistry::getCapabilityWarnings()}.
 */
final readonly class QueueStatsProvider
{
    /**
     * Канонический набор job-стримов `conv.<type>` — источник истины
     * `config/packages/messenger.yaml` (транспорты conv.document…conv.ai) и
     * `workers/gateway/keydb.py::WORKER_TYPES`. Показываем каждый, даже если
     * exporter по нему ещё не эмитил метрику (пустой стрим = нет gauge-строки).
     *
     * @var list<string>
     */
    public const array STREAM_TYPES = ['document', 'image', 'audio', 'video', 'data', 'ai'];

    /** Consumer-группа стримов (совпадает с `keydb.py::GROUP`). */
    private const string GROUP = 'convertor';

    /** Порог idle oldest-PEL для сигнала `idle`, мс (= `keydb.py::STALE_IDLE_MS`). */
    private const int STUCK_IDLE_MS = 300_000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private PrometheusMetricsParser $parser,
        private ConversionRepository $conversions,
        private string $exporterUrl,
        private int $dbStuckMinutes = 15,
        private int $dbStuckLimit = 50,
        private float $timeoutSeconds = 2.0,
        private ?ConversionRegistry $registry = null,
    ) {
    }

    /**
     * @return array{
     *     exporterAvailable: bool,
     *     keydbUp: bool|null,
     *     exporterError: string|null,
     *     streams: list<array{type: string, stream: string, length: int|null, pending: int|null, lag: int|null, consumers: int|null, maxIdleMs: int|null, signals: list<string>}>,
     *     deadLetter: int|null,
     *     dbStuck: array{count: int, thresholdMinutes: int, items: list<array{id: int, status: string, from: string, to: string, ageMinutes: int, updatedAt: string}>},
     *     warnings: list<array{workerType: string, droppedFormats: list<string>, droppedCount: int, totalFormats: int}>
     * }
     */
    public function collect(): array
    {
        $scrape  = $this->scrape();
        $metrics = $scrape['metrics'];

        $lengths   = $this->byStream($metrics, 'convertor_stream_length');
        $pending   = $this->byStreamGroup($metrics, 'convertor_stream_group_pending');
        $lag       = $this->byStreamGroup($metrics, 'convertor_stream_group_lag');
        $consumers = $this->byStreamGroup($metrics, 'convertor_stream_group_consumers');
        $maxIdle   = $this->byStreamGroup($metrics, 'convertor_stream_pending_max_idle_ms');

        $available = $scrape['available'];

        $streams = [];
        foreach (self::STREAM_TYPES as $type) {
            $stream = 'conv.' . $type;

            if (! $available) {
                $streams[] = [
                    'type' => $type, 'stream' => $stream, 'length' => null, 'pending' => null,
                    'lag'  => null, 'consumers' => null, 'maxIdleMs' => null, 'signals' => [],
                ];
                continue;
            }

            $length    = (int) ($lengths[$stream] ?? 0);
            $pend      = (int) ($pending[$stream] ?? 0);
            $lagV      = (int) ($lag[$stream] ?? 0);
            $consumerN = (int) ($consumers[$stream] ?? 0);
            $idleMs    = (int) ($maxIdle[$stream] ?? 0);

            $signals = [];
            if ($idleMs > self::STUCK_IDLE_MS) {
                $signals[] = 'idle';
            }
            if ($lagV > 0 && $consumerN === 0) {
                $signals[] = 'stalled';
            }

            $streams[] = [
                'type' => $type, 'stream' => $stream, 'length' => $length, 'pending' => $pend,
                'lag'  => $lagV, 'consumers' => $consumerN, 'maxIdleMs' => $idleMs, 'signals' => $signals,
            ];
        }

        $deadLetter = null;
        if ($available) {
            $dead       = $metrics['convertor_dead_letter_messages'][0]['value'] ?? 0.0;
            $deadLetter = (int) $dead;
        }

        return [
            'exporterAvailable' => $available,
            'keydbUp'           => $this->keydbUp($metrics, $available),
            'exporterError'     => $scrape['error'],
            'streams'           => $streams,
            'deadLetter'        => $deadLetter,
            'dbStuck'           => $this->dbStuck(),
            'warnings'          => $this->registry?->getCapabilityWarnings() ?? [],
        ];
    }

    /**
     * @return array{available: bool, metrics: array<string, list<array{labels: array<string, string>, value: float}>>, error: string|null}
     */
    private function scrape(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->exporterUrl, [
                'timeout' => $this->timeoutSeconds,
            ]);
            // getContent() бросает при 4xx/5xx и транспортных ошибках.
            $body = $response->getContent();

            return ['available' => true, 'metrics' => $this->parser->parse($body), 'error' => null];
        } catch (HttpClientExceptionInterface $e) {
            // Exporter недоступен/ошибка — панель НЕ падает, отдаём deg-стейт.
            return ['available' => false, 'metrics' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * `convertor_exporter_up`: 1 если последний скрейп exporter'а достучался до
     * KeyDB. null — если сам exporter недоступен или метрику не отдал.
     *
     * @param array<string, list<array{labels: array<string, string>, value: float}>> $metrics
     */
    private function keydbUp(array $metrics, bool $available): ?bool
    {
        if (! $available || ! isset($metrics['convertor_exporter_up'][0])) {
            return null;
        }

        return $metrics['convertor_exporter_up'][0]['value'] >= 1.0;
    }

    /**
     * Метрика с одной меткой `stream` → map stream→value. Исключаем `conv.dead`
     * (DLQ, не тип конвертации — учитывается отдельным dead-letter счётчиком).
     *
     * @param array<string, list<array{labels: array<string, string>, value: float}>> $metrics
     *
     * @return array<string, float>
     */
    private function byStream(array $metrics, string $name): array
    {
        $out = [];
        foreach ($metrics[$name] ?? [] as $sample) {
            $stream = $sample['labels']['stream'] ?? null;
            if ($stream === null || $stream === 'conv.dead') {
                continue;
            }
            $out[$stream] = $sample['value'];
        }

        return $out;
    }

    /**
     * Метрика с метками `stream`+`group` → map stream→value (только наша группа).
     *
     * @param array<string, list<array{labels: array<string, string>, value: float}>> $metrics
     *
     * @return array<string, float>
     */
    private function byStreamGroup(array $metrics, string $name): array
    {
        $out = [];
        foreach ($metrics[$name] ?? [] as $sample) {
            $stream = $sample['labels']['stream'] ?? null;
            $group  = $sample['labels']['group']  ?? null;
            if ($stream === null || $stream === 'conv.dead' || $group !== self::GROUP) {
                continue;
            }
            $out[$stream] = $sample['value'];
        }

        return $out;
    }

    /**
     * DB-сигнал: конвертации, завязшие в Pending/Processing дольше порога.
     *
     * @return array{count: int, thresholdMinutes: int, items: list<array{id: int, status: string, from: string, to: string, ageMinutes: int, updatedAt: string}>}
     */
    private function dbStuck(): array
    {
        $now       = new \DateTimeImmutable();
        $threshold = $now->modify('-' . $this->dbStuckMinutes . ' minutes');

        $count = $this->conversions->countStuck($threshold);
        $rows  = $this->conversions->findStuck($threshold, $this->dbStuckLimit);

        $items = array_map(static function (Conversion $c) use ($now): array {
            $ageMin = (int) floor(($now->getTimestamp() - $c->getUpdatedAt()->getTimestamp()) / 60);

            return [
                'id'         => $c->getId(),
                'status'     => $c->getStatus()->value,
                'from'       => $c->getFromFormat(),
                'to'         => $c->getToFormat(),
                'ageMinutes' => max(0, $ageMin),
                'updatedAt'  => $c->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $rows);

        return ['count' => $count, 'thresholdMinutes' => $this->dbStuckMinutes, 'items' => $items];
    }
}
