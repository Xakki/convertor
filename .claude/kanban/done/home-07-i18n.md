### i18n-фундамент: Symfony translator, детект локали, переключатель языка

**Criticality:** High

**TAGS:**
- feature
- backend
- frontend

**Description:**
Часть эпика [[home-00-epic]]. Делается ПЕРВОЙ из 8 подзадач — фундамент, на
который опираются [[home-06-legal-docs]] (EN/RU легал-тексты) и
[[home-08-login-providers-by-locale]] (провайдеры логина зависят от серверной
локали), а также все остальные подзадачи эпика, которые заводят строки на
странице сразу как ключи перевода, а не хардкод.

Сейчас в проекте i18n НЕТ вообще: нет `config/packages/translation.yaml`, нет
каталога `translations/`, в `config/packages/framework.yaml` не выставлены
`framework.default_locale` и `framework.set_locale_from_accept_language`.
Весь текст на `conversion/index.html.twig` и `auth/login.html.twig` — хардкод
на русском.

**Problem:**
Без i18n-инфраструктуры остальные подзадачи эпика либо блокируются, либо
задваивают работу (сначала хардкодят RU-строки, потом выносят их в ключи).
Отсутствие переключения языка ограничивает сервис русскоязычной аудиторией.

**Impact:**
Блокер для [[home-06-legal-docs]] и [[home-08-login-providers-by-locale]];
риск рефакторинга задним числом остальных 6 подзадач эпика, если сделать
i18n позже них.

**Recommendation:**
- Включить/сконфигурировать Symfony translator (`symfony/translation`
  транзитивно уже в `composer.lock`, проверить нужен ли `composer require
  symfony/translation` как прямая зависимость — сейчас её нет в
  `app-symfony/composer.json`).
- `config/packages/translation.yaml`: `default_locale: en`, `fallbacks:
  ['en']`, `paths: ['%kernel.project_dir%/translations']`.
- `config/packages/framework.yaml`: добавить `default_locale: 'en'` в блок
  `framework:`; `set_locale_from_accept_language: true` — это встроенный
  механизм Symfony 7.3+ (см. `app-symfony/config/reference.php:136`),
  используем его вместо кастомного EventListener, если версия Symfony это
  позволяет (проверить `composer.json` на точную версию `symfony/framework-
  bundle` перед использованием — если версия ниже, где опция появилась,
  делать через кастомный `LocaleListener`).
- Персист выбора локали в cookie: если юзер явно переключил язык
  переключателем — записать cookie (например `locale`, httpOnly НЕ обязателен
  т.к. не секрет, но `SameSite=Lax`), и на последующих запросах отдавать
  приоритет cookie над `Accept-Language`. Порядок приоритета: явный
  query-параметр переключателя (если есть) → cookie → `Accept-Language` →
  default `en`.
- Создать `app-symfony/translations/messages.en.yaml` и
  `messages.ru.yaml` (или `.xlf` — выбрать один формат и придерживаться его
  во всём проекте; `.yaml` проще для ручного редактирования небольшого
  словаря MVP).
- Видимый переключатель языка (компонент в хедере/футере — координировать с
  [[home-01-header-nav]], чтобы не делать два разных переключателя).
- НЕ переводить весь текст сайта в этой подзадаче — завести инфраструктуру +
  перевести то, что уже есть на `conversion/index.html.twig` и
  `auth/login.html.twig` на момент выполнения (остальные подзадачи заводят
  свои ключи сами по мере реализации).

**Acceptance Criteria:**
- Новый анонимный визит без cookie: заголовок `Accept-Language: ru` даёт
  RU-версию страницы, `Accept-Language: fr` (или отсутствие) — EN (default).
- Переключатель языка на странице переключает локаль и ставит cookie;
  повторный визит без `Accept-Language` учитывает cookie.
- `conversion/index.html.twig` и `auth/login.html.twig` не содержат хардкод
  RU-строк — все текстовые узлы через `{{ 'key'|trans }}` / `trans()`.
- `translations/messages.en.yaml` и `messages.ru.yaml` существуют и валидны.
- Tests/QA green: `make test`, `make phpstan`, `make cs-check`.

**Decisions:**
- EN по умолчанию + RU, детект по `Accept-Language` (НЕ User-Agent),
  персист в cookie, видимый переключатель — согласовано с пользователем,
  см. [[home-00-epic]] Decisions.
- Делается первой подзадачей эпика, чтобы остальные 7 подзадач сразу заводили
  ключи перевода.
