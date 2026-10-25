# WURK

**WURK** (wurk.co.za) is a multi-tenant SaaS platform that gives small and medium businesses — starting in South Africa, then expanding across Africa — everything they need to run and grow online from one back office:

- A professional, mobile-first **website** with a visual drag-and-drop editor and premium category templates
- **Online shop** with products, variants, stock and the business's own payment gateways and couriers
- **Appointments** with staff and branch availability, deposits and reminders
- **CRM** for leads, pipelines and opportunities
- **Customer referral programme** — every customer is an affiliate with a wallet, referral link and QR code
- **Marketing** via email, SMS and WhatsApp campaigns and offers
- **Reports**, staff management, audit logs, a rule-based chatbot and more

WURK earns from subscriptions (self-managed or managed packages), add-on credits and managed services. A network of **agents** sells WURK packages and earns commission through a sponsor hierarchy.

> **Status:** Sprint 5 (website engine: static HTML at publish, mobile 99–100 on every template, SEO and AI answers) released — v0.8.0. Next: Sprint 8 (appointments). Plan: docs/sprint-plan.md.

## Documentation

| Document | Description |
|---|---|
| [Product Requirements (PRD)](docs/PRD.md) | Full functional and non-functional requirements |
| [Sprint plan](docs/sprint-plan.md) | 16 sprints to closed beta |
| [Tech stack](docs/architecture/tech-stack.md) | Stack choices with rationale |
| [Tenancy & data architecture](docs/architecture/tenancy-and-data.md) | Hybrid sharded / dedicated database model, WURK ID, rollouts |
| [Design standards](docs/architecture/design-standards.md) | Visual quality bar, design system, performance budgets |
| [Architecture Decision Records](docs/adr/) | Each key decision with context, alternatives and consequences |
| [Contributing](CONTRIBUTING.md) | Branching, commits, PRs, Definition of Done |
| [Docker deployment](docs/deployment/docker.md) | Production image: build, run roles, environment, health checks |

## Local development

Requirements: Docker Desktop (or Docker Engine + Compose), PHP 8.3+, Composer, Node 22 and pnpm for running checks on the host.

```bash
./scripts/dev up        # build and start the stack, install deps, migrate
./scripts/check         # quick checks: style, static analysis, types, lint
./scripts/check --full  # plus all tests and a production build — run before marking a PR ready
```

| Service | URL |
|---|---|
| App (FrankenPHP + Octane, auto-reload) | http://localhost:8000 |
| Health | http://localhost:8000/health/ready |
| Vite dev server | http://localhost:5173 |
| Mailpit | http://localhost:8025 |
| MySQL / Redis / Meilisearch | localhost:3306 / 6379 / 7700 |

### People and sign-in

```bash
./scripts/dev artisan identities:create you@wurk.co.za --staff               # first WURK staff account
./scripts/dev artisan identities:create owner@glow.co.za --tenant=glow-salon  # a business team member
```

Sign in at `admin.wurk.test/sign-in` (staff, authenticator app required) or `app.wurk.test/sign-in`. Locally, emailed codes land in Mailpit and SMS codes in the application log until Sprint 2 connects real providers.

### Businesses and shards

```bash
./scripts/dev artisan tenants:create "Glow Salon"        # provision a business on the least-loaded shard
./scripts/dev artisan shards:migrate                     # roll tenant migrations out shard by shard
./scripts/dev artisan tenants:run "cache:clear" --tenant=glow-salon
```

Local hosts: `admin.wurk.test` (WURK staff), `app.wurk.test/{business}` (back office), `{business}.wurk.test` (public sites). Point them at 127.0.0.1 in your hosts file.

### Modules

Every feature is a module under `modules/` (see [ADR-0001](docs/adr/0001-modular-monolith.md) and the [Sprint 0 spec](docs/sprints/sprint-00.md#5-module-contract)).

```bash
php artisan wurk:modules                 # list installed modules in boot order
php artisan wurk:make-module Bookings    # scaffold a new module
composer dump-autoload
```

Modules may use another module only through its `Contracts\` and `Events\` namespaces. Architecture tests enforce this.

## Core principles

1. **One core platform.** All tenants run the same codebase. Customisation happens through configuration, themes, blocks and isolated extensions — never by forking.
2. **Tenant isolation.** No business can ever see another business's data.
3. **Money never flows through WURK** except WURK's own subscription and credit revenue. Businesses use their own merchant accounts.
4. **Design is a feature.** Every screen must meet a big-brand visual standard and load fast on mobile data.
5. **Self-service first.** A non-technical owner must be able to run their business on WURK without calling support.
6. **Hosted in South Africa** (POPIA). Cloud provider (AWS or Azure) to be decided by the client.
