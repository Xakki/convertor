### Production polish (docs phase 6)

**Criticality:** Medium

**TAGS:**
- feature
- tech-debt

**Description:**
From `ROADMAP.md` (Стадия 6, not started): production-readiness items.

**Problem / scope (S3 storage split out → [[storage-input-to-s3]]):**
- Rate limiting: KeyDB-backed, per-IP and per-user.
- File cleanup cron: 24h auto-delete of uploads/results (Symfony Scheduler).
- Metrics & alerting: monitoring, worker health checks, error alerting.
- SMS verification: SMSC.ru / Vonage OTP as backup auth.

**Impact:**
Not production-safe: no abuse protection, stale files accumulate, no observability.

**Recommendation:**
Tackle as independent hardening tasks; rate limiting is the highest-value remaining item.

**Acceptance Criteria:**
- Rate limits enforced per IP and per user.
- 24h cleanup job verified (deletes S3 objects + DB rows).
- Worker health + error metrics exported and alertable.
- SMS OTP flow works as backup auth.

**Decisions (2026-06-20):**
- **Storage/S3 split out** into its own card [[storage-input-to-s3]] (input → S3, drop `/shared-files`).
  Remaining items stay here.
- **24h cleanup = Symfony Scheduler cron** (PHP command deletes S3 objects + DB rows together;
  single source of logic) — NOT an S3 lifecycle policy (user, 2026-06-20). Covers input + result
  buckets `${S3_BUCKET_PREFIX}-inputs` / `-results`.
- on todo-move, SPLIT into 4 cards (rate-limit / cleanup / metrics / SMS), rate-limit first ; metrics = reuse cross-project Grafana/Prometheus ; SMS = SMSC.ru ; cleanup = Symfony Scheduler deletes S3+DB rows.

**Status:** grooming (Stage 6, not imminent).
