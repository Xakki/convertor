# Convertor Service — Master Plan
> Created: 2026-04-19 | Status: Planning

## Vision
SaaS-сервис для конвертации файлов всех популярных форматов. Бесплатный базовый тариф (2 конвертации), платный через Telegram Stars / Stripe / Cryptomus. Аутентификация через Telegram + SMS. Архитектура: PHP 8.5 + Symfony 7 backend, Alpine.js + HTMX + Tailwind frontend, Python/Go воркеры в очереди KeyDB.

---

## Таблица поддерживаемых конвертаций

| Категория | Исходные форматы | Целевые форматы | Движок | AI? |
|-----------|-----------------|-----------------|--------|-----|
| **Документы** | doc, docx, odt, rtf, txt, html, epub, pages | docx, odt, pdf, txt, html, md, rtf, epub | LibreOffice + Pandoc | — |
| **PDF операции** | pdf | docx, txt, md, jpg (страницы) | LibreOffice + pdftotext + pdftoppm | — |
| **Разметка** | md, rst, latex, html, wiki | md, rst, html, pdf, docx | Pandoc | — |
| **Данные** | csv, json, xml, yaml, toml | csv, json, xml, yaml | Python (pandas/lxml) | — |
| **Изображения** | jpg, png, gif, bmp, webp, tiff, svg, ico, avif, heic | jpg, png, gif, bmp, webp, tiff, ico, avif, pdf | ImageMagick / Pillow | — |
| **OCR** | jpg, png, pdf, tiff | txt, md, docx | Tesseract OCR | — |
| **Аудио** | mp3, wav, ogg, flac, aac, m4a, opus, wma | mp3, wav, ogg, flac, aac, m4a, opus | FFmpeg | — |
| **Видео** | mp4, avi, mkv, mov, webm, flv, wmv | mp4, avi, mkv, mov, webm | FFmpeg | — |
| **Видео → Аудио** | mp4, avi, mkv, mov | mp3, wav, ogg, flac | FFmpeg | — |
| **Речь → Текст** | mp3, wav, ogg, m4a, opus (≤2ч) | txt, srt, vtt | Whisper (local) / OpenAI API | ✅ |
| **Текст → Речь** | txt, md (≤10 000 символов) | mp3, wav, ogg | Coqui TTS (local) / ElevenLabs | ✅ |
| **Архивы** | zip, tar, gz, bz2, 7z | zip, tar.gz (перепаковка) | Python (zipfile/tarfile/py7zr) | — |
| **CAD/DWG** | dwg, dxf | pdf, svg, png | LibreOffice Draw / ezdxf | — |
| **Электронные таблицы** | xls, xlsx, ods, csv | xlsx, ods, csv, pdf | LibreOffice Calc | — |
| **Презентации** | ppt, pptx, odp | pptx, odp, pdf | LibreOffice Impress | — |

**Лимиты бесплатного тарифа:**
- Обычные конвертации: 2 в день
- AI конвертации (OCR, STT, TTS): 1 в день
- Максимальный размер файла: 50 MB (free), 500 MB (paid)

---

## Архитектура системы

```
┌─────────────────────────────────────────────────────────┐
│                    Nginx (reverse proxy)                 │
└──────────┬──────────────────────────┬───────────────────┘
           │ /api/*                   │ /*
    ┌──────▼──────┐            ┌──────▼──────┐
    │  PHP-FPM    │            │  Frontend   │
    │  Symfony 7  │            │  Alpine.js  │
    │  (API +     │            │  HTMX +     │
    │   Auth +    │            │  Tailwind   │
    │   Payment)  │            │             │
    └──────┬──────┘            └─────────────┘
           │
    ┌──────▼──────┐
    │   MariaDB   │  (Users, Conversions, Payments, Files)
    └─────────────┘
           │
    ┌──────▼──────┐
    │    KeyDB    │  (Queue transport + Rate limiting + Sessions)
    └──────┬──────┘
           │ jobs
    ┌──────┴──────────────────────────────┐
    │           Workers (Python)          │
    ├─────────────────────────────────────┤
    │ libreoffice-worker  (docs)          │
    │ ffmpeg-worker       (video/audio)   │
    │ image-worker        (images/OCR)    │
    │ ai-worker           (STT/TTS)       │
    │ data-worker         (csv/json/xml)  │
    └─────────────────────────────────────┘
           │
    ┌──────▼──────┐
    │ Shared FS   │  /shared-files/  (S3 в продакшне)
    └─────────────┘
```

---

## Структура проекта (директории)

