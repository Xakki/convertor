<?php

declare(strict_types=1);

namespace App\Service\Queue;

use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Enum\ConversionStatus;
use App\Event\ConversionCompleted;
use App\Event\ConversionFailed;
use App\Repository\ConversionRepository;
use App\Service\Quota\QuotaService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Persists a worker result event (contract §5) to MariaDB: creates the output
 * FileStorage (S3 key) and finalizes Conversion.status. DB writes stay in PHP
 * (design decision 2).
 *
 * The EM is obtained from ManagerRegistry on every persist() call so that after
 * a flush() failure (which closes the EM) and a ManagerRegistry::resetManager()
 * the next call automatically picks up the fresh EM.
 *
 * CNV-5: after the first successful terminal flush, dispatches
 * {@see ConversionCompleted} / {@see ConversionFailed} for chain orchestration.
 * On idempotent Completed redelivery, re-fires ConversionCompleted only when a
 * next Pending hop still exists (advance recovery) — never re-fires Failed
 * propagation blindly.
 *
 * CNV-71-03: `state: 'expired'` (from `POST /internal/worker/expire`, the
 * gateway's "accepted but never claimed" timeout detector) is a third terminal
 * write alongside 'completed'/'failed' — same refund + ConversionFailed
 * dispatch as 'failed', target status {@see ConversionStatus::Expired}
 * instead. `Expired` is included in the terminal-status idempotency guard
 * below, same as `Completed`/`Failed`.
 *
 * CONCURRENCY (CNV-71-03 review fix): `/result`, `/fail`/`dlq-fail` and
 * `/expire` are three independent gateway-driven callers that can race for the
 * SAME conversionId (e.g. a slow worker finally reports a result the instant
 * after the gateway's expiry-sweep already relayed a timeout for the same
 * job). Reading the row unlocked and only THEN checking the terminal-status
 * guard is a classic TOCTOU: both callers can observe a non-terminal status
 * before either commits, both pass the guard, and both write — a double quota
 * decrement or double prepaid refund ({@see QuotaService::refund()} /
 * {@see \App\Service\Billing\BalanceService::applyCreditLike()} carry no
 * unique constraint that would catch this at the DB level; the PlanQuota path
 * is a raw counter UPDATE with no ledger row at all). What actually closes
 * this now: {@see persist()} takes a `SELECT … FOR UPDATE` lock on the
 * `Conversion` row (`EntityManagerInterface::find()` with
 * {@see LockMode::PESSIMISTIC_WRITE}, same primitive as
 * {@see ConversionRepository::findOneByIdForUpdate()}) and evaluates the
 * terminal-status guard AFTER acquiring it, inside a single transaction that
 * also contains the refund/status write. A second concurrent caller blocks on
 * the lock until the first commits, then re-reads the row's now-terminal
 * status under the SAME lock and no-ops. This relies on Doctrine ORM 3's
 * documented behaviour that a locked `find()` on an ALREADY-managed entity
 * (e.g. the controller's own unlocked `find()` for its 404 check, see
 * `InternalWorkerController::expire()`/`dlqFail()`) still issues `FOR UPDATE`
 * and re-hydrates every scalar field from the fresh row
 * (`UnitOfWork::createEntity()` + `Query::HINT_REFRESH`) — a stale in-memory
 * copy from an earlier unlocked read can never fool the guard. Side effect to
 * keep in mind: that re-hydration overwrites ANY uncommitted in-memory change
 * made to the entity before persist() is called — today no caller does that
 * (`WorkerController::result()`/`InternalWorkerController` only `find()` for
 * existence checks), but a future caller that mutates-then-persists would
 * silently lose the mutation.
 */
final class ConversionResultPersister
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $resultsBucket,
        private readonly LoggerInterface $logger,
        private readonly QuotaService $quotaService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConversionRepository $conversionRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $body decoded result-event JSON
     */
    public function persist(array $body): void
    {
        $em = $this->registry->getManager();
        assert($em instanceof EntityManagerInterface);

        $conversionId = isset($body['conversionId']) ? (int) $body['conversionId'] : 0;
        if ($conversionId <= 0) {
            throw new \RuntimeException('Result event missing conversionId');
        }

        // Lock + guard + write all happen INSIDE this one transaction (CNV-71-03
        // review fix, see class docblock "CONCURRENCY") — the terminal-status
        // guard below is only race-safe because it runs after the row is
        // FOR-UPDATE-locked, not before. dispatch() is deliberately kept OUTSIDE
        // this closure (post-commit): ConversionFailed → ConversionChainFailPropagator
        // touches sibling Conversion rows in the same chain, and firing it while
        // still holding this row's lock would let two concurrent chain hops each
        // hold one row and wait on the other's lock (self-deadlock across the
        // chain, not just this row).
        /** @var ConversionCompleted|ConversionFailed|null $eventToDispatch */
        $eventToDispatch = $em->wrapInTransaction(
            fn (): ConversionCompleted|ConversionFailed|null => $this->persistLocked($em, $conversionId, $body),
        );

        if ($eventToDispatch !== null) {
            $this->eventDispatcher->dispatch($eventToDispatch);
        }
    }

    /**
     * The locked read-guard-write body of {@see persist()}. Must run inside an
     * open transaction (enforced by Doctrine for {@see LockMode::PESSIMISTIC_WRITE}).
     * Returns the event {@see persist()} should dispatch AFTER the transaction
     * commits, or null when nothing should be dispatched.
     *
     * @param array<string, mixed> $body decoded result-event JSON
     */
    private function persistLocked(EntityManagerInterface $em, int $conversionId, array $body): ConversionCompleted|ConversionFailed|null
    {
        // Locked read: `SELECT … FOR UPDATE` on this row. Correct even when the
        // caller already did an UNLOCKED find() earlier for its own existence
        // check (InternalWorkerController::expire()/dlqFail() do this before
        // calling persist()) — Doctrine ORM 3's find() with a LockMode on an
        // entity ALREADY in the identity map still issues FOR UPDATE and
        // re-hydrates every scalar field from the fresh row (UnitOfWork::createEntity()
        // + Query::HINT_REFRESH), so a stale in-memory copy cannot fool the
        // guard below. A second concurrent caller for the SAME conversionId
        // blocks here until the first one commits.
        $conversion = $em->find(Conversion::class, $conversionId, LockMode::PESSIMISTIC_WRITE);
        if ($conversion === null) {
            $this->logger->warning('Result event for unknown conversion', ['id' => $conversionId]);

            return null;
        }
        assert($conversion instanceof Conversion);

        // Stale-attempt guard (requeue-attempt-generation-marker): a result/fail
        // event carrying an `attempt` older than the row's CURRENT attempt targets
        // an attempt that an operator requeue already superseded — e.g. a delayed
        // duplicate dlq-fail for the previous, now-replaced generation. Must run
        // BEFORE the terminal-status guard below: after requeue the row is back to
        // Pending (non-terminal), so without this check the guard would fall
        // through and a stale dlq-fail would refund/fail the FRESH attempt (double
        // refund, or clobbering a result that already completed). `attempt` absent
        // or null (legacy DLQ entries drained before this field existed, and the
        // jobId-keyed result()/fail() path which never sends it) → no-op here,
        // behave exactly as before.
        $attempt = array_key_exists('attempt', $body) && $body['attempt'] !== null
            ? (int) $body['attempt']
            : null;
        if ($attempt !== null && $attempt < $conversion->getAttempt()) {
            $this->logger->info('Result event for superseded attempt ignored', [
                'id'             => $conversionId,
                'eventAttempt'   => $attempt,
                'currentAttempt' => $conversion->getAttempt(),
            ]);

            return null;
        }

        // Idempotency: skip DB write if already finalized. Evaluated under the
        // FOR-UPDATE lock acquired above — that (not the idempotency check by
        // itself) is what makes two concurrent terminal callers for the same
        // conversionId safe: the second one only reaches this line after the
        // first has committed its status change, so it observes the CURRENT
        // (already-terminal) row and no-ops instead of racing the write.
        // Completed + chain with a still-Pending next hop → re-arm
        // ConversionCompleted so the listener can resume advance (crash between
        // flush and dispatch / lost event). Failed/Expired are never re-fired
        // (propagation already durable in DB). Expired IS terminal (CNV-71-03):
        // a late real result/fail for an already-expired row must no-op here
        // (guard trips on the row's CURRENT status, regardless of the incoming
        // event's state) — a slow worker result cannot resurrect/overwrite an
        // expiry, and conversely an expire call arriving after a genuine
        // Completed/Failed is equally a no-op.
        if (in_array($conversion->getStatus(), [ConversionStatus::Completed, ConversionStatus::Failed, ConversionStatus::Expired], true)) {
            if (
                $conversion->getStatus() === ConversionStatus::Completed
                && $this->shouldRearmChainAdvance($conversion)
            ) {
                return new ConversionCompleted($conversion);
            }

            return null;
        }

        $processingMs = isset($body['processingMs']) ? (int) $body['processingMs'] : null;
        $state        = isset($body['state']) ? (string) $body['state'] : '';

        // 'failed' (worker/gateway reported failure) и 'expired' (CNV-71-03:
        // gateway detected the accepted job was never claimed within
        // WORKER_CLAIM_TIMEOUT_MINUTES, POST /internal/worker/expire) share the
        // exact same terminal-write shape — status + errorMessage + refund +
        // chain fail-propagation via the SAME ConversionFailed event (a
        // Pending sibling hop must fail-propagate on an expiry exactly as it
        // does on a worker failure; a Completed sibling must be refunded the
        // same way too) — only the target status and default message differ.
        if ($state === 'failed' || $state === 'expired') {
            $conversion->setStatus($state === 'expired' ? ConversionStatus::Expired : ConversionStatus::Failed);
            $conversion->setErrorMessage(isset($body['error']) ? (string) $body['error'] : (
                $state === 'expired'
                    ? 'Ни один воркер не забрал задачу на обработку в течение отведённого времени ожидания'
                    : 'Conversion failed'
            ));
            $conversion->setProcessingMs($processingMs);
            // Возврат квоты, списанной при сабмите. Коммитится в ТОЙ ЖЕ транзакции,
            // что и переход Conversion в терминальный статус (persist()'s outer
            // wrapInTransaction, started BEFORE the FOR-UPDATE read above) — так
            // decrement и terminal-статус либо оба закоммитятся, либо оба
            // откатятся. Двойной refund закрыт не этим (atomicity внутри одного
            // вызова была бы недостаточна сама по себе), а FOR-UPDATE-локом
            // выше: конкурентный второй вызов ждёт этот коммит и затем видит
            // уже-терминальный статус.
            $this->quotaService->refund(
                $conversion->getUser(),
                $conversion->getCategory(),
                $conversion->isAi(),
                $conversion->getEffectiveBillingMode(),
                $conversion->getId(),
            );
            $em->flush();

            return new ConversionFailed($conversion);
        }

        $outputKey = isset($body['outputKey']) ? (string) $body['outputKey'] : '';
        if ($outputKey === '') {
            throw new \RuntimeException("Result event for {$conversionId} has no outputKey");
        }

        $eventBucket = isset($body['outputBucket']) ? (string) $body['outputBucket'] : '';
        if ($eventBucket !== '' && $eventBucket !== $this->resultsBucket) {
            $this->logger->warning('Result bucket differs from configured results bucket', [
                'id'         => $conversionId,
                'event'      => $eventBucket,
                'configured' => $this->resultsBucket,
            ]);
        }

        $outputFile = new FileStorage();
        // storagePath holds the S3 object key for results (bucket is config-derived).
        $outputFile->setStoragePath($outputKey);
        // Friendly download name: source base name + target extension (e.g. photo.png).
        $sourceName = pathinfo($conversion->getInputFile()->getOriginalName(), PATHINFO_FILENAME);
        $outputFile->setOriginalName(($sourceName !== '' ? $sourceName : (string) $conversionId) . '.' . $conversion->getToFormat());
        $outputFile->setMimeType(isset($body['outputMime']) ? (string) $body['outputMime'] : 'application/octet-stream');
        $outputFile->setSizeBytes(isset($body['outputSize']) ? (int) $body['outputSize'] : 0);
        $outputFile->setExpiresAt(new \DateTimeImmutable('+24 hours'));
        $em->persist($outputFile);

        $conversion->setOutputFile($outputFile);
        $conversion->setStatus(ConversionStatus::Completed);
        $conversion->setProcessingMs($processingMs);
        $em->flush();

        return new ConversionCompleted($conversion);
    }

    /**
     * True when a Completed hop still has a Pending successor — safe to re-emit
     * ConversionCompleted without looping forever (listener only advances while
     * next stays Pending; after dispatch/fail next leaves Pending).
     */
    private function shouldRearmChainAdvance(Conversion $conversion): bool
    {
        $chainId  = $conversion->getChainId();
        $sequence = $conversion->getSequence();
        if ($chainId === null || $sequence === null) {
            return false;
        }

        return $this->conversionRepository->findNextPendingHop($chainId, $sequence) !== null;
    }
}
