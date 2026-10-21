# Sprint 2 — Super admin: detailed specification

| | |
|---|---|
| **Sprint** | 2 of 18 · 16 – 27 November 2026 |
| **Status** | Approved 21 Oct 2026 — 2.1 ✅ (#21), 2.2 ✅ (#22), 2.3 in review |
| **Depends on** | v0.2.0 (tenancy, WURK ID, access control, audit), PRD §4, §12, §14 |
| **Delivery** | 4 sub-sprints, one PR each into `develop`; each must pass CI and be merged before the next starts |

---

## 1. Objectives

Give WURK staff everything they need to configure and run the platform **before** businesses can sign up and pay (Sprint 3):

1. **Run the platform** — a real super admin back office: businesses, WURK staff and their roles.
2. **Sell it** — packages with prices, trials, limits and included modules; module entitlements and feature flags.
3. **Talk to people** — real SMS, WhatsApp and email providers behind one interface (sign-in codes switch from email/log to SMS and WhatsApp).
4. **Charge for usage** — the WURK Credits ledger with a per-country rate card and package allowances.
5. **Lock it down** — security settings: cash-payment OTP and receipt recipients, admin IP allow-list, owners can enforce MFA for their team.

## 2. Out of scope (later sprints)

| Item | Sprint |
|---|---|
| Collecting money (cards, DebiCheck, EFT, cash), invoices, dunning | 3 |
| Marketing site and self-service signup | 3 |
| Editable message templates per business; campaigns | 10, 13 |
| SaaS metrics dashboard (MRR, churn…) | 13 |
| Impersonation, tickets, tenant health monitoring | 14 |

Provider settings for **payment gateways** are stored and connection-tested in this sprint; they are first used to charge in Sprint 3.

---

## 3. Sub-sprints

### 2.1 Super admin back office: businesses and staff

| Screen / capability | Details |
|---|---|
| Admin dashboard | Counts (businesses by status, staff, shards and capacity), recent platform audit events. Full SaaS metrics arrive in Sprint 13 |
| Businesses | List with search and filters (status, package, country, shard); detail page (profile, domains, shard, team owners, audit trail); create a business manually and invite its owner by email or mobile; suspend / reactivate with a reason (audited) |
| WURK staff | List, invite by email (they set up an authenticator on first sign-in), change platform roles, deactivate; custom platform roles from declared permissions |
| Invitations | One reusable invitation flow (signed, single-use, 7-day link) used for WURK staff now and for business team members in Sprint 13 |

Permissions: `tenants.view`, `tenants.manage`, `staff.manage`, `platform-roles.manage` (declared in Sprint 1).

### 2.2 Packages, entitlements and feature flags

**Packages** (PRD §4.2): name, description, display order, public/private; type self-managed or managed; monthly and yearly price (ZAR, stored as cents, VAT added on top at checkout); free trial on/off and days; included modules; storage quota; staff limit; multi-branch on/off and maximum branches; subdomain only or custom domain allowed; monthly WURK Credits; referral guardrails (maximum levels, maximum total commission %); default managed-service fee.

- **Price changes apply to new subscriptions only**; existing subscribers keep their price until they change package (grandfathering). Each change creates a new package *version*, so invoices always show what was sold.
- Packages can be archived (hidden from sale) but never deleted while anyone is on them.

**Module entitlements:** the module catalogue comes from module manifests (`entitlement` key). A business is entitled to a module if its package includes it, plus per-business overrides (grant or revoke) set by WURK staff. Routes declare `entitled:bookings`; menus hide modules the business is not entitled to.

**Feature flags:** Laravel Pennant (database driver, central). Per-business opt-in for new features (PRD §14: 90-day opt-in window, then default), percentage rollouts, and a kill switch. Staff manage flags from the admin.

### 2.3 Countries, providers and messaging

| Area | Details |
|---|---|
| Countries | Country, currency, VAT rate, phone prefix, timezone, enabled flag. South Africa enabled (ZAR, 15% VAT, +27); others prepared but disabled |
| Provider settings | One active provider per channel, chosen by super admin. Credentials encrypted, never shown again after saving, "Send test" for each |
| SMS drivers | Clickatell, BulkSMS, SMSPortal, Brevo, Twilio — one interface; each driver covered by contract tests against recorded API responses |
| WhatsApp | Meta WhatsApp Business Platform (Cloud API) directly; default template list prepared for Meta approval (sign-in code, booking confirmation, booking reminder, payment received) |
| Email | Brevo (transactional) or SMTP |
| Payment gateways (stored only) | PayFast, Paystack, Yoco, Ozow, Peach — credentials + connection test, used from Sprint 3 |
| Sign-in codes | Phone codes switch from the log driver to SMS or WhatsApp (person's choice, WhatsApp first where available); email unchanged |
| Delivery records | Every message: channel, provider, status (queued/sent/delivered/failed), provider id, cost in credits; delivery callbacks (webhooks) update status |

### 2.4 WURK Credits and security settings

**WURK Credits** (ADR-0008):
- Append-only ledger per business: grants (package allowance, purchase, manual adjustment with reason), debits (each message / AI action), refunds (failed sends). Balance is the sum; a cached balance is kept in step inside the same transaction.
- Debits are **idempotent** (keyed by message id) so a retry never charges twice; a send is refused with a clear message when the balance would go negative.
- **Rate card per country and channel** (e.g. ZA: SMS 3 credits, WhatsApp 5, email 0.1 rounded per batch, AI action 2), effective-dated so price changes never rewrite history.
- Monthly package allowance granted on each billing anniversary (job); unused allowance does not roll over (configurable).
- Low-balance alert at a business-chosen threshold.
- Super admin: credit usage by business and channel; manual adjustments (audited, reason required).

**Security settings:**
- Cash payments: OTP recipients and receipt recipients (used by Sprint 3's cash flow).
- Admin IP allow-list (off by default; WURK staff always need MFA regardless).
- Owners can require an authenticator app for everyone on their team.

### Housekeeping
- Dev-image CI build uses buildx with error annotations and retries network steps (a transient mirror failure during the v0.2.0 release was hard to diagnose).
- Pin the FrankenPHP base image to a dated release (follow-up to pinning the extension installer).

---

## 4. Data model (summary)

| Database | Tables |
|---|---|
| Central | `invitations`, `packages`, `package_versions`, `package_modules`, `tenant_subscriptions` (package version per business; billing fields arrive in Sprint 3), `entitlement_overrides`, `features` (Pennant), `countries`, `provider_settings`, `credit_rates`, `platform_settings` |
| Shard | `credit_ledger`, `credit_balances`, `message_deliveries`, `business_settings` |

All amounts are integers (cents, credit units); all changes to packages, rates, providers, settings and credits are audited.

---

## 5. Acceptance criteria

| Sub-sprint | Acceptance |
|---|---|
| 2.1 | Staff create a business and invite its owner, who signs in and lands in it; suspend/reactivate works and is audited; staff invitations force authenticator set-up |
| 2.2 | A package with modules and limits can be created, versioned and archived; a business without the Bookings entitlement cannot reach Bookings routes or see them in the menu; a feature flag can be rolled out to 10% of businesses and opted into by one |
| 2.3 | Each SMS driver passes its contract tests; a sign-in code is delivered by the configured SMS/WhatsApp provider in a staging test; provider credentials are never returned to the browser |
| 2.4 | Credits: allowance granted, debits idempotent under retry, negative balance refused, failed sends refunded; ledger always equals cached balance (property test); IP allow-list blocks admin from other IPs |

## 6. Decisions to confirm

1. **Price changes** apply to new subscriptions only (existing businesses keep their price).
2. **Feature flags** with Laravel Pennant (database driver) and a 90-day opt-in window.
3. **WhatsApp** directly through Meta's Cloud API (no reseller mark-up); WURK completes Meta Business verification.
4. **Email provider:** Brevo for transactional email (it also offers SMS), SMTP as fallback.
5. **Sign-in codes by phone:** WhatsApp first, SMS fallback.
6. **Credits:** unused monthly allowance does **not** roll over (purchased credits never expire).
7. **Starting rate card (ZA):** SMS 3 credits, WhatsApp 5, email 1 per 10, AI action 2 — WURK sets the rand price per credit pack in the admin.

## 7. Client dependencies (needed during this sprint)

| Item | Needed by |
|---|---|
| Accounts / API keys for the chosen SMS provider(s) (sandbox is fine) | 2.3 |
| Meta Business verification started; WhatsApp Business number | 2.3 (sending); templates submitted in 2.3 |
| Brevo (or SMTP) account | 2.3 |
| First package line-up and prices (names, monthly/yearly price, modules, limits) | 2.2 (we can seed placeholders) |

---

## 8. Progress log

| Sub-sprint | PR | Result |
|---|---|---|
| 2.1 Businesses & staff | #21 | 229 tests on SQLite and MySQL 8; 28 browser checks incl. staff sign-in with a real authenticator code. Mobile-number invitations deferred to 2.3 (needs SMS) |
| 2.2 Packages & entitlements | #22 | 273 tests on SQLite and MySQL 8; 28 browser checks (admin now creates a package and visits feature rollouts). Found and fixed: `wurk:make-module` left the app unbootable until a manual autoload refresh; empty package description crashed |
| 2.3 Countries, providers & messaging | see PR | 330 tests; 28 browser checks. Driver requests checked against provider docs: Brevo's SMS endpoint changed in May 2025 (`/transactionalSMS/send`), so the driver uses the current one. Real sends still need sandbox keys. Payment gateways: credentials stored; live connection checks move to Sprint 3 with each integration. Delivery receipts: Twilio and Meta (signed); other SMS providers record "sent" until their callbacks are added |
