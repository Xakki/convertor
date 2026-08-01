# ROADMAP — Convertor Service

> Канонический порядок выполнения MVP. **Сверяться с этим файлом** при планировании и старте
> задач. **Матрица форматов, лимиты, API, UI** — в разделе «Справочные данные» ниже (перенесено из
> удалённого `docs/plan.md`); статус реализации — kanban + git; детальные задачи — `.claude/kanban/`.
> Здесь — приоритеты и связки.
>
> Легенда карточек: `[[slug]]` → `.claude/kanban/**/<slug>.md`.
> «(карточки нет — завести)» = работа запланирована, но kanban-карточки ещё нет.
>
> **Чекбоксы:** `[x]` — сделано (карточка в `kanban/done/`), `[ ]` — в работе/в очереди.
> **Приоритеты внутри стадии:** **P0** — блокер/критично, **P1** — высокий, **P2** — обычный.
> ❄️ — заморожено (`kanban/freeze/`, ждёт разморозки).
> Статус актуализирован: **2026-07-12**.

---

## Стадия 1 — Рабочие конвертации по API (без токена и лимитов) — ✅ завершена

**Цель:** текущие конвертации реально работают через REST API, документированы и покрыты тестами.

> ✅ **Стадия завершена** — все задачи в `done/`, кроме `[ ] CNV-39-smoke-run-verify` (финальный e2e-гейт,
> остаётся в `todo/`).

> **Реальность (актуализация 2026-06-21):** воркеры image/ffmpeg/data/libreoffice **на KeyDB Streams + S3**
> (старый list-транспорт и HTTP-прокси удалены); PHP тоже на Streams; Telegram-login + JWT + квота работают.
> Документы (`conv.document`) теперь потребляются libreoffice-consumer'ом — ядро продукта запущено.
> «Миграция на Streams» и валидация конвертаций воркеров — закрыты. Остались харднинг, баг-фиксы и тесты.

**Сделано (миграция + валидация воркеров):**
- [x] **[[finish-worker-compose-wiring]]** — ffmpeg/data/ai в compose с S3-env и egress (сеть `default`); e2e зелёные.
- [x] **[[validate-libreoffice-worker]]** — libreoffice переведён на Streams-consumer `conv.document` + S3; doc/pdf/md проверены.
- [x] **[[validate-image-worker]]** — OCR (tesseract) реализован inline + capability-роутинг + OCR-флаг (API/UI).
- [x] **[[validate-ffmpeg-worker]]** — 3gp (вход) + integration-тест 3gp→mp4 на реальном ffmpeg.
- [x] **[[validate-data-worker]]** — toml (вход/выход) + матрица csv/json/xml/yaml/toml на S3.

**В работе (приоритет ↓):**
- [x] **P0 — [[backend-hardening-bugs]]** — security/рантайм-баги аудита: Telegram replay (нет окна `auth_date`),
  возврат квоты при фейле, Archive-dispatch без stream, download getChunks, refresh-token.
- [x] **P1 — [[csv-xml-writer-hardening]]** — корректный `*→csv` для всех источников (CSV-writer теряет поля; xml→csv; типизация XML).
- [x] **P1 — [[upload-mime-size-validation]]** — MIME-allowlist + max-size на `POST /convert` до S3-PUT.
- [x] **P2 — [[api-openapi-swagger]]** — `/api/doc` + аннотации (бандл есть, документация пустая).
- [x] **P2 — [[worker-conversion-tests]]** — unit воркеров (`conftest.py`/`pytest.ini`, моки subprocess/SDK).
- [x] **P2 — [[api-integration-tests]]** — реальный прогон файлов через эндпоинты + замер скорости.
- [ ] **P2 — [[CNV-39-smoke-run-verify]]** — финальный e2e-гейт (стек healthy + 1 конвертация на категорию + логи/тесты).

**Условия:** без авторизации по токену, без лимитов.
**Exit:** каждый реализованный эндпоинт отдаёт корректный результат на реальном файле (вкл. документы);
swagger полон; unit зелёные; интеграционные с замером скорости зелёные.

