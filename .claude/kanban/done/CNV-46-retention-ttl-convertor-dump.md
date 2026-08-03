### Retention/TTL старых дампов в бакете convertor-dump

**Criticality:** Medium

**TAGS:**
- tech-debt
- infra
- backup
- s3

**Description:**
Follow-up CNV-10. Дампы пушатся ключами `…/convertor-<UTC>.sql.gz` без
оверрайта; versioning на `convertor-dump` включён. Retention в MVP CNV-10
сознательно отложен.

**Problem:**
Объекты и noncurrent-версии копятся без TTL → рост стоимости/мусора в бакете.

**Impact:**
Долгосрочно — раздутый бакет; нет автоматической ротации бэкапов.

**Recommendation:**
MinIO/S3 bucket lifecycle: expiry текущих объектов **30 дней** + expiry
noncurrent versions (тот же TTL). Без Delete в IAM `convertor-dev` (lifecycle
на стороне MinIO). Задокументировать правило в `docs/infra/` / Execution Log.
Проверка: создать тестовый объект со старой датой / или подтвердить rule через
`mc ilm` / консоль.

**Acceptance Criteria:**
- [x] На `convertor-dump` активен lifecycle: current expiry 30d + noncurrent expiry ≤30d.
- [x] `convertor-dev` по-прежнему без Delete на dump (совместимо с CNV-45).
- [x] Docs описывают правило и как проверить.

**Decisions:**
- (2026-08-03) Механизм: MinIO bucket lifecycle (не скрипт в cron).
- (2026-08-03) TTL текущих объектов: 30 дней.
- (2026-08-03) Noncurrent versions тоже expiry (тот же TTL / не дольше 30d).

## Execution Log

- 2026-08-03 (infra-ops, `task/CNV-46`):
  - MCP `bucket_info convertor-dump`: Versioning Enabled, **ILM: Disabled** (до),
    6 объектов / 7 versions.
  - До: `mc ilm rule ls` → lifecycle configuration does not exist.
  - Добавлено одно правило через docker-exec + `MC_HOST_local` (root, паттерн
    CNV-10/CNV-45; MCP ILM-инструментов нет):
    `mc ilm rule add --expire-days 30 --noncurrent-expire-days 30 local/convertor-dump`
    → ID `d9oc8qg60lkns07alv7g`.
  - После: `bucket_info` → **ILM: Enabled**. Export:
    `Expiration.Days=30`, `NoncurrentVersionExpiration.NoncurrentDays=30`,
    `Status=Enabled`.
  - IAM без изменений: `user_info convertor-dev` →
    `convertor-dev-inputs-rw,convertor-dev-results-rw,convertor-dump-rw-nodelete`;
    `policy_info convertor-dump-rw-nodelete` — нет `s3:DeleteObject`.
  - Объекты не удалялись (list only). Docs: `docs/infra/README.md` § Lifecycle.
- 2026-08-03: Review PASS → moved to ready.
