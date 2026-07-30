### Публикация runnable-образов воркеров в Harbor + pull-деплой на remote-хостах

**Критичность:** Medium (не сломано, но каждый remote-хост пересобирает образы 7-8 мин
на каждый деплой; на uBook это ещё и выкачивание ML-стека)

**TAGS:**
- docker
- devops
- harbor
- workers
- build

**Описание (состояние на 2026-07-30, замерено):**
Сейчас в Harbor (`harbor.xakki.ru/convertor`, проект публичный, anonymous pull
работает — проверено token→manifest 200→blob 206 с saFin и saBots) публикуются
только два артефакта:

| Образ | Размер | Роль |
|---|---|---|
| `convertor/worker-ai-base:latest` | 663 кБ (`FROM scratch`) | code-артефакт: `workers/common` + `workers/ai` + requirements |
| `convertor/ws-gateway:latest` | 212 МБ | гоняется только на главном сервере |

Все **runnable**-образы воркеров собираются локально на каждом хосте и живут под
локальным namespace `IMAGE_NS=xakki-convertor`:
`worker-libreoffice` 1.42 ГБ, `worker-ai:cpu` 3.16 ГБ, `worker-ffmpeg` 837 МБ,
`worker-image` 468 МБ, `worker-data` 438 МБ, `metrics-exporter` 205 МБ.

`IMAGE_NS` (локальная сборка) и `HARBOR_NS` (`workers/Makefile:119`, публикация)
разведены намеренно; `docker/workers/ai.cpu.Dockerfile` прямо декларирует
«NOT published to Harbor — build locally on demand».

**Проблема:**
- Каждый remote-хост (uBook, GPU-хост) собирает образы сам: ~7-8 мин на холодную,
  тянет torch/ML-стек, требует BuildKit и места под build-кэш.
- `make pull` на remote-хосте бесполезен для воркеров (`Skipped — Image can be
  built`), обновление возможно только через `build-workers` + `workers-recreate`.
- Легко получить рассинхрон: `workers-recreate` без пересборки поднимает старый код,
  и БД-сигналы (`alive`, `host`, метрики) этого не показывают.
- CLAUDE.md утверждает «Remote-воркеры (АИ и пр.) пуллят образ из Harbor на своём
  хосте» — **дрейф**: по факту тянется только 663-кБ `worker-ai-base` как база сборки.

**Decisions (грумминг 2026-07-30):**

1. **Публикуем в Harbor runnable-образы:** `worker-libreoffice`, `worker-ffmpeg`,
   `worker-image`, `worker-data`, `worker-ai:cpu`, `ws-gateway`, `metrics-exporter`.
   **`worker-ai:cuda` НЕ публикуем** — CUDA-runtime поверх 3.16-ГБ CPU-образа не влезает
   в бюджет диска (см. п.6); GPU-хост продолжает собирать его локально из
   `worker-ai-base` по текущему флоу (карта `cuda-worker-ai-rebuild-gpu-host`).
   `worker-ai-base` остаётся — он источник сборки обоих AI-вариантов.

2. **Версионирование образов — `<APP_VER>-<git short sha>` + подвижный `latest`.**
   Пример: `harbor.xakki.ru/convertor/worker-data:0.1-a1b2c3d` (иммутабельный) и
   `:latest` (переставляется тем же релизным таргетом). У AI тег-слот уже занят
   вариантом → формат `:<ver>-cpu` / `:latest-cpu`.
   Почему не текущий `.i`-счётчик: `.i` gitignored и монотонен **на каждом хосте
   отдельно** → номера версий несопоставимы между машинами. `.i`/`WORKER_BUILD`
   остаётся внутри образа как build-счётчик (воркер репортит его в `ready`), но
   тегом становится git-SHA. Грязное рабочее дерево → релиз отказывается пушить.

3. **Два РАЗНЫХ таргета сборки — это ключ к «подтягивается только код»:**
   - `make release-workers` — **cache-warm** сборка + тег версии + push. Слой
     зависимостей не меняется → в Harbor и на remote уезжает только слой кода
     (`workers/common/` + `workers/<name>/`, десятки кБ) плюс два копеечных слоя
     `ENV APP_VER` / `RUN printf .i`. **Нормальный путь релиза.**
   - `make rebuild-workers` — `--no-cache --pull`, полная пересборка «с нуля» под
     бамп зависимостей и обновление базового образа (CVE). Редкая, явно вызываемая
     команда. Её нельзя делать дефолтом релиза: `--no-cache` бьёт pip-слой →
     каждый релиз это ~3 ГБ push и полное перекачивание на remote.
   - `make build-workers` остаётся как есть — локальная dev-сборка.

4. **Dockerfile'ы менять НЕ нужно** (проверено по всем: data, libreoffice, ffmpeg,
   image, gateway, metrics_exporter, ai.cpu, ai.cuda). Порядок слоёв уже правильный:
   `COPY requirements-*.txt` → `pip install` (с BuildKit cache-mount) → `COPY
   workers/common/` → `COPY workers/<name>/` → `ARG APP_VER`/`WORKER_BUILD` в самом
   конце. `.dockerignore` исключает `.git`, `**/tests`, `docs/`, `.env*`.
   Единственное косметическое: `gateway` и `metrics_exporter` не запекают `APP_VER` —
   добавить для единообразия версионирования.

