### Фикс: AI-форматы не доходят до реестра (теряется matrix_categories при регистрации воркера)

**Criticality:** High

**TAGS:**
- bug-fix

**Description:**
Удалённый GPU-воркер `worker-ai` регистрируется на gateway, но его AI-форматы (напр. `mp3→txt`) не отображаются в API и UI. Корневая причина: общий слой транспорта Python выкидывает поле `matrix_categories` при сборке регистрационного payload'a. На бэк-стороне это поле молча требуется для маппинга формата к категории (audio/document/…), его отсутствие приводит к отсеву пары из реестра без предупреждения.

**Problem:**
1. `workers/common/ws_client.py::_build_register_body()` собирает payload только из `routing_keys` и `matrix`, молча отбрасывая `matrix_categories` (и прочие ключи вне захардкоженного набора).
2. Бэкенд `app-symfony/src/Service/Conversion/ConversionRegistry.php:229-243` (`buildMatrixFromCapabilities`) для AI требует `matrix_categories` для маппинга from→категория. При отсутствии → `category=null` → пара молча отбрасывается (в логах php 8 warning'ов).
3. Следствие: `GET /api/v1/formats` не отдаёт AI-пары, в дропдауне на главной нет `txt`, `srt`, `vtt` для `mp3`.

**Impact:**
- Пользователи не видят доступные AI-конвертации в UI.
- Функциональность есть в воркере, но недоступна через API.
- На проде ведёт к неполной матрице форматов.

**Recommendation:**
Двухзонный фикс:
1. **Python** (`worker-ai-base`): модифицировать `workers/common/ws_client.py::_build_register_body()` для форвардинга `matrix_categories` в регистрационный payload. Добавить unit-тест, проверяющий, что поле присутствует в собранном body.
2. **PHP** (`app-symfony`): в `ConversionRegistry.php` и/или `QueueController.php` добавить health-сигнал (видимый варнинг в админке `/api/v1/admin/queues` + UI в `templates/admin/queues.html.twig`), когда зарегистрированный воркер прислал неполные capabilities (AI-`matrix` есть, `matrix_categories` нет). Добавить PHPUnit-тест для проверки варнинга.

**Acceptance Criteria:**
- После передеплоя воркера `/api/v1/formats` содержит `mp3→txt/srt/vtt` с `isAi:true` и правильной категорией.
- `workers/common/ws_client.py::_build_register_body()` форвардит `matrix_categories` в payload (есть unit-тест).
- При неполных capabilities (отсутствие `matrix_categories`) админка показывает видимый варнинг вместо тихого отсева (есть PHPUnit-тест).
- `make phpstan` и `make cs-check` чисты (нет нарушений Code Style и type hints).

**Decisions:**
- Выбран двухзонный скоуп: транспорт (Python) + защита на бэке (PHP) — подтверждено пользователем.
- Деплой после фикса: пересобрать+запушить `worker-ai-base` в Harbor (`make -C workers build-ai-base push-ai-base`); на on-server — `make build` + рестарт PHP; на GPU-хосте пользователя — подтянуть свежий base, пересобрать `:cuda`/`:cpu`, передеплой.

**Затронутые файлы:**
- `workers/common/ws_client.py`
- `workers/ai/worker.py` (CAPABILITIES — источник matrix_categories)
- `app-symfony/src/Service/Conversion/ConversionRegistry.php`
- `app-symfony/src/Controller/Admin/Api/QueueController.php`
- `app-symfony/templates/admin/queues.html.twig`

**Ветка:** `task/worker-matrix-categories`

**Reference skills:** `worker-ai-image`, `backend-architecture`, `e2e-ws-transport-stack`

## Результат

Фикс развёрнут и верифицирован на-сервере:
- `mp3→txt/srt/vtt` теперь в `/api/v1/formats` с флагом `isAi:true` и правильной категорией.
- Harbor-база `harbor.xakki.ru/convertor/worker-ai-base:latest` пересобрана и запушена.
- On-server AI-воркер переведён в prod-режим.
- Распределение задач — FIFO (без изменений).
- Осталось: пользователь передеплоит GPU-хост с обновленной базой + на остальные хосты.
