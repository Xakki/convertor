### Эпик: батч простых hardening/cleanup задач

**Criticality:** Medium

**TAGS:**
- epic
- tech-debt

**Description:**
Сборный эпик из простых, независимых, низкорисковых задач (config/test/backend
hardening) с чёткими AC и без открытых дизайн-вопросов. Цель — быстро закрыть
накопившийся мелкий tech-debt одной когезивной пачкой в общей ветке
`epic/hardening`, с единым интеграционным гейтом в конце.

Каждая подзадача независима от остальных; порядок исполнения — от самого
безрискового (изолированные config/test-правки) к более рисковому (бэкенд-логика,
async-вынос). Запуск — **последовательный**: одна подзадача за раз,
`progress → test → ready`, потом следующая.

**Ветка:** `epic/hardening` (одна на весь эпик; отдельных `task/*` у подзадач нет).

**Подзадачи (порядок исполнения):**
1. `hardening-01-seed-plans-stub` — убрать мёртвый `make seed-plans` no-op из Makefile.
2. `hardening-02-worker-secrets-desync` — прокинуть `WORKER_API_TOKEN`/`GATEWAY_INTERNAL_TOKEN` в php/cron env.
3. `hardening-03-refresh-token-clock` — внедрить `ClockInterface` вместо `time()` в `RefreshTokenService`.
4. `hardening-04-dev-dlq-test-pollution` — изолировать тестовый DLQ-стрим + чистка мусорных записей.
5. `hardening-05-conversions-admin-indexes` — составные индексы на `conversions` (миграция).
6. `hardening-06-processing-ms` — пробросить `processingMs` в large/failure путях.
7. `hardening-07-e2e-login-helper` — заменить widget-HMAC логин в тесте на прямой JWT.
8. `hardening-08-test-db-provisioning` — 3 фикса CI/Makefile (canonical target, unify pass, `-j` barrier).
9. `hardening-09-tg-profile-hardening` — 3 нита (avatar HEAD/Range, async refreshAvatar, previewable=completed).
10. `hardening-10-worker-ai-compose-fix` — вынести overlay `worker-ai:` из `fluent-logging.yml` в `docker-compose.worker-ai.yml`. **Добавлена в ходе эпика** (подтв. с @user 2026-07-18): пред-существующая регрессия `17a3fde` ломает тест-стек compose и гейтит верификацию 7/8 + интеграционный гейт. **Исполняется ВНЕ порядка — сразу после 7, ПЕРЕД 8/9**, т.к. разблокирует их.
11. `hardening-11-test-token-env-isolation` — форс тест-токенов в `phpunit.xml`. **Добавлена в ходе эпика** (2026-07-18): регрессия, занесённая hardening-02 (dev-токены в OS-env php-контейнера шэдоят `.env.test` → `test-php-live` красный, 21 failure). Эпик-caused → эпик чинит. Исполняется перед закрытием hardening-08.

**Acceptance Criteria (эпик):**
- Все 9 подзадач доведены до `ready/` (AC каждой выполнены, ревью пройдено).
- Интеграция: рестарт стека, прогон полного тест-сьюта (PHP + Python) зелёный.
- Tests/QA green: `make phpstan`, `make cs-check`, `make test` (+ live/e2e где применимо).

**Decisions:**
- Состав (9 карточек: 7 SIMPLE + 2 мелких MEDIUM `test-db-provisioning-hardening`,
  `tg-profile-hardening`) и последовательный режим запуска — подтверждены с @user 2026-07-17.
- Груминговые карточки (`openai-00`, `conversion-chaining`, `registry-00`,
  `stage7-libreoffice`) в эпик НЕ входят — все MEDIUM/COMPLEX с открытыми
  дизайн-вопросами/зависимостями.

**Интеграционный гейт (2026-07-18) — GREEN:**
`docker-check`+`docker-check-worker-ai` exit 0; полный `make down`+`up` — все
контейнеры healthy; миграция `Version20260717210000` применена, индексы
`IDX_CONVERSIONS_STATUS_UPDATED_AT`/`_CREATED_AT` присутствуют; `app-queue`
messenger-консьюмер запущен; `make test-php-live` 250 tests 0 failures;
`test-api-integration` 10 tests OK; gateway 104 / data 98 / ai 111(+2 skip).

**Итог:** эпик вырос 9→11 подзадач (+10 worker-ai compose-fix с согласия @user;
+11 test-token-isolation — регрессия, занесённая hardening-02, эпик-caused).
Все 11 в ready/. Побочно заведены груминг-карты [[doctrine-schema-validate-drift]],
[[fast-unit-only-php-test-split]]; зависимость передана в [[conv-dead-no-consumer]].

**Status:** done.
