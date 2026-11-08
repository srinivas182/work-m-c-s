# Sprint 14 — Agents (specification, draft for approval)

**Status:** draft for WURK's approval (7 Nov 2026). Nothing is built until approved.
**Plan:** Sprint 14 — sponsor hierarchy; package commissions and clawback; EFT payouts; agent dashboard; demo sites (docs/sprint-plan.md).
**PRD:** §4.6 (super admin), §5.2 (attribution at signup), §10 (agent area), acceptance criterion 9.

Agents sell WURK packages to businesses and earn commission on what those businesses pay WURK. Unlike Sprint 13 (a business's customers), this is **WURK's own** programme: agents, commissions and payouts live in WURK's central data, managed by WURK staff.

## Parts

| Part | What |
|---|---|
| **14.1 Agents and the sponsor hierarchy** | Super admin creates agents (a WURK ID with an *agent* membership — the kind already exists) and places each under a sponsor; moves agents with an audit trail; refuses loops. Per agent: commission duration (lifetime, or N months from each business's start), active/suspended. |
| **14.2 Attribution** | Each agent has a referral link (`wurk.co.za/?agent=CODE` or `/a/CODE`) and QR. A business that signs up through it is attributed to that agent on the business record (PRD §5.2), first link wins, and the attribution is shown in the super admin's business view. Staff can correct an attribution (logged). |
| **14.3 Commission ledger** | On each WURK **subscription** payment (not setup, managed fees or credits — PRD §4.6), **excluding VAT**: the attributed agent earns level 1, their sponsor level 2, and so on up the hierarchy, using the global percentages (default: self + 7 levels; number of levels configurable). Only while the business is within that agent's commission duration. Append-only ledger in cents; once per payment. |
| **14.4 Clawback** | A refund or credit note on a subscription payment reverses the commission in proportion; a chargeback reverses it fully; cancellation stops future commission. If the commission was already paid out, the agent's balance goes negative and is recovered from future commission (decision below). |
| **14.5 Payouts** | Commission becomes payable after a hold period (decision). WURK finance generates an **EFT batch file** of agents above a minimum, pays via the bank, then uploads or marks the batch paid → agents' balances update and they're notified. Each batch has a reference; batches are immutable once paid. |
| **14.6 Agent area** | Dashboard: businesses onboarded (total, this month), active vs churned, earnings, available, pending, paid out; team (downline) view. Menus: Customers (businesses, onboarding date, package, last paid, status), Earnings (per business, period, level), Payouts (batches, dates, references), Referral link and marketing kit, Settings (password, MFA, banking details). |
| **14.7 Banking details** | Changes need a one-time code to the agent's phone and are logged; account numbers encrypted at rest (as for 13.7) and shown masked; a change made within 7 days of a batch holds that agent's payout for review (fraud control). |
| **14.8 Demo sites** | One active demo per agent: a watermarked, non-indexed business site with no payments or messaging, auto-deleted after 14 days (reminders day 10 and 13); the agent can delete and recreate; it converts to a live business on payment with its content kept. |

## Decisions needed

| # | Decision | Recommendation |
|---|---|---|
| A | **Commission percentages** per level (the PRD fixes the structure, not the numbers). | WURK to supply; the super admin can change them later. |
| B | **Hold period** before commission is payable. | **30 days** after the payment (covers most refunds and card chargeback windows' first month). |
| C | **Clawback after payout**: negative balance recovered from future commission, or the agent repays. | **Recover from future commission**; flag agents negative for 90 days for WURK to follow up. |
| D | **EFT batch file format.** | **Standard CSV** (name, bank, branch code, account, amount, reference) that any bank's bulk-payment import can map — or a specific bank's format if WURK names its bank. |
| E | **Where agents sign in.** | **`app.wurk.co.za/agent`** — the business back-office surface (MFA, sessions) with its own menu; no new domain. |
| F | **Tax statements.** | An **annual earnings statement** (PDF) per agent for their tax return; WURK confirms whether it also needs IRP5/IT3(a) handling (an accountant's call). |

## Out of scope
Agent-to-agent transfers; commission on WURK Credits or managed fees; automated bank payouts (needs a bank API — later).

## Verification
Each part: route tests, isolation tests (agents see only their own and their downline's data), MySQL, browser tests with accessibility scans, full CI before merge. Release as **v0.16.0**.
