### Хардненинг провижининга тест-БД (находки ревью ci-test-db-provisioning)

**Критичность:** Low

**TAGS:**
- chore
- ci
- tests

**Описание:**
Находки авто-ревью задачи [[ci-test-db-provisioning]] (2026-07-10). Не баги happy-path
(основной флоу `make test-db-setup` + `test-php-live` проверен и работает), а
completeness/coupling-доработки. Вынесены отдельно, вне AC исходной карточки.

**Находки:**
1. **(altitude) Канонический `make test` / голый `test-php` по-прежнему не провизионят
   БД** → live DB/KeyDB тесты молча скипаются на свежем окружении (тот самый false-green,
   ради которого заводилась карточка). Фикс закрыт только для тех, кто знает про новый
   `test-php-live`. Вопрос: делать ли `make test` зависимым от `test-db-setup` (минус —
   каждый прогон поднимает docker+provision, даже unit/python), или задокументировать
   `test-php-live` как канонический CI-таргет и оставить `make test` быстрым.
2. **(correctness, latent) Пароль тест-юзера раздвоен:** `create-test-db.sh` берёт из
   `DB_TEST_PASS` (фолбэк 123456), а `app-symfony/.env.test` DATABASE_URL хардкодит
   `123456`. Если кто-то переопределит `DB_TEST_PASS≠123456` — провижининг создаст юзера
   с новым паролем, а phpunit подключится со старым → access denied. Сейчас совпадает
   случайно. Развести один источник правды либо задокументировать связь.
3. **(correctness, minor) `test-php-live: test-db-setup test-php`** — порядок prerequisites
   не гарантирован при `make -j`. Добавить order-only барьер или объединить в один рецепт,
   если параллельный make вообще актуален.

**Decisions (2026-07-11):**
- Находка 1: `make test` остаётся быстрым (unit/python); `test-php-live` — канонический
  CI-таргет для live DB/KeyDB тестов. НУЖНО задокументировать это (комментарий в Makefile
  у таргета `test-php-live` и/или раздел про тесты в README).
- Находка 2: зафиксировать пароль тест-юзера `123456` как единый источник; убрать
  `DB_TEST_PASS`-фолбэк в `docker/mariadb/dev/init/create-test-db.sh` как источник
  рассинхрона (test-only, `.env.test` трекается).
- Находка 3: order-only барьер при `make -j` остаётся в scope как есть.

**Files:**
- `Makefile` (`test`, `test-php-live`)
- `docker/mariadb/dev/init/create-test-db.sh`
- `app-symfony/.env.test`

**Контекст:** находки ревью [[ci-test-db-provisioning]]; вне AC той карточки.
