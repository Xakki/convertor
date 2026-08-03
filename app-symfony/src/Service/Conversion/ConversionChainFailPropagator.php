<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\Entity\Conversion;
use App\Enum\BillingMode;
use App\Enum\ConversionStatus;
use App\Repository\ConversionRepository;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CNV-5: mark remaining Pending hops Failed (pointer message) and refund hops
 * that already completed successfully. Shared by createChain abort and
 * {@see \App\EventListener\ConversionChainListener} worker-fail path.
 *
 * The failed hop itself is refunded by the caller / ConversionResultPersister —
 * do not refund it again here.
 */
final class ConversionChainFailPropagator
{
    public function __construct(
        private readonly ConversionRepository $conversionRepository,
        private readonly EntityManagerInterface $em,
        private readonly QuotaService $quotaService,
    ) {
    }

    public function failPropagateFrom(Conversion $failed): void
    {
        $chainId = $failed->getChainId();
        if ($chainId === null) {
            return;
        }

        $hops = $this->conversionRepository->findByChainIdOrdered($chainId);
        $ptr  = sprintf(
            'Chain failed at hop %d (%s→%s)',
            $failed->getSequence() ?? 0,
            $failed->getFromFormat(),
            $failed->getToFormat(),
        );

        /** @var list<array{category: \App\Enum\FileCategory, isAi: bool, billingMode: BillingMode, conversionId?: int|null}> $toRefund */
        $toRefund = [];

        foreach ($hops as $hop) {
            if ($hop->getId() === $failed->getId()) {
                continue;
            }

            if ($hop->getStatus() === ConversionStatus::Pending) {
                $hop->setStatus(ConversionStatus::Failed);
                $hop->setErrorMessage($ptr);

                continue;
            }

            if ($hop->getStatus() === ConversionStatus::Completed) {
                $toRefund[] = [
                    'category'     => $hop->getCategory(),
                    'isAi'         => $hop->isAi(),
                    'billingMode'  => $hop->getEffectiveBillingMode(),
                    'conversionId' => $hop->getId(),
                ];
            }
        }

        $this->em->flush();

        if ($toRefund !== []) {
            $this->quotaService->refundHops($failed->getUser(), $toRefund);
        }
    }
}
