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
| 14.4 Refund window and clawback | see PR | Caught: widening the ledger's once-only rule to allow several reversals let NULL references slip past MySQL's unique check — earned lines now carry a reference. The refund-days field is optional on save (unchanged when not sent). 1,471 backend tests on SQLite; Agents and Billing on MySQL 8. Browser tests in CI only. |
