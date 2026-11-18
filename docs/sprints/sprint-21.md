# Sprint 21 — WhatsApp, both ways

| | |
|---|---|
| **Status** | Approved by WURK (18 Nov 2026: "All approved", with Sprints 19–25 in sequence) |
| **Release** | v0.22.0 |
| **Depends on** | v0.21.0. Builds on: Messaging (Meta Cloud API templates, providers, credits), Customers → Chats (Sprint 12, 19.4b), the AI receptionist and its tools (Sprint 19), Bookings reminders, Payments (pay links), Shop (orders) |
| **Next** | Sprint 22 — Mobile money & payments |

## Why

In Africa, WhatsApp is where customers talk to businesses. Today WURK only sends WhatsApp messages: booking reminders and codes from WURK's own number. Customers reply, and nobody sees the reply.

Owners want three things:
- answer from one place;
- let the AI receptionist (Sprint 19) handle the evening rush on WhatsApp;
- let customers book, pay and reorder without leaving the chat.

## What the market does (Nov 2026)

| Product | What it does well | Gap for WURK's businesses |
|---|---|---|
| WhatsApp Business app (free) | The owner's own number; catalogue, quick replies, labels | One phone, no team inbox, no booking or payments, no AI that knows the diary |
| WATI, Interakt, AiSensy | Shared inbox, broadcasts, keyword bots; from about $20–49 a month plus Meta fees | Separate from bookings, shop and customers; flows are built by hand; dollar pricing |
| respond.io, SleekFlow | Omnichannel inbox, routing, AI assist; from about $79–99 a month | Too complex and expensive for a salon or spaza |
| Chakra and similar | AI trained on your content, payment links, catalogues; from about $12–50 a month | No real booking engine or availability; payments only through links to gateways outside Africa |
| Meta (platform) | Embedded Signup for partners, with **coexistence**: the owner keeps the WhatsApp Business app on the same number while a platform also connects to it. Inbound messages are free. **From 1 Oct 2026, replies inside the 24-hour window are charged** at the utility rate. General-purpose AI chatbots have been banned since 15 Jan 2026; a business's own assistant is allowed | Each business needs a verified Meta portfolio and a payment method on its WhatsApp account; templates need approval |

None of these handle voice notes well, and few take local payments in the chat. **WURK's edge:** the same assistant that knows the business's diary, prices and stock works on WhatsApp. It books, takes deposits by pay link (and mobile money in Sprint 22), understands voice notes in local languages, and hands over to the owner in one inbox. Pricing is in rand, through WURK credits.

