# Sprint 8 — Appointments: detailed specification

| | |
|---|---|
| **Sprint** | 8 (sprint plan revision 3) |
| **Status** | ✅ Released as v0.9.0 (#68) |
| **Depends on** | v0.8.0 (public sites, islands, quote forms); WURK Credits and messaging (Sprints 2–3, 6) |
| **Delivery** | 5 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objective

**A customer books, pays a deposit if the business wants one, and gets reminded — without a phone call.** A salon owner sets their services, team hours and rules once; customers book from the business's website any time; nobody is double-booked; no-shows cost the customer, not the business.

## 2. The decision that comes first — how deposits are paid

| Option | Verdict |
|---|---|
| **Paid straight to the business (recommended)** | Each business connects its own Paystack account (a *subaccount* under WURK's). Deposits go **directly to the business**; WURK never holds customers' money. Paystack handles the business's verification (KYC) and payouts |
| WURK collects, then pays businesses out | WURK would hold other people's money: payment-intermediary obligations in South Africa, reconciliation, payout risk and support — **not recommended** |
| No deposits (book only) | Simplest, but no protection against no-shows — the main reason salons want online booking |

Deposits are **optional per service**. Businesses without a connected payment account can still take bookings, just without deposits.

## 3. Sub-sprints

### 8.1 Services, team hours and availability

| Item | Details |
|---|---|
| Bookable services | Name, duration, buffer before/after, price, deposit (none / fixed / %), which team members do it |
| Team hours | Weekly hours per team member; breaks; time off and public holidays (South African list, editable) |
| Availability | Free slots from service length + buffers + hours + existing bookings, in the business's time zone |
| **No double-booking** | Guaranteed in the database (locked check-and-insert); tested under concurrent bookings |
| Branches | Designed in now (bookings belong to a branch); multi-branch management arrives in Sprint 11 |

### 8.2 Booking widget and the owner's calendar

| Item | Details |
|---|---|
| "Book online" section | A new website section: choose service → team member (or "anyone") → time → details. An island, small enough to keep the **performance gate** passing; without JavaScript it falls back to the quote form or WhatsApp |
| Owner calendar | Day and week views, per team member; add, move and cancel bookings; walk-ins |
| Customers | Name, phone, email, consent — the start of the CRM (Sprint 12) |
| Accessibility | The widget and calendar keyboard-operable and scanned on desktop and phone |

### 8.3 Deposits and policies

| Item | Details |
|---|---|
| Payment accounts | Owners connect their business's Paystack account (subaccount) from Settings |
| Deposits | Paid at booking; the booking holds the slot only once paid (short hold while paying) |
| Rules | Cancellation window (e.g. 24 h); late cancellation and no-show keep the deposit; refunds inside the window |
| Self-service | Customers reschedule or cancel from a link in their confirmation (signed, no account needed) |

### 8.4 Confirmations and reminders

| Item | Details |
|---|---|
| Messages | Confirmation, reminder (e.g. 24 h and 2 h before), change and cancellation |
| Channels | **Email: free.** SMS and WhatsApp: WURK Credits, at rate-card prices, chosen by the owner |
| **Essential messages never silently stop** | If credits run out, confirmations and reminders fall back to **free email** (moved here from 6.3), and the owner is told |

### 8.5 Restaurant table reservations

| Item | Details |
|---|---|
| Model | Party size, time slots, sitting length, covers per slot (no table plan yet) |
| Booking | The same widget in "table" mode; confirmations and reminders as above |
| Owner view | Tonight's reservations by time; seat, no-show, cancel |

## 4. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 8.1 | Availability correct across hours, breaks, time off and buffers (tested); **no double-booking under concurrent requests** (tested) |
| 8.2 | A customer books from a published site on a phone; the owner sees it in the calendar; accessibility scans pass; **performance gate still passes** |
| 8.3 | A deposit is paid to the business's account (Paystack test mode); policies applied correctly to cancellations and no-shows (tested) |
| 8.4 | Messages sent on the right channel at the right time; credits charged once; email fallback when credits run out (tested) |
| 8.5 | Reservations respect covers per slot; same message rules |

## 5. Decisions to confirm

1. **Deposits paid straight to each business** through Paystack subaccounts — WURK never holds customers' money.
2. **Deposits optional per service**; bookings work without a payment account.
3. **Email reminders free; SMS/WhatsApp on credits**, with automatic email fallback for essential messages.
4. **Tables by covers per slot** first (no floor plan) — a table plan can come later if restaurants ask.
5. **Calendar sync** (Google, Outlook) **later**, not in this sprint.

## 5a. Decided with WURK (27 Oct 2026)

1. **Paystack in test mode** for now: keys entered in the admin's provider settings; only the keys change at go-live.
2. **SMS and WhatsApp are provided by WURK** (configured by super admin), paid for with WURK Credits at rate-card prices, with free email fallback. Businesses don't bring their own providers; WhatsApp sends from WURK's Meta-verified number, naming the business. A **"bring your own sender"** add-on (own WhatsApp number or SMS sender name) can come later without changing the design.
3. **Message wording is fixed** (not editable by owners) — required for WhatsApp templates and protects WURK's sender. Confirmation, reminder, change and cancellation messages fill in the business name, client name, service, date and time, and the manage-booking link. Owners choose channels, not words.

## 6. Needed from WURK

| Item | Needed by |
|---|---|
| **Paystack account with subaccounts (split payments) enabled**, and test keys | 8.3 |
| SMS and WhatsApp provider accounts (or confirm the providers already set up in Messaging) | 8.4 |
| Your standard cancellation policy wording (the default owners start from) | 8.3 |

## 7. Progress log

| Sub-sprint | PR | Notes |
|---|---|---|
| 8.1 Services, team hours and availability | #63 | 739 backend tests on SQLite and MySQL 8 (incl. a double-booking race on both); 363 frontend; 60 browser checks. Found: UTC stored against the platform's local-time convention (two-hour shift) |
| 8.2 Online booking and the calendar | #64 | 746 backend tests on SQLite and MySQL 8; 370 frontend; browser: settings, calendar, a customer booking on a published site (desktop and phone), 48 contact-page scans with the live widget. Found: Bookings reached into Access internals (architecture test) |
| 8.3 Deposits and policies | #65 | Paystack test mode. 760 backend tests on SQLite and MySQL 8; 370 frontend; all browser tests. Found: unregistered return-page view; signed links invalidated by actions and the chosen date (separate signed actions; the page ignores only `date`) |
| 8.4 Confirmations and reminders | see PR | 767 backend tests on SQLite and MySQL 8; 370 frontend; all browser tests. WhatsApp templates to be registered with Meta by WURK (names and parameters in CHANGELOG) |
| 8.5 Restaurant table reservations | #67 | 773 backend tests on SQLite and MySQL 8; 370 frontend; all browser tests incl. a guest reserving on the published restaurant site. Found: the branch was cut from a stale local develop (rebased onto the real one before merging) |
