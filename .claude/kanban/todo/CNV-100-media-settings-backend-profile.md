### Media settings: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- audio
- video
- backend
- conversion-options

**Description:**
Определить media profiles и серверную validation безопасных FFmpeg presets.

**Problem:**
Без pair-specific profile API может принять raw arguments, codec selection или video values для audio-only target.

**Impact:**
Пользователь создаст неподдерживаемое или чрезмерно ресурсоёмкое media job.

**Recommendation:**
После CNV-85 публиковать audio `low|medium|high`; video 480p/720p/1080p и 24/30 FPS. Применять plan limits: free до 720p/30 FPS, paid до 1080p/30 FPS; codec выбирает worker. Не вводить duration field или limit.

**Acceptance Criteria:**
- Catalog назначает audio profile audio-only pairs и video profile только video-capable pairs.
- Backend отклоняет raw FFmpeg args, codec key, unknown presets, недоступный plan и video fields для audio-only target.
- Normalized job содержит только разрешённые preset keys и effective values.
- API tests покрывают free/paid boundaries, pair compatibility и invalid values.

**Decisions:**
- Зависит от CNV-85; CNV-77/CNV-101 и CNV-102 начинаются после profile.
- Входной размер ограничивается существующими тарифными лимитами.
- Duration inspection/limit — отдельный будущий server-side scope.
