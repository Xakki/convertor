### AI-воркер — удалить мёртвый `docker/workers/requirements-ai.txt`

**Критичность:** Low

**TAGS:**
- tech-debt
- devops

**Описание:**
После перехода на split-схему ([[ai-worker-docker-images]]) requirements AI-воркера
разнесены на `requirements-ai-base.txt` (light) и `requirements-ai-ml.txt` (heavy).
Старый плоский `docker/workers/requirements-ai.txt` больше **никем не используется**
(нет ссылок ни в Dockerfile, ни в Makefile) и содержит устаревшие зависимости
(`boto3`, `aiohttp`, `redis`) — наследие до миграции на HTTP pull-API; ни одна из них
не импортируется в текущем `workers/ai/`.

**Рекомендация:**
Удалить файл `docker/workers/requirements-ai.txt`. Перед удалением — `grep -r requirements-ai.txt`
по репо (compose, Makefile, скрипты), убедиться, что ссылок нет.

**Контекст:** находка из ревью [[ai-worker-docker-images]]. Не блокер.