5. **Релизы собираются ТОЛЬКО на saFin.** `pip install` не бит-в-бит воспроизводим
   (резолв колёс, таймстемпы, `.pyc`), а BuildKit cache-mount локален для хоста.
   Релиз с другой машины или после `docker prune` даёт новый digest слоя
   зависимостей → полный ~3-ГБ перезалив даже без `--no-cache`. Ограничение жёстче,
   чем дрейф `.i`, и именно оно делает бюджет диска (п.6) выполнимым.

6. **Harbor: retention + GC — обязательная часть задачи.** На saNl (хост Harbor)
   свободно **30 ГБ из 99 ГБ (70% занято)**. Политика: хранить последние **3**
   тегированных версии на репозиторий, untagged удалять старше 7 дней.
   Обязательно **расписание Harbor GC** — удаление тега само по себе не освобождает
   ни байта; без GC повторится текущая картина (у `worker-ai-base` уже 13 untagged
   артефактов), только по ~3 ГБ каждый. Требует админа Harbor → отдельным шагом
   выдать пользователю готовую команду/шаги в UI, анонимным API это не делается.

7. **`:test`-образы никогда не пушить.** После смены `IMAGE_NS` на Harbor-namespace
   `build_test_img` начнёт тегать `harbor.xakki.ru/convertor/worker-ffmpeg:test`
   (1.2-1.79 ГБ каждый). Список push в релизном таргете — **явный, поимённый**,
   никаких glob'ов по тегам.

8. **Compose:**
   - `IMAGE_NS` по умолчанию → `harbor.xakki.ru/convertor`;
   - `image: ${IMAGE_NS}/worker-x:${IMAGE_TAG:-latest}` — remote может пинить версию
     через `IMAGE_TAG` в своём `.env.local`;
   - `pull_policy: ${WORKER_PULL_POLICY:-build}` на воркер-сервисах: dev по умолчанию
     собирает, remote ставит `always` в `.env.local`. Интерполяцию проверить через
     `make docker-check`, не считать её работающей по умолчанию;
   - секции `build:` остаются (dev-сборка + фолбэк на свежем хосте).

9. **Порядок выкатки (важно!):** `make pull` сохраняет `--ignore-buildable` до тех
   пор, пока образы реально не окажутся в Harbor. Флаг снимается **тем же
   изменением**, которым приезжает первый успешный релиз — иначе uBook откатится
   ровно в ту ошибку `pull access denied`, которую только что починили.

**Scope (что сделать):**
1. Makefile: `release-workers` (cache-warm + тег версии + push, явный список образов),
   `rebuild-workers` (`--no-cache --pull`), helper вычисления версии из git.
2. `docker-compose.yml`: `IMAGE_TAG`, `pull_policy`, дефолт `IMAGE_NS`.
3. `.env` / `.env.local_worker_example`: `IMAGE_TAG=latest`, на remote
   `WORKER_PULL_POLICY=always`.
4. `pull` без `--ignore-buildable` (в одном изменении с первым релизом).
5. `APP_VER` в gateway/metrics-exporter Dockerfile.
6. Harbor: retention-политика + расписание GC (шаги для админа).
7. Доки: **поправить дрейф в CLAUDE.md** («remote пуллят образ из Harbor» → что
   именно тянется), `docs/workers-remote-deploy.md`, скиллы `image-build-deploy` и
   `ubook-remote-workers` (happy-path становится
   `git pull && make pull && make workers-recreate`, без сборки).

**Проверка (definition of done):**
- На uBook: `make pull && make workers-recreate` поднимает все 6 воркеров healthy
  **без единой сборки**.
- Изменение одной строки в `workers/data/worker.py` → `make release-workers` →
  на uBook `make pull` качает **килобайты**, а не гигабайты (замерить по выводу
  `docker pull`).
- `worker_capabilities` на главном сервере показывает новую версию у uBook-строк.
- `make docker-check` — оба стенда ok.

**Предусловия/риски:**
- Архитектуры совпадают: saFin `x86_64`, uBook `x86_64` (6 CPU, 883 ГБ свободно) —
  проверено 2026-07-30, multi-arch buildx не нужен. Появится ARM-хост → понадобится.
- Диск Harbor (saNl) — узкое место, см. п.6.
- Первый релиз зальёт ~3 ГБ (сжатые слои), дальше код-only релизы — килобайты.

**Ссылки:**
- `cuda-worker-ai-rebuild-gpu-host` — GPU-хост остаётся на локальной сборке.
- `remote-host-make-up-footgun` (grooming) — профили compose на remote-хостах.
- `docs/workers-remote-deploy.md`, скиллы `image-build-deploy`, `ubook-remote-workers`.

**Status:** todo (groomed 2026-07-30).

## Execution Log

- 2026-07-30 — старт, ветка `task/harbor-published-worker-images`.
