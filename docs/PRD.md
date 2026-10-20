# WURK — Product Requirements Document

| | |
|---|---|
| **Product** | WURK (wurk.co.za) — multi-tenant business platform |
| **Version** | 1.0 (draft for approval) |
| **Phase** | 1 — Product Requirements |
| **Launch market** | South Africa, then Botswana, Zimbabwe and other African countries |
| **Hosting** | South Africa (AWS Cape Town primary) |
| **Status** | Awaiting approval |

---

## 1. Vision

WURK lets any small business — a salon, dentist, spa, shop, tutor or tradesperson — launch a beautiful, fast, professional website with an online shop, bookings, CRM, marketing and a customer referral programme, and run all of it from their phone without technical help.

The ambition is to onboard **100,000 businesses within five years**, with an architecture proven to support **500,000 tenants**.

### 1.1 Product goals

1. **Wow-quality design** — every page, template and dashboard looks like a big-brand product.
2. **Self-service** — a non-technical owner goes live in under 30 minutes and rarely needs support.
3. **Growth built in** — every customer of every business is an affiliate, driving word-of-mouth growth for that business.
4. **Speed** — business websites load in under 1.5 s on 4G (hard ceiling 3 s).
5. **Trust** — strict tenant isolation, POPIA compliance, full audit trails.
6. **Extensibility** — new modules can be added for years without rework.

### 1.2 Success metrics (first 12 months after public launch)

| Metric | Target |
|---|---|
| Median time from signup to live website | < 30 minutes |
| Trial → paid conversion | ≥ 25% |
| Monthly logo churn | < 3% |
| Support tickets per 100 active tenants per month | < 5 |
| Public site Lighthouse performance (mobile) | ≥ 90 on every template |
| Platform availability | 99.9% |

---

## 2. Glossary

| Term | Meaning |
|---|---|
| **Tenant / Business** | A business that subscribes to WURK. Each has its own website, data and settings. |
| **Super admin** | WURK platform owner and WURK staff with platform-level roles. |
| **Agent** | A person who sells WURK packages and earns commission. Created by super admin in a sponsor hierarchy. |
| **Owner** | The business owner who subscribed. Full control of their tenant. |
| **Branch manager** | Tenant user restricted to one branch. |
| **Staff** | Tenant user with role-based permissions. |
| **Customer** | An end customer of a business (e.g. "Jack"). Automatically an affiliate of that business. |
| **WURK ID** | Single login identity for customers and owners across all businesses. Holds identity only, never business data. |
| **Package** | A subscription plan defined by super admin. |
| **Self-managed** | Package where the owner manages their own content. |
| **Managed** | Package where WURK staff make changes on the owner's behalf for an extra monthly fee. |
| **WURK Credits** | Single prepaid balance used for SMS, WhatsApp, email and AI usage. |
| **Wallet** | A customer's referral earnings balance with one specific business. |
| **Block** | A reusable designed website section (hero, services, gallery, etc.). |
| **Template** | A curated set of blocks and pages for a business category. |

---

## 3. Users and roles

### 3.1 Platform side

| Role | Capabilities |
|---|---|
| **Super admin** | Everything. Creates WURK staff roles. |
| **WURK finance staff** | Record cash payments, generate agent payout batches, view revenue. |
| **WURK support staff** | View tenants, impersonate with consent, manage tickets. |
| **WURK managed-services staff** | Edit managed tenants' sites and content on their behalf. |
| **Agent** | Agent dashboard: onboarded businesses, earnings, wallet, payouts, demo site. |

Super admin can create custom platform roles from granular permissions.

### 3.2 Tenant side

| Role | Capabilities |
|---|---|
| **Owner** | Full control of the business, all branches, billing and subscription. |
| **Branch manager** | Full control of one branch: merchant account, prices, stock, staff, calendar, orders, leads. Cannot see other branches. |
| **Staff** | Permissions set by owner (e.g. bookings only, record sales, manage products). |
| **Customer** | Customer portal: referral link, QR code, referrals, wallet, orders, bookings. |

Owners can create custom roles. Staff can be assigned to one or more branches.

---

## 4. Super admin platform

### 4.1 Dashboard (KPIs)

- MRR, ARR, net revenue, revenue by package and country
- New tenants, active tenants, trials, trial conversion rate
- Churn (logo and revenue), LTV, ARPU
- Failed payments and tenants in grace / suspended
- Agent commissions earned, pending, paid
- Credit revenue and credit usage by channel
- Tenant health: sites down, SSL issues, inactive owners (churn risk)
- Date-range filters, comparison to previous period, export

