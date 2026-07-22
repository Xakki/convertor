<?php

declare(strict_types=1);

namespace App\Service\Quota;

use App\Entity\Plan;
use App\Entity\User;
use App\Repository\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class QuotaService
{
    /**
     * Last-resort daily limits used only when the `plans` table is unseeded
     * (the migration seeds free/basic/pro). -1 = unlimited.
     *
     * @var array<string, int>
     */
    private const FREE_FALLBACK = ['conversions' => 2, 'ai_conversions' => 1];

    /**
     * Last-resort max upload size (MB) used only when the resolved plan row is
     * missing or carries a non-positive `maxFileSizeMb` (mirrors the free tier:
     * 50 MB free / 500 MB paid, enforced from the `plans` table).
     */
    private const FREE_MAX_UPLOAD_MB = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanRepository $planRepository,
        private readonly LoggerInterface $logger,
        private readonly string $appEnv,
    ) {
    }

    /**
     * Up-front limit check: reject an over-limit request BEFORE any work is done.
     * Read-only w.r.t. the counters (no increment) — the actual charge happens in
     * {@see charge()} only after a successful submit, so a failure mid-submit can
     * never leak a charge.
     */
    public function check(User $user, bool $isAi): void
    {
        $this->resetIfNeeded($user);

        $limits = $this->limitsFor($user);
        $limit  = $isAi ? $limits['ai_conversions'] : $limits['conversions'];
        $used   = $isAi ? $user->getDailyAiConversions() : $user->getDailyConversions();

        if ($limit !== -1 && $used >= $limit) {
            throw new TooManyRequestsHttpException(
                null,
                $isAi
                    ? "Daily AI conversion limit of {$limit} reached. Upgrade your plan."
                    : "Daily conversion limit of {$limit} reached. Upgrade your plan.",
            );
        }
    }

    /**
     * Commit one daily conversion. Call only AFTER the submit succeeded
     * (S3 upload + persist + enqueue) so the charge is never left dangling.
     *
     * Инкремент — атомарным `UPDATE … WHERE id` (в обход read-then-write), чтобы
     * параллельные сабмиты одного юзера не теряли друг друга. Вызов идёт из
     * HTTP-пути сабмита (без redelivery), поэтому отдельная транзакция не нужна.
     */
    public function charge(User $user, bool $isAi): void
    {
        $this->applyDelta($user, $isAi, +1);
    }

    /**
     * Refund one daily conversion (e.g. the worker reported a failure). Атомарный
     * decrement с клемпом на 0 (raw UPDATE, не read-then-write) — параллельные
     * возвраты не теряются, а клемп делает refund no-op после суточного сброса
     * счётчика (окно уже прокрутилось).
     *
     * ВАЖНО: сам refund НЕ оборачивает decrement в транзакцию — это делает
     * вызывающий ({@see \App\Service\Queue\ConversionResultPersister}), коммитя
     * decrement вместе с переходом Conversion в терминальный статус одной
     * транзакцией. Иначе при падении flush сообщение переставится, idempotency-
     * guard пропустит refund повторно → двойной возврат квоты.
     */
    public function refund(User $user, bool $isAi): void
    {
        $this->applyDelta($user, $isAi, -1);
    }

    /**
     * Остаток + лимиты плана в одном ответе (для UI-виджета квот: home-13).
     * `*_limit` — те же значения, что резолвит limitsFor() (-1 = безлимит) —
     * без них фронт не может показать «использовано/лимит», только remaining.
     *
     * @return array<string, int|string>
     */
    public function getRemainingQuota(User $user): array
    {
        $this->resetIfNeeded($user);

        $limits = $this->limitsFor($user);

        return [
            'conversions' => $limits['conversions'] === -1
                ? -1
                : max(0, $limits['conversions'] - $user->getDailyConversions()),
            'ai_conversions' => $limits['ai_conversions'] === -1
                ? -1
                : max(0, $limits['ai_conversions'] - $user->getDailyAiConversions()),
            'conversions_limit'    => $limits['conversions'],
            'ai_conversions_limit' => $limits['ai_conversions'],
            'plan'                 => $user->getPlan(),
        ];
    }

    public function resetIfNeeded(User $user): void
    {
        $now = new \DateTimeImmutable();

        if ($user->getQuotaResetAt()->format('Y-m-d') < $now->format('Y-m-d')) {
            $this->reset($user);
        }
    }

    /**
     * Безусловный сброс дневной квоты: счётчики → 0, окно (`quotaResetAt`) → now.
     * Единая точка обнуления счётчиков (её же зовёт resetIfNeeded при смене
     * суток) — ручной admin-сброс не дублирует логику в контроллере, а бьёт
     * ровно те же поля. $flush=false — если вызывающий владеет своим flush.
     *
     * TODO(scale-out): сброс пишет счётчики через flush (UnitOfWork), а
     * charge/refund — raw UPDATE. На одном инстансе гонки нет (запросы одного
     * юзера сериализуются). При горизонтальном масштабировании сброс на одном
     * инстансе может затереть параллельный decrement на другом — тогда перевести
     * сброс на атомарный `UPDATE … SET daily_* = 0` с guard по quota_reset_at.
     */
    public function reset(User $user, bool $flush = true): void
    {
        $user->setDailyConversions(0);
        $user->setDailyAiConversions(0);
        $user->setQuotaResetAt(new \DateTimeImmutable());

        if ($flush) {
            $this->em->flush();
        }
    }

    /**
     * Per-plan max upload size in bytes, read from the `plans` table
     * (`maxFileSizeMb`). Falls back to the `free` plan, then to
     * {@see FREE_MAX_UPLOAD_MB} when the row is missing or non-positive.
     */
    public function maxUploadBytes(User $user): int
    {
        $plan = $this->resolvePlan($user);

        $mb = $plan?->getMaxFileSizeMb() ?? 0;
        if ($mb <= 0) {
            $mb = self::FREE_MAX_UPLOAD_MB;
        }

        return $mb * 1024 * 1024;
    }

    /**
     * Single source of truth for daily limits: the `plans` table. Falls back to
     * the `free` plan, then to a hardcoded free baseline if the table is unseeded.
     *
     * @return array<string, int>
     */
    private function limitsFor(User $user): array
    {
        $plan = $this->resolvePlan($user);

        if ($plan === null) {
            return self::FREE_FALLBACK;
        }

        return [
            'conversions'    => $plan->getDailyLimit(),
            'ai_conversions' => $plan->getDailyAiLimit(),
        ];
    }

    /**
     * Атомарно применяет дельту (+1 charge / -1 refund) к дневному счётчику юзера
     * прямым `UPDATE … WHERE id` в обход UnitOfWork. Затем `refresh()`
     * синхронизирует in-memory User с БД: без этого последующий flush в том же
     * запросе перезапишет счётчик устаревшим снимком, а check()/getRemainingQuota()
     * видели бы неконсистентное значение. refresh() ещё и сбрасывает snapshot —
     * так исчезает race «прочитал-записал».
     */
    private function applyDelta(User $user, bool $isAi, int $delta): void
    {
        // Имя колонки — из белого списка (не из ввода), поэтому интерполяция в SQL безопасна.
        $col = $isAi ? 'daily_ai_conversions' : 'daily_conversions';
        $sql = $delta < 0
            ? "UPDATE users SET {$col} = GREATEST(0, {$col} - 1) WHERE id = :id"
            : "UPDATE users SET {$col} = {$col} + 1 WHERE id = :id";

        $this->em->getConnection()->executeStatement($sql, ['id' => $user->getId()]);
        $this->em->refresh($user);
    }

    /**
     * Резолвит план юзера из `plans`, сигналя о «тихом даунгрейде»: платному
     * юзеру молча выдавался free/FREE_FALLBACK без единой ошибки. Даунгрейд =
     * строки запрошенного плана нет (независимо от того, есть ли free), в т.ч.
     * пустая таблица (нет и free → вызывающий возьмёт FREE_FALLBACK).
     *
     * На даунгрейде — ВСЕГДА warning (вкл. prod), а вне prod ещё и throw:
     * misconfig обязан падать в CI/dev, но не ломать платящих в prod (fail-open).
     */
    private function resolvePlan(User $user): ?Plan
    {
        $requested = $user->getPlan();

        $plan = $this->planRepository->findByName($requested);
        if ($plan !== null) {
            return $plan;
        }

        // Строки запрошенного плана нет → тихий даунгрейд. Пытаемся выдать free;
        // если и его нет (пустая таблица) — вызывающий уйдёт в FREE_FALLBACK.
        $free       = $this->planRepository->findByName('free');
        $fallbackTo = $free !== null ? 'free' : 'FREE_FALLBACK';

        $this->logger->warning('Quota silent plan downgrade: requested plan row missing', [
            'requestedPlan' => $requested,
            'fallbackTo'    => $fallbackTo,
        ]);

        if ($this->appEnv !== 'prod') {
            throw new \RuntimeException(sprintf(
                'Quota silent downgrade: plan "%s" not found, served %s. Seed the `plans` table.',
                $requested,
                $fallbackTo,
            ));
        }

        return $free;
    }
}
