# Sprint 16 — Self-service & support (specification)

**Status:** approved by WURK (11 Nov 2026), including the market-review recommendations.
**Plan:** AI assistant; help centre & short videos per category; consent-based impersonation; tickets; tenant health; backup/restore & export (docs/sprint-plan.md).
**PRD:** §13 (self-service & support: assistant that answers **and acts**, tooltips and 30-second videos on every screen, searchable help centre, plain-language errors, self-service backup/restore/export, tickets from the dashboard), §4.8 (ticket system, help centre CMS, tenant health: uptime, SSL, DNS, inactivity, failed payments).
**Builds on:** the `Ai` module (providers with fallback, cost logging, quoted credit-charged actions), `Audit` (already records an impersonator), Reports (15.1), Metrics "At risk" (15.6).

## What the market leaders do (and where they fall short)

| Area | Leaders | What they do well | Where WURK can do better |
|---|---|---|---|
| Assistant that acts | **Shopify Sidekick**, HubSpot Breeze Copilot, Wix AI assistant | Answer "how do I…", read the store's data, take actions (discounts, reports) | Actions are often opaque; WURK shows **exactly what will change before anything happens**, asks to confirm, logs it and offers **undo** |
| AI answers from help | **Intercom Fin**, Zendesk AI | Answer from the help centre with sources; hand over to a person | WURK's answers also use the **business's own live data** and the **page you're on** |
| Help centre | Intercom Articles, Zendesk Guide, Shopify Help Center | Searchable articles, categories, feedback | **"?" on every screen** opens help *for that screen*, with a 30-second video; no hunting |
| Tickets | **Zendesk**, Freshdesk, Intercom Inbox | Inbox, assignment, canned replies, SLAs, satisfaction ratings | **Deflection first** (matching articles and an AI answer before a ticket); the ticket carries the **page and what went wrong** automatically |
| Staff access | Shopify collaborator requests, Stripe support access | Owner approves access; time-limited | **View-only by default**, full access only for a set time, **sensitive actions blocked**, banner on every screen, owner can revoke instantly |
| Customer health | Gainsight, ChartMogul, Baremetrics | A health score with reasons | Score with **plain reasons and a suggested next step** ("no sign-in for 34 days — call"), built from what WURK already measures |
| Export / restore | Shopify CSV exports, Squarespace export, Google Takeout | Download your data | **One-click full export** (CSVs + files) and a **30-day "Recently deleted"** to undo mistakes — the restore people actually need |

## Parts

| Part | What |
|---|---|
| **16.1 Help centre** | Super-admin CMS: categories, articles (rich text, images), **30-second videos**, publish/draft, which screens an article belongs to. **"?" on every back-office screen** opens that screen's help (articles + video) in a side panel; **tooltips** on key fields. Searchable help centre (`help.wurk.co.za`, public); **"Was this helpful?"**; article views and failed searches reported to WURK (to know what to write next). |
| **16.2 AI assistant ("Ask WURK")** | A side panel on every screen. **Answers** how-to questions from the help centre (with links to the articles) and questions about the business's own data, read-only ("how many bookings tomorrow?", "best seller this month?"). **Acts** on request — "add a Saturday 9–1 slot for Thandi", "create a 10% December coupon", "draft a win-back campaign" — by **showing exactly what will change and asking to confirm**; respects the person's permissions; logged; **undo** where it can be. **Free monthly allowance, then credits** (quoted first, like 14.x AI actions). Provider-agnostic through the `Ai` module. |
| **16.3 Support tickets** | **"Contact support"** in the dashboard: first shows matching articles and an AI answer (deflection); the ticket carries **the page, browser and recent error** automatically; attachments (screenshots). WURK **inbox**: assign, status, priority, **internal notes**, **canned replies**, **AI-suggested reply** with sources, **SLA targets by package**, email notifications both ways, **satisfaction rating** on close. |
| **16.4 Consent-based support access** | From a ticket, WURK asks for access; **the owner approves** — **view-only** (default) or **full** for a set time (max 2 hours). A **banner** on every screen while staff are in; **sensitive actions blocked** (bank details, payouts, passwords, deleting the business); every action **audited as "WURK staff (on behalf of …)"**; the owner can **end it instantly**. |
| **16.5 Tenant health** | Admin → Health: a score per business with **plain reasons and a suggested action** — no sign-in (15.6), failed payments/grace, credits run out, site not published, errors, and (Sprint 17) SSL/DNS for custom domains. Filters, sort by MRR at risk, export. Weekly digest to WURK's success team. |
| **16.6 Export & "Recently deleted"** | **Settings → Your data**: one click builds a **full export** (CSVs per area + uploaded files, ZIP) in the background; emailed link valid 24 hours; logged. **Recently deleted** (30 days): customers, products, services, bookings, pages, campaigns — restore with one click; purged after 30 days. Full-business restores stay with WURK (backups), requested through a ticket. |

## Decisions

| # | Decision | Status |
|---|---|---|
| A | **Assistant allowance**: 50 questions a month free per business, then **1 credit per question**; actions free; a monthly cap. Both numbers are **super-admin settings** in Admin. | **Accepted (WURK, 11 Nov 2026)** — no other numbers given. |
| B | **Support targets** (first reply, business hours): Starter 24 h, Growth 8 h, Pro 4 h, managed 2 h; shown to the business; breaches flagged in the inbox. | **Decided (WURK, 11 Nov 2026).** |
| C | **Restore scope**: "Recently deleted" (30 days) + full export, self-service; full-business restore by WURK via a ticket. | **Decided (WURK, 11 Nov 2026).** |
| D | **Support access**: view-only by default; full only when the owner chooses, up to 2 hours; sensitive actions always blocked. | **Decided (WURK, 11 Nov 2026).** |

## Order

16.1 → 16.2 → 16.3 → 16.4 → 16.5 → 16.6, small parts, one full CI run each. Release as **v0.18.0**. Before closing: every spec item and agreed addition verified against code and tests (as in 15.6).

## Progress

| Part | PR | Notes |
|---|---|---|
| 16.1 Help centre | see PR | Central Help module; screens keyed by Inertia page name; videos uploaded, not embedded (no third-party tracking). Caught by lint: the panel set state inside an effect — now loads on click. Sprint order changed by WURK: 16 → 18 (hardening) → 17. 1,708 backend tests on SQLite (in groups); Help on MySQL 8. |
| 16.2 Ask WURK | see PR | Built in the Ai module; tools registered by Bookings (day's bookings, add hours), Campaigns (coupon, draft) and Ai (business figures over the reports registry). Available on every package (questions are support, not paid AI work): free allowance then credits; excluded from the paid-AI daily cap. Proposals expire after 30 minutes. |
