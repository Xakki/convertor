### Текст 403 auth_required гейта ai/video всё ещё называет Telegram явно

**Criticality:** Low

**TAGS:**
- tech-debt
- copy
- frontend
- backend

**Описание:**
`App\Service\Conversion\ConversionManager::checkAuth()`
(`app-symfony/src/Service/Conversion/ConversionManager.php:91`) бросает
`AuthRequiredException('Войдите через Telegram для ai/video конвертаций')`,
которая летит в `ConversionController` как
`{"error":"auth_required","message":"Войдите через Telegram ..."}` (403).

Это захардкоженная RU-строка (не через translator) — и с home-01-header-nav
она разошлась с фронтом: Twig-ключ `home.ai_gate_message` (шапка/страница `/`)
уже обобщён — «Sign in is required…» / «Для ai/video конвертаций нужен
вход.» — БЕЗ упоминания Telegram, т.к. гейт теперь ведёт на `/login`, где
Telegram — лишь один из нескольких провайдеров (VK/Yandex/Google/GitHub +
Telegram). API-текст остался старым и вводит в заблуждение (называет только
Telegram, хотя доступны и другие провайдеры).

**Задача:**
Привести `AuthRequiredException`-сообщение в `ConversionManager` в соответствие
(убрать «через Telegram», обобщить под мультипровайдерный `/login`) —
опционально пропустить через translator, если у API-ошибок уже есть i18n-
прецедент, иначе просто переформулировать хардкод. Свериться, не завязаны ли
существующие тесты (`ConversionController`/`ConversionManager` тесты) на
точный текст сообщения — при правке синхронно поправить и их.

**Контекст:**
Найдено в ходе home-01-header-nav (шапка/навигация): фронтовая копия геймта
ai/video была обобщена под мультипровайдерный `/login`, backend-текст той же
ошибки — не тронут (вне скоупа карточки, чисто header/nav).

**Status:** grooming.
