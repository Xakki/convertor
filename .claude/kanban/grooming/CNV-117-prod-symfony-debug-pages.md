### Prod отдаёт Symfony debug-страницы с полным stack trace — APP_DEBUG на боевом стенде

**Criticality:** High

**TAGS:**
- security
- backend
- configuration
- information-disclosure
- prod-incident

**Description:**
На боевом стенде `https://convertor.xakki.pro` в ответе на недопустимые запросы (405 Method Not Allowed, 404, ошибки валидации) возвращаются полные HTML debug-страницы Symfony с исходным кодом, stack trace и путями файлов сервера вместо чистого JSON/HTML error response для prod-режима.

**Problem:**
Проверено 2026-08-16 вживую:

1. `GET https://convertor.xakki.pro/api/v1/convert/72` (DELETE-only метод)
   → 405 с полной debug-HTML-страницей Symfony (includes Stack Trace, исходники)
2. Заголовок `X-Debug-Exception-File: /app-symfony/vendor/symfony/security-http/Firewall/ExceptionListener.php:126`
   раскрывает абсолютный путь на сервере

Гипотеза: на продовом стенде активен `APP_DEBUG=1` или `APP_ENV=dev` (см. корневой `.env`).

**Impact:**
Высокий (информационная утечка):
- Раскрытие структуры кода (пути, иерархия классов, версии библиотек)
- Раскрытие server-side структуры конфигурации и путей файлов
- Stack trace с локальными переменными может содержать чувствительные данные (токены, ключи в памяти)
- Видимость для публичного запроса на боевой домен — прямая утечка в интернет

**Recommendation:**
1. Проверить боевой `.env` / `.env.local` (особенно `APP_ENV` и `APP_DEBUG`);
   на боевом стенде должно быть `APP_ENV=prod` и `APP_DEBUG=0` (или явно отсутствовать).
2. Убедиться, что при сборке образа для прода в compose не устанавливаются dev-флаги.
3. Проверить, что ошибки API (4xx, 5xx) отдаются чистым JSON (скрывая stack trace и X-Debug-* заголовки).
4. Отключить профайлер на боевом стенде (если включен).

**Acceptance Criteria:**
- `APP_ENV=prod` и `APP_DEBUG=0` явно заданы или не заданы (дефолт в prod) для боевого compose/стенда.
- 405/404/422 ошибки на API возвращают JSON без stack trace и X-Debug-* заголовков.
- Проверено вживую на `https://convertor.xakki.pro`: запрос к недопустимому методу/пути больше не содержит debug-информации.
- Нет X-Debug-Exception-File и прочих debug-заголовков в ответе.

**Open questions:** *(only for `grooming/` cards)*
- Боевой стенд переводим в `APP_ENV=prod`/`APP_DEBUG=0` — или dev-режим на
  convertor.xakki.pro оставлен намеренно (тогда закрыть debug-выдачу иначе:
  правило в nginx на срезание `X-Debug-*` + кастомный error-controller)?
- Не сломает ли переход в prod текущие рабочие процессы (профайлер, отладка
  воркеров, `make console` на боевом контейнере)?

**Decisions:**
- Карта в grooming, диагностическая; пока не нашли точную точку конфигурации.
- Проверка и фиксация должны быть выполнены пользователем или агентом-исследователем (требует доступа к боевому `.env.local`).

**Контекст:** обнаружено в ходе тестирования API 2026-08-16, возможно регрессия при миграции/переконфигурации боевого стенда.

**Status:** grooming
