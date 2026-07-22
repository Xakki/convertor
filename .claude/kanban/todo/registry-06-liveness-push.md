### Gateway пушит liveness воркеров в PHP + long-TTL GC

**Criticality:** Medium

**TAGS:**
- feature
- infra

**Description:**
Пятый шаг Phase 2 эпика `[[registry-00-self-registration]]`. Сейчас gateway получает
`ping{cpu,mem,load}` от воркеров (`_handle_ping()`, `workers/gateway/ws_server.py:425-437`) и
отвечает `pong`, но только логирует — данные никуда не персистятся и не доходят до PHP;
`WorkerCapability.lastSeen` обновляется единственный раз, при `register()`. По эпику (Decisions:
«Eviction = long-TTL GC, NOT short liveness gating») liveness НЕ гейтит роутинг — это чистый
мониторинг + основа для будущего candidate selection в Phase 3.

**Problem:**
- Нет способа узнать, жив ли зарегистрированный воркер, кроме как ждать нового register
  (который может не случиться месяцами, если процесс не рестартует).
- `worker_capabilities` копится бессрочно — отключённый и никогда не переподключившийся
  воркер/seed-строка остаётся в матрице навсегда.

**Impact:**
Без пуша liveness — админ-страница `[[registry-07-admin-workers-page]]` не сможет показать
реальный статус «жив/устарел», а без GC — матрица засоряется мёртвыми записями (в т.ч.
`__seed__`-строками из `[[registry-03-seed-migration]]`, которые не устаревают сами по себе).

**Recommendation:**
- Gateway агрегирует входящие `ping{cpu,mem,load}` по `(workerType, instanceId)` и периодически
  (интервал — batch, не per-ping) пушит их батчем на новый internal-эндпоинт PHP.
- Авторизация — тем же механизмом, что существующие internal-роуты
  (`app-symfony/src/Controller/Api/InternalWorkerController.php`, `#[Route('/api/v1/internal/worker')]`,
  firewall `internal_api`, токен `GATEWAY_INTERNAL_TOKEN`, см. `GatewayInternalAuthenticator.php`) —
  проверить точное имя эндпоинт-группы и токена при реализации, не изобретать новый механизм.
- PHP обновляет `lastSeen` по составному ключу `(workerType, instanceId)` (схема из
  `[[registry-02-schema-multi-instance]]`).
- Отдельная команда/Scheduler-джоба (Symfony Scheduler, как auto-delete файлов через 24ч,
  см. project CLAUDE.md «File Handling») — long-TTL GC, вычищающая capability-строки старше
  настраиваемого TTL (часы–дни, env-параметр). По эпику liveness НЕ гейтит роутинг — GC только
  чистит явно мёртвые записи, не влияет на выбор воркера для живых.
- Gateway при обрыве WS-соединения помечает соответствующий инстанс отключённым (сигнал для
  админ-вью, не для матрицы маршрутизации).

**Acceptance Criteria:**
- Gateway пушит батч liveness на новый internal-эндпоинт с существующей internal-авторизацией
  (`GATEWAY_INTERNAL_TOKEN`); неавторизованный запрос — 401/403.
- `WorkerCapability.lastSeen` обновляется по `(workerType, instanceId)` из push, без нового
  `register()`.
- GC-джоба удаляет строки старше TTL; TTL конфигурируем; живые инстансы (свежий `lastSeen`)
  не трогает.
- Обрыв WS у воркера отражается в статусе инстанса (для последующей админ-страницы), но НЕ
  убирает его пары из активной матрицы маршрутизации до истечения TTL.
- TTL-GC НИКОГДА не удаляет seed-строки (`instance_id='__seed__'`). Иначе пустая БД становится
  достижимой в обычной эксплуатации, и гарантия D1 «БД никогда не пуста» молча аннулируется —
  вместе с ней исчезают `/formats` и submit до первой живой регистрации. Согласовано с
  контрактом пустой БД из `[[registry-05-drop-hardcode]]`.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit, pytest (`workers/tests`, gateway).

**Decisions:**
- Груминг 2026-07-22 (D2): вариант «периодический re-register» отклонён — gateway и так видит
  реальный обрыв WS и уже получает cpu/mem/load через ping, отдельный push-эндпоинт дешевле и
  точнее.

**Зависит от:** `[[registry-05-drop-hardcode]]`

**Эпик:** `[[registry-00-self-registration]]`

**Status:** todo
