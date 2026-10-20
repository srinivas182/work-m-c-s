## What this PR does

<!-- One or two sentences. Link the sprint item / issue. -->

## Type
- [ ] Feature
- [ ] Bug fix
- [ ] Refactor
- [ ] Docs
- [ ] Infra / CI

## Checklist (Definition of Done)
- [ ] Tests added/updated and passing (Pest / Vitest / Playwright)
- [ ] PHPStan and TypeScript checks pass
- [ ] Tenant isolation considered (no cross-tenant queries; tenant context respected under Octane)
- [ ] Branch scoping respected where relevant
- [ ] Migrations are backward-compatible (expand/contract) and reversible
- [ ] UI matches the approved design; checked on mobile (low-end Android viewport) and dark mode
- [ ] Lighthouse budget met for any public-site change
- [ ] Permissions and audit logging added for any new action
- [ ] Docs / ADR updated if behaviour or architecture changed

## Screenshots / recordings
<!-- Required for any UI change: desktop + mobile -->

## Rollback plan
<!-- How to undo this safely if it misbehaves in production -->
