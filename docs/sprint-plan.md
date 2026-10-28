# Sprint plan to closed beta

**Assumptions:** 2-week sprints; team of ~10 — 1 architect/tech lead, 4 Laravel developers, 3 React developers, 2 designers (senior visual + UI/UX), 1 DevOps, 2 QA, plus a project manager. Designers work one sprint ahead.

**Revision 3 — 23 October 2026:** 24 templates (two per category); Sprints 6 and 7 run before Sprint 5; custom domains join the cloud work in Sprint 17; Sprint 7 grows to four weeks (WURK AI image generation, media library and owner usage page added).

**Revision 2 — 23 October 2026.** Agreed with the client: 12 categories and 21 premium templates; category sections (food menu, rooms & rates, quote form, service areas, portfolio, before & after, class timetable, packages & specials, accreditations, promotion banner); online consultations (Google Meet / Zoom) for every category; patient documents for health practices; hotel template starts with enquiries or an embedded booking engine; the cloud provider is chosen near the end. **Goal for every template: a non-technical owner launches a premium site themselves in one hour or less.** Three sprints are added (two for new scope, one because cloud infrastructure moves from Sprint 0 to just before hardening).

| Sprint | Dates | Focus | Deliverables | Status |
|---|---|---|---|---|
| 0 | | Foundations | PRD & architecture; design system; CI/CD | ✅ v0.1.0 |
| 1 | | Platform core | Tenancy & shards; WURK ID; roles & permissions; audit logging | ✅ v0.2.0 |
| 2 | | Super admin | Packages & entitlements; providers; WURK Credits; security | ✅ v0.3.0 |
| 3 | | Signup & billing | Marketing site; signup wizard; card, DebiCheck, cash; VAT invoices; dunning | ✅ v0.4.0 |
| 4 | | Website builder | 14 premium blocks; Puck editor; photo uploads; menus; branding; site preview | ✅ v0.5.0 |
| 5 | after 7 | Website engine | Public site rendering on name.wurk.co.za as **ready-made HTML** (preloaded fonts with size-matched fallbacks, minimal JavaScript; **mobile Lighthouse ≥ 90 and layout shift < 0.1 gated in CI** — moved from Sprint 7 after measuring); caching & purge; automatic SEO & AEO; "temporarily unavailable" page for suspended businesses; WhatsApp chat button (custom domains & SSL move to Sprint 17) | ✅ v0.8.0 |
| 6 | next | Templates I & AI onboarding | Category & template steps in signup; AI site generation from a few questions; 10 category sections; templates for Salon & beauty, Health, Spa & wellness, Retail, Fitness (10); legal pages generated per category; template switching with content kept; media library with quotas | ✅ v0.6.0 |
| 7 | 4 weeks | **Templates II & one-hour launch** *(new)* | Templates for Professional services, Car mechanics, Hospitality, Trades & home services, Education & training, Events & creative, Transport & logistics (14); licensed photo library per category; guided launch checklist with progress; in-editor tips; AI image generation (decorative only); media library; "My plan & usage" page; performance and accessibility budgets for every template; **timed launch test with 5+ non-technical owners** | ✅ v0.7.0 |
| 8 | 15 – 26 Feb | Appointments | Staff/branch availability; booking widget; deposits; cancellation/no-show rules; reminders; restaurant table reservations | ✅ v0.9.0 |
| 9 | 01 – 12 Mar | **Online consultations & patient documents** *(new)* | In-person / online / either per service; Google Meet & Zoom connections with a unique link per appointment; pay online before the slot; join window and reminders; online group classes; patient documents (secure, encrypted, audited) — **gated by legal review** | ✅ v0.10.0 – v0.12.0 |
| 10 | 15 – 26 Mar | Online shop | Catalogue, variants, stock, cart, checkout; PayFast, Yoco, Ozow, Paystack, Peach; orders & refunds | ✅ v0.11.0 |
| 11 | 29 Mar – 09 Apr | Multi-branch & shipping | Branch managers; per-branch merchant accounts, prices, stock; inter-branch settlement; courier integrations | Spec approved 30 Oct 2026 (docs/sprints/sprint-11.md) |
| 12 | 12 – 23 Apr | CRM & messaging | Leads & pipelines; forms & custom fields (incl. quote requests); rule-based chatbot; event template engine (email/SMS/WhatsApp) | |
| 13 | 26 Apr – 07 May | Referral engine | Self + 7 levels; pending/available; wallet rules; attribution; customer payouts; My WURK | |
| 14 | 10 – 21 May | Agents | Sponsor hierarchy; package commissions & clawback; EFT payouts; agent dashboard; demo sites | |
| 15 | 24 May – 04 Jun | Reports & campaigns | Tenant reports; SaaS metrics; **profit per package, pricing simulator, provider reconciliation, margin alerts**; analytics; campaigns & offers with credit estimates; monthly usage summary; auto top-up | |
| 16 | 07 – 18 Jun | Self-service & support | AI assistant; help centre & short videos per category; consent-based impersonation; tickets; tenant health; backup/restore & export | |
| 17 | 21 Jun – 02 Jul | **Cloud, domains & production infrastructure** *(moved)* | Chosen cloud (AWS or Azure, South African region); custom domains & SSL (Cloudflare for SaaS); wurk.co.za DNS; infrastructure as code; staging & production; monitoring & alerts; backups | |
| 18 | 05 – 16 Jul | Hardening | Penetration test; load testing at scale; shard rollout rehearsal; DR drill; accessibility; design polish; UAT | |

