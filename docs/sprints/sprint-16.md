# Sprint 16 — Self-service & support (specification)

**Status:** ✅ complete — released as **v0.18.0** (15 Nov 2026). Approved by WURK (11 Nov 2026), including the market-review recommendations.
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
| 16.1 Help centre | #168 | Central Help module; screens keyed by Inertia page name; videos uploaded, not embedded (no third-party tracking). Caught by lint: the panel set state inside an effect — now loads on click. Sprint order changed by WURK: 16 → 18 (hardening) → 17. 1,708 backend tests on SQLite (in groups); Help on MySQL 8. |
| 16.2 Ask WURK | #169 | Built in the Ai module; tools registered by Bookings (day's bookings, add hours), Campaigns (coupon, draft) and Ai (business figures over the reports registry). Available on every package (questions are support, not paid AI work): free allowance then credits; excluded from the paid-AI daily cap. Proposals expire after 30 minutes. |
| 16.3a Support tickets | #170 | New central Support module (WURK's inbox spans businesses). Split in two: 16.3a = contact flow with deflection, context, screenshots, thread, emails, rating, basic inbox, targets; 16.3b = assignment, priority, internal notes, canned replies, AI-suggested reply, breach filters. Screenshots re-encoded as PNG instead of virus-scanned (images only). |
| 16.3b Support inbox | #171 | Assign (support-team members only: active staff whose role has support.manage), priority, views (mine/unassigned/late/due soon), internal notes, canned replies with placeholders, AI-suggested reply via the Ai `HelpAnswers` contract, editable targets and business hours. |
| 16.4 Support access | #172 | View-only is enforced by refusing every change (non-GET) during the session rather than by a reduced permission set — many screens share one permission for viewing and editing. Full access refuses sensitive routes, and money/access/security/health permissions are never granted (`SupportSessions::NEVER`). Access is checked on every request (scoped cache, cleared per business). |
| 16.5 Business health | #173 | New central Success module ("Admin → Health" became "Business health": the Health module is the platform's own liveness/readiness for load balancers). Scores computed nightly into one table so the page is fast at 100k businesses; each module owns its signals via the Platform `HealthSignals` registry. Server errors per business counted from Laravel's exception reporting. SSL/DNS signals arrive with custom domains in Sprint 17. |
| 16.6a Your data — export | #174 | New Data module. 16.6 split: 16.6a full export; 16.6b Recently deleted (most of the listed things can't be deleted yet — products and categories are deleted for good, services only switched off, customers/campaigns not deletable, bookings cancelled). Files live on several disks, so modules register files as well as tables. `data.export` added to the never-for-support list. |
| 16.6b Recently deleted | #175 | Soft delete added to products, variants, categories, services and campaigns (pages already had it). Raw queries over those tables now skip deleted rows (stock report; every campaigns query — a deleted draft can't be sent). Deleted slugs stay reserved for 30 days rather than being renamed. 16.6c: customers and cancelled bookings. |
| 16.6c Customers and cancelled bookings | #176 | Contacts get soft delete; the campaign audience query (the one raw query over contacts) skips deleted ones; erasure reuses Crm's existing POPIA erase and marks `erased_at`. Bookings record `cancelled_at`; restore re-claims the same slots under the unique key, so a taken time is refused, never double-booked. Coupons used on a cancelled booking aren't re-applied on restore. |

## Verification (before closing)

Every spec item and agreed addition, against the code and its tests.

| Item | Where | Tests | Status |
|---|---|---|---|
| Help CMS: categories, Markdown articles, images, 30-second videos, publish/draft, screens per article | Help module; Admin → Help centre | `HelpCentreTest` | ✅ |
| "?" on every back-office screen (articles + video), tooltips on key fields | `HelpPanel`, `HelpTip` | `help.test.tsx`; `e2e/admin.spec.ts` | ✅ |
| Public, searchable help.wurk.co.za; "Was this helpful?"; views and failed searches reported | `PublicHelpController`; Admin → Help centre | `HelpCentreTest` | ✅ |
| Ask WURK: how-to answers from help with links; own data read-only | Ai `Assistant`, `FiguresTool`, Bookings `BookingsOnDayTool` | `AssistantTest`; `e2e/ai.spec.ts` | ✅ |
| Ask WURK acts: shows exactly what changes, confirm, permissions, logged, undo | Platform `AssistantTools`; hours, coupon, campaign-draft tools | `AssistantTest` (coupon, hours, draft, permissions, expiry) | ✅ |
| Free allowance then credits, quoted first; monthly cap; numbers in Admin (decision A) | `Assistant::ask`; Admin → WURK AI | `AssistantTest` (allowance, refund, cap, admin) | ✅ |
| Provider-agnostic through the Ai module | `Ai` contract, task "assistant" | `AssistantTest`, `AiClientTest` | ✅ |
| Contact support: articles and an AI answer first (deflection) | Support `TicketsController::suggest`, Ai `HelpAnswers` | `SupportTicketsTest`; `support.test.tsx`; `e2e/support.spec.ts` | ✅ |
| Ticket carries page, browser, recent error; screenshots | `recent-error.ts`; `Screenshots` (re-encoded) | `SupportTicketsTest`; `support.test.tsx` | ✅ |
| Inbox: assign, status, priority, internal notes, canned replies, AI-suggested reply with sources | Admin → Support inbox | `SupportTicketsTest`; `e2e/admin.spec.ts` | ✅ |
| SLA targets by package in business hours (decision B), breaches flagged | `SupportTargets`; inbox "late" | `SupportTicketsTest` | ✅ |
| Emails both ways; satisfaction rating on close | `Tickets` | `SupportTicketsTest` | ✅ |
| Support access: asked from a ticket; owner approves view-only (default) or full, max 2 h (decision D) | Support `SupportAccess`; Settings → Support access | `SupportAccessTest`; `access.test.tsx` | ✅ |
| Banner on every screen; sensitive actions blocked; audited "WURK staff (on behalf of …)"; end instantly | `GuardSupportSession`; `SupportSessions::NEVER`; Audit recorder | `SupportAccessTest` | ✅ |
| Tenant health: score with plain reasons and a next step — sign-ins, payments/grace, credits, site not published, errors | Success module; `HealthSignals` from Identity, Plans, Credits, Website, Support | `BusinessHealthTest` | ✅ (SSL/DNS with Sprint 17's custom domains) |
| Filters, sort by MRR at risk, export; weekly digest | Admin → Business health | `BusinessHealthTest`; `e2e/admin.spec.ts` | ✅ |
| Your data: one-click full export (CSVs + files, ZIP), background, 24-hour emailed link, logged | Data module | `DataExportTest`; `e2e/support.spec.ts` | ✅ |
| Recently deleted (30 days): customers, products, services, bookings, pages, campaigns; restore; purge | `RecentlyDeleted` registry; Shop, Bookings, Campaigns, Website, Crm | `RecentlyDeletedTest`, `DeletedCustomersBookingsTest` | ✅ |
| Full-business restore by WURK via a ticket (decision C) | Process (backups) — Support ticket | — | ✅ (by design) |

**Additions made along the way** (each built and tested): WURK support can never export a business's data; screenshots re-encoded instead of virus-scanned; per-business server-error counting for health; navigation skips items it can't link from the current page (found by a browser test); deleted shop addresses stay reserved for 30 days.

**Known limits** (shown to the user where they apply): a coupon used on a cancelled booking isn't re-applied when it's put back; bookings with a deposit or an online meeting can't be put back (book again); a service with bookings stays hidden after 30 days so its bookings keep its name.
