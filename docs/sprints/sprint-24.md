# Sprint 24 — Languages & light on data

**Status:** approved 18 Nov 2026 (decisions A–G as recommended).

## Why

WURK's owners and their customers speak many languages, and many of them use older Android phones on prepaid data with a weak signal. Today every screen is in English, and every page assumes a fast, steady connection.

1. **Language.** An owner who is more at home in isiZulu or French reads settings, reminders and reports slowly in English, and makes mistakes. Customers who book or pay on a business's page often prefer their own language too.
   - Today only the AI receptionist (Sprint 19) and voice notes (Sprint 21) work in other languages.
2. **Data and signal.** A back-office page that downloads a megabyte of script on 3G costs the owner money and time. A sale recorded while the signal drops is lost.

## What the market does (Nov 2026)

| Product | Strengths | Gap for WURK's businesses |
|---|---|---|
| **Shopify, Wix** (multi-language storefronts, translation apps) | Shop pages in many languages, often machine-translated, with apps such as Shopify's own [Translate & Adapt](https://www.shopify.com/blog/shopify-localized) | The back office stays in a handful of large languages; isiZulu, isiXhosa, Sesotho, Hausa and Yoruba are absent or left to agencies ([Afrolingo](https://afrolingo.co.za/blog/multilanguage-shopify/)) |
| **WhatsApp** (in-app message translation) | Translates chats on the phone | No indigenous South African languages yet ([Fast Company SA](https://fastcompany.co.za/tech/2025-09-26-exploring-whatsapps-translation-tool-could-this-help-solve-sas-language-divide)) |
| **Local AI chat builders** (e.g. Howzit AI, Cape Town) | Chatbots in all 11 official South African languages ([Portal ERP](https://portalerp.com/za/noticia/howzit-ai-builds-south-african-chatbot-in-all-11-official-languages)) | A chatbot only, not the whole business tool |
| **Offline-first field apps** (PWA / TWA builds for Africa) | Keep working without a signal and sync later ([Paneo](https://paneo.tech/insights/offline-first-field-operations-pwa-twa-android), [Kolonell](https://kolonell.com/en/blog/offline-mode-app-power-cuts-africa-2026)) | Built one-off per company; not part of a small-business suite |

The gap WURK can fill is a whole small-business tool, back office and customer pages, in the owner's and customers' languages, that stays cheap on data and keeps working when the signal drops.

## Scope

### 24.1 Languages, the foundation
- **Every fixed text in the back office, emails, SMS and customer pages is translatable.** This covers labels, messages, errors, emails, the booking widget, checkout, invoices, posters and the share kit.
  - The texts stay in English in the code. Each page loads only its own texts in the chosen language, as a small file that is cached.
  - Missing texts fall back to English, so nothing ever shows blank.
- **Each person picks their language** in Account → Language, for the back office and their own emails and SMS. A business sets a default for new team members.
- **Numbers, money, dates and times** follow the language and country (for example "R1 380,00" or "R1 380.00"), and the same way on the server and in the browser.
- **A check in CI** fails the build when a new English text isn't marked for translation. It runs as an extraction script and an architecture test.

### 24.2 The nine languages
- **Languages:** isiZulu, isiXhosa, Afrikaans, Sesotho, Swahili, French, Portuguese, Hausa and Yoruba.
- **First drafts by WURK AI, with a glossary per language.** The glossary fixes words like booking, deposit and invoice, so the same thing is always called the same.
- **Reviewed by people.** Admin → Languages shows each language's progress: drafted, reviewed, changed since review. A reviewer, invited by HQ, corrects texts in context, one screen at a time.
  - Until a language is reviewed, it is offered as **"preview"**, with a link to report a wrong word.
- **Messages to customers come in the customer's language.** These are the fixed booking messages, reminders, receipts, invoices and the payment reminder template. The language is remembered per customer, from the page they used or what the business sets.
  - WhatsApp templates get approved language versions, submitted automatically like the English ones.

### 24.3 Customer pages in the customer's language
- **System texts follow the customer's language** on the website's booking, shop, checkout, invoice and portal pages. The language comes from the browser, a switcher, or the link the business shared (`?lang=`).
- **The business's own words** (page text, services, products) stay as the owner wrote them. The owner can add a translation per language, or ask WURK AI to draft one (credits).
  - Translated pages are then served at `/zu/…`, with `hreflang` for search engines. The SEO and AEO standard applies.
- **The AI receptionist** keeps answering in the customer's language (Sprint 19), now with the business's translated facts.

### 24.4 Lite mode (light on data)
- **Turns on by itself** when the browser says data saver is on or the connection is slow (2G or 3G). It can also be switched on or off per person.
- **What it changes:**
  - Smaller images (low-resolution first, tap for more).
  - No autoplay video.
  - No web fonts (system fonts).
  - Charts load on request.
  - Fewer live refreshes.
- **Lighter pages for everyone:**
  - A budget per page for the scripts and data it downloads, kept by a CI check: back office **≤ 170 KB** of compressed script on first load, and **≤ 60 KB** per page after that.
  - Heavy parts (the page editor, charts) load only when used.
- **A data counter:** Account → Lite mode shows about how much data WURK used this month on this phone.

### 24.5 Keep working with a weak signal, catch up later
- **WURK installs from the browser** (also the base for Sprint 26's app). It opens without a signal, showing the last-seen Today, bookings, orders and who owes me, each marked with when it was last updated.
- **Saved now, sent later:** the most common things can be done offline and are sent when the signal returns, each with a clear "waiting to send" mark:
  - record a sale;
  - money taken in person;
  - add a booking;
  - add an expense;
  - cash-up counts;
  - reply to a chat.
- **Each one is sent once, safely.** Every queued action has its own key, so a retry never records it twice.
  - If something changed meanwhile (the time was booked, the order was paid), the person is told plainly and chooses what to do.
  - Nothing that moves money online (refunds, payouts) is ever queued.
- **Customer pages work on a weak signal too.** The booking form keeps what was typed and retries. A shop cart survives a dropped connection.

### 24.6 Release
- **End-to-end tests:**
  - switching language (back office and a customer page);
  - a booking message received in isiZulu;
  - a page loaded with lite mode and slow 3G emulated, within budget;
  - a sale recorded offline and sent when back online, once;
  - a booking conflict found on catch-up.
- **Release v0.25.0,** with upgrade steps:
  - the reviewers for each language;
  - the translated WhatsApp templates;
  - turning languages on in Admin → Languages.

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Translation approach | **WURK AI drafts, people review.** A language is "preview" until reviewed. HQ engages one reviewer per language (paid per batch, or partners). |
| B | Library | **Laravel's own JSON translations on the server; a small `t()` helper in React, fed per page by Inertia.** No heavy client library, which keeps lite mode light and gives one source for both sides. |
| C | Which languages first | **All nine drafted at once; isiZulu, Afrikaans and French reviewed first** (most owners today). The rest come out of preview as reviews finish. |
| D | Business content translation | **Optional, per page.** AI drafts at credit cost and the owner checks. WURK never auto-publishes translated business content. |
| E | Offline scope | **Only the six actions in 24.5,** each with a dedicated "sent once" key and conflict message. Nothing that moves money online is queued. |
| F | Lite mode default | **Automatic** from the browser's data-saver and connection signals, and remembered per person once they choose. |
| G | Data budgets | **CI-enforced script budgets** (170 KB first load, 60 KB per page) plus an image budget on customer pages. A build that goes over fails. |

## Acceptance

1. An owner switches to isiZulu. Every back-office screen they use daily is in isiZulu (reviewed) or clearly marked preview, and nothing is blank.
2. A customer books in French on a business's site and gets their confirmation and reminder in French.
3. On an emulated slow 3G Android Go phone, the bookings screen opens in under 5 seconds after the first visit and stays within its budget.
4. An owner records a cash sale with no signal. It shows "waiting to send", and when the signal returns it's recorded exactly once.
5. A booking made offline for a time someone else took meanwhile is flagged on catch-up, and the owner picks another time.
6. Lite mode switches on with data saver, and images and video follow it.

## Waiting on WURK

- **A reviewer per language**, starting with isiZulu, Afrikaans and French.
- **Approval of translated WhatsApp templates** on WURK's own number (the per-business ones are submitted automatically).
- **Decision A's budget** for reviews.

## Parts

24.1 → 24.2 → 24.3 → 24.4 → 24.5 → 24.6, each with one CI run. 24.1 is large: it touches every page, so it lands in a few PRs (the framework first, then the back office by area).

## Progress

| Part | PR | Notes |
|---|---|---|
| 24.1a | #249 | Language per person (Account & security); visitors by `?lang=`, cookie, browser. Translations as a versioned script before the app; `t()` / `__()` fall back to English; rand formatted the same on server and browser; `scripts/i18n-extract.mjs` + check in `pnpm lint` |
| 24.1b | #250 | Codemod marked ~2,400 back-office texts (`__('…')`); menus and fixed flash messages translated where shown |
| 24.2a | #251 | Translation store over `lang/*.json` (PHP loader + browser script); Admin → Languages (progress, WURK AI drafts with glossary, review in context, offer/withdraw); a draft never replaces a review; placeholders checked; preview until 95% reviewed |
| 24.2b | | Customer messages (booking messages, reminders, receipts, invoice messages) in the customer's language; `contacts.locale` |
| 24.3a | | Customer pages on business addresses in the customer's language (invoice, booking, order, payment pages); signed links ignore `lang` / `utm_source` |
| 24.4 | | Lite mode (Save-Data, slow connection, per-phone choice); site: no web fonts, no autoplaying slides; data counter; build fails over the data budget (first load ceiling 225 KB, pages 60 KB); tests no longer shipped |
| 24.5 | | Service worker (last-seen pages offline), web app manifest; six actions saved offline and sent once (`Idempotency-Key`), conflicts reported; sign-out clears the phone |
| 24.6 | | End-to-end tests; v0.25.0 |

## Verification (before closing)

Every spec item and acceptance criterion, checked against the code and its tests. Verified 19 Nov 2026.

| Item | Where | Status |
|---|---|---|
| 24.1 Every fixed text translatable, falling back to English | i18n.ts, Locales, SetLocale, codemod; LanguagesTest, i18n.test | ✅ Back office and customer pages; the business's site blocks and wurk.co.za pages are not yet marked |
| 24.1 Each person picks their language; a business default for new members | Account & security → Language | ✅ / ⚠️ The business default for new members is not built |
| 24.1 Money and dates per language, the same on server and browser | Locales::money, i18n.ts money | ✅ Money / ⚠️ dates in messages are still English |
| 24.1 CI check for unmarked texts | `pnpm lint` (extraction up to date) | ⚠️ It checks that the list is current, not that every new text is marked |
| 24.2 Nine languages drafted by WURK AI with a glossary, reviewed by people, preview until reviewed | TranslationDrafts, LanguagesController; LanguagesAdminTest | ✅ Drafting runs in production once AI keys are set |
| 24.2 Customer messages in the customer's language; remembered per customer | Templates (locale), Contacts::locale; CustomerLanguageTest | ✅ / ⚠️ WhatsApp templates in other languages not yet submitted |
| 24.3 Customer pages follow the customer's language | CustomerLocale, Blade views; WhoOwesMeTest | ✅ Invoice, booking, order, payment pages |
| 24.3 Owner translations of their own site text, `/zu/…`, hreflang | — | ❌ Not built (open item) |
| 24.4 Lite mode automatic and per person; smaller images, no autoplay, no web fonts | Lite, page.blade, HeroSlideshow; LiteModeTest, e2e | ✅ / ⚠️ smaller images not yet swapped in |
| 24.4 Data budgets in CI | scripts/bundle-budget.mjs via `pnpm build` | ⚠️ Per page ✅ (≤ 60 KB); first load 220 KB against the 170 KB target — ceiling 225 KB, decision pending |
| 24.4 Data counter | lite.ts, Account & security | ✅ |
| 24.5 Installs; opens without a signal showing last-seen pages | sw.js, manifest | ✅ |
| 24.5 Six actions saved offline, sent once, conflicts told | offline.ts, IdempotentRequests; CashUpAndProfitTest, offline.test, e2e | ✅ |
| 24.5 Customer pages on a weak signal (booking form keeps what was typed) | — | ❌ Not built (open item) |
| Acceptance 1 (isiZulu every daily screen) | — | ⏳ Needs the AI drafts and reviews in production |
| Acceptance 2 (French booking messages) | CustomerLanguageTest | ✅ once French is drafted |
| Acceptance 3 (3G bookings screen < 5 s after first visit) | sw.js caches the app | ⏳ To measure on a device |
| Acceptance 4, 6 | e2e, LiteModeTest | ✅ |
| Acceptance 5 (offline booking conflict) | offline.ts reports the server's reason | ✅ (by design; not yet an e2e) |
