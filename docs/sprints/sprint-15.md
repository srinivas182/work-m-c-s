# Sprint 15 — Reports & campaigns (specification)

**Status:** approved by WURK (9 Nov 2026), with the recommended additions; decision A (running cost) still to be supplied.
**Plan:** tenant reports; SaaS metrics; profit per package, pricing simulator, provider reconciliation, margin alerts; analytics; campaigns & offers with credit estimates; monthly usage summary; auto top-up (docs/sprint-plan.md).
**PRD:** §4.1 (super admin KPIs), §7.7 (marketing), §7.11 (reports), credits (§ top-ups).

## Parts

| Part | Who | What |
|---|---|---|
| **15.1 Business reports** | Business owner / managers | **Sales** (by day/week/month, product, service, payment method), **bookings** (utilisation, peak hours, no-shows), **customers** (new vs returning, repeat rate, lifetime value), **CRM** (lead sources, conversion, response time), **stock** (low and slow-moving), **VAT collected** — per branch and combined, any date range, compared with the previous period. **CSV and PDF export**; **scheduled email** (weekly/monthly); **weekly WhatsApp summary** to the owner. Referral reports (13.9) link in. |
| **15.2 Website analytics** | Business | Visits, top pages, where visitors came from, and **which visits became orders or bookings** — first-party and cookie-free (no Google Analytics, nothing to consent to), counted on WURK's own servers. |
| **15.3 Campaigns & offers** | Business | Email, SMS or WhatsApp to a **segment** (all customers, recent, inactive, top referrers, a branch — with live counts); **credit estimate before sending** ("312 SMS ≈ 312 credits; you have 540"); **send now or schedule**; **coupon codes** for the shop and bookings (amount or %, expiry, limits); **results**: delivered, opened, clicked, redeemed, and sales from the campaign. Consent and opt-out automatic (POPIA): only opted-in customers are sent to; every message carries an opt-out. |
| **15.4 WURK SaaS metrics** | Super admin | MRR, ARR, net revenue (by package and country); new, active, trials, trial conversion; churn (logo and revenue), LTV, ARPU; failed payments, grace and suspended; agent commissions (earned, pending, paid); credit revenue and usage by channel; tenant health (inactive owners = churn risk). Date range, comparison with the previous period, export. |
| **15.5 Profitability and credits** | Super admin / business | **Profit per package** (subscription and credit revenue minus provider costs and WURK's per-business running cost); **pricing simulator** ("what if Growth cost R699?" — with the current customers' usage); **provider reconciliation** (upload a provider's statement CSV, matched against WURK's own send log; differences listed); **margin alerts** (a business whose message costs exceed what it pays). Businesses: **monthly usage summary** email and **auto top-up** of credits (below a threshold, buy a chosen bundle with the saved card; monthly cap). |

## Recommended additions

| Addition | Where | Why |
|---|---|---|
| **Plain-language highlights** at the top of reports ("Bookings up 12% on last month; Tuesday 9–11 is your busiest time; 14 customers haven't been back in 60 days") | 15.1 | Owners read sentences, not charts (Shopify, Square) |
| **Ready-made win-back and thank-you campaigns** ("customers not seen in 60 days", "first-time buyers this month") with suggested wording | 15.3 | One-click campaigns that bring money back (Klaviyo, Mailchimp) |
| **Send a test to yourself** and a phone preview before any campaign goes out | 15.3 | Stops embarrassing mistakes |
| **MRR movement** (new, expansion, contraction, churn, reactivation) and a **cohort retention** table | 15.4 | The two views WURK's investors and finance will ask for (ChartMogul, Baremetrics) |
| **Auto top-up safety**: a monthly cap and an email every time it tops up | 15.5 | No surprise card charges |

## Decisions

| # | Decision | Status |
|---|---|---|
| A | **WURK's running cost per business** for profit per package (hosting, storage, support) — a monthly figure per package (or one for all), editable in Admin. | **Still needed from WURK.** Until set it's **R0** and the profit report says so plainly — no invented figure. |
| B | **Provider statements** come in as a **CSV upload with column mapping**, so any provider's statement works. | **Decided (WURK, 9 Nov 2026).** |
| C | **Email opens** counted only for customers who've consented to marketing, and shown as **indicative** (Apple Mail inflates them); clicks and redemptions are the reliable results. | **Decided (WURK, 9 Nov 2026).** |
| D | **Weekly WhatsApp summary** uses WhatsApp **credits** like other messages (≈1 a week); owners can switch it off; needs a Meta-approved template (pending with the others) — until then it goes by email. | **Decided (WURK, 9 Nov 2026).** |

## 15.2 Website analytics — detailed design (9 Nov 2026)

