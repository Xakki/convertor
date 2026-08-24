### Применение media preset в FFmpeg-worker

**Superseded by CNV-101.** Решение пользователя от 2026-08-24 в рамках EPIC-004: эпик
переключён на новое поколение worker-карточек (CNV-98/101/104), нумерационно согласованное
с backend-карточками (97→98, 100→101, 103→104). Все правила этой карточки перенесены в
CNV-101. Не реализовывать по этой карточке.

**Criticality:** Medium

**TAGS:**
- feature
- audio
- video
- ffmpeg-worker

**Description:**
Применить нормализованные audio/video presets в FFmpeg-worker без передачи пользовательских codec или raw FFmpeg arguments.

**Problem:**
Без отдельной worker-логики разрешённые presets не влияют на FFmpeg command, а произвольные args создают небезопасные и непереносимые задания.

**Impact:**
Пользователь не сможет получить выбранное качество/размер, а worker может выполнить неподдерживаемую комбинацию.

**Recommendation:**
Сопоставить audio `low|medium|high` с внутренним bitrate preset, video 480p/720p/1080p и 24/30 FPS — с командой, где codec выбирает worker. Принимать только нормализованные job options; не вводить duration limit.

**Acceptance Criteria:**
- FFmpeg-worker применяет только audio bitrate preset и video resolution/FPS из whitelist.
- Codec и raw FFmpeg arguments не поступают из job options.
- Для audio-only target из video source применяется только audio preset; video controls игнорируются как недопустимые уже на backend.
- Worker-тесты проверяют построенную команду и фактические media properties для каждого preset класса.
- `pytest`, `make test` и `make build` зелёные для изменённого worker scope.

**Decisions:**
- Backend profile и plan validation реализует CNV-100; эта карточка зависит от CNV-100 и CNV-85.
- UI реализует CNV-102 после profile; общая frontend grammar принадлежит CNV-92.
- Free ограничен 720p/30 FPS, paid — 1080p/30 FPS; лимит длительности вне scope до отдельной server-side inspection реализации.
