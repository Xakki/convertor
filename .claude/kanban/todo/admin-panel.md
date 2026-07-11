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

**Frontend-контракт (общий для всех подзадач):** Symfony **Twig + HTMX + Alpine**
(правило проекта), сервится самим app-symfony под `/admin`. Референс-дизайн —
статик-мок `app-front/admin/index.html` (Alpine+Chart.js): **портируем дизайн в
Twig**, сам app-front не подключаем. HTMX — для live-обновления панелей (стата,
очереди), Alpine — для интерактива (модалки бана, поиск).

**Auth-контракт (общий):** доступ ко всему `^/admin` и `^/api/v1/admin` —
`ROLE_ADMIN` (см. подзадачу `admin-panel-auth`, hard-blocks все остальные).

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
- [ ] Все 6 подзадач в `ready/`.
- [ ] Пересборка/recreate контейнеров с чистого состояния; прогнать миграции
      (auth-флаг, conv-toggle — новые).
- [ ] Полный quality-gate: `make phpstan` (0), `make cs-check`, PHPUnit (+pytest,
      если тронут воркер), `make docker-check`.
- [ ] E2E-прогон: админ логинится, видит `/admin`, все 5 панелей отдают реальные
      данные (revenue = 0-плейсхолдер), ban/reset/toggle реально мутируют.
- [ ] Не-админ (`ROLE_USER`/`ROLE_GUEST`) получает 403 на `^/admin` и `^/api/v1/admin`.

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

**Status:** todo (epic). Разморожена из grooming 2026-07-11.
