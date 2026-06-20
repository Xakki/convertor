### Admin panel (docs phase 5)

**Criticality:** Minor

**TAGS:**
- feature

**Description:**
From `ROADMAP.md` (Стадия 3): admin UI is skeletal, backend incomplete.

**Problem / scope:**
- Stats dashboard: API for conversion stats, user counts, revenue.
- User management: ban/unban, manual quota reset, search.
- Queue monitoring: live queue sizes, stuck-job detection.
- Conversion logs: searchable view, error filtering.

**Impact:**
No operational visibility/control for operators.

**Recommendation:**
Build admin API endpoints + bind the existing UI skeleton; reuse KeyDB queue stats for monitoring.

**Acceptance Criteria:**
- Dashboard shows real conversion/user/revenue metrics.
- Admin can ban/unban and reset quota.
- Queue sizes + stuck jobs visible.
- Logs searchable/filterable.

**Open questions:**
- Priority vs payments/prod-polish — defer until after MVP launch?
- Auth/roles for admin access (separate from user JWT)?
- Build on an admin bundle (EasyAdmin) or custom HTMX views?

**Decisions:**
- (to be filled during grooming)
