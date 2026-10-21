# ADR-0009: Purpose-built access control instead of spatie/laravel-permission

- **Status:** Accepted (supersedes Sprint 1 spec §14 decision 6)
- **Date:** 2026-10-21

## Context
Sprint 1 planned spatie/laravel-permission in teams mode. That package keeps users, roles and role assignments in one database and joins across them. In WURK, people (WURK ID) live in the central database, while each business's team, roles and branches live on its shard (ADR-0002, ADR-0005). Cross-database joins are not possible, so the package would force either duplicating identities into every shard or weakening shard isolation.

## Decision
A small Access module:
- **Permissions** are declared in code by each module (`permissions()`), with a scope: `platform` or `business`.
- **System roles** (super-admin, finance, support, managed-services; owner, branch-manager, staff) are defined in code, so every business always has current definitions with nothing to synchronise across shards.
- **Custom roles** are rows (central for platform, shard for business) holding a list of declared permission names; `permissions:sync` prunes permissions no longer declared.
- **Assignments** store a role key: platform assignments centrally, business assignments on the shard next to the member and branch assignments.
- Checks go through Laravel's Gate (`$user->can('bookings.manage')`), so policies, `can:` middleware and navigation filtering work as usual.

## Alternatives considered
- **spatie/laravel-permission (teams):** single-database design; see context.
- **Duplicate identities per shard:** breaks the single WURK ID and multiplies personal data.

## Consequences
Roughly the same amount of code as configuring and working around the package; behaviour is fully covered by an exhaustive permission-matrix test. No third-party dependency on the authorisation path.
