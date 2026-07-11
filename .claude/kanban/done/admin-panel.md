### EPIC: Admin panel (Стадия 3)

**Criticality:** Minor
**Type:** epic

**TAGS:**
- feature
- epic

**Description:**
Размороженная `docs-admin-panel` (была FROZEN post-MVP). Оператору нужна панель
управления: статистика, управление юзерами, мониторинг очередей, логи, тумблер
конвертаций. Разбита на 6 подзадач-вертикальных срезов (каждая = admin API +
своя Twig/HTMX-панель). Груминг-разведка карты — см. ниже «Grounding».

**Frontend-контракт (общий для всех подзадач):** Symfony Twig-оболочка под `/admin`
(сервится app-symfony), рендер данных — **клиентский** (Alpine + Chart.js, htmx с
Bearer-заголовком). Референс-дизайн — статик-мок `app-front/admin/index.html`:
**портируем дизайн в Twig**, сам app-front не подключаем. Данные каждая панель
тянет из своего JSON-эндпоинта `GET /api/v1/admin/*` и рендерит клиентом (НЕ
server-rendered HTML-партиалами — см. auth-решение ниже).

**Auth-контракт (общий, РЕШЕНО 2026-07-11 — Option B):** реальная граница —
только `^/api/v1/admin` → `ROLE_ADMIN` через JWT-Bearer (из памяти JS; уже стоит и
покрыто тестом в `admin-panel-auth`). Twig-страница `/admin` при браузерной
навигации Bearer не несёт (access-JWT в памяти JS, httpOnly — только refresh),
поэтому серверный гейт самой страницы недостижим без изменения auth-контракта →
**НЕ меняем публичный JWT-контракт**. `/admin` — открытая Twig-оболочка с
**client-side guard**: JS проверяет наличие admin-JWT (refresh→access, `roles`
содержит `ROLE_ADMIN`), иначе redirect на логин. Секретных данных в HTML-оболочке
нет — все данные приходят из gated JSON-API.

**Shared client-setup (делается в первой UI-подзадаче `admin-panel-stats`,
переиспользуется всеми):** в `templates/admin/base.html.twig` — (а) admin-guard
редирект, (б) хелпер инъекции `Authorization: Bearer` во все fetch/htmx-запросы
(`htmx:configRequest` + общий fetch-wrapper), (в) обработка 401/403 → redirect.
Последующие панели только добавляют свою секцию + свой `/api/v1/admin/*` вызов.

**Subtasks (порядок = порядок исполнения):**
1. `admin-panel-auth` — **HARD-BLOCKS всё**. ROLE_ADMIN на User+JWT, access_control,
   роут `/admin` + базовый Twig-скелет (layout, nav). Первая.
2. `admin-panel-stats` — dashboard: конвертации/день, by-status, by-format, AI vs
   non-AI, avg processingMs, error-rate, счётчики юзеров; revenue = плейсхолдер-0.
3. `admin-panel-users` — список/поиск, ban/unban (`isActive`), ручной reset квоты,
   смена плана.
4. `admin-panel-queues` — размеры очередей + зависшие задачи из существующего
   `metrics_exporter`/Prometheus (не переписывать XINFO на PHP).
5. `admin-panel-logs` — searchable/filterable view по `Conversion` (DB-backed);
   линк-аут в Graylog для рантайм-логов воркеров.
6. `admin-panel-conv-toggle` — вкл/выкл конкретной конвертации (новый персистентный
   флаг + чтение в `ConversionRegistry`).

Подзадачи 2–6 зависят только от (1); между собой независимы (могут идти параллельно
после auth). Каждая доставляет свой вертикальный срез (API + Twig-панель + тесты).

**Integration checklist** (эпик покидает progress/ только при зелёном):
- [x] Все 6 подзадач в `ready/`.
- [x] Пересборка/recreate контейнеров с чистого состояния; прогнать миграции
      (auth-флаг, conv-toggle — новые).
- [x] Полный quality-gate: `make phpstan` (0), `make cs-check`, PHPUnit (+pytest,
      если тронут воркер), `make docker-check`.
- [x] Smoke-прогон: админ логинится, видит `/admin`, все 5 панелей отдают реальные
      данные (revenue = 0-плейсхолдер), ban/reset/toggle реально мутируют.
- [x] Не-админ (`ROLE_USER`/`ROLE_GUEST`) получает 403 на `^/api/v1/admin`; `/admin`
      client-guard редиректит не-админа на логин (данных в оболочке нет).

**Branch:** один `epic/admin-panel` на весь эпик (подзадачи НЕ получают своих веток).

**Out of scope (отдельные карты):**
- Revenue-метрика по реальным платежам → вернётся в Стадию 6 (Payment сейчас не
  персистится). Здесь только плейсхолдер-плитка.
- «Работа API через токены» (второй P1-пункт Стадии 3) → отдельная карта, не заведена.
- Richer ban-status (banReason/bannedAt) → при необходимости позже; сейчас `isActive`.

**Grounding (из груминг-разведки, 2026-07-11):**
- Admin backend отсутствует полностью; нет `Controller/Admin/`. Роутинг
  attribute-based (`config/routes.yaml`) — новый `Controller/Admin/*` авторегается.
- **ROLE_ADMIN не заведён**: `User::getRoles()` (`src/Entity/User.php:213-219`)
  отдаёт только ROLE_GUEST/ROLE_USER; `security.yaml` role_hierarchy до ROLE_USER.
  Фронт-мок уже ждёт JWT-claim `role:'admin'` (`app-front/js/app.js:46`) — бэк не
  выдаёт. Это и есть блокер-предпосылка.
- Entities: `User, Conversion, Payment, Plan, FileStorage, WorkerCapability`.
  `Payment` есть, но `new Payment` нигде не вызывается → revenue невозможен.
- `metrics_exporter` (Prometheus, `docker-compose.yml:408`, port 9472) уже эмитит
  `convertor_stream_length` (XLEN), `_group_pending` (XPENDING), `_group_lag`,
  `_group_consumers`, `_pending_max_idle_ms`, dead-letter (`conv.dead`). См.
  `workers/metrics_exporter/exporter.py:51-196`.
- «Stuck job» = мульти-сигнал: высокий XPENDING/oldest-PEL idle + consumer-lag при
  0 consumers + попадание в `conv.dead` DLQ + DB `Conversion.status` завис в
  Pending/Processing (`ConversionRepository::findPending`).

**Status:** ready (интеграция GREEN, 265af35; в done — по аппруву юзера).

**Integration evidence (2026-07-11):** dashboard свёл 5 панелей; чистая пересборка `make down/up`; миграции is_admin+conversion_toggles применены; `make phpstan` 0, `cs-check` clean, `lint:twig` OK, `make test-php-live` 192/192 pass, `docker-check` clean; smoke `/admin`=200 (5 панелей+window.admin), API 200 admin / 401 anon / 403 non-admin. 3 пред-существующих PHPUnit Notice (не от эпика).
