### Легал-страницы: privacy policy, terms of use, cookie-consent

**Criticality:** High

**TAGS:**
- feature
- frontend
- backend
- compliance

**Description:**
Часть эпика [[home-00-epic]]. Выполняется после [[home-05-file-viewers]] и
ЗАВИСИТ от [[home-07-i18n]] (тексты нужны на EN+RU через переводчик, не
хардкод).

Сейчас в проекте НЕТ ничего: ни privacy policy, ни terms of use, ни
cookie/GDPR-consent баннера — визуальный мок `app-front/components/
footer.html` содержит только заглушки легал-ссылок (`#`), футера в
раздаваемом Symfony-приложении вообще нет (`templates/base.html.twig` не
содержит `{% block footer %}`).

**Problem:**
Отсутствие privacy policy / terms of use — комплаенс-риск (GDPR/152-ФЗ) и
стандартное ожидание пользователей от любого публичного SaaS; отсутствие
cookie-consent — прямое нарушение GDPR при использовании guest-cookie
(`guest_id`) и cookie локали (см. [[home-07-i18n]]) для пользователей из
юрисдикций, где требуется явное согласие.

**Impact:**
Юридический риск (штрафы/жалобы по GDPR за отсутствие cookie-consent на
сайте, использующем cookie для идентификации гостя), недоверие
пользователей без публичных policy-документов.

**Recommendation:**
- Новый контроллер `App\Controller\Web\LegalController` (или расширить
  `HomeController`) с роутами `GET /privacy` и `GET /terms`, рендерящими
  Twig-шаблоны `templates/legal/privacy.html.twig` и
  `templates/legal/terms.html.twig`. Контент — статичный Twig-текст через
  i18n-ключи (`translations/messages.en.yaml`/`messages.ru.yaml`, см.
  [[home-07-i18n]]), НЕ CMS/БД-driven — MVP.
- Содержание privacy policy — минимум описать: какие данные собираются
  (guest_id cookie, OAuth-профиль при логине через `/login`, загружаемые
  файлы — автоудаление через 24ч, см. правило проекта File Handling),
  зачем (оказание услуги конвертации), сторонние обработчики (S3/MinIO,
  Telegram Bot API при логине через бота, OAuth-провайдеры Google/GitHub/
  Yandex/VK), права пользователя (удаление аккаунта/данных — сверить,
  реализовано ли это уже в проекте, если нет — сослаться как на
  ограничение MVP, не придумывать несуществующий функционал).
- Terms of use — минимум: описание сервиса, ограничения (лимиты/тиры из
  ROADMAP.md), запрет на злоупотребление (массовая автоматизация вне API-
  контракта, загрузка нелегального контента), отказ от гарантий (as-is),
  контакты.
- **Cookie-consent баннер**: Alpine.js-компонент, показывается при первом
  визите (нет consent-cookie), блокирует НЕобязательные cookie до согласия
  (в текущей архитектуре обязательные — `guest_id` для самой функции
  конвертации, locale-cookie из [[home-07-i18n]] — определить, какие из них
  строго необходимы для работы сервиса и не требуют consent по GDPR
  «strictly necessary», а какие — нет; на MVP, если ВСЕ используемые cookie
  strictly necessary, баннер может быть чисто информационным «мы используем
  необходимые cookie» со ссылкой на privacy policy, без блокирующей
  логики opt-in/opt-out — зафиксировать этот вывод явно в Execution Log
  карточки при реализации, чтобы не плодить избыточный UI).
- Ссылки на `/privacy` и `/terms` — в футере страницы (координировать с
  [[home-01-header-nav]] по месту размещения футера, если он ещё не
  заведён этой подзадачей отдельно — иначе завести
  `templates/components/footer.html.twig`, включаемый в `base.html.twig`).
- Прочитать skill `redesign-auth-access-contract` (что реально собирается
  про guest/OAuth-юзера, чтобы не написать в privacy policy то, чего нет
  в системе).

**Acceptance Criteria:**
- `GET /privacy` и `GET /terms` отдают контент на EN (default) и RU (по
  локали из [[home-07-i18n]]), доступны анонимно (без логина).
- Футер страницы (`/`) содержит рабочие ссылки на `/privacy` и `/terms`
  (не `#`-заглушки).
- Cookie-consent баннер/уведомление показывается при первом визите без
  consent-cookie; повторный визит с cookie баннер не показывает.
- Privacy policy описывает ТОЛЬКО реально собираемые данные (guest_id,
  OAuth-профиль, файлы+автоудаление 24ч) — без вымышленных пунктов.
- Tests/QA green: `make test`, `make phpstan`, `make cs-check`.

**Decisions:**
- Легал-контент — статичный Twig + i18n-ключи, не CMS/БД (MVP, см.
  [[home-00-epic]] Facts «строим с нуля»).
- Итоговый вид cookie-баннера (информационный vs блокирующий opt-in) —
  решается исполнителем по факту анализа, какие cookie сервис реально
  использует и являются ли они все «strictly necessary»; вывод фиксируется
  в Execution Log карточки.
