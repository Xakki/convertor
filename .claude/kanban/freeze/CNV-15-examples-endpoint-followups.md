### Мелкие follow-up после home-04 (examples/seed)

**Criticality:** Low

**TAGS:**
- tech-debt
- backend

**STATUS: FROZEN** — отложено до реального UX-удара от `size=0`.

**Description:**
Некритичная находка после `home-04-format-info-examples` (seed-examples + публичный
`ExampleController`). Non-blocking, вынесена сюда, чтобы не потерять.

**async-aws HeadObject content-length = 0 для `text/html`:** для примера
`examples/markup/md-to-html.html` `HeadObject` возвращает `content-length: 0`
(реальный объект в S3 — ~1.9 KiB; стрим отдаёт все 1985 байт корректно).
Сейчас обойдено защитно (строка размера рендерится только при `size > 0`).
Более чистое решение — кэш ответа `GET /api/v1/examples` (инвалидируемый при
seed) вместо per-request HeadObject.

**Problem:**
`async-aws HeadObject` для `text/html` возвращает `content-length: 0`, хотя объект корректно отдаётся потоком; текущий защитный рендер скрывает размер.

**Impact:**
Пока `size=0` не бьёт по UX, пользователь не видит размер части примеров; реализация кэша без подтверждённого UX-эффекта была бы преждевременной.

**Recommendation:**
Сохранить карточку замороженной до реального UX-удара от `size=0`; только после отдельного решения о разморозке рассмотреть кэш списка примеров или разбор quirk `HeadObject`.

**Acceptance Criteria:**
- Карточка остаётся замороженной; реализация не начинается до отдельного решения о разморозке.
- После разморозки выбранное решение устраняет подтверждённый UX-эффект `size=0`, сохраняя защитный рендер до его замены.

**Decisions:**
- Отложить, пока `size=0` не бьёт по UX (2026-08-01): защитный рендер (`size > 0`)
  достаточен на текущем масштабе; кэш списка примеров / разбор quirk HeadObject —
  только если скрытый размер начнёт мешать пользователям. Пункт про PHPUnit notice
  снят как дубль закрытой карточки `phpunit-notice-mock-without-expectations`.

**Status:** freeze.
