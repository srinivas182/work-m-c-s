# ADR-0004: Cloudflare for SaaS for custom domains, SSL and edge caching

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
Up to 500k custom domains and subdomains need automatic SSL, DDoS protection and fast delivery in South Africa.

## Decision
Use Cloudflare for SaaS custom hostnames. Owners point DNS (CNAME, or A record for apex). Certificates issue and renew automatically. Public pages are cached at the edge and purged on publish.

## Alternatives considered
- **Self-managed Let's Encrypt (Caddy/certbot):** operationally heavy at this scale.
- **AWS CloudFront + ACM:** per-distribution certificate limits make it awkward for very large numbers of custom domains.

## Consequences
Reliable SSL at scale and fast local delivery; dependency on Cloudflare pricing per hostname, factored into package pricing.
