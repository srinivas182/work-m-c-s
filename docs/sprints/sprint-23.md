# Sprint 23 — Easy setup & money tools

| | |
|---|---|
| **Status** | Approved by WURK (18 Nov 2026: "All approved", with Sprints 19–25 in sequence). Two additions approved on 18 Nov 2026: setup from an existing online page, and the share kit (see [Sprint 26](sprint-26.md)) |
| **Release** | v0.24.0 |
| **Depends on** | v0.23.0. Builds on: the AI site writer (`SiteGenerator`), templates and categories (Sprint 20), WhatsApp both ways and voice notes (Sprint 21), payments, cash recording and pay as you go (Sprint 22), Agents and demo sites (Sprint 14), Reports and takings (Sprint 15) |
| **Next** | Sprint 24 — Languages & light on data |

## Why

Owners new to technology get stuck in two places.

1. **Getting started.** Choosing a template, typing services and prices, and writing text is hard on a phone. Many owners already have a Facebook page, a Google listing or a WhatsApp catalogue, and they don't want to type it all again. Agents need to sign a business up in one short visit.
2. **Knowing where the money is.** At the end of the day an owner wants to know three things:
   - Did the cash in the drawer match what was taken?
   - Who still owes me?
   - Am I making money?

   WURK records sales, deposits, orders and cash (Sprint 22), but doesn't yet answer those questions in plain words.

## What the market does (Nov 2026)

| Product | Strengths | Gap for WURK's businesses |
|---|---|---|
| **Wix** (AI website builder, app in ChatGPT and Gemini) | A site from a conversation | A generic site, not tied to bookings, payments or WhatsApp; dollar pricing |
| **Repaint, Durable, Brizy** | A site in minutes from a Google Business profile, Instagram or Facebook: photos, reviews, hours and services; Repaint from $25 a month | Website only; nothing for running the business afterwards |
| **Yoco** (200,000+ South African merchants) | Card machines, sales reports, industry software, Yoco AI insights (Q3 2026) | Built around its own card machine; little for cash-heavy or mobile money businesses; no website or booking set-up by voice |
| **Invoice-chasing apps** (PayNudge, Duefy, Rebill and others) | WhatsApp invoices and automatic payment reminders | Separate tools; they don't know the business's bookings, orders or cash |
| **Ozow** (WhatsApp payment requests) | Payment requests in WhatsApp; Ozow reports about a 5% lift in conversion | A payment rail, not a picture of who owes what |

**WURK's edge:**
- The owner sets up by talking: a voice note or a chat, or by pasting a link to what they already have.
- WURK builds the website, services, prices, hours and booking from what they said. Nothing is invented.
- The same records then answer "who owes me", "does my cash add up" and "how did I do this week", on WhatsApp, in rand.

