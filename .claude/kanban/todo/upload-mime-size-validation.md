### Enforce MIME-allowlist + max upload size on POST /convert

**Criticality:** Medium

**TAGS:**
- security
- tech-debt

**Description:**
Surfaced during [[storage-input-to-s3]] review. `ConversionManager::createConversion()` currently
validates only the **extension pair** (`ConversionRegistry::isSupported`) + **quota**
(`QuotaService`/`checkAndDecrement`). There is **no real MIME-type allowlist** and **no upload
size-limit** check in PHP — nginx provides only `limit_req` rate-limiting, not a body-size cap.
Project CLAUDE.md (`File Handling`) mandates: "валидация MIME + расширения", "Max size: 50MB free,
500MB paid". So this is an unmet requirement, not a new feature.

**Scope:**
- **MIME allowlist:** detect the real MIME of the uploaded file (Symfony `UploadedFile::getMimeType()`
  / `MimeTypes` guesser, not the client-sent header) and verify it matches the declared source format
  before the S3 PUT in `storeInput()`. Reject mismatches (e.g. `.png` whose real bytes are a PHP
  script) with 4xx. Build the allowlist from the conversion registry's supported source formats.
- **Size limit:** enforce per-plan max upload size (50MB free / 500MB paid) in PHP, reading the
  authenticated user's plan. Also set/verify nginx `client_max_body_size` consistently with the paid
  ceiling so large uploads are rejected at the edge before buffering.
- Both checks run **before** the S3 PUT and before quota decrement side-effects.

**Acceptance Criteria:**
- A file whose real MIME doesn't match its extension/declared format is rejected (4xx), not PUT to
  `${S3_BUCKET_PREFIX}-inputs`.
- An over-limit upload is rejected per the user's plan; nginx caps body size at the paid ceiling.
- Unit tests cover: MIME mismatch rejected, oversize rejected (free + paid thresholds), valid upload
  still passes through to S3.

**Related:** [[storage-input-to-s3]] (input→S3 move that exposed this gap).
