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
- На `convertor-dump` активен lifecycle: current expiry 30d + noncurrent expiry ≤30d.
- `convertor-dev` по-прежнему без Delete на dump (совместимо с CNV-45).
- Docs описывают правило и как проверить.

**Decisions:**
- (2026-08-03) Механизм: MinIO bucket lifecycle (не скрипт в cron).
- (2026-08-03) TTL текущих объектов: 30 дней.
- (2026-08-03) Noncurrent versions тоже expiry (тот же TTL / не дольше 30d).
