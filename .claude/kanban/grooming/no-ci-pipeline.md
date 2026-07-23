### В репозитории отсутствует CI-pipeline (все тесты запускаются только вручную)

**Criticality:** High

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
1. **Выбрать CI систему** в зависимости от того, где хостится remote репозиторий (GitHub / GitLab / self-hosted на saFin)
2. **Определить набор обязательных гейтов**:
   - PHPStan (level 8, ошибки блокируют PR)
   - php-cs-fixer + cs-check (автоправка + проверка стиля)
   - PHPUnit для `app-symfony/tests/`
   - pytest для `workers/tests/`
   - Drift-тесты: `workers/tests/test_routing_drift.py` (matrix), `doctrine:schema:validate`, schema-mapping-check
3. **Рассмотреть архитектуру CI**:
   - Нужен ли live-стенд (docker + MariaDB) в CI, поскольку часть тестов требует поднятого окружения?
   - Сколько ресурсов потянет раннер (образы Docker, база данных, воркеры)?
4. **Промежуточный шаг** — пока полноценного CI нет, добавить git pre-push хук, который локально запускает минимальный набор (PHPStan + cs-check + fast unit-тесты без БД)
5. **Документировать** в README и ROADMAP, какие команды нужно запустить перед push

**Acceptance Criteria:**
- [ ] Реализована CI-конфигурация на выбранной платформе
- [ ] Гейты запускаются автоматически на каждый PR
- [ ] Failing PR не может быть объединён в main
- [ ] Статус pipeline отображается в PR (checks / status)
- [ ] Логи и результаты тестов доступны для viewing
- [ ] Документация о гейтах добавлена в README или ROADMAP
- [ ] Опционально: git pre-push хук для локального feedback до push

**Open questions:**
- (a) **Где хостится remote репозиторий?** (GitHub / GitLab / gitea на saFin / другое) — это определяет выбор CI (GitHub Actions / GitLab CI / self-hosted runner)
- (b) **Какой набор гейтов делать блокирующим**, а какой только предупреждающим? PHPStan, cs-check, PHPUnit, pytest и drift-тесты — все обязательные? Или некоторые только в full-test?
- (c) **Нужен ли live-стенд в CI** (docker + MariaDB для integration-тестов), или достаточно unit-тестов + статического анализа? Будут ли limitations раннера (CPU, RAM, timeout)?
- (d) **Нужен ли промежуточный шаг** — git pre-push хук для локального feedback, пока полноценного CI нет?
- (e) **Связь с `[[phpstan-skips-migrations]]`** — при добавлении PHPStan гейта нужно ли одновременно расширить анализ на `migrations/` и `bin/`, или это отдельная задача?

**Status:** grooming.
