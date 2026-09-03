# Публичный запуск remote-воркеров (без клона репозитория)

Готовые образы из Harbor + этот каталог `deploy/` (публикуется в Gist из
`make release-workers`). На чужом хосте достаточно Docker и токена.

## Быстрый старт

```bash
curl -fsSL https://gist.githubusercontent.com/<OWNER>/<GIST_ID>/raw/install.sh | bash
```

Скрипт спросит `WORKER_API_TOKEN` (скрытый ввод) и какие воркеры поднять,
запишет `~/convertor-workers/.env`, скачает companions при необходимости,
сделает `docker compose pull` + `up -d`.

Неинтерактивно:

```bash
curl -fsSL https://gist.githubusercontent.com/<OWNER>/<GIST_ID>/raw/install.sh \
  | WORKER_API_TOKEN='…' COMPOSE_PROJECT_NAME='convertor-remote-myhost' \
    WORKER_PROFILES='document image data' bash
```

Локально из репо (dev/проверка):

```bash
cd deploy
cp .env.example .env   # заполнить WORKER_API_TOKEN + COMPOSE_PROJECT_NAME
bash install.sh
# или вручную:
docker compose --env-file .env --profile document --profile image up -d
```

## Обновление

```bash
bash ~/convertor-workers/install.sh update
# или
curl -fsSL https://gist.githubusercontent.com/<OWNER>/<GIST_ID>/raw/install.sh | bash -s -- update
```

Идемпотентно: pull свежих тегов + `--force-recreate --remove-orphans`, без
дублей контейнеров (имена зафиксированы через `COMPOSE_PROJECT_NAME`).

## Матрица воркеров (compose profiles)

| Профиль | Сервис | Образ Harbor |
|---|---|---|
| `document` | worker-libreoffice | `…/worker-libreoffice:latest` |
| `audio` | worker-ffmpeg-audio | `…/worker-ffmpeg:latest` |
| `video` | worker-ffmpeg-video | `…/worker-ffmpeg:latest` |
| `image` | worker-image | `…/worker-image:latest` |
| `data` | worker-data | `…/worker-data:latest` |
| `ai` | worker-ai | `…/worker-ai-cpu:latest` |

`ws-gateway` и `metrics-exporter` на remote **не** поднимаются — только на
главном сервере. Воркеры — WS-клиенты к публичному `wss://…/ws/worker/`.

## Требования

- Docker 24+ с Compose v2 (`docker compose`)
- Исходящий HTTPS к Harbor (`harbor.xakki.ru`, anonymous pull) и к API/gateway
- Уникальный `COMPOSE_PROJECT_NAME` на каждом хосте (иначе столкновение
  `WORKER_ID` / KeyDB consumer names)

## Важно: без CUDA

Публичный путь отдаёт **только** `worker-ai-cpu:latest`. Образ
`worker-ai-cuda` в Harbor не публикуется — GPU-хост собирает его
локально (см. `docs/worker-ai-deploy.md`).

## Создание gist (один раз, вручную)

1. Создать **публичный** gist с файлами из `deploy/`:
   `docker-compose.yml`, `.env.example`, `install.sh`,
   `generate-allowlist.py`, `README.md`.
2. Прописать в корневом `.env.local` (в трекаемом `.env` плейсхолдеры пустые):
   ```bash
   DEPLOY_GIST_ID=<gist-id>
   DEPLOY_GIST_OWNER=<github-username>   # опционально; иначе берётся из gh api
   ```
3. Дальше `make release-workers` / `make publish-deploy-gist` вызывает
   `deploy/publish-to-gist.sh` (`gh api -X PATCH /gists/<id>`). Если
   `DEPLOY_GIST_ID` пуст или нет `gh`/`jq` — warn+skip, релиз образов не падает.
   В публикуемый `install.sh` подставляются `GIST_ID`/`GIST_OWNER`, чтобы
   `curl|bash` сам дотягивал companions.

Raw URL install: `https://gist.githubusercontent.com/<OWNER>/<GIST_ID>/raw/install.sh`

## См. также

- `docs/workers-remote-deploy.md` — полный remote-путь через клон репо + Makefile
- `docs/worker-ai-deploy.md` — детали AI-воркера (CPU/CUDA)
