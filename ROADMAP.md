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
> Статус актуализирован: **2026-06-21**.

---

## Стадия 1 — Рабочие конвертации по API (без токена и лимитов)

**Цель:** текущие конвертации реально работают через REST API, документированы и покрыты тестами.

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
- [ ] **P0 — [[backend-hardening-bugs]]** — security/рантайм-баги аудита: Telegram replay (нет окна `auth_date`),
  возврат квоты при фейле, Archive-dispatch без stream, download getChunks, refresh-token.
- [ ] **P1 — [[csv-xml-writer-hardening]]** — корректный `*→csv` для всех источников (CSV-writer теряет поля; xml→csv; типизация XML).
- [ ] **P1 — [[upload-mime-size-validation]]** — MIME-allowlist + max-size на `POST /convert` до S3-PUT.
- [ ] **P2 — [[api-openapi-swagger]]** — `/api/doc` + аннотации (бандл есть, документация пустая).
- [ ] **P2 — [[worker-conversion-tests]]** — unit воркеров (`conftest.py`/`pytest.ini`, моки subprocess/SDK).
- [ ] **P2 — [[api-integration-tests]]** — реальный прогон файлов через эндпоинты + замер скорости.
- [ ] **P2 — [[smoke-run-verify]]** — финальный e2e-гейт (стек healthy + 1 конвертация на категорию + логи/тесты).

**Условия:** без авторизации по токену, без лимитов.
**Exit:** каждый реализованный эндпоинт отдаёт корректный результат на реальном файле (вкл. документы);
swagger полон; unit зелёные; интеграционные с замером скорости зелёные.

> ℹ️ Валидация воркеров image/libreoffice/data/ffmpeg завершена (см. ✅ выше). Остаётся
> [[validate-ai-worker]] (Стадия 2). Stage-7-форматы (Таблицы/Презентации/epub-вход) вынесены
> в [[stage7-libreoffice-extra-formats]].

---

## Стадия 2 — Распределённые воркеры + AI-контейнер (GPU)

**Цель:** воркеры запускаются по одной команде и обрабатывают задачи; AI работает на видеокарте.

- [ ] **P1 — [[validate-ai-worker]]** — AI-контейнер на GPU: runtime-wiring, egress модели (Whisper), STT/TTS,
  AI-тесты. Гибрид: внешние API/g4f default + local fallback.
- [ ] **P1 — [[distributed-workers]]** — запуск воркеров отдельными контейнерами на любом хосте (только Redis
  Streams через TLS SNI + S3; без app-стека и `/shared-files`).
- [ ] **P2 — [[stream-subscription-distribution]]** — механика Streams: документация, лаг-метрики (XPENDING) в
  Prometheus/Grafana, drift-тест «routing-key без consumer».
- [ ] **P2 — [[add-open-ai]]** — g4f-бэкенд (MarkItDown, STT/TTS, text→image) поверх aip.xakki.ru.

**Exit:** воркеры (включая GPU-AI) поднимаются одной командой, забирают и выполняют задачи; AI-конвертации проверены.

---

## Стадия 3 — Админ-пользователь и API по токенам

**Цель:** появляется админ и доступ к API через токены.

- [ ] **P1 — [[docs-admin-panel]]** — админ-пользователь + панель (stats, user-management, очереди, логи).
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

- [ ] **P1 — [[upload-conversion-ui]]** — страница загрузки/конвертации (drag&drop, выбор формата из реестра,
  OCR-тоггл, статус через HTMX, ссылка на скачивание).
- [ ] **P1 — Лендинг** (публичная страница над формой загрузки). *(карточки нет — завести)*
- [ ] **P2 — История конвертаций** со ссылками на файлы (S3 presign). *(карточки нет — завести)*
- [ ] ❄️ **P2 — [[backlog-auth-providers]]** — Google / GitHub OAuth (заморожено; Telegram + SMS уже есть).

**Exit:** пользователь логинится (google/github/бот), грузит файл, видит результат и историю.

---

## Стадия 6 — Лимиты и оплата

**Цель:** вводятся лимиты на конвертацию и платная разблокировка.

- [ ] **P1 — Лимиты на конвертацию** (QuotaService → enforcement). *(карточки нет — завести)*; часть — в [[docs-prod-polish]].
- [ ] ❄️ **P1 — [[docs-payments-integration]]** — оплата только Telegram Stars (заморожено, ждёт разморозки;
  Stripe/Cryptomus вне MVP, YooMoney исключён).

**Exit:** лимиты применяются; оплата звёздами повышает лимит/кредиты.

---

## Стадия 7 — Прочие форматы и конвертации

**Цель:** добиваем оставшуюся матрицу форматов.

- [ ] ❄️ **[[post-mvp-conversion-formats]]** — зонтик: Архивы (zip/tar/gz/bz2/7z), CAD/DWG, доп. изображения
  (SVG/HEIC/AVIF), разметка rst/latex/wiki, MarkItDown (заморожено).
- [ ] **[[stage7-libreoffice-extra-formats]]** — доп-форматы soffice: epub-вход, Таблицы (Calc) / Презентации
  (Impress) / PDF→jpg постранично, разметка rst/latex/wiki (решение 2026-06-20).
- [ ] **[[archive-input-fanout]]** — распаковка архива на входе → fan-out файлов в отдельные очереди по target-формату
  (batch-распаковка, не конвертация формата архива).
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
- [ ] **[[docs-workers-conversion-validation]]** — зонтик воркер-валидации (umbrella в grooming; per-worker карточки все в done).
- [ ] **[[docs-prod-polish]]** — rate limiting, авто-очистка 24ч, метрики, SMS (пересекается со Стадией 6).
- [ ] **[[extract-worker-common-helpers]]** — DRY: общие хелперы воркеров в `workers/common` (subprocess-runner, MIME-таблицы).
- [ ] **[[align-document-stream-matrix-dlq]]** — выровнять матрицу `conv.document` (PHP-реестр vs воркер) + fast-DLQ перманентных ошибок.

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

> ⚠️ **Модель лимитов уточняется позже (Стадия 6).** Целевая модель (решение 2026-06-20): **у каждой
> конвертации — свои бесплатные лимиты и своя стоимость в звёздах** (per-conversion, не единый общий лимит).
> Цифры ниже — исторические из `plan.md`, противоречивые, оставлены как ориентир до финализации.

**Вариант A — дневные лимиты (из блока «Лимиты бесплатного тарифа»):**
- Обычные конвертации: 2/день; AI (OCR/STT/TTS): 1/день.
- Размер файла: free — 5 MB (архив) / 10 MB (обычный); платно — до 100 MB (загрузка файлов >20 MB — отдельно реализовать).

**Вариант B — месячные тарифные планы (из таблицы тарифов):**

| План | Цена | Конвертации/мес | AI | Файл |
|------|------|-----------------|----|----|
| Free | 0 | 10 | 3 | 50 MB |
| Basic | $3/мес или 150⭐ | 100 | 30 | 200 MB |
| Pro | $10/мес или 500⭐ | Безлимит | 100 | 500 MB |
| Pay-per-use | $0.05/конв | — | $0.15/конв | 500 MB |

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
- **Google / GitHub OAuth** — Стадия 5, см. [[backlog-auth-providers]].

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
