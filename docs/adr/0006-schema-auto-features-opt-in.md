# ADR-0006: Schema changes automatic; features opt-in

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
WURK wants tenants to control when they "update", but tenants sharing a database must share a schema version.

## Decision
Migrations are backward-compatible (expand/contract) and roll out shard by shard to everyone automatically. Tenants control **features** via feature flags, opt-in for 90 days before becoming default. Security fixes are mandatory.

## Alternatives considered
- **Per-tenant version choice:** impossible on shared databases; version sprawl would be unsupportable.

## Consequences
Owners keep control of visible change; platform stays on one supported version; migrations need expand/contract discipline enforced in review.
