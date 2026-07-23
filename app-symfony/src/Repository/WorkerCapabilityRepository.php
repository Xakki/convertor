<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkerCapability;
use App\Enum\WorkerLivenessStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerCapability>
 */
class WorkerCapabilityRepository extends ServiceEntityRepository
{
    /**
     * Литерал ЗАДУБЛИРОВАН намеренно — тот же паттерн, что
     * {@see \App\Service\Worker\WorkerCapabilityGcService::SEED_INSTANCE_ID},
     * `WorkerController::RESERVED_SEED_INSTANCE_ID` и
     * `migrations/Version20260722150301.php::SEED_INSTANCE_ID`. При
     * переименовании синхронизировать все места вручную.
     */
    private const string SEED_INSTANCE_ID = '__seed__';

    public function __construct(ManagerRegistry $registry)
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
     * влияет на маршрутизацию: {@see \App\Service\Conversion\ConversionRegistry}
     * читает только `capabilities`, это поле никогда не трогает.
     *
     * `metrics` (cpu/mem/load, Phase 1 "дешёвые победы") персистится тем же
     * batched CASE — NULL для записей без `metrics` в батче (не трогаем
     * ранее сохранённое значение стиранием на пустой объект: CASE ELSE
     * оставляет текущее значение колонки для строк ВНЕ этого батча, а сама
     * запись батча пишет ровно то, что пришло — null включительно, т.к.
     * отсутствие метрик в текущем пинге тоже честный факт, не повод хранить
     * протухшие данные с прошлого пинга).
     *
     * @param list<array{workerType: string, instanceId: string, status: WorkerLivenessStatus, lastSeenAt: \DateTimeImmutable, metrics?: array{cpu: float|null, mem: float|null, load: float|null}|null}> $instances
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

        $existing = $conn->fetchAllAssociative(
            'SELECT worker_type, instance_id FROM worker_capabilities WHERE ' . implode(' OR ', $selectWhere),
            $selectParams,
        );

        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$row['worker_type'] . "\0" . $row['instance_id']] = true;
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
                $metrics                 = $instance['metrics'] ?? null;
                $updateParams["ume{$i}"] = $metrics !== null ? json_encode($metrics, JSON_THROW_ON_ERROR) : null;
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
     *  - `instance_id != '__seed__'` — seed-строка не процесс, у неё НИКОГДА не
     *    бывает соединения и её `last_seen` вечно древний (дата миграции);
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
            . ' WHERE status = :alive AND instance_id != :seedInstanceId AND last_seen < :threshold';
        $params = [
            'disconnected'   => WorkerLivenessStatus::Disconnected->value,
            'alive'          => WorkerLivenessStatus::Alive->value,
            'seedInstanceId' => self::SEED_INSTANCE_ID,
            'threshold'      => $threshold->format('Y-m-d H:i:s'),
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
}
