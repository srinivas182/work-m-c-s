# OWASP ASVS Level 2 — WURK self-assessment

**Version:** ASVS 4.0.3, Level 2 · **Assessed:** Sprint 18.1 (November 2026) against release 0.19.0 · **Owner:** engineering · **Review:** every release that touches a control, and before each external penetration test.

How to read this: each chapter lists the controls that matter for WURK, **how WURK meets them**, and **the test or check that proves it** (so a regression fails CI rather than surfacing in a pen test). "Gap" rows are open work with where it's scheduled. Controls that don't apply (no GraphQL, no SOAP, no WebSockets yet) are left out.

Status: ✅ met and tested · 🟡 met, partly tested or manual · ⏳ gap, scheduled

## V1 Architecture, design and threat modelling

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 1.1 Secure SDLC, threat model | Every sprint has a written spec with risks; modules can only use other modules' contracts; Sprint 18 is the hardening pass before launch | `docs/sprints/*.md`, `tests/Architecture/ModulesTest.php` | 🟡 |
| 1.2 Authentication architecture | One identity per person across surfaces (admin, back office, My WURK, portal); separate session policies per surface | `Identity` module, `config/wurk.php` `identity.sessions` | ✅ |
| 1.4 Access control architecture | Permissions declared by modules, roles in code (`SystemRoles`), checked by middleware `permission:*` and `Gate`; businesses isolated by tenant | `AccessManager`, `tests/Architecture/RoutesTest.php` | ✅ |
| 1.5 Input/output architecture | Server-side validation on every write (Laravel validation); output encoded by React and Blade `{{ }}`. Raw HTML is inserted in two places only, both from trusted renderers (help articles: Markdown with HTML stripped; the MFA QR code: SVG generated on the server) | code review | 🟡 |
| 1.6 Cryptographic architecture | Laravel's encrypter (`APP_KEY`) for secrets and records; payment, messaging and calendar credentials, MFA secrets and mandates stored with encrypted casts | `Records` module; `encrypted` / `encrypted:array` casts on `PaymentAccount`, `ProviderSetting`, `MeetingConnection`, `IdentityMfa`, `Mandate` | ✅ |
| 1.8 Data protection | Personal data classified by module; POPIA export, erasure, retention; Watchtower masks personal data before storing errors | `Data`, `Crm` (erase), `Watchtower\Support\Mask` + tests | ✅ |
| 1.14 Configuration | Twelve-factor `.env`; containers run as a non-root user; production image without dev dependencies | `docker/production/Dockerfile` | ✅ |

## V2 Authentication

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 2.1 Passwords | Minimum 10 characters, up to 128, all characters allowed, no composition rules, common passwords refused | `PasswordPolicy`, `NotCommonPassword` + tests | ✅ |
| 2.2 Anti-automation | Password lockout after 5 attempts for 15 minutes; one-time codes rate-limited per destination (5/hour) and per IP (20/hour); every public write route throttled | `SignInController`, `OtpManager`, **`tests/Architecture/RateLimitsTest.php`** | ✅ |
| 2.2.3 Notify on security events | Not yet: no email on a sign-in from a new device or a password/MFA change | — | ⏳ |
| 2.5 Credential recovery | Reset by one-time link/code, expires, single use; no hints or secret questions | `PasswordResetController` + tests | ✅ |
| 2.7 Out-of-band verifiers | One-time codes: 6 digits, 5 minutes, 5 attempts, hashed at rest, single use | `OtpManager` + tests | ✅ |
| 2.8 One-time passwords (TOTP) | TOTP for WURK staff (required) and business teams (optional or required by the owner), with recovery codes | `Mfa\Totp`, `RecoveryCodes`, `RequireTeamMfa` + tests | ✅ |
| 2.10 Service credentials | No credentials in code; provider keys in `.env` or encrypted in the database; secret scanning on every change | gitleaks in CI (18.1b) | ✅ |

