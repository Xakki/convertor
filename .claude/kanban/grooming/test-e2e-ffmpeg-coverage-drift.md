### Дрейф покрытия e2e тестов: `make test-e2e` не тестирует ffmpeg

**Criticality:** Medium

**TAGS:**
- testing
- tech-debt

**Description:**
Обнаружен дрейф между описанием таргета и реальным покрытием:
- Makefile `test-e2e` описывает себя как `## Real S3 in/out e2e for ffmpeg + data workers` (строка 314, `workers/Makefile`)
- Фактический файл `workers/tests/test_workers_e2e.py` содержит только ОДИН `@pytest.mark.e2e` тест: `test_worker_data_csv_to_json()` (строка 211)
- Этот тест покрывает ТОЛЬКО data-воркер (csv→json конвертацию), ffmpeg-воркеры (audio/video) вообще не тестируются
- Результат: `make test-e2e` проходит при заявленном покрытии ffmpeg, но реально ffmpeg e2e-контракт (WS-транспорт, S3 in/out) не верифицирован

**Найдено:** диагностический запуск локальных и удалённых воркеров 2026-07-23.

**Open question:**
Какой путь выбрать?
- **(a)** Написать реальный ffmpeg S3 in/out e2e тест (audio/video конвертация): потребует примера аудио/видео-файла в fixtures, цикл обработки через WS-gateway, валидация результата. Затраты — ~2–3 часа на каркас + отладку (зависит от нюансов ffmpeg).
- **(b)** Исправить Makefile: заменить описание на `## Real S3 in/out e2e for data workers (csv→json)` или расширить на `## Real S3 in/out e2e for data workers; ffmpeg coverage via test-api-integration` — отражает текущую реальность. Затраты — 5 минут.
- **(c)** Добавить фикстуры + тест-кейс; отложить закрытие до Stage 2 / post-MVP (не критично для текущей фазы).

**Decisions:**
Не принято — решение за код-ревью и груминг-сессией.

**Related:**
- `test-api-integration` (Makefile, строка 340) — имеет ffmpeg-покрытие через PHPUnit (group=integration), но это API-уровень, не WS-транспорт.

**Status:** grooming.
