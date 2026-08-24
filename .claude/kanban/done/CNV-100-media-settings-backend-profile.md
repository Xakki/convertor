### Media settings: backend profile и validation

**Criticality:** High

**TAGS:**
- feature
- audio
- video
- backend
- conversion-options

**Description:**
Определить media profiles и серверную validation безопасных FFmpeg presets.

**Problem:**
Без pair-specific profile API может принять raw arguments, codec selection или video values для audio-only target.

**Impact:**
Пользователь создаст неподдерживаемое или чрезмерно ресурсоёмкое media job.

**Recommendation:**
После CNV-85 публиковать audio `low|medium|high`; video 480p/720p/1080p и 24/30 FPS. Применять plan limits: free до 720p/30 FPS, paid до 1080p/30 FPS; codec выбирает worker. Не вводить duration field или limit.

**Acceptance Criteria:**
- Catalog назначает audio profile audio-only pairs и video profile только video-capable pairs.
- Backend отклоняет raw FFmpeg args, codec key, unknown presets, недоступный plan и video fields для audio-only target.
- Normalized job содержит только разрешённые preset keys и effective values.
- API tests покрывают free/paid boundaries, pair compatibility и invalid values.

**Decisions:**
- Зависит от CNV-85; CNV-77/CNV-101 и CNV-102 начинаются после profile.
- Входной размер ограничивается существующими тарифными лимитами.
- Duration inspection/limit — отдельный будущий server-side scope.

---

## Execution Log (backend, 2026-08-24)

### JSON-only — extension point подтверждён

Карточка закрыта ЦЕЛИКОМ правкой `app-symfony/config/catalog/conversion_settings.json`
(+ тесты). Ни один PHP-класс не менялся (`git diff --stat` — только JSON + 6 тестовых
файлов).

### Классификация пар: audio-target vs. video-capable

`conversion_pairs.json` (398 пар, не трогался) даёт 134 audio/video пары. Классификация —
по `to`, не по домыслу:
- **video-capable** (`to` ∈ контейнерам `avi/mkv/mov/mp4/webm`, `category=video`) — **35** пар.
- **audio-target** (`to` ∈ `aac/flac/m4a/mp3/ogg/opus/wav`) — **81** пара: 49 из `category=audio`
  (аудио→аудио) + **32 из `category=video`** (video-source, audio-only target — напр. `mp4→mp3`,
  `avi→wav` — извлечение звуковой дорожки).
- Остаток — **18** transcription-пар (`category=audio`, `to` ∈ `srt/txt/vtt`, `isAi=true`) —
  вне scope, без профиля.

Числа получены Python-подсчётом по боевому файлу и зафиксированы как tripwire-ассерт в
`ConversionSettingsCatalogTest::testProductionCatalogAssignsMediaProfiles` (полный обход всех
134 пар, а не выборка) — сдвиг каталога (новый video-container или audio-формат) уронит счётчик,
а не молча проедет мимо.

**Главный риск карточки (`mp4→mp3` — video source, audio-only target) закрыт присвоением
`media.audio`, а НЕ `media.video`** — правило `assignments` матчит по `to`-списку внутри
`category=video`, отдельно от video-container-правила; множества `to` дизъюнктны, порядок
между правилами не важен.

**Побочная находка (важная):** `category=document`, `isAi=true` пары `md/txt → mp3/ogg/wav`
(TTS) делят `to` со `media.audio`, но НЕ `category` — правило `media.audio` ЯВНО скоуплено
`"category": "audio"` (а не голым списком `to`), поэтому TTS-пары остаются без профиля этой
карточки. Без этого category-скоупа TTS получил бы аудио-настройки, которые к TTS не относятся
(другой домен, другая будущая карточка). Доказано can-fail (см. ниже).

### Профили и поля

| Профиль | Поле | Тип | Значения (minPlan) | default |
|---|---|---|---|---|
| `media.audio` | `quality` | select | `low`/`medium`/`high` — все `guest` | null |
| `media.video` | `resolution` | select | `480p` `free`, `720p` `free`, `1080p` **`basic`** (field-level `minPlan: free`) | null |
| `media.video` | `fps` | select | `24` `free`, `30` `free` (field-level `minPlan: free`) | null |