Sources:
- [Meta Embedded Signup](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview/)
- [Pricing changes, Oct 2026](https://www.courier.com/blog/whatsapp-pricing-changes-october-2026)
- [Chatbot policy](https://respond.io/blog/whatsapp-chatbot-policy-2026)
- [Audio messages](https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/audio-messages)
- [African WhatsApp platforms](https://chakrahq.com/article/top-10-whatsapp-api-solutions-in-africa-2026/)

## Scope

### 21.1 Connect the business's own WhatsApp number
- In **Settings → WhatsApp**, the owner taps **Connect WhatsApp**. Meta's Embedded Signup opens, and the owner signs in, picks or creates the business portfolio, and chooses their number.
- **Keep using the WhatsApp Business app** (coexistence) is the default. The owner's phone keeps working; WURK sees the same conversations. A new number works too.
- WURK stores the WhatsApp account and phone number IDs. The business token is encrypted per business and never shown.
- WURK subscribes to webhooks and shows the number's status: connected, display name approved, quality rating, messaging limit, and payment method added. Each status comes with a plain next step.
- **Keys:** Meta app ID, Embedded Signup configuration ID, app secret and system user token are set in **Admin → Providers → WhatsApp** (WURK's rule: keys in settings), with Test connection.
- **Disconnect** removes WURK's access. Messages already received stay with the business.

### 21.2 Two-way WhatsApp in Customers → Chats
- **Inbound:** a signed webhook (app secret, replay-safe) brings in text, images, documents, location, contacts and voice notes, in the right business by phone number ID. Media is fetched, virus-scanned (ClamAV) and stored privately.
- **One conversation per customer number:** WhatsApp threads sit next to web chats in **Chats**. The customer is matched to a contact, or one is created with WhatsApp consent recorded.
- **Replies:**
  - The owner or staff reply from the inbox (text, photo, document).
  - Inside the 24-hour window it's a free-form message. Outside it, the inbox offers the approved templates ("We're following up on your message").
  - The window's remaining time is shown.
- **Status and costs:** sent, delivered and read ticks; failures in plain words. Each reply costs credits at Meta's rate plus WURK's margin, which is set in the rate card. Inbound messages are free.
- **Opt-out:** "STOP" (and local equivalents) stops marketing messages, and a reply confirms it.

### 21.3 The AI receptionist on WhatsApp
- The owner switches the receptionist on for WhatsApp separately from the website.
- It uses the same knowledge, tools (free times, book, move, cancel, prices, hours) and guardrails, and the same hand-over: the owner takes over in Chats, and the receptionist stays quiet until handed back.
- It's business-specific by design, which meets Meta's 2026 AI policy (no general-purpose chat). Off-topic questions are politely declined, as on the web.
- **Replies use WhatsApp's own controls:** reply buttons (up to 3) and lists (up to 10 rows) for services and times, so customers tap instead of type.
- **Out of hours:** an owner-set message, and the receptionist offers to book.
- **Limits:** the monthly cap and per-business credits apply. When credits run out, the conversation goes to the owner, never silence.

### 21.4 Voice notes understood
- Voice notes (and audio files) are transcribed with the AI provider set in **Admin → Providers** (OpenAI, Google; Anthropic has no speech-to-text). They're limited to 2 minutes, with the language detected.
- The transcript shows in Chats with a play button. The receptionist answers it as if it were typed.
- Languages tested: English, isiZulu, isiXhosa, Afrikaans, Sesotho, Setswana, Kiswahili, Hausa, Yoruba and French. Where transcription is unsure, the receptionist asks the customer to confirm or type.
- Audio is deleted after 30 days (setting); the transcript stays with the chat.

### 21.5 Booking, reminders, paying and reordering in WhatsApp
- **Reminders:** booking reminders sent through the business's number (or WURK's, if not connected) carry **Confirm / Move / Cancel** buttons. A tap is handled straight away; Move offers new times.
- **Deposits and balances:** a WURK pay link (Sprint 10 payments, mobile money in Sprint 22). When payment lands, the chat confirms.
- **Reorder:** "Same as last time?" with the last shop order, a pay link, and collection or delivery.
- **Click-to-chat:** the website's WhatsApp button and QR codes open the business's number with a prefilled message ("Hi, I'd like to book").

### 21.6 Owner's daily summary and reply-to-confirm; release
- Every evening, the owner's WhatsApp gets one message: tomorrow's bookings, new orders, payments received, chats waiting, and requests needing a yes or no.
- The owner replies with a number to confirm or decline a pending request (booking request, cancellation, refund). It's applied, logged and audited.
- Only the owner's verified numbers are accepted.
- Quality: the WhatsApp journeys are added to the receptionist evaluation set (voice-note transcripts included).
- Release v0.22.0.

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Whose number | **The business's own number**, through Embedded Signup with WURK as a Meta **Tech Provider**, with coexistence on by default. Not a shared WURK number: customers must see the business's name, and the owner keeps their app |
| B | Who pays Meta | **To start:** each business adds its payment method in Meta during signup (Meta requires it for Tech Providers); WURK charges only its own credits per reply. **Later:** become a Meta Business Partner (Solution Partner with a credit line) so WURK pays Meta and bills everything in credits. This needs Meta's approval and volume. Owners without a card can stay on WURK's number for reminders until then |
| C | Speech-to-text | OpenAI transcription (or Google) through the existing provider settings and per-business caps; Anthropic for the replies. Test the African languages in the evaluation set before switching on per language |
| D | Free-form reply cost | From 1 Oct 2026, Meta charges replies inside the window. WURK passes this on in credits, shown before sending ("This reply uses 0.2 credits"), and the receptionist's replies are counted the same way |
| E | Payments in chat | Pay links now; WhatsApp's native payments are not available in most African markets. Mobile money arrives in Sprint 22 |

## Acceptance

1. An owner connects their existing WhatsApp Business number in under 5 minutes and keeps using the app on their phone.
2. A customer's WhatsApp message (text or voice note) appears in Chats within seconds. The owner replies from WURK, and the customer receives it.
3. With the receptionist on, a customer books by WhatsApp using buttons and lists, pays the deposit by link, and gets a confirmation. The booking is in the diary.
4. A voice note in isiZulu asking for a time on Saturday gets a correct offer of times, or a clear "please type" when unsure.
5. Tapping Cancel on a reminder cancels the booking and frees the slot. Tapping Move offers new times.
6. The owner gets the evening summary, replies "1", and the pending booking is confirmed and audited.
7. Webhooks are signed and replay-safe, media is scanned, tokens are encrypted, and a business never sees another's messages (isolation sweep).

## Waiting on WURK (business steps that take weeks)

- **Meta:** Business Verification for WURK's portfolio, App Review for `whatsapp_business_management` and `whatsapp_business_messaging`, and Tech Provider onboarding. Until then, Embedded Signup works for up to 10 businesses a week in development mode.
- **Templates:** approval for the reply-outside-window template, reminder templates with buttons, and the owner's daily summary.
- **Keys:** the Meta app's keys in Admin → Providers; and OpenAI or Google keys for transcription.

## Parts

21.1 → 21.2 → 21.3 → 21.4 → 21.5 → 21.6, with one CI run each. Everything is built and tested against recorded Meta webhook payloads and a fake Graph API, so nothing waits on Meta's approvals.

## Progress

| Part | PR | Notes |
|---|---|---|
| 21.1 Connect the business's own number | #221 | `whatsapp_connections` (central: Meta's webhooks for every business arrive at one address and are routed by phone number ID), with the token and PIN encrypted. Embedded Signup without Meta's JavaScript SDK: a full-page visit to Meta's dialog (`config_id`, coexistence via `featureType: whatsapp_business_app_onboarding`) and back to one fixed address on the app domain, with an encrypted, expiring state tied to the owner's session nonce and user. The server exchanges the code, finds the shared account from the token's granted scopes (`debug_token`), lists its numbers, subscribes WURK's app and registers a new number with a two-step PIN (not for coexistence). No third-party script or frame on WURK's pages. |
| 21.2 Two-way WhatsApp in Chats | #222 | Meta's webhook routes each change by `phone_number_id` to the connected business and dispatches `WhatsAppReceived` / `WhatsAppStatusChanged` inside its context; Website listens (no module reaches into another). WhatsApp threads are receptionist conversations with channel `whatsapp` (so 21.3's assistant can answer them), with `external_id` unique per business (Meta's repeats are ignored), `last_inbound_at` for the 24-hour window and rank-ordered delivery ticks. Media is fetched by a queued job only from Meta's hosts (no redirects), scanned with ClamAV (retried while the scanner is down) and kept encrypted on the private form-files disk. Replies and photos/PDFs go through the `WhatsAppBusiness` contract, charged in credits first and refunded on failure, with Meta's error codes in plain words. After the window: the `wurk_follow_up` utility template, once per silence. WURK's templates (`WhatsAppGraph::TEMPLATES`) are submitted to each business's account on connect and re-checked with **Check with Meta**; their review statuses are kept by name in `whatsapp_connections.templates`. STOP words withdraw marketing consent through `Audiences`. |