```
convertor/
├── app/                          # PHP 8.5 Symfony 7 backend
│   ├── src/
│   │   ├── Controller/
│   │   │   ├── Api/              # REST API endpoints
│   │   │   │   ├── ConversionController.php
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── PaymentController.php
│   │   │   │   └── UserController.php
│   │   │   └── Admin/            # Admin panel controllers
│   │   ├── Entity/
│   │   │   ├── User.php
│   │   │   ├── Conversion.php
│   │   │   ├── Payment.php
│   │   │   ├── Plan.php
│   │   │   └── FileStorage.php
│   │   ├── Message/
│   │   │   └── ConversionMessage.php
│   │   ├── MessageHandler/
│   │   │   └── ConversionMessageHandler.php
│   │   ├── Service/
│   │   │   ├── Auth/
│   │   │   │   ├── TelegramAuthService.php
│   │   │   │   └── SmsAuthService.php
│   │   │   ├── Conversion/
│   │   │   │   ├── ConversionRegistry.php    # реестр всех конверторов
│   │   │   │   ├── ConversionManager.php
│   │   │   │   └── FormatDetector.php
│   │   │   ├── Payment/
│   │   │   │   ├── TelegramStarsService.php
│   │   │   │   ├── StripeService.php
│   │   │   │   └── CryptomusService.php
│   │   │   └── Quota/
│   │   │       └── QuotaService.php
│   │   ├── Repository/
│   │   ├── DTO/
│   │   └── Enum/
│   │       ├── ConversionStatus.php
│   │       └── FileCategory.php
│   ├── config/
│   ├── migrations/
│   └── public/
├── frontend/                     # Alpine.js + HTMX + Tailwind
│   ├── src/
│   │   ├── pages/
│   │   │   ├── index.html        # главная / дропзона
│   │   │   ├── dashboard.html    # история конвертаций
│   │   │   ├── pricing.html      # тарифы
│   │   │   └── admin/
│   │   ├── js/
│   │   │   ├── app.js
│   │   │   ├── upload.js         # drag & drop, progress
│   │   │   └── auth.js           # Telegram login widget
│   │   └── css/
│   └── dist/                     # сборка (Vite)
├── workers/                      # Python воркеры
│   ├── common/
│   │   ├── base_worker.py        # базовый класс
│   │   └── keydb_client.py
│   ├── libreoffice/              # существующий (рефакторинг)
│   ├── ffmpeg/
│   ├── image/
│   ├── ai/                       # Whisper + TTS
│   └── data/
├── docker/
│   ├── php/
│   ├── nginx/
│   ├── workers/
│   └── frontend/
├── libreoffice/                  # существующий микросервис
├── mariadb/
├── shared-files/
└── docker-compose.yml
```

---

## Фазы разработки

### Фаза 1 — Инфраструктура и ядро (MVP)
- [ ] `1.1` Docker compose с PHP-FPM, Symfony skeleton, KeyDB, MariaDB, Nginx
- [ ] `1.2` Symfony: базовые Entity (User, Conversion, FileStorage)
- [ ] `1.3` Аутентификация через Telegram Login Widget + JWT
- [ ] `1.4` API: загрузка файла → постановка в очередь → статус → скачивание
- [ ] `1.5` Воркер LibreOffice (рефакторинг существующего в queue-based)
- [ ] `1.6` Frontend: drag & drop загрузка, выбор формата, прогресс статуса
- [ ] `1.7` Quota service: лимиты бесплатных конвертаций

### Фаза 2 — Расширенные конвертации
- [ ] `2.1` FFmpeg воркер (видео/аудио конвертации)
- [ ] `2.2` ImageMagick/Pillow воркер (изображения)
- [ ] `2.3` Data воркер (CSV/JSON/XML/YAML)
- [ ] `2.4` Tesseract OCR воркер
- [ ] `2.5` Pandoc воркер (разметка)

### Фаза 3 — AI конвертации
- [ ] `3.1` Whisper STT воркер (speech-to-text)
- [ ] `3.2` Coqui TTS воркер (text-to-speech)
- [ ] `3.3` AI quota tracking

### Фаза 4 — Платежи
- [ ] `4.1` Telegram Stars интеграция (через Bot API)
- [ ] `4.2` Stripe интеграция (для Казахстана / международная)
- [ ] `4.3` Cryptomus интеграция (крипто, доступно из РФ)
- [ ] `4.4` Страница тарифов и управление подпиской

### Фаза 5 — Админ панель
- [ ] `5.1` Dashboard: статистика конвертаций, пользователи, платежи
- [ ] `5.2` Управление пользователями (бан, квоты, ручной сброс)
- [ ] `5.3` Мониторинг очередей и воркеров
- [ ] `5.4` Логи конвертаций

