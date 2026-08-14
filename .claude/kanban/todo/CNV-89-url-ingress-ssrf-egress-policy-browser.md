### URL ingress SSRF и egress policy для browser worker

**Criticality:** High

**TAGS:**
- tech-debt
- browser
- ssrf
- security

**Description:**
Добавить URL ingress и policy-enforcing egress proxy для Chromium worker.

**Problem:**
Прямой browser egress позволяет SSRF, DNS rebinding, metadata access и redirects во
внутреннюю сеть; текущий API не имеет URL input contract.

**Impact:**
URL capture может читать внутренние сервисы, metadata endpoints или произвольно
нагружать сеть, а пользователь получит небезопасные и неинформативные ошибки.

**Recommendation:**
В `POST /api/v1/convert` добавить discriminator `file|text|html|url`. URL только
absolute http/https без credentials/fragments; canonicalization, лимиты и проверка
public IP на каждом redirect/соединении. Chromium ходит только через proxy; proxy
повторяет DNS/IP policy и блокирует private/loopback/link-local/reserved/metadata.

**Acceptance Criteria:**
- URL capture доступен только basic/pro и не обходит proxy; HTML/URL modes имеют
  разные policy.
- Тесты покрывают localhost, RFC1918, `::1`, metadata, credentials, DNS rebinding,
  redirect в private IP, `file:`/`data:` и safe user-facing errors без утечек.
- Browser не передаёт пользовательские cookies/Authorization/client certificates;
  proxy audit не хранит page body/secrets.

**Decisions:** *(resolved grooming questions — keep on the card after `todo/` so the rationale survives)*
- Public Internet разрешён denylist policy, не domain allowlist; каждый redirect/DNS hop
  проверяется повторно через отдельный egress proxy.