### 4.2 Packages

Super admin creates packages with:

- Name, description, display order, public/private visibility
- Type: **self-managed** or **managed**
- Monthly price and yearly price (with discount); VAT **15% added on top**
- Free trial: on/off and number of days
- Modules included (entitlements)
- Storage quota (MB/GB)
- Staff user limit
- Multi-branch: on/off and maximum branches
- Domain type: subdomain only, or custom domain allowed
- Monthly included WURK Credits
- Referral programme guardrails: maximum levels, maximum total commission %
- Managed-service fee (default; overridable per tenant)

### 4.3 Modules & entitlements

- Catalogue of all modules; enable/disable per package
- Per-tenant overrides (grant or revoke a module for one tenant)
- Feature flags (Laravel Pennant): percentage rollouts, per-tenant opt-in

### 4.4 Tenants (customers of WURK)

- List, search, filter by package, status, country, agent, health
- Tenant detail: profile, package, billing history, invoices, credits, usage, domain status, health, audit log
- Create a tenant manually and assign a package (e.g. walk-in customer paying cash)
- Change package, extend trial, override managed fee, suspend, reactivate
- **Login as business** — requires owner consent (consent request or standing consent setting), fully audit-logged, visible banner during session
- Data export and deletion requests

### 4.5 Billing operations

- Subscription payments by card, DebiCheck debit order, instant EFT, or cash at a WURK office
- **Cash payment flow:**
  1. WURK staff records cash payment against tenant with receipt/transaction reference
  2. OTP is sent to the recipients configured in **Security settings**
  3. Staff enters OTP to confirm; payment marked paid
  4. Receipt sent to recipients configured in Security settings and to the tenant
  5. Super admin notified ("Staff X recorded R… cash for Tenant Y"); full audit log entry
- VAT-compliant tax invoices (SARS) for all subscription and credit purchases
- Refunds and credit notes
- Dunning (see §11)

### 4.6 Agents

- Create agents and place them in a sponsor hierarchy
- Global commission percentages per level (default: self + 7 levels; number of levels configurable)
- Commission duration per agent: lifetime, or N months (e.g. 12 / 24)
- Commission applies to **subscription fees only** (not setup, managed fees or credits); calculated on the amount excluding VAT
- Clawback on refunds, chargebacks and cancellations
- **Payouts:** super admin generates an EFT batch file, pays via bank, uploads/marks the batch as paid → agent wallets update and agents are notified
- Agent banking details with change-verification (OTP) and audit log

### 4.7 Settings

