<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Entity\Conversion;
use App\Enum\ConversionStatus;
use App\Repository\ConversionRepository;
use App\Service\Conversion\ConversionManager;
use App\Service\Quota\QuotaService;
use App\Service\Storage\S3Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operator-recovery для конвертаций, застрявших в DLQ (карта conv-dead-no-
 * consumer). Оператор работает с БД-состоянием (Conversion.status=failed),
 * НЕ с сырым стримом `conv.dead` — тот читает только gateway. Реальная граница —
 * ROLE_ADMIN на JWT-firewall (Option B, как у остальных admin-API); не-админ 403.
 *
 * Requeue переставляет задачу через ТОТ ЖЕ producer-путь, что и первичный
 * submit ({@see ConversionManager::dispatch()}) — переиспользует уже
 * сохранённые input-метаданные Conversion (S3-ключ, форматы), НЕ грузит файл
 * заново. Поэтому исходник в S3-бакете inputs должен ещё существовать —
 * автo-очистка (FileCleanupService, по умолч. failed=24ч) могла его уже
 * вычистить; в этом случае — 409 (нужен повторный аплоад пользователем, не
 * тихий silent no-op).
 *
 * Квота ПЕРЕсписывается принудительно (requeue-attempt-generation-marker,
 * MAJOR #1 из карточки grooming/requeue-attempt-generation-marker): исходный
 * fail() уже вернул её через QuotaService::refund (ConversionResultPersister),
 * а requeue ставит НОВУЮ попытку — тот же job, что и обычный сабмит, поэтому
 * charge/refund должны остаться симметричны (submit charge → fail#1 refund →
 * requeue re-charge → fail#2 refund ИЛИ success). Без re-charge успешный
 * requeue не учитывался бы в дневном лимите — бесплатная конверсия.
 * Re-charge — ПРИНУДИТЕЛЬНЫЙ (`QuotaService::charge()`, не `check()`+`charge()`):
 * оператор не должен получать отказ из-за того, что юзер тем временем упёрся
 * в лимит — это восстановление после сбоя инфраструктуры, не новый запрос.
 */
#[Route('/api/v1/admin/dead-letter')]
#[IsGranted('ROLE_ADMIN')]
final class DlqController extends AbstractController
{
    public function __construct(
        private readonly ConversionRepository $conversions,
        private readonly ConversionManager $conversionManager,
        private readonly EntityManagerInterface $em,
        private readonly S3Storage $s3,
        private readonly QuotaService $quotaService,
    ) {
    }

    #[Route('/requeue', name: 'admin_api_dlq_requeue', methods: ['POST'])]
    public function requeue(Request $request): JsonResponse
    {
        $body         = json_decode((string) $request->getContent(), true) ?: [];
        $conversionId = isset($body['conversionId']) ? (int) $body['conversionId'] : 0;

        if ($conversionId <= 0) {
            return $this->json(['error' => '"conversionId" field is required'], Response::HTTP_BAD_REQUEST);
        }

        // S3 objectExists — ДО SELECT … FOR UPDATE (CNV-49): сетевой I/O не
        // должен удерживать InnoDB row lock. Сначала обычный find + fail-fast
        // (404 / not_failed / input_gone), затем locked-транзакция только для
        // статуса, charge и incrementAttempt.
        $conversion = $this->conversions->find($conversionId);
        if ($conversion === null) {
            return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
        }

        if ($conversion->getStatus() !== ConversionStatus::Failed) {
            return $this->json([
                'error'   => 'not_failed',
                'message' => sprintf(
                    'Conversion is in status "%s"; only "failed" conversions can be requeued',
                    $conversion->getStatus()->value,
                ),
            ], Response::HTTP_CONFLICT);
        }

        // Gate BEFORE flipping status: an enqueue the worker can never fetch
        // (input already reaped by FileCleanupService) must not zombie the row
        // into a fresh pending stuck forever — 409 tells the operator to ask
        // the user to re-upload instead.
        if (! $this->s3->objectExists($this->s3->inputsBucket(), $conversion->getInputFile()->getStoragePath())) {
            return $this->json([
                'error'   => 'input_gone',
                'message' => 'Source file was already cleaned up; the user must re-upload to retry this conversion',
            ], Response::HTTP_CONFLICT);
        }

        // Критическая секция под SELECT … FOR UPDATE (CNV-11): повторная
        // загрузка строки, проверка статуса, charge и incrementAttempt — в
        // ОДНОЙ транзакции. Параллельный второй requeue ждёт лок, затем видит
        // уже не-Failed → 409 not_failed без повторного списания и без второго
        // джоба. #[ORM\Version] на Conversion намеренно не добавляем.
        //
        // Order matters (MINOR fix, see class docblock): status → Pending,
        // attempt bumped, quota re-charged, and ALL of it flushed to the DB
        // BEFORE dispatch(). If dispatch() ran first, a fast worker could report
        // a result and race the persister against a row still sitting at
        // Failed/old-attempt — the persister would drop a legitimate result.
        //
        // charge() применяет списание raw `UPDATE` (немедленный, в обход
        // UnitOfWork) — вместе с flush статуса/attempt в одной транзакции
        // (паттерн ConversionResultPersister). Иначе списание закоммитится, а
        // падение flush оставит юзера пере-списанным при строке в Failed.
        $outcome = $this->em->wrapInTransaction(function () use ($conversionId): Conversion|JsonResponse {
            $conversion = $this->conversions->findOneByIdForUpdate($conversionId);
            if ($conversion === null) {
                return $this->json(['error' => 'Conversion not found'], Response::HTTP_NOT_FOUND);
            }

            if ($conversion->getStatus() !== ConversionStatus::Failed) {
                return $this->json([
                    'error'   => 'not_failed',
                    'message' => sprintf(
                        'Conversion is in status "%s"; only "failed" conversions can be requeued',
                        $conversion->getStatus()->value,
                    ),
                ], Response::HTTP_CONFLICT);
            }

            $conversion->setStatus(ConversionStatus::Pending);
            $conversion->setErrorMessage(null);
            $conversion->setProcessingMs(null);
            $conversion->incrementAttempt();
            $this->quotaService->charge($conversion->getUser(), $conversion->isAi());

            return $conversion;
        });

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        $conversion = $outcome;

        try {
            $this->conversionManager->dispatch($conversion);
        } catch (\Throwable) {
            // Compensating rollback: a synchronous XADD failure means no job was
            // actually enqueued — leaving the row at Pending would zombie it
            // forever (nothing will ever report a result). Roll status back to
            // Failed and refund the re-charge above so charge/refund stay
            // symmetric; the row is retryable via the same endpoint, not stuck.
            $this->em->wrapInTransaction(function () use ($conversion): void {
                $conversion->setStatus(ConversionStatus::Failed);
                $this->quotaService->refund($conversion->getUser(), $conversion->isAi());
            });

            return $this->json([
                'error'   => 'dispatch_failed',
                'message' => 'Failed to re-enqueue the job; the conversion was rolled back to "failed" and can be retried.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'ok'           => true,
            'conversionId' => $conversion->getId(),
            'attempt'      => $conversion->getAttempt(),
            'status'       => $conversion->getStatus()->value,
        ]);
    }
}
