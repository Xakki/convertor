### Осиротевшие worker-контейнеры от старого compose/именования

**Criticality:** Low

**TAGS:**
- infra
- cleanup
- observability

**Description:**
Рядом с текущим compose-стеком замечены два устаревших/осиротевших контейнера,
оставшихся от старых путей compose и старого именования:

- `xakki-convertor-libreoffice` — старый compose-project по пути
  `/home/xakki/xakki-convertor/...`, ранее наблюдался в статусе `unhealthy`.
- `xakki-convertor-worker-ffmpeg` — старое именование без суффикса
  `-audio`/`-video`, поднят ~4 дня.

Ни один из них не входит в сервисы текущего `docker-compose.yml` — живые воркеры
это `worker-libreoffice`, `worker-ffmpeg-audio`, `worker-ffmpeg-video` и др.

**Impact:**
Осиротевшие контейнеры зря держат ресурсы и засоряют `docker ps` / мониторинг,
маскируя реальную картину состояния воркеров.

**Recommendation:**
Убедиться, что контейнеры действительно осиротевшие (не привязаны к текущему
стеку), затем `docker rm -f` их.

**ВНИМАНИЕ:** удаление контейнеров — деструктивная операция, требует явного
подтверждения пользователя перед выполнением. Эта карточка — только фиксация
находки, ничего не удалять без «да».

**Resolution:** *(2026-07-13)* Оба осиротевших контейнера
(`xakki-convertor-libreoffice` + `xakki-convertor-worker-ffmpeg`) проверены как
не-стековые (не входят в текущий `docker-compose.yml`) и удалены `docker rm -f`;
живой стек не затронут.

**Status:** grooming.
