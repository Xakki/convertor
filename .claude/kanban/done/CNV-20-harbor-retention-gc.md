### Harbor: retention-политика + расписание GC для проекта convertor

**Критичность:** Medium — не сломано, но место на диске Harbor-хоста (saFin) утекает
необратимо: удаление тега БЕЗ GC не освобождает ни байта.

**Status:** ready (awaiting user approval)

**TAGS:**
- harbor
- devops
- docker
- disk
- maintenance

**Описание**

Хвост задачи `harbor-published-worker-images` (смержена `77cb13f`, 2026-07-31) —
единственный невыполненный пункт её §6. Требует **админа Harbor**, анонимным API не
делается, поэтому вынесен отдельно.

Замеры на 2026-07-30 (изначально ошибочно указан saNl; Harbor-хост — **saFin**): на saFin
свободно **286 ГБ из 938 на `/home` (68% занято)**. У
репозитория `worker-ai-base` уже висело **13 untagged-артефактов**. После внедрения
pull-деплоя в `harbor.xakki.ru/convertor` публикуются 7 runnable-образов, из них
`worker-ai:*-cpu` — ~3.16 ГБ. На 2026-07-31 там уже три релиза: `0.1-7ba7330`,
`0.1-da2ccea`, `0.1-41a9592`.

Смягчающий фактор (замерено): code-only релиз добавляет единицы килобайт — тяжёлые
слои переиспользуются, Harbor дедуплицирует блобы даже между репозиториями. Место
жрут не релизы кода, а untagged-хвосты от пересборок зависимостей
(`make rebuild-workers`) и обновлений базовых образов.

**Scope**

1. **Retention-политика** проекта `convertor` (UI: Projects → convertor → Policy →
   Tag Retention → ADD RULE): repositories `**`, правило «retain the most recently
   pushed # artifacts» = **3**, tags `**`, галку «untagged artifacts» СНЯТЬ, schedule
   `Daily`.
2. **Расписание Garbage Collection** (UI: Administration → Clean Up → Garbage
   Collection): включить **Delete Untagged Artifacts**, schedule `Daily`, SAVE.
3. **Разовый `GC NOW`** — забрать накопившиеся untagged-артефакты (в т.ч. 13 у
   `worker-ai-base`).
4. Зафиксировать «свободно на saFin до/после» — это база для оценки, хватает ли
   retention=3.

**Проверка (definition of done)**

- Administration → Clean Up → History: последний GC-прогон `Success`, показан
  освобождённый объём.
- В репозиториях проекта `convertor` не больше 3 тегированных версий, untagged
  отсутствуют.
- Записана цифра свободного места на saFin после GC.
- Через несколько релизов повторно свериться, что число тегов не растёт.

**Предусловия/риски**

- Нужен доступ админа Harbor (UI). Действие пользователя, не агента.
- Retention=3 означает: откатиться можно максимум на 2 релиза назад. Если нужен
  более длинный горизонт — пересмотреть цифру ПОСЛЕ замера реального расхода.
- НЕ трогать `worker-ai-base` вслепую — это база сборки обоих AI-вариантов, включая
  cuda, который собирается локально на GPU-хосте.

**Ссылки:**
- `.claude/kanban/done/harbor-published-worker-images.md` — §6 родительской задачи.
- `.claude/kanban/grooming/CNV-44-ubook-orphaned-ai-volumes.md` — смежная уборка.
- скилл `image-build-deploy` — топология образов.

---

## Execution Log

### Подтверждение хоста Harbor (2026-08-02)

**Факт:** Harbor работает на **saFin** (`safin.variantgood.com`), не на saNl.

- На saFin (локально): контейнеры `harbor-core`, `harbor-jobservice`, `harbor-db`, `harbor-portal`, `harbor-log`; данные в `/home/soft/harbor/data` (**24 ГБ**).
- saNl (`95.211.47.43:22022`) — **не** Harbor-хост; docker есть, но Harbor там не развёрнут. Карточка и замеры 2026-07-30 ошибочно указывали saNl — исправлено.

### Свободное место ДО (df BEFORE)

