<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Repository\WorkerCapabilityRepository;
use Psr\Log\LoggerInterface;

/**
 * registry-09: WS-Gateway — ИСТОЧНИК ИСТИНЫ о том, какие воркеры подключены
 * прямо сейчас; `worker_capabilities` — лишь кеш регистрации.
 *
 * ЗАЧЕМ. До registry-09 liveness-пуш применялся ТОЛЬКО как дельта: инстанс,
 * которого gateway никогда не видел, либо пропавший без чистого disconnect
 * (краш воркера, обрыв сети, убитый контейнер), навсегда оставался в БД со
 * `status = alive`. Ничто не переводило молчащего воркера в offline между
 * прогонами часового {@see WorkerCapabilityGcService} (а тот только УДАЛЯЕТ
 * строки старше многодневного TTL) — админ-панель врала.
 *
 * ИНВАРИАНТ (единственный, на котором стоит весь сверочный проход):
 *
 *   Строка переводится в `disconnected` тогда и только тогда, когда
 *   (a) пуш объявил себя ПОЛНЫМ снапшотом живых (`snapshot: true`) И
 *       авторитетным (`authoritative: true` — gateway прогрелся);
 *   (b) её `(workerType, instanceId)` НЕТ в этом снапшоте;
 *   (c) её `lastSeen` старше окна тишины `silenceSeconds`;
 *   (d) это не seed-строка и её текущий статус — `alive`.
 *
 * ПОЧЕМУ ИМЕННО ТАК, а не «нет в снапшоте → сразу offline»: снапшот
 * авторитетен только для тех инстансов, о которых ЭТОТ gateway вообще может
 * что-то знать. Условие (c) превращает это ограничение в проверяемое свойство
 * без всякого учёта «владения» инстансом (и без колонки `gateway_id`):
 *
 *  - НЕСКОЛЬКО GATEWAY. Каждый gateway каждый push-цикл переписывает
 *    `lastSeen = now` КАЖДОМУ своему инстансу. Значит воркер, подключённый ко
 *    ВТОРОМУ gateway, физически не может провалить (c) — снапшот первого его
 *    не заденет. Свойство держится by construction, а не по договорённости.
 *  - ПЕРЕЗАПУСК GATEWAY. После рестарта его alive-set пуст, пока воркеры
 *    переподключаются с backoff. Первые циклы приходят с
 *    `authoritative: false` (окно прогрева, `LIVENESS_SNAPSHOT_WARMUP_S`) и
 *    сверку не запускают вовсе; даже если бы запустили — (c) не даст погасить
 *    строки, обновлённые меньше окна тишины назад.
 *  - ПУСТОЙ/ЧАСТИЧНЫЙ СНАПШОТ. Худшее, что делает пустой снапшот, — гасит
 *    ровно те строки, которые и так молчат дольше окна тишины. Массового
 *    погашения «потому что пуш пришёл пустым» не бывает.
 *
 * ЧЕГО ЭТОТ ПРОХОД НЕ ДЕЛАЕТ (осознанно):
 *  - НЕ трогает маршрутизацию. `status` не входит в критерии
 *    {@see \App\Service\Conversion\ConversionRegistry} — она читает только
 *    `capabilities` (это зафиксировано тестом `ConversionRegistryLivenessStatusTest`).
 *  - НЕ удаляет строки — удаление остаётся исключительно за долгим TTL-GC.
 *  - НЕ двигает `lastSeen` — он вход GC и колонки «Свежесть» админки.
 *  - ИЗВЕСТНОЕ ОГРАНИЧЕНИЕ push-модели: если gateway лежит целиком, пушей нет
 *    → сверки нет → строки остаются `alive` до GC. Лечить это Symfony-кроном
 *    значило бы вернуть модель «истина в БД», которую эпик отверг намеренно;
 *    для этого случая на admin-странице работает отдельный признак `stale`.
 */
final readonly class WorkerLivenessReconciler
{
    public function __construct(
        private WorkerCapabilityRepository $repository,
        private LoggerInterface $logger,
        private int $silenceSeconds,
    ) {
    }

    /**
     * Один сверочный проход. ВЫЗЫВАТЬ СТРОГО ПОСЛЕ применения самого батча
     * ({@see WorkerCapabilityRepository::updateLiveness()}): порядок —
     * часть инварианта. Сначала живым инстансам проставляется свежий
     * `lastSeen`, и только потом гасятся молчащие; обратный порядок погасил бы
     * строку, которую тот же батч через миллисекунду обновил бы как живую.
     *
     * @param list<array{workerType: string, instanceId: string}> $snapshotKeys
     *        все `(workerType, instanceId)` из пуша (и alive, и disconnected —
     *        для disconnected условие (d) и так не выполнится, но исключать их
     *        из «не гасить» дешевле, чем полагаться на порядок статусов)
     * @return int число строк, переведённых в `disconnected`
     */
    public function reconcile(array $snapshotKeys, ?string $gatewayId = null): int
    {
        $offlined = $this->repository->markSilentDisconnected(
            $snapshotKeys,
            WorkerLivenessTtl::silenceThreshold($this->silenceSeconds),
        );

        if ($offlined > 0) {
            $this->logger->info('worker liveness reconcile: инстансы помечены disconnected', [
                'offlined'       => $offlined,
                'snapshotSize'   => count($snapshotKeys),
                'silenceSeconds' => $this->silenceSeconds,
                'gatewayId'      => $gatewayId,
            ]);
        }

        return $offlined;
    }
}
