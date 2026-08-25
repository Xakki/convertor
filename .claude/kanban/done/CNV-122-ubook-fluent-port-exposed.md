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

**Execution Log:**
- 2026-08-25: `.env.local` на uBook переведён на `EXT_FLUENT_PORT=127.0.0.1:24224`,
  сайдкар пересоздан через `make fluent-recreate`. Бэкап старого файла —
  `/tmp/backup/convertor/backup_env.local.20260825_012014` НА САМОМ uBook.
  `ss -tlnp` и `ss -ulnp` — 24224 только на `127.0.0.1`, TCP и UDP. Все 6
  воркеров остались healthy, не пересоздавались.
- **Реальная экспозиция оказалась МЕНЬШЕ заявленной в карточке.** uBook за NAT
  (192.168.10.0/24), публичного IP нет. Проба с saBots на egress-адрес
  `95.211.47.43` — `connection refused` и на 24224, и на 22 (роутер их не
  форвардит). То есть порт не был доступен из интернета; риск был «любое
  устройство в той же локальной сети». Закрыт всё равно правильно.
- **Критерий №2 (логи всех 6 воркеров доходят до Graylog) НЕ ПРОВЕРЕН и в
  текущем состоянии непроверяем.** Запрос в Graylog по
  `container_name:convertor-remote-ubook*` не даёт НИЧЕГО за 3 суток —
  простаивающие воркеры не логируют вообще. Подтверждено только то, что
  конвейер жив: запись от `logrotate` пришла в Graylog в `22:20:30Z`, сразу
  после пересоздания (fluent-bit metrics: 1 на входе, 1 на выходе, 0 ошибок).
  **Следствие для этого хоста: отличить «доставка сломалась» от «нечего
  доставлять» нельзя, пока не пройдёт реальная задача.** Перепроверить после
  первой же конвертации на uBook.
- У 6 контейнеров в `LogConfig` остался старый `fluentd-address: 0.0.0.0:24224`.
  Безвредно — на Linux `connect()` к `0.0.0.0` уходит на петлю (проверено
  живьём). Намеренно НЕ пересоздавали: `AI_PULL_POLICY=always` утянул бы
  образ AI-воркера побочным эффектом security-фикса. Нормализуется на
  следующем плановом релизе.