> ℹ️ Валидация воркеров image/libreoffice/data/ffmpeg завершена (см. ✅ выше); AI-воркер тоже
> провалидирован ([[validate-ai-worker]], Стадия 2). Stage-7-форматы (Таблицы/Презентации/epub-вход)
> вынесены в [[CNV-41-stage7-libreoffice-extra-formats]].

---

## Стадия 2 — Распределённые воркеры + AI-контейнер (GPU)

**Цель:** воркеры запускаются по одной команде и обрабатывают задачи; AI работает на видеокарте.

- [x] **P1 — [[validate-ai-worker]]** — AI-контейнер на GPU: runtime-wiring, egress модели (Whisper), STT/TTS,
  AI-тесты. Гибрид: внешние API/g4f default + local fallback.
- [ ] **P1 — [[distributed-workers]]** — запуск воркеров отдельными контейнерами на любом хосте (только Redis
  Streams через TLS SNI + S3; без app-стека и `/shared-files`).
- [x] **P2 — [[stream-subscription-distribution]]** — механика Streams: документация, лаг-метрики (XPENDING) в
  Prometheus/Grafana, drift-тест «routing-key без consumer».
- [ ] **P2 — [[CNV-27-openai-00-integration]]** — g4f-бэкенд (MarkItDown, STT/TTS, text→image) поверх aip.xakki.ru.

**Registry (динамическая матрица форматов из БД):**
- [x] **[[registry-01-worker-register]]** — Phase 1: воркеры само-регистрируют capabilities → DB-матрица.
- [ ] **[[registry-00-self-registration]]** — EPIC: динамическая матрица форматов из БД (Phase 2/3, заблок.).

**Exit:** воркеры (включая GPU-AI) поднимаются одной командой, забирают и выполняют задачи; AI-конвертации проверены.

---

## Стадия 3 — Админ-пользователь и API по токенам

**Цель:** появляется админ и доступ к API через токены.

- [x] **P1 — [[admin-panel]]** — админ-пользователь + панель (stats, user-management, очереди, логи).
  Все 6 подзадач в `done/`: admin-panel-auth / -stats / -users / -queues / -logs / -conv-toggle.
- [ ] **P1 — Работа API через токены** — выпуск/проверка API-токенов поверх текущего JWT. *(карточки нет — завести)*

**Exit:** админ заходит в панель; API-запросы авторизуются по токену.

---

## Стадия 4 — Телеграм-бот с конвертацией

**Цель:** бот выполняет конвертацию, включая загрузку больших файлов.

- [ ] **P1 — Телеграм-бот конвертации** + загрузка больших файлов (обход лимита Bot API на размер). *(карточки нет — завести)*

**Exit:** через бота можно сконвертировать файл, в т.ч. большой.

---

## Стадия 5 — Лендинг и веб-форма

**Цель:** публичная страница с загрузкой/конвертацией и историей.

- [x] **P1 — [[upload-conversion-ui]]** — страница загрузки/конвертации (drag&drop, выбор формата из реестра,
  OCR-тоггл, статус через HTMX, ссылка на скачивание).
- [ ] **P1 — Лендинг** (публичная страница над формой загрузки). *(карточки нет — завести)*
- [ ] **P2 — История конвертаций** со ссылками на файлы (S3 presign). *(карточки нет — завести)*
- [x] **P2 — [[backlog-auth-providers]]** — Google / GitHub / Yandex / VK OAuth — реализовано эпиком
  `oauth-00-epic` (`.claude/kanban/progress/oauth-00-epic.md`); Yandex и VK добавлены сверх исходного
  scope карточки (Google+GitHub). Разморозка/перенос карточки бэклога — при закрытии эпика (тимлид).

**Exit:** пользователь логинится (google/github/yandex/vk/бот), грузит файл, видит результат и историю.

---

## Стадия 6 — Лимиты и оплата

**Цель:** вводятся лимиты на конвертацию и платная разблокировка.

- [ ] **P1 — Лимиты на конвертацию** (QuotaService → enforcement). *(карточки нет — завести)*
- [ ] **P1 — [[quota-service-hardening]]** — hardening QuotaService.
- [ ] **P2 — [[CNV-40-sms-otp-backup-auth]]** — SMS OTP резервный auth (SMSC.ru).
- [ ] ❄️ **P1 — [[CNV-12-docs-payments-integration]]** — оплата только Telegram Stars (заморожено, ждёт разморозки;
  Stripe/Cryptomus вне MVP, YooMoney исключён).

