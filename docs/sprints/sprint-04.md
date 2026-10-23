# Sprint 4 — Website builder: detailed specification

| | |
|---|---|
| **Sprint** | 4 of 18 · 14 – 25 December 2026 |
| **Status** | Approved 22 Oct 2026 — 4.1 ✅ (#35), 4.2 in review |
| **Depends on** | v0.4.0 (packages and entitlements, design system and palette engine); PRD §5.4 FR-WB-01, FR-WB-02, FR-WB-03; ADR-0003 |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each must pass CI and be merged before the next starts |
| **Capacity note** | 16 December (Day of Reconciliation) and 25 December (Christmas Day) are public holidays in South Africa: 8 working days instead of 10 (see §6, decision 7) |

---

## 1. Objectives

Give every business a website it can build and change itself, visually, without breaking anything:

1. **Pages** — unlimited pages within the package's storage quota; drafts, publishing, version history and restore.
2. **Premium blocks** — the first part of the block library (FR-WB-01): accessible, responsive, mobile-first sections that look designed, not generic.
3. **On-page editing** (FR-WB-02) — click-to-edit text and images, drag-and-drop blocks, undo/redo, menus.
4. **Branding** (FR-WB-03) — logo, favicon, one or two brand colours turned into an accessible palette (Sprint 0 engine), curated font pairings.

## 2. Out of scope (and where it lands)

| Item | Sprint |
|---|---|
| Serving sites to the public on `name.wurk.co.za` and custom domains, edge caching, SEO/AEO, sitemaps, "Powered by WURK", neutral suspended page (FR-WB-05, FR-WB-07) | 5 — this sprint publishes versions the Sprint 5 engine serves; until then sites are previewed inside the back office |
| Templates per category, template switching, AI site generation, wizard steps (FR-WB-04, FR-ON) | 6 |
| Full media library with automatic resizing and modern formats (FR-WB-06) | 6 — this sprint has a simple, safe image upload |
| Booking, shop, CRM blocks | With their modules (7, 8, 10) |
| Real-time co-editing | Not planned — two editors are warned instead (decision 5) |

---

## 3. Sub-sprints

### 4.1 Site model and branding

| Item | Details |
|---|---|
| Website module | Tenant data on the business's shard, entitlement `website` |
| Pages | Title, slug (unique per site, reserved slugs blocked), order, home page flag, SEO title/description, draft content, published version, soft delete with 30-day restore |
| Versions | Every publish stores an immutable snapshot with author and optional note; restore creates a new draft from any version |
| Content format | Our own JSON document with a `schema` version per page and per block; Puck data is converted at the edge of the editor, never stored as-is (decision 1) |
| Branding | Logo, favicon, primary and optional secondary colour → accessible palette (Sprint 0 engine, WCAG 2.2 AA), curated font pairing, corner style |
| Audit | Publish, restore, delete and branding changes recorded on the business's audit chain |

### 4.2 Block library, part 1

About 14 blocks, each a server-renderable React component used unchanged by the editor (4.3) and the public engine (Sprint 5):

Header/navigation · Hero (3 variants) · Services and prices · Feature grid · Text · Image and text · Gallery · Testimonials · Team · Opening hours · Contact details with map link · FAQ · Call-to-action band · Footer

- Themed only through brand tokens, so one site's palette and fonts never leak into another.
- Every block: field schema, sensible defaults, mobile-first layout, keyboard and screen-reader support, axe check in CI, visual snapshot at phone and desktop widths.
- Block props are versioned with upgrade functions, so improving a block later never breaks saved pages.

### 4.3 Visual editor

| Item | Details |
|---|---|
| Editor | Puck (`@puckeditor/core`, pinned) inside the back office, behind our own adapter |
| Editing | Click-to-edit text, image replace, drag-and-drop to add and reorder blocks, duplicate/delete, block settings panel |
| Undo / redo | Full history within the session (keyboard shortcuts included) |
| Saving | Autosave drafts (debounced); a version check warns when someone else saved in the meantime instead of overwriting their work |
| Preview | Phone, tablet and desktop widths; "preview as published" |
| Publish | Publish with an optional note; unpublish; version history with restore |
| Images | Upload JPEG/PNG/WebP up to 5 MB to the business's storage, re-encoded and stripped of metadata (EXIF, including GPS), resized to two widths in a modern format (brought forward from Sprint 6 for photo-led heroes, approved 23 Oct); counts towards the storage quota |

### 4.4 Menus, branding screen and site preview

| Item | Details |
|---|---|
| Menus | Header, footer and mobile menus: pages, external links, drag to reorder, one level of sub-items |
| Branding screen | Logo and favicon upload, colour pickers with the live accessible palette, font pairing picker with live preview |
| Site preview | The draft or published site rendered with the real blocks at `app.wurk.co.za/{business}/website/preview` until Sprint 5 serves public sites |
| Quotas | Clear messages when the storage quota is reached, with the upgrade path |

---

## 4. Data model (summary)

| Database | Tables |
|---|---|
| Shard | `sites` (one per business: branding, menus, settings), `pages`, `page_versions` (immutable), `site_assets` (uploaded images: path, size, dimensions, checksum) |
| Central | — (storage usage is summed per business for quota checks) |

## 5. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 4.1 | Pages and versions are isolated per business (cross-tenant tests); restoring version 3 of 5 produces a draft identical to version 3; branding colours always produce an AA-compliant palette |
| 4.2 | Every block passes axe (no serious/critical issues) at phone and desktop widths in light and dark site palettes; a block upgrade function migrates old saved props |
| 4.3 | An owner adds, edits, reorders and deletes blocks, undoes and redoes, publishes and restores — in a browser test on desktop and phone; two editors saving the same page get a conflict warning, never a silent overwrite; uploaded images lose their EXIF data |
| 4.4 | Menus and branding changes appear in the site preview; the preview renders the published version byte-for-byte the same as the draft preview of that content |

## 6. Decisions to confirm

1. **Puck as the editor**, package `@puckeditor/core` (the old `@measured/puck` name is deprecated), version pinned. It is MIT-licensed and supports React 19, but still pre-1.0 — so it sits behind our own adapter and pages are stored in our own versioned format, making an upgrade or replacement safe.
2. **Unlimited pages, bounded by the package's storage quota** (PRD FR-WB-02), rather than a page count.
3. **Images this sprint:** simple upload with re-encoding and metadata stripping; resizing, modern formats and the media library come in Sprint 6.
4. **Fonts self-hosted**, from about 8 curated pairings — no Google Fonts requests from public sites (faster, and no visitor data sent to a third party under POPIA).
5. **Concurrent editing:** conflict warning, not live co-editing.
6. **Preview inside the back office** until Sprint 5 serves public sites.
7. **Holiday capacity:** with two public holidays, menus (4.4) may finish in the first days of Sprint 5. Everything else stays in Sprint 4; the Sprint 6 milestone ("live site in 30 minutes") is unaffected.

## 7. Client dependencies

| Item | Needed by |
|---|---|
| Sign-off on the block design direction (a short design board early in 4.2) | 4.2 |
| The ~6 business categories for templates (Sprint 6), so blocks cover them | 4.2 |
| **Cloud provider decision (AWS or Azure)** for staging | Sprint 5 |
| Cloudflare account and wurk.co.za DNS access | Sprint 5 |
| Carried over: DebiCheck provider choice and sandbox; real invoice issuer details; Paystack test keys | As soon as possible |

---

## 8. Progress log

| Sub-sprint | PR | Result |
|---|---|---|
| 4.1 Site model & branding | #35 | 562 tests on SQLite and MySQL 8; 32 browser checks (a new owner adds a page). Puck package checked before starting: `@measured/puck` is deprecated, `@puckeditor/core` 0.23.0 will be pinned in 4.3 |
| 4.2 Block library, part 1 | #36 | Design direction approved 23 Oct; client asked for photo-led heroes — Hero has five layouts incl. a guarded slideshow, and basic image resizing moves into 4.3. 14 blocks; 45 frontend tests, 562 backend, 36 browser checks. Deviation: pixel-snapshot tests replaced by server-render tests plus accessibility scans at both widths — pixel baselines differ between machines (font rendering) and would make CI flaky; CI-generated baselines can follow with the Sprint 5 engine |
| 4.3 Visual editor & photo uploads | see PR | 568 backend tests on SQLite and MySQL 8; 127 frontend; 38 browser checks incl. the full editing journey on desktop and phone. Deviations: uploads up to 10 MB (phone photos); photo resizing brought forward from Sprint 6 at the client's request; on phones Publish/Versions live in a bottom bar (Puck's canvas covers its folded header menu) |

### Resolved — first edit could not be undone (4.3)

Puck 0.23 does not record the page as loaded in its undo history, so the first edit after opening the editor could never be undone (later edits could). It first looked like a focus problem with inline text; measuring showed focus was irrelevant — screen and saved page always agreed, and Puck refreshes inline text whenever its value changes. Fixed by seeding Puck's `initialHistory` with the loaded page; the editor browser test now undoes a first edit on desktop and phone.
