# Sprint 10 — Online shop: detailed specification

| | |
|---|---|
| **Sprint** | 10 (sprint plan revision 3) |
| **Status** | Approved 28 Oct 2026 — 10.1 ✅ (#76), 10.2 ✅ (#78), 10.3 ✅ (#79), 10.4 in progress |
| **Depends on** | v0.10.0 (website engine, payments straight to the business, fixed-wording messages) |
| **Delivery** | 5 sub-sprints, one PR each into `develop`; each passes the full CI run (every job checked) before merging |

---

## 1. Objective

**A small business can sell online from its own WURK website** — products with sizes and colours, stock that never oversells, a fast checkout, payment straight to the business, and orders it can pack, hand over and refund.

## 2. Key decisions

| Decision | Recommendation | Why |
|---|---|---|
| **Where the money goes** | **Straight to the business** (its own merchant account) — as for deposits | WURK never holds customers' money; simpler compliance |
| **Payment providers in Sprint 10** | **Paystack (already built), PayFast and Yoco** first; Ozow and Peach after | The three most used by South African small businesses; each added behind one gateway contract |
| **Delivery** | **Collection and a flat local delivery fee** now; couriers in Sprint 11 | Sprint 11 is multi-branch and shipping |
| **VAT** | **Prices include VAT**; VAT-registered businesses show their VAT number and VAT on receipts | How South African shoppers expect prices |
| **WURK's fee on sales** | **None in Sprint 10** (subscription and credits only) — to confirm | Matches "payments straight to the business" |

## 3. Sub-sprints

### 10.1 Catalogue
Products with photos, description, price, **variants** (e.g. size × colour, each with its own price, SKU and stock), categories, and published or hidden. **Shop sections** for the website: product grid, featured products, and a product page — rendered at publish like every page, so product pages keep the 99–100 performance score.

### 10.2 Cart and checkout
A cart that works on any page (island), and a **checkout on the business's own address**: contact details, collection or delivery, VAT shown, and consent. **Stock is held while paying** (like class places) — never oversold, released if payment doesn't come.

### 10.3 Payments
**One gateway contract**, with Paystack, PayFast and Yoco behind it. Each business connects its own merchant account in Settings. Payment is **verified with the provider** before an order is confirmed (redirect and signed webhook, idempotent); late payments are refunded automatically.

### 10.4 Orders and refunds
An **Orders** screen: paid → packed → ready for collection / out for delivery → done; cancellations; **full or partial refunds** through the provider, returning stock. Customers get fixed-wording messages (order confirmed, ready, on its way, refunded) — email free, SMS/WhatsApp on credits with email fallback.

### 10.5 Templates and quality
Shop sections in the retail-led templates; accessibility scans of product, cart and checkout pages on desktop and phone; the performance gate extended to product pages.

## 4. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 10.1 | Variants with their own stock; product pages pass the performance gate (mobile ≥ 90; target 99–100) |
| 10.2 | Two shoppers can never buy the last item (database-enforced, tested on MySQL 8) |
| 10.3 | No order is confirmed without a verified payment; webhooks idempotent (tested per provider, simulated in CI) |
| 10.4 | Refunds return stock and match the provider's record (tested) |
| 10.5 | Shop pages pass accessibility scans on both sizes |

## 5. Decisions to confirm

1. **Payments straight to each business**, with **no WURK fee on sales** in Sprint 10.
2. **Paystack, PayFast and Yoco** first; Ozow and Peach later.
3. **Collection and flat local delivery** now; couriers in Sprint 11.
4. **Prices include VAT.**

## 6. Needed from WURK

| Item | Needed by |
|---|---|
| **PayFast and Yoco sandbox accounts** (merchant test credentials) | 10.3 |
| Confirmation of WURK's fee on sales (if any) | Before 10.3 |

## 7. Confirmed with WURK (29 Oct 2026)

1. **WURK takes no fee on sales** — it collects only subscriptions and add-ons.
2. **PayFast and Yoco**, test mode first; **each business enters its own keys** in Settings.
3. **Orders paid by EFT or on collection** — an option each business can switch on (agreed 29 Oct 2026).
4. **One payment setup per business for everything customers pay it** — shop sales, appointment deposits and class payments move to the business's own keys (replacing the Paystack subaccounts WURK set up in Sprints 8–9), so WURK is never involved in customer payments.

## 8. Progress log

| Sub-sprint | PR | Notes |
|---|---|---|
| 10.1 Catalogue | #76 | 840 backend tests on SQLite and MySQL 8; 375 frontend; all browser tests incl. a shopper browsing a demo shop; product pages score 100. Found: public shop pages ignored the shop entitlement (fixed) |
| 10.2 Cart and checkout | #78 | 855 backend tests on SQLite and MySQL 8; 375 frontend; all browser tests incl. a full purchase on desktop and phone; product pages 98–100. Found: a 27-character id MySQL rejected; an older page at /cart would have been hidden (now falls through). Owners' Orders screen and a new-order alert are in 10.4, before release |
| 10.3 Card payments (business’s own PayFast/Yoco) | #79 | 904 backend tests on SQLite and MySQL 8; all browser tests. Deposits and class payments moved off Paystack. Found: MySQL JSON reorders keys (PayFast form now text); test leftovers had filled the disk (cleaned up per test now) |
