### Тир-квоты — пер-групповые лимиты с суточным И месячным окном

**Criticality:** Medium

**TAGS:**
- feature
- config

**Description:**
Квота сегодня — только **суточная** и только «all vs AI»: `QuotaService`
считает `dailyConversions`/`dailyAiConversions`, окно сброса — суточное
(`User.quotaResetAt`), план несёт лишь `Plan.dailyLimit`/`dailyAiLimit`.
Нужны **пер-групповые** лимиты (тиры) с **двумя** окнами — суточным И
месячным, — а цена плана считается за месяц.

**Problem:**
Квота не различает дешёвые (document/data) и дорогие (video/AI-GPU)
конверсии и не имеет месячного потолка. Нельзя выразить план вида «N/день
И M/мес по каждому классу нагрузки». Тарифы ROADMAP («/мес») не enforce'ятся.

**Тиры (4) — вычисляются в рантайме:**
`tier = isAi ? AI : mapCategory(category)`. Сигналы уже есть на `Conversion`
(category + isAi) — новой классификации сущностей не требуется.
ВАЖНО: **OCR — НЕ AI** (image-воркер, Tesseract, локально); `isAi=true`
только STT/TTS.

- **T1 Light**: document, markup, data, archive → worker-libreoffice/data (CPU-дёшево)
- **T2 Medium**: image (вкл. OCR), audio → worker-image / ffmpeg-audio
- **T3 Heavy**: video → worker-ffmpeg-video (тяжёлый транскод)
- **T4 AI**: isAi (STT/TTS) → worker-ai (remote GPU, внешняя стоимость)

**Прайс (ячейка = сутки / месяц; −1 = ∞; числа — ПРЕДЛОЖЕНИЕ):**

|                    | free ($0)     | basic ($3/мес·150⭐) | pro ($10/мес·500⭐) |
|--------------------|---------------|---------------------|--------------------|
| T1 Light           | 3 / 30        | 100 / 1500          | −1 / −1            |
| T2 Medium          | 2 / 15        | 50 / 800            | 300 / 6000         |
| T3 Heavy (видео)   | 0 / 0 🔒      | 10 / 120            | 60 / 800           |
| T4 AI (STT/TTS)    | 0 / 0 🔒      | 20 / 200            | 80 / 1200          |
| Макс. файл         | 50 MB         | 200 MB              | 500 MB             |

**Decisions (зафиксировано с пользователем):**
- Месячное окно = **СКОЛЬЗЯЩИЕ 30 ДНЕЙ** (от даты подписки / первой
  конверсии; НЕ календарный месяц).
- Дневной И месячный лимит — **оба ЖЁСТКИЕ потолки**; превышение любого окна
  задействованного тира → отказ (429/квота). Неиспользованный дневной остаток
  **НЕ переносится** в месячный (окна независимы).
- pro: безлимит (−1) **только на T1**; T3/T4 — конечные (защита дорогих
  GPU/видео-ресурсов).
- free T3/T4 = 0 — **новый путь enforcement**: квота-0 → 429, ОТДЕЛЬНО от
  существующего guest-гейта (залогиненный free-юзер сегодня проходит гейт
  ai/video — тот гейт только для гостей). Учесть **оба** пути в тестах.
- Pay-per-use ($0.05/конв, AI $0.15) — **ВНЕ этой итерации** (отдельная карта
  `pay-per-use-credits`).

**Impact (реализация):**
- **Plan**: пер-тир поля обоих окон
  (`{light,medium,heavy,ai}DailyLimit` + `…MonthlyLimit`, 8 полей); убрать
  `dailyLimit`/`dailyAiLimit`.
- **User**: пер-тир суточные + месячные счётчики + `monthlyResetAt`
  (скользящее окно) рядом с `quotaResetAt`.
- **QuotaService**: сигнатура `check`/`charge`/`refund`/`getRemainingQuota`
  принимает `category`+`isAi` → тир; проверяет **оба** окна; `applyDelta` по
  пер-тир колонкам; `resetIfNeeded` + месячная ветка (скользящие 30д).
- **ConversionManager**: передаёт `category`+`isAi`.
- **Миграция**: `ALTER plans`/`users` + reseed по таблице (заменяет seed из
  `Version20260419000001`).
- `/api/v1/quota` отдаёт 4 тира × 2 окна.
- admin `reset-quota` сбрасывает пер-тир оба окна.
- `FREE_FALLBACK` / `FREE_MAX_UPLOAD_MB` обновить.
- UI-квота (HTMX) показывает 4×2.

**Acceptance Criteria:**
- Пер-тир daily+monthly enforcement работает (unit+e2e квоты зелёные).
- free видео/AI отдаёт 429 (оба пути: квота-0 и guest-гейт).
- Миграция применяется + планы пере-сижены по таблице.
- `/api/v1/quota` и admin `reset-quota` обновлены (4×2).
- `make phpstan` / `make cs-check` / tests зелёные.

**Ссылки (первоисточники):**
- `FileCategory.php` — категории (mapCategory → тир).
- `QuotaService.php` — check/charge/refund/getRemainingQuota/resetIfNeeded.
- `Plan.php` — поля лимитов.
- `User.php:59-65` — счётчики + `quotaResetAt`.
- `Version20260419000001.php:110-113` — текущий seed планов.
- `ConversionRegistry.php:122-139` — streamFor/isOcr (сигналы тира).
- `ConversionManager.php` — оркестрация конверсии.
- Смежные карты: `seed-plans-stub`, `pay-per-use-credits`.
- QuotaService hardening — коммит `73f5c11`.

**NOTE:** Числа прайса подтверждены 2026-08-02 (см. Decisions).

**Decisions (старт 2026-08-02):**
- Числа прайса из таблицы карточки/ROADMAP — **подтверждены пользователем** без правок.
- Состав команды: backend → test-engineer → frontend → reviewer.
- Release note: миграция `Version20260802180000` **обнуляет** legacy `daily_conversions`/`daily_ai_conversions` без переноса в tier-счётчики (одноразовый reset usage на prod).

**Status:** ready

## Execution Log

- 2026-08-02: старт — `todo→progress`, ветка `task/CNV-30`, числа прайса подтверждены.
- 2026-08-02: делегирован backend (entity/migration/QuotaService/API/admin).
- 2026-08-02: backend landed `4b9b8b4` — 4×2 tiers, `/quota` shape `tiers.{light|medium|heavy|ai}.{daily|monthly}`; phpstan/cs/unit OK.
- 2026-08-02: делегированы test-engineer + frontend.
- 2026-08-02: tests `b0a6afb` — 539 PHP OK (+18); frontend `51abadc` — quota bar 4×2.
- 2026-08-02: QA PASS (`e3df5a1` cs); reviewer REQUEST CHANGES → fix `8988a67` (admin UI + DLQ check + charge reset) → APPROVE.
- 2026-08-02: hand-off → `test` → `ready`. Ожидает approve пользователя (`ready→done` + merge).