- **Payments (WURK's own):** gateway(s) for subscription and credit collection; DebiCheck provider
- **Messaging providers:** SMS (Clickatell, BulkSMS, SMSPortal, Brevo, Twilio), email (Brevo or similar), WhatsApp Business Platform. Super admin enables one provider per channel; all tenants use it.
- **WURK Credits rate card** per country (e.g. 1 SMS = N credits) and credit pack pricing
- **AI credits** pricing; free first AI site generation
- **Security:** OTP recipients for cash payments, receipt recipients, admin IP allow-list, MFA enforcement for WURK staff, session policy
- **Default message templates** for every event (email, SMS, WhatsApp)
- Countries, currencies, tax rules, languages (South Africa enabled at launch)
- Template & category management (see §6)
- Platform announcements (shown in tenant dashboards)
- Dunning and retention timings

### 4.8 Support & operations

- Support ticket system (tenant ↔ WURK)
- Help centre content management (articles, 30-second videos, tooltips)
- Tenant health monitoring: uptime, SSL expiry, domain DNS status, inactivity, failed payments
- Credit usage by tenant and channel
- Managed-service request queue with internal time logging (no hours cap; time tracked for future pricing)
- Platform audit log: who did what, when, from which IP and device

---

## 5. Tenant onboarding (wurk.co.za)

### 5.1 Marketing website

Premium landing page, features, categories and template gallery with live previews, pricing (from packages), agent referral link support, FAQ, contact, legal pages.

### 5.2 Signup wizard

1. **Business details** — business name, first name, last name, email, phone, address
2. **Verify** — OTP to phone or email
3. **Package** — self-managed or managed; monthly or yearly
4. **Category** — e.g. salon, dental, spa, retail, fitness, professional services
5. **Template** — choose from the category's templates with live preview
6. **Address** — subdomain (`name.wurk.co.za`) with live availability check, or custom domain (if package allows) with uniqueness check
7. **AI site generation** — describe the business in a few words; logo upload; AI writes copy, selects imagery and derives brand colours (first generation free)
8. **Payment** — card, DebiCheck, or instant EFT (or skip if trial)
9. **Done** — land in the dashboard with the go-live checklist

Agent attribution is captured from the agent's referral link and stored on the tenant.

### 5.3 Custom domains

- Owner buys their own domain and adds the DNS records shown by WURK (CNAME, or A record for apex domains)
- Guided DNS checker with plain-language status
- SSL issued automatically (Cloudflare for SaaS)
- Domain purchase inside WURK: **post-beta**

### 5.4 Go-live checklist

Progress score with one-tap steps: add logo, set brand colour, review home page, add services/products, set opening hours, connect payment gateway, publish. Contextual help on each step.

---

## 6. Website builder & templates

### 6.1 Templates

- Categories, each with multiple templates (super admin managed)
- Templates are curated combinations of premium **blocks**
- Every template includes: home/landing, about, services, contact, booking page, shop pages (if enabled), legal pages (privacy, terms, cookies — auto-generated from category templates and editable)
- Owner can **switch template within the same category**; content carries over
- Beta target: ~6 categories × 2–3 templates each, all showcase quality

### 6.2 Visual editor

- Edit directly on the page: click text to type, click images to replace
- Drag-and-drop blocks from the block library; reorder, duplicate, hide on mobile/desktop
- Create unlimited custom pages (within storage quota)
- Menus: header, footer, mobile navigation
- Draft / publish with preview; version history with one-click restore
- Undo/redo

### 6.3 Branding

- Logo, favicon, brand colour (one or two), font pairing from a curated set
- System generates full palette with automatic contrast checking (owners cannot produce unreadable combinations)
- Light/dark site variants

### 6.4 SEO & AEO (automatic)

- Per-page titles and descriptions (AI-suggested), clean URLs, sitemaps, robots
- Structured data: LocalBusiness, Service, Product, FAQ, Review, Breadcrumb, Organization
- `llms.txt` and AI-readable business summary for answer engines
- One location page per branch
- Google Business Profile link-up and review collection
- Social sharing previews (Open Graph)

### 6.5 Media library

- Upload images, video and documents; automatic resizing and WebP/AVIF conversion
- **Storage quota enforced:** when the limit is reached, uploads are blocked and an upgrade prompt appears
- Usage meter always visible

### 6.6 Site status

- Live, suspended (neutral "temporarily unavailable" public page; renewal prompt only for the owner), or demo (watermarked, not indexed)
- "Powered by WURK" footer link (business-to-business referral)

---

## 7. Tenant modules

Each module is enabled per package. All modules respect branch scoping when multi-branch is on.

### 7.1 Dashboard

KPIs for today/week/month: sales, bookings, new leads, new customers, referral sales, wallet liability; go-live checklist; announcements; quick actions (record sale, add booking, share link).

### 7.2 Appointments

- Services with duration, price, buffer time, assigned staff, branch
- Availability per business, branch and staff member; opening hours, breaks, holidays, time off
- Public booking widget: choose branch → service → staff (optional) → time → details → pay deposit/full or pay in store
- Deposits / prepayment per service; cancellation and no-show rules
- Reminders and confirmations via WhatsApp, SMS and email (credits)
- Calendar views (day/week/staff), drag to reschedule
- Booking creates/links customer account automatically
- Google Calendar sync for staff: **post-beta**

### 7.3 Online shop

- Categories and sub-categories; products (physical, digital, services); variants (size, colour, etc.); SKUs; images; stock tracking per branch; low-stock alerts
- Per-branch price overrides, branch-only products, branch visibility
- Cart (locked to one branch), checkout, guest checkout creating a customer account
- Wallet spend at checkout (subject to business rules)
- Orders: statuses, fulfilment, delivery or collection, refunds
- **Payments:** the business connects its own merchant account(s) — PayFast, Paystack, Yoco, Ozow, Peach Payments (plus SnapScan, Zapper; expansion: M-Pesa, MTN MoMo, Flutterwave). Per-branch accounts with fallback to business-level account. **WURK never handles the business's money.**
- **Shipping:** The Courier Guy, Bob Go, Pargo, PostNet (business's own accounts); per-branch dispatch address; collection in store
- Coupons and offers

### 7.4 CRM

- Leads from website forms, bookings, chatbot, manual entry, import
- Pipelines with customisable stages; opportunities with value and expected close
- Activities: notes, tasks, calls, follow-up reminders
- Lead routing to branch; lead source tracking

### 7.5 Referral programme (customer MLM)

- **Every customer is automatically an affiliate** of the business they interact with
- Customer gets referral link and downloadable QR code per business
- Commission structure set by the business: **self-cashback + up to 7 levels by default**; number of levels and percentages configurable within package guardrails
- **Commission triggers on every financial transaction:** online orders, bookings paid online, and in-store payments recorded via **Record sale**
- Commission is **pending** until the payment is confirmed and the business's return window has passed, then **available**; refunds reverse it
- Attribution rule set by the business: **first referrer for life** or **last referrer**; referral link validity period configurable
- **Wallet rules set by the business:** payouts allowed or not, minimum payout, maximum % of an order payable by wallet, balance expiry
- Wallet balance usable **only at that business**
- Customer payout requests are approved and paid by the business from its own funds; the business marks payouts paid with a reference
- Reports: top referrers, referral sales vs commission cost, outstanding wallet liability

### 7.6 Record sale (in-store)

- Staff PWA screen: scan customer QR or enter phone → amount → payment method (cash/card/EFT) → optional link to booking → save
- Commission and cashback calculated instantly; customer receives WhatsApp/SMS confirmation
- Fraud controls: audit log with staff and device; manager approval above an owner-defined threshold; pending period

### 7.7 Marketing

- Campaigns via email, SMS, WhatsApp to segments (all customers, recent, inactive, top referrers, branch)
- Offers and coupon codes; scheduled sends; results (delivered, opened, clicked, redeemed)
- Consent and opt-out handled automatically (POPIA)

### 7.8 Message templates

- Default template for every event across email, SMS and WhatsApp (booking confirmed, reminder, order paid, shipped, commission earned, payout paid, etc.)
- Tenant can edit email/SMS content and From name; WhatsApp templates allow variable edits, full rewrites go through Meta approval
- Default sending from WURK's provider accounts; optional custom sending domain with guided DNS verification
- Shared WURK WhatsApp number by default; tenant's own WhatsApp number as a **premium add-on** (post-beta)

### 7.9 Chatbot

- Rule-based (no AI cost): answers from tenant-provided FAQs plus live data — services, prices, hours, branches, product availability
- Fallback: capture visitor details as a CRM lead or hand off to WhatsApp
- Built behind an interface so an AI chatbot can replace it later

### 7.10 Staff management

Staff users, roles, permissions, branch assignment, activity history. (Shifts, leave and staff commissions: not in scope.)

### 7.11 Reports

Sales, bookings (utilisation, peak hours, no-shows), customers (new vs returning, repeat rate, lifetime value), referral programme (ROI, top referrers, wallet liability), marketing, CRM (lead sources, conversion, response time), website analytics, stock, VAT collected. Per branch and combined. PDF/CSV export, scheduled email delivery, **weekly WhatsApp summary** to the owner.

### 7.12 Audit log

Every significant action: who, what, before/after, when, IP, device. Filterable and exportable.

### 7.13 Custom forms & fields (module builder v1)

Custom forms on the website, custom fields on customers, products, bookings and leads, custom data lists. **Workflow automation builder and full no-code module builder: post-beta.**

### 7.14 Subscription & invoices

- Current package, renewal date, credits balance, storage usage
- Upgrade/downgrade package or switch monthly ↔ yearly with **proration**
- Download all invoices; update card; set up DebiCheck
- Buy WURK Credits and add-ons
- Cannot change category; can switch template within category

### 7.15 Settings

Business profile, branches, branding, domain, payment gateways, shipping, referral programme rules, wallet rules, message templates, notifications, staff & roles, data export, backup & restore, feature updates (opt-in new features).

---

## 8. Multi-branch

- Enabled per package with a maximum branch count
- Every record carries a branch; owners see all branches with a switcher and an "All branches" combined view
- Branch managers see only their branch (enforced in the data layer)
- Per branch: merchant account, prices (overrides), stock, staff, hours, services, calendar, shipping origin, leads
- Website: branch selector (with nearest-branch suggestion); one location page per branch
- Wallet and referral programme are business-wide; **inter-branch settlement report** shows amounts owed between branches when wallet earned at one is spent at another

---

## 9. Customers and My WURK

- Customer account created automatically on booking, order, form submission or Record sale
- Login: **OTP** (SMS/WhatsApp/email) always available; tenant chooses whether new customers are passwordless or receive a default password they must change. If the customer already has a WURK ID, existing credentials are used.
- **Customer portal per business:** referral link, QR code, referrals and their activity, commissions (pending/available), wallet, payout requests, orders, bookings, place new order, book appointment
- **My WURK** (my.wurk.co.za): one login listing every business the customer belongs to, with one-tap sign-in to each. Business A never learns about Business B.
- **Owners with multiple businesses** use the same WURK ID with a business switcher.
- POPIA consent captured on account creation; data access and deletion requests supported.

---

## 10. Agents

### 10.1 Agent dashboard

Businesses onboarded (total, this month), active vs churned, earnings, wallet balance, paid out, pending; team (downline) view.

### 10.2 Agent menus

- **Customers:** businesses onboarded, onboarding date, package, last paid, status
- **Earnings:** per business, per period, level
- **Payouts:** batches, dates, references
- **Referral link** and marketing kit
- **Demo site:** one active demo at a time; auto-deleted after 14 days (reminders on day 10 and 13); agent may delete and recreate; converts to a live tenant on payment with content preserved; watermarked, not indexed, no payments or messaging
- **Settings:** password, MFA, banking details (OTP-verified changes)

---

## 11. Billing lifecycle

### 11.1 Dunning (defaults; configurable by super admin)

| Day | Action |
|---|---|
| 0 | Payment fails → owner notified (email, SMS, WhatsApp) with pay link: other card, instant EFT, cash at office |
| 3, 5, 7 | Automatic retries (debit orders on next permitted date) |
| 1–7 | Grace: full service, red banner in dashboard |
| 8–14 | Website live; back office restricted to Billing |
| 15 | Suspended: public sees neutral "temporarily unavailable" page; owner sees renewal prompt |

### 11.2 Retention after suspension

| Day | Action |
|---|---|
| 0–90 | All data kept; renewal restores instantly |
| 30, 60, 85 | Warnings with "download all your data" |
| 90 | Data permanently deleted; deletion logged |
| 120 | Backups containing the data purged |

### 11.3 Proration

Upgrades charge the prorated difference immediately. Downgrades and yearly → monthly changes take effect at the next renewal (credit applied where applicable).

---

## 12. WURK Credits

- One credit balance per tenant for SMS, WhatsApp, email and AI usage
- Rate card per country set by super admin
- Packages include a monthly allowance; top-ups purchasable anytime
- Low-balance alerts; usage history by channel
- Super admin sees usage per tenant and per channel

---

## 13. Self-service & support experience

- AI site generation at onboarding
- Go-live checklist with progress score
- In-app AI assistant that can answer **and act** ("add a Saturday slot", "create a 10% December offer") — uses credits beyond a free monthly allowance
- Contextual tooltips and 30-second videos on every screen; searchable help centre
- Plain-language errors and actionable empty states
- Self-service backup, restore and full data export
- Support tickets from inside the dashboard

---

## 14. Updates and feature rollout

- Database changes are backward-compatible and roll out to all tenants automatically and invisibly
- New features and design updates are **opt-in per tenant** ("Preview / Switch on") for a window of 90 days, then become the default
- Security fixes are mandatory and automatic
- Managed tenants' customisations live in an isolated extension layer and survive every update

---

## 15. Non-functional requirements

### 15.1 Design

See [design standards](architecture/design-standards.md). Every screen must meet the big-brand visual standard, be mobile-first, support dark mode for back offices, and pass design review before acceptance.

### 15.2 Performance

- Public sites: LCP < 1.5 s on 4G (ceiling 3 s); Lighthouse mobile ≥ 90
- Back office: interactions < 200 ms perceived; API p95 < 300 ms
- Works well on low-end Android devices and low-data connections

### 15.3 Scale

- Architecture supports 500,000 tenants; 100,000 in five years
- Horizontal scaling of application tier; sharded shared databases; dedicated databases for managed tenants
- Fair queuing: no tenant's workload delays another's critical notifications

### 15.4 Availability & recovery

- 99.9% availability target
- Point-in-time database recovery; tested restores; offsite backup copy in a second region
- RPO ≤ 15 minutes, RTO ≤ 4 hours (platform-wide incident)

### 15.5 Security

- Strict tenant isolation enforced in the data layer and covered by automated tests
- MFA for WURK staff (mandatory) and owners (optional, encouraged)
- RBAC with tenant and branch scoping
- CSRF, XSS, SQL injection protections; CSP; rate limiting; secure uploads with type and size validation
- Encryption in transit and at rest; secrets in a managed vault
- Full audit logging; impersonation only with consent
- Annual penetration test; dependency scanning in CI

### 15.6 Compliance

- POPIA: consent, purpose limitation, access/correction/deletion requests, defined retention, data hosted in South Africa
- SARS-compliant VAT invoices
- Consumer Protection Act: referral programme guardrails; WURK terms make each business responsible for operating its referral programme lawfully

### 15.7 Accessibility

WCAG 2.2 AA for back offices and all templates.

### 15.8 Internationalisation

Per-country currency, tax rules, languages, phone formats, payment providers and messaging rates — built in from day one, South Africa enabled at launch.

---

## 16. Integrations summary

| Category | Providers |
|---|---|
| Business payments | PayFast, Paystack, Yoco, Ozow, Peach Payments, SnapScan, Zapper; later M-Pesa, MTN MoMo, Flutterwave |
| WURK subscription billing | Card gateway + DebiCheck provider (selected in Phase 2) |
| Shipping | The Courier Guy, Bob Go, Pargo, PostNet |
| SMS | Clickatell, BulkSMS, SMSPortal, Brevo, Twilio (one active, chosen by super admin) |
| Email | Brevo or equivalent |
| WhatsApp | WhatsApp Business Platform (shared WURK number; tenant numbers post-beta) |
| Domains & SSL | Cloudflare for SaaS |
| AI | Provider-agnostic layer |
| Maps / reviews | Google Maps, Google Business Profile |

---

## 17. Scope: beta vs later

### In closed beta

Everything in §4–§14 except the items below.

### Post-beta

- Workflow automation builder; full no-code module builder
- Tenant's own WhatsApp number
- Domain purchase inside WURK
- Google Calendar sync
- AI chatbot (replacing rule-based)
- Additional countries
- Native mobile apps (React Native)
- Staff shifts, leave and commissions
- Accounting integrations (Sage, Xero)

---

## 18. Acceptance criteria (beta)

1. A new owner can sign up, generate a site with AI, pay, and publish on a subdomain in under 30 minutes without help.
2. A custom domain connects with automatic SSL using only on-screen guidance.
3. Every template scores ≥ 90 Lighthouse mobile performance and passes design review.
4. A customer can book, pay a deposit, receive WhatsApp confirmation and reminder, and appear in the CRM.
5. A customer can buy from the shop using the business's own gateway; money settles directly to the business.
6. Referral commissions calculate correctly for self + 7 levels across online orders, paid bookings and Record sale; refunds reverse them.
7. A customer belonging to multiple businesses sees them all in My WURK and can sign into each; no data leaks between businesses (verified by automated isolation tests).
8. Branch managers can manage only their branch; per-branch merchant accounts route payments correctly.
9. Agents receive correct commissions through the sponsor hierarchy; clawbacks apply; EFT batch payouts update wallets and notify agents.
10. Failed subscription payments follow the dunning schedule; suspension and reactivation work end to end.
11. Cash payments require OTP confirmation and produce receipts and audit entries.
12. Load tests demonstrate the target scale; disaster-recovery restore drill succeeds; penetration test has no open critical/high findings.

---

## 19. Risks, assumptions and dependencies

| Item | Type | Mitigation / note |
|---|---|---|
| Referral programme legality per tenant | Risk | Legal opinion before launch; package guardrails; tenant responsibility in terms |
| WhatsApp template approval delays | Risk | Submit default templates early (Sprint 2); SMS fallback |
| DebiCheck provider onboarding time | Dependency | Start provider application in Sprint 0 |
| AWS service availability in af-south-1 | Assumption | Verify every service in Phase 2 |
| Template design quality | Risk | Dedicated senior visual designer; design gate per sprint |
| Scope growth (module builder) | Risk | Phased: forms/fields in beta, builders post-beta |
| IP ownership between partners | Dependency | Written agreement on codebase and brand ownership early |
| Commission on subscriptions calculated ex-VAT | Assumption | Confirm with WURK |

---

## 20. Approval

| Role | Name | Decision | Date |
|---|---|---|---|
| WURK product owner | | | |
| Technical lead | | | |
