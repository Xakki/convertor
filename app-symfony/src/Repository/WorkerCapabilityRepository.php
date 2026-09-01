<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkerCapability;
use App\Enum\WorkerLivenessStatus;
use App\Service\Worker\WorkerLivenessTtl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerCapability>
 */
class WorkerCapabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly int $silenceSeconds = 120)
    {
        parent::__construct($registry, WorkerCapability::class);
    }

    /**
     * Обновляет или создаёт запись для данной пары (workerType, instanceId).
     * Повторная регистрация того же (workerType, instanceId) обновляет данные,
     * не дублирует ряд; разные instanceId одного workerType сосуществуют как
     * отдельные ряды.
     *
     * Реализовано нативным `INSERT ... ON DUPLICATE KEY UPDATE` (одним SQL-запросом,
     * без find-then-update) — снимает TOCTOU-гонку конкурентного register одного
     * ключа (Phase 1 carry-over): два одновременных запроса больше не могут упасть
     * на UNIQUE-конфликте между find и flush.
     *
     * registry-06: КАЖДЫЙ register() безусловно сбрасывает `status` в `alive`
     * (и на INSERT, и на UPDATE) — реконнект воркера это ipso facto живое
     * соединение, даже если до этого он был помечен `disconnected` предыдущим
     * liveness-пушем. Без этого сброса заново подключившийся воркер навсегда
     * читался бы как disconnected в будущей admin-странице, пока не придёт
     * следующий liveness-пуш.
     *
     * `$host` (registry-08) — явный host/node-идентификатор воркера из
     * register-payload'а (`data['host']`, см. {@see \App\Controller\Api\WorkerController}).
     * Отдельный столбец, НЕ только часть JSON-блоба `capabilities` — по требованию
     * задачи (в отличие от `image`/`version`, которые `WorkerStatsProvider` читает
     * прямо из блоба). `null` — воркер его не прислал (старый билд) — существующие
     * строки без host не ломаются (колонка nullable).
     *
     * @param array<string, mixed> $capabilities
     */
    public function upsert(string $workerType, string $instanceId, array $capabilities, ?string $host = null): WorkerCapability
    {
        $em   = $this->getEntityManager();
        $conn = $em->getConnection();
        $now  = new \DateTimeImmutable();

        $conn->executeStatement(
            <<<'SQL'
                INSERT INTO worker_capabilities (worker_type, instance_id, capabilities, last_seen, status, host)
                VALUES (:workerType, :instanceId, :capabilities, :lastSeen, :status, :host)
                ON DUPLICATE KEY UPDATE
                    capabilities = VALUES(capabilities),
                    last_seen = VALUES(last_seen),
                    status = VALUES(status),
                    host = VALUES(host)
                SQL,
            [
                'workerType'   => $workerType,
                'instanceId'   => $instanceId,
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
                'lastSeen'     => $now->format('Y-m-d H:i:s'),
                'status'       => WorkerLivenessStatus::Alive->value,
                'host'         => $host,
            ],
        );

        $id = $conn->fetchOne(
            'SELECT id FROM worker_capabilities WHERE worker_type = :workerType AND instance_id = :instanceId',
            ['workerType' => $workerType, 'instanceId' => $instanceId],
        );
        if ($id === false) {
            throw new \RuntimeException('worker_capabilities row missing immediately after upsert');
        }

        $cap = $em->find(WorkerCapability::class, (int) $id);
        if ($cap === null) {
            throw new \RuntimeException('worker_capabilities row missing immediately after upsert');
        }
        // Строка могла уже быть в identity map (напр. загружена ранее в этом же
        // запросе) — ORM не перезатирает поля управляемой сущности данными из
        // повторного SELECT. refresh() гарантирует, что вернутся свежие данные,
        // только что записанные нативным SQL мимо UnitOfWork.
        $em->refresh($cap);

        return $cap;
    }

    /**
     * Возвращает все зарегистрированные типы воркеров.
     *
     * @return WorkerCapability[]
     */
    public function findAllCapabilities(): array
    {
        return $this->findAll();
    }

    /**
     * Durable admission for normal queues: any registered row keeps the worker
     * type admissible until long-TTL GC removes it. Short liveness state must
     * not turn transient disconnects into global submit failures.
     */
    public function existsForWorkerType(string $workerType): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchOne(
            'SELECT 1 FROM worker_capabilities WHERE worker_type = :workerType LIMIT 1',
            ['workerType' => $workerType],
        ) !== false;
    }

    /**
     * Fresh alive rows for live-only capability publication and admission.
     *
     * @return list<WorkerCapability>
     */
    public function findLiveForWorkerType(string $workerType): array
    {
        return $this->createQueryBuilder('capability')
            ->andWhere('capability.workerType = :workerType')
            ->andWhere('capability.status = :status')
            ->andWhere('capability.lastSeen >= :threshold')
            ->setParameter('workerType', $workerType)
            ->setParameter('status', WorkerLivenessStatus::Alive)
            ->setParameter('threshold', WorkerLivenessTtl::silenceThreshold($this->silenceSeconds))
            ->orderBy('capability.instanceId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * registry-06: liveness-пуш от WS-Gateway обновляет `last_seen` по
     * составному ключу (workerType, instanceId) — UPDATE ONLY, никогда
     * INSERT (в отличие от {@see upsert()}). Молчаливая вставка здесь
     * позволила бы liveness-пингу сфабриковать воркера без объявленной
     * матрицы — запрещено картой registry-06.
     *
     * Реализация — 2 запроса независимо от размера батча, НЕ полагается на
     * affected-rows семантику UPDATE (MySQL/MariaDB по умолчанию считают
     * ИЗМЕНЁННЫЕ, не СОВПАВШИЕ строки — идемпотентный повторный пуш с тем же
     * `lastSeenAt` дал бы affected=0 и ложно попал бы в `unknown`, вынуждая
     * gateway на пустом месте форсировать re-register):
     *   1. SELECT существующих (workerType, instanceId) из запрошенного набора.
     *   2. Один batched UPDATE (CASE per ключ) — только по найденным строкам.
     * `unknown` = запрошенные ключи МИНУС найденные (по SELECT, не по UPDATE).
     *
     * Обновляет `status` наравне с `last_seen` (тот же составной CASE) — НЕ
     * влияет на маршрутизацию: с CNV-71-02
     * {@see \App\Service\Conversion\ConversionRegistry} вообще не читает эту
     * таблицу для роутинга (та строится из статического каталога);
     * `capabilities` используется только для live-диагностики
     * ({@see \App\Service\Conversion\ConversionRegistry::getCapabilityWarnings()}).
     *
     * `metrics` (cpu/mem/load, Phase 1 "дешёвые победы") и `inflight` (CNV-61,
     * ЖИВЁТ В ТОМ ЖЕ JSON-блобе `metrics`, без миграции/нового столбца) — два
     * НЕЗАВИСИМЫХ компонента одного блоба, персистятся genuine read-modify-write
     * мержем над уже сохранённой строкой (CNV-61 review, finding #3): батч,
     * принёсший ТОЛЬКО `inflight` (без `metrics`), не стирает ранее сохранённые
     * cpu/mem/load — и наоборот, батч с ТОЛЬКО `metrics` не стирает ранее
     * сохранённый `inflight`. Раньше блоб перезаписывался ЦЕЛИКОМ из текущего
     * пинга — сегодня это латентно безопасно лишь потому, что
     * `ws_client.py::_load_snapshot()` всегда прикладывает `metrics` к каждому
     * пушу (см. `liveness.py`), но эта безопасность держалась на Python-side
     * инварианте, ничем не гарантированном на PHP-стороне.
     *
     * Компонент, отсутствующий в ТЕКУЩЕМ пуше, берётся из строки, уже
     * прочитанной тем же SELECT, что определяет `existingKeys` ниже (второй
     * столбец `metrics`) — отсюда `$existingMetricsByKey`. Если компонент
     * отсутствует И в пуше, И в ранее сохранённой строке — итоговое значение
     * остаётся null, как и было. Если оба компонента (metrics и inflight) в
     * итоге null — блоб целиком пишется NULL (тот же "null-or-{cpu,mem,load}"
     * контракт, что читает {@see \App\Service\Admin\WorkerStatsProvider::toRow()});
     * `inflight`-ключ добавляется в блоб ТОЛЬКО когда он реально известен —
     * отсутствующий `inflight` не фабрикует сохранённый `inflight: null`.
     *
     * @param list<array{workerType: string, instanceId: string, status: WorkerLivenessStatus, lastSeenAt: \DateTimeImmutable, metrics?: array{cpu: float|null, mem: float|null, load: float|null}|null, inflight?: int|null}> $instances
     * @return array{updated: int, unknown: list<array{workerType: string, instanceId: string}>}
     */
    public function updateLiveness(array $instances): array
    {
        if ($instances === []) {
            return ['updated' => 0, 'unknown' => []];
        }

        $conn = $this->getEntityManager()->getConnection();

        $selectWhere  = [];
        $selectParams = [];
        foreach ($instances as $i => $instance) {
            $selectWhere[]           = "(worker_type = :swt{$i} AND instance_id = :sid{$i})";
            $selectParams["swt{$i}"] = $instance['workerType'];
            $selectParams["sid{$i}"] = $instance['instanceId'];
        }

        // `metrics` тоже читаем здесь (не только worker_type/instance_id) —
        // read-modify-write мерж ниже нуждается в том, что УЖЕ сохранено по
        // каждому ключу батча, чтобы не потерять компонент, отсутствующий в
        // текущем пуше (finding #3).
        $existing = $conn->fetchAllAssociative(
            'SELECT worker_type, instance_id, metrics FROM worker_capabilities WHERE ' . implode(' OR ', $selectWhere),
            $selectParams,
        );

        $existingKeys         = [];
        $existingMetricsByKey = [];
        foreach ($existing as $row) {
            $key                        = $row['worker_type'] . "\0" . $row['instance_id'];
            $existingKeys[$key]         = true;
            $existingMetricsByKey[$key] = $row['metrics'] !== null
                ? json_decode((string) $row['metrics'], true, flags: JSON_THROW_ON_ERROR)
                : null;
        }

        $toUpdate = [];
        $unknown  = [];
        foreach ($instances as $instance) {
            $key = $instance['workerType'] . "\0" . $instance['instanceId'];
            if (isset($existingKeys[$key])) {
                $toUpdate[] = $instance;
            } else {
                $unknown[] = ['workerType' => $instance['workerType'], 'instanceId' => $instance['instanceId']];
            }
        }

        if ($toUpdate !== []) {
            $lastSeenCase = [];
            $statusCase   = [];
            $metricsCase  = [];
            $updateWhere  = [];
            $updateParams = [];
            foreach ($toUpdate as $i => $instance) {
                $lastSeenCase[]          = "WHEN worker_type = :uwt{$i} AND instance_id = :uid{$i} THEN :uts{$i}";
                $statusCase[]            = "WHEN worker_type = :uwt{$i} AND instance_id = :uid{$i} THEN :ust{$i}";
                $metricsCase[]           = "WHEN worker_type = :uwt{$i} AND instance_id = :uid{$i} THEN :ume{$i}";
                $updateWhere[]           = "(worker_type = :uwt{$i} AND instance_id = :uid{$i})";
                $updateParams["uwt{$i}"] = $instance['workerType'];
                $updateParams["uid{$i}"] = $instance['instanceId'];
                $updateParams["uts{$i}"] = $instance['lastSeenAt']->format('Y-m-d H:i:s');
                $updateParams["ust{$i}"] = $instance['status']->value;

                $key            = $instance['workerType'] . "\0" . $instance['instanceId'];
                $stored         = $existingMetricsByKey[$key] ?? null;
                $pushedMetrics  = $instance['metrics']        ?? null;
                $pushedInflight = $instance['inflight']       ?? null;

                // Read-modify-write мерж (finding #3): компонент, отсутствующий
                // в ЭТОМ пуше, берётся из уже сохранённого блоба, а не
                // фабрикуется null'ом — metrics-группа (cpu/mem/load) и
                // inflight мержатся НЕЗАВИСИМО друг от друга.
                $cpu      = $pushedMetrics !== null ? $pushedMetrics['cpu'] : ($stored['cpu'] ?? null);
                $mem      = $pushedMetrics !== null ? $pushedMetrics['mem'] : ($stored['mem'] ?? null);
                $load     = $pushedMetrics !== null ? $pushedMetrics['load'] : ($stored['load'] ?? null);
                $inflight = $pushedInflight ?? ($stored['inflight'] ?? null);

                $mergedMetrics = $cpu === null && $mem === null && $load === null && $inflight === null
                    ? null
                    // `inflight` key ONLY added when actually known — an
                    // omitted/never-known `inflight` must not fabricate a
                    // stored `inflight: null`, same "no fabrication" rule as
                    // cpu/mem/load already follow.
                    : ['cpu' => $cpu, 'mem' => $mem, 'load' => $load] + ($inflight !== null ? ['inflight' => $inflight] : []);
                $updateParams["ume{$i}"] = $mergedMetrics !== null ? json_encode($mergedMetrics, JSON_THROW_ON_ERROR) : null;
            }

            $conn->executeStatement(
                'UPDATE worker_capabilities SET '
                . 'last_seen = CASE ' . implode(' ', $lastSeenCase) . ' ELSE last_seen END, '
                . 'status = CASE ' . implode(' ', $statusCase) . ' ELSE status END, '
                . 'metrics = CASE ' . implode(' ', $metricsCase) . ' ELSE metrics END '
                . 'WHERE ' . implode(' OR ', $updateWhere),
                $updateParams,
            );
        }

        return ['updated' => count($toUpdate), 'unknown' => $unknown];
    }

    /**
     * registry-09 (gateway = источник истины о том, кто подключён СЕЙЧАС):
     * помечает `disconnected` строки живых воркеров, которых ни один gateway не
     * репортил живыми дольше окна тишины. Инвариант и его обоснование — в
     * {@see \App\Service\Worker\WorkerLivenessReconciler}; здесь только SQL.
     *
     * Условия (все обязательны):
     *  - `status = 'alive'` — трогаем только строки, чья ложь реально видна на
     *    admin-странице; уже disconnected/unknown переписывать нечем;
     *  - `last_seen < :threshold` — ГЛАВНОЕ условие: каждый gateway обновляет
     *    `last_seen` каждому своему инстансу КАЖДЫЙ push-цикл, поэтому пройти
     *    его может только инстанс, которого не видел НИКТО целое окно;
     *  - `(worker_type, instance_id)` НЕ в текущем снапшоте — явная страховка
     *    на случай инстанса, который подключён, но не шлёт ping'и (его
     *    `lastSeenAt` заморожен моментом connect и может протухнуть, оставаясь
     *    при этом честно живым).
     *
     * `last_seen` НЕ трогаем — это вход GC ({@see \App\Service\Worker\WorkerCapabilityGcService});
     * сдвиг сломал бы TTL-удаление и колонку «Свежесть» админки.
     *
     * @param list<array{workerType: string, instanceId: string}> $snapshotKeys живые по снапшоту
     * @return int число переведённых в `disconnected` строк
     */
    public function markSilentDisconnected(array $snapshotKeys, \DateTimeImmutable $threshold): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'UPDATE worker_capabilities SET status = :disconnected'
            . ' WHERE status = :alive AND last_seen < :threshold';
        $params = [
            'disconnected' => WorkerLivenessStatus::Disconnected->value,
            'alive'        => WorkerLivenessStatus::Alive->value,
            'threshold'    => $threshold->format('Y-m-d H:i:s'),
        ];

        $keep = [];
        foreach (array_values($snapshotKeys) as $i => $key) {
            $keep[]            = "(worker_type = :kwt{$i} AND instance_id = :kid{$i})";
            $params["kwt{$i}"] = $key['workerType'];
            $params["kid{$i}"] = $key['instanceId'];
        }
        if ($keep !== []) {
            $sql .= ' AND NOT (' . implode(' OR ', $keep) . ')';
        }

        return (int) $conn->executeStatement($sql, $params);
    }

    /**
     * CNV-61: ручное удаление ряда воркеров admin-панелью — по СТАТУСУ
     * (`disconnected` или `unknown`), а не по возрасту `last_seen`, как
     * {@see \App\Service\Worker\WorkerCapabilityGcService::run()} (эта GC
     * остаётся отдельной, независимой операцией — эндпоинт её не заменяет и
     * не меняет её расписание/поведение).
     *
     * @return int число удалённых строк
     */
    public function deleteStaleByStatus(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->executeStatement(
            'DELETE FROM worker_capabilities WHERE status IN (:disconnected, :unknown)',
            [
                'disconnected' => WorkerLivenessStatus::Disconnected->value,
                'unknown'      => WorkerLivenessStatus::Unknown->value,
            ],
        );
    }
}
