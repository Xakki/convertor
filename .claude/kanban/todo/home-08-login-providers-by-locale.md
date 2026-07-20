### Провайдеры логина по локали (RU → Yandex+VK; остальное → Google+GitHub+Telegram)

**Criticality:** High

**TAGS:**
- feature
- frontend
- backend

**Description:**
Часть эпика [[home-00-epic]]. Последняя подзадача цепочки, ЗАВИСИТ от
[[home-07-i18n]] (нужна серверная локаль запроса) и логически завершает
[[home-06-legal-docs]] в порядке выполнения эпика.

Сейчас `App\Controller\Web\LoginController::index()` рендерит
`templates/auth/login.html.twig` со ВСЕМИ 4 OAuth-провайдерами
(Google/GitHub/Yandex/VK, RU-first порядок) БЕЗ каких-либо условий —
`OauthProviderRegistry` регистрирует все 4 адаптера тегом
`app.oauth_provider` независимо от того, заполнен ли
`<PROVIDER>_OAUTH_CLIENT_ID` (см. докблок `LoginController`, это
осознанное решение oauth-05 — `has()` не отличает «сконфигурирован» от
«плейсхолдер пуст»). Telegram magic-link кнопка — отдельно, не через
`OauthProviderRegistry`.

**Problem:**
Показывать все 4 OAuth-провайдера + Telegram одновременно всем
пользователям — избыточно и не соответствует локальному контексту:
Yandex/VK нерелевантны non-RU аудитории, Google/GitHub/Telegram менее
привычны RU-аудитории (VK/Yandex — доминирующие ID-провайдеры в РФ).

**Impact:**
Хуже конверсия в логин из-за нерелевантных/избыточных опций входа для
конкретной аудитории.

**Recommendation:**
- Фильтрация видимых провайдеров по серверной локали текущего запроса
  (`Request::getLocale()`, устанавливается механизмом из
  [[home-07-i18n]]): `ru` → показывать ТОЛЬКО Yandex + VK; любая другая
  локаль (`en` и др.) → Google + GitHub + Telegram.
- Применяется в ДВУХ местах — держать логику фильтрации в одном месте
  (например небольшой сервис/метод, переиспользуемый обоими шаблонами, а
  не дублировать if/else в двух Twig-файлах):
  1. `templates/auth/login.html.twig` (сама страница `/login`).
  2. Пункт «Войти» в хедере (`templates/components/header.html.twig` из
     [[home-01-header-nav]]) — ЕСЛИ хедер сам показывает провайдеров (а не
     просто ссылку на `/login`, где уже фильтруется список) — сверить с
     итоговой реализацией [[home-01-header-nav]] (по D1 хедер — это просто
     ссылка на `/login`, значит фильтрация может понадобиться ТОЛЬКО на
     самой `/login`; если в ходе [[home-01-header-nav]] появился inline-
     дропдаун с провайдерами прямо в хедере — фильтровать и там).
- НЕ трогать `OauthProviderRegistry` (все 4 адаптера остаются
  зарегистрированными на бэкенде — фильтрация ТОЛЬКО на уровне того, что
  РЕНДЕРИТСЯ на странице, не какие провайдеры технически доступны;
  прямой переход по URL провайдера, не показанного для локали, должен
  по-прежнему работать — это не access-контроль, а UX-подсказка).
- Прочитать skill `redesign-auth-access-contract` (раздел «OAuth-
  провайдеры», порядок RU-first, контракт `/api/v1/auth/oauth/{provider}/
  start`) ОБЯЗАТЕЛЬНО перед изменением `LoginController`/
  `login.html.twig` — не путать фильтрацию видимости с реализацией OAuth-
  флоу (флоу не меняется).

**Acceptance Criteria:**
- `Accept-Language`/cookie-локаль `ru` (или пользователь переключил язык
  на RU через [[home-07-i18n]]) → `/login` показывает ТОЛЬКО кнопки
  Yandex и VK (без Google/GitHub/Telegram).
- Любая другая локаль → `/login` показывает Google, GitHub, Telegram (без
  Yandex/VK).
- Прямой переход по OAuth start-URL провайдера, не показанного для
  текущей локали (например `/api/v1/auth/oauth/google/start` при RU-
  локали), продолжает работать (без искусственного 403) — фильтрация
  только визуальная.
- Логика фильтрации не задублирована в 2+ местах шаблонов — общий
  источник (сервис/метод/Twig-функция).
- Tests/QA green: `make test`, `make phpstan`, `make cs-check`.

**Decisions:**
- Правило фильтрации (RU → Yandex+VK; остальное → Google+GitHub+Telegram)
  — согласовано с пользователем, см. [[home-00-epic]] Decisions, не
  открывать заново.
- Фильтрация — только на уровне рендера (UX), не access-контроль;
  `OauthProviderRegistry` и сами OAuth start/callback роуты не меняются.
