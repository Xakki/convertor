### В репозитории отсутствует CI-pipeline (все тесты запускаются только вручную)

**Criticality:** High
**Epic:** [[CNV-48]]

**TAGS:**
- tech-debt
- ci-cd
- quality-gates

**Description:**
Репозиторий не имеет никакой CI/CD конфигурации — ни `.github/workflows`, ни `.gitlab-ci.yml`, ни других конфигов для GitHub Actions, GitLab CI, CircleCI, Jenkins, Drone, Bitbucket Pipelines или других систем. Каждый тестовый таргет (`make phpstan`, `make cs-check`, `make test`, `pytest`) полностью зависит от дисциплины разработчика и запускается только вручную (если вообще запускается).

**Problem:**
В период 10–22 июля 2026 г. в коммите `2105d70` (auth-фича) посторонним разработчиком был удалён файл `app-symfony/bin/dump-matrix.php`. Вслед за этим тест `workers/tests/test_routing_drift.py`, который проверяет синхронизацию матрицы форматов между PHP и Python воркерами, молча **скипался в течение ~12 дней** вместо того, чтобы упасть с ошибкой отсутствующего файла. Никто этого не заметил, потому что тест никто не запускал автоматически. Проблему обнаружили только при реализации карточки `[[registry-04-matrix-tooling-tests]]` (2026-07-22).

Эта конкретная проблема была исправлена, но фундаментальный дефект сохраняется: любая защита (drift-тесты, golden-фикстуры, PHPStan анализ, code-style проверка) держится целиком на том, что кто-то вспомнит её запустить.

**Impact:**
- Регрессии в quality-гейтах (PHPStan, cs-check, PHPUnit, pytest) остаются незамеченными до merge/deploy
- Drift-тесты (routing-sync, matrix-sync, schema-validate) не гарантируют корректность между компонентами
- Опасность скрытых ошибок типов, стиля кода и логики на staging/production
- Нет сигнала о проблемах перед объединением в main
- Риск нарушения контрактов между PHP API и Python воркерами

**Recommendation:**
1. GitHub Actions workflow на каждый PR
2. Блокирующие гейты: `make phpstan`, `make cs-check`, `make TEST=1 test-php`, `make TEST=1 test-python`, `make TEST=1 test-drift`
3. e2e / integration — warn-only (не блокируют merge)
4. Live docker test-проект как у `make test` (`xakki-convertor-test`)
5. Документировать гейты в README

**Acceptance Criteria:**
- [x] GitHub Actions workflow запускается на каждый PR
- [x] Блокирующие checks: phpstan + cs-check + `TEST=1` test-php + test-python + test-drift
- [x] e2e / integration — warn-only (не блокируют)
- [x] CI поднимает live docker test-проект по образцу `make test`
- [x] Failing blocking checks не дают merge в main — *документировано: required check `Quality gates` в branch protection (Settings → Branches); YAML сам merge не блокирует*
- [x] Статус pipeline виден в PR; логи доступны — *стандарт GHA Checks*
- [x] Документация о гейтах в README или ROADMAP

**Decisions:**
- CI = GitHub Actions.
- Блокирующие гейты: `phpstan` + `cs-check` + `TEST=1 test-php` + `TEST=1 test-python` + `TEST=1 test-drift`.
- e2e и integration — только warn (не блокируют).
- В CI — live docker test-проект как у `make test` (изолированный compose-проект со своей БД).
- Pre-push хук — вне scope этой карточки.
- Расширение PHPStan на `migrations/`/`bin/` — отдельно (`[[CNV-29-phpstan-skips-migrations]]`).
- CI: `COMPOSE_FILE` без fluent-logging; Harbor secrets обязательны для gates; host pytest через `.venv-ci` (PEP 668).

**Work notes:**
Groomed 2026-08-01: GHA + blocking gates + live test stand; e2e/integration warn-only.

**Status:** ready.

## Execution Log

- 2026-08-02: todo→progress. Inspected Makefile/`TEST=1`/`.env.test`.
- Job graph: **gates** (blocking) vs **warn-e2e** (`continue-on-error`). Harbor login + `TEST=1 pull` + `test-up`; no fluent on GHA; PUID/PGID from runner.
- Composer: `.github/workflows/ci.yml` + README CI section.
- Fix: pytest via `python3 -m venv .venv-ci` (PEP 668 blocks `--user` pip on ubuntu-latest).
- Local validation: `pyyaml` parse OK; `make docker-check` OK; actionlint/yamllint not installed.
- Residuals: full GHA run not executed here; branch protection must be set in GitHub UI; parallel jobs may stress runner disk (libreoffice images); Harbor secrets must be configured before first green PR.
