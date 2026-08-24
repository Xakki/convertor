### Static SVG: backend profile и catalog

**Criticality:** High

**TAGS:**
- feature
- images
- svg
- backend
- conversion-options

**Description:**
Добавить в backend catalogue profiles для статичных SVG → GIF/BMP/TIFF/ICO и серверную validation их options.

**Problem:**
Без точного profile API может опубликовать worker target с неподдерживаемыми settings или пропустить невалидные options в job.

**Impact:**
Клиенты увидят неверный contract, а worker получит неоднозначные параметры legacy-output.

**Recommendation:**
После CNV-85 назначить profile точным SVG target pairs: публиковать только static semantics и разрешённые текущим pipeline width/height; нормализовать options в job. Не публиковать FPS, duration, loop, palette/dither или animation controls.

**Acceptance Criteria:**
- `/formats` возвращает SVG → GIF/BMP/TIFF/ICO только с profile, совместимым со статичным image-worker.
- Backend принимает только разрешённые profile fields и нормализует их до job payload.
- Animation-specific keys и options неподдерживаемых pairs отклоняются предсказуемо.
- API/contract tests покрывают catalog, valid и invalid options.

**Decisions:**
- Зависит от CNV-85; worker CNV-75 и frontend CNV-96 начинаются после profile.
- GIF в этой карточке только статичный; animated SVG принадлежит CNV-106/CNV-82.
- Форматы доступны всем пользователям; ограничений плана для них нет.

---

## Execution Log (backend, 2026-08-24)

### Corrective, не additive — что было не так

CNV-75 добавила 4 пары (`svg→{bmp,gif,ico,tiff}`, 398→402), но НЕ добавляла
профиль — все 4 молча попали под общий catch-all `image.raster` (`category:
image, ocr:false`, без `to`/`from`), унаследовав `width`/`height`. Ревью
(эмпирически, конвертацией реальных файлов) нашло два расхождения с тем, что
worker реально применяет:
1. `svg→ico` — `width`/`height` **инертны** (`_save_svg_ico()` намеренно
   пропускает `_apply_image_options()`, CNV-75) — рекламировались, хотя
   ничего не делают.
2. `svg→bmp` — worker **композитит** прозрачность на `options["background"]`
   (`_save_svg_bmp()`, дефолт `#FFFFFF`), но `background` нигде не
   рекламировался (только `image.jpeg` его несёт).

### JSON-only — extension point подтверждён

`git diff --stat` — только `conversion_settings.json` + 4 тестовых файла. Ни
один PHP-класс не менялся.

### Ключевая сложность: профиль не может иметь пустой `fields`

`SettingsProfile::fromArray()` требует непустой `fields` (`RuntimeException`
иначе), а `parseAssignments()` требует, чтобы каждое правило ссылалось на
СУЩЕСТВУЮЩИЙ профиль (нет sentinel «null-профиль»). Значит «у пары нет
применимых опций» выражается ТОЛЬКО отсутствием совпавшего правила — а старый
catch-all `image.raster` был БЕЗУСЛОВНЫМ (`category:image, ocr:false`, без
`to`/`from`) и матчил ЛЮБУЮ пару категории, включая `svg→ico`. Чтобы
`svg→ico` не матчил НИЧЕГО, catch-all пришлось сделать условным.

**Решение (по совету ревью — минимальный blast radius):** catch-all ограничен
по `from` (явный список 9 НЕ-svg источников: `bmp,gif,ico,jpeg,jpg,png,tif,
tiff,webp`), а НЕ по `to`. Так `to` остаётся неограниченным — новый ЦЕЛЕВОЙ
image-формат для НЕ-svg источников по-прежнему автоматически получает
`image.raster` без правки каталога. Плата: новый image-ИСТОЧНИК (не только
svg) нужно дописывать в этот `from`-список явно — задокументировано в
`$comment` файла как новый инвариант для будущих карточек. Альтернатива
(ограничить catch-all по `to`, исключив `ico`) была отвергнута — она задела
бы docx/md/pdf/png/txt targets для ВСЕХ источников, а не только ico.

### Профили и правила (позиция в `assignments` и почему)

