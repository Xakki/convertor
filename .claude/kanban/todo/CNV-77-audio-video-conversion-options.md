### Настройки конвертации аудио и видео

**Criticality:** Medium

**TAGS:**
- feature
- audio
- video
- conversion-options
- grooming

**Description:**
Добавить безопасные preset-параметры для FFmpeg-конвертаций без передачи raw
аргументов или выбора codec пользователем.

**Problem:**
FFmpeg допускает множество взаимоисключающих комбинаций. Выставление raw-аргументов
или неограниченных полей из UI небезопасно и приведёт к непереносимым результатам.

**Impact:**
Пользователь не может выбрать качество/размер media-результата; поспешный UI может
создавать неподдерживаемые задания и повышать потребление ресурсов.

**Recommendation:**
Использовать whitelist: audio low/medium/high bitrate; video 480p/720p/1080p
и 24/30 FPS. Codec выбирает worker. Free ограничить 720p/30 FPS, paid —
1080p/30 FPS; входной размер и длительность ограничивать существующими
тарифными лимитами.

**Acceptance Criteria:**
- API/UI/worker используют только audio low/medium/high bitrate и video
  480p/720p/1080p, 24/30 FPS; raw FFmpeg args и codec choice отсутствуют.
- Валидация применяет лимиты: free до 720p/30 FPS, paid до 1080p/30 FPS,
  а также существующие лимиты размера и длительности.
- Audio-only target formats из video source показывают только audio presets.
- Невалидные либо недоступные по плану варианты получают предсказуемую ошибку.
- Тесты/QA green: pytest; make test; make build.

**Decisions:**
- 2026-08-15: параметры audio/video не входят в CNV-74 и требуют отдельного
  whitelist вместо передачи произвольных FFmpeg-аргументов.
- 2026-08-14: выбраны только presets; codec определяет worker. Free — до
  720p/30 FPS, paid — до 1080p/30 FPS; audio использует low/medium/high bitrate.
