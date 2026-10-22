# Sprint 3 — Signup & billing: detailed specification

| | |
|---|---|
| **Sprint** | 3 of 18 · 30 November – 11 December 2026 |
| **Status** | Approved 22 Oct 2026 — 3.1 ✅ (#28), 3.2 ✅ (#29), 3.3 ✅ (#30), 3.4 in review |
| **Depends on** | v0.3.0 (packages, entitlements, providers, WURK Credits, security settings); PRD §4.5, §5.1–5.2, §7.14, §11 |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each must pass CI and be merged before the next starts |

---

## 1. Objectives

Let a business find WURK, sign up on its own, and pay — and let WURK collect every month without manual chasing:

1. **Be found** — the wurk.co.za marketing site with pricing drawn live from packages.
2. **Sign up alone** — a short wizard: details, verify, package, web address, trial or payment; land in the back office.
3. **Get paid** — card (recurring), DebiCheck debit orders, and cash at a WURK office with OTP approval; credit top-ups.
4. **Stay compliant** — SARS-compliant tax invoices, credit notes and refunds.
5. **Handle failure** — dunning with retries, grace, restriction and suspension; proration on package changes.

## 2. Out of scope (and where it lands)

| Item | Sprint |
|---|---|
| Wizard steps: **category, template with live preview, AI site generation** (PRD §5.2 steps 4, 5, 7) | Added to the wizard when the website engine (4–5) and AI (14) ship; until then new businesses start with a default site |
| Custom domains at signup (DNS checker, SSL) | 5 |
| Agents, referral links on signup, commissions and payouts (PRD §4.6) | 12 |
| Post-suspension retention and deletion (PRD §11.2) | 14 — suspension itself is in this sprint; nothing is deleted before 14 |
| Instant EFT (Ozow) | Optional stretch in 3.3 if the card gateway work finishes early; otherwise 4 (decision 3) |

---

## 3. Sub-sprints

### 3.1 Marketing site and signup wizard

| Item | Details |
|---|---|
| Marketing site | wurk.co.za: home, features, pricing (live from public packages, monthly/yearly toggle, VAT shown), FAQ, contact, legal pages (content supplied by WURK). Server-rendered for search engines; Lighthouse ≥ 90 on mobile for performance, accessibility, SEO |
| Wizard | (1) business and owner details → (2) verify phone or email by one-time code → (3) package, monthly or yearly → (4) web address `name.wurk.co.za` with live availability → (5) start trial, or pay (3.2) → (6) land in the back office |
| Accounts | Creates the WURK ID, the business on the least-full shard, owner membership and role, subscription (trial or pending payment), first credit allowance |
| Abuse controls | Rate limits per IP and per destination, one trial per verified phone/email, disposable-email block list, CAPTCHA only if abuse appears (Cloudflare Turnstile ready) |
| Resume | Progress saved; leaving and returning resumes the wizard after verification |

### 3.2 Payments: cards and credit top-ups

| Item | Details |
|---|---|
| Gateway abstraction | `PaymentGateway` contract: create checkout, store a reusable card authorisation, charge a saved card, refund, verify webhook; one driver in this sprint (decision 1), others behind the same contract |
| Subscriptions | Monthly or yearly billing periods; first payment at signup or trial end; renewal charges on the saved card |
| Webhooks | Signature-verified, idempotent (event id), replay-safe; the only source of truth for "paid" |
| Credit top-ups | Credit packs set by WURK staff (e.g. 100 / 500 / 2 000 credits); bought from Settings → WURK Credits; granted only after payment confirmation |
| Owner billing page | Package, renewal date, next amount, payment method (update card), invoices, buy credits (PRD §7.14) |

### 3.3 DebiCheck and cash

| Item | Details |
|---|---|
| DebiCheck | Mandate creation with the owner's banking details, bank approval by the account holder on their phone (TT1/TT2 per the provider), monthly collections on the agreed date, status webhooks. Built against the provider WURK contracts (decision 2) |
| Cash at a WURK office | Staff record amount, reference and payer → approval code sent to the recipients in Security settings → staff enter the code → paid, receipts to the configured recipients and the owner, super admin notified, audited (PRD §4.5) |
| Payment allocation | Every payment (card, debit order, cash) settles the oldest unpaid invoice first; overpayments become account credit |

### 3.4 Invoices, proration and dunning

| Item | Details |
|---|---|
| Tax invoices | Issued for every subscription charge and credit purchase. SARS requirements: the words "Tax Invoice", WURK's name, address and VAT number, the business's name, address (and VAT number if supplied), unique sequential number, date, description, amount excl. VAT, VAT, total. Immutable once issued; PDF download |
| Credit notes and refunds | Credit notes reference the original invoice; refunds go back through the original method (card) or EFT (cash, debit order) |
| Proration | Upgrades charge the prorated difference immediately; downgrades and yearly → monthly take effect at the next renewal (PRD §11.3) |
| Dunning | Day 0 notify with pay link; retries days 3, 5, 7; days 1–7 grace with banner; days 8–14 back office limited to Billing; day 15 suspended (public sees "temporarily unavailable"); paying restores immediately (PRD §11.1). Timings configurable by super admin |
| Admin | Invoices and payments per business, failed-payment queue, manual retry, refunds/credit notes (permission-gated, audited) |

---

## 4. Data model (summary)

| Database | Tables |
|---|---|
| Central | `billing_accounts` (per business: billing details, VAT number, saved method), `invoices` + `invoice_lines` (immutable, sequential per issuing entity), `credit_notes`, `payments`, `payment_attempts`, `mandates` (DebiCheck), `cash_payments` (with approval), `gateway_events` (webhook idempotency), `credit_packs`, `signups` (wizard progress) |
| Shard | — (billing is WURK's relationship with the business, so it lives centrally; credit grants after purchase use the existing shard ledger) |

All money in cents (ZAR); VAT computed per line and rounded per invoice with the rate stored on the invoice.

## 5. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 3.1 | A new visitor signs up from the pricing page to a working back office in under 3 minutes on a phone; handle availability is live; a second trial with the same verified phone/email is refused; Lighthouse mobile ≥ 90 |
| 3.2 | Sandbox: card checkout → subscription active → renewal charge succeeds on the saved card; a replayed or forged webhook changes nothing; credit pack purchase grants credits only after confirmation |
| 3.3 | Sandbox DebiCheck mandate approved and a collection recorded (if the provider sandbox is available — decision 2); cash payment cannot be marked paid without the approval code; every step audited |
| 3.4 | Invoice numbers have no gaps under concurrency; invoices contain every SARS field; upgrade proration matches a worked example to the cent; a failed renewal moves through every dunning stage on schedule (time-travel test) and payment restores service |

## 6. Decisions to confirm

1. **Card gateway: Paystack** as the first driver — clean recurring-card API (saved authorisations), ZAR settlement, good webhooks. PayFast (familiar to SA businesses) as the second driver later if WURK wants it.
2. **DebiCheck provider:** WURK's application decides this (candidates: Netcash, Peach Payments, Stitch). If the provider's sandbox isn't available by 7 December, 3.3 ships cash and the DebiCheck contract with a fake provider, and the live driver follows in Sprint 4.
3. **Instant EFT** is a stretch goal this sprint (via Ozow), otherwise Sprint 4 — the delivery plan's Sprint 3 scope is card, DebiCheck and cash.
4. **Trials need no card**; a card or debit order is required at trial end, with 3 reminders (7, 3 and 1 day before).
5. **Invoice numbering:** one gapless series `WURK-INV-000001` (credit notes `WURK-CN-000001`), allocated inside the payment transaction.
6. **Marketing site** built in this repository as server-rendered pages (Inertia SSR) sharing the design system — one codebase, one deploy, consistent brand.
7. **Dunning defaults** exactly as PRD §11.1; changeable later by super admin.

## 7. Client dependencies (needed during this sprint)

| Item | Needed by |
|---|---|
| WURK legal entity name, registered address and **VAT registration number** (printed on every tax invoice) | 3.4 (placeholders until then; no live invoices without them) |
| **Paystack** merchant account (test keys now, live keys before launch) | 3.2 |
| **DebiCheck provider** contract and sandbox access | 3.3 |
| Marketing copy, imagery, FAQ, and legal pages (terms of service, privacy policy / POPIA notice) | 3.1 (placeholders until then) |
| Credit pack sizes and prices; final package line-up | 3.2 |
| Bank account details for EFT refunds and cash deposits | 3.3 |

---

## 8. Progress log

| Sub-sprint | PR | Result |
|---|---|---|
| 3.1 Marketing site & signup | #28 | 456 tests on SQLite and MySQL 8; 32 browser checks incl. a full signup from the pricing page into the back office (desktop and mobile). Lighthouse mobile ≥ 98 performance, 100 accessibility, 100 SEO through compression (best practices only lacks HTTPS locally). Category, template and AI steps join the wizard with Sprints 4–5 and 14. Without a trial (already used), finishing waits for card payment in 3.2 |
| 3.2 Card payments & credit top-ups | #29 | 497 tests on SQLite and MySQL 8; 32 browser checks. Paystack calls checked against its docs: only `charge.success` is sent as a webhook, so declines are taken from the charge response or verify API. Real charges still need Paystack test keys |
| 3.3 Cash & DebiCheck | #30 | 509 tests on SQLite and MySQL 8; 32 browser checks. DebiCheck runs against a stand-in provider (refused in production) until WURK chooses a provider; the live driver follows in Sprint 4. Three real bugs found by tests: unlimited cash-code guesses, debit collections marked past due, shared rate-limit counters |
| 3.4 Invoices, proration & dunning | see PR | 522 tests on SQLite and MySQL 8; 32 browser checks; invoice PDF and billing/cash screens reviewed visually. Issuer details are placeholders until WURK enters real ones. Dunning notices by email/SMS; WhatsApp needs an approved template; instant EFT not yet in the pay link |
