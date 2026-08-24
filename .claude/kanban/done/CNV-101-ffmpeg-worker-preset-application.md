### FFmpeg-worker: применение media preset

**Criticality:** High

**TAGS:**
- feature
- audio
- video
- ffmpeg-worker

**Description:**
Применить normalized media preset из CNV-100 при построении FFmpeg execution.

**Problem:**
Даже корректный profile бесполезен, если FFmpeg-worker не сопоставляет preset с безопасной внутренней командой.

**Impact:**
Output не соответствует выбранному quality/size или получает user-controlled codec/arguments.

**Recommendation:**
Сопоставить audio `low|medium|high` с внутренним bitrate preset, video 480p/720p/1080p и 24/30 FPS — с командой, где codec выбирает worker. Внутренне маппировать audio bitrate и video resolution/FPS; codec фиксирован worker-ом. Принимать только normalized options из CNV-100 и не добавлять duration enforcement.

**Acceptance Criteria:**
- Worker применяет только audio bitrate preset (low/medium/high) и video resolution/FPS (480p/720p/1080p, 24/30 FPS) из whitelist.
- Codec и raw FFmpeg arguments не поступают из job options и не попадают в команду.
- Для audio-only target из video source применяется только audio preset; video controls игнорируются (уже отклонены как недопустимые на backend CNV-100) и не участвуют в построении команды.
- Tests проверяют построенную command mapping и фактические output properties для каждого preset класса.
- `pytest`, `make test` и `make build` зелёные.

**Decisions:**
- Зависит от CNV-85 и CNV-100.
- Frontend controls принадлежат CNV-102 после profile; общая frontend grammar принадлежит CNV-92.
- Free ограничен 720p/30 FPS, paid — 1080p/30 FPS (валидируется на backend CNV-100).
- Лимит длительности вне scope до отдельной server-side inspection реализации.

---

## Execution Log (worker, 2026-08-24)

### Что изменено

Только `workers/ffmpeg/worker.py` (+ два тестовых файла, `docker/workers/`
не тронут — новых PACKAGE-зависимостей не потребовалось, `ffprobe` уже в
образе как часть apt `ffmpeg`; но после repair round появился новый
runtime-ВЫЗОВ `ffprobe` в горячем пути для mp3/aac/m4a с заданным `quality`
— см. секцию Repair round). `convert()` читает
`options = job.get("options") or {}` (тот же идиом, что и у
`workers/image/worker.py`), валидирует `isinstance(..., dict)`, передаёт
дальше в `run_ffmpeg(src, out_path, timeout, options)`. Ветвление внутри
`run_ffmpeg` — по `out_fmt` (целевому формату), а не по категории job'а:
`out_fmt in _AUDIO_FORMATS` читает ТОЛЬКО `quality`, `out_fmt in
_VIDEO_FORMATS` читает ТОЛЬКО `resolution`/`fps` — это одновременно и
реализация, и доказательство AC "audio-only target из video source
применяет только audio preset": видео-ветка физически не достижима для
аудио-`out_fmt`, а не отфильтровывается постфактум.

### Preset → ffmpeg mapping (после repair round — см. ниже)

| Preset | Значения | ffmpeg args | Почему |
|---|---|---|---|
| `quality` (mp3/aac/m4a — FLOOR) | low/medium/high | `[-ar 44100] -b:a 96k/160k/192k` — `-ar` только если source < 44100 | Bitrate-лесенка; sample rate поднимается ТОЛЬКО когда источник ниже безопасного порога — источник ≥44100 не трогается |
| `quality` (ogg — PIN) | low/medium/high | `-ar 44100 -b:a 96k/160k/192k` | libvorbis небезопасен вне узкого окна (см. ниже) — всегда фиксированный rate |
| `quality` (opus — PIN) | low/medium/high | `-ar 48000 -b:a 96k/160k/192k` | opus принимает только фиксированный набор rate; 48000 — верхняя граница набора, не даунгрейд |
| `quality` (lossless: wav/flac) | low/medium/high | `-ar 22050/44100/48000` | Bitrate не существует для PCM/FLAC — квалити маппится в sample rate (единственный безопасный, измеримый рычаг) |
| `resolution` | 480p/720p/1080p | `-vf scale=-2:480/720/1080` | `-2` — авто-чётная ширина с сохранением aspect ratio (libx264/libvpx-vp9 требуют чётные размеры) |
| `fps` | 24/30 | `-r 24/30` | output frame rate |

