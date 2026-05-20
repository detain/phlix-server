# Step P.1 — Security Audit

**Phase:** P (Phase-end Audit & v1.0)
**Step:** P.1
**Depends on:** O.7 (Release process)
**Review:** No (audit only)
**Target repos:** `detain/phlex-server` (local: `/home/sites/phlex/`), `detain/phlex-hub` (local: `/home/sites/phlex-hub/`), client repos
**Estimated subagent type:** code-reviewer / general-purpose (3 parallel subagents)

## 1. Goal

Conduct a comprehensive OWASP Top 10 security audit against the Phlex codebase before v1.0 release. Deliver a finding list with severity ratings and remediation guidance.

## 2. Context

Read first:
- `PHLEX_EXPANSION_PLAN.md` §13 (v1.0 criteria)
- `RELEASE_PROCESS.md` (pre-release checklist)
- `docs/dev/pairing-protocol.md` (server↔hub pairing)
- `docs/dev/relay-protocol.md` (WS reverse-tunnel)
- Wave 1 security fixes (phlex-server PR #76): ldap_escape, OIDC PKCE S256+state CSRF, Trakt OAuth state validation, TLS verify_peer

## 3. Scope

### P.1a — Server audit (phlex-server)

OWASP Top 10 against the server codebase:
- **A01 Broken Access Control** — verify all /api/v1/* endpoints enforce auth + ownership checks
- **A02 Cryptographic Failures** — verify JWT secret strength, password hash cost, no hardcoded creds
- **A03 Injection** — verify parameterized queries everywhere, no eval(), no shell injection
- **A04 Insecure Design** — review Phase C (hub pairing), Phase K (request UI), Phase L (webhooks)
- **A05 Security Misconfiguration** — verify error handling doesn't leak stack traces, debug mode gated
- **A06 Vulnerable Components** — audit composer.json for outdated deps with known CVEs
- **A07 Auth Failures** — verify brute-force protection on login, rate limiting, secure session tokens
- **A08 Integrity Failures** — verify plugin signature verification, file upload validation
- **A09 Logging Failures** — verify AuditLogger covers all security events (login, claim, admin actions)
- **A10 SSRF** — verify server cannot be used to pivot to internal cloud metadata endpoints

### P.1b — Hub audit (phlex-hub)

Same OWASP Top 10 against phlex-hub codebase:
- All /api/v1/* endpoints enforce auth
- JWT handling follows same standards as server
- Rate limiting on signup/login
- No SSRF in webhook delivery
- AuditLogger covers all auth events

### P.1c — Client audit (phlex-mobile/tizen/roku/windows)

Hub-mode security:
- Auth token storage security (no plaintext tokens in localStorage/SharedPreferences)
- HTTPS certificate validation on all hub-server traffic
- Relay traffic encryption (WSS tunnel integrity)
- No sensitive data in client logs

## 4. Deliverables

For each audit area:
1. Finding description
2. Affected file(s) + line number(s)
3. Severity: Critical / High / Medium / Low / Informational
4. OWASP category mapping
5. Remediation recommendation

Output: `plans/expansion/p.1-findings.md` listing all findings.

## 5. Acceptance Criteria

- [ ] P.1a server audit complete with findings documented
- [ ] P.1b hub audit complete with findings documented
- [ ] P.1c client audit complete with findings documented
- [ ] Zero CRITICAL or HIGH findings remain unresolved
- [ ] Zero known CVEs in direct dependencies
- [ ] All findings have severity rating and remediation guidance

## 6. Git ritual

No code changes for audit-only step. Document findings in `plans/expansion/p.1-findings.md`.

```bash
cd /home/sites/phlex
git checkout -b p.1-security-audit
# ... audit work ...
git add plans/expansion/p.1-findings.md
git commit -m "Step P.1: security audit findings"
unset GITHUB_TOKEN
gh pr create --title "Step P.1: security audit findings" --body "OWASP Top 10 audit findings for v1.0"
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```
