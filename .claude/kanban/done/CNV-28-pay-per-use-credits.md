### Pay-per-use — поштучная оплата конверсий сверх лимитов плана

**Criticality:** Low

**TAGS:**
- feature
- payments

**Description:**
Поштучная оплата конверсий сверх лимитов плана: $0.05/конв, AI — $0.15/конв
(ROADMAP). Отдельная фича поверх тир-квот: когда пользователь упёрся в дневной
или месячный потолок тира, вместо отказа (429) он может доплатить за конверсию
из биллинг-баланса.

**Problem:**
Тир-квоты (`plan-quota-daily-monthly`) — жёсткие потолки: при превышении окна
конверсия отклоняется. Нет способа разово доплатить за конверсию сверх квоты,
не апгрейдя план.

**Impact (реализация):**
- **User**: биллинг-баланс / кредиты (поле баланса + история списаний).
- **Списание**: при конверсии сверх квоты тира — списывать стоимость
  ($0.05, AI $0.15) с баланса; при нехватке — отказ.
- **Пополнение**: только через Telegram (Stars / ЮMoney / провайдеры BotFather)
  → зачисление на баланс (Stripe/Cryptomus вне scope).
- Интеграция с `QuotaService` (ветка «квота исчерпана → есть баланс → charge
  credits вместо 429»).

**Зависимости:**
- Отложено из итерации `plan-quota-daily-monthly` (сначала тир-квоты, потом
  оплата сверх них).

**Decisions:**
Решение (grooming 2026-07-17):
1. Модель списания — **prepaid баланс**: пользователь заранее пополняет баланс,
   каждая конверсия сверх лимита плана ($0.05 обычная / $0.15 AI) списывается
   атомарно с баланса. Нет постоплаты/долгов. Согласуется с
   quota-атомарностью из conversion-chaining.
2. Провайдеры пополнения — **пока только через Telegram**: Telegram Stars +
   ЮMoney (юмани) + прочие провайдеры, доступные через BotFather/Telegram
   Payments. Отдельные Stripe/Cryptomus вне scope. Все платежи идут через
   Telegram-бота.
- (2026-08-02 start) Prepaid-конверсия НЕ инкрементит tier-счётчики плана — только атомарный debit баланса.
- Refund fail-path: при billingMode=prepaid_balance → credit баланса; при plan_quota → decrement counters (как сейчас).
- Guest (ROLE_GUEST): pay-per-use недоступен — при исчерпании квоты тот же 429, без баланса.
- Деньги: баланс User в integer USD cents; ledger append-only `balance_transactions`.
- Ошибка нехватки: HTTP 429 + JSON `{"error":"insufficient_balance"}` (квота без баланса); внутри квоты поведение без изменений.
- Top-up: пакеты в USD (presets), оплата Stars/BotFather; курс/цена пакета в конфиге (env), не live FX.
- Debit: атомарно в момент charge/reserve при over-quota (SELECT FOR UPDATE / WHERE balance>=); Conversion.billingMode обязателен.
- Stripe/Cryptomus вне scope.

**Примечание (drift, РАЗРЕШЕНО 2026-07-17):** секция Payments в CLAUDE.md
обновлена под это решение — «оплата через Telegram с несколькими провайдерами
(Stars + ЮMoney + BotFather)», pay-per-use = prepaid-баланс.

**Ссылки:**
- ROADMAP тарифы (строка Pay-per-use — $0.05/конв, AI $0.15).
- Смежная карта: `plan-quota-daily-monthly`.

**Status:** ready.

**Acceptance Criteria:**
- У User есть prepaid-баланс (денежный, USD cents или decimal — как принято в Entity Payment) и история списаний/зачислений.
- При исчерпании дневного ИЛИ месячного лимита плана конверсия НЕ даёт голый 429, если баланса хватает: атомарно списывается $0.05 (обычная) / $0.15 (AI), задача принимается.
- При нехватке баланса — отказ (429 или явный код `quota_exceeded` / `insufficient_balance` — согласовать с api-design).
- Пополнение баланса: Telegram invoice (Stars / провайдеры BotFather) → webhook `successful_payment` → идемпотентное зачисление. Stripe/Cryptomus вне scope.
- QuotaService + тесты (unit/integration) покрывают ветки: в квоте / сверх квоты+баланс / сверх квоты без баланса / refund при отмене.
- `make phpstan` + релевантные PHPUnit-тесты зелёные.

## Execution Log

- 2026-08-02: started (todo→progress), branch `task/CNV-28`; exploration of Quota/Payment/Telegram skeleton.
- 2026-08-02: exploration done; decisions locked; starting slice 1 schema.
- Slice 1 (schema): migration `Version20260802190000`, `User.balanceCents`, append-only `balance_transactions`, `BillingMode` на `Conversion`.
- Slice 2 (BalanceService): атомарный debit/credit в USD cents, `InsufficientBalanceException`, unit-тесты.
- Slice 3 (Quota wiring): ветка prepaid в `QuotaService`/`ConversionManager`, charge при исчерпании квоты, refund в DLQ.
- Slice 4 (Telegram top-up): `PaymentTopUpService`, `TopUpPackRegistry`, invoice + `successful_payment` webhook.
- Slice 5 (Payment API): `PaymentController` (topup/history), баланс в `MeController`, functional-тесты.
- Slice 6 (tests/cs): prepaid branches в Quota/Conversion/Payment PHPUnit; cs-fix импортов в DLQ и unit-тестах.
- 2026-08-02: review CHANGES_REQUESTED → fixed dispatch refund, UNIQUE external_id, orphan Failed on charge fail, functional prepaid test; QA green → ready.
