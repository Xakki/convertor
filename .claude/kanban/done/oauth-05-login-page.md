### Served-страница /login (кнопки провайдеров + Telegram)

**Criticality:** Medium

**TAGS:**
- feature
- frontend
- backend
- auth

**Description:**
Часть эпика `oauth-00-epic.md`. Общая точка входа для соцвхода, разблокирует
дальнейшую работу над личным кабинетом (`user-dashboard-page.md`), которому
нужна served-страница логина.

Scope:
- Новый Web-контроллер, отдаёт Twig-страницу `GET /login`.
- Кнопки «Continue with Google/GitHub/Yandex/VK» — обычные
  `<a href="/api/v1/auth/oauth/{provider}/start">` (full-page navigation, не
  JS-fetch), инлайн SVG бренд-иконок, Tailwind через CDN (по фронт-правилам
  проекта).
- Плюс существующая кнопка «Войти через Telegram» — переиспользовать ту, что
  уже есть на `/`, не дублировать логику.
- Обработка `?oauth_error` — вывести пользователю ошибку логина.
- NelmioApiDoc-атрибуты на новых API-эндпоинтах (`start`/`callback` из
  `oauth-01-foundation.md`), если их ещё нет.

Визуальный референс (только UX, переносить логику НЕ нужно, реализация —
Twig): `/home/xakki/proxy-service/app-front/src/routes/login.tsx`.

**Acceptance Criteria:**
- `/login` отдаётся анонимному пользователю.
- Кнопки ведут на `start`-эндпоинт каждого провайдера.
- Кнопка Telegram переиспользована (не задублирован код).
- `?oauth_error` отображается пользователю.
- `make phpstan`, `make cs-check` — зелёные.

**Decisions:**
- Full-page navigation на `start`, без JS/fetch-обёрток — OAuth-редиректы
  провайдеров всё равно требуют полной навигации браузера.

**Status:** done.

**Execution Log:**
- `App\Controller\Web\LoginController::index` — `GET /login` (`app_login`),
  рендерит `templates/auth/login.html.twig`, прокидывает `oauthError` из
  query `?oauth_error`. Под firewall `main` (lazy, catch-all) — ни один
  `access_control` в security.yaml не матчит `^/login`, страница публична
  без изменений в security.yaml.
- Шаблон `auth/login.html.twig`: 4 кнопки-ссылки
  `<a href="/api/v1/auth/oauth/{google,github,yandex,vk}/start">` с инлайн
  SVG-иконками (Google/GitHub — 1:1 из UX-референса proxy-service
  `login.tsx`; VK/Yandex — компактные брендовые бейджи), порядок VK/Yandex →
  Google/GitHub (RU-копирайт страницы). `?oauth_error=state|exchange|internal`
  → маппинг на понятный RU-текст в самом Twig (`oauthErrorMessages`),
  неизвестная причина — общий фолбэк «Не удалось войти. Попробуйте ещё раз.».
- Кнопка Telegram переиспользует ТОТ ЖЕ magic-link флоу, что
  `conversion/index.html.twig::startLogin()` (POST
  `/api/v1/auth/telegram/start` → `window.open(deep_link)`, без поллинга);
  Alpine-компонент `loginPage()` — узкий (только needed для этой страницы:
  `loggingIn`/`loginHint`/`authError`), тело `startLogin()` скопировано 1:1
  из index.html.twig, а не абстрагировано в общий JS-модуль — фронт-правила
  проекта (без сборки/npm) делают шаринг через `<script>`-инклюд неоправданно
  сложнее прямого дублирования одной функции.
- **Гейтинг по «сконфигурирован ли провайдер» — НЕ применён, все 4 кнопки
  рендерятся безусловно.** Причина: `OauthProviderRegistry` (oauth-01)
  регистрирует все 4 адаптера тегом `app.oauth_provider` в
  `config/services.yaml` независимо от того, пуст ли
  `<PROVIDER>_OAUTH_CLIENT_ID` в env (значения — обычные `%env(...)%`
  плейсхолдеры, не условная регистрация) → `registry->has($key)` истинно
  для всех четырёх всегда, гейтинг через реестр был бы фиктивным. Заводить
  отдельный признак «сконфигурирован» в интерфейсе провайдера — вне scope
  этой карточки (карточка allow'ит этот вариант явно: «If simpler, render
  all four unconditionally — acceptable for this iteration»).
- NelmioApiDoc на `OauthController::start`/`callback` — уже полностью
  документированы в oauth-01 (`OA\Tag`, `OA\Get`, `OA\Parameter`,
  `OA\Response` 302/404) — правок не потребовалось.
- Тесты `tests/Functional/Controller/Web/LoginControllerTest.php` (3 новых,
  зелёные): 200 + все 4 provider-ссылки + переиспользованная Telegram-кнопка
  для анонима; `?oauth_error=state` → баннер с понятным текстом; неизвестная
  причина → фолбэк-текст.
- Гейт: `make phpstan` — 0 ошибок; `make cs` + `make cs-check` — чисто;
  `make test-php-live` — 306/306 тестов зелёные (включая 3 новых).
