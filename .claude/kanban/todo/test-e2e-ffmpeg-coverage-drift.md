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

**Related:**
- `test-api-integration` (Makefile, строка 340) — имеет ffmpeg-покрытие через PHPUnit (group=integration), но это API-уровень, не WS-транспорт.

**Acceptance Criteria:**
- [ ] `##` help для `test-e2e` в Makefile отражает реальность (data workers / csv→json; без ложного ffmpeg)

**Decisions:**
- Сейчас: только исправить help в Makefile (описание = фактическое покрытие data workers).
- Реальный ffmpeg WS/S3 e2e — follow-up post-MVP (отдельная карточка позже; не в scope этой).

**Work notes:**
Groomed 2026-08-01: scope = Makefile help fix only; ffmpeg e2e deferred post-MVP.

**Status:** todo.
