<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\DTO\ConversionResultDTO;
use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Enum\FileCategory;
use App\Message\ConversionMessage;
use App\Repository\ConversionRepository;
use App\Service\Queue\ConversionStatusReader;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

class ConversionManager
{
    public function __construct(
        private readonly ConversionRegistry $registry,
        private readonly ConversionRepository $conversionRepository,
        private readonly QuotaService $quotaService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly ConversionStatusReader $statusReader,
        private readonly S3Storage $s3,
    ) {
    }

    public function createConversion(User $user, UploadedFile $file, string $toFormat, bool $ocr = false): Conversion
    {
        $fromFormat = strtolower($file->getClientOriginalExtension());

        // Explicit OCR intent: validate against the OCR capability set up front
        // (cheap 400 before quota/S3), then force an image-worker, non-AI, free
        // job. The worker decides OCR by the text targetFormat.
        if ($ocr) {
            if (! $this->registry->isOcrSupported($fromFormat, $toFormat)) {
                throw new \InvalidArgumentException("Unsupported OCR conversion: {$fromFormat} → {$toFormat}");
            }
            $category = FileCategory::Image;
            $isAi     = false;
        } else {
            if (! $this->registry->isSupported($fromFormat, $toFormat)) {
                throw new \InvalidArgumentException("Unsupported conversion: {$fromFormat} → {$toFormat}");
            }
            $category = $this->registry->getCategory($fromFormat, $toFormat);
            $isAi     = $this->registry->isAi($fromFormat, $toFormat);
        }

        // No `conv_archive` transport exists yet (Stage 7). Reject up front with a
        // clear 422 so dispatch() never routes to a missing stream (would 500).
        if ($category === FileCategory::Archive) {
            throw new UnprocessableEntityHttpException('Archive conversions are not supported yet.');
        }

        $this->quotaService->check($user, $isAi);

        // Read metadata BEFORE the upload is consumed — keep size/mime from the
        // live tmp upload.
        $originalName = $file->getClientOriginalName() ?: 'upload';
        $mimeType     = $file->getMimeType() ?? 'application/octet-stream';
        $sizeBytes    = $file->getSize();

        $storagePath = $this->storeInput($file, $fromFormat, $mimeType);

        $inputFile = new FileStorage();
        $inputFile->setOriginalName($originalName);
        $inputFile->setStoragePath($storagePath);
        $inputFile->setMimeType($mimeType);
        $inputFile->setSizeBytes($sizeBytes);
        $inputFile->setExpiresAt(new \DateTimeImmutable('+48 hours'));

        $this->em->persist($inputFile);

        $conversion = new Conversion();
        $conversion->setUser($user);
        $conversion->setInputFile($inputFile);
        $conversion->setFromFormat($fromFormat);
        $conversion->setToFormat($toFormat);
        $conversion->setCategory($category);
        $conversion->setIsAi($isAi);
        $conversion->setIsOcr($ocr);

        $this->em->persist($conversion);
        $this->em->flush();

        // Enqueue + charge LAST. check() above already rejected over-limit requests;
        // the quota increment happens only after a successful submit, so a failure
        // in S3 upload / persist / dispatch can never leave a charge without a
        // worker job (closes the submit-path quota leak). The worker-failure refund
        // (ConversionResultPersister) is the complementary, mutually-exclusive path:
        // it only ever fires for a job that was successfully enqueued here, so the
        // two can never double-count.
        $this->dispatch($conversion);
        $this->quotaService->charge($user, $isAi);

        return $conversion;
    }

    public function dispatch(Conversion $conversion): void
    {
        $sourceFormat = $conversion->getFromFormat();
        $key          = $this->routingKey($conversion);

        $this->bus->dispatch(
            new ConversionMessage(
                conversionId: $conversion->getId(),
                inputBucket: $this->s3->inputsBucket(),
                inputKey: $conversion->getInputFile()->getStoragePath(),
                originalFilename: $conversion->getInputFile()->getOriginalName(),
                sourceFormat: $sourceFormat,
                targetFormat: $conversion->getToFormat(),
                category: $conversion->getCategory()->value,
                isAi: $conversion->isAi(),
                subType: $this->resolveSubType($sourceFormat),
                options: [],
            ),
            [new TransportNamesStamp(['conv_' . $key])],
        );
    }

    /**
     * Routing key = stream suffix. Delegates to the pure
     * {@see ConversionRegistry::streamFor()}; OCR jobs are forced to the image
     * stream via the persisted {@see Conversion::isOcr()} flag.
     */
    private function routingKey(Conversion $conversion): string
    {
        return $this->registry->streamFor(
            $conversion->getFromFormat(),
            $conversion->getToFormat(),
            $conversion->isOcr(),
        );
    }

    /**
     * Derive the AI sub-type (ocr/stt/tts) from the registry's virtual source
     * key suffix (e.g. "mp3_stt"). Returns null for plain formats. Real AI
     * sub-type population is the deferred AI-routing gap, not Phase 0.
     */
    private function resolveSubType(string $sourceFormat): ?string
    {
        $pos = strrpos($sourceFormat, '_');
        if ($pos === false) {
            return null;
        }

        $suffix = substr($sourceFormat, $pos + 1);

        return in_array($suffix, ['ocr', 'stt', 'tts'], true) ? $suffix : null;
    }

    public function getStatus(int $id, User $user): ConversionResultDTO
    {
        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('Conversion not found');
        }

        // Live status from Redis hash `conv:status:{id}` (TTL 24h). Falls back to
        // the MariaDB row once the hash has expired. Contract §4.
        $live = $this->statusReader->read($id);
        if ($live !== null) {
            $state = isset($live['state']) ? ConversionStatus::tryFrom($live['state']) : null;

            return new ConversionResultDTO(
                conversionId: $conversion->getId(),
                status: $state                 ?? $conversion->getStatus(),
                outputPath: $live['outputUrl'] ?? $live['outputKey'] ?? $conversion->getOutputFile()?->getStoragePath(),
                errorMessage: ($live['error'] ?? '') !== '' ? $live['error'] : $conversion->getErrorMessage(),
            );
        }

        return new ConversionResultDTO(
            conversionId: $conversion->getId(),
            status: $conversion->getStatus(),
            outputPath: $conversion->getOutputFile()?->getStoragePath(),
            errorMessage: $conversion->getErrorMessage(),
        );
    }

    /**
     * Upload the validated input to the S3 inputs bucket and return the object
     * key. Key layout mirrors the results layout: `inputs/{Y}/{m}/{d}/{uuid}.{ext}`
     * with a random, path-traversal-safe basename (never the user filename).
     * `$ext` is the already-validated source extension; `$mimeType` is stored as
     * the object Content-Type. Nothing is written to /shared-files.
     */
    private function storeInput(UploadedFile $file, string $ext, string $mimeType): string
    {
        $key = 'inputs/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open uploaded file for reading');
        }

        try {
            $this->s3->putObject($this->s3->inputsBucket(), $key, $stream, $mimeType);
        } finally {
            fclose($stream);
        }

        return $key;
    }
}