### Фаза 6 — Полировка и продакшн
- [ ] `6.1` S3/MinIO для хранения файлов
- [ ] `6.2` Rate limiting (Redis)
- [ ] `6.3` Очистка временных файлов (cron)
- [ ] `6.4` Метрики и алерты
- [ ] `6.5` SMS верификация (Vonage/SMSC)

---

## Аутентификация

### Telegram Login Widget
1. Frontend показывает кнопку «Войти через Telegram»
2. Telegram возвращает hash + user data
3. Backend верифицирует hash через HMAC-SHA256 с bot token
4. Создаём/находим User по telegram_id, выдаём JWT

### SMS верификация (резервная)
- Провайдер: SMSC.ru (доступен для РФ) или Vonage
- Алгоритм: phone → OTP (6 цифр, 5 мин) → JWT

### Другие надёжные сервисы
- Google OAuth (если есть аккаунт)
- GitHub OAuth

---

## Платежи

| Шлюз               | Доступность            | Комиссия        | Валюта           |
|--------------------|------------------------|-----------------|------------------|
| **Telegram Stars** | Везде через Telegram   | ~30% (апп сбор) | XTR              |
| **Stripe**         | Казахстан (KZ карта ✅) | 2.9% + $0.30    | USD/EUR/KZT      |
| **Cryptomus**      | Везде + РФ ✅           | 0.4-1%          | USDT/BTC/ETH/... |
| **YooMoney**       | РФ                     | 3.5%            | RUB              |

### Тарифные планы
| План        | Цена             | Конвертации/мес | AI конвертации | Файл   |
|-------------|------------------|-----------------|----------------|--------|
| Free        | 0                | 10              | 3              | 50 MB  |
| Basic       | $3/мес или 150⭐  | 100             | 30             | 200 MB |
| Pro         | $10/мес или 500⭐ | Безлимит        | 100            | 500 MB |
| Pay-per-use | $0.05/конв       | —               | $0.15/конв     | 500 MB |

---

## UI/UX

### Пользовательский интерфейс
- **Главная страница**: большая зона drag & drop, выбор «из формата» → «в формат», кнопка конвертировать
- **Dashboard**: история конвертаций (статус, скачать, удалить), счётчик квоты
- **Профиль**: привязанные аккаунты, тарифный план, история платежей
- **Тарифы**: сравнительная таблица, кнопки оплаты

### Интерфейс администратора (отдельный маршрут /admin)
- **Статистика**: конвертаций/день, пользователей, выручка, ошибки воркеров
- **Пользователи**: список, поиск, детальная страница, ручные операции
- **Очереди**: размер очередей по типу, зависшие задачи, ошибки
- **Платежи**: история транзакций, возвраты
- **Форматы**: включить/выключить конкретную конвертацию

---

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
POST   /api/payment/stripe         # оплата картой
POST   /api/payment/crypto         # крипто оплата
GET    /api/admin/stats            # [ADMIN] статистика
```

---

## Технологический стек

| Компонент   | Технология                        | Обоснование                         |
|-------------|-----------------------------------|-------------------------------------|
| Backend     | PHP 8.5 + Symfony 7               | Требование пользователя, как ExRate |
| Frontend    | Alpine.js 3 + HTMX + Tailwind CSS | Лёгкий, быстрый, нет сборщика       |
| Queue       | KeyDB + Symfony Messenger         | Уже есть в инфраструктуре           |
| Workers     | Python 3.12                       | Экосистема AI/ML библиотек          |
| DB          | MariaDB 11                        | Уже в стеке                         |
| Cache/Rate  | KeyDB                             | Redis-совместимый                   |
| Storage     | Local volume → MinIO              | Простой старт, S3-совместимый       |
| OCR         | Tesseract 5                       | Open source, без API                |
| STT         | Whisper (faster-whisper)          | Локально, без оплаты                |
| TTS         | Coqui TTS                         | Open source, локально               |
| Audio/Video | FFmpeg                            | Стандарт отрасли                    |
| Images      | ImageMagick + Pillow              | Надёжно, полная поддержка           |
| Documents   | LibreOffice + Pandoc              | Уже реализовано                     |

---

## Безопасность
- Все файлы в изолированных директориях (path traversal защита есть)
- JWT токены с коротким TTL (1ч) + refresh token
- Rate limiting по IP + User через KeyDB
- Сканирование загружаемых файлов (ClamAV опционально)
- Автоудаление файлов через 24ч после конвертации
- Проверка MIME типа (не только расширение)
- Максимальный размер файла на уровне Nginx