**Обоснование по стоимости** (Guest-политика CLAUDE.md — гейтим не «на всякий случай», а по
CPU/памяти):
- **Аудио — `guest` на всех значениях.** Смена битрейта/пресета качества аудио дёшева по CPU
  относительно видео-транскода; та же логика, что уже применена к image-геометрии/качеству в
  CNV-85.
- **Видео — `minPlan: free` НА ВСЁ ПОЛЕ целиком (не `guest`), а не только на 1080p.** Видео-
  транскод — CPU-тяжёлая операция уже на 480p (сам факт запуска FFmpeg с видео-фильтром), это
  прямо названо в каталожном `$comment` («разрешение и fps видео» — пример того, что требует
  обоснования выше guest) и в `ROADMAP.md:230` («T3 Heavy: video (тяжёлый транскод)»). Это
  согласуется и с отдельным фактом: анонимные пользователи УЖЕ заблокированы от
  `category=Video` на auth-слое (403 `auth_required`, `ConversionController::convert()`) — гость
  физически не достигает `media.video` ни при каком `minPlan`, так что `guest` здесь был бы
  просто неисполнимым обещанием.
- **1080p — `basic` (paid).** AC карточки: «free до 720p/30fps, paid — 1080p/30fps». `fps`
  (24/30) не гейтится сверх `free`, т.к. по AC free уже получает 30fps в паре с 720p.
- **Codec нигде не экспонируется** — грамматика каталога закрыта (`range/select/number/text/
  boolean/color`), сырых полей движка не существует в принципе; `codec`/`ffmpegArgs` отклоняются
  как `unknown_option` (не специальным кодом, а тем же путём, что и любой незаявленный ключ).

**Явная моот-permissiveness (не утечка):** `mp4→mp3` несёт `media.audio` с `minPlan: guest` на
поле `quality`, но категория пары — `video`, поэтому гость никогда не достигает её физически
(auth-гейт видео блокирует раньше). `guest` на этом поле — недостижимое, а не дырявое
разрешение; проговорено явно, чтобы не потребовалось ревью-вопроса.

**Значения нормализуются как строки** (`resolution: "1080p"`, `fps: "30"`) — select-грамматика
всегда отдаёт строку; `quality` (media.audio) — строковый select, а НЕ числовой `range`, как
одноимённое поле `image.lossy`/`image.jpeg`. CNV-101 не должен трактовать `options['quality']`
как число.

### Три живых доказательства риска 1 (per-VALUE plan-гейтинг, первое боевое поле)

Все три — через реальный HTTP, persisted `User`, реальный JWT, БОЕВОЙ каталог (без grammar-
фикстуры):

1. **Free видит 1080p НЕредактируемым** (`GET /formats` → `settings.profiles['media.video']
   .fields[resolution].options['1080p'].editable === false`, `480p`/`720p` — `true`) —
   `ConversionCatalogPresenterTest::testMediaVideoResolutionIsPersonalizedByRealPlan` (unit,
   передаёт `SettingsAccessLevel` напрямую) И
   `ConversionSettingsCatalogApiTest::testMediaVideoResolutionPlanGatingThroughTheFullChain`
   (HTTP, реальный JWT/план — добавлено по замечанию ревью, «показан как недоступный» и
   «отклонён при отправке» изначально были доказаны на разных уровнях).
2. **Free отклоняется при отправке 1080p** — тот же HTTP-тест: `POST /convert` с
   `resolution=1080p` от free-плана → 422 `option_plan_required`, `ConversionManager` не
   вызывается (`$captured['message'] === null`).
3. **Paid (basic) принимается и доходит до задачи нормализованным** — тот же тест: `POST
   /convert` с `resolution=1080p` от basic-плана → 202, `ConversionMessage.options ===
   ['resolution' => '1080p', 'fps' => '30']`.
4. **Retry после понижения плана отклоняет** —
   `ConversionManagerRetryDeleteTest::testRetryRejectsMediaVideoOptionAfterDowngrade` (unit,
   мок quota `never()`) и
   `ConversionRetryDeleteControllerTest::testRetryRejectsMediaVideoOptionAfterDowngradeThroughHttp`
   (HTTP, persisted user basic→free, реальный JWT) — первый раз этот гейт (построен в репэйр-
   раунде CNV-85) упражняется на ЖИВОМ поле, а не на синтетическом `test.grammar`.

