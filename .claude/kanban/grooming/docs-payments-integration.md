### Payments integration (docs phase 4)

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
From `docs/plan.md` phase 4 (skeleton only): three payment gateways per project CLAUDE.md — Telegram Stars, Stripe Checkout (KZ card), Cryptomus (USDT/BTC, RU-accessible).

**Problem / scope:**
- Telegram Stars: bot invoice → `successful_payment` webhook, signature verify.
- Stripe: Checkout session, webhook handling, KZ card validation.
- Cryptomus: REST v1 integration, webhook verify, status polling.
- Pricing page: wire buttons to live gateways, success/cancel flows.
- Secrets (`STRIPE_*`, `CRYPTOMUS_*`, `TELEGRAM_BOT_TOKEN`) are empty in `.env` (see [[fix-configs-working-state]]).

**Impact:**
No monetization path; pricing UI is non-functional.

**Recommendation:**
Implement each gateway behind a common payment abstraction; verify webhooks; reconcile quota/credit on success.

**Acceptance Criteria:**
- Each gateway completes a test-mode payment and credits the user.
- Webhook signatures verified; idempotent processing.
- Pricing page initiates real checkouts with success/cancel handling.

**Open questions:**
- Which gateway is the MVP priority (Telegram Stars likely first)?
- Test/sandbox credentials availability for Stripe/Cryptomus?
- Credit model: per-conversion credits, subscription tiers, or both?
- Split into one card per gateway when moving to todo?

**Decisions:**
- (to be filled during grooming)
