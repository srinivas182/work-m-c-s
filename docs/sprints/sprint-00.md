# Sprint 0 — Foundations: detailed specification

| | |
|---|---|
| **Sprint** | 0 of 15 (pre-beta) |
| **Status** | In progress — 0.1 ✅ (#1), 0.2 ✅ (#2), 0.3 ✅ (#8); next 0.4 production image |
| **Depends on** | PRD v1.0 (approved), ADR-0001 … 0008 |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each must pass CI and be merged before the next starts |

---

## 1. Objectives

1. A production-grade **Laravel + React/TypeScript + Inertia** application skeleton that every future module plugs into.
2. The **module contract** (ADR-0001) implemented and enforced by architecture tests, so modules cannot reach into each other.
3. The **design system foundations** in code: tokens, light/dark mode, the tenant **brand palette engine** with contrast checking, the back-office app shell and core components — at the big-brand standard in `docs/architecture/design-standards.md`.
4. **CI** that is fast (< 10 minutes), cheap on Actions minutes, and blocks merges on any failure.
5. **Local development** that starts with one command (Docker Compose).
6. **Infrastructure as code** (Terraform) for AWS Cape Town, validated in CI and ready to apply once the AWS account exists.

## 2. Out of scope (later sprints)

Tenancy and WURK ID (Sprint 1), any business feature, the Node render service and public sites (Sprint 5), Lighthouse CI on public sites (Sprint 5), actual AWS provisioning (needs account — see §10).

---

## 3. Technology versions (pinned at build time)

| Item | Choice | Note |
|---|---|---|
| PHP | 8.4 (CI, Docker); local pre-checks on 8.3 | Code must stay 8.3-compatible until the local box gets 8.4 |
| Laravel | 13.x (13.38 at kickoff) | Recorded in `composer.json` and CHANGELOG |
| Octane runtime | FrankenPHP | Single binary, HTTP/2–3, simpler images than Swoole |
| Node | 22 LTS | `.nvmrc` |
| Package manager | pnpm | Faster, disk-efficient, strict |
| React | 19 + TypeScript 6 (strict) | TypeScript 7 not yet supported by typescript-eslint |
| Inertia.js | v3 (React adapter, Laravel adapter 3.x) | Current major at kickoff |
| Styling | Tailwind CSS v4 + shadcn/ui (Radix) | CSS-first tokens |
| Icons | Lucide | Consistent, light line icons |
| Fonts | Inter (UI), plus a display face for headings, self-hosted | No third-party font requests |
| Testing | Pest (+ arch plugin), Larastan/PHPStan, Vitest + Testing Library, Playwright | |
| Style | Laravel Pint, ESLint (flat config), Prettier | |

---

## 4. Repository structure

```
/
├── app/                      # Laravel kernel only — no business logic
├── bootstrap/
├── config/
├── modules/                  # every feature lives here
│   ├── Platform/             # module registry, contract, shared kernel
│   └── Health/               # first module: health/readiness endpoints
├── resources/
│   ├── css/app.css           # Tailwind v4 + design tokens
│   └── js/
│       ├── app.tsx           # Inertia bootstrap (resolves module pages)
│       ├── design-system/    # tokens, palette engine, components
│       ├── layouts/          # AppShell (back office), AuthLayout
│       └── pages/            # platform-level pages (e.g. design showcase)
├── routes/
├── database/
├── tests/                    # cross-module + architecture tests
├── docker/                   # Dockerfiles, Caddy/FrankenPHP config
├── infra/terraform/          # IaC (see §8)
├── docs/
└── .github/workflows/
```

### 4.1 Module layout

```
modules/<Name>/
├── module.json               # manifest (see §5)
├── src/                      # PSR-4: Wurk\<Name>\
│   ├── <Name>ServiceProvider.php
│   ├── Actions/              # single-purpose business operations
│   ├── Contracts/            # the ONLY classes other modules may use
│   ├── Events/
│   ├── Http/Controllers/     # thin
│   ├── Models/
│   └── Policies/
├── database/migrations/
├── routes/{web,api}.php
├── resources/js/pages/       # Inertia pages, namespaced "<Name>::Page"
└── tests/{Unit,Feature}/
```

---

## 5. Module contract

`module.json`:

```json
{
  "name": "Health",
  "key": "health",
  "version": "1.0.0",
  "core": true,
  "entitlement": null,
  "provides": ["routes", "permissions", "menu", "settings", "blocks", "templates", "reports"],
  "depends_on": ["platform"]
}
```

`Wurk\Platform\Modules\ModuleServiceProvider` (abstract base) gives each module a uniform way to declare:

| Declaration | Method | Consumed by |
|---|---|---|
| Migrations | auto-loaded from `database/migrations` | migrator |
| Routes | auto-loaded `routes/web.php`, `routes/api.php` (api versioned `/api/v1`) | router |
| Permissions | `permissions(): array` | permissions seeder (Sprint 1) |
| Menu items | `menu(): array` | back-office navigation |
| Settings screens | `settings(): array` | settings hub (Sprint 2) |
| Website blocks | `blocks(): array` | page builder (Sprint 4) |
| Message templates | `messageTemplates(): array` | messaging (Sprint 10) |
| Reports | `reports(): array` | reports (Sprint 13) |
| Event listeners | `listen(): array` | event bus |
| Entitlement key | from manifest | package entitlements (Sprint 2) |

`ModuleRegistry` discovers `modules/*/module.json`, validates manifests, resolves dependency order, registers providers, and exposes `php artisan wurk:modules` (list, status) and `php artisan wurk:make-module {Name}` (scaffolds the full layout above).

### 5.1 Enforced rules (Pest architecture tests)

- A module may only use another module's `Contracts\` namespace or events — never its Models, Actions or controllers.
- Controllers never use Eloquent models' query builder directly beyond route-model binding; they call Actions.
- No `dd`, `dump`, `ray`, `env()` outside config.
- `app/` contains no business logic (only kernel, exceptions, base providers).
- All classes `declare(strict_types=1)`.

---

## 6. Design system foundations (sub-sprint 0.3)

### 6.1 Tokens

CSS variables via Tailwind v4 `@theme`, mirrored in a TS token file:

- **Colour:** neutral scale (12 steps), brand scale (12 steps, generated), semantic (success, warning, danger, info), surfaces (background, raised, overlay, sunken), borders, focus ring
- **Typography:** type scale (12–60px), weights, line heights, letter spacing; UI font + display font
- **Spacing:** 4px base scale
- **Radius:** sm / md / lg / xl / full
- **Elevation:** 5 soft layered shadows tuned for light and dark
- **Motion:** durations and easings; all animations respect `prefers-reduced-motion`

Light and dark themes for back offices via `data-theme` on `<html>`, defaulting to system preference, persisted per user later (Sprint 1).

### 6.2 Brand palette engine (`design-system/brand/`)

The heart of tenant branding:

1. Input: one hex brand colour (optionally a second accent).
2. Convert to OKLCH; generate a 12-step perceptually even tonal scale (light → dark) preserving hue.
3. Pick the accessible "on-brand" text colour (white or near-black) per step.
4. **Contrast guard:** compute WCAG 2.2 contrast for every text/background pairing used by components; if a pairing fails AA, auto-adjust lightness until it passes and report the adjustment.
5. Output: CSS variables applied at runtime.

Fully unit-tested (Vitest), including edge cases: pure yellow, near-white, near-black, low-saturation greys, neon colours.

### 6.3 Core components (shadcn/ui based, restyled to WURK tokens)

Button (variants, sizes, loading), Input, Textarea, Select, Checkbox, Switch, Radio, Label + field error, Card, Badge, Avatar, Tooltip, Dropdown menu, Dialog, Sheet (mobile drawer), Tabs, Toast, Table (TanStack-ready), Skeleton, **EmptyState** (illustration + message + action), Stat card (KPI), Page header.

### 6.4 App shell (back office)

- Collapsible sidebar on desktop; bottom navigation + sheet menu on mobile
- Top bar: search trigger (⌘K placeholder), notifications slot, theme toggle, user menu
- Content area with page header pattern and max-width rules
- Navigation built from module `menu()` declarations

### 6.5 Design showcase page

Internal route `/_design` (local and staging only): every token and component in light and dark, plus a **live brand colour picker** that re-themes the page through the palette engine — the visual sign-off surface for the design system.

### 6.6 Design deliverables outside code (designers)

High-fidelity Figma for: wurk.co.za home and pricing, signup wizard, tenant dashboard, super admin dashboard, booking widget (mobile), customer portal (mobile). These drive Sprints 1–3 and are reviewed against the benchmarks in the design standards.

---

## 7. CI/CD (sub-sprint 0.2)

Designed for < 10 minutes and minimal Actions usage.

| Trigger | Runs |
|---|---|
| Draft PR (open/update) | **Quick:** Pint (test mode), PHPStan, ESLint, `tsc --noEmit`, Prettier check |
| PR marked **ready for review** / updated while ready | **Full:** quick checks + Pest (parallel) + Vitest + production build + Playwright smoke (once UI exists) + Terraform fmt/validate (if `infra/` changed) |
| Push to `main` / `develop` after merge | **Nothing** (already verified on the PR) |
| Docs-only changes | **Nothing** (`paths-ignore`) |

- `concurrency` group per PR with `cancel-in-progress: true` — superseded runs cancel automatically.
- Path filters: backend jobs skip when only frontend changed and vice versa.
- Caching: Composer, pnpm store, PHPStan result cache.
- Required status check on `develop` and `main`: the full CI job. Branch protection enabled at the end of 0.2.
- Note: while the repository is public, GitHub Actions minutes on standard runners are free; the rules above still apply so cost stays low when it goes private.

Local pre-push script `scripts/check` runs the same quick checks plus affected tests before anything is pushed.

---

## 8. Local development & infrastructure (sub-sprints 0.1 and 0.4)

### 8.1 Docker Compose (local)

Services: `app` (FrankenPHP + Octane, hot reload via `--watch`), `vite`, `mysql` (8.x), `redis`, `meilisearch`, `mailpit`. One command: `./scripts/dev up`. Seeded `.env.example` with no secrets.

### 8.2 Production image

Multi-stage Dockerfile: Composer deps (no dev) → pnpm build → FrankenPHP runtime image, non-root user, OPcache tuned, healthcheck on `/health/ready`.

### 8.3 Health module

- `GET /health/live` — process is up
- `GET /health/ready` — DB, Redis and cache reachable; returns JSON with per-check status and timing
- Used by ECS health checks, uptime monitoring and the update engine later

### 8.4 Terraform (AWS af-south-1)

```
infra/terraform/
├── modules/
│   ├── network/        # VPC, subnets across 3 AZs, NAT, security groups
│   ├── ecr/            # image repositories
│   ├── ecs/            # cluster, services, autoscaling (web, worker, scheduler)
│   ├── database/       # Aurora MySQL cluster (central DB + first shard)
│   ├── cache/          # ElastiCache Redis
│   ├── storage/        # S3 buckets (media, backups) with versioning + encryption
│   ├── secrets/        # Secrets Manager + KMS keys
│   └── observability/  # CloudWatch log groups, alarms
└── environments/
    ├── staging/
    └── production/
```

Remote state in S3 with DynamoDB locking. CI runs `terraform fmt -check` and `terraform validate` only; **no apply** until the AWS account and credentials exist. Each module documents inputs, outputs and estimated monthly cost.

---

## 9. Sub-sprints and acceptance criteria

| Sub-sprint | Deliverables | Acceptance criteria |
|---|---|---|
| **0.1 Skeleton** | Laravel + React/TS + Inertia + Tailwind v4; module system (registry, base provider, manifest, `wurk:modules`, `wurk:make-module`); Platform + Health modules; architecture tests; Pint/PHPStan/ESLint/Prettier configs; Docker Compose; `scripts/dev`, `scripts/check` | Fresh clone → `./scripts/dev up` → app loads; `/health/ready` green; `wurk:make-module Demo` scaffolds a working module whose page renders; architecture tests fail on a deliberate cross-module violation |
| **0.2 CI** | Workflows per §7; caching; branch protection on `develop` and `main` | Draft PR runs quick checks only; ready PR runs full suite in < 10 min; docs-only PR runs nothing; merge blocked when CI red |
| **0.3 Design system** | Tokens, light/dark, palette engine with tests, core components, AppShell, `/_design` showcase, Playwright smoke + axe checks | Palette engine passes all edge-case tests; every showcase pairing passes WCAG AA in both themes; showcase reviewed and approved visually (desktop + mobile) |
| **0.4 Production image** | Cloud-agnostic production Dockerfile with healthcheck, built and verified in CI | Image builds and passes `/health/ready` in CI |

Sprint 0 is complete when all four PRs are merged into `develop` with green CI and `develop` is merged to `main`.

---

### 8.5 Cloud decision (update 2026-10-20)

The client has not yet chosen between AWS (Cape Town) and Azure (South Africa North). Cloud-specific infrastructure as code is **deferred** until that decision and is no longer part of Sprint 0. Everything delivered in Sprint 0 is cloud-agnostic (Docker images, Compose, CI). The Terraform layout in §8.4 will be re-specified for the chosen provider.

## 10. Dependencies outside the code (start now)

| Item | Owner | Why now |
|---|---|---|
| Cloud provider decision (AWS Cape Town or Azure South Africa North), then account | WURK | Needed to write and apply infrastructure code and stand up staging |
| Cloudflare account (for SaaS add-on later) | WURK | Domains and edge caching from Sprint 5 |
| wurk.co.za DNS access | WURK | Staging subdomains |
| DebiCheck provider application | WURK | Long onboarding lead time; needed by Sprint 3 |
| Meta Business verification + WhatsApp Business Platform | WURK | Verification and template approval take weeks; needed by Sprint 2–3 |
| Figma high-fidelity designs (§6.6) | Designers | Drive Sprints 1–3 |
| Legal opinion on referral programme; IP / ownership agreement | WURK + MCS | Before beta |

---

## 11. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| PHP 8.3 locally vs 8.4 in CI | Code using 8.4-only syntax passes CI but fails local checks | Keep code 8.3-compatible during Sprint 0; PHPStan configured for 8.3 target |
| Tailwind v4 / shadcn compatibility gaps | Component rework | Pin versions; components copied into repo (shadcn model), so we own them |
| Designs not ready before Sprint 1 | Rework of screens | Design system ships first; screens follow approved Figma only |
| AWS account delay | No staging environment | Local Docker parity; Terraform ready to apply same day |

## 12. Rollback

Each sub-sprint is a single PR. Reverting the merge commit on `develop` fully removes it; no database or infrastructure state is created in Sprint 0.

## 13. Decisions to confirm

1. FrankenPHP as the Octane runtime (vs Swoole).
2. pnpm as the package manager.
3. Tailwind CSS v4.
4. Inter + one display typeface (designers may propose alternatives in Figma).
5. `/_design` showcase available on staging for client review (not production).

---

## 14. Progress log

| Sub-sprint | PR | Result |
|---|---|---|
| 0.1 Skeleton | #1 | Merged. 28 backend + 6 frontend tests; PHPStan level 8 clean |
| 0.2 CI | #2 | Merged. Draft run (quick checks) 0.7 min; full run 1.7 min. Branch protection on `develop` and `main` with `CI` as the single required check |
| 0.2 follow-up | #5, #6 | Protection test showed admins could bypass the required check; admin enforcement enabled, test commit reverted via green PR |
| 0.3 Design system | #8 | Merged. 36 frontend + 31 backend tests; 14 browser checks (desktop + mobile, light + dark) with axe: no serious or critical issues. Browser checks caught and fixed: logo and avatar accessible names, faint welcome text, mobile page overflow (bottom nav untappable), unlabelled Select, keyboard-inaccessible table scroll |
