# Sprint 22 — Mobile money & payments

| | |
|---|---|
| **Status** | Approved by WURK (18 Nov 2026: "All approved", with Sprints 19–25 in sequence) |
| **Release** | v0.23.0 |
| **Depends on** | v0.22.0. Builds on: Payments (businesses' own merchant accounts, Sprint 10), Billing (WURK's invoices, card, DebiCheck and cash, Sprint 3), Credits (wallet and rate card), Sales (cash at the till), WhatsApp (Sprint 21) |
| **Next** | Sprint 23 — Easy setup & money tools |

## Why

Many of WURK's owners and their customers don't pay by card:
- In South Africa, a growing share pays by **QR (SnapScan, Zapper)**, **Capitec Pay** and **PayShap**. Cash is still common at the counter.
- In the rest of Africa, **mobile money is how people pay**: M-Pesa in Kenya and Tanzania, MTN MoMo in Ghana, Uganda, Zambia, Cameroon and Côte d'Ivoire, and Airtel Money across East and Central Africa.

Owners also need a way to pay WURK that fits their cash flow: small, frequent top-ups instead of a card on file or a debit order.

## What the market does (Nov 2026)

| Option | Strengths | Limits for WURK's businesses |
|---|---|---|
| **Paystack** (WURK's billing gateway since Sprint 3) | In South Africa: cards, EFT, **Capitec Pay** and **SnapScan** (QR) on one hosted checkout. **Mobile money** for businesses in Ghana (MTN, AirtelTigo, Telecel), Kenya (M-Pesa, Airtel) and Côte d'Ivoire (MTN, Orange, Wave): `POST /charge` with `mobile_money`, then the customer approves on their phone within 180 seconds | Mobile money only for businesses registered in those countries; the account's own currency |
| **Safaricom M-Pesa (Daraja)** | Direct STK push ("Lipa na M-Pesa") to the customer's phone, to the business's own Till or Paybill; the cheapest route in Kenya | Kenya only; each business needs its own shortcode and Daraja app |
| **MTN MoMo API** (Collections, "Request to Pay") | One API across MTN's markets (Uganda, Ghana, Zambia, Cameroon, Côte d'Ivoire, Rwanda…); the customer approves on their phone | Each country is onboarded separately; sandbox, then production keys per business |
| **Airtel Money (Africa API)** | USSD push collections in Kenya, Uganda, Tanzania, Zambia, Malawi, Rwanda, DRC and more | Separate onboarding per country |
| **Flutterwave** | Widest single coverage: cards, mobile money and bank transfer in Nigeria, Ghana, Kenya, Uganda, Tanzania, Rwanda, Zambia, Cameroon and francophone West Africa | Aggregator fees (about 0.5–1 point above direct); KYC per business |
| **PayShap** (South Africa) | Instant bank payments to a phone number (ShapID); 45 million payments a month by late 2025; "PayShap Request" lands in the customer's banking app; up to R50 000 | Merchant access through a few gateways (e.g. Stitch); not yet on Paystack's checkout |
| Cash | Universal | Easy to lose track of; nothing records it unless the owner does |

**WURK's position:** a business pays and gets paid the way its customers already do, with every payment confirmed by asking the provider (never trusting the browser), money going straight into the business's own account, and the owner seeing it in one place.