| Хост | Раздел | Size | Used | Avail | Use% |
|------|--------|------|------|-------|------|
| **saFin** (Harbor) | `/` | 922G | 453G | **423G** | 52% |
| **saFin** (Harbor) | `/home` | 938G | 605G | **286G** | 68% |
| saNl (сравнение) | `/` | 99G | 70G | **30G** | 71% |

Harbor data volume: `/home/soft/harbor/data` = 24G (registry + DB + logs).

### Инвентаризация проекта `convertor` (Harbor API v2.0, 2026-08-02)

Учётные данные: `DOCKER_USER`/`DOCKER_PASS` из `.env.local` (admin). API доступен.

| Репозиторий | Tagged | Untagged | Теги |
|-------------|--------|----------|------|
| metrics-exporter | 3 | 0 | 0.1-41a9592, 0.1-7ba7330, 0.1-da2ccea, latest |
| worker-ai | 3 | 0 | 0.1-*-cpu (×3), latest-cpu |
| **worker-ai-base** | 1 | **13** | latest |
| worker-data | 3 | 0 | 0.1-* (×3), latest |
| worker-ffmpeg | 3 | 0 | 0.1-* (×3), latest |
| worker-image | 3 | 0 | 0.1-* (×3), latest |
| worker-libreoffice | 3 | 0 | 0.1-* (×3), latest |
| ws-gateway | 3 | **2** | 0.1-* (×3), latest |
| **ИТОГО** | **22** | **15** | 8 репозиториев |

Untagged-хвост: 13 у `worker-ai-base` + 2 у `ws-gateway` — кандидаты на GC NOW.

### Чеклист Harbor UI (copy-paste для админа)

> Требует доступа админа Harbor UI: https://harbor.xakki.ru  
> Агент политики **не менял** — только инвентаризация.

#### 1. Retention-политика проекта `convertor`

1. Projects → **convertor** → **Policy** → **Tag Retention** → **ADD RULE**
2. Repositories: `**`
3. Rule: **retain the most recently pushed # artifacts** = **3**
4. Tags: `**`
5. Галку **«untagged artifacts»** — **СНЯТЬ**
6. Schedule: **Daily**
7. **SAVE**

#### 2. Расписание Garbage Collection

1. Administration → **Clean Up** → **Garbage Collection**
2. Включить **Delete Untagged Artifacts**
3. Schedule: **Daily**
4. **SAVE**

#### 3. Разовый GC NOW

1. Administration → **Clean Up** → **Garbage Collection**
2. **GC NOW** (разовый прогон)
3. Дождаться **Success** в Clean Up → **History**; записать освобождённый объём

#### 4. После GC — зафиксировать

- [x] History: последний GC = Success + объём освобождён (API: id=19815, ~14 GiB)
- [x] В репозиториях `convertor`: ≤3 tagged версий, untagged = 0
- [x] `df` на **saFin** (`/` и `/home`) — AFTER-цифры в эту секцию
- [x] НЕ трогать `worker-ai-base` retention вслепую (база cuda/cpu сборок) — retention не меняли

### Harbor UI выполнен пользователем (2026-08-02)

Подтверждено: retention=3 (Daily), GC schedule Daily + Delete Untagged, GC NOW.

### Свободное место ПОСЛЕ (df AFTER, saFin)

| Раздел | Size | Used | Avail | Use% | Δ к BEFORE |
|--------|------|------|-------|------|------------|
| `/` | 922G | **452G** | 423G | 52% | Used −1G |
| `/home` | 938G | 605G | 286G | 68% | без изменений |
| `/home/soft/harbor/data` | — | **24G** | — | — | без изменений |

### Инвентаризация ПОСЛЕ GC (Harbor API v2.0, 2026-08-02)

| Репозиторий | Tagged | Untagged | Release-теги (≤3) | Moving |
|-------------|--------|----------|-------------------|--------|
| metrics-exporter | 3 | 0 | 0.1-41a9592, 0.1-7ba7330, 0.1-da2ccea | latest |
| worker-ai | 3 | 0 | 0.1-*-cpu (×3) | latest-cpu |
| **worker-ai-base** | 1 | **13** | — (только `latest`) | latest |
| worker-data | 3 | 0 | 0.1-* (×3) | latest |
| worker-ffmpeg | 3 | 0 | 0.1-* (×3) | latest |
| worker-image | 3 | 0 | 0.1-* (×3) | latest |
| worker-libreoffice | 3 | 0 | 0.1-* (×3) | latest |
| ws-gateway | 3 | **2** | 0.1-* (×3) | latest |
| **ИТОГО** | **22** | **15** | 7 реп × 3 release OK | moving OK |

