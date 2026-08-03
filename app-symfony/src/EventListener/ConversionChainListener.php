<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Conversion;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Event\ConversionCompleted;
use App\Event\ConversionFailed;
use App\Exception\InsufficientBalanceException;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionChainFailPropagator;
use App\Service\Conversion\ConversionManager;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * CNV-5 Phase 1: advance next Pending hop on Completed; fail-propagate + refund
 * Completed siblings on Failed. Idempotent: only dispatches while next hop is
 * still Pending; skips quota charge when intermediate input was already wired.
 */
final class ConversionChainListener
{
    /** Placeholder S3 key prefix for undispatched chain hops (set at submit). */
    public const PENDING_INPUT_PREFIX = 'inputs/.chain-pending/';

    public function __construct(
        private readonly ConversionRepository $conversionRepository,
        private readonly ConversionManager $conversionManager,
        private readonly QuotaService $quotaService,
        private readonly S3Storage $s3,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ConversionChainFailPropagator $failPropagator,
    ) {
    }

    #[AsEventListener]
    public function onConversionCompleted(ConversionCompleted $event): void
    {
        $done    = $event->conversion;
        $chainId = $done->getChainId();
        if ($chainId === null || $done->getStatus() !== ConversionStatus::Completed) {
            return;
        }

        $sequence = $done->getSequence();
        if ($sequence === null) {
            return;
        }

        $next = $this->conversionRepository->findNextPendingHop($chainId, $sequence);
        if ($next === null) {
            return;
        }

        // Re-check after load — survive races / redelivery without double-dispatch.
        if ($next->getStatus() !== ConversionStatus::Pending) {
            return;
        }

        // null = advance aborted inside wire (next already Failed + fail-propagated)
        $justWired = $this->wireIntermediateInput($done, $next);
        if ($justWired === null) {
            return;
        }

        $user        = $next->getUser();
        $category    = $next->getCategory();
        $isAi        = $next->isAi();
        $billingMode = $next->getEffectiveBillingMode();

        if ($justWired && $billingMode === BillingMode::PrepaidBalance) {
            try {
                $this->quotaService->chargeHop($user, $category, $isAi, $billingMode, $next->getId());
            } catch (InsufficientBalanceException $e) {
                $next->setStatus(ConversionStatus::Failed);
                $next->setErrorMessage('insufficient_balance');
                $this->em->flush();
                $this->failPropagator->failPropagateFrom($next);
                $this->logger->error('Chain advance: insufficient balance for next hop', [
                    'conversionId' => $next->getId(),
                    'chainId'      => $chainId,
                    'error'        => $e->getMessage(),
                ]);

                return;
            }
        }

        try {
            $this->conversionManager->dispatch($next);
        } catch (\Throwable $e) {
            if ($justWired && $billingMode === BillingMode::PrepaidBalance) {
                $this->em->wrapInTransaction(function () use ($next, $user, $category, $isAi, $billingMode): void {
                    $next->setStatus(ConversionStatus::Failed);
                    $this->quotaService->refund(
                        $user,
                        $category,
                        $isAi,
                        $billingMode,
                        $next->getId(),
                    );
                });
                $this->failPropagator->failPropagateFrom($next);
            } else {
                $next->setStatus(ConversionStatus::Failed);
                $next->setErrorMessage('Chain advance dispatch failed');
                $this->em->flush();
                $this->failPropagator->failPropagateFrom($next);
            }
            $this->logger->error('Chain advance: dispatch failed', [
                'conversionId' => $next->getId(),
                'chainId'      => $chainId,
                'error'        => $e->getMessage(),
            ]);

            return;
        }

        if ($justWired && $billingMode === BillingMode::PlanQuota) {
            $this->quotaService->chargeHop($user, $category, $isAi, $billingMode, $next->getId());
        }
    }

    #[AsEventListener]
    public function onConversionFailed(ConversionFailed $event): void
    {
        $failed = $event->conversion;
        if ($failed->getChainId() === null || $failed->getStatus() !== ConversionStatus::Failed) {
            return;
        }

        $this->failPropagator->failPropagateFrom($failed);
    }

    /**
     * Copy prior hop output results→inputs and rewrite the next hop's placeholder
     * FileStorage.
     *
     * @return bool|null true = wired now (caller should charge); false = already
     *                   wired (recovery / redelivery); null = aborted (Failed)
     */
    private function wireIntermediateInput(Conversion $done, Conversion $next): ?bool
    {
        $input = $next->getInputFile();
        $path  = $input->getStoragePath();
        if (! str_starts_with($path, self::PENDING_INPUT_PREFIX)) {
            return false;
        }

        $output = $done->getOutputFile();
        if ($output === null) {
            $this->logger->error('Chain advance: completed hop has no outputFile', [
                'conversionId' => $done->getId(),
                'chainId'      => $done->getChainId(),
            ]);
            $next->setStatus(ConversionStatus::Failed);
            $next->setErrorMessage('Chain advance failed: missing prior output');
            $this->em->flush();
            $this->failPropagator->failPropagateFrom($next);

            return null;
        }

        $ext    = $next->getFromFormat();
        $dstKey = 'inputs/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $mime   = $output->getMimeType();
        $srcKey = $output->getStoragePath();

        try {
            $this->conversionManager->assertSafeObjectKey($srcKey, 'results/');
            $this->conversionManager->assertSafeObjectKey($dstKey, 'inputs/');
        } catch (\RuntimeException $e) {
            $this->logger->error('Chain advance: invalid S3 object key', [
                'conversionId' => $done->getId(),
                'chainId'      => $done->getChainId(),
                'srcKey'       => $srcKey,
                'dstKey'       => $dstKey,
                'error'        => $e->getMessage(),
            ]);
            $next->setStatus(ConversionStatus::Failed);
            $next->setErrorMessage('Chain advance failed: invalid storage path');
            $this->em->flush();
            $this->failPropagator->failPropagateFrom($next);

            return null;
        }

        $this->s3->copyObject(
            $this->s3->resultsBucket(),
            $srcKey,
            $this->s3->inputsBucket(),
            $dstKey,
            $mime,
        );

        $sourceBase = pathinfo($done->getInputFile()->getOriginalName(), PATHINFO_FILENAME);
        $input->setStoragePath($dstKey);
        $input->setMimeType($mime);
        $input->setSizeBytes($output->getSizeBytes());
        $input->setOriginalName(($sourceBase !== '' ? $sourceBase : (string) $next->getId()) . '.' . $ext);
        $input->setExpiresAt(new \DateTimeImmutable('+48 hours'));

        $this->em->flush();

        return true;
    }
}
