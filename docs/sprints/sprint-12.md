# Sprint 12 — CRM and messaging

| | |
|---|---|
| **Status** | Approved 31 Oct 2026 — 12.1 next |
| **Plan (revision 3)** | 12 – 23 Apr 2027 · leads and pipelines; forms and custom fields (incl. quote requests); rule-based chatbot; event template engine (email/SMS/WhatsApp) |
| **Builds on** | Messaging (SMS, WhatsApp and email behind one `Messenger`, with delivery records); booking messages (9.x); the website builder's blocks |

## 1. Objectives

Every business gets one list of its customers and leads, built from everything that already happens in WURK (bookings, orders, class places, form replies, chatbot chats), a simple pipeline to follow up on leads and quotes, website forms it designs itself, a rule-based chatbot on its site, and one place to word every message it sends — by email, SMS or WhatsApp.

## 2. Finding that shapes the sprint

There is **no shared customer list** today: bookings, shop orders, class places and patient records each keep their own name, phone and email. A CRM needs one contact per person, so 12.1 creates it and links the existing records to it (matched by phone or email) — without changing how bookings, orders or records work.

## 3. Deliverables

| Part | What |
|---|---|
| **12.1 Contacts** | One contact per person per business (name, phone, email, tags, notes, consent to marketing), matched by phone or email from bookings, orders, class places, forms and chats; a contact page with their history (bookings, orders, messages); search, tags, export. Branch managers see contacts linked to their branches. |
| **12.2 Pipelines** | Leads and quote requests move through stages (New → Contacted → Quoted → Won/Lost — the business can rename and add stages); owners and assignees; follow-up reminders; value and source; a board and a list. |
| **12.3 Forms and custom fields** | Forms built in the website editor (contact, quote request, enquiry, waiting list) with the business's own fields (text, choice, date, file upload); replies create or update a contact and, optionally, a lead; spam protection; custom fields also on contacts. |
| **12.4 Chatbot** | A rule-based assistant on the business's site: greeting, buttons and keywords leading to answers (hours, prices, booking link, location), and a hand-off that captures name and number as a lead. No AI in this sprint. |
| **12.5 Message templates** | One place (Settings → Messages) to word every message WURK sends for the business — booking confirmed/reminder/cancelled, order received/ready/shipped, form received, lead assigned — per channel (email, SMS, WhatsApp), with placeholders, a preview and a test send. WhatsApp uses Meta-approved templates. |
| **12.6 Follow-ups from Sprint 11** | Product weights (courier rates stop using 0.5 kg per item); CI split into parallel parts (the MySQL and browser jobs are at ~11½ minutes and growing ~1 minute per sprint). |

## 4. Decisions needed from WURK

| # | Question | Recommendation |
|---|---|---|
| A | **Patient records and the CRM** | **Keep patient records out of the CRM** (special personal information under POPIA): a patient appears as a contact only through their bookings; documents and notes stay in Records. |
| B | **Chatbot** | **Rule-based only** this sprint (predictable, free to run); an AI option can follow once AI keys and margins are settled. |
| C | **Marketing consent** | Contacts get **marketing messages only with recorded consent** (opt-in on forms, checkout and chats; one-tap unsubscribe). Service messages (confirmations, reminders) don't need it. |
| D | **WhatsApp** | Use **Meta-approved templates** for business-initiated messages (the four pending with Meta, plus new ones per event); free-form replies only inside WhatsApp's 24-hour window. |

## 5. Risks

| Risk | Mitigation |
|---|---|
| Matching people wrongly (two people sharing a phone) | Match on exact phone or email only; owners can merge or split contacts; nothing is deleted when merging (history kept). |
| Privacy | Contacts are business data under POPIA: consent recorded, export and delete on request, access logged; patient records stay separate (decision A). |
| Messages behaving differently after templates change | Today's wording becomes the default templates, so nothing changes until an owner edits them; previews and test sends before saving. |
| Scope | Six parts, each releasable; development remains ahead of the calendar. |

## 6. Expected outcome

An owner opens Contacts and sees everyone who has booked, bought, enquired or chatted — one row per person, with their history — follows up quotes on a board, adds a quote-request form to their site in minutes, has a chatbot answer common questions and capture leads, and words every message in one place.

## 7. Needed from WURK

Decisions A–D; the WhatsApp templates with Meta; SMS/WhatsApp provider accounts (for test sends).

## 8. Decisions (WURK, 31 Oct 2026)

| # | Decision |
|---|---|
| A | Patient records stay **out of the CRM**; a patient appears as a contact only through their bookings |
| B | The chatbot is **rule-based only** this sprint |
| C | Marketing messages **only with recorded consent** (one-tap unsubscribe); service messages don't need it |
| D | WhatsApp: **Meta-approved templates** for business-initiated messages; free-form only inside the 24-hour window |
