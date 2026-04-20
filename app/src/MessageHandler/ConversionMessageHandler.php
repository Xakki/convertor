<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\FileStorage;
use App\Enum\ConversionStatus;
use App\Message\ConversionMessage;
use App\Repository\ConversionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class ConversionMessageHandler
{
    /** Worker URLs indexed by category name */
    private array $workerUrls;

    public function __construct(
        private readonly ConversionRepository $conversionRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        string $workerLibreofficeUrl,
        string $workerFfmpegUrl,
        string $workerImageUrl,
        string $workerAiUrl,
        string $workerDataUrl,
        private readonly string $shareDir,
    ) {
        $this->workerUrls = [
            'document' => $workerLibreofficeUrl,
            'markup'   => $workerLibreofficeUrl,
            'audio'    => $workerFfmpegUrl,
            'video'    => $workerFfmpegUrl,
            'image'    => $workerImageUrl,
            'ai'       => $workerAiUrl,
            'data'     => $workerDataUrl,
            'archive'  => $workerDataUrl,
        ];
    }

    public function __invoke(ConversionMessage $message): void
    {
        $conversion = $this->conversionRepository->find($message->conversionId);

        if ($conversion === null) {
            $this->logger->error('Conversion not found', ['id' => $message->conversionId]);
            return;
        }

        $conversion->setStatus(ConversionStatus::Processing);
        $this->em->flush();

        $startMs = (int) (microtime(true) * 1000);

        try {
            $workerUrl = $this->resolveWorkerUrl($message->category);
            $outputPath = $this->callWorker($workerUrl, $message);

            $outputFile = new FileStorage();
            $outputFile->setOriginalName(
                pathinfo($message->inputPath, PATHINFO_FILENAME) . '.' . $message->outputFormat
            );
            $outputFile->setStoragePath($outputPath);
            $outputFile->setMimeType($this->guessMime($message->outputFormat));
            $outputFile->setSizeBytes(file_exists($outputPath) ? filesize($outputPath) : 0);
            $outputFile->setExpiresAt(new \DateTimeImmutable('+24 hours'));

            $this->em->persist($outputFile);

            $conversion->setOutputFile($outputFile);
            $conversion->setStatus(ConversionStatus::Completed);
            $conversion->setProcessingMs((int) (microtime(true) * 1000) - $startMs);
        } catch (\Throwable $e) {
            $this->logger->error('Conversion failed', [
                'id'    => $message->conversionId,
                'error' => $e->getMessage(),
            ]);
            $conversion->setStatus(ConversionStatus::Failed);
            $conversion->setErrorMessage($e->getMessage());
            $conversion->setProcessingMs((int) (microtime(true) * 1000) - $startMs);
        }

        $this->em->flush();
    }

    private function resolveWorkerUrl(string $category): string
    {
        return $this->workerUrls[$category]
            ?? throw new \RuntimeException("No worker configured for category: {$category}");
    }

    private function callWorker(string $workerUrl, ConversionMessage $message): string
    {
        $response = $this->httpClient->request('POST', rtrim($workerUrl, '/') . '/convert', [
            'json' => [
                'input_path'    => $message->inputPath,
                'output_format' => $message->outputFormat,
                'category'      => $message->category,
                'conversion_id' => $message->conversionId,
            ],
            'timeout' => 300,
        ]);

        $data = $response->toArray();

        if (!isset($data['output_path'])) {
            throw new \RuntimeException('Worker returned no output_path: ' . json_encode($data));
        }

        return $data['output_path'];
    }

    private function guessMime(string $format): string
    {
        return match ($format) {
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'jpg'  => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'zip'  => 'application/zip',
            default => 'application/octet-stream',
        };
    }
}
