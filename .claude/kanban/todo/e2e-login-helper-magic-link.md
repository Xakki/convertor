### Перевести e2e login-хелпер с obsolete widget-эндпоинта на новый auth-флоу

**Критичность:** Medium

**TAGS:**
- test
- auth

**Описание:**
После редизайна auth (magic-link через бота) `ConversionApiIntegrationTest::testPublicApiConversionEndToEnd` (4 кейса) падают в `login()`: хелпер логинится через **obsolete** `POST /api/v1/auth/telegram` (widget-HMAC), который снят с эксплуатации (пустой `TELEGRAM_BOT_TOKEN` в dev, нет e2e-стека) → 401 до исполнения тестируемого кода.

**Проблема:**
- Новый флоу (magic-link) требует реального Telegram round-trip — в e2e его не воспроизвести напрямую.
- Виджет-эндпоинт `POST /api/v1/auth/telegram` помечен к удалению (карта [[upload-ui-bot-auth-rework]] / контракт «Устаревшее»). После удаления хелпер сломается окончательно.

**Decisions (2026-07-11):** JWT прямо в фикстуре.
- В setup теста `ConversionApiIntegrationTest` генерировать `issueFamily`+JWT для сид-юзера напрямую (минуя HTTP-логин), обновить `login()`-хелпер на этот путь. НЕ вводить test-only HTTP-эндпоинт (ноль prod-поверхности).
- Согласовать с удалением obsolete виджет-эндпоинта `POST /api/v1/auth/telegram` (карта [[upload-ui-bot-auth-rework]]) — не оставлять висящих ссылок.
- Отдельные e2e для самого magic-link callback с мок-ботом ВЫНЕСЕНЫ отдельной grooming-картой [[e2e-magic-link-callback-mockbot]]; эту карту не блокируют.

**Контекст:** выявлено при ре-ревью magic-link rework эпика auth-redesign (2026-07-10).

**Status:** grooming.
