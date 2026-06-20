### Validate & complete conversion workers (docs phases 2–3)

**Criticality:** High

**TAGS:**
- feature

**Description:**
From `docs/plan.md` + `docs/progress.md`: extended (phase 2) and AI (phase 3) conversions are scaffolded but not validated end-to-end. Phase-1 init is handled separately in [[fix-configs-working-state]]; this card starts at phase 2.

**Problem / scope:**
Phase 2 (extended, "in progress" per docs):
- FFmpeg worker — real audio/video conversions, codec deps in Docker.
  - **3gp input support:** add `"3gp"` to `SUPPORTED` in `workers/ffmpeg/worker.py` (вход 3gp → mp4 уже маппится на `libx264`). Покрыть **integration-тестом 3gp→mp4** (реальный ffmpeg, маркер `integration`). Фикстура готова: `workers/tests/example_files/video.3gp` укорочена до 41KB (h263+aac, 3s). Перенесено сюда из [[worker-conversion-tests]] (там unit-only), решение от 2026-06-20.
- Image worker (Pillow/ImageMagick) — format conversions + versions.
- Data worker — CSV/JSON/XML/YAML with real datasets.
- Tesseract OCR — image→text.
- Pandoc markup worker — md/rst/latex/html/wiki (incl. possible MarkItDown integration).

Phase 3 (AI, not started):
- faster-whisper STT model setup + test.
- Coqui/local TTS setup + test.
- AI conversion quota tracking in QuotaService.
- **worker-ai egress blocker (found in review):** `ai.Dockerfile` does NOT pre-bake the Whisper model — it downloads from HuggingFace on first run, but `worker-ai` sits on the `backend` network which is `internal: true` (no egress) → download fails silently (healthcheck only does `import faster_whisper`). Fix: pre-bake the model into the image, or grant worker-ai controlled egress.

Note: depends on [[fix-queue-php-worker-mismatch]] — until the queue contract is fixed, no worker (incl. these) actually receives jobs.

**Impact:**
Core product breadth (the format matrix in plan.md) is unverified; advertised conversions may not work.

**Recommendation:**
Validate each worker against the plan.md format matrix; wire missing engines; cover with the suite from [[worker-conversion-tests]].

**Acceptance Criteria:**
- Each phase-2 worker performs a real conversion for its advertised formats.
- AI STT + TTS produce valid output locally (or documented external-provider path).
- AI quota tracked separately.
- Covered by tests ([[worker-conversion-tests]]).

**Open questions:**
- Confirm the authoritative format matrix (plan.md) — any formats to drop/defer for MVP?
- AI: bundle local models (whisper/TTS) vs rely on external APIs (OpenAI/Gemini/Claude keys)? Resource budget?
- Adopt Microsoft MarkItDown for doc→markdown, or stick with pandoc?
- Split this into per-worker cards when moving to todo, or keep as one epic?

**Decisions:**
- (to be filled during grooming)