**Exit:** лимиты применяются; оплата звёздами повышает лимит/кредиты.

---

## Стадия 7 — Прочие форматы и конвертации

**Цель:** добиваем оставшуюся матрицу форматов.

- [ ] ❄️ **[[CNV-31-post-mvp-conversion-formats]]** — зонтик: Архивы (zip/tar/gz/bz2/7z), CAD/DWG, доп. изображения
  (SVG/HEIC/AVIF), разметка rst/latex/wiki, MarkItDown (заморожено).
- [ ] **[[CNV-41-stage7-libreoffice-extra-formats]]** — доп-форматы soffice: epub-вход, Таблицы (Calc) / Презентации
  (Impress) / PDF→jpg постранично, разметка rst/latex/wiki (решение 2026-06-20).
- [ ] **[[CNV-4-archive-input-fanout]]** — распаковка архива на входе → fan-out файлов в отдельные очереди по target-формату
  (batch-распаковка, не конвертация формата архива).
- [ ] **[[CNV-5-conversion-chaining]]** — цепочки конвертаций A→B→C (grooming, Стадия 7).
- [ ] **Расширение data-воркера** (лёгкие форматы, тот же движок pandas/stdlib) — кандидаты:
  - **TSV** и иные разделители (`;`, `|`) ↔ csv/json — тривиально через pandas.
  - **NDJSON / JSON Lines** ↔ csv/json.
  - **INI / .env / .properties** ↔ json/yaml.
  - **HTML-таблицы** → csv/json (pandas `read_html`).
  - **Parquet / Feather** ↔ csv/json (pyarrow) — для «дата»-аудитории, тяжелее по зависимостям.
  - **Excel (xlsx/xls/ods)** ↔ csv/json — пересекается с таблицами Calc; решить зону (data-воркер vs libreoffice).
  - Нюансы: flatten/unflatten вложенности (json/yaml/xml ↔ плоский csv), типизация при csv→json,
    атрибуты/namespaces в XML.

**Exit:** заявленная матрица форматов (см. «Справочные данные») покрыта или явно отложена.

---

## Сквозное / инфраструктура (вне нумерации стадий)

- [x] **[[fluent-logging-setup]]** — логирование (fluent-bit → Graylog, JSON-логи).
- [x] **[[optimize-worker-dockerfiles]]** — оптимизация образов воркеров (multi-stage, non-root, pinned).
- [x] **[[fix-configs-working-state]]**, **[[fix-queue-php-worker-mismatch]]** — базовый boot + контракт очереди (Streams).
- [x] **[[storage-input-to-s3]]** — файлы в S3 (in/out), `/shared-files` убран.
- [x] **[[docs-workers-conversion-validation]]** — зонтик воркер-валидации (umbrella в grooming; per-worker карточки все в done).
- [ ] **[[CNV-34-rate-limit-per-ip-user]]** — rate limiting per-IP/per-user (KeyDB).
- [ ] **[[file-cleanup-24h-cron]]** — авто-удаление файлов через 24ч (Scheduler).
- [ ] **[[CNV-24-metrics-alerting]]** — метрики/алертинг (worker health). *(пересекается со Стадией 6)*
- [x] **[[extract-worker-common-helpers]]** — DRY: общие хелперы воркеров в `workers/common` (subprocess-runner, MIME-таблицы).
- [x] **[[align-document-stream-matrix-dlq]]** — выровнять матрицу `conv.document` (PHP-реестр vs воркер) + fast-DLQ перманентных ошибок.

