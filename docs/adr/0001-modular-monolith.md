# ADR-0001: Modular monolith

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
WURK has many business modules (shop, bookings, CRM, referrals, etc.) and must keep adding modules for years. The team is ~10 people.

## Decision
Build a single deployable Laravel application split into self-contained modules that follow a common module contract and communicate via events and published contracts.

## Alternatives considered
- **Microservices:** independent scaling, but far higher operational cost, distributed transactions and slower delivery at this stage.
- **Unstructured monolith:** fast initially, unmaintainable at this scope.

## Consequences
Simple deployment and debugging; module boundaries enforced by architecture tests; any module can be extracted into a service later if its scaling profile demands it.
