# Tech stack

Final versions are pinned at Sprint 0 kickoff. Laravel no longer ships LTS releases (each major gets ~2 years of security fixes), so the plan includes one framework upgrade per year.

## Backend

| Layer | Choice | Rationale | Alternatives considered |
|---|---|---|---|
| Framework | Latest stable Laravel, PHP 8.4+ | Mature ecosystem, fast delivery, strong queue/event tooling | Symfony (slower delivery), Node/NestJS (team skills, ecosystem fit) |
| Architecture | Modular monolith | One deployable; clear module boundaries; any module can be extracted later | Microservices (operational cost too high for current stage) |
| Tenancy | Purpose-built tenancy layer (router + connection manager) | Hybrid sharded + dedicated DBs and automated tenant moves are core; we own the critical path. Confirmed after Phase 2 evaluation | stancl/tenancy (strong, but optimised for single-mode tenancy) |
| App server | Laravel Octane (FrankenPHP or Swoole) | Several times higher throughput per server | PHP-FPM (simpler, slower) |
| Database | MySQL 8 on Amazon Aurora or RDS | Managed backups, PITR, replicas | PostgreSQL (viable; MySQL chosen for team familiarity) |
| Connection pooling | ProxySQL | Hundreds of tenant DBs × many containers would exhaust connections | RDS Proxy (evaluate in Phase 2) |
| Cache & queues | Redis (ElastiCache) + Horizon | Priority queues and per-tenant fairness | SQS (less visibility) |
| Search | Meilisearch | Fast product/service search and chatbot matching; tenant-scoped keys | Typesense, OpenSearch |
| Analytics events | ClickHouse | Billions of page-view/event rows across 500k sites | MySQL (unsuitable at this volume) |
| Realtime | Laravel Reverb | Live notifications | Pusher (cost at scale) |
| Files | Amazon S3 (Cape Town) + image optimisation | Quotas, resizing, WebP/AVIF | — |
| Auth | Laravel Sanctum + WURK ID; OTP; MFA-ready | Fits SPA/PWA; central identity | JWT (unnecessary complexity for first-party apps) |
| Permissions | spatie/laravel-permission (tenant + branch scoped) | Proven, flexible | Custom |
| Feature flags | Laravel Pennant | Per-tenant opt-in and percentage rollout | Third-party flag services |
| Audit | spatie/laravel-activitylog + platform audit service | Who/what/when/IP | — |
| AI | Provider-agnostic layer (e.g. Prism PHP) | Swap providers; meter usage into credits | Direct SDK (lock-in) |
| Testing | Pest, PHPStan (max practical level) | Fast, readable tests; static safety | PHPUnit |

## Frontend

| Layer | Choice | Rationale |
|---|---|---|
| Back offices | React + TypeScript + Inertia.js | App-like UX with Laravel routing and security; TypeScript safety |
| Styling & components | Tailwind CSS + shadcn/ui (Radix) + design tokens | Modern, accessible, fully ownable components; tenant colours as tokens |
| Data | TanStack Query / Table, Zustand | Fast data tables and lightweight state |
| Page builder | Puck (React visual editor) | On-page editing with our own block library; GrapesJS and Craft.js evaluated |
| Public sites | Same React blocks server-rendered to HTML by a Node render service; interactive islands only; cached at Cloudflare | One component set for editor and site; minimal JS; fast on mobile; fully indexable |
| Mobile | PWA now; React Native (Expo) later | Installable, offline-capable "Record sale"; code and API reuse later |
| Testing | Vitest, Playwright (incl. visual regression), Lighthouse CI | Logic, end-to-end, design drift and performance budgets |

## Infrastructure

| Layer | Choice |
|---|---|
| Cloud | AWS Cape Town (af-south-1) primary; Azure South Africa North is a valid alternative. Service availability verified in Phase 2 |
| Compute | Docker on Amazon ECS (Fargate), auto-scaling; EKS only if needed |
| IaC | Terraform |
| CDN, SSL, custom domains | Cloudflare for SaaS |
| Secrets | AWS Secrets Manager + KMS |
| Backups | Automated snapshots + PITR, S3 versioning, cross-region copy, tested restores |
| Observability | Sentry, Grafana + Prometheus / OpenTelemetry, Laravel Pulse, per-tenant uptime and SSL monitoring |
| CI/CD | GitHub Actions: Pest, PHPStan, Vitest, Playwright, Lighthouse CI, dependency scanning |
| Deployment | Blue-green, zero downtime; shard-by-shard migration rollout with auto-pause on errors |

## Module contract (extensibility)

Every module — first-party or future third-party — is a self-contained package that declares:

- migrations
- routes and API endpoints
- permissions
- menu items
- settings screens
- website blocks
- message templates (email / SMS / WhatsApp)
- reports
- events listened to and emitted
- package entitlement key

The platform discovers these on install; super admin enables the module per package. Modules communicate through the internal event bus and published contracts only. A versioned REST API (OpenAPI documented) with webhooks supports integrations and future native apps. A plugin SDK supports isolated per-tenant customisations for managed clients.
