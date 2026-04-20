<?php

declare(strict_types=1);

namespace App\Service\Conversion;

use App\DTO\ConversionResultDTO;
use App\Entity\Conversion;
use App\Entity\FileStorage;
use App\Entity\User;
use App\Enum\ConversionStatus;
use App\Message\ConversionMessage;
use App\Repository\ConversionRepository;
use App\Service\Quota\QuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

class ConversionManager
{
    public function __construct(
        private readonly ConversionRegistry $registry,
        private readonly ConversionRepository $conversionRepository,
        private readonly QuotaService $quotaService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly string $shareDir,
    ) {}

    public function createConversion(User $user, UploadedFile $file, string $toFormat): Conversion
    {
        $fromFormat = strtolower($file->getClientOriginalExtension());

        if (!$this->registry->isSupported($fromFormat, $toFormat)) {
            throw new \InvalidArgumentException("Unsupported conversion: {$fromFormat} → {$toFormat}");
        }

        $isAi = $this->registry->isAi($fromFormat, $toFormat);
        $this->quotaService->checkAndDecrement($user, $isAi);

        $storagePath = $this->storeUploadedFile($file);

        $inputFile = new FileStorage();
        $inputFile->setOriginalName($file->getClientOriginalName() ?? 'upload');
        $inputFile->setStoragePath($storagePath);
        $inputFile->setMimeType($file->getMimeType() ?? 'application/octet-stream');
        $inputFile->setSizeBytes($file->getSize());
        $inputFile->setExpiresAt(new \DateTimeImmutable('+48 hours'));

        $this->em->persist($inputFile);

        $conversion = new Conversion();
        $conversion->setUser($user);
        $conversion->setInputFile($inputFile);
        $conversion->setFromFormat($fromFormat);
        $conversion->setToFormat($toFormat);
        $conversion->setCategory($this->registry->getCategory($fromFormat, $toFormat));
        $conversion->setIsAi($isAi);

        $this->em->persist($conversion);
        $this->em->flush();

        return $conversion;
    }

    public function dispatch(Conversion $conversion): void
    {
        $this->bus->dispatch(new ConversionMessage(
            conversionId: $conversion->getId(),
            inputPath: $conversion->getInputFile()->getStoragePath(),
            outputFormat: $conversion->getToFormat(),
            category: $conversion->getCategory()->value,
        ));
    }

    public function getStatus(int $id, User $user): ConversionResultDTO
    {
        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('Conversion not found');
        }

        return new ConversionResultDTO(
            conversionId: $conversion->getId(),
            status: $conversion->getStatus(),
            outputPath: $conversion->getOutputFile()?->getStoragePath(),
            errorMessage: $conversion->getErrorMessage(),
        );
    }

    private function storeUploadedFile(UploadedFile $file): string
    {
        $dir = rtrim($this->shareDir, '/') . '/input/' . date('Y/m/d');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return $dir . '/' . $filename;
    }
}