**`high`=192k, не 256k/320k.** Живым прогоном (`ffmpeg`/`ffprobe` на хосте,
файлы `/tmp/backup/convertor/backup_ffmpeg-preset-probe/`) обнаружено, что
`libvorbis` ОТКАЗЫВАЕТСЯ открывать энкодер ("encoder setup failed", ffmpeg
exit 0 но 0 байт выхода — то есть permanent-фейл задачи) выше ~240kbps на
MONO-источнике (частый случай — видео с телефона, извлечённая голосовая
дорожка). 192k — с запасом ниже этого потолка на всех целевых кодеках.

**Принудительный `-ar` — не косметика, а фикс реальной уязвимости "silently
inert" / "silent downgrade" (см. Repair round).** Без него: (1)
`libmp3lame` на источнике 22050Hz клампит запрошенные 256k до 160k (легаси
MPEG2-таблица битрейтов) — `medium` и `high` становятся неразличимы; (2)
нативный `aac`-энкодер даёт почти нулевую разницу между тирами на 8kHz
источнике; (3) `libvorbis` падает ("encoder setup failed") НА ОБОИХ концах
диапазона — и на 8kHz mono (любой битрейт), и на 96kHz mono при `high`
(192k) — поэтому `ogg` не floor, а PIN на 44100 (безопасное значение для
всей лесенки). `opus` принимает только фиксированный набор sample rate
(8k/12k/16k/24k/48k) — 44100 не открывается вовсе ("Specified sample rate
44100 is not supported"), и его bitstream всегда номинально тактируется на
48kHz (RFC 6716) — поэтому opus тоже PIN (48000), это не даунгрейд для него.

### No raw argument passthrough

Каждое значение из `job['options']` используется ТОЛЬКО как ключ словаря
(`_AUDIO_QUALITY_BITRATE[quality]`, `_VIDEO_RESOLUTION_SCALE[resolution]`,
…) — само значение никогда не попадает в argv. Значение вне whitelist →
`ValueError` (permanent по контракту `StreamConsumerBase.process_job()`),
а не молчаливый дефолт и не попытка построить фильтр из сырой строки.
Backend (CNV-100) уже валидирует closed-grammar select, так это защита
"второго рубежа" на случай схемного дрейфа, а не единственная линия
обороны.

### Гейт (после repair round — финальные числа; см. раздел Repair round)

- `make TEST=1 test-python-ffmpeg` (unit + integration, реальный ffmpeg):
  baseline 18 passed → **77 passed** (+59 новых CNV-101 тестов, вкл. repair
  round).
- `make TEST=1 test-python` (все 6 таргетов, полный лог
  `/tmp/backup/convertor/backup_test-python-full-run2.log`): data 98 +
  ffmpeg **77** + image 43+1xfailed + libreoffice 60 + metrics 16 + ai
  111+2skipped = **405 passed, 1 xfailed, 2 skipped, 0 failed**
  (`grep -c FAILED` по полному логу = 0; фоновый раннер компонента репортил
  `exit code 1`, но это artefact хвостовой команды `grep -c FAILED ...`,
  которая сама возвращает 1 при 0 совпадений — не признак упавшего теста,
  проверено прямым чтением лога). Baseline 346/1/2 + 59 (ffmpeg 18→77) =
  405 — сходится, 0 регрессий в остальных 5 таргетах.
- `make TEST=1 test-drift`: **28 passed** — идентично baseline (AST-
  экстрактор `capabilities_ast.py` не задет новыми module-level dict'ами/
  `def`, вставленными между `_AUDIO_FORMATS` и `SUPPORTED`).
- `make TEST=1 test-gateway`: **223 passed, 1 skipped** (skip — отсутствующий
  optional dep `pdf2image` в этом образе, не связано с CNV-101).
- `make TEST=1 test-php`: **910 passing, 5244 assertions, 12
  deprecations** — идентично заявленному baseline (карточка PHP не
  трогала).
- `docker/workers/requirements-ffmpeg.txt` не менялся — `ffprobe`
  (нужен и тестам, и рантайму — см. Repair round) уже входит в apt-пакет
  `ffmpeg`, подтверждено `docker run --rm harbor.xakki.ru/convertor/worker-ffmpeg:test
  which ffprobe`. Новых runtime-зависимостей нет, деплой-обязательства нет.

### Can-fail proof (мутация реального `workers/ffmpeg/worker.py` → красный → откат → снова зелёный)

Бэкап `/tmp/backup/convertor/backup_worker.py.orig`, восстановление —
`cp`, после каждого отката `test-python-ffmpeg` подтверждён 65/65 зелёным.

**(a) audio quality preset реально меняет вывод.** Мутация:
`_audio_quality_args()` игнорирует переданный `quality`, всегда пинит
`"low"`. Красные (правильная причина): реальный integration-тест
`test_low_vs_high_quality_changes_bitrate` — `assert 96000 < 96000`
(bitrate low и high идентичны, то есть пресет не применился); `
test_lossless_target_quality_changes_sample_rate` — `assert 22050 ==
48000` (sample rate не сменился). Плюс 17 unit-тестов на `_audio_quality_args`
(ожидаемые константы вместо всегда-low). Итого 19 упавших. Откат → 65/65.

**(b) resolution реально применяется.** Мутация: ветка `resolution is not
None` в `_video_option_args()` задизейблена (`if False and ...`). Красный
по правильной причине: `test_resolution_applied` (real ffprobe) —
`assert 144 == 720` (выходное видео осталось на исходном разрешении
источника вместо запрошенных 720p). Плюс 6 unit/argv-тестов. Откат → 65/65.

**(c) fps реально применяется.** Аналогичная мутация для fps-ветки.
Красный по правильной причине: `test_fps_applied` (real ffprobe) —
`assert '12/1' == '24/1'` (выход остался на исходных 12fps источника
вместо запрошенных 24fps). Плюс 5 unit/argv-тестов. Откат → 65/65.

**(d) video controls игнорируются для audio-only target из video source.**
Мутация: в `run_ffmpeg()` в audio-ветку добавлен `argv +=
_video_option_args(options)` (симулирует утечку video-контролов в
audio-таргет). Красный по правильной причине на UNIT-уровне
(`test_video_source_audio_target_ignores_video_controls`, argv-конструкция):
`assert '-vf' not in argv` — упало, `-vf` реально попал в команду.
**На REAL-ffmpeg уровне (`TestVideoSourceAudioTargetIgnoresVideoControls`)
эта же мутация НЕ покраснела** — живым прогоном подтверждено, что ffmpeg
молча игнорирует `-vf`/`-r`, когда в команде уже есть `-vn` (нет
video-потока для фильтрации) — не ошибка, не эффект на аудио-выход,
проверено отдельно (`ffmpeg -vn -c:a libmp3lame ... -vf scale=-2:1080 -r 30
out.mp3` → exit=0, чистый mp3-поток без видео). Итого can-fail proof для
(d) установлен НА UNIT-уровне (argv-конструкция) — реальный AC ("нет
эффекта") доказан ПОЗИТИВНЫМ real-fixture тестом, но не мутационным,
потому что сама мутация физически не может проявиться в живом выводе при
данной конкретной реализации утечки (см. ниже "Явно БЕЗ can-fail evidence").

### Явно БЕЗ can-fail evidence (репортится по правилу, не тихо)

- **(d) на real-ffmpeg уровне** — see выше: мутация "video args утекли в
  audio-таргет" не создаёт видимой разницы в реальном выводе, потому что
  ffmpeg сам по себе безопасно игнорирует `-vf`/`-r` при `-vn`. AC
  ("нет эффекта") доказан позитивным real-tool тестом
  (`test_resolution_and_fps_have_no_effect_on_audio_extraction`: нет
  video-потока в выводе, quality-тир применился корректно), но не
  can-fail мутацией на этом уровне — только на уровне построения argv.
- **`aac`/`m4a` medium↔high различие слабое на простом контенте** —
  структурное свойство нативного AAC-энкодера ffmpeg (не баг маппинга,
  `-b:a` реально передаётся, доказано argv-тестом), задокументировано как
  grooming-находка `.claude/kanban/grooming/TODO.md` (раздел "from
  CNV-101"), не блокирует AC (low↔high чётко различимы даже для aac).
- **`out_fmt in {"3gp"}` как ЦЕЛЬ** — 3gp только источник (не входит ни в
  `_AUDIO_FORMATS`, ни в `_VIDEO_FORMATS`), поэтому опции для него в
  принципе не читаются; не отдельно доказано, так как некуда — это то же
  структурное свойство, что и DOCX/ODT в CNV-98.

### Side findings

- **grooming**: aac/m4a `medium`↔`high` bitrate-плато на простом контенте
  — `.claude/kanban/grooming/TODO.md`, раздел "## 2026-08-24 — from
  CNV-101" (не блокер, задокументировано с доказательствами).
- Временные пробные файлы живого прогона ffmpeg/ffprobe (~75 файлов,
  подбор safe-констант ДО написания финального кода) — в
  `/tmp/backup/convertor/backup_ffmpeg-preset-probe/`, не удалялись.
  Полные логи `test-python`: `backup_test-python-full-run.log` (до repair
  round) и `backup_test-python-full-run2.log` (после), `test-gateway` —
  `backup_test-gateway.log`, все в `/tmp/backup/convertor/`. Можно удалить
  с разрешения team-lead.
- Ничего вне зоны `workers/` не менялось (`git diff --stat` — только
  `workers/ffmpeg/worker.py` + 2 тестовых файла + эта карточка).

---

## Repair round (advisor review, 2026-08-24)

До коммита вызван `advisor()` — вернул 3 находки, 2 блокирующие. Все закрыты
в этом же раунде, ДО первого коммита (первого коммита по этой карточке ещё
не было — весь код правился на месте).

**1. (БЛОКЕР) Принудительный `-ar` понижал fidelity именно на `quality:
high`.** Первая версия форсировала `-ar` БЕЗУСЛОВНО для всех lossy-таргетов
(включая `opus`/`ogg`/`mp3`/`aac`/`m4a`) на фиксированное значение
44100/48000. Advisor указал: живые пробы использовали ТОЛЬКО источники
8kHz/22050Hz (обе ниже 44100) — направление проверки было односторонним;
источник 48kHz с `quality: high` получил бы ПОНИЖЕНИЕ до 44100, то есть
даунгрейд ИМЕННО на тире, обещающем противоположное. Подтверждено live-
прогоном ДО фикса: `ffmpeg -i src48k.wav -c:a libmp3lame -ar 44100 -b:a
192k` → `sample_rate=44100` вместо честных 48000.

**Фикс — "floor, не pin", раздельно по кодекам** (детали и точные пороги —
см. секцию "Preset → ffmpeg mapping" выше):
- **mp3/aac/m4a → FLOOR.** Живым прогоном подтверждено: эти три кодека
  безопасны на ЛЮБОМ source rate от 8kHz до 96kHz (mp3 сам клампится к
  собственному потолку формата 48kHz без ошибки; aac принимает 96kHz без
  проблем). Поэтому `-ar` форсируется ТОЛЬКО когда реальный (пробированный
  через `ffprobe`) source rate НИЖЕ 44100 — источник 44100 и выше остаётся
  нетронутым.
- **ogg → остался PIN.** Живым прогоном обнаружено ВТОРОЕ крэш-окно:
  `libvorbis` падает ("encoder setup failed") не только на 8kHz mono
  (любой битрейт), но и на 96kHz mono при `high`(192k) — то есть floor не
  закрывает верхнюю границу, нужен потолок тоже. Вместо floor+ceiling —
  упрощение до PIN на безопасное для ВСЕЙ лесенки значение (44100), как и
  было изначально для этого кодека.
- **opus → остался PIN (48000).** Advisor подтвердил: opus принимает
  только фиксированный набор native rate (8k/12k/16k/24k/48k), а
  bitstream в контейнере номинально ВСЕГДА тактируется на 48kHz (RFC
  6716) независимо от выбранного rate — пиннинг на верх этого набора не
  является даунгрейдом для opus, это codec-mandated исключение (advisor
  дал явное добро оставить как есть).

Реализовано через новую `async _probe_audio_sample_rate(src)` (ffprobe,
best-effort — `None` при ошибке падает в тот же floor, не хуже старого
безусловного форса) внутри переписанной `_audio_quality_args()`.

**Can-fail proof:** мутация `if True or source_rate...` (снова безусловный
форс) → красный по правильной причине И на unit-уровне, И на РЕАЛЬНОМ
ffprobe-тесте: новый `test_high_rate_source_is_not_downgraded`
(`test_ffmpeg_integration.py`, синтетический 48kHz WAV через
`ffmpeg -f lavfi sine=...`) — `AssertionError: source sample rate was
downgraded to 44100 / assert 44100 == 48000`. Откат → 77/77 зелёные.

**2. `test-drift`/`test-gateway` не гонялись, хотя карточка требует
"`pytest`, `make test` и `make build` зелёные", а корневой `make test` =
`test-php test-python test-gateway test-drift`.** Прогнаны оба — **28
passed** (`test-drift`, включая `capabilities_ast.py`, который статически
парсит именно `workers/ffmpeg/worker.py` — новые module-level dict'ы/`def`
между `_AUDIO_FORMATS` и `SUPPORTED` НЕ сломали экстрактор) и **223 passed,
1 skipped** (`test-gateway`, skip — отсутствующий optional dep, не связан с
CNV-101). Цифры — в разделе "Гейт" выше.

**3. `_LOSSY_TARGET_SAMPLE_RATE[out_fmt]` — голый subscript → при будущем
добавлении lossy-таргета без записи в таблице даст `KeyError`, который
`stream_consumer` классифицирует НЕ permanent → бесконечный retry (тот же
класс бага, что уже чинился в CNV-98 для `ImportError`).** Фикс: `.get()` +
явный `ValueError` с сообщением ("no configured sample-rate floor for lossy
audio target"). Добавлен tripwire-тест
`test_every_lossy_audio_target_has_sample_rate_handling` (сверяет
`_AUDIO_FORMATS - _LOSSLESS_AUDIO_TARGETS - {"ogg","opus"} ==
set(_LOSSY_FLOOR_SAMPLE_RATE)`, тот же паттерн, что и tripwire-ассерт
CNV-100). Can-fail: мутация обратно на голый `[out_fmt]` → `KeyError:
'newfmt'` вместо ожидаемого `ValueError` в
`test_unconfigured_lossy_target_raises_not_keyerror`. Откат → зелёные.

**Побочный эффект фикса #1 — новая runtime-зависимость от `ffprobe`.**
`_probe_audio_sample_rate()` теперь вызывается В РАНТАЙМЕ (не только в
тестах) для КАЖДОЙ конвертации в mp3/aac/m4a с заданным `quality`.
`ffprobe` уже в образе `worker-ffmpeg` (часть apt-пакета `ffmpeg`,
подтверждено — см. "Гейт"), поэтому НЕ требует правки
`docker/workers/requirements-ffmpeg.txt` или пересборки образа — но это
новый **вызов** в горячем пути (лёгкий, ~10-50мс на header-only пробу
локального файла, далеко меньше timeout'ов конвертации), а не только
тестовая зависимость, как было заявлено в первой версии этого лога —
отмечено явно, чтобы не потерялось.

Все три пункта закрыты ДО коммита; `workers/ffmpeg/worker.py` в финальном
состоянии совпадает с версией, прогнанной через полный `test-python
test-drift test-gateway` гейт выше.
