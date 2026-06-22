### Бэкенд: рантайм-баги и security-харднинг

**Критичность:** High

**TAGS:**
- bug

**Описание:**
Сборник рантайм-багов и security-дыр, выявленных при аудите кода (вкл. uncommitted working tree) 2026-06-20. Каждый пункт — закрыть либо явно отложить с записью.

**Проблема:**
- **Archive без транспорта:** `FileCategory::Archive='archive'` есть в enum, но транспорта `conv_archive` нет → `ConversionManager::dispatch()` бросит исключение на ЛЮБОЙ archive-конверсии.
- **Telegram replay:** `TelegramAuthService::verify()` не проверяет свежесть `auth_date` → перехваченный hash валиден вечно.
- **download getChunks():** `S3Storage::downloadResponse()` (вызывается из `ConversionController::download()`, `GET /api/v1/convert/{id}/download`) стримит результат из S3 чанками (`$output->getBody()->getChunks()`), чтобы не держать файл целиком в памяти. Опасение «метода нет в async-aws/s3 v2» **при проверке не подтвердилось**: в `composer.json` стоит `async-aws/s3: ^2.0`, а `getChunks()` определён в интерфейсе `ResultStream` (`vendor/async-aws/core/.../ResultStream.php`) → метод есть, рантайм-падения по этой причине нет. Пункт фактически закрыт проверкой; в коде уже висит NOTE с fallback-вариантами на случай смены версии.
- **Квота не возвращается при сбое:** счётчик квоты у пользователя только инкрементируется, обратной операции нет. Списание на submit: `ConversionManager::createConversion()` → `QuotaService::checkAndDecrement()` проверяет дневной лимит и сразу `incrementDailyConversions()`/`incrementDailyAiConversions()` + `flush()`. При провале воркера `ConversionResultPersister` (ветка `state==='failed'`) ставит `ConversionStatus::Failed` + `errorMessage` — и всё, **возврата квоты нет** → упавшая конверсия засчитана как успешная. Второй слой: лимиты захардкожены массивом `QuotaService::$planLimits` (`free`/`basic`/`pro`), при этом сущность `Plan` (поля `dailyLimit`, `dailyAiLimit`) существует, но `QuotaService` её не читает — два источника правды.
- **Refresh-token отсутствует:** правило CLAUDE.md «JWT TTL 1h + refresh 30д в httpOnly cookie» выполнено лишь частично — access TTL 1ч есть, refresh-механики нет.

**Влияние:**
Падения эндпоинтов в рантайме (archive, download), вечно валидный Telegram-hash (security), некорректный учёт квот, несоответствие auth-правилам проекта.

**Решение:**
- **Archive:** убрать/загейтить `Archive` до Стадии 7 либо явно отклонять archive-запросы с 4xx (понятная ошибка вместо 500).
- **Telegram replay:** ввести окно свежести `auth_date` (напр. ≤ 24ч) в `TelegramAuthService::verify()`. **Приоритетно (security).**
- **download getChunks():** проверка выполнена — метод присутствует в async-aws/s3 ^2.0 (интерфейс `ResultStream`). Доп. правок не требуется; закрыть как verified, оставить NOTE-fallback в коде.
- **Квота:** в ветке `failed` у `ConversionResultPersister` возвращать квоту (декремент счётчика; не уводить в минус, если суточный сброс `resetIfNeeded` уже произошёл); перевести `QuotaService` на чтение лимитов из `Plan`-сущности вместо хардкод-массива (единый источник истины).
- **Refresh-token:** **ОТЛОЖЕНО** (решение пользователя 2026-06-22). Refresh-механика (endpoint, ротация, хранение, отзыв) — заметный объём, смешивать с баг-фиксами рискованно. Вынести в отдельную задачу. Access TTL 1ч остаётся как есть.

**Критерии приёмки:**
- Каждый пункт закрыт ИЛИ явно отложен с записью причины.
- Security-пункты (Telegram replay) закрыты в первую очередь.

**Decisions:**
- Сгруппировано из аудита 2026-06-20; security приоритетнее остального.
- 2026-06-22: уточнены пункты getChunks (verified — не баг) и квота (детали механики). Команда: 2 dev + reviewer. Refresh-token отложен в отдельную задачу.
- 2026-06-22: при ревью найдена смежная утечка квоты на submit-пути (списание до S3-upload/persist/dispatch) — по решению пользователя пофикшена в этой же задаче (split check/charge).

**Execution Log (2026-06-22):**
- **Telegram replay** ✅ — `TelegramAuthService::verify()` сначала `isAuthDateFresh()`: null → reject, far-future (skew 300s) → reject, иначе `now-authDate ≤ maxAge` (default 86400). Env `TELEGRAM_AUTH_MAX_AGE` + фолбэк `env(...)=86400` в `services.yaml` (опционален в любом окружении). HMAC не тронут; невалидный → 401. Тесты: fresh/stale/far-future/missing/tampered.
- **Archive** ✅ — `ConversionManager::createConversion()` бросает `UnprocessableEntityHttpException` для `FileCategory::Archive` ДО check/dispatch; контроллер → 422 JSON. Тест пинит порядок (quota не трогается). Enum-значение сохранено.
- **getChunks()** ✅ — verified: метод есть в async-aws/s3 v2.10.0 (`ResultStream`). Правок нет.
- **Квота** ✅ — (1) refund при failure воркера: `QuotaService::refund()` (clamp-at-0, без flush/reset) из `ConversionResultPersister` failed-ветки, идемпотентно по terminal-state. (2) лимиты теперь из `Plan`-сущности через новый `PlanRepository::findByName()` (fallback free), хардкод-массив удалён; миграция уже сидит plans идентично. (3) submit-leak закрыт by-construction: `checkAndDecrement` разбит на `check()` (up-front, без инкремента) и `charge()` (после успешного `dispatch()`); `dispatch()` перенесён внутрь `createConversion`, из контроллера убран. Тесты на 3 точки сбоя (dispatch/S3/archive — charge не вызывается).
- **Refresh-token** ⏸ — отложен (см. Decisions).
- **Gate:** `make cs` ✅, `make phpstan` ✅, `make test-php` ✅ (30 тестов / 112 assertions).
- **Review:** reviewer — APPROVE (после APPROVE-WITH-NITS + ре-ревью дельты).
- **Прочее:** предупреждение про «закоммиченный TELEGRAM_BOT_TOKEN» неактуально — `.env` гитигнорится, реального токена в трекаемых файлах нет.

**Follow-ups (вне этой задачи):**
- Refresh-token (JWT refresh 30д, httpOnly cookie) — отдельная задача.
- Non-atomic charge/refund (нет оптимистичного лока) — митигировано single-instance консьюмером; `@Version` на Conversion/User при масштабировании.
- Unseeded `plans` → fallback в free (under-provision basic/pro) — информационно; миграция сидит, prod/test ок.
- `/formats` vs `/convert`: archive advertised в `workerCapabilities`, но `/convert` отвечает 422 — minor UX.
