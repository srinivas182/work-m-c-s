# ADR-0008: Single WURK Credits balance

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
SMS, WhatsApp, email and AI all have usage costs. Owners are often non-technical.

## Decision
One prepaid credit balance per tenant with a per-country rate card per channel, set by super admin. Packages include a monthly allowance; top-ups available anytime.

## Alternatives considered
- **Separate balances per channel:** more precise, more confusing.

## Consequences
Simple owner experience; requires a robust credit ledger with idempotent debits and per-channel usage reporting.
