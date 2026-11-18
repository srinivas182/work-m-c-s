# Sprint 25 — Reach & trust

**Status:** approved 19 Nov 2026 (decisions A–H as recommended).

## Why

WURK's businesses already reach customers on WhatsApp and the web. Two groups are still left out, and new customers have little to go on when deciding whether to trust a business.

1. **Reach.** Many customers have a basic phone, no data that day, or no WhatsApp. They can't open a booking page.
   - USSD (`*120*…#`) works on every phone, costs the customer nothing on a zero-rated string, and needs no data.
   - A plain SMS works on every phone too.
2. **Trust.** A first-time customer wants to know four things:
   - the business is real;
   - other people were happy;
   - a refund is possible if something goes wrong;
   - prices and tax are shown the way their country expects.
3. **Help for owners.** Owners outside South Africa, or more at home in another language, need help in their own words. They ask on WhatsApp, not through a help-centre search.

## What the market does (Nov 2026)

| Product | Strengths | Gap for WURK's businesses |
|---|---|---|
| **USSD platforms** (Africa's Talking, Panacea, HelloDuty, Vodacom Messaging) | Every phone, no data. Africa's Talking raises a *shared* code at once on MTN, Vodacom, Cell C and Telkom ([AT help](https://help.africastalking.com/en/articles/8167081-ussd-south-africa)). Vendors report 30–40% engagement, against 2–3% for SMS ([Panacea, vendor figures](https://www.panaceamobile.com/the-small-business-revolution-how-smes-are-scaling-with-low-code-ussd-in-2026/)) | Sold to one company at a time from about R5,000 a month. A dedicated code is out of reach for a salon |
| **Review tools** (Birdeye, Podium, WhatsApp review apps) | Automatic requests after a visit raise Google reviews ([Wiser Review](https://wiserreview.com/blog/whatsapp-review-request/), [SleekFlow](https://sleekflow.io/en-sg/blog/collect-reviews-whatsapp)) | Separate subscriptions. Some still "gate" reviews (only happy customers sent to Google), which Google now forbids ([Search Engine Land](https://searchengineland.com/google-review-guidelines-say-discouraging-negative-or-selectively-soliciting-positive-reviews-is-not-allowed-388541)) and the US FTC treats as review suppression ([Goodwin](https://www.goodwinlaw.com/en/insights/publications/2024/09/alerts-practices-cldr-ftc-finalizes-rule-on-consumer-reviews)) |
| **Business verification APIs** (CIPC lookups via Didit, Youverify, PBVerify) | Check a company number and its directors ([Didit](https://didit.me/blog/south-africa-company-registry-database-validation/), [Youverify](https://youverify.co/en/blogs/business-verification-in-south-africa)) | Paid per check. Most WURK businesses are sole traders with no company number, so a company check alone can't be the badge |
| **Shopify, Wix, Square** (refunds, tax) | One refund button per order. Tax per country | Built for card payments. No mobile-money or cash refunds, and no CPA-ready returns policy for South Africa |

**What WURK can do:**
- One shared USSD code and one SMS number for every business, each business with its own short number.
- Honest reviews on WhatsApp, linked to Google, with no gating.
- A badge that sole traders can earn too.
- Refunds that work for cash, card and mobile money.
- Country packs, so a business in Nairobi or Accra starts with the right currency, VAT and phone format.

## Scope

### 25.1 Country packs
- **A country pack holds:**
  - currency and money format;
  - VAT (or GST) name, rate and whether prices include it;
  - phone format and prefix;
  - time zone and first day of the week;
  - the payment providers offered there;
  - the SMS and USSD provider;
  - the default languages;
  - the legal-page templates (returns, privacy, terms).
- **First packs:**
  - **South Africa:** 15% VAT, prices include it.
  - **Namibia:** 15%.
  - **Botswana:** 14%.
  - **Eswatini:** 15%.
  - **Kenya:** 16%.
  - **Nigeria:** 7.5%.
  - **Ghana:** 15% plus the NHIL and GETFund levies, 2.5% each.
  - **Côte d'Ivoire:** 18%.
  - Rates come from [PwC Tax Summaries](https://taxsummaries.pwc.com/quick-charts/value-added-tax-vat-rates), and HQ confirms each one before a pack is offered.
- **Packs are data,** edited by HQ in Admin → Countries with a change history. Setting a new rate starts on a date, and older invoices keep theirs.
- **A business picks its country at signup.** WURK fills in the rest, and the owner can change what differs: a business that isn't VAT-registered sets VAT to none.
- The existing `countries` table (Regions module) grows into the pack. Today's hard-coded rand and 15% become lookups.

### 25.2 USSD and SMS booking
- **One WURK USSD code** (shared, through a provider), with a short number per business. For example: `*384*1234#`, then the business's number.
- **The menu:** see the next free times, book a service, cancel or move a booking, check what's owed, and hear back by SMS.
  - Each screen fits 182 characters, with at most 7 choices per screen.
  - A session that drops can be picked up again within 2 minutes.
- **SMS booking:** a customer texts `BOOK` (or the business's own word) to the WURK number with the business's number. WURK replies with times to choose from, using the same booking rules as the website.
  - Deposits still need a payment link. Without data, the booking is made "pay at the business", if the business allows it.
- **The owner's side:** Settings → Reach shows the business's USSD number and SMS keyword, and a poster and table card with both (the share kit, Sprint 23). Bookings show "came by USSD" or "came by SMS".
- **Costs:** session and SMS costs are charged as WURK credits, at a price HQ sets per country. A business can switch USSD or SMS off.
- **Providers:** Africa's Talking first, for South Africa, Kenya, Nigeria and Ghana. The USSD driver is pluggable, like the SMS drivers. Keys go in Admin → Providers.

### 25.3 Reviews (WhatsApp, Google and the business's site)
- **After a completed booking or a delivered order,** one WhatsApp message (or SMS) asks "How was it?" with 1–5 taps and room for a comment.
  - At most once per customer per 90 days. STOP is respected, and the business can switch requests off.
- **Every customer who answers** gets the same thank-you and the same link to the business's Google review page. **There is no gating:** WURK never sends only happy customers to Google.
- **Every customer is also offered the same private reply:** "Anything we should put right? Reply here and the owner will see it."
  - The owner sees low scores first in Customers → Reviews, so they can put things right. That doesn't change who gets the Google link.
- **Reviews on the business's site:** a Reviews block shows WURK-collected reviews with the date and the reviewer's first name and initial. The owner can reply.
  - The owner can hide a review only for a stated reason (abusive, personal data, not a customer). Hidden reviews are logged and still counted in the average.
  - Every review is shown in date order, not sorted to put the best first. The block shows how many there are and the average.
  - The existing testimonials block can carry only words the owner confirms are real.
- **Google link:** the business's Google review link comes from its Google listing (Sprint 23's Places lookup) or is pasted in.

### 25.4 Verified business badge
- **Three checks, each shown on its own,** so sole traders can be verified too:
  1. **Phone verified:** the owner's number was confirmed by one-time code. Done today at signup.
  2. **Identity checked:** HQ has seen the owner's ID document and a selfie, or a provider has checked them.
  3. **Business registered:** a company or close-corporation number matches the registry (CIPC in South Africa, the matching registries elsewhere). Optional for sole traders.
- **The badge "Verified by WURK"** shows on the site, booking pages and invoices once checks 1 and 2 pass. "Registered business" is added when check 3 passes. The badge links to a WURK page that says what was checked and when.
- **HQ reviews by hand first,** in Admin → Verification: a queue, the documents, approve or refuse with a reason. Documents are kept encrypted and deleted 90 days after the decision. Only the decision is kept.
- **A provider can do the checks later,** with its keys in Admin → Providers, without changing what owners see.
- **The badge is withdrawn** if HQ suspends the business or a check expires. ID checks are redone every 2 years.

### 25.5 Clear refunds
- **One "Refund" action** on a paid booking, shop order, sale or invoice. It refunds all or part, with a reason and a note for the customer.
  - **Card and online payments:** refunded through the provider when the provider supports it (Payments' `refund()`). Otherwise WURK records it as a refund made by hand.
  - **Cash and mobile money:** recorded with who refunded and how. It shows in cash-up (Sprint 23) as cash out.
  - **Store credit:** offered as a choice, never forced. Under South Africa's CPA, a customer entitled to a refund can insist on cash.
- **The returns policy page** comes from the country pack, filled in with the business's choices: its return window (the existing `RefundWindow`) and what it accepts. In South Africa it states the CPA's rights:
  - 6 months for defective goods (s56);
  - 5 days for direct marketing (s16);
  - 10 days for goods unsuitable for a stated purpose (s55(3));
  - 7 days for online sales (ECT Act).
  - Source: [Michalsons](https://www.michalsons.com/blog/returns-under-the-consumer-protection-act/9191).
  - The page says plainly that it isn't legal advice, and the business confirms it.
- **The customer's receipt and the "Who owes me" and Money reports** show refunds. VAT on a refund reverses with it.

### 25.6 Help in the owner's language
- **A WhatsApp help line:** owners message WURK's help number. Ask WURK (Sprint 16.2) answers from the help centre in the owner's language (Sprint 24), including voice notes (Sprint 21).
  - "Talk to a person" hands over to Support with the conversation attached.
  - It never makes changes from the help line. Changes stay in the app, confirmed there.
- **Video clips:** the 30-second help videos (Sprint 16) get subtitles in each reviewed language. WURK AI drafts them and the language reviewers check them.
  - Voice-over is a later choice, not in this sprint.
- **The help line counts toward Ask WURK's free questions,** with the same credits after that.

### 25.7 Release
- End-to-end tests:
  - a USSD session books a time (simulated provider);
  - a review request is answered and shown;
  - a refund is made;
  - the badge appears after HQ approves.
- v0.26.0 with upgrade steps:
  - provider keys;
  - the USSD code request;
  - the WhatsApp templates `wurk_review_request` and `wurk_help_reply`;
  - country pack rates to confirm.

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | USSD code | **One shared WURK code through Africa's Talking, with a business number inside it.** It's live at once and costs one fee for all. A dedicated code per business is offered later, at the business's cost. |
| B | Who pays for USSD and SMS | **The business, in WURK credits,** at a per-country price HQ sets. The customer's session is zero-rated where the network allows. |
| C | Reviews and Google | **No gating.** Everyone who answers is offered the Google link and the private reply alike, as Google's rules and the FTC require. This corrects the Sprint 26 note ("4–5 stars get a link, 1–3 go privately"), which would breach Google's policy. |
| D | Hiding reviews | **Only for a stated reason, logged, and still counted in the average.** Reviews are shown in date order. |
| E | Badge checks | **Phone and identity make "Verified by WURK"; a registry match adds "Registered business."** HQ reviews by hand first; a provider is added later. Documents are deleted 90 days after the decision. |
| F | Refund methods | **Through the provider when it can; otherwise recorded by hand.** Store credit is a choice, never forced. |
| G | First country packs | **South Africa, Namibia, Botswana, Eswatini, Kenya, Nigeria, Ghana and Côte d'Ivoire.** HQ confirms each rate before a pack is offered. |
| H | Help line | **WhatsApp, answered by Ask WURK in the owner's language, with handover to a person; no changes from the help line.** Subtitles for videos; no voice-over yet. |

## Acceptance

1. A customer with a basic phone dials the code, picks a business and books a free time. An SMS confirms it, and the booking appears in the calendar marked "USSD".
2. A customer texts `BOOK` with the business number, chooses a time by reply, and is booked.
3. After a completed booking, the customer gets one review request. Whatever score they give, they're offered the Google link and the private reply. The review shows on the site in date order.
4. HQ approves an owner's ID. The site, booking page and invoice show "Verified by WURK", linked to what was checked.
5. An owner refunds half a card payment and a whole cash sale. The customer gets a receipt, cash-up shows the cash out, and VAT reverses.
6. A new business in Kenya starts with KES, 16% VAT, +254 numbers and the Kenyan returns-policy template.
7. An owner asks the help line in isiZulu how to add a service and gets an answer in isiZulu, with a link to the help article.

## Waiting on WURK (HQ)

- An Africa's Talking account and keys. Request the shared USSD code (immediate) and an SMS number per country.
- The per-country prices for USSD sessions and SMS in credits.
- The WhatsApp templates `wurk_review_request` and `wurk_help_reply` to be approved by Meta, and a WhatsApp number for the help line.
- Confirmation of each country pack's VAT rate and the returns-policy wording (a lawyer's read for South Africa).
- Who in HQ reviews verification documents.

## Parts

| Part | What |
|---|---|
| 25.1 | Country packs: Regions' `countries` table grows into packs, Admin → Countries with history, the business's country at signup, lookups replace the hard-coded currency and VAT |
| 25.2a | USSD: a provider driver (Africa's Talking plus a simulated one), the session state machine and the booking menu |
| 25.2b | SMS booking by keyword; Settings → Reach; posters; credits per session and SMS |
| 25.3 | Reviews: requests after a visit or order, the Reviews screen, the site block, the Google link, no gating |
| 25.4 | Verification: Admin → Verification queue, encrypted documents with 90-day deletion, the badge and its page |
| 25.5 | Refunds across bookings, shop, sales and invoices; cash-up; VAT reversal; the returns-policy template per country |
| 25.6 | WhatsApp help line through Ask WURK; video subtitles |
| 25.7 | End-to-end tests; v0.26.0 |