Sources:
- [Wix app in ChatGPT](https://www.wix.com/press-room/home/post/wix-launches-app-in-chatgpt-expanding-website-creation-to-one-of-the-world-s-leading-conversational)
- [Repaint: Google Business to website](https://repaint.com/features/google-business-to-website)
- [Brizy and Google Business Profile](https://brizy.io/brizy-connects-with-google-my-business-for-effortless-website-creation)
- [Yoco's 2026 plans](https://techcabal.com/2026/06/18/yoco-south-africa-small-businesses/)
- [Ozow WhatsApp payment requests](https://ozow.com/blog/fixing-invoice-problem-payment-requests-whatsapp-payment-completion)
- [Automated payment reminders for SMEs (2026)](https://kolonell.com/en/blog/automated-payment-reminders-for-smes-cost-2026)

## Scope

### 23.1 Set up by talking (voice note or WhatsApp)
- *Delivery note:* 23.1 brings the conversation to the back office (typed or as voice notes recorded in the browser). The same questions on **WhatsApp** use the owner-instructions channel built in Sprint 26.1, so they arrive there, on the same engine (`SetupTalk`).
- **On WhatsApp or in the app, a short conversation.** The owner answers in text or voice notes, in any language WURK's speech-to-text understands. The setup assistant asks:
  - what the business is called and does
  - where it is, or the areas it serves
  - its services or products, with prices
  - opening hours
  - how customers pay
  - whether to take bookings or orders online
- **From the answers WURK:**
  - picks the category and one of its designs (the owner can switch);
  - fills services and prices, hours, contact details and the business's area **from what the owner said**;
  - writes the text with the AI site writer, which never invents testimonials, qualifications, prices or team members;
  - turns on bookings or the shop.
- **The owner sees a preview link** and publishes with one tap. Nothing goes live without that tap.
- **Photos** sent in the chat are used as the business's own (virus-checked, no metadata). Places without one keep the design's demo photo, marked **Demo** (Sprint 20).
- **Cost:** AI credits at the receptionist's rate. The first setup is free (a WURK grant).

### 23.2 Set up from what you already have online *(added 18 Nov 2026)*
- **The owner pastes a link** to their Google Business listing, Facebook page, Instagram profile or old website. Or the agent does, with the owner present.
- **WURK reads only public information:**
  - **Google listing:** through the Google Places API, with the key in Admin → Providers. It gives name, address, phone, hours, category and website.
  - **Facebook, Instagram and websites:** the page's public preview (name, description, image) and the website's text.
- **WURK drafts the site, services and hours from it.** The owner checks each item before anything is saved, and picks which photos to use. Only photos of their own business are offered.
- **Reviews:** WURK links to the Google listing's reviews rather than copying them. Copied reviews could be mistaken for reviews collected by the business.

### 23.3 Share kit *(added 18 Nov 2026)*
- **One tap to share** a booking link, a special, a product or the website:
  - to **WhatsApp Status** (an image made for Status, 9:16, in the business's brand, with the link);
  - to WhatsApp chats, Facebook and Instagram, using the phone's share sheet.
- **Printable posters and table cards** (A4, A5, table tent) with the business's QR code, logo and colours, in PDF. "Scan to book", "Scan to order" and "Scan to chat on WhatsApp" versions.
- **Each link is tagged by source** (Status, poster, Facebook), so Reports shows which brought customers.

### 23.4 Agents: a business set up in 15 minutes, on the agent's phone
- **One guided flow on the agent's phone:**
  1. The owner's number is verified with a one-time code on WhatsApp or SMS.
  2. Setup by talking (23.1) or from a link (23.2), together with the owner.
  3. Choose a package; pay-as-you-go packages need no card.
  4. The first top-up from R20 by Capitec Pay, SnapScan or mobile money (Sprint 22), or cash collected by the agent and recorded as Sprint 3's cash payments.
  5. Hand over: the owner gets a WhatsApp message with their sign-in link and the share kit.
- **Commission** follows the existing agent rules (Sprint 14). The agent sees the business as "set up, not yet live" until the owner publishes.
- **A demo site made earlier** (Sprint 14.8) can be claimed in the same flow.

### 23.5 Money tools: who owes me, cash-up, profit, weekly "how did I do"
- **Who owes me:**
  - One list of money customers owe: unpaid deposits, pay-later orders, sales recorded on account, and invoices.
  - **A simple invoice**, new: lines, VAT when the business is VAT-registered, due date, and a pay link (Sprint 22 methods). Sent on WhatsApp or by email.
  - **Reminders:** one tap, or automatic on day 1, 7 and 14 after the due date, on WhatsApp first. STOP is respected, and there's never more than one a week.
  - Paid by link: marked paid automatically. Paid in cash: recorded in person.
- **End-of-day cash-up:**
  - The till's expected cash and card-machine totals come from the day's in-person payments and recorded sales, per person and per branch.
  - The owner or cashier counts and enters what's there. Differences are shown and must be explained when above an amount the owner sets.
  - Each cash-up is locked and audited; the owner can reopen it.
- **Profit in plain words:**
  - **Expenses** can be added in seconds: amount, category and note, or a **photo of the slip**, which WURK AI reads and the owner confirms.
  - Reports and Home show "This month you took R…, spent R…, so you made about R…". Categories are kept simple (stock, rent, wages, transport, data and airtime, other).
- **Weekly "how did I do" on WhatsApp,** each Monday on WURK's number. It covers takings against last week, the best-selling service or product, new and returning customers, who owes, and one suggestion (e.g. "3 regulars haven't been back for 6 weeks — send them a special?"). It can be switched off.

### 23.6 Release
- End-to-end tests:
  - setup by chat (with recorded AI answers) and from a Google listing (recorded Places responses);
  - the share kit and a poster PDF;
  - an agent's 15-minute setup;
  - an invoice sent and paid by link;
  - a reminder;
  - a cash-up with a difference;
  - an expense from a slip;
  - the weekly summary.
- Release v0.24.0, with upgrade steps:
  - a Google Places API key;
  - the WhatsApp templates for the weekly summary and payment reminders.

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Reading Facebook and Instagram | **Public previews only**, plus photos the owner picks or sends. No scraping; Meta's own permissions can come later if needed |
| B | Google listings | **Google Places API**, with WURK's key in Admin → Providers; about $0.02–0.03 per lookup, one lookup per setup |
| C | Invoices | **Simple invoices only:** one-off, with lines and VAT and a pay link. No quotes-to-invoice workflow or statements (the Records module and Sprint 25's refunds cover the rest) |
| D | Reminders | **At most one a week per invoice, three in all,** WhatsApp first then SMS; the owner can switch them off per customer |
| E | Expense slips | **Read by WURK AI** for amount, date and category; the owner confirms; AI credits per slip; the photo is kept privately with the expense |
| F | Weekly summary | **On by default** for owners with a verified WhatsApp number; a new WURK template (`wurk_weekly_summary`) |
| G | Setup cost | **The first setup is free** (a one-off WURK credit grant); later re-runs use AI credits |

## Acceptance

1. An owner with no website sends voice notes in isiZulu on WhatsApp, answering the setup questions, and gets a preview with their real services, prices and hours. They publish with one tap, and bookings work.
2. An agent pastes a Google listing link. The name, address, phone and hours are filled after the owner confirms, and no review text is copied.
3. A special shared to WhatsApp Status shows a 9:16 image with the link. A printed A5 poster's QR opens the booking page, and Reports counts the visit as "poster".
4. An agent sets up a new business in under 15 minutes on a phone, from code to first top-up, and the owner receives the hand-over message.
5. An invoice for R1 200 is sent on WhatsApp. A reminder goes out 7 days after the due date, the customer pays by link, and the invoice is marked paid.
6. A cash-up with R50 missing asks for a reason. Locked cash-ups can only be reopened by the owner, and each is audited.
7. A slip photo becomes an expense after the owner confirms. The month's "you made about R…" equals takings minus expenses.
8. The Monday WhatsApp summary arrives with last week's figures, and STOP turns it off.

## Waiting on WURK

- A **Google Places API** key (and a billing account on Google Cloud).
- **WhatsApp templates** on WURK's number:
  - `wurk_weekly_summary`;
  - a payment reminder sent from each business's own number: a new per-business template, submitted automatically like the follow-up and reminder templates (Sprint 21).
- The **free first setup grant** in credits (recommend 20 credits).

## Parts

23.1 → 23.2 → 23.3 → 23.4 → 23.5a (who owes me, invoices) → 23.5b (cash-up, expenses, profit, weekly summary) → 23.6, each with one CI run.

## Progress

| Part | PR | Notes |
|---|---|---|
