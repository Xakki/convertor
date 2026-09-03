<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\WorkerCapability;
use App\Repository\WorkerCapabilityRepository;
use App\Service\Worker\WorkerLivenessTtl;

/**
 * Сбор списка зарегистрированных воркеров для admin-панели (registry-07,
 * финальный шаг эпика `registry-00-self-registration`).
 *
 * Источник — тот же {@see WorkerCapabilityRepository::findAllCapabilities()},
 * что {@see \App\Service\Conversion\ConversionRegistry} использует для
 * построения routing-матрицы (registry-05: единственный источник, без
 * хардкод-фолбэка) — эта панель показывает РОВНО те строки, что реально
 * участвуют в маршрутизации, не отдельный/расходящийся снимок.
 *
 * НЕ дублирует {@see \App\Service\Conversion\ConversionRegistry::getCapabilityWarnings()}
 * (AI-воркер без `matrix_categories` → пары молча дропаются из routing-матрицы)
 * — та логика и её вывод остаются на `/admin/queues`
 * ({@see QueueStatsProvider::collect()}); эта страница только ссылается на неё
 * в UI, не копирует.
 *
 * `stale` — TTL-сравнение читает ТОТ ЖЕ конфиг (`WORKER_CAPABILITY_GC_TTL_HOURS`),
 * что {@see \App\Service\Worker\WorkerCapabilityGcService} использует для
 * РЕАЛЬНОГО удаления строк — намеренно НЕТ второго литерала TTL здесь.
 * `$ttlHours` — обязательный конструкторный параметр (без дефолта) именно
 * чтобы не завести случайный повторно-хардкоженный номер вместо явного
 * `%env(...)%`-wiring в services.yaml. Ту же дисциплину распространили и на
 * САМУ ФОРМУЛУ порога (registry-07 review): значение TTL было общим с самого
 * начала, но формула "TTL → cutoff-дата" была задублирована буквально в этом
 * классе и в `WorkerCapabilityGcService::run()` — обе стороны теперь идут
 * через {@see \App\Service\Worker\WorkerLivenessTtl::staleThreshold()}, чтобы
 * будущая правка floor/единиц/jitter в одном месте не разошлась молча со
 * вторым.
 */
