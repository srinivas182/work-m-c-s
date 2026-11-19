# Sprint 26 — Run it from your phone

| | |
|---|---|
| **Status** | Approved by WURK (18 Nov 2026: "yes, agreed to all"), with the additions to Sprints 23 and 25 and decisions A–F as recommended. Built 19 Nov 2026 as v0.27.0 |
| **Release** | v0.27.0 |
| **Depends on** | v0.26.0 (Sprint 25). Builds on: WhatsApp both ways and the owner's evening summary (Sprint 21), payments and cash recording (Sprint 22), languages and lite mode (Sprint 24), Bookings, Shop, Team, Customer accounts (Portal), Campaigns |
| **Next** | Sprint 17 — Cloud & going live (still last) |

## Why

Sprints 19–25 make WURK work for African owners and customers new to technology. In use, three gaps remain:

1. **Owners rarely open a back office.** They live in WhatsApp. Sprint 21 gives them an evening summary that can be answered by number. They can't yet *tell* WURK to do something: "add Thandi for braids on Saturday at 10", "mark order 1042 paid cash", "how much did we make this week?"
2. **Teams work from their phones.** A stylist, driver or cashier needs today's bookings, a new chat or order to buzz their phone, and the till, without logging in to a website every time.
3. **Customers come back when they're rewarded.** Small businesses run loyalty on paper stamp cards that get lost. Nothing in WURK rewards a regular, and nothing helps an owner pay staff commission fairly.

## What the market does (Nov 2026)

| Product | Strengths | Gap for WURK's businesses |
|---|---|---|
| **Booksy** | Digital loyalty cards stamped automatically after each qualifying visit; the owner sets the reward, the stamps needed and the minimum spend; included in the subscription | Customers need the Booksy app; salons only; dollar or euro pricing |
| **Square** (Team app, Shifts) | Staff schedules, time clock, tips and commission, a team app | Not available in most of Africa; card-machine first |
| **Fresha, Vagaro** | Team calendar app, push notifications, commission reports | Salon and spa only; no WhatsApp running the business; pricing outside Africa |
| **WhatsApp Business app** | Where owners already work; Status is used as a shopfront | No bookings, orders, money or staff; no way to "ask" it anything |
| **Review tools** (Birdeye-style, SchedulingKit) | Automatic review requests after appointments raise Google reviews | Separate subscriptions; English and SMS or email first |

Research in 2026 on African women running businesses on WhatsApp found:
- Status is used as a shopfront, for prices, stock, finished work and specials.
- Discovery comes through people they know, not search.
- About 30% of participants in Kenya and Nigeria share a phone.

**WURK's edge:** the assistant that already knows the business's diary, stock and money takes the owner's instructions on WhatsApp too. The team gets an app that installs from the browser and works on cheap phones. Loyalty and reviews run from the same customer records, through WhatsApp, with no app for the customer.

