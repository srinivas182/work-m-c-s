# Sprint 1 — Platform core: detailed specification

| | |
|---|---|
| **Sprint** | 1 of 18 · 2 – 13 November 2026 |
| **Status** | Specification — awaiting approval |
| **Depends on** | Sprint 0 (v0.1.0), PRD §3 and §15.5, ADR-0002 (hybrid tenancy), ADR-0005 (WURK ID) |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each must pass CI and be merged before the next starts |

---

## 1. Objectives

1. **Tenancy core** — every request, job, cache entry, file and log line knows which business it belongs to, and data can never cross between businesses.
2. **WURK ID** — one secure identity per person, with OTP (SMS / WhatsApp / email) and password sign-in, ready for My WURK.
3. **Access control** — roles and permissions scoped to platform, business and branch, built from each module's declarations.
4. **Audit logging** — a tamper-evident record of who did what, when, from where.

These four are the security foundation every later module stands on, so this sprint is weighted towards tests that *prove* isolation and access rules.

## 2. Out of scope (later sprints)

| Item | Sprint |
|---|---|
| Self-service signup, packages, billing | 2–3 |
| Provider settings UI (choosing the SMS / WhatsApp / email provider) | 2 |
| Dedicated databases for managed tenants, tenant move tool | 14 |
| My WURK hub and one-tap sign-in across businesses | 11 |
| Customer portal on business domains | 11 |
| Impersonation ("login as business") | 14 |

In Sprint 1, OTP messages go through a channel interface with **email (Mailpit locally)** and **log** drivers; real SMS and WhatsApp drivers plug in during Sprint 2 without code changes elsewhere.

---

## 3. Hosts and URLs

| Host | Who | Notes |
|---|---|---|
| `admin.wurk.co.za` | WURK staff (super admin, finance, support, managed services) | Separate host and session; MFA mandatory |
| `app.wurk.co.za/{business}/…` | Business owners, branch managers, staff, agents | One back office for all businesses. The business handle in the path makes URLs bookmarkable and lets an owner have two businesses open in two tabs |
| `{business}.wurk.co.za` / custom domains | Public websites and customer portal | Resolved by host (public sites arrive in Sprint 5) |

Locally: `admin.wurk.test`, `app.wurk.test`, `*.wurk.test` (documented in README).

---

## 4. Module layout

| Module | Responsibility | Depends on |
|---|---|---|
| `Tenancy` | Tenants, shards, domains, resolution, tenant context, isolation guard, provisioning | platform |
| `Identity` | WURK ID, credentials, OTP, MFA, sessions, sign-in UI | platform, tenancy |
| `Access` | Roles, permissions, scopes, branch assignments, policies | platform, tenancy, identity |
| `Audit` | Audit events, Auditable trait, hash chain, viewer | platform, tenancy, identity |

The Laravel default `App\Models\User` and `users` migration are **removed**; Identity owns people.

---

## 5. Data model

### 5.1 Central database

