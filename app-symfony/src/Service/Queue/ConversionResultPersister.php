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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Persists a worker result event (contract §5) to MariaDB: creates the output
 * FileStorage (S3 key) and finalizes Conversion.status. DB writes stay in PHP
 * (design decision 2). Idempotent: a conversion already in a terminal state is
 * skipped for DB writes, so redelivery never double-writes.
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

        $conversion = $em->find(Conversion::class, $conversionId);
        if ($conversion === null) {
            $this->logger->warning('Result event for unknown conversion', ['id' => $conversionId]);

            return;
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

            return;
        }

        // Idempotency: skip DB write if already finalized. Completed + chain with
        // a still-Pending next hop → re-arm ConversionCompleted so the listener
        // can resume advance (crash between flush and dispatch / lost event).
        // Failed is never re-fired (propagation already durable in DB).
        if (in_array($conversion->getStatus(), [ConversionStatus::Completed, ConversionStatus::Failed], true)) {
            if (
                $conversion->getStatus() === ConversionStatus::Completed
                && $this->shouldRearmChainAdvance($conversion)
            ) {
                $this->eventDispatcher->dispatch(new ConversionCompleted($conversion));
            }

            return;
        }

        $processingMs = isset($body['processingMs']) ? (int) $body['processingMs'] : null;
        $state        = isset($body['state']) ? (string) $body['state'] : '';

        if ($state === 'failed') {
            $conversion->setStatus(ConversionStatus::Failed);
            $conversion->setErrorMessage(isset($body['error']) ? (string) $body['error'] : 'Conversion failed');
            $conversion->setProcessingMs($processingMs);
            // Возврат квоты, списанной при сабмите. refund() делает атомарный
            // decrement raw UPDATE'ом — коммитим его в ОДНОЙ транзакции с
            // переходом Conversion в терминальный статус. Иначе при падении flush
            // сообщение переставится, а idempotency-guard выше (статус ещё не
            // терминальный) пропустит refund повторно → двойной возврат квоты.
            $em->wrapInTransaction(function () use ($em, $conversion): void {
                $this->quotaService->refund(
                    $conversion->getUser(),
                    $conversion->getCategory(),
                    $conversion->isAi(),
                    $conversion->getEffectiveBillingMode(),
                    $conversion->getId(),
                );
                $em->flush();
            });

            $this->eventDispatcher->dispatch(new ConversionFailed($conversion));

            return;
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

        $this->eventDispatcher->dispatch(new ConversionCompleted($conversion));
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
