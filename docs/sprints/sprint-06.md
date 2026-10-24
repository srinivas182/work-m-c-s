# Sprint 6 — Templates I & AI onboarding: detailed specification

| | |
|---|---|
| **Sprint** | 6 · 18 – 29 January 2027 (runs before Sprint 5 — see §8) |
| **Status** | Approved 23 Oct 2026 — 6.1 ✅ (#43), 6.2 in review |
| **Depends on** | v0.5.0 (blocks, editor, photo uploads, menus, branding, preview); PRD §5.3 onboarding, §5.4 FR-WB-04 templates, FR-WB-06 media; sprint plan revision 3 |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objectives

**A non-technical owner launches a premium website from a template in one hour or less** (measured in Sprint 7). Sprint 6 builds the machinery and the first half of the templates:

1. **Template system** — categories and templates managed by WURK staff; choosing one at signup builds a complete site; switching template within the category keeps the owner's content.
2. **10 category sections** — the specialised blocks the categories need.
3. **WURK AI** — the owner describes the business in a few words and the AI writes the site (PRD §5.3 step 7; first generation free), with full cost transparency for owners and WURK staff.
5. **10 templates** for Salon & beauty, Health, Spa & wellness, Retail and Fitness (Sprint 7 delivers the other 14).

## 2. Out of scope (and where it lands)

| Item | Sprint |
|---|---|
| Templates for the other 7 categories (14); licensed photo library completed; launch checklist; timed launch test | 7 |
| Public sites (website engine) | 5, after Sprint 7 |
| Custom domains, SSL, Cloudflare, cloud | 17 |
| Booking, online consultations, shop | 8, 9, 10 — templates show these sections from the start and they switch on when the module ships |

---

## 3. Sub-sprints

### 6.1 Template system & category management

| Item | Details |
|---|---|
| Data | `categories` and `templates` (central database): pages, blocks, menus, default branding and sample content, in WURK's page format, versioned |
| Admin | WURK staff create, edit, preview, publish and retire categories and templates; a template is edited with the same visual editor owners use |
| Signup | New steps: **category** (12, with icons) → **template** (live previews of its 2 templates) |
| Apply | Choosing a template builds the site: pages, menus, branding, sample content and photos |
| Switching | Owners switch template within their category; their text, photos, prices and menus carry across (matched by section type); nothing is lost — the previous site is kept as a version |
| Category | Fixed after signup (PRD §5.4) |

### 6.2 Category sections (10)

| Section | Used by | Notes |
|---|---|---|
| Before & after | Salon, Health (dental), Spa, Trades | Side-by-side or slider; both photos need descriptions |
| Class timetable | Fitness, Education | Week view on desktop, day view on phones; static until Appointments (Sprint 8) makes it bookable |
| Packages & specials | Salon, Spa, Events | Price, inclusions, validity dates; expired specials hide themselves |
| Accreditations | Health, Professional services, Mechanics, Trades | Logos or text badges (e.g. medical aids, SAICA, brand-approved) |
| Promotion banner | All, especially Retail | Dismissible; start and end dates |
| Food menu | Restaurant | Sections, dishes, prices, dietary tags (vegetarian, halaal, contains nuts…) |
| Rooms & rates | Hotel / guesthouse | Room photos, sleeps, from-price per night; "enquire" or an embedded booking engine |
| Quote form | Professional services, Mechanics, Trades, Transport, Events | Stored in a simple inbox and emailed to the owner until the CRM (Sprint 12); POPIA consent line; spam protection |
| Service areas | Mechanics, Trades, Transport | Suburbs/areas served; map link (no embedded third-party map) |
| Portfolio | Professional services, Events & creative | Projects with photos, short story and outcome |

Every section: accessible (WCAG 2.2 AA), mobile-first, versioned, inline editing proven safe, and looks finished with whatever the owner fills in.

### 6.3 WURK AI, credits and transparency

Owners see one **"WURK AI"** — never providers, models or tokens. WURK staff choose and change the AI behind it.

**Super admin — providers and models**

| Item | Details |
|---|---|
| AI providers | Several providers (initially Anthropic, Google, OpenAI) with encrypted, write-only keys and a "send test" button, like the existing SMS providers |
| Model per task | e.g. website writing → Claude Sonnet 5.5; quick edits → Claude Haiku 5.5; fallback → Gemini Flash. Chosen from the provider's model list where available; **specific versions pinned, never a "latest" alias** |
| Fallback order | If a provider fails, the next one is used automatically |
| Model prices | Per million tokens, pre-filled and editable; **USD → ZAR exchange rate** (entered or updated daily) with an optional safety buffer |
| Try before switching | Generate a sample site with a new model and compare side by side before making it the default |
| Retirement alerts | Warning when a model in use is scheduled to be withdrawn |
| Message costs | Cost per SMS / WhatsApp message on each messaging provider (needed for the cost tip) |

**Super admin — what to charge and what you pay**

| Item | Details |
|---|---|
| Package cost tip | While entering credits for a package (and for credit packs and the rate card): estimated cost **if fully used** as a range — cheapest mix, most expensive (all SMS), typical (from real usage once live) — and the share of the package price it represents, with a suggested credit price at the target margin |
| Per-action prices | The rate card prices AI actions per action (e.g. generate website 20 credits, rewrite a section 1), so owners always know the cost |
| AI usage dashboard | Every request: business, task, provider, model, tokens, **actual cost**, credits charged, fallback used. Totals per provider and month, per business, and margin |
| Controls | Monthly spending cap per provider, daily cap per business, alerts on provider failure or unusual spend |
| Provider balances | Prepaid balance and **runway** ("lasts about 12 days at current usage") |

**Package settings**

| Setting | Effect |
|---|---|
| AI included | Off → "WURK AI isn't in your package" with an **Upgrade** button (existing "not included" page) |
| Monthly credits | 0 or more |
| Can buy credit packs | Off → upgrading is the only way to get credits (e.g. trial or free packages) |

**Owners — every paid action asks first**

| Rule | Details |
|---|---|
| Confirmation | "This uses **8 credits**. Balance **142** → after **134**. [Generate] [Cancel]" |
| Exact price | The price shown is exactly what is charged; variation in AI cost is WURK's, visible on the admin dashboard |
| Reserve, charge, refund | Credits are reserved on confirm, charged on success, **refunded automatically on failure**; locked ledger writes prevent two actions spending the same credits |
| Not enough credits | The action never runs: "You need 8 credits and have 5" with **Buy credits** and/or **Upgrade** (whichever the package allows). If a business keeps buying packs, show the saving an upgrade would give |
| First website generation | Free on every package |
| Small actions | Optional "don't ask again for actions under 3 credits" (off by default) |
| Who can spend | "Use WURK AI" permission per staff role |
| After the action | "Done — 8 credits used, 134 left"; listed on the credits statement |
| Out of credits | Low-balance alert early; optional **free email fallback** for essential customer messages (e.g. booking reminders); platform sign-in codes are always free |

**AI site generation**

| Item | Details |
|---|---|
| Questions | Business name, what you do (a sentence), area, main services and prices (optional), tone; logo upload |
| Output | Headlines and page copy, SEO titles and descriptions, FAQ answers, brand colours from the logo; the owner reviews everything in the editor |
| Never invented | **Testimonials, prices, qualifications, registrations and opening hours are never made up** |
| Privacy | Only business details are sent to the AI provider — no customer personal information |

### Moved to Sprint 7 (with this specification)

- **AI image generation** (OpenAI or Google — Claude does not generate images), with the **decorative-only rule**: allowed for backgrounds, banners, illustrations and atmosphere; **blocked** in Before & after, Team, Portfolio, Testimonials and "our work" galleries; never images of real, identifiable people.
- **Media library** (all photos, descriptions, where used, delete when unused, storage vs quota).
- **"My plan & usage" page**: included vs used (credits, storage, staff, pages), renewal date and amount, **credits shown in rands**, allowance expiry made clear ("40 monthly credits expire on 1 December; bought credits never expire"), where credits went (chart + statement with PDF/CSV download), published price list, spending limits (per business and per staff member).

### Planned for Sprint 15 (Reports & campaigns)

Profit per package and per business (AI, messaging, payment fees, later hosting); pricing simulator; monthly provider reconciliation; margin, price-change and exchange-rate alerts; advance notice to businesses before price increases; monthly usage summary by email/WhatsApp; opt-in auto top-up with a cap; credit estimates for bulk campaigns.

### 6.4 Templates I (10)

| Category | Template 1 | Template 2 |
|---|---|---|
| Salon & beauty | Hair & braids salon | Nails, lashes & barbers |
| Health | Dental practice | Doctor's practice |
| Spa & wellness | Day spa | Wellness practitioner |
| Retail | Boutique | General store |
| Fitness | Gym & class studio | Personal trainer |

Each template: home, about, services, contact and legal pages (privacy, terms, cookies — generated for the category, editable), realistic South African sample content, photography, and its own visual direction (layouts, fonts, colours) so the two templates in a category feel genuinely different.

---

## 4. Definition of done for every template

- Premium on first load; looks right with the owner's own branding.
- Mobile performance score ≥ 90; no serious or critical accessibility issues on phone and desktop.
- Every section's sample content is either real-looking and safe to keep, or clearly flagged for the owner to replace; **sample testimonials are never published** — they stay hidden on the live site until the owner edits or removes them.
- Switching to the other template in the category keeps the owner's content.

## 5. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 6.1 | Signup with category + template produces a complete site; switching template keeps every edited text, photo, price and menu item (automated test across both templates of every category built so far) |
| 6.2 | All 10 sections pass the inline-editing safety, accessibility and option-value tests at phone and desktop widths |
| 6.3 | AI output never contains testimonials, prices or registrations the owner didn't give; a failed or slow AI call never blocks signup (sample content is used); an action is charged exactly the quoted credits, refunded on failure, and never runs without enough credits (tested, including two simultaneous actions); the fallback provider takes over when the first fails; the cost tip matches a worked example to the cent |
| 6.4 | All 10 templates meet §4, verified by automated performance and accessibility checks plus a visual review |

## 6. Decisions to confirm

1. **Order:** Sprints 6 and 7 (templates) before Sprint 5 (website engine); domains and cloud in Sprint 17.
2. **24 templates:** 10 in Sprint 6 and 14 in Sprint 7; Sprint 7 grows to three weeks.
3. **Sample testimonials never published** (Consumer Protection Act and trust); AI never invents testimonials, prices or registrations.
4. **WURK AI:** one AI for owners; providers (Anthropic default, Google and OpenAI as fallbacks) and models chosen by WURK staff in settings, pinned to specific versions; per-action credit prices; confirmation before every paid action.
6. **Scope:** the additions in §3 (6.3) total about 13 days. Sprint 6 stays two weeks; image generation, the media library and the owner usage page move to **Sprint 7, which becomes four weeks** (14 templates, launch tooling and these items). Finance reporting goes to Sprint 15.
5. **Photography** — needed from this sprint (see §7).

## 7. Client dependencies

| Item | Needed by | Options |
|---|---|---|
| **Template photography** | 6.4 (now) | (a) **Commercial stock licence suitable for templates** used by many businesses (an extended/multi-user licence — standard licences usually don't cover redistribution in templates); (b) **a commissioned South African photo shoot** — authentic, owned outright, best fit for "premium"; (c) AI-generated imagery — cheapest, but risks looking generic. Recommended: (b) for hero images of each category + (a) for the rest |
| AI provider accounts | 6.3 | Anthropic (default), Google and OpenAI API keys under WURK's account, with prepaid balances or billing set up |
| Target margin and exchange-rate buffer | 6.3 | e.g. 40% margin, 5% buffer — used by the cost tip |
| Category list and template names | ✅ agreed | — |

## 8. Plan changes recorded with this specification

- 24 templates (2 per category): Car mechanics adds *Panel beater & auto electrician*; Events & creative adds *Venue & event planner*; Transport & logistics splits into *Shuttles & tours* and *Couriers & removals*.
- Sprint order: 6 → 7 → 5 → 8 …; custom domains, SSL and Cloudflare join the cloud work in Sprint 17.

---

## 9. Progress log

| Sub-sprint | PR | Result |
|---|---|---|
| 6.1 Template system | #43 | 595 backend tests on SQLite and MySQL 8; 127 frontend; 44 browser checks (signup with category and design, switching design, previews, admin screens scanned). Unfinished admin work from another session was found on the branch, reviewed, fixed (photo listing, partial renames, empty quota line, labels) and tested |
| 6.2 Category sections | see PR | 600 backend tests on SQLite and MySQL 8; 214 frontend; 46 browser checks incl. accessibility scans of all 24 sections on desktop and phone. Deviation: Rooms & rates links out to a booking engine instead of embedding it (no third-party requests, POPIA). Found: pages without a Hero had no main heading |
