# ADR-0007: Business money never flows through WURK

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
Collecting payments on behalf of businesses would make WURK a payment facilitator with major compliance and reconciliation burdens.

## Decision
Each business (and optionally each branch) connects its own merchant accounts and courier accounts. WURK collects only its own subscription, credits and managed-service revenue. Customer referral payouts are paid by the business; WURK keeps the ledger.

## Consequences
Lower regulatory exposure; gateway integrations use per-tenant credentials stored encrypted.
