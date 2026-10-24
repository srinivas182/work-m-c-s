# Sprint 7 — Templates II & one-hour launch: detailed specification

| | |
|---|---|
| **Sprint** | 7 · four weeks (sprint plan revision 3) |
| **Status** | Approved 24 Oct 2026 — 7.1 in review |
| **Depends on** | v0.6.0 (template system, category sections, WURK AI, first 10 templates) |
| **Delivery** | 6 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objective

**A non-technical owner launches a premium website themselves in one hour or less — proven with real owners.** Sprint 7 completes the template catalogue (all 12 categories, 24 templates) and adds everything an owner needs to get from signup to a site they're proud of without help.

## 2. Sub-sprints

### 7.1 Templates II, part 1 (6)

| Category | Template 1 | Template 2 | Category sections |
|---|---|---|---|
| Professional services | Advisory practice | Agency / consultancy | Accreditations, portfolio, quote form |
| Car mechanics | Workshop & service centre | Panel beater & auto electrician | Quote form, service areas, accreditations (brands) |
| Hospitality | Hotel / guesthouse | Restaurant | Rooms & rates (enquiry or link to booking engine), food menu |

### 7.2 Templates II, part 2 (8)

| Category | Template 1 | Template 2 | Category sections |
|---|---|---|---|
| Trades & home services | Solar & electrical | Home services | Quote form, service areas, before & after, accreditations |
| Education & training | Tutor & online classes | Early-learning centre | Class timetable |
| Events & creative | Photographer & events portfolio | Venue & event planner | Portfolio, packages, quote form |
| Transport & logistics | Shuttles & tours | Couriers & removals | Quote form, service areas |

Same standard as Sprint 6 (approved): two distinct designs per category; honest samples (no invented reviews, team, prices, offers, policies or schedules); POPIA legal pages; template guard and an accessibility scan of every page on desktop and phone.

### 7.3 WURK AI images & media library

| Item | Details |
|---|---|
| AI images | Image providers (OpenAI, Google) in WURK AI settings, with model, price per image and fallback; a simulated provider until keys arrive |
| Decorative only | Allowed for backgrounds, banners, illustrations and atmosphere; **blocked** in Before & after, Team, Portfolio, Testimonials and "our work" galleries; never images of real, identifiable people |
| Owner flow | The same confirmation (credits, balance before and after), charged once, refunded on failure; rate card: "AI: generate an image" |
| Media library | Every photo: thumbnails, descriptions, where it's used, delete when unused, storage used vs the package quota |

### 7.4 My plan & usage

| Item | Details |
|---|---|
| Plan & usage page | Included vs used this month (credits, storage, staff, pages) with progress bars; renewal date and amount |
| Credits in rands | "142 credits (about R64)" |
| Expiry made clear | "40 monthly credits expire on 1 December; bought credits never expire" |
| Where credits went | Chart (AI, SMS, WhatsApp, email) and the statement with PDF/CSV download |
| Price list | What every action costs in credits |
| Spending limits | Monthly limit per business and per staff member |

### 7.5 Launch checklist & quality

| Item | Details |
|---|---|
| Launch checklist | Guided steps with progress: logo and colours, services and prices, opening hours, contact details, photos, review legal pages, publish. Each step links straight to where it's done |
| In-editor tips | Short, dismissible hints at the moments owners get stuck |
| Performance budget | Mobile performance ≥ 90 for every template, measured automatically in CI (Lighthouse) — not yet measured |
| Carried over from Sprint 6 | One money format across the app (the package pages use a second formatter); links named only "Edit" on the packages page given distinct names |

### 7.6 Timed launch test

At least five non-technical owners each launch a site from signup without help, timed and observed (remote screen-share is fine). **Pass:** median under 60 minutes and everyone succeeds without help. Every place someone hesitates is fixed in this sub-sprint and re-tested.

## 3. Out of scope

| Item | Sprint |
|---|---|
| Public sites (website engine) | 5, after this sprint |
| Appointments, online consultations, shop | 8, 9, 10 |
| Custom domains, cloud | 17 |

## 4. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 7.1–7.2 | All 24 templates pass the template guard and the accessibility scan on every page, desktop and phone |
| 7.3 | AI images can't be placed in blocked sections (tested); confirmation, charge-once and refund tested as in 6.3 |
| 7.4 | Figures on the page match the credit ledger exactly; limits stop spending at the limit (tested) |
| 7.5 | Every template scores ≥ 90 mobile performance in CI; the checklist reflects real site state |
| 7.6 | Median launch time under 60 minutes, 100% success without help |

## 5. Client dependencies

| Item | Needed by | Why |
|---|---|---|
| **Template photography** | 7.1 | The biggest factor in "premium"; layouts are final, photos replace stand-ins file by file |
| **5+ non-technical owners** for the timed test | 7.6 | The one-hour goal is proven only with real people |
| AI API keys (text and image) | 7.3 | Simulated providers until then |
| Margin, buffer and exchange rate | 7.4 | Credits shown in rands |

---

## 6. Progress log

| Sub-sprint | PR | Result |
|---|---|---|
| 7.1 Templates II, part 1 | see PR | 658 backend tests on SQLite and MySQL 8; 301 frontend; 80 browser checks incl. every page of all 16 templates. Found: empty sections showed orphan headings on the live site (also in Sprint 6 templates) — now hidden; three invented policies in copy |
