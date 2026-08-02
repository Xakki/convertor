### UI баланса и пополнения на сайте (quota/dashboard + CTA + pricing + TG link)

**Criticality:** High

**TAGS:**
- feature
- ux
- frontend

**Description:**
Follow-up к [[CNV-28-pay-per-use-credits]]: API (`/me.balance_cents`,
`/quota.balance_cents`, `/payment/packs|topup|history`) есть, на сайте
пользователь **не видит баланс и не может пополнить**. В scope: живая
страница `/pricing` в меню и **привязка Telegram** к уже залогиненному
OAuth-User (top-up требует `telegram_id`, link-флоу сейчас нет).

**Problem:**
- Виджет квоты (главная / dashboard) не рендерит `balance_cents`.
- Нет CTA «Пополнить», нет вызова `POST /api/v1/payment/topup`.
- При 429 `insufficient_balance` — общий текст, без пути к пополнению.
- `app-front/pricing.html` — мёртвый прототип (неверные `pay_*` deep-links,
  Stripe/Cryptomus stubs); twig pricing-страницы нет; в нав нет пункта.
- Ссылка «Бот» в хедере ведёт на голого `t.me/bot` (вход чинит CNV-58 —
  вне этой карты).
- OAuth-User без `telegram_id` не может пополнить; привязки TG к текущему
  аккаунту нет (bot login всегда find/create по `telegramId` → смена сессии).

**Impact:**
Prepaid из CNV-28 недоступен с сайта → баланс и pay-per-use «невидимы»;
RU OAuth-юзеры без пути к Stars/top-up.

**Recommendation:**
1. Баланс + rates в quota-bar на главной **и** на dashboard (не в хедере).
2. UI пополнения: модалка/панель (CTA из quota-bar / dashboard) → packs API →
   `invoice_link` (Telegram). Без отдельной `/billing` в MVP.
3. На `insufficient_balance` — CTA «Пополнить баланс».
4. Twig `/pricing` + пункт в хедере: карточки Free/Basic/Pro как в прототипе;
   CTA подписок disabled/«скоро» (CNV-12 не размораживать); top-up packs —
   живой Telegram-флоу.
5. «Привязать Telegram» для залогиненного User без `telegram_id`: зеркало
   login-флоу (`…/telegram/link/start` → deep-link → кнопка в боте → poll),
   nonce + текущий UserId; коллизия `telegram_id` → жёсткий отказ.
6. RU `/login`: Telegram **не** показывать (только Yandex+VK); путь к TG —
   только «Привязать» после OAuth-входа.
7. Ссылку «Бот» в хедере не менять (CNV-58).

**Acceptance Criteria:**
- Залогиненный User видит баланс (USD) в quota-bar на главной и на dashboard.
- Есть модалка/панель пополнения через реальные packs API → открытие
  `invoice_link`.
- 429 `insufficient_balance` предлагает пополнение.
- Guest — понятный отказ (войти); User без `telegram_id` — CTA «Привязать
  Telegram», не silent fail.
- Link-флоу: `POST` start (auth required) → deep-link в бота → confirm →
  poll; `telegram_id` пишется на **текущего** User; сессия не прыгает на
  другой аккаунт.
- Если `telegram_id` уже занят другим User — отказ без merge (понятное
  сообщение).
- `/pricing` (Twig) в нав-меню: карточки Free/Basic/Pro; checkout подписок
  disabled/«скоро»; top-up через Telegram packs (без Stripe/Cryptomus stubs).
- RU `/login` по-прежнему без Telegram-провайдера; привязка только из
  залогиненного UI.
- Переводы RU/EN; phpstan/cs; ручной smoke.
- `app-front/pricing.html` — удалить или deprecated, не конкурирует с Twig.

**Decisions:**
- (2026-08-02) Заведено как follow-up CNV-28; CNV-12 freeze не трогать.
- MVP-платёж = Telegram only (как Decisions CNV-28).
- (2026-08-02 grooming) Q4=A: баланс в quota-bar на главной и на dashboard
  (не в хедере).
- (2026-08-02 grooming) Q5=A: модалка/панель, не отдельная `/billing`.
- (2026-08-02 grooming) Q6=B: привязка Telegram к существующему OAuth-User
  в этой карточке.
- (2026-08-02 grooming) Q7: живая `/pricing` + пункт в нав-меню.
- (2026-08-02 grooming) Q8=A: ссылку «Бот» не менять (зона CNV-58).
- (2026-08-02 grooming) Q9=A: коллизия `telegram_id` — жёсткий отказ, без
  merge аккаунтов.
- (2026-08-02 grooming) Q10=A: UX привязки = зеркало login (start → bot
  button → poll, nonce bound to current UserId).
- (2026-08-02 grooming) Q11=B: на RU `/login` Telegram скрыт; только
  «Привязать» после Yandex/VK.
- (2026-08-02 grooming) Q12=B: `/pricing` = карточки Free/Basic/Pro как в
  прототипе, CTA подписок disabled/«скоро»; packs top-up живые.
- Зависит от packs/invoice API (CNV-28 ready); CNV-58 желателен раньше,
  сайт на командах бота не блокируется.