**Хардненинг / tech-debt (todo):**
- [ ] **[[guest-row-flood-hardening]]** — защита от флуда guest-строк.
- [ ] **[[e2e-login-helper-magic-link]]** — e2e-хелпер логина через magic-link.
- [ ] **[[CNV-14-e2e-magic-link-callback-mockbot]]** — e2e callback c mock-ботом (grooming).
- [ ] **[[formats-api-ocr-capable-flag]]** — флаг ocr-capable в `/formats` API.
- [ ] **[[CNV-1-admin-ban-instant-lockout]]** — мгновенный lockout при бане.
- [ ] **[[refresh-token-injectable-clock]]** — injectable clock для refresh-token тестов.
- [ ] **[[conversions-admin-indexes]]** — индексы для админ-выборок conversions.
- [ ] **[[cache-app-keydb-vs-filesystem]]** — app-cache: KeyDB vs filesystem.
- [ ] **[[test-db-provisioning-hardening]]** — hardening провижининга тест-БД.
- [ ] **[[verify-webm-harness-rewrite]]** — переписать webm verify-harness.
- [ ] **[[CNV-3-ai-worker-benchmarks]]** — бенчмарки AI-воркера.

---

# Справочные данные

> Перенесено из `docs/plan.md` (файл удалён). Это **авторитетный источник** матрицы форматов,
> лимитов, API и UI — карточки ссылаются сюда.

## Матрица поддерживаемых конвертаций

| Категория | Исходные форматы | Целевые форматы | Движок | AI? | MVP-статус |
|-----------|-----------------|-----------------|--------|-----|------------|
| **Документы** | doc, docx, odt, rtf, txt, html, epub, pages | docx, odt, pdf, txt, html, md, rtf, epub | LibreOffice + Pandoc | — | Стадия 1 |
| **PDF операции** | pdf | docx, txt, md, jpg (страницы) | LibreOffice + pdftotext + pdftoppm | — | Стадия 1 (PDF→jpg — Стадия 7) |
| **Разметка** | md, rst, latex, html, wiki | md, rst, html, pdf, docx | Pandoc | — | md/html — Стадия 1; rst/latex/wiki — Стадия 7 |
| **Данные** | csv, json, xml, yaml, toml | csv, json, xml, yaml, toml | Python (pandas/lxml/tomllib) | — | Стадия 1 (toml вкл.) |
| **Изображения** | jpg, png, gif, bmp, webp, tiff, svg, ico, avif, heic | jpg, png, gif, bmp, webp, tiff, ico, avif, pdf | ImageMagick / Pillow | — | Стадия 1 (SVG/HEIC/AVIF — Стадия 7) |
| **OCR** | jpg, png, pdf, tiff | txt, md, docx | Tesseract (в image-воркере) | — | Стадия 1 |
| **Аудио** | mp3, wav, ogg, flac, aac, m4a, opus, wma | mp3, wav, ogg, flac, aac, m4a, opus | FFmpeg | — | Стадия 1 |
| **Видео** | mp4, avi, mkv, mov, webm, flv, wmv (+3gp) | mp4, avi, mkv, mov, webm | FFmpeg | — | Стадия 1 |
| **Видео → Аудио** | mp4, avi, mkv, mov | mp3, wav, ogg, flac | FFmpeg | — | Стадия 1 |
| **Речь → Текст** | mp3, wav, ogg, m4a, opus (≤2ч) | txt, srt, vtt | Whisper (local) / внешние API | ✅ | Стадия 2 |
| **Текст → Речь** | txt, md (≤10 000 символов) | mp3, wav, ogg | TTS (local espeak/Coqui) / внешние API | ✅ | Стадия 2 |
| **Архивы** | zip, tar, gz, bz2, 7z | zip, tar.gz | Python (zipfile/tarfile/py7zr) | — | Стадия 7 |
| **CAD/DWG** | dwg, dxf | pdf, svg, png | LibreOffice Draw / ezdxf | — | Стадия 7 |
| **Электронные таблицы** | xls, xlsx, ods, csv | xlsx, ods, csv, pdf | LibreOffice Calc | — | Стадия 7 |
| **Презентации** | ppt, pptx, odp | pptx, odp, pdf | LibreOffice Impress | — | Стадия 7 |

## Лимиты и тарифы

> ⚠️ **Числа ниже — предложение; финализируется в задаче `CNV-30-plan-quota-daily-monthly`.**
> Модель: **пер-групповые (тир) лимиты с двумя окнами — суточным И месячным**;
> цена плана — за месяц. Тир вычисляется в рантайме по сигналам `Conversion`
> (`tier = isAi ? AI : mapCategory(category)`).