- **Counted on WURK's servers, no script and no cookie**: public pages are served `max-age=0, must-revalidate` with an ETag, so every view reaches WURK (a full page or a "not modified"). Bots, crawlers, link previews and browser prefetches are skipped.
- **Unique visitors without cookies** (as Plausible): a one-way hash of a **random daily secret**, the business, the IP address and the browser. The secret is discarded after two days, so a visitor can't be followed across days or identified; no IP address is stored.
- **Where visitors came from**: the referring site (Google, Facebook, …) or a campaign's `utm_source`; otherwise "Direct".
- **Visits that became orders or bookings**: when checkout or a booking remembers the customer (CRM's `ContactRemembered`, already raised), the same day's hash finds that visitor's source — no change to the shop or bookings.
- **Reports → Website**: visitors, page views, top pages, sources, and orders/bookings by source, compared with the period before; highlights.

## 15.3 Campaigns and offers — detailed design (9 Nov 2026)

**15.3a — sending campaigns** (new Campaigns module; `campaigns.manage` for owners):
- **Audiences from CRM, only customers who agreed to marketing**: everyone; seen in the last 30 days; not seen in 60+ days (win-back); one branch; top referrers (when the referral programme is on). Live counts.
- **Email or SMS** (WhatsApp once Meta approves the marketing templates). **Credit estimate before sending** ("312 SMS ≈ 312 credits; you have 540") — refused if the credits won't cover it. **Test send to yourself**; **send now or schedule**.
- **Opt-out on every message** (a link; one click withdraws consent in CRM, logged) — POPIA.
- **Results**: sent, failed, **clicks** (links tracked through WURK), **opens** for email (indicative — decision C).
- **Ready-made campaigns**: win-back ("We miss you…") for customers not seen in 60 days, and thank-you for first-time customers this month, with suggested wording.

**15.3b — coupons**: codes for the shop and booking deposits (amount or %, expiry, how many times), optionally attached to a campaign so its results show redemptions and the sales they made.

## Order and size

15.1 → 15.2 → 15.3 → 15.4 → 15.5, each in small parts with one full CI run per part. Release as **v0.17.0**.

## Out of scope

Paid advertising integrations (Meta/Google ads); AI-written campaign copy (Sprint 16's AI assistant can add it); SMS two-way replies.

## Progress

| Part | PR | Notes |
|---|---|---|
| 15.1a Reports: sales, bookings, customers | #153 | Caught: the formatter turned the "nothing before?" check strict, which would divide by zero for 0.00 — made safe. Lifetime value counts paid amounts only (unpaid bookings have none). 1,551 backend tests on SQLite; Reports and CRM on MySQL 8. Browser tests in CI only. |
| 15.1b Takings/VAT, leads, stock; export; summaries | #154 | VAT only for businesses that say they're registered (most small businesses aren't). Scheduled summaries run with no one signed in, so report periods can be marked "for owners". Caught: business routes drop `{tenant}`, so a `$tenant` argument shifted the export's parameters (a crash) — removed. 1,555 backend tests on SQLite; Reports and CRM on MySQL 8. Browser tests in CI only. |
| 15.2 Website analytics | #155 | Counting happens in the public page response (also on 304s). Caught in my test: Laravel's withHeaders() carries headers into later requests — cleared per visit. 1,557 backend tests on SQLite; analytics and Reports on MySQL 8. Browser tests in CI only. |
| 15.3a Campaigns | #156 | Caught: the estimate compared against the spending-limit headroom (null = no limit) instead of the balance; the subject wasn't personalised; rescheduling a sent campaign silently did nothing; four status updates lacked the business filter (raw-query guard). Top-referrers audience moves to 15.3b (needs a Referrals contract). Browser test: the e2e business has no package with campaigns, so the three pages are render-tested in Vitest instead. 1,620 backend tests on SQLite; Campaigns and CRM on MySQL 8. |
| 15.3b Coupons; campaign coupon sales; top referrers | #157 | Payments holds the Coupons contract (none by default; Campaigns supplies the book); Platform AudienceSegments for extra audiences. Caught: place()'s transaction didn't capture the coupon (would have been ignored); a shared helper first lived in Campaigns, then in Payments' internals — now with the contract; the cart's coupon message would have added a second status region. 1,625 backend tests on SQLite; Campaigns, deposits and shop checkout on MySQL 8. |
| CI: MySQL parts' limit 10 → 12 min | #158 | **Decided by WURK (9 Nov 2026).** Each part runs about 4–6 min; GitHub's slower machines add 3–4 min, which cancelled four good runs at 10 min (15.1b, 14.8a, 15.3b and one earlier). A typical whole run stays about 7 min; a truly stuck part still stops at 12. |
| 15.4a SaaS metrics: subscriptions | #159 | History by daily snapshot rather than change events (no code path can be missed); starts on deploy. MRR = package price excl. VAT (yearly ÷ 12) for active and past-due; demos and archived excluded. 1,628 backend tests on SQLite (run in five groups — one combined run hit the tool's time limit); Admin and Plans on MySQL 8. |
| 15.4b SaaS metrics: revenue, agents, credits, movement, cohorts, CSV | #160 | Credit use lives in each business's database, so it's rolled up nightly into one central table (backfillable from the ledgers). The CSV builder moved from Reports to Platform so Admin could share it. Caught in my tests: a VAT sum I'd added up wrong by a cent, and a comment that broke a file. 1,632 backend tests on SQLite (five groups); Admin and Credits on MySQL 8. |
| 15.5a Profitability | #161 | Costs live in Admin → Profitability (decision A is now a field there); unset costs are reported, never assumed. Running cost applies to trials too. Caught: the "what if" read the package price from the wrong field (would have shown R0 everywhere). 1,634 backend tests on SQLite (in groups); Admin and Credits on MySQL 8. |
| 15.5b Auto top-up; monthly usage summary | see PR | Triggered by Credits' existing LowCreditBalance (once per crossing). Caught on MySQL only: payments.details is JSON, which MySQL stores normalised ({"auto": true}), so a text match missed it and the monthly limit would never have applied — now a JSON query. 1,636 backend tests on SQLite (in groups); Billing on MySQL 8. |
