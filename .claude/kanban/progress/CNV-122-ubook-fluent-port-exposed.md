### uBook fluent-bit intake порт `EXT_FLUENT_PORT` реально смотрит в `0.0.0.0`, а не в loopback

**Criticality:** Medium (сетевая экспозиция сервиса приёма логов на удалённом хосте, не сам convertor)

**TAGS:**
- infra
- ubook
- fluent-log
- security
- observation

**Description:**
При обобщении скилла `ubook-remote-workers` → `remote-workers` (2026-08-24)
перепроверил live-факты на uBook и подтвердил живьём то, что `CNV-17` (done,
2026-08-01) только предполагало: `.env.local` на uBook задаёт
`EXT_FLUENT_PORT=0.0.0.0:24224` вместо требуемого loopback-only
`127.0.0.1:24224`. Подтверждено двумя независимыми проверками:

```
ssh uBook 'cat /home/xakki/www/xakki/convertor/.env.local | grep EXT_FLUENT_PORT'
→ EXT_FLUENT_PORT=0.0.0.0:24224

ssh uBook 'ss -tlnp | grep 24224'
→ LISTEN 0 4096 0.0.0.0:24224 0.0.0.0:*   (плюс UDP на 0.0.0.0)
```

**Problem:**
`docs/workers-remote-deploy.md` и `.env.local_worker_example` явно требуют
loopback-only bind ("Intake сайдкара — только loopback: docker
logging-driver на хосте → sidecar. НЕ биндить `0.0.0.0` — сборщик не должен
слушать снаружи"). Живой хост нарушает это требование — TCP/UDP 24224
(fluent-bit forward-протокол, без аутентификации на этом порту) доступен
снаружи хоста, а не только с локальной docker logging-driver. CNV-17 закрыл
свою задачу, зафиксировав это как "may have 0.0.0.0 — ops to rebind when
convenient", но ни одной последующей карточки на это заведено не было — факт
осел в done-карточке и не был виден как открытая работа.

**Что сделать:**
На uBook (`/home/xakki/www/xakki/convertor/.env.local`, gitignored) поменять
`EXT_FLUENT_PORT=0.0.0.0:24224` → `EXT_FLUENT_PORT=127.0.0.1:24224`, затем
`make fluent-recreate` (пересоздать сайдкар с новым bind-адресом), проверить
`ss -tlnp | grep 24224` → должен показывать только `127.0.0.1:24224`.
Убедиться, что логи воркеров по-прежнему доходят до Graylog после смены
bind (docker logging-driver на хосте достаточно близко к сайдкару, loopback
не должен ничего сломать — сам сайдкар слушает на хосте, не в контейнерской
сети).

**Criteria for done:**
- `ss -tlnp`/`ss -ulnp` на uBook показывают `24224` только на `127.0.0.1`.
- Логи всех 6 воркеров продолжают приходить в Graylog после `fluent-
  recreate` (проверить по `HOST_NAME=uBook` в интерфейсе Graylog).
- Обновить `.claude/skills/remote-workers/hosts.md` — снять пометку о
  расхождении для uBook, зафиксировать дату проверки.

**Found at:** обобщение скилла `ubook-remote-workers`→`remote-workers`,
эпик EPIC-004 (2026-08-24). Смежная задача: `CNV-17-fluent-bit-orphan-
remote-host` (done, 2026-08-01) — первый, так и не доведённый до карточки
сигнал об этом же расхождении.

**Status:** grooming — ops-правка на живом remote-хосте, нужен доступ и
короткое окно (пересоздание одного сайдкара).
