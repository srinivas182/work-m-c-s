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
| 15.2 Website analytics | see PR | Counting happens in the public page response (also on 304s). Caught in my test: Laravel's withHeaders() carries headers into later requests — cleared per visit. 1,557 backend tests on SQLite; analytics and Reports on MySQL 8. Browser tests in CI only. |
