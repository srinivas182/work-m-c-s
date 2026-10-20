# Tenancy and data architecture

## 1. Layers

| Layer | Holds | Who |
|---|---|---|
| **Central (landlord) DB** | Tenants, domains, packages, subscriptions, invoices, agents, agent commissions, credit ledger, shard map, WURK ID (identity only) | Platform |
| **Shared tenant DBs (sharded)** | All business data, every row tagged with `tenant_id` (and `branch_id` where relevant) | Self-managed tenants — a few thousand per database, many databases across clusters |
| **Dedicated DB** | Full private database incl. extension tables | Managed tenants |

At 500,000 tenants this is roughly 100–250 shared databases plus dedicated databases for managed tenants.

## 2. Request flow

1. Request arrives at Cloudflare. Public site pages are served from edge cache where possible.
2. On cache miss / dynamic request: host name → central lookup (cached in Redis) → tenant record → shard or dedicated DB connection.
3. Tenant context is bound for the request (DB connection, cache prefix, storage prefix, queue tags, feature flags).
4. **Under Octane, tenant context is reset at the end of every request.** A dedicated test suite fires interleaved requests for different tenants on the same worker and asserts zero leakage.

## 3. Isolation enforcement

- Shared DBs: global query scope on `tenant_id` applied to every tenant model, plus a guard that throws if a tenant model is queried without tenant context.
- Branch scoping: additional scope for branch-restricted users, enforced in the data layer, not the UI.
- Cache keys, S3 paths, search indexes and queue jobs are all tenant-prefixed.
- Automated isolation tests run in CI for every module.

## 4. WURK ID (central identity)

- Stores name, phone, email, password hash, MFA, consent records.
- Business data (orders, wallets, commissions, bookings) stays in the tenant DB, linked by WURK ID.
- My WURK lists a person's tenant memberships from a central membership index (tenant ID + role only); one-tap sign-in issues a tenant session.
- A tenant can never query which other tenants a person belongs to.

## 5. Tenant moves

Upgrade from self-managed to managed (or downgrade) triggers an automated move:

1. Provision target DB
2. Copy tenant rows (consistent snapshot) and replay changes
3. Verify row counts and checksums per table
4. Brief write-freeze, final sync, switch shard map, unfreeze
5. Keep source rows for a retention window, then purge

## 6. Schema changes and rollouts

- All migrations follow **expand / contract**: add first, migrate data, switch code, remove old later. Never a breaking change in one step.
- Migration runner rolls out shard by shard (canary shard first), records version per database, and **pauses automatically on error**.
- Tenants never choose schema versions. They choose **features** (feature flags), opt-in for 90 days before becoming default. Security fixes are always applied.

## 7. Backups and recovery

- Aurora/RDS automated backups with point-in-time recovery
- Per-tenant logical export for self-service backup/restore and POPIA data portability
- Cross-region backup copy
- Quarterly restore drills
- RPO ≤ 15 minutes, RTO ≤ 4 hours

## 8. Analytics data

Page views, clicks and marketing events go to ClickHouse (tenant-partitioned), not MySQL. Reports query ClickHouse for traffic and MySQL for transactional data.
