### Сужение IAM convertor-dev: scoped-политики вместо broad readwrite для convertor-dump

**Criticality:** Medium

**TAGS:**
- tech-debt
- infra
- security
- s3

**Description:**
Follow-up CNV-10. Юзер MinIO `convertor-dev` после attach
`convertor-dump-rw-nodelete` всё ещё несёт broad `readwrite`, который даёт
`s3:DeleteObject` на **все** бакеты — в т.ч. `convertor-dump`, сводя no-Delete
политику на нет. MCP `minio` умеет только встроенные политики; кастомные JSON —
через `mc admin policy` (как в CNV-10).

**Problem:**
Least-privilege нарушен: dump-бакет фактически deletable; inputs/results тоже
живут под слишком широким `readwrite` без явного scoped JSON.

**Impact:**
Случайный/compromised ключ может стереть дампы и объекты inputs/results;
no-Delete гарантия CNV-10 неэффективна, пока `readwrite` прикреплён.

**Recommendation:**
1. Создать/дописать scoped-политики: `*-inputs`, `*-results` (Get/List/Put/Delete),
   dump — уже `convertor-dump-rw-nodelete` (без Delete).
2. `policy_attach` все три; `policy_detach` broad `readwrite` с `convertor-dev`.
3. Проверить push dump + upload/download conversion + GC delete.
4. Зафиксировать в `docs/infra/` + Execution Log (MCP limitation).

**Acceptance Criteria:**
- У `convertor-dev` нет `readwrite`; есть scoped на inputs, results, dump-nodelete.
- `make db-dump-push` работает; DeleteObject на `convertor-dump/*` — Access Denied.
- Conversion upload/result + 24h cleanup (Delete на inputs/results) работают.
- Docs/JSON в `docs/infra/` актуальны.

**Decisions:**
- (2026-08-03) Три отдельные scoped-политики (inputs rw+delete, results rw+delete,
  dump без delete); снять broad `readwrite`.
- (2026-08-03) Применять на shared MinIO prod (`apis3`) + docs; local/test без
  отдельного юзера не трогаем.
- (2026-08-03) Delete на inputs/results оставляем (нужен scheduler GC).

## Execution Log

- 2026-08-03: Live verify `user_info convertor-dev` → `convertor-dump-rw-nodelete,readwrite`. Dump policy JSON совпадает с `docs/infra/minio-convertor-dump-policy.json` (без DeleteObject) — не пересоздавали.
- 2026-08-03: Созданы кастомные политики через `docker exec shared-minio mc admin policy create` + `MC_HOST_local` (паттерн CNV-10 / mcp_minio._mc): `convertor-dev-inputs-rw`, `convertor-dev-results-rw`. MCP `policy_create` нет.
- 2026-08-03: Docs: `minio-convertor-dev-inputs-policy.json`, `minio-convertor-dev-results-policy.json`, `docs/infra/README.md`; `minio-convertor-policy.json` → deprecation stub (старый convertor-results).
- 2026-08-03: MCP `policy_attach` inputs+results (dump уже был); затем `policy_detach readwrite`. Итог `user_info`: `convertor-dev-inputs-rw,convertor-dev-results-rw,convertor-dump-rw-nodelete` — без `readwrite`.
- 2026-08-03: Smoke IAM convertor-dev: Put dump OK; Delete dump → Access Denied; Put/Get/Delete на convertor-dev-inputs и convertor-dev-results OK. Probe dump очищен админом. `make db-dump-push` — OK.
- 2026-08-03: `make db-dump-push` OK — ключ `xakki-convertor/convertor-20260803-153523.sql.gz` (9.06 KiB) в `convertor-dump`.
