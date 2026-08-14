### FFmpeg-worker: применение media preset

**Criticality:** High

**TAGS:**
- feature
- audio
- video
- ffmpeg-worker

**Description:**
Применить normalized media preset из CNV-100 при построении FFmpeg execution.

**Problem:**
Даже корректный profile бесполезен, если FFmpeg-worker не сопоставляет preset с безопасной внутренней командой.

**Impact:**
Output не соответствует выбранному quality/size или получает user-controlled codec/arguments.

**Recommendation:**
Выполнить worker scope CNV-77: внутренне маппировать audio bitrate и video resolution/FPS; codec фиксирован worker-ом. Принимать только normalized options и не добавлять duration enforcement.

**Acceptance Criteria:**
- Worker применяет audio low/medium/high и video 480p/720p/1080p, 24/30 FPS.
- Codec и raw FFmpeg arguments не попадают из job в команду.
- Tests проверяют command mapping и output properties для каждого preset класса.
- `pytest`, `make test` и `make build` зелёные.

**Decisions:**
- Зависит от CNV-85 и CNV-100; это исполняющая часть CNV-77.
- Frontend controls принадлежат CNV-102 после profile.
- Лимит длительности вне scope.