**Closed beta** from ~19 July 2027 with 20–50 pilot businesses; 2–3 sprints of beta feedback before public launch (~August–September 2027). Sprint dates do not yet allow for South African public holidays (e.g. Good Friday and Family Day 2027); affected sprints will be noted in their specifications.

## Categories and templates (24)

| Category | Templates | Category sections |
|---|---|---|
| Salon & beauty | Hair & braids salon; Nails, lashes & barbers | Before & after, packages & specials |
| Health | Dental practice; Doctor's practice | Accreditations (medical aids), online consultations, patient documents |
| Spa & wellness | Day spa; Wellness practitioner | Packages & specials, online sessions |
| Retail | Boutique; General store | Promotion banner, shop (Sprint 10) |
| Fitness | Gym & class studio; Personal trainer | Class timetable, online sessions |
| Professional services | Advisory practice; Agency / consultancy | Accreditations, portfolio, quote form, online consultations |
| Car mechanics | Workshop & service centre; Panel beater & auto electrician | Quote form, service areas, accreditations (brands) |
| Hospitality | Hotel / guesthouse; Restaurant | Rooms & rates (enquiries / embedded booking engine), food menu |
| Trades & home services | Solar & electrical; Home services | Quote form, service areas, before & after, accreditations |
| Education & training | Tutor & online classes; Early-learning centre | Class timetable, online classes |
| Events & creative | Photographer & events portfolio; Venue & event planner | Portfolio, packages, quote form |
| Transport & logistics | Shuttles & tours; Couriers & removals | Quote form, service areas |

## Definition of done for every template

- **Launch in one hour or less** by a non-technical owner — measured with real owners in Sprint 7 (median under 60 minutes, everyone succeeds without help).
- Premium on first load: realistic South African content for the category, licensed photography, the business's own branding applied.
- Mobile-first; Lighthouse mobile performance ≥ 90; no serious or critical accessibility issues (WCAG 2.2 AA) on phone and desktop.
- Every editable field safe to edit on the page; every section looks finished with whatever the owner fills in.
- Switching template within the category keeps the owner's content.

## Client dependencies

| Item | Needed by |
|---|---|
| Cloudflare account and wurk.co.za DNS | Sprint 17 (with cloud) |
| Template photography (licence or commissioned shoot) | Sprint 6 |
| AI provider account (Anthropic API) | Sprint 6 |
| 5+ non-technical owners for the timed launch test | Sprint 7 |
| Legal review: telehealth, patient documents, prescriptions (POPIA special personal information) | Before Sprint 9 ships patient documents |
| Cloud decision (AWS or Azure) and domains | Sprint 15 at the latest (accounts, region, lead time) |
| Carried over: DebiCheck provider; invoice issuer details; Paystack test keys | As soon as possible |

## Team size sensitivity

| Team | Sprints to beta | Approx. time |
|---|---|---|
| ~6–7 | 25–27 | 12–14 months |
| ~10 | 19 | 9.5 months |
| ~14 (two squads) | 14–15 | 7 months |

## Decisions since revision 3

- **Payment gateways (Sprint 10):** each business connects **its own PayFast or Yoco** account; WURK is never in any customer payment and takes no fee on sales. **Ozow and Peach were not built**; Paystack is used only for WURK's own subscriptions. Another gateway can be added inside the Payments module without touching shop or bookings.
- **Dates (corrected 30 Oct 2026):** development is **ahead** of revision 3's calendar — Sprints 0–10 are complete (v0.12.0, 30 Oct 2026) against Sprint 10's planned 15–26 March 2027. An earlier note here wrongly said the dates had slipped. What now sets the pace is outside development: provider sandbox and live accounts, WhatsApp templates, owners for the timed launch test, and template photography. Any change to the calendar is for WURK to decide (revision 4).
- **Year 2038 (for Sprint 18, Hardening):** most tables use MySQL `TIMESTAMP`, which ends on 19 January 2038. Records (9.5) already uses `DATETIME`; the rest should be migrated well before 2038.
