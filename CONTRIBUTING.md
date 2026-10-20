# Contributing to WURK

## Branching

- `main` — always deployable. Protected. Merge via PR only.
- `develop` — integration branch for the current sprint.
- Feature branches: `feature/<sprint>-<short-name>` (e.g. `feature/s07-booking-widget`)
- Fixes: `fix/<short-name>`; urgent production fixes: `hotfix/<short-name>` from `main`.

## Commits

Conventional Commits:

```
feat(bookings): add deposit requirement per service
fix(referrals): correct level-3 commission rounding
docs(adr): add ADR-0009 search engine choice
```

Scopes match module names: `platform`, `tenancy`, `billing`, `site-builder`, `shop`, `bookings`, `crm`, `referrals`, `agents`, `messaging`, `reports`, `branches`, `ui`, `infra`.

## Pull requests

- Small and focused. One concern per PR.
- Use the PR template. Every checklist item must be addressed.
- At least one approving review; architecture-affecting changes need the tech lead.
- **CI must be green before merge.** No exceptions.

## CI

| When | What runs |
|---|---|
| Draft PR | Quick checks: Pint, PHPStan, TypeScript, ESLint, Prettier |
| PR ready for review | Quick checks + Pest + Vitest + production build (+ Docker build if Docker files changed) |
| Docs-only change | Nothing (the `CI` check passes immediately) |
| Push to `main` / `develop` | Nothing — already verified on the PR |

Workflow: open PRs as **draft**, iterate, run `./scripts/check --full` locally, then mark **ready for review** for the single full run. The `CI` job is the only required check.

## Definition of Done (per sprint item)

1. Code reviewed and merged with green CI
2. Automated tests cover the behaviour, including tenant-isolation tests for any data access
3. Approved design implemented; design review passed
4. Documentation and ADRs updated
5. Rollback strategy documented in the PR
6. Demo-able on the staging environment

## Golden rules

- Controllers stay thin. Business logic lives in module Services / Actions.
- Modules talk to each other through events and published contracts, never by reaching into another module's internals.
- Every write action that matters is permission-checked and audit-logged.
- Never store secrets in code. Never commit `.env`.
- Database migrations must be backward-compatible and reversible — they roll out across hundreds of databases.