| Table | Key columns | Notes |
|---|---|---|
| `shards` | id, name, connection (host/port/database/credentials ref), status (`active`/`draining`/`full`), capacity, tenant_count | Credentials resolved from secrets, never stored in plain text |
| `tenants` | id (ULID), handle (unique, URL-safe), name, status (`provisioning`/`active`/`suspended`/`deleted`), database_model (`shared`/`dedicated`), shard_id, country, timezone, created_at | `handle` is used in `app.wurk.co.za/{handle}` |
| `tenant_domains` | id, tenant_id, host (unique), type (`subdomain`/`custom`), is_primary, verified_at | Lookup cached in Redis, invalidated on change |
| `identities` | id (ULID), first_name, last_name, email (unique, nullable), email_verified_at, phone (E.164, unique, nullable), phone_verified_at, password (nullable), locale, status, last_login_at | At least one of email/phone required |
| `identity_mfa` | identity_id, type (`totp`), secret (encrypted), confirmed_at, recovery_codes (encrypted, hashed) | |
| `otp_challenges` | id, identity_id (nullable), channel, destination_hash, code_hash, purpose (`login`/`verify`/`reset`), attempts, expires_at, consumed_at, ip | Codes stored only as hashes |
| `memberships` | id, identity_id, tenant_id, kind (`staff`/`customer`/`agent`), status, created_at | Membership index for My WURK; holds **no** business data |
| `platform_staff` | identity_id, status | Who can sign in to `admin.wurk.co.za` |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions` | spatie/laravel-permission with teams | Platform roles live here (team = `null`) |
| `audit_events` | see §8 | Platform-level events |
| `sessions` | Laravel default | Redis in production |

### 5.2 Tenant (shard) databases

Every table carries `tenant_id` (indexed, part of every unique index) and, where relevant, `branch_id`.

| Table | Notes |
|---|---|
| `branches` | id, tenant_id, name, code, timezone, is_default — every tenant gets a default branch |
| `members` | id, tenant_id, identity_id, display_name, status — the business-side record of a person (staff or customer) |
| `branch_member` | branch_id, member_id — branch assignment for staff |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions` | spatie teams; team = `tenant_id` |
| `audit_events` | tenant-level events |

### 5.3 IDs

Public identifiers are ULIDs (sortable, non-guessable, safe in URLs). Internal joins use the same ULIDs; no auto-increment IDs are exposed.

---

## 6. Tenancy core (sub-sprint 1.1)

### 6.1 Resolution

| Context | Resolved by |
|---|---|
| `app.wurk.co.za/{handle}/…` | `{handle}` route parameter → tenant (cached); user must hold an active membership |
| Business domain | Host → `tenant_domains` (cached) |
| `admin.wurk.co.za` | No tenant context (platform scope) unless explicitly entered for support |
| Queued job | `tenant_id` captured at dispatch and restored before `handle()` |
| Artisan | `--tenant=` option or `tenants:run` iterator |

Unknown or suspended tenants return a neutral 404 / suspended page; resolution never leaks whether a handle exists to unauthenticated users beyond the public site.

### 6.2 Tenant context

A single `TenantContext` service holds the current tenant. Entering a tenant:

1. Sets the `tenant` database connection to the tenant's shard (connection pool per shard, not per tenant).
2. Prefixes cache keys `t:{tenant_id}:`.
3. Scopes the filesystem to `tenants/{tenant_id}/`.
4. Sets permission team id to `tenant_id`.
5. Adds `tenant_id` to log context and error reports.

Leaving (end of request, end of job, `TenantContext::forget()`) reverses all of it. **Under Octane, context is reset on `RequestReceived` and `RequestTerminated`** — a request never starts with a previous request's tenant.

### 6.3 Isolation guard

- `BelongsToTenant` trait on every tenant model: global scope `where tenant_id = current`, auto-fills `tenant_id` on create, and **throws** if a tenant model is queried, created or updated with no tenant context.
- Raw queries against the `tenant` connection without context throw.
- Branch scope (§7.4) layers on top for branch-restricted users.

### 6.4 Provisioning (for testing and operations)

- `php artisan tenants:create {name} --handle= --owner-email=` — picks the least-loaded active shard, creates the tenant, primary subdomain, default branch, owner membership and owner role. (Self-service signup calls the same action in Sprint 3.)
- `php artisan shards:migrate` — runs tenant migrations shard by shard; stops on first error; records schema version per shard.
- `php artisan tenants:run {command} --tenant=*` — runs a command in each tenant context.

### 6.5 Tests that prove isolation

- Two tenants on **two different shards** plus two tenants on the **same shard**; every Tenancy, Identity, Access and Audit query is asserted to return only the current tenant's rows.
- Octane simulation: interleaved requests for tenant A and B on one worker; asserts no context, cache key or permission leaks.
- Queued job for tenant A processed while context is B — runs as A.
- Architecture test: every model in a tenant table uses `BelongsToTenant`.
- **New CI job:** backend tests run against **MySQL 8** with two shard databases (SQLite remains for the fast job). Adds about 1 minute.

