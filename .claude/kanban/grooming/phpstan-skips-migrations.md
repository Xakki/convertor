### PHPStan не анализирует миграции и bin (стиковая лакуна в toolchain)

**Criticality:** Medium

**TAGS:**
- tech-debt
- migrations
- static-analysis

**Description:**
В `app-symfony/phpstan.neon` анализ ограничен только папкой `src/`:
```
paths:
    - src
```
Папки `migrations/` и `bin/` полностью исключены из статического анализа PHPStan и из проверки code-style (`.php-cs-fixer.php` также содержит только `src` и `tests`). Между тем, миграции содержат hand-written SQL и логику на PHP (примеры: `migration Version20260520.php` с guard от дубликатов, `Version20260521.php` со статическими seed-данными). Файлы в `bin/` содержат служебные утилиты на PHP (примеры: `bin/dump-matrix.php` для восстановления fixture'ов). Обе категории файлов требуют анализа, особенно миграции, которые выполняются против production-данных при развёртывании.

**Problem:**
Миграции и bin-утилиты — компоненты проекта, которые не проходят через PHPStan gate и не проверяются code-style-фиксером. Их единственная защита — тесты, которые не всегда их упражняют. Ошибки в SQL или логике миграций обнаруживаются только в production, когда уже поздно. Утилиты в `bin/` проверяются вручную (обнаружено 2026-07-22 при восстановлении `bin/dump-matrix.php` в `[[registry-04-matrix-tooling-tests]]`).

**Impact:**
- Риск неловленных синтаксических ошибок в SQL миграциях
- Ошибки типов в PHP-коде миграций (логика обработки данных)
- Потенциальная несогласованность схемы БД
- Опасность для production-deployment

**Recommendation:**
1. Добавить `migrations/` и `bin/` в paths PHPStan
2. Добавить `bin/` в Finder для php-cs-fixer
3. Подумать о baseline-файле для поглощения шума из старых миграций
4. Применить cs-check/cs-fix также к обеим директориям

**Контекст:** обнаружено при реализации карточки `[[registry-03-seed-migration]]` (2026-07-22) и `[[registry-04-matrix-tooling-tests]]` (2026-07-22). Связано с `[[migrate-diff-schema-drift]]` — обе касаются toolchain для миграций и служебных инструментов.

**Open questions:**
- (a) Добавить `migrations/` и `bin/` в paths на том же уровне PHPStan (`level: 8`), или нужна сниженная level (могут быть шумные findings в generated stubs)?
- (b) Нужен ли baseline-файл, чтобы абсорбировать pre-existing findings из старых миграций перед добавлением в gate?
- (c) Нужно ли также расширить cs-check/cs-fix на обе директории одновременно, или отдельно?

**Status:** grooming.
