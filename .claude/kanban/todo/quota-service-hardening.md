### Квота: hardening QuotaService (тихий откат в free + неатомарность charge/refund)

**Критичность:** Low

**TAGS:**
- bug
- tech-debt

**Описание:**
Слияние двух FROZEN grooming-карт (обе Low, обе трогают только `QuotaService`,
обе просили bundle): `quota-unseeded-plans-fallback` + `quota-charge-refund-atomicity`.
Найдено при ревью `backend-hardening-bugs` (2026-06-22).

Две независимые проблемы в `app-symfony/src/Service/Quota/QuotaService.php`:

**(A) Тихий откат платного плана в free.** `limitsFor()` и `maxUploadBytes()`
делают `findByName($user->getPlan()) ?? findByName('free')`, а `limitsFor()` при
пустой таблице ещё и уходит в `FREE_FALLBACK`. Итог — под-провижн платных юзеров
БЕЗ ошибки в двух сценариях:
1. Таблица `plans` пуста (миграция `Version20260419000001` сидит free/basic/pro,
   но новый стенд / частичный rollback / ручная БД могут её пропустить) →
   `plan===null` → `FREE_FALLBACK`.
2. **Коварнее:** у юзера `plan='pro'`, строки `pro` нет, а `free` есть →
   молча обслуживают по free, ветка fallback (`$plan===null`) НЕ срабатывает,
   сигнала нет вообще.

**(B) Неатомарность charge/refund.** `charge()` (increment) и `refund()`
(decrement) — read-then-write по счётчику `User` без лока, финализируются
`em->flush()`. Сейчас не воспроизводится (result-consumer single-instance:
`docker/php/supervisor.app.ini`, без `numprocs`), но при scale-out консьюмера
или гонке web-charge vs near-instant worker-refund возможен last-flush-win.
`clamp-at-0` спасает от ухода в минус, но не от неверного учёта.

**Решение (согласовано в груминге):**

*Часть A — сигнал об откате:*
- Считать «молчаливым откатом» ОБА случая: РАЗРЕШЁННОЕ имя плана ≠ запрошенному
  (`$user->getPlan()`) ИЛИ таблица пуста (`plan===null` → `FREE_FALLBACK`).
- **Warning-лог всегда** (и в prod) при таком откате — с `requestedPlan` и тем,
  во что откатились (`free` / `FREE_FALLBACK`). Логер `LoggerInterface`
  автовайрится (образец — `ConversionResultPersister`).
- **Throw в non-prod:** при `APP_ENV !== 'prod'` бросать `RuntimeException` прямо
  в точке отката (в `limitsFor()` и `maxUploadBytes()`), чтобы misconfig падал
  сразу в CI/dev. В prod — только warning + отдаём free (fail-open, не рушим
  платящих). Env инжектить как строковый arg `%kernel.environment%` (образец —
  как `$resultsBucket: '%env(...)%'` в этом же сервисе-слое).
- `FREE_FALLBACK` / `FREE_MAX_UPLOAD_MB` остаются как last-resort (не удалять).

*Часть B — атомарность:*
- Заменить read-then-write на атомарный SQL-UPDATE по PK `User`:
  - refund: `UPDATE users SET daily_conversions = GREATEST(0, daily_conversions - 1) WHERE id = :id`
    (и аналог для `daily_ai_conversions`).
  - charge: тем же паттерном `... = daily_conversions + 1 ...` (increment
    ловит ту же гонку — делаем симметрично; frozen-решение называет decrement,
    increment покрываем заодно тем же UPDATE).
- Через DBAL/repository, НЕ через KeyDB (frozen: «atomic SQL decrement, not KeyDB»).
- После UPDATE синхронизировать in-memory `User` (`em->refresh()` или ручной
  `setDaily*`), т.к. счётчик мог измениться в БД — иначе последующий flush в том
  же реквесте перетрёт. Проверить, что `check()` (read-guard) и `getRemainingQuota()`
  видят согласованное значение.
- Учесть `resetIfNeeded()`: сброс окна тоже пишет счётчики через flush — не
  словить гонку сброс-vs-decrement (документировать/оставить как есть при
  single-instance, TODO-коммент про scale-out).

**Затрагиваемые файлы:**
- `app-symfony/src/Service/Quota/QuotaService.php` — основной.
- `app-symfony/config/services.yaml` — bind `%kernel.environment%` в QuotaService.
- Вызыватели (проверить, что контракт не сломан): `ConversionResultPersister`,
  `ConversionManager`, `ConversionController`.
- Тесты: `QuotaServiceTest` (PHPUnit) — новые кейсы (см. AC).

**Acceptance criteria:**
- [ ] Warning-лог при откате в free пишется в ОБОИХ сценариях (пустая таблица +
      подмена именованного плана), в т.ч. в prod.
- [ ] При `APP_ENV !== 'prod'` откат платного плана в free бросает исключение
      (в `limitsFor()` и `maxUploadBytes()`); при `APP_ENV === 'prod'` — только
      warning, поведение free сохраняется (запрос не падает).
- [ ] Запрос юзера с `plan='free'` при засиженной таблице НЕ логирует warning и
      НЕ бросает (не ложные срабатывания).
- [ ] `charge()`/`refund()` выполняют атомарный SQL-UPDATE по PK; счётчик в БД
      не уходит в минус (`GREATEST(0, x-1)`); in-memory `User` согласован.
- [ ] `make phpstan` — 0 ошибок; `make cs-check` чисто.
- [ ] PHPUnit-кейсы: (1) prod-fallback логирует и не бросает, (2) non-prod
      бросает, (3) named-plan-substitution ловится, (4) refund clamp-at-0,
      (5) charge/refund корректны для обычного пути.

**Decisions:**
- Слиты две FROZEN-карты в одну todo (обе Low, одна зона QuotaService, один PR).
- (A) Warning всегда + throw в non-prod (`APP_ENV≠prod`); `FREE_FALLBACK` —
  last-resort, не удалять. Детект отката = разрешённый план ≠ запрошенному ИЛИ
  пустая таблица (покрываем и подмену pro→free, не только пустую таблицу).
- (B) Атомарный SQL-decrement `GREATEST(0,x-1)` (не KeyDB); increment покрываем
  симметрично. Revisit при scale-out консьюмера.

**Status:** todo.