Все 3 новых/изменённых правила — в блоке `category:image` (порядок МЕЖДУ
блоками разных категорий не важен, категории дизъюнктны), СРАЗУ ПОСЛЕ
`image.jpeg`/`image.lossy` и ДО (изменённого) catch-all:

| # | Профиль | from | to | Позиция |
|---|---|---|---|---|
| 1 | `image.bmp` (новый) | `[svg]` | `[bmp]` | до catch-all |
| 2 | `image.raster` | `[svg]` | `[gif, png, tiff]` | до catch-all |
| 3 | `image.raster` (catch-all, ИЗМЕНЁН) | `[bmp,gif,ico,jpeg,jpg,png,tif,tiff,webp]` (было: не ограничен) | не ограничен | последний в блоке |

Правило 2 нужно ТОЛЬКО потому, что catch-all перестал матчить `from:svg` —
без него `svg→gif/png/tiff` тоже остались бы без профиля (регресс). Явного
правила для `svg→ico` НЕТ — это и есть механизм «нет профиля»: ни правило 1
(to не bmp), ни правило 2 (to не в списке), ни правило 3 (from не в списке)
не матчат, `resolveProfileId()` возвращает `null`.

### Профиль `image.bmp` (новый)

`width`/`height`/`background`, все `minPlan: guest` (дёшево по CPU — та же
логика, что у `image.jpeg`). **Без `default` на `background`** — worker уже
берёт дефолт `#FFFFFF` сам (`options.get("background", "#FFFFFF")`); если бы
каталог материализовал default, это добавило бы `background` в
`ConversionMessage.options` каждой пустой `svg→bmp` задачи, нарушив
инвариант «пустой запрос → пустые options», который держат все
предшествующие карточки.

### Итоговый набор полей по паре (подтверждено сверкой с worker.py)

| Пара | Профиль | Поля | Honoured worker'ом |
|---|---|---|---|
| `svg→gif` | `image.raster` | width, height | оба — да |
| `svg→bmp` | `image.bmp` | width, height, background | все три — да |
| `svg→tiff` | `image.raster` | width, height | оба — да |
| `svg→ico` | — (null) | — | ничего не применимо (by design, CNV-75) |

Ничего инертного, ничего скрытого — набор полей КАЖДОЙ пары равен ровно тому,
что worker честно применяет (`_do_svg_convert()`/`_save_svg_bmp()`/
`_save_svg_ico()`).

### Тесты

Полный обход всех 86 боевых `category=image` пар (не выборка) —
`ConversionSettingsCatalogTest::testProductionCatalogAssignsImageProfiles`
(tripwire-счётчики: jpeg-target 9, webp-target 9, svg→bmp 1, svg→{gif,png,
tiff} 3, svg→ico null 1, остальной catch-all 63 — сумма 86, числа получены
python-подсчётом по боевому `conversion_pairs.json`). Плюс читаемые примеры
(`testProductionCatalogAssignsSvgProfilesExamples`), presenter-уровень
(`ConversionCatalogPresenterTest::testKnownPairsCarryTheExpectedProfile`),
validator-уровень (`testProductionSvgOptionsAreValidatedAndNormalized` +
`testProductionSvgRejectionsFollowClosedGrammar`, 10 кейсов) и HTTP-уровень
(`ConversionSettingsCatalogApiTest`: 2 новых позитивных теста + 4 новых кейса
в `rejectedRequestProvider`).

### Гейт

- `make phpstan` — OK, 0 ошибок (оба конфига).
- `make cs` — исправил 1 файл (форматирование нового теста); `make cs-check`
  — 0 из 290 файлов требуют правок.
- `make TEST=1 test-php` — **975 тестов / 5755 ассертов, 0 падений** (было
  949/5417 — task-prompt baseline; +26 тестов/+338 ассертов). 12
  PHPUnit-deprecations — то же число, что в baseline.
  `testProductionCatalogLoadsAndIsVersioned` дополнен проверкой наличия
  `image.bmp` в каталоге (независимый оракул от sweep-теста на случай, если
  профиль пропадёт из `profiles`, но останется в `assignments`).
- `make TEST=1 test-drift` — **28 passed** (`conversion_pairs.json` не
  трогался).
- `make TEST=1 test-python` — **431 passed, 1 xfailed, 2 skipped** —
  ИДЕНТИЧНО заявленному baseline (`workers/` не трогался вовсе, только JSON +
  PHP-тесты).

