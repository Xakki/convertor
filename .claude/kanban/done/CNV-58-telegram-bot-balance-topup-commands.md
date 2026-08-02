### Telegram-бот: команды баланса/пополнения и понятные тексты входа

**Criticality:** High

**TAGS:**
- feature
- ux

**Description:**
Follow-up к [[CNV-28-pay-per-use-credits]]: бэкенд prepaid готов, но в боте нет
команд баланса/пополнения в меню, а тексты при «голом» `/start` и `/help`
ссылаются на «ссылку с сайта», не объясняя, что делать.

**Problem:**
1. Меню команд (`setMyCommands`) — только `start` / `help` / `convert`.
2. Голый `/start` → «Откройте бота по ссылке с сайта, чтобы войти.» — юзер не
   понимает, куда на сайте жать.
3. Пополнение только через deep-link `/start pay_<pack>` (с сайта) — из меню
   бота недоступно; баланс не показывается.

**Impact:**
После CNV-28 нельзя удобно пополнить/посмотреть баланс из бота; вход через
голого бота выглядит сломанным.

**Recommendation:**
- Только BotCommands в меню «/» (без ReplyKeyboard): добавить `balance`, `topup`.
- `/balance` — баланс + rates pay-per-use для User с `telegram_id`; иначе —
  текст + кликабельный `APP_URL/login`.
- `/topup` без аргумента — инлайн-кнопки пакетов из `TOPUP_PACKS_JSON`;
  `/topup <pack_id>` (напр. `pack_100`, `pack_850` если есть в JSON) —
  сразу invoice этого пакета; неизвестный id — понятная ошибка.
- Зарегистрировать в `TelegramSetCommandsCommand` + `make tg-set-commands`.
- Переписать голый `/start` и `/help`: явная ссылка на `/login` и next-step.
- После `successful_payment` — показать новый баланс.

**Acceptance Criteria:**
- В меню «/» есть `balance` и `topup` (BotCommands only, без ReplyKeyboard).
- `/balance` для связанного User показывает `balance_cents` (и rates);
  для несвязанного — `APP_URL/login` + инструкция входа.
- `/topup` без args — inline-кнопки всех packs из registry → invoice по клику.
- `/topup <pack_id>` — invoice выбранного пакета (id из `TOPUP_PACKS_JSON`);
  неизвестный pack_id — отказ с подсказкой списка.
- Несвязанный на `/topup` — тот же отказ с `APP_URL/login`, без silent fail.
- Голый `/start` / `/help` содержат кликабельный URL `/login` и понятный
  next-step; без «магической ссылки с сайта» без объяснения.
- После `successful_payment` в ответе бота — актуальный баланс.
- Unit/functional webhook-тесты на новые ветки; phpstan green.
- После деплоя: `make tg-set-commands`.

**Decisions:**
- (2026-08-02) Заведено как follow-up CNV-28 UX; дубликатов в канбане не было.
- Пополнение только Telegram Payments (как CNV-28); Stripe вне scope.
- (2026-08-02 grooming) Q1=A: только BotCommands, без ReplyKeyboard.
- (2026-08-02 grooming) Q2=A+: `/topup` = inline-кнопки пакетов **и**
  аргумент `/topup <pack_id>` (значение пакета из registry/`TOPUP_PACKS_JSON`).
- **(2026-08-02) Q2 OVERRIDE:** кроме pack_id разрешён произвольный `/topup <N>` —
  invoice на N Telegram Stars (XTR amount = N); min **5** ⭐, max нет;
  custom credit **1⭐ = 1¢** (как base rate pack_100, без скидки);
  `hasPack($arg)` проверяется **до** `ctype_digit`; metadata `pack_id=custom`.
- (2026-08-02 grooming) Q3=A: несвязанный → текст + кликабельный `APP_URL/login`.
- Порядок относительно CNV-59: бот можно делать независимо / раньше сайта.

## Execution Log

- (2026-08-02, Agent: php-dev) Webhook: `/balance`, `/topup` (+ `@bot`), inline `topup:<pack_id>`; тексты bare `/start`/`/help` с `APP_URL/login`; после `successful_payment` — актуальный баланс.
- (2026-08-02, Agent: php-dev) `TelegramSetCommandsCommand` + `make tg-set-commands` ##: добавлены `balance`/`topup`. Переиспользованы `TopUpPackRegistry` / `PaymentTopUpService` / `BalanceService`. Тесты в этом проходе не писались.
- (2026-08-02, Agent: test-engineer) Webhook functional + unit: `/balance` linked/unlinked, `/topup` menu/known/unknown/unlinked, callback `topup:*`, bare `/start`+`/help` login URL, `successful_payment` с балансом; `TelegramSetCommandsCommandTest` (balance+topup). Зелёный: `docker exec … phpunit` на эти файлы (19 OK). `make TEST=1 test-php` — suite red из‑за чужих фейлов (CleanTestData FK, ConversionTextInput QuotaService/BillingMode).
- (2026-08-02, Agent: php-dev) Review Major: `handleSuccessfulPayment` учитывает bool от `PaymentTopUpService` — при false тихий no-op (без «Баланс пополнен»); при true — прежнее success-сообщение. Functional: false-path + willReturn(true) на success.
- (2026-08-02, Agent: php-dev) Arbitrary Stars: `PaymentTopUpService::sendInvoiceForStars` + `MIN_TOPUP_STARS=5`; webhook `/topup <N>` (hasPack → pack, else digits → custom); UX RU min/hint; unit+functional.
