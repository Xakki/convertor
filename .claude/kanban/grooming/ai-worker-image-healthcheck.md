### AI-воркер — добавить HEALTHCHECK в рабочие образы ai.cuda/ai.cpu

**Критичность:** Low

**TAGS:**
- devops

**Описание:**
Новая схема образов ([[ai-worker-docker-images]]) убрала прежний (к тому же сломанный —
`python` вместо `python3`) HEALTHCHECK и не добавила замену. Без healthcheck Docker не
отличит зависший воркер от живого.

**Open questions:**
- Что считать «здоровьем» pull-API воркера: успешный импорт ключевой ML-библиотеки
  (`python3 -c "import faster_whisper"`), или активность polling-цикла к API
  (last-poll timestamp / lightweight self-check)? Импорт проверяет только готовность
  процесса, не то, что воркер реально тянет задания.
- Где определять healthcheck — в `ai.cuda`/`ai.cpu` Dockerfile (общий для рабочих
  образов) или в `docker-compose.worker-ai.yml` (ближе к деплою)?
- Нужен ли healthcheck лёгкому `ai-base` (он не запускает воркер — вероятно нет).

**Рекомендация:**
Определиться с критерием и местом, добавить HEALTHCHECK (обязательно `python3`, не `python`),
согласовать с pull-API ([[worker-pull-api-live-status-hash]]).

**Контекст:** находка из ревью [[ai-worker-docker-images]]; вне AC той карточки.
