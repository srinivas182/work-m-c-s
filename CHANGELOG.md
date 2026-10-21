# Changelog

All notable changes to WURK are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Added — Sprint 1.3 (access control)
- Access module (ADR-0009): permissions declared by each module with a platform or business scope; system roles defined in code (super-admin, finance, support, managed-services; owner, branch-manager, staff); custom roles from declared permissions only.
- Checks through Laravel's Gate: declared permissions answered by Access, everything else falls through to policies. Business permissions apply only inside the current business; super admins get no business permissions inside a business.
- Team records, role assignments and branch assignments on the shard; `BelongsToBranch` limits branch-restricted members to their branches.
- Guards: a business always keeps an owner; WURK always keeps a super admin; custom roles cannot mix scopes or be deleted while assigned.
- Policies for businesses and branches; navigation items hidden without the required permission.
- `permissions:sync` (validates system roles, prunes undeclared permissions from custom roles on the central database and every shard); `identities:create --role` and `--staff-role`.
- Exhaustive permission-matrix test (every role × permission × place).

### Added — Sprint 1.2 (WURK ID)
- Identity module: one WURK ID per person (email and/or mobile), business memberships, platform staff.
- Sign-in with one-time codes (6 digits, 5 minutes, 5 attempts, 30-second resend, hourly limits per address and IP; stored only as keyed hashes; neutral responses) or password (min 10 characters, ~98 000 common passwords rejected, lockout after 5 failures).
- Authenticator-app MFA (RFC 6238, replay-protected) with ten single-use recovery codes; mandatory for WURK staff, optional for everyone else. Nobody is signed in until every required factor passes.
- Sessions: admin 30-minute idle / 8-hour maximum; back office 2-hour idle with optional 30-day remember-me; device list and "sign out other devices"; password change signs out other devices.
- Screens: sign in, code entry, password, MFA challenge and set-up, recovery codes, forgot password, choose business, account & security; business home and admin placeholders.
- `identities:create` to bootstrap staff and team members; `scripts/e2e-setup` for isolated browser-test environments.
- Security events dispatched for the Audit module (Sprint 1.4).

### Fixed
- Business handles can no longer capture platform paths on the back-office host (Laravel matches domain routes first); an architecture test keeps every platform path reserved.

### Added — Sprint 1.1 (tenancy core)
- Tenancy module: shards, tenants and domains in the central database; branches in shard databases.
- Tenant context: shard connection per shard, tenant in log and queued-job context, scoped cache keys and storage, URL defaults; reset at Octane request boundaries and after every queued job.
- Isolation guard: `BelongsToTenant` filters every query to the current tenant, fills `tenant_id`, and throws on use without context or on another tenant's rows. `CentralModel` marks central data; an architecture test requires every model to be one or the other.
- Resolution by back-office path (`app.{root}/{handle}`) and by host (subdomains and custom domains), cached by id and invalidated on change; `Route::tenant()` and `Route::tenantSite()` route macros.
- Commands: `shards:register`, `shards:migrate` (shard-by-shard, stops on first failure), `tenants:create`, `tenants:run`.
- CI job running the full suite on MySQL 8 with two real shard databases; local dev stack creates and registers two shards.

## [0.1.0] — 2026-10-21 — Sprint 0: Foundations

### Added — Sprint 0.1 (skeleton)
- Laravel 13 application with React 19, TypeScript 6 (strict), Inertia v3, Tailwind CSS v4, Vite 8, pnpm.
- Module system: `modules/*/module.json` manifests, `ModuleRegistry` (discovery, validation, dependency ordering, cycle detection), `ModuleServiceProvider` base class (migrations, web routes, versioned `/api/v1` routes, contribution declarations).
- `php artisan wurk:modules` and `php artisan wurk:make-module`.
- Platform module (kernel, temporary welcome page) and Health module (`/health/live`, `/health/ready`, pluggable checks via `HealthCheck` contract).
- Module-namespaced Inertia pages (`Module::Page`).
- Architecture tests enforcing module boundaries, strict types, no debug helpers, `env()` only in config.
- Pest, Larastan (level 8), Pint, ESLint, Prettier, Vitest.
- Docker Compose dev stack (FrankenPHP + Octane, Vite, MySQL 8.4, Redis, Meilisearch, Mailpit); `scripts/dev` and `scripts/check`.

### Added — Sprint 0.2 (CI)
- GitHub Actions CI: change detection, quick checks on draft PRs, full tests and build when ready for review, Docker dev-image build when Docker files change, a single always-running `CI` summary job used as the required status check.
- Concurrency cancellation of superseded runs; Composer, pnpm and PHPStan caches.
- Branch protection on `develop` and `main` (PR required, `CI` must pass).
- Feature tests no longer depend on a compiled Vite build.

### Added — Sprint 0.3 (design system)
- Brand palette engine (OKLCH): one brand colour → 12-step light and dark scales plus brand-tinted neutrals, with a WCAG 2.2 contrast guard (muted text ≥ 4.5:1, body text ≥ 7:1, focus ring ≥ 3:1, text on solid fills ≥ 4.5:1). Tested on 16 edge-case colours and 300 random colours.
- Generated design tokens (`resources/css/tokens.css`, `pnpm tokens`), semantic colour roles, type scale (Inter + Plus Jakarta Sans, self-hosted), radius, elevation, motion; light, dark and system themes without a flash on load; reduced-motion support.
- Components: Button, Input, Textarea, Field (label/hint/error wiring), Select, Checkbox, Switch, Radio group, Card, Badge, Avatar, Tooltip, Dropdown menu, Dialog (bottom sheet on mobile), Sheet, Tabs, Toast, Table, Skeleton, EmptyState, StatCard, PageHeader.
- Back-office AppShell: collapsible sidebar, top bar (search ⌘K, notifications, theme, account), mobile bottom navigation and menu sheet, skip link; navigation built from module `menu()` declarations.
- `/_design` showcase (not in production) with live brand colour lab and contrast report.
- Playwright end-to-end smoke and axe accessibility checks (desktop and mobile, light and dark) in CI, with failure annotations on the PR.
- `Table` requires a `label` and its scroll area is keyboard-focusable; `Field` supports composite controls (e.g. Select) via a render function.

### Added — Sprint 0.4 (production image)
- Cloud-agnostic production Docker image: multi-stage build, FrankenPHP + Octane, non-root user, tuned OPcache/JIT, liveness `HEALTHCHECK`, logs to stderr.
- One image, four roles via the entrypoint: `serve`, `worker`, `scheduler`, `migrate`.
- CI builds, lints (hadolint) and smoke-tests the image: health endpoints, home page, showcase not exposed, non-root, no `X-Powered-By`, Docker health `healthy`.
- `docs/deployment/docker.md`.

### Fixed
- Branch protection now applies to administrators (an admin merge could bypass the required `CI` check).

### Changed
- Removed the framework default `/up` route in favour of the Health module.
