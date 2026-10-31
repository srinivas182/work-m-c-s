# Sprint 12 — CRM and messaging

| | |
|---|---|
| **Status** | Approved 31 Oct 2026 — 12.1 ✅ (#97), 12.2 ✅ (#98); 12.6 CI brought forward |
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

## 7a. 12.3 in detail (forms) — written after reviewing what exists

**Already there (Sprint 6.2):** Quote form and Contact form blocks; submissions stored as *enquiries* (name, phone, email, service, message, consent time, hashed IP, status), emailed to the business and listed under Website → Enquiries; consent to be contacted is required (POPIA); a hidden field catches bots; rate limits. **12.3 builds on this — no second form system.**

| # | Part | What |
|---|---|---|
| 1 | **Enquiries become contacts** | Every enquiry is matched to (or creates) a contact, so it appears in their history. Existing enquiries are added by the same release step as 12.1 (`crm:link-existing`). |
| 2 | **Leads from forms** | Each form has "Open a lead for each reply" — on by default for quote forms, off for contact forms. The lead's source is *form* or *quote*, with the service and message as its first note. |
| 3 | **Marketing consent** | An optional, unticked "Send me news and offers" on every form, recorded with its source only when ticked (decision C). The required "contact me about this" tick stays as it is. |
| 4 | **Your own fields** | Owners add questions to a form in the website editor: short text, long text, choice, date, yes/no; each can be required. Answers are stored with the enquiry and shown in Enquiries and on the contact. |
| 5 | **More kinds of form** | A general *Form* block for enquiries, waiting lists and registrations of interest (title, purpose, own fields), alongside the existing Quote and Contact blocks. |
| 6 | **Enquiries ↔ CRM** | Enquiries link to their contact and lead; the lead page shows the enquiry. |
| 7 | **CI balance** | Move two modules into MySQL part 3 (parts took 6m53 / 7m24 / 4m24). |

**File uploads on public forms** (in the original 12.3 line) are **proposed for a follow-up (12.3b)**: they need size and type limits (images and PDF, ≤ 5 MB), private storage, owner-only download and malware checks before being exposed to the public — worth doing properly rather than squeezing in.



| # | Decision |
|---|---|
| A | Patient records stay **out of the CRM**; a patient appears as a contact only through their bookings |
| B | The chatbot is **rule-based only** this sprint |
| C | Marketing messages **only with recorded consent** (one-tap unsubscribe); service messages don't need it |
| D | WhatsApp: **Meta-approved templates** for business-initiated messages; free-form only inside the 24-hour window |

## 9. Progress log

| Part | PR | Notes |
|---|---|---|
| 12.1 Contacts | #97 | Found no shared customer list; contacts link the existing records without changing them. 1,151 backend tests on SQLite; Crm and shop contacts on MySQL 8; all browser tests. Fixed in review: a streamed export ran after the business's context ended; the merge search now uses its own JSON endpoint |
| 12.2 Pipelines | #98 | The leads screens existed from an interrupted session: reviewed and completed rather than rebuilt (per the project's rule). Fixed: stage removal didn't reach the server; follow-up times 2 h off. 1,157 backend tests on SQLite; Crm on MySQL 8; all browser tests incl. the leads journey |
| 12.6 (CI, brought forward) | #99 | The MySQL job reached ~15 min of its 18 after 12.2. Split in three (own MySQL server each) and the browser job in three (two halves + the performance gate). Pest's --shard covered only 618 of 1,157 tests, so the split uses scripts/ci-test-part (two module lists; part 3 is everything else) — verified locally: 232 + 214 + 711 = 1,157 |
| 12.3 Forms | #100 | Continued from another session's branch (its detailed spec, approved; uploads moved to 12.3b). Builds on Sprint 6.2's forms. 1,160 backend tests on SQLite; forms and CRM on MySQL 8 (caught: MySQL's JSON type reorders keys — test made order-free); 388 frontend; all browser tests incl. a real quote request through Enquiries to the contact |

## 7b. Sprint 12.3b in detail — file uploads on public forms (approved 31 Oct 2026)

Visitors can attach files to a form (a photo of the job, a plan, a CV) — safely. Public uploads are the riskiest thing a website accepts, so every layer below applies.

| Part | What |
|---|---|
| **1. A "File" question** | A new question type on Quote and Form blocks: "Attach a file", optional or required, up to **3 files per reply**, **5 MB each**. Allowed: **JPEG, PNG, WebP, HEIC and PDF** only. |
| **2. Checked on arrival** | The file's real type is read from its contents (never trusted from its name or the browser); anything else is refused. Images are **re-saved** (which strips hidden content and location data); PDFs containing JavaScript or embedded files are refused. |
| **3. Malware scan** | Every file is scanned before anyone can open it (decision A below). A file that hasn't been scanned yet shows "Being checked" and can't be downloaded; one that fails is deleted and logged. |
| **4. Private storage** | Encrypted on a private disk (as patient records, 9.5) — never public, never on the website's address. |
| **5. Owner-only download** | From the enquiry (and the contact's page), for people allowed to see enquiries; downloads are always "save as" (never opened in the browser), logged, and not cached. |
| **6. Limits and clean-up** | Uploads count towards the package's storage; the per-business form limit applies; files of archived enquiries are removed after 12 months (a setting) — logged. |
| **7. Tests** | Wrong types disguised as images, oversized files, too many files, PDFs with JavaScript, the scanner unavailable (files held, not released), downloads by people without access (refused), and a browser test with an accessibility scan. |

### Decision needed from WURK

| # | Question | Recommendation |
|---|---|---|
| A | **How are files scanned for malware?** | **ClamAV** (free, open source) running as its own container next to WURK's; the app sends each file to it and waits for the answer. Its virus list updates itself several times a day. Needs ~1.5 GB of memory on the server. *Alternatives:* a cloud scanning service (sends customers' files to a third party — not recommended under POPIA); or no scanning, relying on type checks and re-saving only (cheaper, weaker). |
| B | **Allowed file types** | Images and PDFs only (as above). Office documents (Word, Excel) can carry macros — not recommended at launch. |

### Risks

| Risk | Mitigation |
|---|---|
| A scanner outage blocks downloads | Files are kept "being checked" and released automatically when the scanner is back; the owner still sees the reply and the file's name. |
| Storage abuse | Size and count limits, the form's rate limit, and the package's storage meter. |
| Privacy (CVs, photos of homes) | Encrypted, private, owner-only, logged, and removed with archived enquiries after 12 months. |

### Decisions (WURK, 31 Oct 2026)

| # | Decision |
|---|---|
| A | **ClamAV** in its own container next to WURK's; files are held until scanned |
| B | **Images and PDFs only** at launch |
| 12.3b File uploads | #102 | Decisions: ClamAV; images and PDFs. HEIC dropped (GD can't re-save it; iPhones send JPEG). 1,165 backend tests on SQLite; uploads on MySQL 8; all browser tests incl. a held upload; CI scans EICAR with a real ClamAV |

## 7c. Sprint 12.4 in detail — the chatbot (approved 31 Oct 2026)

A rule-based assistant on the business's website (decision B: no AI this sprint). It answers common questions instantly, points people to booking, the shop or WhatsApp, and when someone wants a person, takes their details as a contact and lead.

| Part | What |
|---|---|
| **1. Ready-made answers** | Switching the chat on starts it with answers built from what WURK already knows (`SiteFacts`): hours, address and map, phone and WhatsApp, services and prices, booking and shop links. The owner reviews them before going live; when the facts change, the answers follow (unless the owner rewrote them). |
| **2. The owner's chat designer** | Website → Chat: a greeting; up to **8 quick buttons** ("Prices", "Book", "Where are you?"), each with an answer (text, plus an optional link — booking, shop, a page, call, WhatsApp, map); **keyword answers** (words like "price, cost, how much" lead to an answer); a fallback when nothing matches ("I'm not sure — would you like someone to get back to you?"); a live preview. |
| **3. Hand-off to a person** | "Talk to someone": name, phone or email, a short message, the required "contact me about this" tick and the optional "news and offers" tick (as forms). The person becomes a contact (source: chat), a lead opens (a setting, on by default), and the owner is emailed — like a form reply, with spam protection and limits per business and visitor. Outside business hours it says when they'll hear back. |
| **4. Chats in the back office** | Customers → Chats: each hand-off with its conversation, linked to the contact and lead. |
| **5. The visitor's side** | A button in the corner of every page (when on); opens a panel (full screen on phones); works by keyboard and screen reader (a labelled dialog, focus kept inside, answers announced); the business's colours. Answers are matched on the visitor's device — typing isn't sent anywhere until they ask for a person. |
| **6. Speed** | The chat's code loads only when the button is first tapped (or after the page has settled), so every page still passes the mobile performance gate. |
| **7. Tests** | Ready-made answers from facts; keyword matching (case, punctuation, several words); hand-off → contact, lead, email; limits and spam; out of hours; isolation between businesses; accessibility of the panel (keyboard, focus, announcements) on desktop and phone; the performance gate. |

### Decisions needed from WURK

| # | Question | Recommendation |
|---|---|---|
| A | **What is stored?** | **Only conversations that end in a hand-off** (with consent), kept with the contact. Chats that never reach a person aren't stored — just anonymous counts of which buttons are used, so owners can improve the answers. (Storing every chat would hold people's typing without consent.) |
| B | **Where does it appear?** | **Every page of the site when switched on** (off by default); the owner can hide it on chosen pages. |
| C | **Opening hours** | Use the hours already confirmed in the site's facts; with none, the hand-off says "We'll get back to you as soon as we can." |