Sources:
- [Paystack mobile money codes by country](https://mctaba.com/learn/payments/paystack-mobile-money-provider-codes-by-country)
- [Paystack: SnapScan](https://paystack.com/blog/product/snapscan)
- [Paystack: Capitec Pay](https://paystack.com/blog/product/accept-instant-bank-payments-with-capitec-pay-on-paystack)
- [Provider by country, 2026](https://kolonell.com/en/blog/choosing-payment-provider-by-country-decision-matrix-2026)
- [PayShap in 2026](https://stitch.money/blog/real-time-payments-in-south-africa-the-state-of-payshap-in-2026)
- [Flutterwave mobile money](https://developer.flutterwave.com/docs/mobile-money/)
- [MTN MoMo collections](https://kolonell.com/en/blog/mtn-momo-api-ghana-ivory-coast-2026)

## Scope

### 22.1 Paystack for businesses: QR, Capitec Pay and mobile money
- A business can connect **its own Paystack account** (secret key, in Settings → Payments, encrypted, with "Test connection"), next to PayFast, Yoco, Ozow and Peach.
- In South Africa, Paystack's checkout offers card, EFT, **Capitec Pay** and **SnapScan**. The owner picks which channels appear.
- Deposits, class places and shop orders can be paid this way. As with every provider, a payment counts only when WURK has verified it with Paystack: the webhook signature (HMAC-SHA512) is checked, then the transaction is verified. Refunds go through Paystack.
- **Mobile money on Paystack** is used by businesses registered in Ghana, Kenya and Côte d'Ivoire (see "Countries" below).

### 22.2 Mobile money, direct
- **M-Pesa (Daraja STK push)**, **MTN MoMo (Request to Pay)** and **Airtel Money (USSD push)** as payment methods. The business enters its own shortcode or API keys in Settings → Payments, and money goes to its own wallet or till.
- **The customer's experience:**
  - They choose mobile money and enter their number (prefilled on WhatsApp).
  - A prompt appears on their phone, and they approve it with their PIN.
  - The page waits and confirms, or says plainly what went wrong: declined, timed out, or not enough funds.
- **Reliability:** each payment is confirmed by the provider's callback and by asking the provider. Payments that never get an answer are re-checked by a job. Duplicate callbacks are ignored.
- **In WhatsApp (Sprint 21):** the assistant can send the mobile money prompt itself ("Pay R200 deposit now?" → Pay), so the customer never leaves the chat.

### 22.3 Cash and in-person payments, recorded
- **On a booking:** "Paid in cash", "Paid by card machine" or "Paid by SnapScan / Zapper at the counter" for the deposit or the full price, with the amount and who took it. It's audited and counts in reports.
- **On a shop order:** marked paid in cash at collection or on delivery. The stock and the order's journey follow as for any payment.
- The day's cash appears in the owner's evening summary (Sprint 21.6) and in Reports. "End-of-day cash-up" itself comes in Sprint 23.

### 22.4 Businesses pay WURK by mobile money and QR
- WURK's invoices and credit packs can be paid through **Paystack's checkout with every channel WURK's account allows**. In South Africa: card, EFT, Capitec Pay and SnapScan.
- A **second gateway for WURK itself, Flutterwave**, covers mobile money and cards outside South Africa. Its keys go in Admin → Providers → Payments.
- Each payment is verified with the gateway, and its webhooks are signed, as for Paystack today.

### 22.5 Low entry plans and pay-as-you-go
- **Prepaid plans:** a package can be marked "pay as you go". Its price is taken from the business's WURK credits each day (the monthly price ÷ days in the month) instead of an invoice. Owners top up credits by any method above, including small amounts (from R20).
- **When credits run low:** WhatsApp, SMS and email warnings at 7, 3 and 1 day(s) left. With nothing left there's a grace period (Admin setting, default 3 days), then the usual suspension rules (Sprint 3 dunning). Nothing is deleted, and topping up restores the business at once.
- **Low entry plan:** HQ can create packages with a small monthly price and a limited set of modules (e.g. website, chat, bookings) in Admin → Packages, as today. Pay as you go makes them practical without a card.
- The owner sees "Your plan is paid until …" and "about N days left" on Home and in My plan & usage.

### 22.6 Release
- The payment journeys are added to the end-to-end tests: Paystack in South Africa with each channel; mobile money with a declined, a timed-out and a successful prompt (sandbox responses recorded); cash on a booking and an order; and pay-as-you-go running out and topping up.
- Release v0.23.0, with upgrade steps.

## Countries

WURK runs in South African rand today. **Country packs** (currency, VAT, phone formats and payment providers per country) arrive in **Sprint 25**. Until then:
- **Live in South Africa:** Paystack (card, EFT, Capitec Pay, SnapScan), cash and in-person recording, pay as you go, and Flutterwave and Paystack for WURK's own billing.
- **Built and tested, then switched on per country with the country packs:**
  - M-Pesa (Kenya, Tanzania).
  - MTN MoMo (Ghana, Uganda, Zambia, Cameroon, Côte d'Ivoire, Rwanda).
  - Airtel Money (Kenya, Uganda, Tanzania, Zambia, Malawi, Rwanda).
  - Paystack mobile money (Ghana, Kenya, Côte d'Ivoire).

  Each method is tied to the countries and currency its provider supports, so it can't be switched on for a business elsewhere.

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Whose account | **The business's own** merchant account or wallet for its customers' payments, as since Sprint 10. WURK never holds customers' money, which avoids licensing as a payment service provider |
| B | Mobile money: direct or aggregator | **Direct where it's much cheaper and widely used** (M-Pesa Daraja, MTN MoMo, Airtel), **Paystack** where the business already has it, and **Flutterwave for WURK's own billing** (one integration for many countries) |
| C | PayShap | **Not in this sprint**: no gateway WURK uses offers PayShap Request yet. Capitec Pay and Ozow (instant EFT) cover instant bank payments today. Revisit when Paystack or Peach adds it |
| D | Pay as you go | **Daily deduction from WURK credits** for packages marked prepaid. It reuses the credit wallet, top-ups and dunning; no new ledger |
| E | Minimum top-up | **R20**, so a business can pay in small amounts; Paystack's fees on small amounts are shown before paying |

## Acceptance

1. A South African customer pays a booking deposit with SnapScan, and with Capitec Pay, through the business's own Paystack account. The booking is confirmed only after WURK verifies the payment.
2. A customer pays by mobile money (sandbox): the prompt on the phone, then approval, gives a confirmed booking. Declined and timed-out prompts say so plainly, and the time is released when the hold expires.
3. The owner records a cash deposit on a booking and cash on collection for an order. Both are audited and appear in reports and in the evening summary.
4. A business on a pay-as-you-go package is charged daily from credits and warned before it runs out. It is suspended after the grace period, and restored at once when it tops up R20 by Capitec Pay.
5. WURK's invoice is paid by SnapScan through Paystack, and (sandbox) by mobile money through Flutterwave.
6. No provider key is stored or shown in plain text. Every payment is verified with its provider. Webhooks are signed and replay-safe.

## Waiting on WURK

- **Paystack:** confirm SnapScan and Capitec Pay are on WURK's own account; a Flutterwave business account (KYC) and its keys for Admin → Providers.
- **For live mobile money tests:** M-Pesa Daraja, MTN MoMo and Airtel production onboarding (per country), when the first non-South African businesses are planned (Sprint 25).
- **Pricing:** which packages are pay as you go, their prices, the grace period and the minimum top-up.

## Parts

22.1 → 22.2 → 22.3 → 22.4 → 22.5 → 22.6, each with one CI run. Providers are tested against recorded sandbox responses, so nothing waits on onboarding.

## Progress

| Part | PR | Notes |
|---|---|---|
| 22.1 | | Paystack on the business's own account: key tested on saving, channels the owner picks (card, EFT, Capitec Pay, SnapScan), webhook address shown, signed webhooks, verified before counting (amount and currency), refunds. WURK can take payments through its own Paystack account too (Admin → Payment methods) |
| 22.2 | | M-Pesa (Daraja STK push, Paybill or Till), MTN MoMo (Request to Pay) and Airtel Money (USSD push) on the business's own account, offered only where each provider works. The customer types their number on the business's payment page, approves on their phone, and the page waits (no script; it refreshes itself). Results are always read back from the provider: on the page, from the (unsigned) callback, and by a job every 20 s. Plain reasons (declined, timed out, not enough funds, wrong PIN); prompts expire after 3 minutes; at most 5 prompts per payment, 30 s apart; a retry first re-checks the earlier prompt so money is never taken twice |
