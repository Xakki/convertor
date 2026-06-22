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

**Execution Log:**
- MIME validation: category-level (по `FileCategory`, не exact) — exact-match массово ложно
  отклонял бы (OOXML/ODF/epub→`application/zip`, text/data/markup→`text/plain`, CAD→`octet-stream`).
  Реализовано `match($category)` в `ConversionManager::assertMimeAllowed()`: image→`image/`;
  audio→`audio/,video/` (audio-воркер извлекает звук из видео); video→`video/`;
  document/markup/data→`application/`+`text/`; archive→`application/`; OCR-override (категория Image)
  →`image/`+`application/` (pdf). Реальный MIME — через `UploadedFile::getMimeType()` (finfo, не
  client header). Mismatch → 415 (`UnsupportedMediaTypeHttpException`).
- Size limit: `QuotaService::maxUploadBytes(User)` читает `Plan::maxFileSizeMb` (fallback
  `FREE_MAX_UPLOAD_MB`=50MB при отсутствии плана / mb<=0). Oversize → 413 (`HttpException(413)`,
  отдельного класса в Symfony 7 нет).
- Порядок в `createConversion()`: формат → archive-reject → size → MIME → quota check → S3 PUT →
  dispatch → charge. Все reject'ы — до quota/S3 side-effects.
- nginx: `client_max_body_size 512M` + PHP upload/post 512M **только** в location `\.php$`
  (prod+dev), глобальный include не трогали (не открывать body-cap всем эндпоинтам). Per-plan
  enforcement (50MB free / 500MB paid) — в PHP.
- Review: APPROVE-WITH-NITS (блокеров нет). Внесены 2 нита: прямой `expects(once())->putObject` на
  happy-path (AC#3) + переформулирован вводящий в заблуждение комментарий о порядке size/mime.
- QA: phpstan [OK], cs-check чистый, PHPUnit 37/37 (137 assertions) OK.