---

## 7. WURK ID and sign-in (sub-sprint 1.2)

### 7.1 Sign-in methods

| Method | Rules |
|---|---|
| OTP (default) | 6 digits · valid 5 minutes · 5 attempts per code · resend after 30 s · max 5 codes per destination per hour and 20 per IP per hour · constant-time comparison · neutral messages that never reveal whether an account exists |
| Password | Min 10 characters, no forced composition rules; rejected if in a bundled list of the 100 000 most common passwords (NIST SP 800-63B); bcrypt/argon2id hashing; 5 failures → 15-minute lock per identity + IP |
| TOTP MFA | Mandatory for WURK staff on `admin.wurk.co.za`; optional for owners (setting to enforce per business arrives with Sprint 2 security settings); 10 single-use recovery codes |

### 7.2 Flows and screens (built with the Sprint 0 design system, mobile-first)

1. **Sign in** — enter email or mobile number → choose "Send code" (default) or "Use password".
2. **Enter code** — 6 separate digit boxes, paste support, auto-submit, resend timer, change destination.
3. **Password** — show/hide, "Use a code instead".
4. **MFA challenge** — authenticator code or recovery code.
5. **Choose business** — if the person belongs to several businesses on `app.wurk.co.za`; remembered as default.
6. **Forgot password** — OTP verification → set new password.
7. **Profile & security** — name, email/phone (re-verified by OTP), password, MFA enrolment (QR + manual key), active sessions with "sign out other devices".

All screens: light/dark, brand-themed per business where a business context exists, accessible (keyboard, screen reader, `autocomplete="one-time-code"`), plain-language errors.

### 7.3 Sessions

| Surface | Idle timeout | Absolute | Remember me |
|---|---|---|---|
| `admin.wurk.co.za` | 30 min | 8 h | No |
| `app.wurk.co.za` | 2 h | 30 days | Yes, 30 days |

Session ID regenerated on sign-in and privilege change; cookies `Secure`, `HttpOnly`, `SameSite=Lax`; session list stores device, IP and last activity.

---

## 8. Access control (sub-sprint 1.3)

### 8.1 Scopes and default roles

| Scope | Default roles | Notes |
|---|---|---|
| Platform | `super-admin`, `finance`, `support`, `managed-services` | Super admin bypasses permission checks but **not** audit logging |
| Business | `owner`, `branch-manager`, `staff` | `owner` cannot be removed from the last owner |
| Branch | assignment, not a role | A branch manager's data access is limited to assigned branches |

Owners and super admins can create custom roles from permissions. Permissions are declared by modules (`permissions()` in each module provider) and synchronised by `php artisan permissions:sync` on deploy — no hand-maintained lists.

### 8.2 Enforcement

- Policies for every model; controllers authorise via policies, never ad-hoc checks.
- Route middleware `can:` plus scope middleware (`platform`, `tenant`).
- Navigation items with a `permission` key are hidden when the user lacks it (extends the Sprint 0 navigation builder).
- **Branch scope:** `BelongsToBranch` trait limits branch-restricted members to their branches; owners see all branches.

### 8.3 Tests

A permission matrix test generates one case per (role × permission × scope) and asserts allow/deny, including cross-tenant attempts (a member of A with every permission in A is denied in B).

---

## 9. Audit logging (sub-sprint 1.4)

### 9.1 Event record

| Field | Content |
|---|---|
| `id` | ULID |
| `tenant_id`, `branch_id` | nullable for platform events |
| `actor_identity_id`, `actor_type` | identity, system, or job |
| `impersonator_identity_id` | ready for Sprint 14 |
| `action` | e.g. `identity.signed_in`, `role.assigned`, `tenant.created` |
| `subject_type`, `subject_id` | what was affected |
| `changes` | before/after for changed attributes; secrets and personal fields redacted |
| `ip`, `user_agent`, `request_id` | |
| `occurred_at` | UTC, microseconds |
| `prev_hash`, `hash` | SHA-256 chain per scope — any edit or deletion breaks the chain |

