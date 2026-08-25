### Payments integration (docs phase 4)

**Criticality:** Medium

**STATUS: FROZEN** (user decision 2026-06-18) — монетизация отложена. На текущем этапе сервис **бесплатный, но с лимитами** (модель лимитов уточняется отдельно). Платёжные секреты (Stripe/Cryptomus/Telegram Stars) НЕ требуются для boot. Разморозить при возврате к монетизации.

**TAGS:**
- feature

**Description:**
From `ROADMAP.md` (Стадия 6, skeleton only): three payment gateways per project CLAUDE.md — Telegram Stars, Stripe Checkout (KZ card), Cryptomus (USDT/BTC, RU-accessible).

**Problem:**
- Telegram Stars: bot invoice → `successful_payment` webhook, signature verify.
- Stripe: Checkout session, webhook handling, KZ card validation.
- Cryptomus: REST v1 integration, webhook verify, status polling.
- Pricing page: wire buttons to live gateways, success/cancel flows.
- Secrets (`STRIPE_*`, `CRYPTOMUS_*`, `TELEGRAM_BOT_TOKEN`) are empty in `.env` (see [[fix-configs-working-state]]).

**Impact:**
No monetization path; pricing UI is non-functional.

**Recommendation:**
Карточка остаётся замороженной: не начинать реализацию до отдельного решения о разморозке; после него реализовать каждый gateway за общей payment abstraction, проверить webhooks и сверять quota/credit при успехе.

**Acceptance Criteria:**
- Карточка остаётся замороженной, пока deferred considerations не будут отдельно рассмотрены и не будет принято решение о разморозке.
- После разморозки каждый gateway completes a test-mode payment and credits the user.
- После разморозки webhook signatures verified; idempotent processing, а pricing page initiates real checkouts with success/cancel handling.

**Unresolved / deferred considerations (not approved; resolve before reactivation):**
- Which gateway is the MVP priority (Telegram Stars likely first)?
- Test/sandbox credentials availability for Stripe/Cryptomus?
- Credit model: per-conversion credits, subscription tiers, or both?
- Split into one card per gateway when moving to todo?

**Decisions:**
- **MVP-платёж — только Telegram Stars** (2026-06-20, см. [[ROADMAP]] стадия 6). Stripe/Cryptomus — позже, вне MVP. YooMoney исключён из планов.
