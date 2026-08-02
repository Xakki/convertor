### PHPStan не анализирует миграции и bin (стиковая лакуна в toolchain)

**Criticality:** Medium
**Epic:** [[CNV-48]]

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
1. Добавить `bin/` в paths PHPStan на level 8 + cs-fixer
2. Добавить `migrations/` на level 5 либо через baseline
3. cs для migrations — опционально позже

**Контекст:** обнаружено при реализации карточки `[[registry-03-seed-migration]]` (2026-07-22) и `[[registry-04-matrix-tooling-tests]]` (2026-07-22). Связано с `[[CNV-25-migrate-diff-schema-drift]]` — обе касаются toolchain для миграций и служебных инструментов.

**Acceptance Criteria:**
- [x] `bin/` добавлен в PHPStan paths на level 8; `make phpstan` зелёный
- [x] `bin/` добавлен в Finder php-cs-fixer; cs-check/cs покрывают `bin/`
- [x] `migrations/` под PHPStan: level 5 **или** baseline для pre-existing findings
- [x] Документировано в phpstan.neon / комментарии, почему migrations не на level 8

**Decisions:**
- `bin/` — PHPStan level 8 + cs (обязательно в этой карточке).
- `migrations/` — PHPStan level 5 **или** baseline (на выбор при реализации; цель — включить без шума от старых миграций).
- cs для `migrations/` — опционально, follow-up (не блокер этой карточки).
- **Реализовано:** migrations = **отдельный `phpstan-migrations.neon` на level 5** (не baseline). Probe показал 0 ошибок даже на L8; L5 выбран как потолок на будущее (автоген/boilerplate), без ослабления src/bin@8. `make phpstan` гоняет оба конфига.

**Work notes:**
Groomed 2026-08-01: bin@8+cs mandatory; migrations@5-or-baseline; cs migrations later.

**Status:** ready.

## Execution Log

- 2026-08-02: ветка `epic/CNV-48` подтверждена; карточка todo→progress.
- 2026-08-02: probe — migrations 0 ошибок даже на L8; bin/ — 1 ошибка (`$argv` в dump-matrix.php). Стратегия: migrations **level 5** (отдельный `phpstan-migrations.neon`), не baseline.
- 2026-08-02: правки — `phpstan.neon` (+bin@8 + комментарий), `phpstan-migrations.neon` (L5), Finder cs (+bin), `Makefile` phpstan двойной прогон, fix `$argv`→`$cliArgv` в dump-matrix.php. Probe → `backup_phpstan.cnv29-probe.neon` (не в коммите).
- 2026-08-02: `make phpstan` OK; `make cs` OK (формат dump-matrix); `make cs-check` OK. AC выполнены → test→ready.
