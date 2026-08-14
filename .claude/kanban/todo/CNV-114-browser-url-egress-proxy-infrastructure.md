### Egress proxy-инфраструктура для URL browser worker

**Criticality:** High

**TAGS:**
- feature
- browser
- infrastructure
- proxy
- security

**Description:**
Infrastructure-специалист разворачивает policy-enforcing egress proxy для удалённых
URL browser-задач. Proxy является единственным сетевым выходом browser container-а
к публичному Internet.

**Problem:**
Первичная проверка URL недостаточна против redirects, DNS rebinding и connection к
private, reserved или metadata IP. Прямой egress Chromium позволяет обходить policy
на каждом последующем hop.

**Impact:**
URL capture может обратиться к внутренним сервисам и metadata endpoints, создать
SSRF или раскрыть данные сети; audit без redaction способен сохранить page body и
секреты.

**Recommendation:**
Развернуть proxy с denylist policy для public Internet: повторная canonical DNS/IP
проверка на каждом redirect и соединении, блок private/loopback/link-local/reserved/
metadata ranges, запрет user cookies, Authorization и client certificates. Сетевой
маршрут browser container-а разрешает web egress только через proxy. Proxy audit
хранит минимальные redacted технические события без page body и secrets. Не менять
API DTO, плановые gates, Chromium screenshot/recording runtime и UI.

**Acceptance Criteria:**
- Browser container не имеет прямого web egress; разрешённые URL доступны только
  через proxy, а обход proxy завершает задачу безопасной ошибкой.
- Proxy блокирует localhost, RFC1918, `::1`, link-local, reserved и metadata ranges,
  credentials, DNS rebinding и redirect в private IP на каждом hop/connection.
- Audit не хранит page body, cookies, Authorization, client certificates и secrets;
  интеграционные тесты покрывают allowed public URL и все перечисленные deny cases.
- Целевые инфраструктурные проверки проходят без новых предупреждений.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Владелец: infrastructure-специалист; граница работы — proxy и сетевое принуждение.
- Выбрана denylist policy публичного Internet, а не domain allowlist; проверка повторна
  для каждого redirect, DNS resolution и соединения.
- CNV-114 зависит от нормализованного URL contract CNV-89; CNV-90 и CNV-91 зависят
  от CNV-114 и не реализуют собственный egress path.
