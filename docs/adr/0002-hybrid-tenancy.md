# ADR-0002: Hybrid tenancy — sharded shared DBs + dedicated DBs

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
Target is 100k tenants in five years, architecture proven to 500k. Managed tenants need private customisation without affecting others.

## Decision
Self-managed tenants live in sharded shared databases isolated by `tenant_id`. Managed tenants get a dedicated database. A central DB holds the shard map, billing and identity. An automated tenant-move tool moves tenants between models.

## Alternatives considered
- **Database per tenant for all:** strongest physical isolation, but 100k–500k databases make migrations, backups and connection management very costly.
- **Single shared database:** cheapest, but no route for managed customisation and a single scaling bottleneck.

## Consequences
Lower hosting cost and manageable operations; isolation must be enforced in code and proven by automated tests; tenants cannot choose schema versions (see ADR-0006).