### Can-fail proof (каждый: сломал → красный по нужной причине →
восстановил → зелёный; бэкап `/tmp/backup/convertor/backup_conversion_settings.cnv95-good.json`,
восстановление подтверждено `diff`)

**(a) width/height отклонены для svg→ico.** Вернул `svg` в `from`-список
catch-all (точь-в-точь старый баг) → **9** красных по нужной причине:
`svg→ico` снова резолвится в `image.raster`, null-ассерты и 422-ожидания
ломаются на `202`/`'image.raster'` вместо `null`/`422`
(`ConversionSettingsCatalogTest`, `ConversionCatalogPresenterTest`,
`ConversionOptionsValidatorTest`, `ConversionSettingsCatalogApiTest`).
Восстановил → 231/231 зелёные.

**(b) background принят и нормализован для svg→bmp.** Убрал поле
`background` из профиля `image.bmp` → **5** красных/error по нужной причине:
`InvalidConversionOptionException: Unknown option "background" for svg →
bmp` там, где ожидался приём и нормализация. Восстановил → зелёные.

**(c) новые правила не затенены catch-all'ом и не затеняют его.** Три мутации:
- **Удалил правило #2** (`image.raster`, `from:[svg]`, `to:[gif,png,tiff]`)
  целиком — **11 failures + 1 error** по нужной причине: `svg→gif`/`svg→png`/
  `svg→tiff` резолвятся в `null` вместо `image.raster`
  (`testProductionCatalogAssignsImageProfiles`: `svgRasterCount` 3→0 + три
  "must resolve to image.raster"; `svgPairProvider`, три кейса; presenter;
  legacy-allowlist кейс `svg source is a normal image pair`). Это и есть
  регресс, который вносит рестрикция catch-all'а по `from` — без правила #2
  он не гейтится ничем. Восстановил → зелёные.
- Убрал `from: ["svg"]` у правила `image.bmp` (симулирует, что оно начинает
  матчить ЛЮБОЙ `→bmp`, затеняя catch-all для НЕ-svg источников) — **7**
  красных по нужной причине: `jpg→bmp`/`png→bmp`/`gif→bmp` внезапно получили
  `image.bmp`/`background` вместо `image.raster` без него. Восстановил →
  зелёные.
- Вернул `svg` в `from`-список catch-all'а (та же мутация, что и (a) —
  доказывает «не затенено»: без явных правил 1/2 catch-all снова матчил бы
  `svg→ico` первым же совпадением) — **9** красных, см. (a) выше.
Финальное восстановление подтверждено `diff` с бэкапом и полным
`make TEST=1 test-php` — 975/5755, 0 падений.

### Side findings / нужен ack team-lead

- **Гейты гонялись НЕ на тихом хосте.** `ps aux` во время прогона показал
  параллельно работающий `docker compose ... run --rm gateway-api pytest`
  ЧУЖОГО проекта (`gateway-tg`, другой агент/сессия на этом же хосте). Числа
  сошлись ровно (`975-949=26` тестов = `9+11+2+4` по файлам; python-сумма
  `431/1/2` совпала с заявленным baseline), похоже на не-влияние, но хост не
  был изолирован — фиксирую как факт, не как найденную проблему convertor.
- `/tmp/backup/convertor/backup_conversion_settings.cnv95-good.json` —
  снапшот-бэкап каталога для can-fail restore, оставлен по правилу проекта
  (не удалять, переименовывать `backup_`) — можно удалить с разрешения.
- Ничего вне scope не найдено.
- `$comment` каталога дополнен новым инвариантом (catch-all's `from` теперь
  явный список НЕ-svg источников) — будущим карточкам, добавляющим новый
  image-ИСТОЧНИК, нужно дописать его туда, иначе он молча останется без
  width/height. Зафиксировано в файле, не только здесь.
- Открытый вопрос CNV-75 «team-lead ack на `_save_svg_ico()` игнорирует
  width/height by design» — закрыт ЭТОЙ карточкой: `svg→ico` не получает
  профиля вовсе, значит клиент физически не может прислать эти ключи для
  этой пары (422 `settings_not_supported`, а не «поле неизвестно профилю»).