**Untagged остались:** 13 у `worker-ai-base` + 2 у `ws-gateway` (как до GC).

### GC History (Harbor API `/system/gc`)

| id | Время (UTC) | Status | delete_untagged | freed |
|----|-------------|--------|-----------------|-------|
| **19811** | 2026-08-02T13:04:08 | Success | **false** | 92 268 B (~90 KiB) |
| 17110 | 2026-04-17 | Success | false | 29 150 B |
| 10161 | 2025-07-13 | Success | false | ~2.0 GiB |

Расписание GC (`/system/gc/schedule`): Daily (`0 0 0 * * *`), API `delete_untagged: false`.

**Разрыв:** пользователь включил Delete Untagged в UI, но API и инвентарь показывают
15 untagged и `delete_untagged=false` в последнем GC и в schedule. Вероятно GC NOW прошёл
до сохранения опции или опция не применилась — нужен повторный GC NOW с Delete Untagged
(проверить History в UI на освобождённые GiB).

### Definition of done — статус

| Критерий | Статус |
|----------|--------|
| History: GC Success + объём | ✅ Success (API); объём ~90 KiB (не GiB) |
| ≤3 release-тегов, untagged=0 | ⚠️ release OK; **untagged=15** |
| df AFTER на saFin | ✅ записано |
| Повторная сверка через релизы | ⏳ отложено |

### Второй проход AFTER (повторный GC NOW, 2026-08-02)

Пользователь: SAVE Delete Untagged → GC NOW. API-проверка сразу после.

#### df AFTER #2 (saFin)

| Раздел | Size | Used | Avail | Use% | Δ к AFTER #1 |
|--------|------|------|-------|------|--------------|
| `/` | 922G | 452G | 423G | 52% | без изменений |
| `/home` | 938G | **591G** | **300G** | **67%** | Used **−14G**, Avail +14G |
| `/home/soft/harbor/data` | — | **9.3G** | — | — | **−14.7G** |

#### Инвентаризация AFTER #2

| Репозиторий | Tagged | Untagged | Release (≤3) | Moving |
|-------------|--------|----------|--------------|--------|
| metrics-exporter | 3 | 0 | 3 | latest |
| worker-ai | 3 | 0 | 3 | latest-cpu |
| worker-ai-base | 1 | **0** | — | latest |
| worker-data | 3 | 0 | 3 | latest |
| worker-ffmpeg | 3 | 0 | 3 | latest |
| worker-image | 3 | 0 | 3 | latest |
| worker-libreoffice | 3 | 0 | 3 | latest |
| ws-gateway | 3 | **0** | 3 | latest |
| **ИТОГО** | **22** | **0** | OK | OK |

#### GC History — актуально

| id | Время (UTC) | Status | delete_untagged | freed |
|----|-------------|--------|-----------------|-------|
| **19815** | 2026-08-02T13:07:36 | Success | **true** | **14 997 504 297 B (~14.0 GiB)** |
| 19811 | 2026-08-02T13:04:08 | Success | false | 92 268 B (~90 KiB) |

Расписание GC: Daily, `delete_untagged: true` (API подтверждено).

**Урок:** первый GC NOW (id=19811) прошёл с `delete_untagged=false` — освободил ~90 KiB,
untagged не тронул. После SAVE + повторного GC NOW — 15 untagged удалены, ~14 GiB на диске.

### Definition of done — статус (финальный)

| Критерий | Статус |
|----------|--------|
| History: GC Success + объём | ✅ id=19815, ~14 GiB |
| ≤3 release-тегов, untagged=0 | ✅ |
| df AFTER на saFin | ✅ (проход #2) |
| Повторная сверка через релизы | ⏳ отложено |

**Status:** ready (awaiting user approval)
