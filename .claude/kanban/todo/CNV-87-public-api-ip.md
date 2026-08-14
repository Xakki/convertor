### Анонимная идентификация public API по хэшу IP

**Criticality:** High

**TAGS:**
- tech-debt
- api
- authentication
- guest
- privacy
- rate-limit

**Description:**
Разрешить public API для анонимного клиента без обязательных cookie и персонального
токена. Если клиент не предъявил cookie или токен, идентифицировать public flow по
безопасному хэшированному представлению IP для quota/rate-limit и связанной истории.

**Problem:**
Текущий guest flow опирается на transient guest и materialized `guest_id` cookie.
Публичный API-клиент без browser-cookie не имеет стабильного контрактного
идентификатора, хотя продуктовая политика допускает anonymous public API.

**Impact:**
Внешний клиент без cookie/token не сможет предсказуемо использовать public API или
будет обходить guest/quota модель; документация CNV-86 не сможет честно описать
анонимный доступ.

**Recommendation:**
Сохранить приоритет существующего JWT/персонального API-токена и валидного
`guest_id`; только при их отсутствии вычислять keyed one-way IP identifier на
доверенном server-side boundary. Не хранить raw IP в user/history payload, не
доверять непроверенным forwarded headers и не подменять существующие user/guest
ownership checks.

**Acceptance Criteria:**
- Утверждённые public API endpoints работают без cookie и bearer token; AI/video
  ограничения и существующие policy guest/public endpoints сохраняются.
- При отсутствии JWT, personal token и валидного `guest_id` сервер создаёт/находит
  anonymous identity по keyed необратимому IP identifier и применяет к ней quota,
  rate-limit и owner checks.
- Raw IP не сохраняется в User, Conversion, history/API responses или logs как
  часть новой identity; секрет key не попадает в tracked env-файлы.
- Доверенная цепочка proxy и источник client IP определены явно; spoofed
  `X-Forwarded-For` не позволяет выбрать чужую identity.
- Existing cookie guest flow, login merge и registered user/API-token flow не
  регрессируют; нет пересечения данных между разными IP/owners.
- IP-derived identity и связанные anonymous conversion records хранятся 30 дней;
  lifecycle файлов и cleanup должны быть приведены в соответствие с этой policy.
- Есть functional/security tests no-cookie access, stable same-IP identity,
  distinct-IP isolation, quota/rate-limit, spoofed header protection и отсутствие
  raw IP/secret leakage; применимые QA зелёные.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- JWT, personal API token и валидный `guest_id` имеют приоритет над IP fallback.
- 2026-08-14: Nginx — единственный trusted proxy. Symfony принимает
  `X-Forwarded-For`/`X-Real-IP` только от него; forwarded headers от клиента не
  являются источником identity.
- 2026-08-14: anonymous identity — стабильный HMAC-SHA-256 client IP без
  ротации, с секретом только в `.env.local`. Это допускает долгую корреляцию
  активности одного IP; retention записей и история требуют отдельного решения.
- 2026-08-14: IP fallback применяется только к quota/rate-limit и ownership
  текущей операции. Browser после успешной конвертации получает существующий
  `guest_id`; общий anonymous history без cookie/token не выдаётся, чтобы не
  раскрывать данные пользователей за общим NAT/VPN IP.
- 2026-08-14: anonymous public API включает formats/examples, создание
  конвертации, quota, status и download/source/preview только текущей собственной
  операции. List history, retry/delete, payment, profile и management остаются
  user/admin-only.
- 2026-08-14: IP-derived identity и связанные anonymous conversion records
  хранятся 30 дней; cleanup файлов и policy документов должны быть согласованы
  с этим сроком.
- Карточка является prerequisite для честной анонимной маркировки public operations
  в CNV-86 и не меняет allowlist персональных токенов из CNV-83.