final readonly class WorkerStatsProvider
{
    /**
     * Внутренний sentinel для группировки легacy-строк (`host IS NULL`) в
     * {@see collectHosts()} — просто читаемее на диффе, чем buckets[null].
     * Никогда не пересекается с реальным именем хоста (NUL-байт невалиден в
     * строке-хосте, приходящей от gateway/воркера).
     */
    private const string NULL_HOST_BUCKET_KEY = "\0__none__";

    public function __construct(
        private WorkerCapabilityRepository $repository,
        private int $ttlHours,
    ) {
    }

    /**
     * @return array{
     *     ttlHours: int,
     *     workers: list<array{
     *         workerType: string,
     *         instanceId: string,
     *         image: string|null,
     *         version: string|null,
     *         provenance: array{appVersion: string|null, build: string|null, revision: string|null, sourceState: string|null, imageRepository: string|null},
     *         lastSeen: string,
     *         status: string,
     *         stale: bool,
     *         pairCount: int,
     *         matrix: array<string, list<string>>,
     *         isAi: bool,
     *         streams: list<string>,
     *         routingKeys: list<string>,
     *         executionKind: string|null,
     *         settings: array<string, mixed>|null,
     *         matrix_categories: array<string, string>,
     *         metrics: array{cpu: float|null, mem: float|null, load: float|null}|null,
     *         host: string|null,
     *         inflight: int|null
     *     }>
     * }
     */
    public function collect(): array
    {
        // registry-07 review: threshold-формула вынесена в WorkerLivenessTtl —
        // тот же callable использует WorkerCapabilityGcService (реальное
        // удаление), чтобы страница никогда не разошлась с тем, когда GC
        // фактически соберёт строку.
        $threshold = WorkerLivenessTtl::staleThreshold($this->ttlHours);

        $rows = array_map(
            fn (WorkerCapability $cap): array => $this->toRow($cap, $threshold),
            $this->repository->findAllCapabilities(),
        );

        // workerType asc, затем instanceId asc.
        usort($rows, static fn (array $a, array $b): int
            => $a['workerType'] <=> $b['workerType']
            ?: $a['instanceId'] <=> $b['instanceId']);

        return ['ttlHours' => $this->ttlHours, 'workers' => $rows];
    }

    /**
     * Per-host агрегат для `/admin/workers/hosts` (CNV-61) — ленивая загрузка
     * host-списка, детальный список воркеров хоста подгружается отдельно
     * (`?host=` на {@see \App\Controller\Admin\Api\WorkerController::workers()}).
     *
     * Намеренно СВЕРНУТО В PHP над готовым выводом {@see collect()}/{@see toRow()},
     * НЕ отдельным GROUP BY SQL-запросом: `status` — продукт реконсайлера
     * (registry-09), `stale` — TTL-сравнение через {@see WorkerLivenessTtl} — обе
     * формулы уже единожды выражены в `toRow()`; повторный вывод той же логики
     * на SQL гарантированно рассинхронизировался бы с ней при следующей правке
     * одной из сторон. Таблица крохотная (десятки строк) — цена PHP-свёртки
     * нулевая.
     *
     * @return array{
     *     ttlHours: int,
     *     hosts: list<array{
     *         host: string|null, workers: int,
     *         alive: int, disconnected: int, unknown: int, stale: int,
     *         lastSeen: string|null,
     *         inflight: int, inflightKnown: bool,
     *         cpu: array{avg: float, max: float}|null,
     *         mem: array{avg: float, max: float}|null,
     *         load: array{avg: float, max: float}|null,
     *         images: list<string>, versions: list<string>,
     *         hasAi: bool
     *     }>
     * }
     */
    public function collectHosts(): array
    {
        $collected = $this->collect();

        // Реальные хосты — строка; легacy-бакет `host IS NULL` группируем под
        // внутренним sentinel'ом (сам null не годится ключом ассоц-массива по
        // смыслу групп — используем как есть, PHP это разрешает, но явный
        // sentinel читаемее на диффе).
        $buckets = [];
        foreach ($collected['workers'] as $row) {
            $key             = $row['host'] ?? self::NULL_HOST_BUCKET_KEY;
            $buckets[$key][] = $row;
        }

        $hosts = [];
        foreach ($buckets as $key => $rows) {
            $hosts[] = $this->aggregateHost($key === self::NULL_HOST_BUCKET_KEY ? null : $key, $rows);
        }

        // По имени хоста asc, null-бакет (легacy-строки без host) — последним.
        usort($hosts, static function (array $a, array $b): int {
            if ($a['host'] === null) {
                return $b['host'] === null ? 0 : 1;
            }
            if ($b['host'] === null) {
                return -1;
            }

            return $a['host'] <=> $b['host'];
        });

        return ['ttlHours' => $collected['ttlHours'], 'hosts' => $hosts];
    }

    /**
     * @param list<array<string, mixed>> $rows строки {@see toRow()} для ОДНОГО хоста
     * @return array{
     *     host: string|null, workers: int,
     *     alive: int, disconnected: int, unknown: int, stale: int,
     *     lastSeen: string|null,
     *     inflight: int, inflightKnown: bool,
     *     cpu: array{avg: float, max: float}|null,
     *     mem: array{avg: float, max: float}|null,
     *     load: array{avg: float, max: float}|null,
     *     images: list<string>, versions: list<string>,
     *     hasAi: bool
     * }
     */
    private function aggregateHost(?string $host, array $rows): array
    {
        $alive        = 0;
        $disconnected = 0;
        $unknown      = 0;
        $stale        = 0;
        $lastSeen     = null;
        // inflightKnown=false ⇔ НИ ОДИН воркер хоста не отдал inflight — тогда
        // sum=0 было бы неотличимо от "все реально idle"; UI должен показать
        // "—", а не лживый 0 (контракт CNV-61, ttlHours-сосед по форме ответа).
        $inflightSum   = 0;
        $inflightKnown = false;
        $cpuValues     = [];
        $memValues     = [];
        $loadValues    = [];
        $images        = [];
        $versions      = [];
        $hasAi         = false;

        foreach ($rows as $row) {
            match ($row['status']) {
                'alive'        => $alive++,
                'disconnected' => $disconnected++,
                default        => $unknown++,
            };
            if ($row['stale']) {
                ++$stale;
            }

            $rowLastSeen = new \DateTimeImmutable($row['lastSeen']);
            if ($lastSeen === null || $rowLastSeen > $lastSeen) {
                $lastSeen = $rowLastSeen;
            }

            if ($row['inflight'] !== null) {
                $inflightKnown = true;
                $inflightSum += $row['inflight'];
            }

            $metrics = $row['metrics'];
            if ($metrics !== null) {
                if ($metrics['cpu'] !== null) {
                    $cpuValues[] = $metrics['cpu'];
                }
                if ($metrics['mem'] !== null) {
                    $memValues[] = $metrics['mem'];
                }
                if ($metrics['load'] !== null) {
                    $loadValues[] = $metrics['load'];
                }
            }

            if (is_string($row['image']) && $row['image'] !== '') {
                $images[] = $row['image'];
            }
            if (is_string($row['version']) && $row['version'] !== '') {
                $versions[] = $row['version'];
            }
            if ($row['isAi']) {
                $hasAi = true;
            }
        }

        $images = array_values(array_unique($images));
        sort($images);
        $versions = array_values(array_unique($versions));
        sort($versions);

        return [
            'host'          => $host,
            'workers'       => count($rows),
            'alive'         => $alive,
            'disconnected'  => $disconnected,
            'unknown'       => $unknown,
            'stale'         => $stale,
            'lastSeen'      => $lastSeen?->format(\DateTimeInterface::ATOM),
            'inflight'      => $inflightSum,
            'inflightKnown' => $inflightKnown,
            'cpu'           => $this->avgMax($cpuValues),
            'mem'           => $this->avgMax($memValues),
            'load'          => $this->avgMax($loadValues),
            'images'        => $images,
            'versions'      => $versions,
            'hasAi'         => $hasAi,
        ];
    }

    /**
     * cpu/mem/load здесь — та же доля 0..1, что и в `toRow()['metrics']`
     * (см. `workers/common/ws_client.py::_load_snapshot`) — PHP-слой НЕ
     * пересчитывает в проценты, это осознанно (CNV-61 review): единица
     * должна совпадать с per-worker строкой, иначе один и тот же хост
     * читался бы по-разному в двух местах UI. Percent-вид строит Twig
     * (`fmtHostStat()`/`fmtMetric()` в `workers.html.twig`) на этой же
     * доле — здесь же округляем до 4 знаков (а не до 1, как выглядело бы
     * естественно для процента), иначе 1-знаковое округление ДОЛИ съедало
     * бы почти всю значащую точность до того, как Twig умножит на 100.
     *
     * @param list<float> $values
     * @return array{avg: float, max: float}|null null, когда ни один воркер хоста не отдал эту метрику
     */
    private function avgMax(array $values): ?array
    {
        if ($values === []) {
            return null;
        }

        return [
            'avg' => round(array_sum($values) / count($values), 4),
            'max' => round(max($values), 4),
        ];
    }

    /**
     * @return array{
     *     workerType: string, instanceId: string,
     *     image: string|null, version: string|null, lastSeen: string,
     *     provenance: array{appVersion: string|null, build: string|null, revision: string|null, sourceState: string|null, imageRepository: string|null},
     *     status: string, stale: bool, pairCount: int,
     *     matrix: array<string, list<string>>,
     *     isAi: bool, streams: list<string>, routingKeys: list<string>,
     *     executionKind: string|null, settings: array<string, mixed>|null,
     *     matrix_categories: array<string, string>,
     *     metrics: array{cpu: float|null, mem: float|null, load: float|null}|null,
     *     host: string|null,
     *     inflight: int|null
     * }
     */
    private function toRow(WorkerCapability $cap, \DateTimeImmutable $staleThreshold): array
    {
        $blob = $cap->getCapabilities();

        // `inflight` (CNV-61) живёт ВНУТРИ того же JSON-блоба `metrics`
        // (см. {@see WorkerCapabilityRepository::updateLiveness()}), но наружу
        // отдаётся ОТДЕЛЬНЫМ top-level полем — `metrics` для потребителей
        // остаётся ровно {cpu, mem, load} | null, как было до CNV-61.
        //
        // Пуш только `inflight` (без cpu/mem/load — реальный кейс,
        // `liveness.py::_Instance.to_payload()` крепит `metrics` независимо
        // от `inflight`) пишет блоб `{cpu:null,mem:null,load:null,inflight:N}`
        // — из null ПО ВСЕМ трём ключам нельзя заключить "метрик не было",
        // поэтому здесь та же проверка, что раньше давал сам факт null-блоба.
        $rawMetrics = $cap->getMetrics();
        $inflight   = $rawMetrics['inflight'] ?? null;
        $cpu        = $rawMetrics['cpu']      ?? null;
        $mem        = $rawMetrics['mem']      ?? null;
        $load       = $rawMetrics['load']     ?? null;
        $metrics    = $cpu === null && $mem === null && $load === null
            ? null
            : ['cpu' => $cpu, 'mem' => $mem, 'load' => $load];

        /** @var array<string, list<string>> $matrix */
        $matrix = $blob['matrix'] ?? [];

        $pairCount = 0;
        foreach ($matrix as $targets) {
            $pairCount += count($targets);
        }

        /** @var list<string> $streams */
        $streams = is_array($blob['streams'] ?? null) ? array_values($blob['streams']) : [];
        /** @var list<string> $routingKeys */
        $routingKeys = is_array($blob['routingKeys'] ?? null) ? array_values($blob['routingKeys']) : [];
        /** @var array<string, string> $matrixCategories */
        $matrixCategories = is_array($blob['matrix_categories'] ?? null) ? $blob['matrix_categories'] : [];
        $executionKind    = isset($blob['executionKind']) && is_string($blob['executionKind'])
            ? $blob['executionKind']
            : null;
        /** @var array<string, mixed>|null $settings */
        $settings      = is_array($blob['settings'] ?? null) ? $blob['settings'] : null;
        $rawProvenance = is_array($blob['provenance'] ?? null) ? $blob['provenance'] : [];
        $provenance    = [
            'appVersion'      => isset($rawProvenance['appVersion'])      && is_string($rawProvenance['appVersion']) ? $rawProvenance['appVersion'] : null,
            'build'           => isset($rawProvenance['build'])           && is_string($rawProvenance['build']) ? $rawProvenance['build'] : null,
            'revision'        => isset($rawProvenance['revision'])        && is_string($rawProvenance['revision']) ? $rawProvenance['revision'] : null,
            'sourceState'     => isset($rawProvenance['sourceState'])     && is_string($rawProvenance['sourceState']) ? $rawProvenance['sourceState'] : null,
            'imageRepository' => isset($rawProvenance['imageRepository']) && is_string($rawProvenance['imageRepository']) ? $rawProvenance['imageRepository'] : null,
        ];

        return [
            'workerType'        => $cap->getWorkerType(),
            'instanceId'        => $cap->getInstanceId(),
            'image'             => isset($blob['image'])   && is_string($blob['image']) ? $blob['image'] : null,
            'version'           => isset($blob['version']) && is_string($blob['version']) ? $blob['version'] : null,
            'provenance'        => $provenance,
            'lastSeen'          => $cap->getLastSeen()->format(\DateTimeInterface::ATOM),
            'status'            => $cap->getStatus()->value,
            'stale'             => $cap->getLastSeen() < $staleThreshold,
            'pairCount'         => $pairCount,
            'matrix'            => $matrix,
            'isAi'              => (bool) ($blob['isAi'] ?? false),
            'streams'           => $streams,
            'routingKeys'       => $routingKeys,
            'matrix_categories' => $matrixCategories,
            'executionKind'     => $executionKind,
            'settings'          => $settings,
            'metrics'           => $metrics,
            'host'              => $cap->getHost(),
            'inflight'          => $inflight,
        ];
    }
}