### Risks

| Risk | Mitigation |
|---|---|
| Slower pages | Loaded only on use; the performance gate covers every template. |
| Spam through the hand-off | The same protections as forms (hidden field, limits per business and visitor, consent tick). |
| Wrong answers after facts change | Ready-made answers follow the facts unless rewritten; the designer flags answers that mention old details. |

### Decisions (WURK, 31 Oct 2026)

| # | Decision |
|---|---|
| A | Store **only chats that reach a person**, with consent; otherwise anonymous button counts only |
| B | **Every page when switched on** (off by default); the owner can hide it on chosen pages |
| C | Reply times from the site's **confirmed hours**; otherwise "as soon as we can" |
| 12.4 Chatbot | see PR | Decisions A–C as recommended. Found in testing: the page ETag ignored the chat's state; showing the chat depended on an asset address (empty in tests and before a build); the conversation lost its list meaning; focus left the chat after a hand-off. 1,172 backend tests on SQLite; chatbot on MySQL 8; 390 frontend; all browser tests incl. the full chat journey on a published demo site |

## 7d. Sprint 12.5 in detail — message templates (draft for WURK's approval, 31 Oct 2026)

**Two findings first.** (1) Booking messages were built with *fixed wording — owners choose channels, not words — as agreed with WURK* (Sprint 8). This sprint's plan ("word every message in one place") changes that agreement, so it needs WURK's decision (A). (2) WhatsApp messages use templates WURK registers once with Meta; a business can't have its own WhatsApp wording without registering its own templates with Meta. So WhatsApp wording stays fixed whatever is decided (decision D, already agreed).

