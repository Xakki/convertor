### Безопасные параметры audio и video конвертаций

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Внедрить безопасные whitelisted preset-параметры audio/video-конвертаций во frontend, API и FFmpeg-worker.

**Problem:**
Пользователь не может управлять качеством media-результата, а raw FFmpeg arguments и выбор codec создают небезопасный и непереносимый контракт.

**Impact:**
Невозможно предсказуемо выбрать баланс качества и размера, а неограниченный UI может создавать неподдерживаемые и ресурсоёмкие задания.

**Recommendation:**
Реализовать только low/medium/high bitrate для audio и 480p/720p/1080p, 24/30 FPS для video; codec выбирает worker, а сервер применяет тарифный whitelist.

**Acceptance Criteria:**
- Выполнены AC CNV-77: отсутствуют raw FFmpeg args и выбор codec, недоступные по плану варианты получают предсказуемую ошибку.
- pytest, `make test` и `make build` зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Отдельный fullstack-эпик для FFmpeg-агента: его whitelist и quota policy не смешиваются с document/data contracts.

**Subtasks:**
- CNV-77 — безопасные параметры audio и video конвертаций

**Integration checklist:**
- Проверить audio-only targets из video source, лимиты free/paid и отказ невалидных preset’ов.
- Выполнить pytest, `make test` и `make build`.
