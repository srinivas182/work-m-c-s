# Design standards

WURK's design must feel like a big-brand product on every screen: the wurk.co.za site, every template in every category, every dashboard and every portal.

## Benchmarks

| Area | Reference standard |
|---|---|
| Back offices (super admin, tenant, agent) | Stripe, Linear, Shopify admin |
| Business websites / templates | Squarespace, Framer, Webflow showcase |
| Booking flows | Fresha, Booksy |
| Customer portal & mobile | Modern fintech / banking apps |

## Principles

1. **Calm and confident** — generous whitespace, clear hierarchy, one primary action per view.
2. **Refined typography** — curated font pairings, consistent type scale, comfortable line lengths.
3. **No boxy, dated layouts** — soft radii, layered surfaces, subtle shadows, considered imagery.
4. **Motion with purpose** — smooth transitions and micro-interactions; respects reduced-motion settings.
5. **Never a blank or broken state** — skeleton loaders, illustrated empty states with a clear next action, plain-language errors.
6. **Mobile-first** — designed for a thumb on a mid-range Android phone first, then scaled up.

## Design system

- Built in Figma and mirrored 1:1 in code (Tailwind tokens + shadcn/ui components).
- Tokens: colour, typography, spacing, radius, elevation, motion.
- Back offices support light and dark mode.

## Tenant branding engine

- Owner chooses one or two brand colours, a logo and a font pairing.
- System generates the full tonal palette and checks contrast automatically (WCAG 2.2 AA). Owners cannot create unreadable combinations.
- Every block is designed to look excellent across the full range of brand colours.

## Templates

- Built from a shared library of premium blocks; templates are curated combinations.
- Beta: ~6 categories × 2–3 templates, all showcase quality.
- Each template ships with realistic, category-specific copy and imagery.

## Quality gates

| Gate | When | Requirement |
|---|---|---|
| Design approval | Before build | High-fidelity design approved for every screen |
| Design review | End of every sprint | Built UI matches approved design |
| Visual regression | Every PR (CI) | Playwright screenshot diffs reviewed |
| Performance | Every PR touching public sites | Lighthouse mobile ≥ 90; LCP < 1.5 s on 4G profile |
| Accessibility | Every PR | Automated axe checks; WCAG 2.2 AA |
| Device check | Every sprint | Tested on a low-end Android device |

## Team

- Senior visual designer dedicated to templates and the marketing site
- UI/UX designer dedicated to back offices and portals
- Designers work one sprint ahead of development
