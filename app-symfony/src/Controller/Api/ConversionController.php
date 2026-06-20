<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Conversion\ConversionRegistry;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1')]
class ConversionController extends AbstractController
{
    public function __construct(
        private readonly ConversionManager $conversionManager,
        private readonly ConversionRegistry $registry,
        private readonly ConversionRepository $conversionRepository,
        private readonly QuotaService $quotaService,
        private readonly S3Storage $s3,
    ) {
    }

    #[Route('/convert', methods: ['POST'])]
    public function convert(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $file     = $request->files->get('file');
        $toFormat = $request->request->get('to_format');
        $ocr      = $request->request->getBoolean('ocr');

        if ($file === null) {
            return $this->json(['error' => 'File required'], Response::HTTP_BAD_REQUEST);
        }

        if (! $toFormat) {
            return $this->json(['error' => 'to_format required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $conversion = $this->conversionManager->createConversion($user, $file, strtolower((string) $toFormat), $ocr);
            $this->conversionManager->dispatch($conversion);

            return $this->json([
                'conversion_id' => $conversion->getId(),
                'status'        => $conversion->getStatus()->value,
            ], Response::HTTP_ACCEPTED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (TooManyRequestsHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        }
    }

    #[Route('/convert/{id}/status', methods: ['GET'])]
    public function status(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->conversionManager->getStatus($id, $user);

            return $this->json([
                'conversion_id' => $result->conversionId,
                'status'        => $result->status->value,
                'error'         => $result->errorMessage,
            ]);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/convert/{id}/download', methods: ['GET'])]
    public function download(int $id, #[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $conversion = $this->conversionRepository->find($id);

        if ($conversion === null || $conversion->getUser()->getId() !== $user->getId()) {
            throw new NotFoundHttpException('Conversion not found');
        }

        $outputFile = $conversion->getOutputFile();
        if ($outputFile === null) {
            return $this->json(['error' => 'Output file not available'], Response::HTTP_NOT_FOUND);
        }

        // storagePath holds the S3 object key for results; bucket is config-derived.
        return $this->s3->downloadResponse(
            $outputFile->getStoragePath(),
            $outputFile->getOriginalName(),
            $outputFile->getMimeType(),
        );
    }

    #[Route('/convert/history', methods: ['GET'])]
    public function history(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $limit  = min((int) $request->query->get('limit', 20), 100);
        $offset = (int) $request->query->get('offset', 0);

        $conversions = $this->conversionRepository->findByUser($user, $limit, $offset);

        return $this->json([
            'items' => array_map(
                static fn ($c) => [
                    'id'          => $c->getId(),
                    'from_format' => $c->getFromFormat(),
                    'to_format'   => $c->getToFormat(),
                    'status'      => $c->getStatus()->value,
                    'is_ai'       => $c->isAi(),
                    'created_at'  => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $conversions,
            ),
        ]);
    }

    #[Route('/formats', methods: ['GET'])]
    public function formats(): JsonResponse
    {
        return $this->json([
            'formats' => $this->registry->getSupportedFormats(),
        ]);
    }

    #[Route('/quota', methods: ['GET'])]
    public function quota(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->quotaService->getRemainingQuota($user));
    }
}
