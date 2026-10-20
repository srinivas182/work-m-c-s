# ADR-0005: WURK ID central identity

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
Customers belong to many businesses; owners may run several businesses. Both need one login.

## Decision
A central identity (name, phone, email, password hash, MFA, consents) with a membership index (tenant ID + role). All business data stays in tenant databases. My WURK provides one-tap sign-in into each membership.

## Alternatives considered
- **Separate accounts per tenant:** simple, but poor experience for customers in multiple businesses.

## Consequences
Better UX; identity data is centralised and must be protected accordingly; tenants can never enumerate a person's other memberships.
