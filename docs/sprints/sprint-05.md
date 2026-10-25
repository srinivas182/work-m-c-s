# Sprint 5 — Website engine: detailed specification

| | |
|---|---|
| **Sprint** | 5 · runs after Sprint 7 (sprint plan revision 3) |
| **Status** | Approved 25 Oct 2026 — 5.1 ✅ (#57), 5.2 in progress |
| **Depends on** | v0.7.0 (blocks, templates, publishing, media); Sprint 7 performance findings (docs/sprints/sprint-07.md) |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objective

**Every business's published website is live, fast and found** — at `name.wurk.co.za` — as ready-made HTML that scores **≥ 90 on mobile (Lighthouse) with layout shift < 0.1**, for every one of the 24 templates.

Measured in Sprint 7: template previews score 65–71 on mobile because they're built in the browser (the HTML arrives empty; ~250 KB of back-office JavaScript runs before the first photo loads) and fonts arrive after first paint. Public pages must not work that way.

## 2. The key decision — render once, at publish

| Option | Verdict |
|---|---|
| Render on every visit (a Node server-side renderer per request) | Works, but every page view depends on the renderer being up, and costs more per view |
| **Render once at publish (recommended)** | Publishing queues a job; a small **Node renderer** turns the page (content + branding + menus) into finished HTML, which is **stored and served as a file**. Visitors get static, cacheable HTML. **If the renderer is down, the live site keeps serving the last version** — only publishing waits and retries |
| Rewrite the blocks as PHP templates | Two copies of every block would drift apart — rejected |

The renderer is a pure function: content in, HTML out. It has no database access and no secrets. **Simplified during 5.1:** production already ships Node and a server-rendering bundle, so the renderer is a command-line script the queued job runs (`bootstrap/site-render`) — no extra container, network call or service to monitor. The safety property is unchanged: visitors only ever get stored HTML.

Interactive parts become **islands** — a few kilobytes of JavaScript each, loaded only where used: slideshow, before & after slider, mobile menu, promotion banner close, quote form.

## 3. Sub-sprints

### 5.1 Rendering pipeline

| Item | Details |
|---|---|
| Renderer | Node container rendering the existing SiteRenderer to HTML (no second copy of any block) |
| Publish → render | Queued job per published page; retries with back-off; owners see "Publishing…" then "Live" |
| Stored HTML | One file per published page version; the live site points at the latest |
| Site CSS | Only the CSS the site's sections use, inlined in the page |
| Fonts | The template's display and body fonts **preloaded**, with **size-matched fallbacks** so a late font moves nothing |
| Islands | Slideshow, before & after, mobile menu, banner close, quote form — each tiny and self-contained |
| Re-render | Branding and menu changes re-render every published page (queued) |

### 5.2 Serving, caching and safety

| Item | Details |
|---|---|
| Addresses | `{handle}.wurk.co.za` (development and tests: `{handle}.wurk.test`); custom domains arrive with Sprint 17 |
| Pages | Home at `/`, pages at `/{slug}`; a friendly 404 in the site's own style |
| Renamed pages | Old addresses **redirect permanently** to the new ones (slug history), so shared links keep working |
| Not yet published | A simple "coming soon" page (not indexed by search engines) |
| Suspended businesses | "Temporarily unavailable" (503 with Retry-After), never the business's content |
| Caching | **As built:** the stored render is the page cache (no rendering per visit); browsers revalidate by ETag (changes with each render and each deploy, so nothing stale is ever shown); `Cache-Tag: site-{id}` lets Cloudflare purge a whole site on publish (Sprint 17) |
| Security headers | Content Security Policy allowing nothing from other sites (no third-party requests, POPIA), X-Content-Type-Options, Referrer-Policy, frame protection |

### 5.3 Search engines and AI answers (SEO & AEO)

| Item | Details |
|---|---|
| Per page | Title, description, canonical address, `lang`, Open Graph and social card (the page's hero photo) |
| Site | `sitemap.xml`, `robots.txt`, favicon |
| Structured data | `LocalBusiness` (name, address, phone, opening hours, services and prices) from the site's own sections |
| AI answers | `llms.txt` — a plain summary of the business for AI assistants |

### 5.4 Visitor features and the performance gate

| Item | Details |
|---|---|
| WhatsApp button | Floating chat button from the contact details (opens WhatsApp; no third-party script) |
| Quote form live | Posts to the business's inbox (built in 6.2); returns to the page with a thank-you |
| **Performance gate** | **Lighthouse in CI on public pages of all 24 templates: mobile ≥ 90, layout shift < 0.1** |
| Accessibility | Every public page of every template scanned on desktop and phone |

## 4. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 5.1 | A published page is served as complete HTML (content visible with JavaScript disabled); a renderer outage never takes a live site down (tested) |
| 5.2 | Suspended, unpublished, renamed and missing pages each behave as specified (tested); no request to another site from any public page (CSP and browser test) |
| 5.3 | Sitemap, robots, structured data and llms.txt valid for every template (schema validation in tests) |
| 5.4 | **Every template's public pages: mobile Lighthouse ≥ 90 and layout shift < 0.1, enforced in CI**; accessibility scans pass |

## 5. Decisions to confirm

1. **Render once at publish** with a small Node renderer container (recommended) — over rendering per visit or rewriting blocks in PHP.
2. **Islands** for interactive parts instead of loading the full app on public pages.
3. **"Coming soon"** for businesses that haven't published yet (rather than a 404).
4. **Permanent redirects** for renamed pages.

## 6. Dependencies

| Item | Needed by |
|---|---|
| None from WURK for building and testing (everything runs on `*.wurk.test`) | — |
| wurk.co.za DNS, Cloudflare, cloud | Sprint 17 (going live) |

## 7. Progress log

| Sub-sprint | PR | Notes |
|---|---|---|
| 5.1 Rendering pipeline | #57 | 687 backend tests on SQLite and MySQL 8 (also without the renderer bundle, as in CI); 363 frontend; 58 browser checks. Found: context lost after synchronous jobs; slideshow without a pause control on static pages; dated content frozen in static HTML (nightly re-render) |
