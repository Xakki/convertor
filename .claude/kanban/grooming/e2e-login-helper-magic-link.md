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

**Решение (черновик, на груминг):**
- Ввести **test-only auth-путь** для e2e: напр. в `when@test` — эндпоинт/фикстура, выдающая JWT для сид-юзера без Telegram (за флагом окружения, недоступно в prod), ИЛИ прямой `issueFamily`+JWT в setup теста, минуя HTTP-логин.
- Обновить `login()`-хелпер `ConversionApiIntegrationTest` на этот путь.
- Согласовать с удалением виджета (карта C), чтобы не осталось висящих ссылок на `POST /api/v1/auth/telegram`.

**Открытые вопросы:**
- Тест-only эндпоинт vs прямая генерация JWT в фикстуре (безопасность: не должно течь в prod).
- Нужны ли e2e-кейсы для самого magic-link callback (с мок-ботом) отдельно.

**Контекст:** выявлено при ре-ревью magic-link rework эпика auth-redesign (2026-07-10).

**Status:** grooming.
