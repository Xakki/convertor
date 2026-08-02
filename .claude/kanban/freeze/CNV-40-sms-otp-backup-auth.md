### SMS OTP как резервный auth (SMSC.ru)

**Criticality:** Low

**TAGS:**
- feature

**Описание:**
Выделено из docs-prod-polish (Stage 6 split, 2026-07-11). Стадия 6 (production polish),
не срочно.

SMS OTP как **резервный** способ аутентификации (fallback к Telegram-логину). Провайдер —
**SMSC.ru**. Сейчас в коде заглушка, возвращающая 501.

**Проблема:**
SMS OTP не реализован (заглушка 501) — нет резервного пути логина, если Telegram недоступен.

**Влияние:**
Пользователь без Telegram не может войти; нет запасного канала auth. Низкий приоритет
(Telegram-логин — основной).

**Recommendation:**
- Интеграция SMSC.ru API: отправка OTP, верификация кода, TTL/лимит попыток.
- Заменить заглушку 501 на рабочий флоу.

**Acceptance Criteria:**
- SMS OTP работает как резервный auth: отправка кода через SMSC.ru + верификация.
- Заглушка 501 убрана.
- Tests/QA green: `make phpstan`, `make cs-check`, PHPUnit.

**Decisions:**
- SMS-провайдер = **SMSC.ru** (из docs-prod-polish).
- SMS OTP — именно **резервный** auth (основной — Telegram-логин).

**Status:** todo (Stage 6, не срочно).