### Гейт

- `make phpstan` — OK, 0 ошибок (оба конфига).
- `make cs` / `make cs-check` — 0 из 290 файлов требуют правок.
- `make TEST=1 test-php` — **910 тестов / 5244 ассерта, 0 падений** (task-prompt baseline —
  876/4643; +34 теста/+601 ассерт). 12 PHPUnit-deprecations — то же число, что и в baseline, в
  файлах, которые эта карточка не трогала.
- `make TEST=1 test-python` — **346 passed, 1 xfailed, 2 skipped** — ИДЕНТИЧНО baseline
  (карточка `workers/` не трогала).
- `make TEST=1 test-drift` — **28 passed** (каталог пар не тронут).

### Can-fail proof (каждый: сломал → красный по нужной причине → восстановил → зелёный)

**(a) Free-plan 1080p отклонён.** Временно `1080p.minPlan: basic → guest` →
5 тестов красные по нужной причине (значение стало ПРИНЯТО там, где ожидался отказ):
- `ConversionOptionsValidatorTest::testProductionMediaRejectionsFollowClosedGrammar@free-plan
  1080p is rejected` — `Ожидался отказ с кодом option_plan_required`.
- `ConversionCatalogPresenterTest::testMediaVideoResolutionIsPersonalizedByRealPlan` —
  `'1080p' => false` ожидалось, получили `true`.
- `ConversionSettingsCatalogApiTest::testMediaVideoResolutionPlanGatingThroughTheFullChain` —
  та же разница, через HTTP.
- `ConversionManagerRetryDeleteTest::testRetryRejectsMediaVideoOptionAfterDowngrade` — мок
  quota `check()` вызван, хотя ожидался `never()` (значит гейт пропустил выполнение дальше).
- `ConversionRetryDeleteControllerTest::testRetryRejectsMediaVideoOptionAfterDowngradeThroughHttp`
  — `429 insufficient_balance` вместо `422` (без гейта retry дошёл до реального quota-чека,
  который упал по НЕсвязанной причине — само по себе доказательство, что гейт обычно
  останавливает выполнение раньше).
Восстановил `basic` → все зелёные.

**(b) Video-поля отсутствуют на audio-only target.** Временно правило `video→audio-extraction`
переключил с `media.audio` на `media.video` (симулирует ровно ту ошибку, которую предостерегает
карточка) → **8** красных по нужной причине (unit-каталог, unit-валидатор, unit-презентер,
HTTP): `mp4→mp3` резолвится в `'media.video'` вместо `'media.audio'`; `resolution`/`fps` на
`mp4→mp3` внезапно ПРИНИМАЮТСЯ там, где ожидался `unknown_option`. Восстановил → зелёные
(910/5244 подтверждено полным прогоном).

**(c) TTS (`md/txt→mp3`) не получает media-профиль.** Временно снял `"category": "audio"` у
правила `media.audio` (голый `to`-список) → **3** красных по нужной причине: `md→mp3` стал
резолвиться в `'media.audio'` вместо `null`
(`ConversionCatalogPresenterTest::testKnownPairsCarryTheExpectedProfile`,
`ConversionSettingsCatalogTest::testProductionCatalogAssignsMediaProfilesExamples`), TTS-запрос
с `quality` внезапно принимается там, где ожидался `settings_not_supported`
(`ConversionOptionsValidatorTest::testProductionMediaRejectionsFollowClosedGrammar@TTS document
pair`). Восстановил `"category": "audio"` → зелёные.

### Side findings / нужен ack team-lead

- Ничего вне scope не найдено; PHP не менялся вовсе (JSON-only подтверждено `git diff --stat`).
- Два лог-файла ранних прогонов `test-python` этой сессии оставлены в
  `/tmp/backup/convertor/` как `backup_test-python-full.log` /
  `backup_test-python-full2.log` (не удалялись, правило проекта) — можно удалить с разрешения.