### 9.2 Behaviour

- `Audit::record()` API and an `Auditable` model trait for automatic create/update/delete events.
- Security events always recorded synchronously: sign-in success/failure, OTP requested, MFA changes, password change, session revoked, role/permission changes, tenant created/suspended.
- Append-only: no update or delete through the application; database user for the app has no `UPDATE`/`DELETE` on audit tables in production (documented for DevOps).
- `php artisan audit:verify` checks the hash chain.
- Viewer: filterable list (actor, action, subject, date range) for super admin (platform + any tenant) and owners (their business), with CSV export.

---

## 10. Sub-sprints and acceptance criteria

| Sub-sprint | Deliverables | Acceptance criteria |
|---|---|---|
| **1.1 Tenancy core** | Tenancy module, central + shard schemas, resolution, context, isolation guard, Octane reset, queue/cache/storage/log awareness, provisioning commands, MySQL CI job | All isolation tests in §6.5 pass on SQLite and MySQL; a tenant model queried without context throws |
| **1.2 WURK ID** | Identity module, OTP + password + TOTP, all screens in §7.2, sessions, rate limits; default User removed | Sign-in by OTP and by password works on desktop and mobile; rate limits and lockouts enforced; staff cannot reach admin without MFA; browser accessibility checks pass |
| **1.3 Access control** | Access module, scopes, default roles, permission sync from modules, policies, branch scope, navigation filtering | Permission matrix test passes including cross-tenant denial; branch manager sees only assigned branches |
| **1.4 Audit** | Audit module, hash chain, Auditable trait, security events, viewer, `audit:verify` | Every security event in §9.2 is recorded; tampering with a row is detected by `audit:verify` |

Sprint 1 is complete when all four PRs are merged into `develop` with green CI and the sprint demo is accepted.

---

## 11. Security checklist

- No tenant data reachable without tenant context (enforced, tested).
- OTP and passwords hashed; secrets encrypted at rest; no secrets in logs or audit `changes`.
- Neutral error messages; rate limits per identity, destination and IP.
- CSRF on all state-changing web routes; `SameSite` cookies; session fixation prevented.
- MFA mandatory for platform staff.
- Every privilege change audited.

## 12. Risks

| Risk | Mitigation |
|---|---|
| Isolation bug in a later module | Guard throws without context; architecture test requires `BelongsToTenant`; MySQL isolation tests in CI |
| SQLite and MySQL behaving differently | Second CI job on MySQL 8 |
| OTP delivery before real providers exist | Email and log drivers now; SMS/WhatsApp drivers in Sprint 2 behind the same interface |
| Octane state leaks | Context reset on both request events; dedicated interleaving test |

## 13. Rollback

Each sub-sprint is one PR; reverting its merge commit removes it. Sprint 1 introduces the first real schema; migrations are reversible and there is no production data yet.

## 14. Decisions to confirm

1. **Hosts:** `admin.wurk.co.za` for WURK staff; `app.wurk.co.za/{business}/…` as the single back office for owners, staff and agents; customer portal on each business's own domain (Sprint 11).
2. **OTP rules:** 6 digits, 5-minute expiry, 5 attempts, 30-second resend, hourly limits as in §7.1.
3. **Passwords:** minimum 10 characters, common-password list, no forced symbols (current NIST guidance).
4. **MFA:** mandatory for WURK staff from Sprint 1; optional for owners, with an "enforce for my team" setting in Sprint 2.
5. **Sessions:** admin 30-minute idle / 8-hour absolute; back office 2-hour idle with 30-day "remember me".
6. **Permissions library:** spatie/laravel-permission with teams (team = business).
7. **CI:** add a MySQL 8 test job (~1 extra minute per full run).
