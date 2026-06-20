# ROADMAP — Convertor Service

> Канонический порядок выполнения MVP. **Сверяться с этим файлом** при планировании и старте
> задач. **Матрица форматов, лимиты, API, UI** — в разделе «Справочные данные» ниже (перенесено из
> удалённого `docs/plan.md`); статус реализации — kanban + git; детальные задачи — `.claude/kanban/`.
> Здесь — приоритеты и связки.
>
> Легенда карточек: `[[slug]]` → `.claude/kanban/**/<slug>.md`.
> «(карточки нет — завести)» = работа запланирована, но kanban-карточки ещё нет.

---

## Стадия 1 — Рабочие конвертации по API (без токена и лимитов)

**Цель:** текущие конвертации реально работают через REST API, документированы и покрыты тестами.

> **Реальность (аудит 2026-06-20):** код опережает план. Воркеры image/ffmpeg/data/ai **уже на KeyDB
> Streams + S3 в коде** (старый list-транспорт удалён); PHP тоже на Streams; Telegram-login + JWT + квота
> работают. «Миграция на Streams» как задача — закрыта. Остались точечные дыры (ниже).

- 🔴 **[[finish-worker-compose-wiring]]** — ffmpeg/data/ai мигрированы в коде, но в compose без S3-env и на
  `internal:true` сети → в рантайме не достучатся до S3. **Блокер стадии.**
- 🔴 **[[validate-libreoffice-worker]]** — libreoffice всё ещё HTTP-прокси, не consumer; задачи на
  документы/разметку (`conv.document`) **никто не потребляет** → документы (ядро продукта) не работают. Топ-приоритет.
- **Точечные дыры конвертаций:** [[validate-ffmpeg-worker]] (3gp), [[validate-data-worker]] (toml),
  [[validate-image-worker]] (OCR — не реализован). [[validate-ai-worker]] — Стадия 2.
- **[[api-openapi-swagger]]** — `/api/doc` + аннотации (бандл есть, документация пустая).
- **Тесты:** [[worker-conversion-tests]] (unit воркеров; нет `conftest.py`/`pytest.ini`),
  [[api-integration-tests]] (реальный прогон файлов через эндпоинты + замер скорости).
- **[[backend-hardening-bugs]]** — рантайм-баги из аудита (Archive без транспорта, Telegram replay,
  download getChunks, возврат квоты, refresh-token).
- **Сопутствующее:** [[upload-mime-size-validation]] (валидация загрузки), [[smoke-run-verify]] (e2e-гейт).

**Условия:** без авторизации по токену, без лимитов.
**Exit:** каждый реализованный эндпоинт отдаёт корректный результат на реальном файле (вкл. документы);
swagger полон; unit зелёные; интеграционные с замером скорости зелёные.

> ⚠️ **На совместный груминг (спросить пользователя):** [[validate-image-worker]],
> [[validate-libreoffice-worker]], [[validate-data-worker]], [[validate-ai-worker]] — грумим далее
> вместе. Открытые вопросы: владелец OCR, LibreOffice (Streams vs HTTP), toml в data,
> MVP-приоритет Таблиц/Презентаций.

---

## Стадия 2 — Распределённые воркеры + AI-контейнер (GPU)

**Цель:** воркеры запускаются по одной команде и обрабатывают задачи; AI работает на видеокарте.

- **Запуск отдельных воркеров одной командой** (в Docker), обработка задач из очереди — [[distributed-workers]].
- **Механика Streams** (подписка/распределение уже реализованы) — доделки: целостная документация,
  лаг-метрики в Prometheus, drift-тест «routing-key без consumer»: [[stream-subscription-distribution]].
- **AI-контейнер с использованием видеокарты (GPU)** — [[validate-ai-worker]] (гибрид: внешние API/g4f
  default + local fallback; фикс egress Whisper) + [[add-open-ai]] (g4f-бэкенд).
- **Расширение конвертаций с учётом AI** и проверка работоспособности.

**Exit:** воркеры (включая GPU-AI) поднимаются одной командой, забирают и выполняют задачи; AI-конвертации проверены.

---

## Стадия 3 — Админ-пользователь и API по токенам

**Цель:** появляется админ и доступ к API через токены.

- **Админ-пользователь** + панель — [[docs-admin-panel]].
- **Работа API через токены** (выпуск/проверка API-токенов, поверх текущего JWT). *(карточки нет — завести)*

**Exit:** админ заходит в панель; API-запросы авторизуются по токену.

---

## Стадия 4 — Телеграм-бот с конвертацией

**Цель:** бот выполняет конвертацию, включая загрузку больших файлов.

- **Телеграм-бот конвертации** + загрузка больших файлов (обход лимита Bot API на размер). *(карточки нет — завести)*

**Exit:** через бота можно сконвертировать файл, в т.ч. большой.

---

## Стадия 5 — Лендинг и веб-форма

**Цель:** публичная страница с загрузкой/конвертацией и историей.

- **Авторизация/регистрация:** Google / GitHub OAuth — [[backlog-auth-providers]]; вход через бота (по ссылке).
- **Лендинг + форма загрузки файла + опции конвертации.** *(карточки нет — завести)*
- **История конвертаций** со ссылками на файлы (S3 presign). *(карточки нет — завести)*

**Exit:** пользователь логинится (google/github/бот), грузит файл, видит результат и историю.

---

## Стадия 6 — Лимиты и оплата

**Цель:** вводятся лимиты на конвертацию и платная разблокировка.

- **Лимиты на конвертацию** (QuotaService → enforcement). *(карточки нет — завести)*; часть — в [[docs-prod-polish]].
- **Оплата — только Telegram Stars** — [[docs-payments-integration]] (разморозить; Stripe/Cryptomus/YooMoney
  вне MVP, YooMoney исключён).

**Exit:** лимиты применяются; оплата звёздами повышает лимит/кредиты.

---

## Стадия 7 — Прочие форматы и конвертации

**Цель:** добиваем оставшуюся матрицу форматов.

- [[post-mvp-conversion-formats]] — Архивы (zip/tar/gz/bz2/7z), CAD/DWG, доп. изображения (SVG/HEIC/AVIF),
  разметка rst/latex/wiki, MarkItDown.
- **Таблицы (Calc) / Презентации (Impress) / PDF→jpg постранично** — отложены сюда (решение 2026-06-20),
  тот же движок soffice, см. [[validate-libreoffice-worker]].
- **Расширение data-воркера** (лёгкие форматы, тот же движок pandas/stdlib) — кандидаты:
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

- [[fluent-logging-setup]] — логирование (done).
- [[optimize-worker-dockerfiles]] — оптимизация образов воркеров (done).
- [[fix-configs-working-state]], [[fix-queue-php-worker-mismatch]] — базовый boot + контракт очереди (done).
- [[storage-input-to-s3]] — файлы в S3 (in/out), `/shared-files` убран.
- [[docs-prod-polish]] — rate limiting, авто-очистка 24ч, метрики, SMS (частично пересекается со стадиями 6).
- [[docs-workers-conversion-validation]] — зонтик-трекер воркер-валидации (umbrella, не исполняется напрямую).

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
