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

> **Status:** Phase 1 — Product Requirements. No application code yet. Code begins in Sprint 0 after PRD approval.

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

## Core principles

1. **One core platform.** All tenants run the same codebase. Customisation happens through configuration, themes, blocks and isolated extensions — never by forking.
2. **Tenant isolation.** No business can ever see another business's data.
3. **Money never flows through WURK** except WURK's own subscription and credit revenue. Businesses use their own merchant accounts.
4. **Design is a feature.** Every screen must meet a big-brand visual standard and load fast on mobile data.
5. **Self-service first.** A non-technical owner must be able to run their business on WURK without calling support.
6. **Hosted in South Africa** (POPIA).