**Тиры (4):**
- **T1 Light**: document, markup, data, archive (CPU-дёшево).
- **T2 Medium**: image (**вкл. OCR** — локальный Tesseract, image-воркер), audio.
- **T3 Heavy**: video (тяжёлый транскод).
- **T4 AI**: только STT/TTS (`isAi`, remote GPU, внешняя стоимость).

> OCR — **НЕ AI**: это Medium (image, локальный Tesseract). AI-тир = только
> речь↔текст (STT/TTS).

**Прайс (ячейка = сутки / месяц; −1 = ∞):**

|                    | free ($0)  | basic ($3/мес·150⭐) | pro ($10/мес·500⭐) |
|--------------------|------------|---------------------|--------------------|
| T1 Light           | 3 / 30     | 100 / 1500          | −1 / −1            |
| T2 Medium          | 2 / 15     | 50 / 800            | 300 / 6000         |
| T3 Heavy (видео)   | 0 / 0 🔒   | 10 / 120            | 60 / 800           |
| T4 AI (STT/TTS)    | 0 / 0 🔒   | 20 / 200            | 80 / 1200          |
| Макс. файл         | 50 MB      | 200 MB              | 500 MB             |

- Месячное окно = скользящие 30 дней; дневной и месячный лимит — оба жёсткие
  потолки (превышение любого → 429). free видео/AI = 0 (недоступны без плана).
- **Pay-per-use** ($0.05/конв, AI $0.15) — отдельная фича `CNV-28-pay-per-use-credits`
  (оплата сверх лимитов плана), вне итерации тир-квот.

## API endpoints (основные)

```
POST   /api/auth/telegram          # Telegram login
POST   /api/auth/sms/request       # запросить SMS код
POST   /api/auth/sms/verify        # верифицировать OTP
POST   /api/convert                # загрузить файл и поставить в очередь
GET    /api/convert/{id}/status    # статус задачи
GET    /api/convert/{id}/download  # скачать результат
GET    /api/convert/history        # история пользователя
GET    /api/formats                # список доступных конвертаций
GET    /api/quota                  # текущий баланс квоты
POST   /api/payment/telegram-stars # оплата звёздами
GET    /api/admin/stats            # [ADMIN] статистика
```

## Аутентификация
- **Telegram Login Widget** (основной): hash → HMAC-SHA256 с bot token → User по telegram_id → JWT.
- **SMS OTP** (резерв): SMSC.ru, phone → OTP (6 цифр, 5 мин) → JWT.
- **Google / GitHub / Yandex / VK OAuth** — реализовано (эпик `oauth-00-epic`), см. skill
  `redesign-auth-access-contract` (раздел «OAuth-провайдеры») и [[backlog-auth-providers]] (карточка
  бэклога переносится/разрешается тимлидом при закрытии эпика). `GET /api/v1/auth/oauth/{provider}/start`
  и `/callback`, provider ∈ {google, github, yandex, vk}.

## Платежи (шлюзы)
- **MVP — только Telegram Stars** (XTR, через Bot API: invoice → `successful_payment` webhook).
- Позже (вне MVP): Stripe (KZ, USD/EUR/KZT), Cryptomus (USDT/BTC, РФ). **YooMoney исключён.**

## UI/UX (ориентир для Стадии 5)
- **Главная:** зона drag & drop, выбор «из формата → в формат», кнопка конвертировать.
- **Dashboard:** история конвертаций (статус, скачать, удалить), счётчик квоты.
- **Профиль:** привязанные аккаунты, тариф, история платежей.
- **Админка (`/admin`):** статистика (конвертаций/день, юзеры, выручка, ошибки воркеров), пользователи,
  очереди (размер по типу, зависшие задачи), платежи, вкл/выкл конкретной конвертации.

## Безопасность (инвариант)
- Path-traversal защита, JWT TTL 1ч + refresh, rate limiting по IP+User (KeyDB), MIME-проверка,
  автоудаление файлов через 24ч, лимит размера на Nginx, ClamAV — опционально.
