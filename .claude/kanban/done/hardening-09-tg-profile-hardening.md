### TG-профиль: три отложенных нита из код-ревью (аватар + previewable)

**Criticality:** Low/Medium

**TAGS:**
- hardening
- backend
- tech-debt

**Description:**
Три отложенных замечания из ревью хардеринга TG-профиля. Все три низко-/средне-рисковые,
поэтому вынесены сюда, а не правились в текущем PR.

1. **Ограничение памяти при загрузке аватара.**
   `MeController` / `S3Storage::getObjectContents` читает ВЕСЬ S3-объект в строку
   ДО проверки `strlen` против лимита 512 KiB. Риск низкий (в S3 пишутся только
   аватары ≤320px, крупных объектов там не бывает), но по факту cap не ограничивает
   память: сначала читаем всё, потом проверяем размер. Фикс — предварительный
   HEAD/ContentLength-чек или Range-чтение первых 512 KiB, чтобы лимит реально
   ограничивал память.

2. **Синхронная загрузка аватара в webhook-пути.**
   `TelegramUserProvisioner::findOrCreateUser` вызывает `refreshAvatar` синхронно
   прямо в обработке webhook: +3 HTTP-запроса к Telegram + S3 put в ответ на webhook.
   Обёрнуто в try/catch (best-effort — логин не падает), но медленный ответ TG API
   тормозит webhook → риск ретрая со стороны Telegram. Вынести в Messenger/async.
   (Объединяет/заменяет более раннюю заметку backend-B про «async avatar» — одной
   карточки достаточно.)

3. **Семантика флага `previewable`.**
   `serializeHistoryItem` отдаёт `previewable=true` независимо от статуса задачи,
   тогда как `preview()` для незавершённой конвертации возвращает 409. Фронт уже
   гейтит превью по `status==='completed'`, так что расхождение косметическое.
   Для консистентности API — рассмотреть, чтобы флаг тоже требовал completed-статус.

**Impact:**
1 — потенциальный (маловероятный при текущем инварианте ≤320px) неограниченный
расход памяти на чтение аватара. 2 — задержка/риск ретрая webhook при медленном
TG API. 3 — косметическое расхождение контракта API (без функционального эффекта,
т.к. фронт гейтит сам).

**Recommendation:**
- Аватар: HEAD/ContentLength pre-check либо Range-чтение первых 512 KiB в
  `S3Storage::getObjectContents` (или отдельный capped-метод) перед материализацией.
- Async-аватар: перенести `refreshAvatar` из webhook-пути в Messenger-хендлер.
- `previewable`: в `serializeHistoryItem` добавить условие completed-статуса к флагу.

**Итог реализации (2026-07-18, commit `fa078c0`):**
- Nit1: `avatarDataUri()` → `S3Storage::readCapped()` (реальный `Range: bytes=0-N`,
  ≤ `AVATAR_MAX_BYTES+1`); `getObjectContents()` удалён. Тест проверяет сам Range-хедер.
- Nit2: `TelegramAvatarRefreshMessage`(userId) + `TelegramAvatarRefreshMessageHandler`;
  `TelegramUserProvisioner` принимает `MessageBusInterface`, диспатчит после flush.
  Новый транспорт `async` в `messenger.yaml` (redis, stream `messenger.async`,
  отдельно от conv.*), раскомментирован supervisor `app-queue`
  (`messenger:consume async`). Тесты переписаны на assert dispatch, не inline.
- Nit3: `previewable = status===Completed && isPreviewable(...)` в `serializeHistoryItem`
  (call-site; helper остаётся format-only для 415-гейта в `preview()`). Новый тест.
- Верификация: phpstan [OK], cs green, `make test-php-live` 250 tests 0 failures,
  `lint:container`/`debug:messenger` OK.
- **Опер.заметка:** `supervisor.app.ini` смонтирован в 2 сервиса → `app-queue`
  консьюмер может подняться дважды; безопасно (consumer-group), но лишний процесс —
  при желании сузить запуск до одного сервиса (не блокер).

**Status:** done.