Sources:
- [Booksy digital loyalty cards](https://biz.booksy.com/en-za/features/digital-loyalty-cards)
- [Square shift scheduling](https://square.com/us/en/press/shift-scheduling-for-team-management)
- [WhatsApp as a shopfront for African women (2026)](https://techtrendske.co.ke/2026/05/16/whatsapp-african-women-business-livelihoods/)
- [WhatsApp Status for marketing](https://techpoint.africa/general/whatsapp-status-for-marketing/)
- [Automating review requests after appointments](https://schedulingkit.com/blog/how-to-automate-review-requests-after-appointments)

## What's already covered elsewhere (not repeated here)

- **Setup by voice note or WhatsApp; who owes me, cash-up, profit, weekly "how did I do"**: Sprint 23.
- **Languages and a lite mode** that works on a weak signal and catches up later: Sprint 24.
- **USSD and SMS booking, verified badge, reviews on WhatsApp, help line and videos**: Sprint 25.
- **Win-back and other ready-made campaigns**: Campaigns (Sprint 15).
- **Customers sign in with a one-time code**: Customer accounts (Portal).

## Scope

### 26.1 Tell WURK what to do, on WhatsApp
- The owner, or a team member with the right permission, sends WURK's number a message or a voice note in plain words. For example:
  - "Book Thandi for braids Saturday 10am"
  - "Move my 3 o'clock to 4"
  - "Mark order 1042 paid cash"
  - "Add 20 Kasi T-shirts, size M"
  - "Send everyone a 10% Friday special"
  - "How much did we make this week?"
- **WURK always reads back what it will do**, and does it only after a **Yes** button. Questions are answered straight away.
- **It acts as that person,** with that person's permissions, branches and spending limits. Everything is audited, and the owner can switch it off or limit it per person.
- **It is built on the receptionist's tools** (Sprint 19), extended with owner actions: bookings, orders and stock, cash recorded, a campaign draft (sent only after approval), and figures.
- **It costs AI credits,** like the receptionist. A daily cap is set in Admin.

### 26.2 The WURK app for owners and teams
- WURK installs from the browser as an app on Android and iPhone, with no app store needed. It has an icon, a full screen, and opens on **Today**.
- **Push notifications,** each switched on or off per person:
  - a new booking or change
  - a new chat waiting
  - a new order
  - a payment received
  - credits running low
- **The team's Today:**
  - my next bookings, with Start, Done and No-show
  - chats assigned to me
  - orders to pack
  - Paid in person (Sprint 22.3)
- It works with Sprint 24's lite mode: it opens with a weak signal and queues actions until the phone is back online.

### 26.3 "Needs you now" on Home
- One list across the modules, newest first:
  - bookings to confirm
  - deposits not paid
  - chats waiting longer than 15 minutes
  - orders to pack or collect
  - who owes me (Sprint 23)
  - demo photos still to replace
  - credits running out (Sprint 22.5)
- Each item has its action right there. The list is empty when nothing needs doing: "All done for now."

### 26.4 Team: shifts, clock-in and commission
- **Shifts:**
  - a weekly roster built from each person's booking hours, so nothing is typed twice
  - swaps and leave, approved by the owner
  - each person's shifts on their phone
- **Clock in and out** from the app, optionally only on the business's Wi-Fi or near its address (decision C). It gives hours worked per week and per person.
- **Commission and tips:**
  - commission per person as a % of services or products they did or sold
  - tips recorded with a payment
  - a monthly **"what each person earned"** report to export
- This is not payroll: no tax or UIF. It gives the numbers to the owner's bookkeeper.

### 26.5 Loyalty cards, on WhatsApp
- **The owner sets it up:**
  - the reward (e.g. "your 8th haircut free" or "R50 off")
  - the number of stamps needed
  - the minimum spend that earns a stamp
  - what counts: bookings, orders, in-store sales
- **Stamps are added automatically** after a paid visit or order, including cash recorded in person.
- **The customer sees** "6 of 8 — 2 more to your free cut" in the receipt message on WhatsApp, on their account page, and in the reply to "my card". No app to install.
- **The reward** is offered at the next booking or checkout and used once. It counts in reports like a discount.
- **It works alongside referral rewards** (Sprint 13) and campaigns' coupons. Only one discount applies per purchase, the customer's best.

### 26.6 Release
- End-to-end tests:
  - an owner books, moves and marks paid on WhatsApp, with confirmation
  - the app installs and receives a push
  - Home's "needs you now" list
  - a shift, a clock-in and the commission report
  - a loyalty card filling up and its reward used
- Release v0.27.0, with upgrade steps:
  - the push notification keys (VAPID), generated by WURK
  - a daily cap for owner AI on WhatsApp

## Additions proposed to earlier sprints

| Sprint | Add | Why there |
|---|---|---|
| **23 — Easy setup** | **Set up from what the business already has online:**<br>- The owner pastes a Facebook page, Google Business listing, Instagram or old website link.<br>- WURK AI drafts the site from it: services, prices, hours, photos the owner chooses, and contact details.<br>- The owner checks everything before it goes live. | The same setup assistant as the voice-note setup; one more starting point |
| **23 — Easy setup** | **Share kit:**<br>- One tap shares a booking link, a special or a product to WhatsApp Status, Facebook and Instagram, with an image made for Status (9:16).<br>- Printable A4 and A5 posters and table cards with the business's QR code, in its brand. | Sharing to Status is how these businesses find customers; it belongs with getting started |
| **25 — Reach & trust** | **Automatic review requests:**<br>- After a completed visit or delivered order, one WhatsApp message asks "How was it?" with 1–5 taps.<br>- Everyone who answers gets the same link to the business's Google review page and the same offer to tell the owner privately. *(Corrected in the Sprint 25 spec: sending only happy customers to Google is review gating, which Google forbids.)*<br>- At most once per customer per 90 days, and STOP is respected. | Extends the planned "reviews on WhatsApp" to Google, where new customers look |

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Where Sprint 26 goes | **After Sprint 25, before Sprint 17 (cloud last)**. It needs Sprint 24's lite mode and Sprint 25's country packs |
| B | Owner actions on WhatsApp without confirmation | **Never.** Every change is confirmed with a Yes button; only questions are answered straight away |
| C | Clock-in check | **Optional per business:** Wi-Fi or within 200 m of the address; off by default, because shared phones and patchy GPS are common |
| D | Loyalty in packages | **Included in every package,** as Booksy does. It brings customers back, which keeps businesses on WURK |
| E | Payroll | **Not built.** Hours, commission and tips are exported for the bookkeeper; South African payroll rules (PAYE, UIF) are left to payroll software |
| F | Native apps (store) | **Not now.** The browser-installed app covers notifications and offline use; a store app can wrap it later if needed |

## Acceptance

1. An owner sends "book Thandi for braids Saturday 10am" as text and as a voice note. WURK reads back the booking, books it after **Yes**, and it appears in the calendar. A team member without permission to book is told politely that they can't.
2. A stylist installs the app, receives a push for a new booking within seconds, and marks it done from Today.
3. Home lists what needs the owner now; each item can be done from there, and the list empties.
4. A roster is built from booking hours; a stylist clocks in and out; the month's commission and tips per person export to a spreadsheet that matches the takings.
5. A customer's 8th paid haircut, including two paid in cash, earns the free cut. They see their progress in WhatsApp, and the reward is used once.
6. Nothing changes a business's data without a confirmation, and every owner action on WhatsApp is audited.

## Agreed (18 Nov 2026)

- Sprint 26 runs after Sprint 25 and before Sprint 17, and the three additions go into Sprints 23 and 25.
- Decisions A–F are taken as recommended.
- **Owner AI on WhatsApp is charged at the AI receptionist's rate**, with a default cap of **50 owner requests per business per day**. The cap can be changed in Admin → WURK AI.

## Parts

26.1 → 26.2 → 26.3 → 26.4 → 26.5 → 26.6, each with one CI run.

## Progress

| Part | PR | Notes |
|---|---|---|
| 26.1 Tell WURK what to do, on WhatsApp | #272 | Text and voice notes; read back with Yes/No buttons; acts as the person; receptionist rate; daily cap (50) in Admin → WURK AI |
| 26.2 The WURK app | #273 | New Push module (Web Push with WURK's own VAPID keys); Today; installable app opening on Today; offline queue |
| 26.3 Needs you now | #274 | One list across WURK with actions; "All done for now." |
| 26.4 Shifts, clock-in, commission | #275 | New Shifts module; roster from booking hours; Wi-Fi or 200 m check optional; earnings export |
| 26.5 Loyalty cards | #276 | New Loyalty module; stamps once per visit, cash included; reward used once, best of coupon or reward |
| 26.6 Release | #277 | e2e/sprint-26.spec.ts; CHANGELOG, VERSION 0.27.0, upgrade steps |

## Verification (before closing)

Every spec item and acceptance criterion, checked against the code and its tests. Verified 19 Nov 2026.

| Item | Where | Status |
|---|---|---|
| 26.1 Plain words or a voice note; examples (book, move, mark paid, add stock, a special, figures) | Assistant::instruct, HelpLine; BookCustomer, MoveBookingForOwner, MarkOrderPaid, AddStock, DraftCampaign tools; OwnerInstructionsTest | ✅ |
| 26.1 Always read back; only after Yes; questions straight away | `act:yes|no` buttons, cached pending action; OwnerInstructionsTest | ✅ |
| 26.1 Acts as that person with their permissions; audited; switch off or limit per person | Assistant::asPerson, permission `assistant.whatsapp`, audit | ✅ Limited per person through their role |
| 26.1 AI credits at the receptionist's rate; daily cap in Admin | Assistant::instructed, Admin → WURK AI | ✅ |
| 26.2 Installs from the browser, icon, full screen, opens on Today | manifest (`/businesses?to=today`), icons, apple meta; e2e | ✅ |
| 26.2 Push per person: bookings, chats, orders, payments, credits | Push Notifier and hooks; PushNotificationsTest | ✅ |
| 26.2 Today: my bookings with Start/Done/No-show, Paid in person; orders to pack | MyBookings, Shop section; TodayTest | ✅ |
| 26.2 Today: chats assigned to me | Website section | ⚠️ Shows chats waiting for a person; WURK has no chat assignment yet |
| 26.2 Works with lite mode: opens on a weak signal, queues actions | sw.js cache, offline.ts queueable patterns | ✅ |
| 26.3 Bookings to confirm, deposits not paid, chats over 15 min, orders, who owes me, demo photos, credits | NeedsYouNow sources; NeedsYouNowTest | ✅ Bookings to confirm and deposits not paid are the same bookings (owed money) |
| 26.3 Action on each item; "All done for now." | Workspace NeedsYouNow; NeedsYouNowTest, e2e | ✅ |
| 26.4 Roster from booking hours; swaps and leave approved by the owner; own shifts on the phone | Roster, MyShiftsController; ShiftsTest, e2e | ✅ |
| 26.4 Clock in and out, optional Wi-Fi or 200 m; hours per person | Clock; ShiftsTest | ✅ / ⚠️ the Today clock button doesn't send location (My shifts does) |
| 26.4 Commission % of services or products; tips; monthly report to export; not payroll | Earnings; ShiftsTest, e2e | ✅ Tips are recorded by the owner on Earnings, not inside the payment flow |
| 26.5 Owner sets reward, stamps, least spend, what counts; in every package | Loyalty settings; LoyaltyTest, e2e | ✅ |
| 26.5 Stamps after a paid visit or order, cash included | PurchaseMade listener; LoyaltyTest | ✅ |
| 26.5 "6 of 8 — 2 more…" on WhatsApp, account page, and "my card" | `wurk_loyalty_progress`, Portal Home, WhatsAppChats; LoyaltyTest | ✅ Sent as its own message after the stamp, not inside the receipt |
| 26.5 Reward offered at the next booking or checkout, used once, counts like a discount | Today "Use reward", OrderPlacer; LoyaltyTest | ✅ Online booking deposits don't take the reward; it's used at the counter |
| 26.5 Alongside referrals and coupons; one discount, the customer's best | OrderPlacer (better of coupon or reward); LoyaltyTest | ✅ |
| 26.6 e2e: owner on WhatsApp books, moves, marks paid | OwnerInstructionsTest (webhook-level) | ⚠️ Covered by feature tests; a browser can't drive WhatsApp |
| 26.6 e2e: app installs and receives a push | e2e (manifest, service worker), PushNotificationsTest (encrypted send) | ✅ / ⚠️ a real push service isn't reachable from CI |
| 26.6 e2e: Needs you now; a shift and the commission report; loyalty | e2e/sprint-26.spec.ts; ShiftsTest, LoyaltyTest | ✅ |
| 26.6 v0.27.0 with upgrade steps (VAPID keys made by WURK, daily cap) | CHANGELOG, docker.md | ✅ |
| Acceptance 1 (text and voice note, read back, Yes, in the calendar; no permission → polite no) | OwnerInstructionsTest | ✅ |
| Acceptance 2 (stylist installs, gets a push in seconds, marks done from Today) | PushNotificationsTest, TodayTest, e2e | ✅ Simulated push delivery |
| Acceptance 3 (Home lists, each done from there, list empties) | NeedsYouNowTest, e2e | ✅ |
| Acceptance 4 (roster, clock in/out, export matching the takings) | ShiftsTest | ✅ |
| Acceptance 5 (8th paid haircut incl. cash earns the reward; progress on WhatsApp; used once) | LoyaltyTest | ✅ |
| Acceptance 6 (no change without confirmation; every owner action audited) | OwnerInstructionsTest | ✅ |