**What's in scope:** messages a business sends *to its customers* — bookings (confirmed, reminder, moved, cancelled), class places, and orders (placed, and each update: paid, packed, ready, on its way, done, cancelled, refunded). **Out of scope (fixed):** WURK's own messages — invoices, sign-in codes, invitations, patient-record codes — and alerts to owners.

| Part | What |
|---|---|
| **1. Settings → Messages** | One list of every customer message, grouped (bookings, classes, orders), showing each channel the business uses (email, SMS, WhatsApp) and today's wording. |
| **2. Rewording email and SMS** | (If A is yes.) The owner can rewrite the subject and text of email and SMS messages, using placeholders ({client}, {service}, {when}, {business}, {link}, {order}); a preview with sample details; an SMS length and cost counter; "Back to standard wording". |
| **3. Safety rails** | Messages that carry a link the customer needs (change/cancel a booking, view an order) must keep {link}; only known placeholders; plain text only (no HTML); length limits (SMS 2 parts by default); every change logged. |
| **4. Test send** | Send any message to the owner's own email or phone with sample details. |
| **5. WhatsApp** | Shown read-only with the Meta-approved wording and why it can't be changed; uses the same placeholders. |
| **6. Tests** | Standard wording unchanged until edited (today's exact text); placeholders filled; required {link} enforced; unknown placeholders refused; SMS counter; test sends; isolation; accessibility. |

### Decisions needed from WURK

| # | Question | Recommendation |
|---|---|---|
| A | **Change the "fixed wording" agreement?** | **Yes for email and SMS**, with the safety rails above (a business's tone matters, and the rails keep the essential parts); **WhatsApp stays fixed** (Meta). If no, 12.5 becomes part 1 + 4 + 5 only (one place to see every message, and test sends). |
| B | **Test sends by SMS** | **Count towards the business's SMS credits** like any other SMS (email test sends are free). |
| C | **Automatic replies to enquiries and chats** | Not today. **Add later** as an optional "We got your message" email — not in this sprint. |