## V3 Session management

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 3.2 Session binding | New session ID on sign-in and sign-out; tokens from Laravel's CSPRNG | `SignInFlow` (`regenerate()`), Identity tests | ✅ |
| 3.3 Session termination | Idle and absolute limits per surface (admin 30 min idle / 8 h absolute); "sign out other devices"; other sessions revoked when the password changes | `SessionGuardian`, `SessionRegistry`, `AccountController` + tests | ✅ |
| 3.4 Cookie-based sessions | `HttpOnly`; `Secure` wherever WURK runs on HTTPS (18.1); `SameSite=Lax`; host-only (no `Domain`) so a business's site never shares the back office's session | `config/session.php` | ✅ |
| 3.7 Re-authentication | The current password is needed to change it; turning the authenticator off needs a current code from it. Not yet before starting a full data export | `AccountController` + tests | ⏳ 18.2b |

## V4 Access control

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 4.1 Enforced server-side, deny by default | Every back-office route behind `auth` + `permission:*`; menu items hidden but routes checked independently | route middleware; module feature tests | ✅ |
| 4.2 Operation level (IDOR) | Every tenant query scoped by `BelongsToTenant`; raw queries must filter `tenant_id`; data on separate shards. **Isolation sweep**: two businesses on one shard, one row in every table of the other (shard and central), every back-office route (all methods) and every route on a business's website address called with the other's IDs and tokens — no leak, no change, no crash; the sweep proves it can catch a leak | `tests/Architecture/RawQueriesTest.php`, **`tests/Feature/Isolation/IsolationSweepTest.php`** (SQLite and MySQL) | ✅ |
| 4.3 Administrative interfaces | Admin on its own host, staff only, TOTP required, optional IP allow-list | `AdminIpAllowList`, staff tests | ✅ |
| 4.3 Support access | WURK staff see a business only with the owner's time-limited consent; sensitive actions never allowed; audited "on behalf of" | `SupportAccess` + tests (16.4) | ✅ |

## V5 Validation, sanitisation and encoding

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 5.1 Input validation | Laravel validation on every request that writes; models are filled from validated arrays only — saving `$request->all()`, `input()` or `except()` fails CI | feature tests; Semgrep rule `php-mass-assign-request-all` | ✅ |
| 5.2 Sanitisation | Page-editor content is rendered as React elements, never raw HTML; AI-written text has tags stripped; help articles are Markdown with HTML stripped and unsafe links refused; screenshots re-encoded | `SiteGenerator`, `HelpCentre::render`, `Support\Screenshots` | ✅ |
| 5.3 Output encoding, injection | React escapes by default; Blade `{{ }}`; parameterised queries only (Eloquent / query builder); spreadsheet exports formula-safe | `Data\Exporter` test; code review | ✅ |
| 5.3.3 XSS defence in depth | **Content Security Policy with a per-request nonce** on every platform page; public sites `script-src 'self'` | `SecurityHeadersTest`, every e2e test fails on a CSP violation | ✅ |
| 5.5 Deserialisation | No `unserialize()` of user data; JSON only | static rules (18.1b) | ✅ |

## V7 Errors and logging

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 7.1 No sensitive data in logs | Audit log redacts secrets; Watchtower masks emails, numbers, IDs and quoted values; no request bodies stored | `config('wurk.audit.redact')`, `WatchtowerTest` masking | ✅ |
| 7.2 Security events logged | Sign-ins, failures, permission and role changes, support access, exports, money movements in the audit trail | `Audit` module + tests | ✅ |
| 7.4 Error handling | Generic error pages (no stack traces with `APP_DEBUG=false`); errors grouped and alerted by Watchtower | production image sets `APP_DEBUG=false`; `Watchtower` | ✅ |

## V8 Data protection

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 8.1 Server-side protection | Private files on private disks, served only by controllers that check access; no public `/storage` routes (18.1) | `SecurityHeadersTest` (routes gone), module tests | ✅ |
| 8.2 Client-side | `Cache-Control: no-store` on record links and payment pages; nothing sensitive in `localStorage` (theme and the last error only) | `PublicRecordsController`, `PublicPaymentsController` | 🟡 |
| 8.3 Sensitive private data | Health records encrypted at rest with per-access audit; POPIA export and erasure; data in South Africa | `Records`, `Data`, `Crm` erase tests | ✅ |

