### Write conversion tests for workers (+ fixtures in tests/example_files)

**Criticality:** High

**TAGS:**
- feature
- tech-debt

**Description:**
Worker conversion logic is largely untested. Only `DataWorker` has partial conversion tests; `Image/Ffmpeg/Ai` workers have **zero**; `LibreOffice` has integration-only tests (needs running service). Fixtures in `workers/tests/example_files/` are sparse and partly orphaned.

**Problem:**
Current coverage:
- `test_base_worker.py` — plumbing only (callback/ack/loop via EchoWorker), no real conversions.
- `test_data_worker.py` — ~20% of CSV/JSON/XML/YAML matrix, no error/edge cases.
- Image/Ffmpeg/Ai workers — untested.
- LibreOffice — `unittest` integration only, requires live service.

Fixture gaps in `tests/example_files/`:
- Have: `image.jpg`, several doc/docx/pdf, plus **orphans** `29216306410573.dwg` (no worker) and `video.3gp` (3gp unsupported by ffmpeg worker).
- Missing: audio (mp3/wav/ogg/flac), valid video (mp4/avi/mkv), data (csv/json/xml/yaml), extra image formats, OCR test image, malformed/empty files.

**Impact:**
Conversion regressions ship silently; can't trust the core product (file conversion).

**Recommendation:**
Add pytest config (`conftest.py`/`pytest.ini`, asyncio mode, `integration`/`slow` markers). Layer tests:
- **Tier 1 (cheap, mock/validation):** unsupported input/output format, missing file, path-traversal (`safe_share_path`), empty input, output-not-created — across Image/Ffmpeg/Ai/Data.
- **Tier 2 (real conversions):** Image jpg→png/pdf/webp (Pillow), Data full matrix + roundtrip + malformed JSON/XML/CSV, OCR (mock pytesseract).
- **Tier 3 (integration, marked):** Ffmpeg audio/video (needs binaries+fixtures), LibreOffice HTTP endpoints + path-traversal + size limit.
Add the missing fixtures (small clips/files) and remove or document the orphans.

**Acceptance Criteria:**
- `pytest workers/tests -m "not integration"` green and runnable without external binaries.
- Each worker has happy-path + error-case coverage; safe_path traversal tested.
- Required fixtures present (small, committed); orphan fixtures resolved.
- Tests/QA green per project CLAUDE.md (`pytest` for workers).

**Open questions:**
- Generate audio/video fixtures synthetically (ffmpeg `testsrc`/`sine`, kept tiny) or commit small real samples? Repo-size limit?
- Mock heavy engines (whisper/TTS/ffmpeg/soffice) for unit tests vs run real in a marked integration suite — where to draw the line?
- Should LibreOffice tests be refactored from `unittest` to pytest, or kept separate?
- Target a coverage threshold (e.g. enforce in CI), or just add cases now?
- Remove orphan `.dwg`/`.3gp` fixtures, or add worker support to match them?

**Decisions:**
- (to be filled during grooming)
