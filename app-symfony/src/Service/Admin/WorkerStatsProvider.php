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
 *
 * Seed-строки (`instanceId='__seed__'`, `[[registry-03-seed-migration]]`)
 * помечены `isSeed=true`: `stale` для них считается тем же способом (честные
 * сырые данные — их `lastSeen` действительно старый, это дата seed-миграции),
 * но UI ОБЯЗАН не показывать их как "мёртвый воркер" — они не воркер, а
 * статичный снимок матрицы, который никогда не устаревает по смыслу и не
 * получает liveness-пуш. Решение о представлении — на фронтенде
 * (`templates/admin/workers.html.twig`), не здесь: сервис отдаёт честные
 * данные + флаг, разметка решает, как их показать.
 */
final readonly class WorkerStatsProvider
{
    private const string SEED_INSTANCE_ID = '__seed__';

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
     *         isSeed: bool,
     *         image: string|null,
     *         version: string|null,
     *         lastSeen: string,
     *         status: string,
     *         stale: bool,
     *         pairCount: int,
     *         matrix: array<string, list<string>>,
     *         isAi: bool,
     *         streams: list<string>,
     *         routingKeys: list<string>,
     *         matrix_categories: array<string, string>,
     *         metrics: array{cpu: float|null, mem: float|null, load: float|null}|null,
     *         host: string|null
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

        // workerType asc, seed-строка первой в своей группе (естественная
        // "база, затем живые инстансы" читаемость), затем instanceId asc.
        usort($rows, static fn (array $a, array $b): int
            => $a['workerType'] <=> $b['workerType']
            ?: $b['isSeed']     <=> $a['isSeed']
            ?: $a['instanceId'] <=> $b['instanceId']);

        return ['ttlHours' => $this->ttlHours, 'workers' => $rows];
    }

    /**
     * @return array{
     *     workerType: string, instanceId: string, isSeed: bool,
     *     image: string|null, version: string|null, lastSeen: string,
     *     status: string, stale: bool, pairCount: int,
     *     matrix: array<string, list<string>>,
     *     isAi: bool, streams: list<string>, routingKeys: list<string>,
     *     matrix_categories: array<string, string>,
     *     metrics: array{cpu: float|null, mem: float|null, load: float|null}|null,
     *     host: string|null
     * }
     */
    private function toRow(WorkerCapability $cap, \DateTimeImmutable $staleThreshold): array
    {
        $blob = $cap->getCapabilities();

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

        return [
            'workerType'        => $cap->getWorkerType(),
            'instanceId'        => $cap->getInstanceId(),
            'isSeed'            => $cap->getInstanceId() === self::SEED_INSTANCE_ID,
            'image'             => isset($blob['image'])   && is_string($blob['image']) ? $blob['image'] : null,
            'version'           => isset($blob['version']) && is_string($blob['version']) ? $blob['version'] : null,
            'lastSeen'          => $cap->getLastSeen()->format(\DateTimeInterface::ATOM),
            'status'            => $cap->getStatus()->value,
            'stale'             => $cap->getLastSeen() < $staleThreshold,
            'pairCount'         => $pairCount,
            'matrix'            => $matrix,
            'isAi'              => (bool) ($blob['isAi'] ?? false),
            'streams'           => $streams,
            'routingKeys'       => $routingKeys,
            'matrix_categories' => $matrixCategories,
            'metrics'           => $cap->getMetrics(),
            'host'              => $cap->getHost(),
        ];
    }
}
