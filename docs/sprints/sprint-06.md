# Sprint 6 — Templates I & AI onboarding: detailed specification

| | |
|---|---|
| **Sprint** | 6 · 18 – 29 January 2027 (runs before Sprint 5 — see §8) |
| **Status** | Specification — awaiting approval |
| **Depends on** | v0.5.0 (blocks, editor, photo uploads, menus, branding, preview); PRD §5.3 onboarding, §5.4 FR-WB-04 templates, FR-WB-06 media; sprint plan revision 3 |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objectives

**A non-technical owner launches a premium website from a template in one hour or less** (measured in Sprint 7). Sprint 6 builds the machinery and the first half of the templates:

1. **Template system** — categories and templates managed by WURK staff; choosing one at signup builds a complete site; switching template within the category keeps the owner's content.
2. **10 category sections** — the specialised blocks the categories need.
3. **AI site generation** — the owner describes the business in a few words; the AI writes the copy and derives brand colours (PRD §5.3 step 7). First generation free.
4. **Media library** — every photo in one place, with descriptions and the storage quota.
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

### 6.3 AI site generation & media library

| Item | Details |
|---|---|
| Questions | Business name, what you do (a sentence), area, main services and prices (optional), tone; logo upload |
| Output | Headlines and page copy in the template's sections, SEO titles and descriptions, FAQ answers, brand colours derived from the logo; the owner reviews everything in the editor |
| Never invented | **Testimonials, prices, qualifications, registrations and opening hours are never made up** — they come from the owner or stay as clearly marked samples |
| Cost | First generation free; later generations use WURK Credits (AI rate from the rate card) |
| Privacy | Only business details are sent to the AI provider — no customer personal information |
| Media library | All photos with thumbnails, descriptions, where each is used, delete when unused, storage used vs package quota |

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
| 6.3 | AI output never contains testimonials, prices or registrations that the owner didn't give; a failed or slow AI call never blocks signup (the template's sample content is used instead) |
| 6.4 | All 10 templates meet §4, verified by automated performance and accessibility checks plus a visual review |

## 6. Decisions to confirm

1. **Order:** Sprints 6 and 7 (templates) before Sprint 5 (website engine); domains and cloud in Sprint 17.
2. **24 templates:** 10 in Sprint 6 and 14 in Sprint 7; Sprint 7 grows to three weeks.
3. **Sample testimonials never published** (Consumer Protection Act and trust); AI never invents testimonials, prices or registrations.
4. **AI provider:** Anthropic's Claude API (strong writing quality, data not used for training under its commercial terms). Business details only.
5. **Photography** — needed from this sprint (see §7).

## 7. Client dependencies

| Item | Needed by | Options |
|---|---|---|
| **Template photography** | 6.4 (now) | (a) **Commercial stock licence suitable for templates** used by many businesses (an extended/multi-user licence — standard licences usually don't cover redistribution in templates); (b) **a commissioned South African photo shoot** — authentic, owned outright, best fit for "premium"; (c) AI-generated imagery — cheapest, but risks looking generic. Recommended: (b) for hero images of each category + (a) for the rest |
| AI provider account and budget | 6.3 | Anthropic API key under WURK's account |
| Category list and template names | ✅ agreed | — |

## 8. Plan changes recorded with this specification

- 24 templates (2 per category): Car mechanics adds *Panel beater & auto electrician*; Events & creative adds *Venue & event planner*; Transport & logistics splits into *Shuttles & tours* and *Couriers & removals*.
- Sprint order: 6 → 7 → 5 → 8 …; custom domains, SSL and Cloudflare join the cloud work in Sprint 17.