## V9 Communications

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 9.1 TLS everywhere | TLS terminated at the load balancer (Sprint 17); **HSTS** (18.1); `TRUSTED_PROXIES` so WURK knows the request was HTTPS | `SecurityHeadersTest` | 🟡 until 17 |
| 9.2 Server communications | Outbound calls to providers over HTTPS with verification; webhooks verified by signature or secret | provider tests | ✅ |

## V10 Malicious code

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 10.2 No back doors / unsafe functions | Static rules for `eval`, shell, `unserialize` and SQL string building | Semgrep (18.1b) | ✅ |
| 10.3 Integrity of dependencies | Lock files committed; `composer audit` and `pnpm audit --prod` fail CI on high/critical; Dependabot weekly | CI (18.1b) | ✅ |

## V12 Files and resources

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 12.1 Upload limits | Size and count limits per upload type; storage quota per package | module tests | ✅ |
| 12.2 File integrity | Type checked by content, not name; images re-encoded; other files scanned by ClamAV before release | `VirusScanner`, CI job "malware scanner (real ClamAV)" | ✅ |
| 12.3 File execution | Uploads stored outside the web root with generated names; never executed | disk config | ✅ |
| 12.6 SSRF | Server fetches only allow-listed provider hosts; AI and image providers by fixed URLs | code review | 🟡 |

## V13 API and web services

There is no public API yet (the module system reserves `/api/v1`). Every JSON endpoint today is used by WURK's own pages: cookie-authenticated with CSRF protection on writes, or stateless with its own secret (provider webhooks, the uptime webhook) and rate-limited.

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 13.2 RESTful | CSRF on every cookie-authenticated write (Laravel); webhooks verified by signature or secret | framework; Payments/Watchtower tests | ✅ |

## V14 Configuration

| Control | How WURK meets it | Proof | Status |
|---|---|---|---|
| 14.1 Build | Reproducible builds from lock files; CI on every change; production image built in CI | `.github/workflows/ci.yml` | ✅ |
| 14.2 Dependencies | Audits, Dependabot, no assets from third-party CDNs | `tests/Architecture/NoExternalAssetsTest.php` | ✅ |
| 14.3 Unintended disclosure | `APP_DEBUG=false`; `expose_php = Off`; security headers on every response | production image, `SecurityHeadersTest` | ✅ |
| 14.4 HTTP security headers | CSP, HSTS, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` on WURK's responses; the basic ones also on static files (production Caddyfile) | `SecurityHeadersTest`; OWASP ZAP baseline in CI (`.zap/rules.tsv`: a missing header fails) | ✅ |
| 14.5 Request validation | Requests are routed by host: WURK's own domains and businesses' addresses; anything else finds no business | tenancy resolution tests | 🟡 |

## Where the checks run

| Check | When | Fails CI on |
|---|---|---|
| `composer audit` | every change (job "Security · audits, secrets & static rules") | any PHP advisory |
| `pnpm audit --prod` | every change | a high or critical advisory in a production JavaScript dependency |
| gitleaks (`.gitleaks.toml`) | every change — the PR's commits | any secret |
| Semgrep with WURK's rules (`.semgrep.yml`) | every change | eval/unserialize/shell, SQL built from variables or request input, saving `$request->all()`, predictable secrets, unescaped request data in Blade, raw HTML in React outside two reviewed places |
| OWASP ZAP baseline (`.zap/rules.tsv`) | every change ready for review (with the performance gate) | a page without clickjacking protection or a CSP, or a cookie without SameSite (other headers and HttpOnly are reported, and enforced by `SecurityHeadersTest`) |
| Rate limits (`tests/Architecture/RateLimitsTest.php`) | every change | a public write route without a throttle |
| CSP in the browser (`e2e/fixtures.ts`) | every browser test | anything blocked by the Content Security Policy |
| Dependabot (`.github/dependabot.yml`) | weekly (Composer, npm), monthly (Actions, Docker) | — opens grouped update PRs |

## Open gaps

| Gap | Plan |
|---|---|
| Email on sign-in from a new device and on password/MFA changes (2.2.3); password or code before a full data export (3.7) | **18.2b** |
| TLS and HSTS verified on the real load balancer (9.1) | **Sprint 17** |
| External penetration test (grey-box, CREST-accredited) — no open critical/high | **Sprint 17**, on staging |
