# Как не допускать дрифт DB ↔ Doctrine entities

Краткий регламент после CNV-25 (`make migrate-diff` снова должен быть чистым на свежей/синхронизированной БД).

## Правило

Любое изменение схемы — **пара**: entity-маппинг + миграция. Нельзя править только одно.

1. Меняешь `src/Entity/*` (колонка, индекс, unique, тип) → сразу миграция.
2. Предпочитай `make migrate-diff` **после** правок entity — на БД, уже на последней версии миграций.
3. Перед коммитом миграции проверь:
   - `make console CMD='doctrine:schema:validate'` — Mapping OK + Database sync;
   - повторный `make migrate-diff` → «No changes detected» (не коммить пустой/паразитный файл).
4. Индексы и unique, заведённые руками в SQL, **обязаны** быть на entity:
   - `#[ORM\Index(name: '…', columns: […])]`
   - `#[ORM\UniqueConstraint(name: '…', columns: […])]`
   - имя = как в БД (`IDX_*` / `UNIQ_*` / `FK_*`), иначе diff предложит RENAME в hash Doctrine.
5. Не вычищай из feature-миграции «чужой» дрифт молча — либо включи в catch-up отдельной задачей, либо сначала синхронизируй стенд (`make migrate`). Hand-written SQL ок, если diff тянет несвязанное; тогда в шапке миграции явно напиши, что отфильтровано и почему.

## Типичные источники шума

| Симптом в diff | Причина | Что делать |
|---|---|---|
| `RENAME INDEX … TO IDX_/UNIQ_<hash>` | индекс только в SQL, нет атрибута на entity | добавить `Index`/`UniqueConstraint` с реальным именем; `unique: true` на Column заменить на `UniqueConstraint` |
| `DROP INDEX IDX_…` | индекс в БД не описан в mapping | либо объявить на entity, либо осознанный DROP в миграции |
| `CHANGE … DATETIME` / снятие `COMMENT '(DC2Type:…)'` | DBAL 4 не пишет type-comments | catch-up один раз; новые миграции без DC2Type-комментариев |
| `CHANGE … TINYINT` / снятие `DEFAULT` | boolean/дефолты только в PHP | не возвращать display-width/`DEFAULT` в SQL «для красоты» |

## Стенды

- Опасные прогоны (`migrate`, эксперименты с diff) — предпочтительно `make TEST=1 …`.
- Dev-БД после merge миграций: `make migrate`, затем validate/diff.
- CI empty-diff гейт — отдельно (CNV-26), не заменяет локальную проверку.

## Чеклист перед merge ветки с миграцией

- [ ] `make migrate` (или `TEST=1`) без ошибок
- [ ] `doctrine:schema:validate` зелёный (mapping + sync)
- [ ] `migrate-diff` пустой
- [ ] новые индексы/unique названы явно на entity
