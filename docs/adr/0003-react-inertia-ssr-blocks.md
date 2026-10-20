# ADR-0003: React + Inertia back offices; server-rendered React blocks for public sites

- **Status:** Accepted
- **Date:** 2026-10-20

## Context
Back offices need an app-like experience. Public business sites must be extremely fast and SEO/AEO friendly. The page builder needs the same visual blocks as the live sites.

## Decision
Back offices use React + TypeScript + Inertia. Public sites render the same React block components to static HTML via a Node render service, cached at Cloudflare, with interactive islands only (booking widget, cart, chatbot). Puck is the visual editor.

## Alternatives considered
- **Public sites as React SPAs:** heavy JavaScript, slower on mobile, weaker SEO.
- **Blade templates for public sites:** fast, but duplicates every block in two technologies.
- **Vue 3 (used on GMLM):** viable; React chosen for the Puck editor ecosystem, talent pool and a React Native path.

## Consequences
One block library; minimal JS on public pages; adds a small Node rendering service to operate.
