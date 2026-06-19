<?php

declare(strict_types=1);

namespace App\Service\Queue;

use App\Entity\FileStorage;
use App\Enum\ConversionStatus;
use App\Repository\ConversionRepository;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists a worker result event (contract §5) to MariaDB: creates the output
 * FileStorage (S3 key) and finalizes Conversion.status. DB writes stay in PHP
 * (design decision 2). Idempotent: a conversion already in a terminal state is
 * skipped, so redelivery never double-writes.
 */
final class ConversionResultPersister
{
    public function __construct(
        private readonly ConversionRepository $conversionRepository,
        private readonly EntityManagerInterface $em,
        private readonly S3Storage $s3,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $body decoded result-event JSON
     */
    public function persist(array $body): void
    {
        $conversionId = isset($body['conversionId']) ? (int) $body['conversionId'] : 0;
        if ($conversionId <= 0) {
            throw new \RuntimeException('Result event missing conversionId');
        }

        $conversion = $this->conversionRepository->find($conversionId);
        if ($conversion === null) {
            $this->logger->warning('Result event for unknown conversion', ['id' => $conversionId]);

            return;
        }

        // Idempotency guard: skip if already finalized.
        if (in_array($conversion->getStatus(), [ConversionStatus::Completed, ConversionStatus::Failed], true)) {
            return;
        }

        $processingMs = isset($body['processingMs']) ? (int) $body['processingMs'] : null;
        $state = isset($body['state']) ? (string) $body['state'] : '';

        if ($state === 'failed') {
            $conversion->setStatus(ConversionStatus::Failed);
            $conversion->setErrorMessage(isset($body['error']) ? (string) $body['error'] : 'Conversion failed');
            $conversion->setProcessingMs($processingMs);
            $this->em->flush();

            return;
        }

        $outputKey = isset($body['outputKey']) ? (string) $body['outputKey'] : '';
        if ($outputKey === '') {
            throw new \RuntimeException("Result event for {$conversionId} has no outputKey");
        }

        $eventBucket = isset($body['outputBucket']) ? (string) $body['outputBucket'] : '';
        if ($eventBucket !== '' && $eventBucket !== $this->s3->resultsBucket()) {
            $this->logger->warning('Result bucket differs from configured results bucket', [
                'id'         => $conversionId,
                'event'      => $eventBucket,
                'configured' => $this->s3->resultsBucket(),
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
        $this->em->persist($outputFile);

        $conversion->setOutputFile($outputFile);
        $conversion->setStatus(ConversionStatus::Completed);
        $conversion->setProcessingMs($processingMs);
        $this->em->flush();
    }
}
