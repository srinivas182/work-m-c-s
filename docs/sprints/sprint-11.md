# Sprint 11 — Multi-branch and shipping

| | |
|---|---|
| **Status** | Draft for WURK's approval (30 Oct 2026) |
| **Plan (revision 3)** | 29 Mar – 09 Apr 2027 · branch managers; per-branch merchant accounts, prices, stock; inter-branch settlement; courier integrations |
| **Builds on** | Sprint 1's branches table, branch-manager role and branch scope (unused until now); packages' `multi_branch` / `max_branches` (not yet enforced) |

## 1. Objectives

A business with several locations runs them from one WURK account: each branch has its own team, hours, stock, prices (where they differ) and — if it wants — its own payment accounts. Customers choose a branch when they book or collect. Orders can be delivered by courier with live rates, labels and tracking. WURK is never in the money flow.

## 2. Deliverables

| Part | What |
|---|---|
| **11.1 Branches** | Add, edit and close branches (name, address, phone, hours), within the package's limit; a branch switcher for owners; branch managers and staff assigned to branches (they see only their branches — the Sprint 1 scope, now used). |
| **11.2 Bookings by branch** | Services and team members per branch; availability per branch; the booking widget asks which branch (skipped when there's one). |
| **11.3 Shop by branch** | Stock per branch (the business total is the sum); collection from a chosen branch; orders fulfilled by a branch; branch managers see their branch's orders. Optional price per branch (otherwise the business price). |
| **11.4 Payments by branch** | A branch may use its own PayFast / Yoco / Ozow / Peach / EFT account; otherwise the business's. Same rules as 10.6 (test/live, keys per mode, WURK's availability). |
| **11.5 Settlement between branches** | When one branch's account is paid for something another branch supplies, the amount owed is recorded — a ledger per branch and a monthly statement, marked settled by hand. Records only: no money moves through WURK. |
| **11.6 Shipping** | Delivery by courier: rates at checkout, shipments and labels from the order, tracking for the customer — through the business's own courier account. Plus the existing collection and flat-fee local delivery. |

## 3. Decisions needed from WURK

| # | Question | Recommendation |
|---|---|---|
| A | **Which couriers?** | **Bob Go** (one integration covering The Courier Guy, Aramex, Fastway and others, with rates, labels and tracking) **plus Pargo** (pickup points). One aggregator keeps maintenance low; more can be added in the same way. |
| B | **Whose courier account?** | **The business's own** (like payment accounts): WURK never pays couriers or holds money. |
| C | **Prices per branch?** | **Optional override** per product and per service; otherwise the business price. |
| D | **Settlement between branches?** | **Records only** (ledger + monthly statement, settled by hand), never moving money. |
| E | **Branch limit** | Enforce the package's `max_branches`; Starter = 1. Confirm the Growth and Pro numbers in the admin. |

## 4. Risks

| Risk | Mitigation |
|---|---|
| Making existing data branch-aware without breaking single-location businesses | Every business gets a "main" branch; existing services, stock and orders move to it; one branch = no visible change. |
| Branch scope leaking data between branches | The Sprint 1 scope applies to every branch-scoped model; tests prove a branch manager can't see another branch (lists, pages, exports, API). |
| Courier APIs unknown in detail until sandbox accounts exist | Provider addresses in config (as with Ozow and Peach); simulated providers in tests; one real shipment per courier before go-live. |
| Scope larger than the plan's two weeks | Delivered in six parts, each releasable; development is ahead of the calendar. |

## 5. Approach and alternatives

- **Recommended:** branches as a first-class part of Tenancy and Access (already there), with each module adding `branch_id` and the scope to what's branch-specific; one courier aggregator.
- **Alternative:** a separate WURK business per location — simpler, but owners lose one login, shared customers, combined reports and stock moves. Not recommended.
- **Alternative:** integrate each courier directly — more control, much more to maintain. Not recommended for now.

## 6. Expected outcome

An owner with three salons or two shops adds them in minutes, gives each manager access to only their branch, and customers book or collect at the branch they choose; shop orders can go by courier with tracking. Businesses with one location see no change.

## 7. Needed from WURK

Decisions A–E; Bob Go and Pargo sandbox accounts (with the payment providers' sandboxes) for one real test each before go-live.
