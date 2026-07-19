### Дрейф доков: несуществовавший pairing/poll в Telegram-логине

**Criticality:** Low

**TAGS:**
- docs
- tech-debt

**Description:**
При разведке под эпик `oauth-00-epic.md` (мультипровайдерный OAuth)
обнаружен дрейф документации: skill `redesign-auth-access-contract`
(раздел «Решения», строка ~12) и DONE-карточки
`done/telegram-bot-login-flow.md` + `done/upload-ui-bot-auth-rework.md`
по-прежнему описывают модель «pairing + poll (cross-device)» с эндпоинтом
`GET /api/v1/auth/telegram/poll`, который **никогда не был поставлен** —
фактический код реализует same-device magic-link без поллинга.
`done/hardening-07-e2e-login-helper.md` подтверждает этот пивот (переход от
poll-модели к magic-link).

**Open question:**
Чинить устаревшие доки/карточки на месте, или оставить DONE-карточки как
исторический артефакт и поправить только живой skill?

**Decisions:**
Не принято — вопрос открыт, решение за груминг-сессией.

**Status:** grooming.
