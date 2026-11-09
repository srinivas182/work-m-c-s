# Sprint 14 — Agents (specification)

**Status:** approved by WURK (7 Nov 2026); all decisions made (8 Nov 2026).
**Plan:** Sprint 14 — sponsor hierarchy; package commissions and clawback; EFT payouts; agent dashboard; demo sites (docs/sprint-plan.md).
**PRD:** §4.6 (super admin), §5.2 (attribution at signup), §10 (agent area), acceptance criterion 9.

Agents sell WURK packages to businesses and earn commission on what those businesses pay WURK. Unlike Sprint 13 (a business's customers), this is **WURK's own** programme: agents, commissions and payouts live in WURK's central data, managed by WURK staff.

## Parts

| Part | What |
|---|---|
| **14.1 Agents and the sponsor hierarchy** | Super admin creates agents (a WURK ID with an *agent* membership — the kind already exists) and places each under a sponsor; moves agents with an audit trail; refuses loops. Per agent: commission duration (lifetime, or N months from each business's start), active/suspended. |
| **14.2 Attribution** | Each agent has a referral link (`wurk.co.za/?agent=CODE` or `/a/CODE`) and QR. A business that signs up through it is attributed to that agent on the business record (PRD §5.2), first link wins, and the attribution is shown in the super admin's business view. Staff can correct an attribution (logged). |
| **14.3 Commission ledger** | On each WURK **subscription** payment (not setup, managed fees or credits — PRD §4.6), **excluding VAT**: the attributed agent earns level 1, their sponsor level 2, and so on up the hierarchy, using the global percentages (default: self + 7 levels; number of levels configurable). Only while the business is within that agent's commission duration. Append-only ledger in cents; once per payment. |
| **14.4 Refund window and clawback** | **WURK's refund window** (super admin; default 30 days — decision B/C): WURK billing refuses refunds on a subscription payment once it closes, and commission on that payment becomes payable when it does (0 days = no refunds, commission credited at once). A refund or credit note **within** the window reverses the commission in proportion before it's ever paid; cancellation stops future commission. **Card chargebacks** (outside WURK's control, up to ~120 days) after payout make the agent's balance negative, recovered from future commission; agents negative for 90+ days are flagged (recommended — to confirm). |
| **14.5 Payouts** | Commission becomes payable when WURK's refund window closes (14.4). WURK finance generates an **EFT batch file** of agents above a minimum, pays via the bank, then uploads or marks the batch paid → agents' balances update and they're notified. Each batch has a reference; batches are immutable once paid. |
| **14.6 Agent area** | Dashboard: businesses onboarded (total, this month), active vs churned, earnings, available, pending, paid out; team (downline) view. Menus: Customers (businesses, onboarding date, package, last paid, status), Earnings (per business, period, level), Payouts (batches, dates, references), Referral link and marketing kit, Settings (password, MFA, banking details). |
| **14.7 Banking details** | Changes need a one-time code to the agent's phone and are logged; account numbers encrypted at rest (as for 13.7) and shown masked; a change made within 7 days of a batch holds that agent's payout for review (fraud control). |
| **14.8 Demo sites** | One active demo per agent: a watermarked, non-indexed business site with no payments or messaging, auto-deleted after 14 days (reminders day 10 and 13); the agent can delete and recreate; it converts to a live business on payment with its content kept. |

## Decisions

| # | Decision | Status |
|---|---|---|
| A | **Commission percentages** per level are **set — and changed at any time — by WURK's super admin in the back office** (Admin), for self and each level; every level starts at 0% until set (no commission accrues at 0%). | **Decided (WURK, 8 Nov 2026).** |
| B | **Hold period** = **WURK's refund window**, set by the super admin, **default 30 days**. | **Decided (WURK, 7 Nov 2026).** |
| C | **No refunds after the window** (WURK billing refuses them), so commission is only paid once refunds have closed and a refund can't reach paid commission; **0 days = commission credited at once**. Card **chargebacks** after payout (outside WURK's control): recovered from future commission; agents negative 90+ days flagged. | **Decided (WURK, 7 Nov 2026).** |
| D | **EFT batch file**: standard CSV (name, bank, branch code, account, amount, reference); WURK's bank's exact format if named later. | **Decided (WURK, 7 Nov 2026).** |
| E | **Agents sign in at `app.wurk.co.za/agent`** (the back-office surface, MFA, sessions; its own menu). | **Decided (WURK, 7 Nov 2026).** |
| F | **Tax statements**: an annual earnings statement (PDF) per agent; WURK's accountant to confirm any IRP5/IT3(a) needs. | **Decided (WURK, 7 Nov 2026).** |

## 14.5 Payouts — detailed specification (8 Nov 2026)

**14.5a — building the batch** (Admin → Agent payouts; new permission `agents.pay` for Finance and the super admin):
- **Minimum payout** — WURK finance's setting; no minimum until set.
- **Preview first** (recommended addition): *included* (agent, amount, bank, last four), *held* (banking details changed in the last 7 days — fraud control), *skipped* with the reason (no banking details, below the minimum, suspended, owes WURK).
- **Create** reserves each included amount at once as a `paid_out` line in the agent's ledger (reference `batch:<id>`), so it can't be batched or paid twice; banking details are copied onto the batch (masked).
- **Download the EFT CSV** (decision D: holder, bank, branch code, account type, account number, amount, reference) — account numbers decrypted only for the download; every download logged (recommended addition).
- **Cancel** an unpaid batch: reservations released.

**14.5b — settling it:** **mark paid** (locked; each agent gets a **remittance advice** — amount, reference, the businesses it covers — recommended addition); **mark one payment bounced** (the money returns to that agent's balance and they're asked to check their banking details — recommended addition).

## 14.6 Agent area — detailed specification (8 Nov 2026)

At `app.wurk.co.za/agent` (agents only; started in 14.7). Every figure is the agent's own — and, for the team view, their downline's counts only (never another agent's earnings or their businesses' details).

**14.6a — dashboard and My businesses**
- **Dashboard:** businesses onboarded (total, this month); **active**, **at risk** (payment overdue) and **churned** (cancelled or suspended); commission available, pending and paid out; earned this month.
- **Next payout** *(recommended addition)*: whether they'll be in the next batch, and if not, why — the same rules finance sees (no banking details, below the minimum, banking details changed in the last 7 days, suspended, owes WURK).
- **Coming available** *(recommended addition)*: pending commission by the date it becomes payable, next 30 days.
- **Earnings by month** *(recommended addition)*: the last 12 months, as bars with a table for screen readers.
- **My businesses:** name, joined, package, status (trial · active · **at risk** · churned), monthly subscription, and the commission they've earned from it. **At-risk businesses first** *(recommended addition — the agent can help keep them, and their commission)*.

**14.6b — earnings, team, payouts, marketing kit**
- **Earnings:** each line (business, level, amount, pending/available date) for a period; **download as CSV** for their own records *(recommended addition)*.
- **Team:** their downline by level — names, businesses onboarded, active/suspended; no earnings of others.
- **Payouts:** batches they were paid in, with the remittance details (and bounced ones).
- **Marketing kit:** link, QR download, **WhatsApp share** with a ready message *(recommended addition)*.

## 14.8 Demo sites — detailed specification (9 Nov 2026)

**Approved by WURK (9 Nov 2026)**, including the recommended additions and archiving now with a permanent purge in Sprint 18.

A demo is a **real WURK business marked as a demo** (so the site, templates and editor are the real ones), with restrictions.

**14.8a — making, showing, restricting and expiring demos**
- **Make a demo** (agent area → Demo site): business name and an **industry template** from the 24 *(recommended addition — the agent pitches a site that already looks like the prospect's trade)*; published at once on its own address. **One active demo per agent**; they can delete it and make another.
- **On the demo site**: a **"Demo" banner** (made by the agent, for the business) and **not indexed by search engines** (`noindex` header and meta). **Payments and outgoing messages are switched off** — online checkout, booking deposits, SMS, WhatsApp and emails to customers are refused with a plain reason.
- **The agent can edit it** in the normal back office (they're its owner while it's a demo) *(recommended addition)*, and **share a QR code** to open it on a prospect's phone *(recommended addition)*.
- **Expiry**: 14 days; the agent is emailed on **day 10 and day 13**; on day 14 it's **archived** — site offline, address freed, hidden everywhere. *Deviation from "auto-deleted": WURK can't yet delete a business's data safely; archived demos' data is **purged permanently in Sprint 18** (hardening) after proper testing. Demo content is the agent's sample material, not a customer's data.*
- **Admin → Businesses** marks demos and can filter them.

**14.8b — "Make it yours"**
- The demo's banner and the agent area offer a **claim link** for the prospect (signed, valid until the demo expires). The prospect signs up with **the demo's name and address already filled in**, chooses a package and pays; the demo **becomes their live business** — content kept, restrictions lifted, the prospect its owner (the agent's access ends) and **credited to the agent** (14.2).

## Out of scope
Agent-to-agent transfers; commission on WURK Credits or managed fees; automated bank payouts (needs a bank API — later).

## Verification
Each part: route tests, isolation tests (agents see only their own and their downline's data), MySQL, browser tests with accessibility scans, full CI before merge. Release as **v0.16.0**.

## Progress

| Part | PR | Notes |
|---|---|---|
| 14.1 Agents and hierarchy | #139 | 1,459 backend tests on SQLite; Agents and Access on MySQL 8. Browser tests in CI only. |
| 14.2 Attribution | #140 | 1,463 backend tests on SQLite; Agents and Onboarding on MySQL 8. Browser tests in CI only. |
| 14.3 Commission | see PR | 1,467 backend tests on SQLite; Agents and Billing on MySQL 8. Browser tests in CI only. |
| 14.4 Refund window and clawback | #142 | Caught: widening the ledger's once-only rule to allow several reversals let NULL references slip past MySQL's unique check — earned lines now carry a reference. The refund-days field is optional on save (unchanged when not sent). 1,471 backend tests on SQLite; Agents and Billing on MySQL 8. Browser tests in CI only. |
| 14.7 Banking details | #143 | Built before 14.5 (payouts need banking details); starts the agent area. 1,473 backend tests on SQLite; Agents on MySQL 8. Browser tests in CI only. |
| 14.5a Payout batches | #144 | Recommended additions included (preview with reasons, logged downloads). Caught: saving the minimum before any percentages existed failed. MySQL caught `payout_returned` (15) overflowing the ledger's 12-character `kind` (SQLite doesn't enforce lengths) — widened to 20; the referral ledger's kinds all fit. 1,476 backend tests on SQLite; Agents on MySQL 8. Browser tests in CI only. |
| 14.5b Settling batches | #145 | Agents' emails follow Billing's pattern (WURK's own mail, not business-editable templates); both rendered in tests. Browser coverage gap: Mark paid / Mark bounced appear only once a batch exists, and the e2e data has no commission — the page itself is scanned (14.5a); the actions are covered by backend tests. 1,479 backend tests on SQLite; Agents on MySQL 8. CI: MySQL part 4 timed out on the first full run (tests 9m30s; it had swung 6.5–10 min, timing out on 14.7's first run too) — Tenancy, its heaviest module, moved to part 2 in this PR. |
| 14.6a Dashboard and My businesses | #146 | Caught: my status mapping missed Plans' `pending_payment` (signed up, first payment due) and detected trials wrongly — now read from Plans' actual statuses. To watch: "next payout" runs finance's whole preview to find one agent — fine now; look at one agent directly before agents number in the thousands (Sprint 18). 1,481 backend tests on SQLite; Agents on MySQL 8. Browser tests in CI only. |
| 14.6b Earnings, team, payouts, kit | #147 | Also moved Agents' tests to MySQL part 4. 1,484 backend tests on SQLite; Agents on MySQL 8. Browser tests in CI only. |
| 14.8a Demo sites | see PR | Caught: first version logged the archive from Tenancy (a module cycle — Audit depends on Tenancy) — the agents module logs it. CI: the first full run was cancelled — part 1 reached 10 min (tests 9m10s) with no new tests in it, and parts 2 and 4 slowed ~3 min too (a slow runner; the demo tests take 17 s on MySQL). The third such timeout, so the MySQL tests now run in **five** parts. 1,488 backend tests on SQLite; demo tests and Tenancy on MySQL 8. Browser tests in CI only. |
