### Harbor: retention-политика + расписание GC для проекта convertor

**Критичность:** Medium — не сломано, но место на диске Harbor-хоста (saNl) утекает
необратимо: удаление тега БЕЗ GC не освобождает ни байта.

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

Замеры на 2026-07-30: на saNl (хост Harbor) свободно **30 ГБ из 99 (70% занято)**. У
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
4. Зафиксировать «свободно на saNl до/после» — это база для оценки, хватает ли
   retention=3.

**Проверка (definition of done)**

- Administration → Clean Up → History: последний GC-прогон `Success`, показан
  освобождённый объём.
- В репозиториях проекта `convertor` не больше 3 тегированных версий, untagged
  отсутствуют.
- Записана цифра свободного места на saNl после GC.
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

**Status:** todo (выделено из harbor-published-worker-images 2026-07-31).
