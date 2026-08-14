### CNV-109 — Backend: append-only storage, history endpoint и retention API-аудита

**Criticality:** High

**TAGS:**
- backend
- api
- audit
- database
- retention

**Description:**
Backend-разработчик реализует append-only хранилище безопасных audit events по контракту
CNV-84, owner-scoped paginated endpoint и автоматическую очистку записей старше 90 дней.

**Problem:**
После определения безопасного event contract отсутствуют долговечное storage, выдача
журнала владельцу и исполнимый retention lifecycle.

**Impact:**
Пользователь и последующие UI/docs не смогут получить историю token-вызовов, а audit
данные будут либо потеряны, либо храниться бессрочно.

**Recommendation:**
Добавить migration/entity/repository с индексом `(owner_id, created_at)`, append-only
writer, newest-first cursor/page pagination и отдельный private-user endpoint. Cleanup
удаляет audit records старше 90 дней; storage принимает только event CNV-84.

**Acceptance Criteria:**
- Migration создаёт отдельное от `Conversion` append-only audit storage с индексом
  owner+created_at и без plaintext токенов, headers, bodies, файлов, IP и UA.
- Writer сохраняет только validated CNV-84 event и не предоставляет update/delete API
  для audit record.
- Private-user history endpoint возвращает только записи текущего owner newest-first с
  детерминированной пагинацией и корректным empty state.
- Гость/аноним не могут вызвать endpoint; owner isolation доказана functional tests.
- Scheduled cleanup удаляет records старше 90 дней и имеет покрытие boundary case.
- Backend tests зелёные для persistence, pagination, isolation, empty state, retention
  и запрета неразрешённых полей.

**Decisions:**
- **Владелец:** backend-разработчик.
- **Зависимость:** CNV-84 задаёт единственный audit-event/redaction contract.
- Эта карточка не изменяет token authenticator CNV-83, OpenAPI areas CNV-86 и frontend
  history CNV-110.
- Фильтры и поиск не входят в MVP.
