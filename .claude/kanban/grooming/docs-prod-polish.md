### Production polish (docs phase 6)

**Criticality:** Medium

**TAGS:**
- feature
- tech-debt

**Description:**
From `docs/plan.md` phase 6 (not started): production-readiness items.

**Problem / scope:**
- S3/MinIO storage: replace local `/shared-files/` volume with S3-compatible object storage.
- Rate limiting: KeyDB-backed, per-IP and per-user.
- File cleanup cron: 24h auto-delete of uploads/results (Symfony Scheduler).
- Metrics & alerting: monitoring, worker health checks, error alerting.
- SMS verification: SMSC.ru / Vonage OTP as backup auth.

**Impact:**
Not production-safe: unbounded storage, no abuse protection, stale files accumulate, no observability.

**Recommendation:**
Tackle as independent hardening tasks; storage + rate limiting are the highest-value (mark High), others follow.

**Acceptance Criteria:**
- Files stored in MinIO/S3 in prod; local path remains dev default.
- Rate limits enforced per IP and per user.
- 24h cleanup job verified.
- Worker health + error metrics exported and alertable.
- SMS OTP flow works as backup auth.

**Open questions:**
- MinIO target: project-local instance or shared infra MinIO (`apis3.variantgood.com`)?
- Rate-limit thresholds for free vs paid tiers?
- Reuse the cross-project monitoring stack (Grafana/Prometheus) or app-local metrics?
- SMS provider: SMSC.ru (per CLAUDE.md) confirmed?
- Split into per-item cards when moving to todo (storage / rate-limit / cleanup / metrics / SMS)?

**Decisions:**
- (to be filled during grooming)
