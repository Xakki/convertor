### PHPStan не анализирует миграции (стиковая лакуна в toolchain)

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
Папка `migrations/` полностью исключена из статического анализа PHPStan. Между тем, миграции содержат hand-written SQL и логику на PHP (примеры: `migration Version20260520.php` с guard от дубликатов, `Version20260521.php` со статическими seed-данными). Эти миграции выполняются против production-данных при развёртывании.

**Problem:**
Миграции — один из немногих компонентов проекта, который не проходит через PHPStan gate. Их единственная защита — тесты, которые не всегда их упражняют. Ошибки в SQL или логике миграций обнаруживаются только в production, когда уже поздно.

**Impact:**
- Риск неловленных синтаксических ошибок в SQL миграциях
- Ошибки типов в PHP-коде миграций (логика обработки данных)
- Потенциальная несогласованность схемы БД
- Опасность для production-deployment

**Recommendation:**
1. Добавить `migrations/` в paths PHPStan
2. Подумать о baseline-файле для поглощения шума из старых миграций
3. Возможно, применить cs-check/cs-fix также к миграциям

**Контекст:** обнаружено при реализации карточки `[[registry-03-seed-migration]]` (2026-07-22). Связано с `[[migrate-diff-schema-drift]]` — обе касаются toolchain для миграций.

**Open questions:**
- (a) Добавить `migrations/` в paths на том же уровне PHPStan (`level: 8`), или нужна сниженная level для миграций (могут быть шумные findings в generated stubs)?
- (b) Нужен ли baseline-файл, чтобы абсорбировать pre-existing findings из старых миграций перед добавлением в gate?
- (c) Нужно ли также расширить cs-check/cs-fix на миграции, и если да — есть ли специальные правила для hand-written миграций?

**Status:** grooming.
