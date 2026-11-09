# Changelog

All notable changes to WURK are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Added — Sprint 15.3b (coupons)
- **Customers → Coupons**: amount or % off, for the shop and/or booking deposits; minimum spend, last day, total uses, once per customer (by a one-way key from their phone), optionally for a campaign; switch off.
- **At the shop**: a coupon field in the cart, quoted by the same pricing checkout uses; off the goods, never delivery; **commission on what was charged**; given back when an order is cancelled or never paid.
- **On booking deposits and classes**: the stored deposit is what's due; a code covering it all confirms the booking at once; given back on cancellation, expiry, or if payment can't start.
- **Campaign results** show coupon redemptions and the sales they brought. New audience **"Your top referrers"** while the referral programme is on (Platform `AudienceSegments`; Referrals registers it).

### Added — Sprint 15.3a (campaigns)
- **Customers → Campaigns** (`campaigns.manage`; packages with *Campaigns & offers*): **email** (free) or **SMS** (credits) to customers **who agreed to marketing** — everyone, seen in the last 30 days, not seen in 60+ days, or one branch.
- **Credit estimate before sending** (the balance, capped by any spending limit); refused when it won't cover it. **Test send** to yourself; **send now or schedule**. `{first_name}` in the subject and message.
- **Opt-out on every message** (one click; consent withdrawn in CRM, logged); email `List-Unsubscribe`. **Results**: sent, failed, opened (email, indicative), clicked — links tracked through the business's site and signed (no open redirect).
- **Ready-made**: "We miss you" (60+ days) and "Thank you" (recent), with suggested wording.
- CRM: `Audiences` contract.

### Added — Sprint 15.2 (website analytics)
- **Reports → Website**: visitors, page views, top pages, where visitors came from (Google, Facebook, Instagram, WhatsApp, a campaign's `utm_source`, or Direct), and the orders and bookings each source brought.
- **No cookies, no script, no consent banner**: counted on WURK's servers (pages revalidate, so revisits count too); visitors are a one-way hash with a random daily secret kept two days — no IP stored, not traceable across days; bots, link previews and prefetches skipped.
- Only orders and bookings made on the public site are credited to a source.

### Added — Sprint 15.1b (more reports, export, summaries)
- **Takings and VAT** (first section): everything taken — shop, bookings, in-store sales — after refunds; **VAT** (included in prices, at the country's rate) **only once the business says it's VAT-registered** (**Settings → VAT registration**, owners).
- **Leads**: new leads by source, won of those closed, median first response. **Stock**: out of stock, lowest stock, didn't sell in the period (shown as "right now").
- **Download** any report as **CSV** or **PDF**.
- **Owners' summary email**: weekly (Mondays) by default, monthly (the 1st) or off — from 07:00 in the business's time, once per period (`reports:send-summaries`, hourly). By email until the WhatsApp template is approved (decision D).

### Added — Sprint 15.1a (business reports)
- **Reports → Overview** (`reports.view` — owners and branch managers; managers see only their branches): this month, last 7/30 days, this quarter or year, **compared with the period before**, per branch or combined, with **plain-language highlights** at the top.
- **Shop sales**: after refunds, orders, average order, by day, best sellers, payment methods.
- **Bookings**: bookings, value (from service prices), no-show rate, busiest times, outcomes.
- **Customers**: seen, new vs returning, repeat rate, average lifetime value, and customers not seen in 60 days.
- Each module supplies its own section through Platform's `BusinessReports` contract; CRM records what each purchase was paid (from the purchase notices) for lifetime value.

## [0.16.0] — 2026-11-09 — Sprint 14: Agents

WURK's agents: the sponsor hierarchy, referral links and attribution, commission on subscription payments (up to 7 levels, percentages set by the super admin), WURK's refund window and clawback, EFT payout batches with remittance advice, banking details, the agent area (dashboard, businesses, earnings, team, payouts, marketing kit), and demo sites with "Make it yours". CI: MySQL tests in five parts. Release steps: docs/deployment/docker.md (v0.16.0 rows).

### Added — Sprint 14.8b ("Make it yours")
- **Claim link** for an agent's demo (signed; works until the demo expires): on the demo's banner and the agent's Demo page (copy, **send on WhatsApp**).
- **Signup for that demo**: name and address filled in, no design step; the prospect may keep the demo's address or choose another.
- **Completing it turns the same business into theirs** — pages kept, demo restrictions lifted, they become the owner — then the agent's access ends and the business is credited to them (source `demo`). Damaged or expired links get the normal signup with a plain message.

### Added — Sprint 14.8a (agents' demo sites)
- **Agent area → Demo site**: a real business marked as a demo, built from one of the industry templates and published at once, with the agent as owner; one active per agent; edit it in the back office, show a QR code, delete and remake.
- **On a demo**: a "Demo site made by a WURK agent" banner; `noindex` (header and meta); **payments** (methods, checkout, deposits, EFT details) and **outgoing messages** (SMS, WhatsApp, customer emails) switched off with a plain reason.
- **Expiry**: reminders to the agent on days 10 and 13; archived on day 14 (`agents:sweep-demos`, daily 03:15) — offline, address freed, hidden. Archived demos' data is purged permanently in Sprint 18.
- **Admin → Businesses**: demos labelled and filterable; archived businesses hidden unless "Archived" is chosen.
- **Release note:** demos need the industry templates installed (`website:install-templates`), as production setup already does.

### Added — Sprint 14.6b (agent area: earnings, team, payouts, marketing kit)
- **Earnings**: every commission line (date, business, level, amount, payable date; refunds shown) for a period, and as CSV for the agent's records.
- **Team**: the downline by level (up to 7) — names, businesses onboarded, suspended — counts only, never others' earnings.
- **Payouts**: the agent's payouts with bank reference, masked account and status (being paid · paid · bounced); cancelled batches hidden.
- **Marketing kit**: link (copy), QR download, a ready message with **Share on WhatsApp** and copy.
- CI: Agents' tests moved to MySQL part 4 (part 2 reached 8m15s).

### Added — Sprint 14.6a (agent dashboard and My businesses)
- **Agent dashboard** (`app.wurk.co.za/agent`): businesses onboarded (total, this month); active, at risk (payment overdue) and churned; commission available, pending and paid out; earned this month; **next payout** — in the next batch or why not, by finance's own rules; what becomes payable in the next 30 days; 12 months of earnings (bars, with a table for screen readers).
- **My businesses**: package, status (trial · new — first payment due · active · at risk · churned), monthly subscription and the commission earned from it; at-risk first. Agents see only their own.

### Added — Sprint 14.5b (settling agent payout batches)
- **Mark paid** (once the bank has paid; locks the batch): each agent is emailed a **remittance advice** — amount, bank reference, the account it went into (masked), and the businesses it covers since their previous payout.
- **Mark bounced** on one payment (in a paid batch, once): the money returns to the agent's balance and they're emailed a link to check their banking details. Both actions logged.

### Added — Sprint 14.5a (agent payout batches)
- **Admin → Agent payouts** (`agents.pay` — Finance and the super admin): a minimum payout (none until set); a **preview** of who's included, **held** (banking details changed in the last 7 days) and skipped, with reasons (no banking details, below the minimum, suspended, owes WURK).
- **Making a batch** reserves each amount in the agent's ledger at once, so nothing is batched or paid twice; reference `WURK-AG-YYYYMMDD-NNN`.
- **EFT CSV** (holder, bank, branch code, account type, account number, amount, reference): account numbers decrypted only for the file; refused if an agent's banking details changed after the batch was made; spreadsheet-formula safe; not cached; every download logged. **Cancel** an unpaid batch to release the reservations.

### Added — Sprint 14.7 (agent banking details; the agent area begins)
- **Agent area** at `app.wurk.co.za/agent` (agents only; `agent` is now a reserved handle): their link and commission; the full dashboard follows in 14.6.
- **Banking details**: a change is confirmed with a code to the agent's phone (email if none) before it's saved, and held encrypted in the session meanwhile; account numbers encrypted at rest, shown masked, never logged; the change date is kept so a payout can be held after a recent change (14.5).
- `php artisan agents:create <contact>` makes someone an agent (as Admin → Agents does) — used by the test setup.

### Added — Sprint 14.4 (WURK's refund window and clawback)
- **Admin → Billing settings: refund window** (default 30 days; 0 = no refunds). Credit notes are refused once a payment's window has closed; agent commission on a payment becomes payable when it closes (at once with 0).
- **Refunds within the window** reverse agent commission in proportion — cumulatively, so a full refund lands exactly on zero (before anything is paid).
- **Record a chargeback** (Admin → business billing, paid card payments; WURK has no dispute feed): reverses that payment's commission in full; if it was already paid out, the agent owes WURK — shown on Admin → Agents and flagged after 90 days, recovered from future commission (decision C).

### Added — Sprint 14.3 (agent commission)
- **Commission** on each paid WURK subscription (excluding VAT; not credits): the business's agent earns "self", each sponsor up earns their level — at the percentages WURK's super admin sets in **Admin → Agent commission** (self plus up to 7 levels; 0% until set; logged; finance can view). Each agent earns only within their own commission period; suspended agents are skipped without shifting levels. Append-only ledger, once per payment; pending until refunds close (30 days — a setting in 14.4). **Admin → Agents** shows each agent's available, pending and paid-out commission. Billing gains a `SubscriptionPayments` contract.

### Added — Sprint 14.2 (agent attribution)
- **Agent links** `wurk.co.za/a/CODE` (and QR codes in Admin → Agents): remember an active agent for 90 days across wurk.co.za and its sub-addresses (a cookie holding only the public code); first link wins; suspended agents' links do nothing.
- A business **created during signup** is credited to that agent — never businesses created by staff in Admin.
- **Admin → Business → Agent**: see and correct who brought a business (logged; finance can look). Admin → Agents shows each agent's link, QR and business count.

### Added — Sprint 14.1 (agents and the sponsor hierarchy)
- **Admin → Agents** (new Agents module; `agents.view` / `agents.manage` — finance and support can view): add agents (their WURK ID, a 7-character referral code), place and move them under a sponsor (loops refused), suspend them, and set commission for life or N months from each business's start; direct and whole-team counts. Every change in the platform audit log.

## [0.15.0] — 2026-11-08 — Sprint 13: Referral engine

Referral programme rules, links and QR codes, commission (pending then available after the business's return window), wallets, Record sale, the customer portal (sign-in by code or password, POPIA consent, data copies and deletion), payouts, My WURK, referral reports and branch settlements for wallets. Release steps: see *Version-specific release steps* in docs/deployment/docker.md (v0.15.0 rows).

### Added — Sprint 13.6c (spending the wallet)
- **Wallet at shop checkout and on booking and class deposits**, for customers signed in to the business's portal: "Use R… from your wallet" (bookings: "up to", as the form knows the price, not the deposit). The site's checkout has no session, so the portal issues a **signed wallet pass** (bound to the business and customer; 15 minutes) that the server checks before spending.
- Card payments ask for and check **only what's due**; ledgers record only cash received; paid wholly from the wallet is paid at once. **Rewards on the full amount** (decision E).
- **Refunds** return the wallet part to the wallet and the rest to the card or account (decision F) — in proportion, exact on the last refund. Unpaid orders and holds that expire, or that can't be paid, give the wallet part back. A wallet problem can never stop a form working.

### Added — Sprint 13.9b (inter-branch settlement for wallets)
- When a customer spends rewards at a different branch from where they were earned, the branch ledger (11.5) records what the earning branch owes the spending branch. Spends use the oldest rewards first; same-branch parts (and single-branch businesses) record nothing; give-backs reverse in proportion, the last exactly.
- `StoreCredit::spend()` takes the branch the money is spent at (Shop: the order's; Bookings: the booking's or class session's).

### Added — Sprint 13.9a (referral reports and home tiles)
- **Customers → Referral reports** (`referrals.manage`; packages with referrals), for this month, the last 30 days, this year or all time: referral sales vs commission cost by month (own-purchase vs referral rewards, cost as a share of sales), top referrers, wallet liability now (in wallets, on its way, payouts waiting, expiring in 30 days), payouts paid and declined.
- **Business home tiles** through a new Platform `DashboardTiles` contract (any module can add tiles): *Referral sales (this month)* and *Wallet liability*, for people who manage referrals at businesses whose package has them.
- **Fixed during the build:** report periods are in the time zone WURK stores times in (converting to UTC dropped the last two hours and shifted month boundaries).

### Added — Sprint 13.8 (My WURK)
- **`my.wurk.co.za`** (`WURK_MY_DOMAIN`; its own surface and session policy): customers sign in with their WURK ID (code or their own password — never creates one) and see one card per business they have an account with — wallet, rewards on the way, latest visit — each built from that business alone.
- **Open** goes to that business's portal already signed in (a one-time pass: signed, one business and person, 60 seconds, once). **Remove from My WURK** hides a card.
- A central index holds only which businesses a WURK ID has an account with (added on portal sign-in, removed on an approved deletion).
- **Fixed:** every `{handle}` route (business sites, shop, bookings, referral links, portal) now refuses reserved handles — `my.{root}` had been matched as a business site.
- **Release steps:** DNS for `my.wurk.co.za`; run `php artisan portal:index-memberships` once after migrating.

### Added — Sprint 13.6b (customer passwords)
- **Settings → Customer accounts** (`contacts.manage`): customers sign in with a code only (default), or a code or a password; send someone their access by email or SMS (uses SMS credits).
- **Default passwords** (decision J): emailed automatically to new customers with an email when passwords are on; generated by WURK, never shown to staff, hashed, single-use, valid 7 days; kept per business, never on the WURK ID, and never issued to someone whose WURK ID has its own password. The first sign-in goes straight to choosing their own (WURK's password rules). Repeated wrong attempts are throttled.
- Identity's `Accounts` contract gains `hasPassword`, `checkPassword` (throttled like staff sign-in), `setPassword` and `passwordRules`.

### Added — Sprint 13.7 (payouts)
- **Portal → Payouts** (when the business allows them): ask for an amount between the business's minimum and the wallet, with bank details; the amount is reserved at once (can't also be spent at checkout); one open request.
- **Customers → Payouts** (`referrals.manage`): the business pays from its own bank and marks it paid with a reference, or declines with a reason (the amount returns to the wallet); the customer is emailed (wording in Settings → Messages).
- **Bank details:** account numbers encrypted at rest and deleted once the payout is decided (last four kept); never in logs. A deletion request waits for an outstanding payout.

### Added — Sprint 13.6e (POPIA data requests)
- **Portal → Your information:** download a full copy (details, all history including their own enquiries and chats, notes the business keeps about them, every reward and wallet movement, consent date); ask for deletion (explained first, confirmed; one open request; status shown).
- **Customers → Data requests** (`contacts.manage`): the wallet is settled first (EFT or cash, recorded as a payout); approving emails the customer, then erases their details (and every record merged into them; enquiry and chat summaries blanked; orders and sales kept as the law requires), ends portal access, retires their referral link and forfeits pending rewards; declining needs a reason they're told. Both emails editable in Settings → Messages.
- **Audit:** customers' own portal actions are logged as "Customer" with their WURK ID only; deletion steps carry ids and amounts only — the log is a sealed chain, so it must never hold details a deletion would need to remove.

### Added — Sprint 13.6d (identity QR for Record sale)
- **"Show this at the till"** in the customer portal: a QR proving who the customer is — signed, for one business and customer, valid 5 minutes, refreshed every 4; only for the signed-in, consented customer.
- **Record sale → Customer's code** (scanner, paste, or the camera where the browser reads QR codes): once proven, no name or phone needed; the sale is linked to exactly that customer (receipt and referral rewards as usual). Altered, other businesses' and expired codes are refused.

### Added — Sprint 13.6a (customer sign-in and portal)
- **The customer portal** (new Portal module) at `{business}/account` on the business's own address, with its own host-only session (a new "portal" surface: 2 hours idle, 30 days, remember me). A session counts only for the business it was started at.
- **Sign in with a code** to phone or email (the same rate limits as signup); new customers give their name (asked before the code is checked, so it isn't spent); linked to their customer record, found as bookings and orders find it. **POPIA consent** once, before the portal opens.
- **The portal page:** wallet, pending rewards and expiry; their referral link (copy) and QR code; who they referred (first names only); their own history (orders, bookings, classes, in-store) — never internal entries. No back-office menu is ever sent to the portal.
- Not yet: businesses on their own custom domain.

### Added — Sprint 13.5 (Record sale)
- **Customers → Record sale** (new Sales module): an in-store sale (cash, card, EFT) linked to the customer — found by phone or email exactly as bookings and orders find them, or new — with branch and note; phone-first for the till.
- **Approval** above an owner-set amount (`sales.manage`): staff (`sales.record`) can't approve; managers (`sales.approve`) approve, decline and refund — branch managers only their branches. A sale earns rewards only once recorded or approved.
- A recorded sale is a **purchase** (rewards; final once the return window from the sale closes) with an **email receipt** (Settings → Messages; no SMS, which would spend credits on every sale). **Refunds** only within the window, capped at what was paid. Every action logged with who and which device.
- Finding customers by scanning a QR arrives with the customer portal (13.6).

### Added — Sprint 13.4b (wallets)
- **Wallets:** each customer's available rewards, less what's spent, paid out or expired (the same append-only ledger). Payments' **`StoreCredit`** contract lets Shop and Bookings use them without knowing about referrals (no credit by default).
- **Spending** within the balance and the business's share of an order (refused in plain words above it; one spend at a time); a **refund gives the wallet part back** (never more than spent). Spending at checkout and online booking payments is switched on in 13.6, once customers sign in.
- **Expiry** nightly (02:30): unused rewards expire oldest first, counting what's been spent; logged.
- **Settings → Referrals → Wallets:** the share of an order a wallet can pay (default 50%), expiry (default 12 months; 0 = never), payouts on/off and the minimum payout. **Customers → Referrals** shows each customer's wallet.

### Added — Sprint 13.4a (the return window)
- **Settings → Payments → Refunds and returns:** the business's return window (default 14 days; 0 = no refunds). **No refunds in WURK after it closes** — orders counted from payment (refunds and cancelling a paid order are refused, with the date it closed), bookings and class deposits from the appointment. Logged.
- **Referral rewards become available when the window closes** (the referral programme's own "available after" setting is replaced by it), so a refund never touches an available reward.

### Added — Sprint 13.3 (commission)
- **Rewards on purchases:** when a shop order is paid (card or EFT) or a booking or class deposit is paid online, the buyer earns their cashback and each referrer up the chain earns their level — on the items, not delivery; whole cents; within the package's current limits; once per purchase (resent payment notices can't pay twice).
- **Pending, then available** after the return window (derived from dates). **Refunds reverse** in proportion — a full refund exactly — without editing anything (append-only ledger); a reversal reduces pending or available, matching the reward it cancels.
- **Customers → Referrals** shows each customer's available and pending rewards. Shop and Bookings only announce purchases and refunds (CRM `PurchaseMade` / `PurchaseRefunded`); they know nothing about referrals.

### Added — Sprint 13.2 (referral links and attribution)
- **Referral links** `{business}/r/CODE` (7-character codes, no look-alike characters): remember the referrer in a first-party cookie for the programme's link validity, and land on any page of the same site.
- **Who referred whom:** when a referred visitor becomes a customer (booking, order, form, chat), the referrer is recorded — once (first referrer) or each time (last); self-referral, loops and other businesses' codes refused; logged. CRM now raises `ContactRemembered`.
- **Customers → Referrals:** each customer's code, link (copy) and QR code, who referred them and how many they referred; search by code.

### Added — Sprint 13.1 (referral programme rules)
- **Settings → Referrals** (new Referrals module; owners, `referrals.manage`; packages with referrals): run a programme (off by default) with a percentage back on customers' own purchases and up to the package's levels (at most 7) — the total never above the package's cap, refused in plain words otherwise; first or last referrer; how long a link counts; how long before rewards become available (refunds cancel them). Logged.
- **Architecture guard:** raw queries on business tables must filter by business or be on a reviewed list (catches the class of bug fixed in v0.14.1 and v0.14.2).

## [0.14.2] — 2026-11-03 — Hotfix: branch settlements shared between businesses on a shard

### Fixed
- **Team → Settlement between branches** (Sprint 11.5, since v0.13.0): a business's monthly statement included the settlement entries — amounts, references and notes — of **every** business on the same database shard. It now shows only that business's. Found by a scan of every raw database query while adding an automatic check for this kind of mistake; a new test puts two businesses on one shard (and fails without the fix).

## [0.14.1] — 2026-11-03 — Hotfix: settings shared between businesses on a shard

### Fixed
- **Settings → Shipping → Parcels**: saving deleted the boxes of **every** business on the same database shard; it now replaces only that business's boxes.
- **Courier prices** could use another business's boxes or default weight (same shard).
- **The "We got your message" auto-reply** switch could switch on for other businesses on the same shard.
- **Website → Chat**: "most used" counts included other businesses' taps.
- **`crm:link-existing`** (the v0.14.0 release step) reported a contact count for the whole shard; it now counts each business's own. A full review of the other raw queries found them safe (looked up by records that belong to the business).
- Cause: these tables were read and written with the query builder without filtering by business (Eloquent models filter automatically). New tests put two businesses on one shard and check each sees and changes only its own.

## [0.14.0] — 2026-11-03 — Sprint 12: CRM and messaging

**Release steps** (docs/deployment/docker.md): run **ClamAV** next to WURK and set `CLAMAV_HOST`; after migrating, run `php artisan crm:link-existing` once.

### Added — Sprint 12.6 (product weights and parcel sizes)
- **Weights and sizes** on products, in kg and cm (a variant can have its own); stored in grams and millimetres.
- **Settings → Shipping → Parcels**: the default weight for items without one (starts at 0.5 kg — nothing changes until a business adds weights) and the boxes the business ships in.
- **One parcel per order**: the smallest box that fits by volume, the largest item and weight; if none fits, the courier's size-based weight (L×W×H ÷ 5,000) and a warning on the order. The same parcel for the checkout quote, the re-check when placing the order, and booking — Bob Go is sent the box size (field names to confirm with the sandbox).
- **Products** shows how many products use the default weight, with a filter; the **order page** shows the box to pack it in.

### Added — Sprint 12.5 (message templates)
- **Settings → Messages** (owners; `messages.manage`): every message customers receive — bookings (confirmed, reminder, moved, cancelled) and orders (placed and its updates) — with each channel's wording, a live preview, placeholder buttons and an SMS length/parts counter.
- **Rewording email and SMS** (decision A; replaces the Sprint 8 fixed-wording agreement for these channels) within safety rails: known placeholders only, required ones (like the booking link) kept, plain text, SMS within two parts, a subject for emails; "Back to standard wording"; every change logged. Standard wording is unchanged until a business edits it.
- **WhatsApp stays fixed** (Meta-approved templates) and is shown as such.
- **Test sends** to the owner's own email (free) or phone (SMS credits, decision B).
- **"We got your message"** — an optional auto-reply email (off by default, decision C) for form replies and chat hand-offs; email only.
- Order emails: only the subject and opening line (placed) or sentence (updates) are worded; items, totals, payment details and links stay generated.
- Tests: page accessibility scans leave out the toast area (axe misjudges stacked toasts); a single toast is still checked.

### Added — Sprint 12.4 (the website chat)
- **A rule-based chat** on the business's website (off by default; every page when on; hidden on chosen pages). Ready-made answers from what the business published — prices, location and map, call, WhatsApp, and opening hours once confirmed — follow those details until the owner rewrites them.
- **Website → Chat**: greeting, fallback, quick buttons (up to 8) and keyword answers with links (the site's own pages, call, WhatsApp, map, email), a preview, and the most-used buttons.
- **Talk to someone**: a visitor's details and the conversation are stored only then, with consent (decision A) — a contact, a lead (a setting), and the owner emailed; reply times from confirmed hours (decision C). Spam field, per-visitor limit, daily cap per business. **Customers → Chats** lists them.
- **Accessible and light**: a labelled dialog (focus kept inside, Escape closes it from anywhere, answers announced), full screen on phones; answers matched on the visitor's device; the panel's code loads only on first use; anonymous counts of button taps only.
- Published pages' ETag includes the chat's state, so switching it reaches visitors at once.

### Added — Sprint 12.3b (file uploads on public forms)
- **"Attach a file"** questions on the Quote and Form blocks: JPEG, PNG, WebP or PDF, up to 3 files of 5 MB each.
- **Checked on arrival**: the real type is read from the file's contents; images are re-saved (hidden content, metadata and location dropped); PDFs with scripts, launch actions or attachments are refused; one bad file refuses the whole reply.
- **Malware scanning with ClamAV**: files are encrypted on a private disk and held ("Being checked") until scanned clean; infected files are deleted and logged; while the scanner is down files stay held and are retried.
- **Owner-only downloads** from Enquiries — clean files only, always saved (never opened in the browser), sandboxed, not cached, logged.
- Uploads count towards the package's storage; files of enquiries archived over 12 months are removed daily (logged; a setting).
- **Deployment (v0.14.0):** run ClamAV next to WURK — see "Malware scanning (ClamAV)" in docs/deployment/docker.md. CI scans the standard antivirus test file with a real ClamAV.
- HEIC is not accepted (it can't be re-saved safely here); iPhones send JPEG to these forms.

### Added — Sprint 12.3 (forms)
- **Form replies join the CRM**: every reply becomes (or joins) a contact; past enquiries via `crm:link-existing`. Quote forms open a lead per reply by default (a setting on the block), with the message and answers as its first note.
- **Optional "Send me news and offers"** on forms — unticked; marketing consent recorded only when ticked, with where.
- **Your own questions** on forms (short or long text, a choice, a date, yes/no; required or optional), stored with the enquiry and shown in the owner's email, the lead and Enquiries.
- **A general Form block** for enquiries, waiting lists and registrations of interest, sharing the Quote form's view, spam protection and limits; the message is optional.
- **Enquiries** show the form's kind, the answers, and links to the contact and lead (for those allowed to see them).
- CI: the MySQL parts rebalanced (Billing and Tenancy moved to part 3); every test still runs exactly once.

### Added — Sprint 12.2 (pipelines)
- **Leads** (Customers → Leads; `leads.view` / `leads.manage`): every business starts with a Sales pipeline (New → Contacted → Quoted → Won / Lost) whose stages can be renamed, added, reordered and removed (not while they still hold leads; one open stage always remains). Leads carry a value, source, assignee, branch and follow-up date; a board (moved with a stage choice, so it works by keyboard) and a list, open leads by default with won and lost on request; a lead page with its full history; New lead from a contact's page.
- **Follow-up reminders**: a daily email (07:00 South Africa) to the assignee — once per follow-up date, never for closed leads, with the contact's name only.
- Branch managers handle their branches' leads and leads assigned to them.

### Fixed
- Follow-up times were stored two hours off (found while completing an interrupted session's work).
- Removing a stage on the pipeline screen didn't remove it on the server.

### Added — Sprint 12.1 (contacts)
- **Contacts** (Customers → Contacts; new Crm module, `contacts.view` / `contacts.manage`): one contact per person per business, matched by exact phone or email (South African numbers written one way), built automatically from bookings, class places and orders, with their branch. Search by name, number or email; tags and notes; a contact's history; merge (nothing deleted) and split (exact undo); CSV export (audited, not cached). Branch managers see their branches' contacts.
- Marketing consent is recorded only when given, with where; patient records stay out of the CRM.
- **Release step (v0.14.0):** `php artisan crm:link-existing` once after migrating — makes contacts of existing customers (safe to run again). See docs/deployment/docker.md.

## [0.5.0 – 0.13.0] — Sprints 4–11

These releases' entries were not moved under their own version headings at the time; each version's exact contents are in its GitHub release (v0.5.0 … v0.13.0). Grouped here, newest first:

### Added — Sprint 11.6 (shipping)
- **Shipping** (new module; Settings → Shipping): the business's own **Bob Go** (courier delivery at live rates) and **Pargo** (pickup points, flat rate) accounts, for the business or a branch, test or live; credentials checked with the courier before saving. WURK never pays couriers.
- **Checkout**: "How should we deliver?" — the shop's own flat-fee delivery, a courier at live prices for the customer's address, or a Pargo pickup point near them; the courier's price is checked again when the order is placed.
- **Orders**: "Create shipment" saves the tracking number and label and sends the order out for delivery; customers see tracking on their order page; Bob Go's signed tracking updates mark delivered orders done.
- Provider request/response details and Bob Go's signature header are marked to confirm against Bob Go and Pargo sandbox accounts before go-live. Items count as 0.5 kg each until products have weights.

### Fixed
- The payments permission label listed only PayFast and Yoco.

### Added — Sprint 11.5 (settlement between branches)
- **What one branch owes another** is recorded when one branch's account is paid for something another branch supplied (card and EFT orders, booking deposits, class places; the business-wide account counts as the main branch's). Refunds and card cancellations net it off. Nothing is recorded within a branch.
- **Between branches** (Settings → Branches): monthly statements per pair of branches, with what makes up each amount; owners mark a month settled (who and when). Records only — no money moves through WURK. Branch managers see their branches only.

### Added — Sprint 11.4 (payments by branch)
- **A branch can have its own payment accounts** (PayFast, Yoco, Ozow, Peach, EFT — test or live, keys per mode), set by the owner under Settings → Payments ("Accounts for"); without one, the branch uses the business's.
- Payments go through the branch they belong to (shop orders: the fulfilling branch; deposits: the booking's; classes: the session's), and are verified and refunded through the account used. Providers' notifications say which branch (each account's own signature and secrets).

### Added — Sprint 11.3 (shop by branch)
- **Stock per branch** (the product's stock is the total, so pages and "sold out" work as before); existing stock moved to each business's main branch.
- **Orders fulfilled by a branch**: collection from the branch the customer chooses (checkout asks where, and re-prices for that branch), delivery from a "ships from" branch. Never oversold per branch; cancelled, expired and restocked items go back to their branch.
- **Optional prices per branch** (product editor), used by the cart, checkout and orders.
- **Orders get their own permission** (`shop.orders`): branch managers see and handle only their branches' orders, and are alerted only about those; products, prices and shop settings stay business-wide (`shop.manage`).

### Fixed
- A shop that never saved its shop settings couldn't take an order (no order number).
- A single-branch shop's stock total was reset to 0 when saving a product (caught by the browser tests during this sprint; regression test).

### Added — Sprint 11.2 (bookings by branch)
- **Bookings, classes, reservations and team hours belong to a branch** (existing rows moved to each business's main branch; anything created without a branch goes there too — single-branch businesses see no change).
- **Hours per branch** (someone can work Mondays at one branch and Tuesdays at another; never two branches at once); a person's booked time is shared across branches, so no double bookings.
- **Services offered at chosen branches**, with an optional **price per branch** (deposits use it).
- **Customers choose a branch first** on the website (several branches only), then see that branch's services, prices and times.
- **Branch managers run their branch**: bookings, hours, time off, classes and tables at their branches only. A new permission, `bookings.settings`, covers business-wide booking settings (services and prices, policy, messages, meeting links, health practice, table settings); `bookings.manage` is now day-to-day only.

### Added — Sprint 11.1 (branches and team)
- **Branches** (Settings → Branches): address, phone, close and reopen (history kept; the main branch stays open), within the package's limit (`multi_branch` / `max_branches`, now enforced). Other modules read and manage branches through Tenancy's new `BranchDirectory` contract.
- **Team** (Settings → Team) — owners couldn't manage their team before: members with their role and branches; invite by email with a role (seat limit checked), cancel invitations; change roles; limit people to branches (a branch manager always has particular branches); remove someone (their history is kept). Owners can't change or remove themselves, and the business never loses its last owner.

### Fixed
- Branch's fillable list silently dropped new fields (closing a branch, address and phone).

### Added — Sprint 9.5 (patient documents)
- **Patient records** for health practices (new Records module; permissions `records.view` and `records.manage`): patients, documents and corrections (each correction says what changed; the original is kept), the private link, an access log in plain words, and a "due for removal" list.
- **Encrypted at rest** on a private disk, separate from website media; never public; every upload, correction, view, download, link, code and removal logged.
- **Kept 6 years** from the patient's most recent document (a setting): removal is refused until then; afterwards patients are flagged daily (via a central list — no full scan) and the owner may remove them (logged). Nothing is deleted automatically.
- **The patient's side**: a private link (only its hash stored; no health details in the email), then a one-time code by email or SMS (5 tries, 10 minutes; keyed hash), then a 30-minute encrypted session scoped to the link, showing only their own documents.

### Fixed
- **Rate limits per business**: unnamed throttles on public routes shared one counter per IP across every business and route — patient page loads used up the code limit, and providers' payment notifications for all businesses shared one limit. Now named limiters per business (and link/visitor, or provider), with regression tests.
- Records' dates are DATETIME (MySQL's TIMESTAMP ends on 19 January 2038).

### Added — Sprint 10.6 (payment methods)
- **Five methods**: PayFast, Yoco, **Ozow** (instant EFT), **Peach Payments** (cards) and **EFT / bank transfer**.
- **Businesses** (Settings → Payments): each method on or off, **test or live**, with **test and live keys kept separately** (live only once live keys are saved; keys encrypted, never shown again). Customers choose from the business's enabled methods; deposits and class payments use its card methods. EFT moved here from Shop settings.
- **Super admin** (Payment methods): which methods businesses may offer (a method switched off disappears from every business), and **WURK's own accounts** for businesses paying WURK — the Billing page offers "Pay with…" and WURK's EFT details. Automatic monthly card charges and DebiCheck are unchanged.
- One driver per provider, shared by businesses and WURK; every payment confirmed with the provider before it counts (Ozow: hash + Ozow's API; Peach: signed notification + status read back).

### Fixed
- Notification fields are read exactly as sent (PHP's form parser turned Peach's dotted names into underscores, which would have failed every Peach signature).
- WURK's payment routes are registered before businesses' (the business address pattern also matched WURK's app address); "pay" and "payments" reserved as business handles.

### Added — Sprint 9.4 (health consultations online)
- **Health practice** (Booking settings): a switch, and each practitioner's **registration** (council and number, e.g. HPCSA MP0123456).
- Online services and online classes at a health practice need **every practitioner registered**; health mode can't be switched on while any isn't, and a registration can't be removed from someone who sees patients online.
- **Consent before an online consultation**: fixed, versioned wording (a working default for WURK's lawyers to confirm); bookings and class places without it are refused; what was agreed and when is recorded and never rewritten.
- Registrations shown in the booking and class widgets, the confirmation, and the patient's own page (with when they agreed).

### Added — Sprint 10.4 (orders and refunds)
- **Orders** (Shop → Orders): filters with counts (to do, awaiting payment, ready / on its way, done, cancelled); each order with items and totals, customer, collection or delivery, notes, **only the next steps that make sense**, and a **timeline** of every change (what, who, when).
- **The journey**: paid → packed → ready for collection / out for delivery → done. Owners mark EFT orders paid; card orders are marked paid only by the verified payment.
- **Cancel** before it leaves (stock back; card payments refunded in full) and **refund** all or part (card refunds through the provider, never more than what's left; optional restock; EFT refunds recorded).
- **New-order alert** to everyone who can manage the shop (Access: `TeamDirectory::emailsWithPermission`), and **customer emails** as the order moves on (paid, ready, on its way, cancelled, refunded).

### Added — Sprint 10.3 (card payments into the business's own account)
- **Payments module** (every package): each business connects **its own Yoco** (secret key tested on the spot by registering our webhook) **or PayFast** account (passphrase required) under **Settings → Payments**. Keys are encrypted and never shown again; owners see a masked label and a clear test-mode badge. New permission `payments.manage`.
- **Verified payments only**: Yoco webhooks must be correctly signed and recent, and the checkout status is read back from Yoco; a PayFast notification counts only if its signature, PayFast's own validation ("VALID"), status and exact amount all check out.
- **Card payment at checkout** (card or EFT when both are on): stock held for 15 minutes while paying; confirmed only after verification (notification or return); stock back at once if payment can't start; late payments refunded automatically.
- **Deposits and class payments** now use the business's own PayFast or Yoco account too — the Paystack subaccounts WURK set up in Sprints 8–9 are retired, so WURK is never in any customer payment.

### Fixed
- PayFast's signed fields are kept in order (stored as text: MySQL's JSON type reorders keys).
- Tests no longer leave temporary shard databases and folders behind (they had filled the disk).

### Added — Sprint 10.2 (cart and checkout)
- **Add to cart** on product pages (sold-out options disabled; "Ask about this product" without JavaScript), and **the cart and checkout at `/cart`**: quantities, collection or delivery with a live total, details and consent, then the customer's order page. The cart lives in the shopper's browser; **the server re-checks every price and stock level**.
- **Never oversold**: each variant is locked and its stock taken off in the same transaction as the order — or nothing is saved. Order numbers per business (#1001…); names and prices copied into the order.
- **Paying by EFT or on collection** (optional, per business, agreed with WURK): customers see the business's instructions with their order number as the reference; unpaid orders release their stock after the hold time (default 72 hours). Card payments follow in 10.3.
- **Shop settings**: collection, delivery fee and areas, EFT/pay-on-collection instructions and hold time. **Order confirmation email** to the customer.
- Demo shops take orders; the full purchase is tested in the browser on desktop and phone; product pages still score 98–100.

### Fixed
- **Cart pages are kept per business**: they were stored under one fixed id, but that id is unique across a whole shard — so only one business per shared shard could ever have had a cart. Now keyed by the business's own id, tested with two businesses on one shard. Found when CI's fresh setup failed (the two retail demos share a shard).

### Changed — online appointments use pasted meeting links (agreed with WURK)
- **Meeting links per team member**: each person's own Google Meet or Zoom link, plus an optional default for the whole business — simpler than connecting accounts. Appointments use the practitioner's link; classes the instructor's; anyone without one, the default. No calls to Google or Zoom are needed.
- An online service needs everyone who does it to be reachable (their link, or a default); an online class needs its instructor's link (or a default).
- Settings → Meeting links, with a waiting-room tip. Account connections (Sprint 9.1) stay in the code, shown only if WURK enables them.

### Added — Sprint 10.1 (catalogue)
- **Shop module** (Growth and Pro): products with up to three options (e.g. Size, Colour) and variants — each with its own price, "was" price, SKU and stock (empty = not tracked); categories; unique slugs; variant ids kept when editing.
- **Product photos** from the business's one photo library (Website's `MediaLibrary` contract) — shop managers needn't edit the website.
- **Products section** for the website, rendered at publish through Website's `ProductCatalog` contract (Shop implements it; Website never depends on Shop): "from" and "was" prices, sold out; hidden when there's nothing to sell; a prompt in the editor; samples in template previews.
- **Product pages** at `/shop/{slug}`: the site's header and footer around the product, rendered ahead of time and served through the same shell as every page; rebuilt when products, the home page, branding or menus change; removed when unpublished.
- Only while the package includes the shop (downgrades hide grids and product pages).
- Demo shops (`shop:demo`); "New in" products on the retail templates; the performance gate also measures product pages (`--only` for local checks) — all 100.

### Added — Sprint 9.3b (online payment for classes)
- **Paid online when booking**: a class's price goes straight to the business's Paystack account. The place is **held while paying (and counts toward capacity)**, confirmed only once Paystack verifies the payment, and released after 15 minutes if unpaid; late payments are refunded automatically.
- **Refunds**: leaving in time refunds (cancellation policy); when the business cancels a class, everyone who paid is refunded.
- Owners tick "Paid online when booking" when scheduling (needs a connected bank and a price); paid classes are marked.

### Fixed
- `class_attendees.status` widened to 20 characters — `pending_payment` (15) would have been rejected by MySQL (SQLite ignores lengths). Caught by the MySQL test run.

### Added — Sprint 9.3 (group classes)
- **Classes**: scheduled sessions with a capacity, in person or online, an instructor and a price (paid at the class for now). **No overbooking** — the session is locked while places are counted.
- **One shared video link per online class** (the business's Google, Zoom or fixed link), in every attendee's email and on their page from 15 minutes before.
- **Book online in class mode**: upcoming classes with places left (full ones shown, not selectable); confirmation with a change-or-cancel link. The class timetable section can link to booking.
- **Owners**: Bookings → Classes — schedule, see who's coming, add someone by hand (capacity still holds), mark attended or no-show, cancel a class (everyone is told).
- Confirmations, the 24-hour reminder and cancellations reuse the booking messages ("Class: Yoga Flow").
- The gym template and demo book classes; browser tests and public scans cover them.

### Fixed
- **Public rate limits were shared across all businesses per IP address**: bookings, table reservations, classes and quote forms keyed their limit by the route's domain pattern (the same for every business), so a few bookings anywhere could block other customers on a shared mobile IP (carrier NAT). Limits are now per business and visitor (bookings and quotes: 10 per 10 minutes), with regression tests. Found when CI ran all booking tests together.

### Added — Sprint 9.2 (pay before the slot)
- **The full price** as a payment option, alongside a fixed or percentage deposit — paid straight to the business before the booking is confirmed.
- **Charge it always, or only for online appointments** (in person, clients can pay at the desk).
- **No joining link until the payment is verified** — online appointments get their link on confirmation, and a booking awaiting payment isn't confirmed. Refunds follow the cancellation policy.

### Changed
- CI: the browser job's limit is 20 minutes (454 checks plus the 24-template performance gate outgrew 12).

### Added — Sprint 9.1 (online appointments)
- **Where, per service**: in person, online, or the client chooses. A service can't go online until clients have a way to join.
- **A private video link per appointment**, hosted by the business's **own** Google (Meet) or Zoom account — created before the confirmation (so the email carries it), moved and deleted with the booking; Zoom meetings use a waiting room. If the provider is down, the booking stands and the link is retried before the reminder.
- **Connecting Google or Zoom** (OAuth): the business travels in an encrypted, expiring state bound to the owner's own session (a forged return is refused); tokens are encrypted at rest and refreshed automatically; disconnecting revokes access. Super admin enters WURK's Google and Zoom app credentials under providers.
- **Fixed meeting link** as a fallback (https only), with a clear "same link for everyone" warning.
- **Clients** choose In person / Online in "Book online"; the joining link appears on their booking page from 15 minutes before (and in emails).

### Fixed
- The handle `oauth` is reserved (the video-connection return address lives under `/oauth`).

### Added — Sprint 8.5 (restaurant table reservations)
- **Tables by covers** (no floor plan yet): covers, sitting length, interval between times, largest party online, notice, and arrival hours per weekday (lunch and dinner).
- **Exact capacity**: a time is offered only if the most guests present at any moment during the sitting, plus the party, fits the covers — parties that never share the room don't block each other.
- **No overbooking**: the evening is locked while capacity is re-checked and the reservation saved; tested on SQLite and MySQL 8.
- **Online**: "Book online" in table mode (guests → day → time → details); a signed reservation page to cancel; confirmations, a 24-hour reminder and cancellations reuse the booking messages ("Table for 4"), with the same email fallback.
- **Owners**: Bookings → Tables — the day's reservations with guests booked against covers; add a reservation (any time if there's room); seated, no-show, cancel; table settings.
- The restaurant template takes table reservations; its demo site does too (browser tests and public scans cover the live widget).

### Added — Sprint 8.4 (confirmations and reminders)
- **Booking messages** in fixed wording (agreed with WURK): confirmed, reminder, moved and cancelled — client's first name, service, business, date and time, and the manage link. WhatsApp uses matching Meta templates (`wurk_booking_<kind>`, parameters: client, service, business, when, link) for WURK to register once.
- **Channels**: email free; SMS and WhatsApp through WURK's providers, charged in WURK Credits first and refunded if the send fails. **Essential messages never silently stop**: when credits (or a spending limit) run out or a send fails, the message goes by free email, and the reason is recorded.
- **Triggers**: confirmation on booking (or when the deposit is paid), a message when a booking moves or is cancelled; nothing for expired holds or no-shows. Each message is sent once.
- **Reminders** 24 hours (default) and/or 2 hours before, via a central due list and a 5-minute sweep; moving a booking reschedules them, cancelling removes them.
- **Settings**: the channel, with costs stated and channels WURK hasn't switched on shown as unavailable; reminder times.

### Added — Sprint 8.3 (deposits and policies; Paystack test mode)
- **Deposits paid straight to the business** through a Paystack subaccount (Billing `SplitPayments`; 0% WURK commission; the business bears Paystack's fee). Owners connect their bank in Booking settings; only a masked label (e.g. "Capitec Bank ••••7890") is kept.
- **Deposit per service**: none, a fixed amount, or a percentage of the price. Email is required when a deposit applies.
- **Held times**: a booking awaiting its deposit holds the time for 15 minutes; an every-minute sweep releases unpaid holds. Payment is confirmed only after verifying with Paystack, idempotently (return redirect and signed webhook); short payments aren't accepted; a payment arriving after its hold expired is refunded automatically.
- **Cancellation policy** (default 24 hours, editable window and wording): customer cancels in time → deposit refunded; later or a no-show → kept; the business cancels → always refunded.
- **Self-service manage link** (signed, expires at the booking's start): see the booking and the policy, what cancelling now means, cancel, or move to another free time — plain HTML, works without JavaScript.

### Fixed
- The deposit return page's view wasn't registered (customers returning from Paystack would have seen an error).

### Added — Sprint 8.2 (online booking and the owner calendar)
- **Online booking API** on each business's own address (`/book/services`, `/book/times`, `POST /book`): active services and team, free times in local time, booking with consent, a spam trap and rate limits; a time taken a moment ago is refused kindly (409). Only for packages that include bookings.
- **"Book online" website section**: service → team member ("anyone") → day → free time → details → confirmation, as a small island (DOM-built, so business names can't inject markup; announces progress; focuses the confirmation). Without JavaScript it shows how to book instead. Added to nine booking-led templates.
- **Calendar**: day (per team member) and week views; add bookings and walk-ins (owners aren't held to notice or listed hours — never to overlaps), move atomically, mark done, no-show or cancel (frees the time). Viewing needs `bookings.view`; changes `bookings.manage`.
- `bookings:demo` (development only) makes the demo sites bookable, so browser tests book on a published site and the public scans cover the live widget.
- Access contract `TeamDirectory::add()`.

### Added — Sprint 8.1 (services, team hours and availability)
- **Bookings module** (entitlement `bookings`): bookable services (length and buffers in 5-minute steps, price, who does it), weekly team hours with breaks, time off for one person or the whole business.
- **Availability** in the business's time zone: hours and breaks, buffers that must fit inside working hours, time off, existing bookings, 60 minutes' notice, 60 days ahead; "anyone" spreads bookings across the team.
- **No double-booking, enforced by the database**: each booking claims its 5-minute slots under a unique key per team member — tested with a race on SQLite and MySQL 8.
- **Booking settings** screen (services, team hours, time off), accessibility-scanned on desktop and phone.
- Access contract `TeamDirectory`; tenancy contract `timezone()`.

### Fixed
- Bookings stored times in UTC while WURK stores South African wall-clock time (APP_TIMEZONE): every booking would have been two hours out. One helper (`Instant`) now converts every stored and compared time.

### Added — Sprint 5.4 (visitor features and the performance gate)
- **Performance gate in CI**: mobile Lighthouse on the public home page of every template — **≥ 90 and layout shift < 0.1, enforced**. All 24 score 99–100 (layout shift ≤ 0.002, main image 1.3–2.2 s), against 65–71 for the old previews.
- **Every public page of every template accessibility-scanned** on desktop and phone (338 checks), from demo sites (`website:demo-sites`, development and tests only). The preview scan now covers each template's home page (the preview's own controls).
- **WhatsApp chat button** on every page once a WhatsApp number is published: a plain link (no third-party script), in WhatsApp's darker green so the icon meets the 3:1 contrast graphics need.

### Fixed
- **Quote forms on published sites would all have failed**: the form route existed only on the app's domain while pages post to their own address (and the content policy allows only that). Each site now has its own quote route, with the same spam protection.
- Business facts (contact, services) vanished for renders that reported no block values (an empty list wasn't treated as missing).

### Added — Sprint 5.3 (search engines and AI answers)
- Every published page: canonical address, Open Graph and social card (the page's first photo), favicon.
- **Structured business data** (schema.org LocalBusiness) on the home page: name, description, phone or WhatsApp, email, address, **opening hours once the owner has confirmed them** (templates ship sample hours), services with prices — **no price where the price is "ask us"**, each service once.
- **`sitemap.xml`, `robots.txt` and `llms.txt`** per site; sites not yet live ask not to be indexed.
- Everything is taken from **what visitors see**: the renderer reports each block's final values (defaults filled in), stored with the render — e.g. hours left at the section's defaults are included once confirmed.
- Owner text can't break out of the structured-data block (`</script>` escaped).

### Added — Sprint 5.2 (serving, caching and safety)
- **Statuses for public sites**: "coming soon" before the first publish (not indexed); **suspended businesses show "temporarily unavailable"** (503, Retry-After) and nothing of the business; missing pages get a 404 in the business's name with a link home.
- **Renamed pages redirect** (301) from their old addresses, so shared links keep working.
- **Security headers** on every public response: a Content Security Policy allowing only the business's own address (no third-party requests — POPIA), no framing, no type sniffing, a strict referrer policy.
- **Caching**: the stored render is the page cache; browsers revalidate by ETag (changing with each render and each deploy), and `Cache-Tag: site-{id}` is ready for CDN purges (Sprint 17).

### Fixed
- **A deploy would have broken every published page**: stored pages had baked-in links to hashed asset files that a new build replaces, served from the app's address. Only content is stored now; the page shell is added per request from the current build, as same-site paths.
- **Unpublished pages stayed live**: only currently published pages are served.

### Added — Sprint 5.1 (rendering pipeline)
- **Published pages are ready-made HTML**, rendered once at publish by Node (bootstrap/site-render) with the same SiteRenderer as the editor — no second copy of any block. Stored per published version; a failed render never takes a live site down (the previous version stays live; retried with back-off).
- **Public websites** at `{handle}.{root}` (`*.wurk.test` in development and tests): complete HTML with JavaScript off, a public-only stylesheet (site blocks only), and no third-party requests.
- **Fonts without layout shift**: each page preloads its two fonts (Latin, regular — the renderer reports them); size-matched fallbacks for all 14 families, generated from font metrics (`pnpm fonts:fallbacks`).
- **Islands**: a tiny script, only on pages that need it, for before & after, the slideshow (dots, arrows, autoplay that pauses on hover/focus and never runs with reduced motion) and closing promotions.
- **Re-rendering**: on branding and menu changes, and nightly at 00:05 SAST for pages with dated content (promotions, expiring specials).
- `createdAt()` stays on the tenancy contract; the renderer ships in the production image.

### Fixed
- **Business context lost after a synchronous job**: the after-job reset (right for queue workers) also fired for jobs run inline in a request, leaving the rest of the request without its business.
- **Published slideshows would have autoplayed with no pause button** (the server can't know a visitor's motion preference): the control is now always present when autoplay runs (WCAG 2.2.2).
- The public-site route no longer swallows other routes on business subdomains (a fallback now).
- The renderer's build output had been committed; it's ignored now.

### Added — Sprint 7.6 (preparation)
- **Time to launch**, measured automatically: the first time a business publishes its home page, WURK records the minutes since the business was created (and its template). **Platform → Time to launch** shows each business, the median and the share launched within an hour — for the timed launch test now, and as an ongoing product metric.
- **Timed launch test kit** (docs/research/launch-test.md): who to recruit, POPIA consent for screen recording, the session script, an observation sheet and what happens after.

### Added — Sprint 7.5 (launch checklist and quality)
- **Launch checklist** at the top of Website → Pages: logo, contact details, prices, your own photos, opening hours, legal pages, publish — each judged from the site’s real state (or confirmed by the owner where it can’t be), each linking straight to where it’s done.
- **Editing tips** in the editor: three short tips that step through, dismiss, and stay dismissed; on phones they sit above the bottom controls and close when the owner taps elsewhere.
- Template guard: every template photo must be a 1600 px WebP with its 640 px phone size.

### Changed
- Template photos are responsive WebP sizes (phone size about a fifth of the JPEGs).
- **Performance gate moved to Sprint 5** after measuring: template previews score 65–71 on mobile because the preview is built in the browser; the ≥ 90 gate (and layout shift < 0.1) will be enforced on real public pages served as ready-made HTML.
- One money format across WURK (the package pages and WURK AI admin used locale formatting, e.g. “R0,28”).

### Fixed
- Editor accessibility (Puck’s chrome): unnamed list “add” buttons, untitled preview frame, unlabelled zoom control, invalid label on the loading indicator — found by the first scan of the editor with its fields panel open.
- Package “Edit/View” links named per package; checklist buttons name their visible words first (label in name).
- An unproven “under an hour” claim and stale copy (“the editor and preview arrive next”) on the Pages screen.

### Added — Sprint 7.4 (Plan & usage)
- **Settings → Plan & usage** (was “WURK Credits”): renewal date and amount (incl. VAT); monthly credits, storage and team members as accessible progress bars; the balance in rands (cheapest pack, incl. VAT); when monthly credits expire vs bought credits that never expire; usage by type; the price list; statement download (CSV).
- **Spending limits**: a monthly limit for the business (set by those who manage billing) and per staff member, enforced when credits are charged, inside the balance lock. A limit is a kind of “insufficient credits”, so messaging and WURK AI handle it automatically; AI quotes explain a limit instead of offering an action that would be refused.
- **Usage meters** (Plans contract): storage (Website) and team members, so future modules can add their own.
- **Team-member limit enforced** wherever team members are added (it was defined and sold but never enforced; no owner invite screen exists yet, so it couldn’t be exceeded in practice).

### Fixed
- Usage “this month” counted refunded charges as spent; it’s now net of refunds.
- A circular dependency between Plans and Access (introduced while building the staff meter) — Access now defines small contracts that Plans implements; credit pricing lives in Plans so Credits never depends on Billing.
- Outdated copy on the credits page (“Buying credits arrives with billing”).

### Added — Sprint 7.3 (AI images and media library)
- **AI images**: “Generate an image” in the photo picker, with the same confirmation (8 credits; balance before and after), charged once and refunded on failure. Stored through the same safe pipeline as uploads and marked as AI. Image providers in WURK AI settings: OpenAI and a simulated provider (Google once a key lets us verify its API); price per image.
- **Decorative only, enforced on save**: AI images are allowed in the Hero and Image & text sections and refused elsewhere (galleries, Before & after, Team, Portfolio, Rooms, Reviews, Accreditations, logo) with a plain message. The editor only offers and shows AI images where allowed; a test keeps the editor's and server's lists identical. Every image request asks for no real, identifiable people, logos or brands.
- **Media library** (Website → Media): every photo with an AI badge, size, where it's used, an editable description offered wherever it's placed, delete (refused while in use), and storage against the package quota.

### Fixed
- Photo usage detection read stored page JSON as text, where “/” is escaped — every photo looked unused, so a photo still on a page could have been deleted. It now reads the content as data, through one shared helper.

### Added — Sprint 7.2 (templates II, part 2)
- Eight templates: Trades & home services (Solar & electrical; Home services), Education & training (Tutor & online classes; Early-learning centre), Events & creative (Photographer & events portfolio; Venue & event planner), Transport & logistics (Shuttles & tours; Couriers & removals). **All 24 templates across the 12 categories are done.**
- The early-learning centre's sample photos show rooms and play spaces, never children (photos of children need parental consent under POPIA); registrations are left for the owner, never claimed.

### Changed
- Browser tests: the sign-in code limit per IP (20/hour in production, unchanged) is raised only in the browser-test environment; the full suite sends more codes from one IP than that, which made CI fail.
- The template accessibility scan runs one test per page (338 page checks across desktop and phone): clearer failures and no timeouts.

### Fixed
- Every page's HTML now has a title from the first byte (it was added only by JavaScript): untitled pages for screen readers, browser tabs and link previews until scripts ran — and a CI accessibility scan that raced it.
- Copy reviewed against the honesty rules: an invented class size, and safety claims for the early-learning centre (in its features and its headline), removed.

### Added — Sprint 7.1 (templates II, part 1)
- Six templates: Professional services (Advisory practice; Agency / consultancy), Car mechanics (Workshop & service centre; Panel beater & auto electrician), Hospitality (Hotel / guesthouse with rooms & rates; Restaurant with a food menu). 16 templates in total.

### Fixed
- Sections with nothing in them yet showed an orphan heading on the live site (portfolio, team, accreditations, timetable and other lists, including in Sprint 6 templates). They're now hidden on the live site and show a prompt in the editor; the main heading goes to the first section actually shown.
- The template guard now also catches pages that would be blank before the owner adds content.
- Three invented policies removed from template copy (free quotes, a kitchen closing time, a reply-time promise).
- CI: changes to website templates (and their photos) now run the backend and frontend tests that read them; previously a template-only change would have skipped them.
- Marketing site: the Monthly/Yearly price switch changes colour instantly; its fade could be caught mid-way by the accessibility scan (a CI timing race).

### Added — Sprint 6.4 (first 10 templates)
- Ten templates in the repository (modules/Website/templates): Salon & beauty (Hair & braids salon; Nails, lashes & barbers), Health (Dental practice; Doctor’s practice), Spa & wellness (Day spa; Wellness practitioner), Retail (Boutique; General store), Fitness (Gym & class studio; Personal trainer) — each pair visually distinct.
- `website:install-templates`: creates templates and publishes a new version only when a file changes (fingerprint); safe on every deploy.
- Privacy, terms and cookies pages for every template, written for POPIA, kept in one place; the business’s name is filled in when its site is built. General templates, not legal advice.
- Honest sample content: no sample reviews, team members, prices, offers or invented policies — those sections prompt the owner. Health templates ask visitors not to send medical details in messages; the doctor’s practice shows the emergency numbers.
- Template guard: every prop is a real field, every option valid, every photo present, every page has exactly one main heading. Every template page is accessibility-scanned on desktop and phone.

### Fixed
- Switching between designs failed when both had a section with the same id.
- “Write my website” never filled Text sections’ main content (wrong field name); a test now checks every AI slot against the real block fields.
- Links inside sections weren’t validated; only site paths, #anchors, https://, mailto: and tel: are accepted, at any depth.
- Galleries left gaps for 3, 4 and other photo counts.
- Low contrast with bright brand colours: faded call-to-action text and an inverted button using the wrong colour; guard tests prevent both returning.
- “Use this design” buttons now name their design for screen readers.

### Added — Sprint 6.3 (WURK AI, credits and transparency)
- **WURK AI** module: Anthropic, Google and OpenAI providers on the existing Providers screen (encrypted, write-only keys) plus a simulated provider that never answers in production. Model per task (pinned versions; "latest" aliases refused), fallback order, every attempt logged with its real cost, daily limit per business and monthly spend cap per provider.
- **Owners:** "Write with AI" — describe the business, add services and prices, choose a tone; a confirmation shows the exact credits and balance before and after; charged once (double-clicks included), refunded automatically on failure. The first website is free on every package; later AI actions need a package that includes WURK AI. Not enough credits → Buy credits and/or Upgrade.
- **Honesty by design:** the AI fills only whitelisted text slots; testimonials, team, prices, opening hours, qualifications and registrations are never sent or written; prices come only from the owner. Results are drafts for review.
- **Admin → WURK AI:** this month's real cost per provider (requests, failures, fallbacks, $ and R) and per business; models and fallbacks; "try a model" with a sample and its cost; prices, exchange rate, buffer and margin; caps; model retirement dates with a "retiring soon" warning.
- **Package cost tip:** as monthly credits are entered — cost if fully used (range across channels), share of the package price, minimum safe price per extra credit at the target margin, and which costs aren't set yet. New "Your cost per message" setting on every SMS, WhatsApp and email provider.
- **Can buy credit packs** per package (versioned; absent means allowed). When off, purchases are refused at the server and the billing page suggests upgrading.
- Per-action credit prices on the rate card: "AI: write a website" (20) and "AI: rewrite a section" (1) for South Africa.

### Fixed
- "Write with AI" page crashed (business resolved the wrong way) — caught by the browser test; the page now has its own test.
- Unlabelled dropdowns on the admin AI and AI writer screens (accessibility scan).
- A test that passed for the wrong reason (CSRF 419 instead of the production guard) now proves the guard itself.

### Added — Sprint 6.2 (category sections)
- Ten sections: Before & after (keyboard-accessible slider or side by side), Packages & specials (expired specials hide themselves), Class timetable (week grid on desktop, days with classes on phones), Food menu (dietary tags), Rooms & rates (links out to a booking engine — never embedded), Portfolio, Accreditations, Service areas (map link, no embedded map), Promotion banner (between dates, dismissible, above the header), Quote form.
- Quote form submissions: stored per business and emailed if the owner sets an address; consent required; hidden-field spam trap, per-visitor limit and a daily cap per business; visitors' IP addresses stored only as a hash. New Website → Enquiries screen (new first, one-tap Call / WhatsApp / Email, mark read, archive, notification email).
- The design board shows all 24 sections, accessibility-scanned on desktop and phone.

### Fixed
- Pages without a Hero had no main heading (accessibility and SEO): the first section's heading becomes the page's only h1; four blocks that wrote their own h2 now follow the rule.
- The Before & after caption couldn't be edited until both photos were uploaded.
- The inline-editing safety test skipped fields in lists inside lists (e.g. a menu's dishes); nested lists are now filled and tested.

### Added — Sprint 6.1 (template system)
- Website categories (the 12 agreed, `website:seed-categories`) and templates managed by WURK staff: create blank or as a copy, edit pages in the same visual editor owners use, template photos (same safe pipeline, kept apart from businesses' photos), preview drafts, publish immutable versions with a note, restore a version, retire. Permission `templates.manage`.
- Signup: "What kind of business is it?" and "Choose your website design" steps with live previews (published designs only); categories appear once they have a design. Completing signup builds the site from the design (pages, menus linked to real pages, branding). Applying a design can never block signup — on failure the business gets the starter layout.
- Owners switch to the other design in their category from the Pages screen, keeping every text, photo, price, menu item and page (only style settings and fonts change; colours and logo stay). The site is snapshotted first; the live site changes only when they publish.
- `website:seed-sample-templates` for development and browser tests (refused in production).

### Fixed (review of unfinished work found on the branch)
- The template photo list read every image from storage to get its size; sizes are now recorded at upload.
- A refused template save could still rename the template.
- Staff saw "0 MB of 0 MB used" in the template photo picker.

### Added — Sprint 4.4 (menus, branding, site preview)
- All 8 curated font pairings work (6 font families added, self-hosted, OFL); a test keeps the server's list and the site theme in step.
- Menus: one header menu for desktop and phones, with one level of accessible dropdown sub-items, plus footer links. Items point to pages by id (renaming a page's address never breaks a menu; deleted pages drop out). Only https://, mailto:, tel: and #section links are accepted — never javascript: or data:.
- Branding screen: logo and browser icon (only this business's own uploads accepted), main and optional second colour with the generated palette, font pairing cards written in their own fonts, corner styles, and a live preview that updates before saving.
- Site preview inside the back office: the whole site with its real blocks, latest changes or exactly what's published, page switcher, menu links that stay within the preview.

### Changed
- Signup rate limits are counted per browser session with a high per-IP backstop (30/min per session + 300/min per IP; starting a signup 5/hour per session + 60/hour per IP). South African mobile networks share IP addresses, so IP-only limits let strangers throttle each other.
- A rate-limited app screen now shows a plain message on the same screen instead of a raw "429" box (API and JSON still receive 429).

### Fixed
- The Branding service is a singleton; it now looks up the current business on every call (a captured context could outlive the request under Octane).
- The photo picker's hidden upload input had no label (accessibility scan).
- An unset second colour showed a misleading swatch; it is now an "Add a second colour" button.
- On short pages the back office's background showed below the preview's footer.

### Added — Sprint 4.3 (visual editor, photo uploads)
- Visual editor (Puck `@puckeditor/core` 0.23.0, pinned, behind our own adapter — pages stay in WURK's format): all blocks, click-to-edit text on the page (38 fields, each checked safe), drag-and-drop, undo/redo, phone/tablet/desktop previews, autosave with a clear conflict message (never a silent overwrite), offline/"couldn't save" retry, leave-page warning, publish with a note, version history with restore. On phones Puck's header folds away and its canvas covers that menu, so save status, Versions and Publish sit in a bar within thumb reach.
- New pages never start blank: Home starts with header, hero and footer; other pages with header, a text section headed with the page title, and footer.
- Photo uploads (GD, added to both Docker images and CI): content-checked JPEG/PNG/WebP up to 10 MB, dimensions checked before decoding, phone orientation applied, re-encoded to WebP at 640px and 1600px with all metadata (GPS, camera) removed; the original is never stored; counts towards the package storage quota. Photo picker (upload or choose an uploaded photo). Every block photo serves the 640px file to phones (`srcset`/`sizes`).
- Guard tests: inline-editable fields render safely with and without photos; select options carry real values; no stylesheet may load from another host.

### Fixed
- On/off options were stored as the text "true"/"false" (both truthy): choosing "No" would have auto-played the slideshow, pinned the header and shown every price as "from".
- Puck's block ids were longer than the server allowed, so every save would have failed.
- Puck's default stylesheet loaded Inter from rsms.me (a third-party request from every owner's browser); the no-external stylesheet is used with our self-hosted font.
- The first edit after opening the editor could not be undone (Puck doesn't record the page as loaded); history is now seeded.
- The Text block's empty heading had no height and couldn't be clicked.
- Browser-test setup now clears rate limits, and test businesses no longer share a seeded business's name.

### Fixed
- Website header: logo and button now line up with the content of every section (the header kept its side padding inside the content column).

### Added — Sprint 4.2 (block library, part 1)
- 14 server-renderable website blocks, used unchanged by the editor, the preview and the Sprint 5 public engine: Header and menu, Hero, Why choose us, Services and prices, Photo and text, Gallery, Testimonials, Team, Opening hours, Contact details, Questions and answers, Text, Call to action, Footer.
- Hero (version 2) with five layouts: Full photo, Text and photo, Photo collage, Slideshow and Text only. The slideshow never advances on its own unless the owner chooses it — then slowly, pausing on hover/focus, with a pause button, and never for visitors who prefer reduced motion; only the first photo loads immediately. Without photos, every layout falls back to a strong text-only hero on the live site.
- Block contract: versioned props with upgrade steps (Hero v1 → v2 converts the single photo to a photo list); unknown blocks, or blocks saved by a newer release, are skipped instead of breaking the page.
- Site theming from each business's branding through the Sprint 0 palette engine, scoped to the site; site base styles so back-office fonts never leak into a website; self-hosted font pairings (Playfair Display and Source Sans 3 added, OFL licence).
- Page structure built by the renderer: header before `<main>`, footer after, skip link.
- No third-party requests from public sites: the map is a link to Google Maps, not an embed (POPIA); phone and WhatsApp links use South African international format.
- Design board at `/_blocks` (not in production) with two contrasting sample businesses; accessibility-scanned on desktop and phone.

### Fixed
- Block upgrades merged old props into the new shape, so removed fields survived; each step now replaces them and defaults apply afterwards.
- A block with nothing to show left an empty wrapper on the page.

### Added — Sprint 4.1 (site model, branding)
- Website module (entitlement `website`): one site per business on its shard, created with a home page the first time it's needed.
- Pages: titles, addresses (unique per site, engine paths reserved), order, exactly one home page, SEO title and description, soft delete with 30-day restore (a reused address gets `-2`).
- Content stored in WURK's own versioned format (`schema` + blocks with id, type, version and props), validated on every save (size, section count, ids, types) — never raw editor data, so editor upgrades can't break saved pages.
- Drafts with a revision number: a second editor saving a stale copy gets a conflict (HTTP 409) instead of silently overwriting.
- Publishing creates an immutable numbered version; any version can be restored as the draft (publishing it is a separate step); unpublish.
- Branding inputs (primary/secondary colour, curated self-hosted font pairing, corner style) validated and stored; the accessible palette is computed from them by the Sprint 0 engine wherever the site renders.
- Pages screen for owners; JSON endpoints for the editor (4.3) and branding (4.4). Permissions `website.edit` and `website.publish`; every publish, restore, delete and branding change is on the business's audit chain.

## [0.4.0] — 2026-10-22 — Sprint 3: Signup & billing

### Added — Sprint 3.4 (invoices, proration, dunning)
- SARS tax invoices for every payment: gapless `WURK-INV-` numbers (allocated under a row lock in the issuing transaction), issuer and customer details snapshotted at issue, all required fields, PDF (dompdf, no network access while rendering), emailed with the PDF attached; owners download only their own. Invoices are immutable — corrections are credit notes.
- Credit notes (`WURK-CN-`, own PDF, emailed): full or partial, never more than is left; card payments refunded through Paystack automatically, cash and debit orders flagged for an EFT refund that finance marks paid.
- Invoice issuer details (legal name, address, VAT number, registration) as settings with placeholder defaults, clearly flagged on the settings page and on invoices until replaced.
- Proration (PRD §11.3): upgrades charged now for the rest of the period (monthly → yearly starts a new year, crediting the unused month); downgrades and yearly → monthly scheduled for renewal and charged at the new price then.
- Dunning (PRD §11.1, schedule editable): owner told on failure (email; SMS when a billing mobile is set), banner on days 1–7, retries on days 3/5/7 (once per day), Billing-only from day 8, suspended on day 15, restored at once on payment. Suspension is a billing state — the owner can still reach Billing — and `ServiceStatus` tells the Sprint 5 site renderer to show "temporarily unavailable".
- Admin: invoice and dunning settings, a business's invoices and payments (credit notes, EFT refunds, retry now), failed-payments queue. Permissions `invoices.view` and `invoices.manage`.
- Generic page banner in the back office (`banner` prop), set per request.
- Guard tests: no `Inertia::share()` in modules; every menu icon is registered.

### Fixed
- A request-specific banner was set with `Inertia::share()`, which outlives the request in Octane workers and could have shown one business's notice to another; it is now attached to the request.
- Billing, Failed payments and Invoice settings showed blank menu icons (unregistered icon).

### Added — Sprint 3.3 (cash, DebiCheck)
- Cash at a WURK office (PRD §4.5): staff record cash for a subscription or credit pack; a 6-digit code goes to the approvers in Security settings — never to the person who recorded it, and recording is refused when nobody else can approve. Codes are hashed, valid 15 minutes, 5 attempts. Once confirmed the payment settles like any other; receipts go to finance and the business. Recorded, approved and cancelled cash is audited. New platform permission `payments.record-cash` (finance and super admin).
- DebiCheck debit orders behind a `DebitOrderProvider` contract with a stand-in provider until WURK chooses one (Sprint 3 decision 2); the stand-in refuses to run in production. Owners set up a mandate (SA ID number checksum, 6-digit branch codes with universal codes prefilled, account details encrypted and masked); approval, rejection and collection outcomes arrive as signed events, each handled once. Renewals collect by debit order when one is active; outcomes settle days later without moving the billing date.
- Any failed subscription payment (card now, debit order later) makes an active subscription past due.

### Fixed
- Wrong cash approval codes never counted: the attempt was rolled back with the error, allowing unlimited guesses. Attempts now commit before the error is reported.
- A new payment's status was empty in memory (database default not loaded), so a submitted debit-order collection was marked past due; payments now set "pending" explicitly.
- Rate limits shared one counter per user across routes (e.g. wrong codes used up "send a new code"); every throttled route now has its own counter.

### Added — Sprint 3.2 (card payments, credit top-ups)
- Billing module with a `PaymentGateway` contract and Paystack as the first driver (checkout, server-side verify, saved-card charges, refunds, HMAC-SHA512 webhooks).
- A payment is marked paid only on server-side confirmation from Paystack (signed webhook or our own verify call), and only if the confirmed amount and currency match; every confirmation path goes through one idempotent, row-locked settle step. Webhook events are handled once.
- VAT added per the business's country (15% in ZA) and stored on each payment for invoicing (3.4).
- Subscriptions: first payment, paying during a trial (the paid period starts when the trial ends), and hourly renewals on the saved card; failed renewals mark the subscription past due (dunning in 3.4). Subscriptions now record their current period.
- Saved cards: Paystack's reusable token only (encrypted), deduplicated by card signature; "add a card" makes a R1 check refunded straight away.
- Credit packs (set by WURK staff) bought by card or one tap on the saved card; credits granted only after confirmation.
- Owner Settings → Billing: package, price incl. VAT, renewal or trial date, billing details (SA VAT number check), card, credit packs, payment history. A business that hasn't paid, or whose trial has ended, can only reach Billing.
- Signup: when the free trial was already used, the business is created waiting for payment and the owner goes straight to Billing.

### Added — Sprint 3.1 (marketing site, signup wizard)
- Marketing module: wurk.co.za home, pricing (live from public packages, monthly/yearly, prices excl. and incl. VAT), questions, contact (stored, emailed to `WURK_CONTACT_EMAIL`, honeypot and rate limit) and legal pages (awaiting WURK's final text).
- Server-side rendering for wurk.co.za only (Inertia SSR): full HTML for search engines; if the renderer is down, pages render in the browser. Self-contained SSR bundle; the production image ships a pinned Node runtime and an `ssr` role; CI renders a page from the built image.
- Onboarding module: signup wizard — details → verify (one-time code) → package → web address (live availability) → finish → signed in to the new business on its free trial with the first month's credits. Resumes on another device after re-verifying; one free trial per verified email or mobile; temporary mailboxes refused; finishing twice never creates two businesses; signups expire after 7 days; rate limits.
- Contracts: `Identity\Contracts\Verification` and `Accounts`, `Tenancy\Contracts\TenantProvisioner`, `Plans\Contracts\Catalog`; subscriptions record the billing period.
- One-time codes go to new addresses only for the signup purpose; every other purpose still only reaches existing WURK IDs.
- `packages:seed-placeholders` (development and testing only; refused in production).
- Mobile Lighthouse (production-like compression): home 98 / 100 / 100, pricing 98 / 100 / 100 for performance / accessibility / SEO.

### Fixed
- Prices could format differently on the server and in browsers (`R343,85` vs `R343.85`); rand amounts now format identically everywhere.
- The marketing site downloaded the whole design-system bundle; it now loads only the components it uses (page weight 729 KB → about 220 KB).

## [0.3.0] — 2026-10-22 — Sprint 2: Super admin

### Added — Sprint 2.4 (WURK Credits, security settings)
- Credits module (ADR-0008): one balance per business, kept in thousandths of a credit. Append-only ledger (allowance, purchase, adjustment, debit, refund, expiry) with a cached balance kept in step under a row lock; every write is idempotent by key.
- Monthly allowance spent first; unused allowance expires at the next one; purchased credits never expire. First allowance on a business's first package, then daily `credits:grant-allowances` on each billing anniversary (31st → last day of shorter months).
- Effective-dated rate card per country and channel (starting ZA: SMS 3, WhatsApp 5, email 0.1, AI 2); past charges keep their price.
- Business messages are charged before sending and refunded automatically on failure; sending is refused cleanly without credits; platform messages (sign-in codes, invitations) are free.
- Low-balance alert raised once and re-armed by a top-up (recorded; owner notifications arrive with the notification engine).
- Admin: credit rates, a business's balance and ledger, audited manual adjustments. Owners: Settings → WURK Credits (balance, usage, prices, alert level).
- Security module: admin IP allow-list (off by default; refuses a list that would lock out the person saving it; `WURK_ADMIN_IP_ALLOWLIST=false` emergency switch), cash-payment approval and receipt recipients (used from Sprint 3), and owners can require an authenticator for their whole team.
- Extension points: the `tenant.back-office` middleware group; `CurrentTenant::countryCode()`.

### Fixed
- Two rates set in the same second could apply in either order; the most recently set rate now always wins.

### Added — Sprint 2.3 (countries, providers, messaging)
- Regions module: countries with currency, VAT, phone prefix and time zone; South Africa open for sign-up, neighbours prepared but off (their VAT rates must be confirmed before enabling). Business creation only allows open countries.
- Messaging module: one interface over five SMS providers (SMSPortal, Clickatell, BulkSMS, Brevo, Twilio), the WhatsApp Cloud API (Meta) and SMTP email (Brevo relay or any provider).
- Provider settings in the admin: credentials encrypted at rest, write-only (never returned to the browser; blank keeps the saved value; never written to the audit log), "send test" per channel, recent platform messages.
- Sign-in codes to mobile numbers by WhatsApp first (approved authentication template with copy-code button), falling back to SMS.
- Delivery records for every message (recipient stored as a hash plus masked number); signed delivery receipts from Twilio and Meta update status; forged calls are rejected.
- Invitations by mobile number (business owners and team): SMS with the link; accepting verifies the number.
- Payment gateway credentials (PayFast, Paystack, Yoco, Ozow, Peach) stored encrypted, used from Sprint 3.
- docs/messaging/whatsapp-templates.md: templates to submit to Meta and webhook set-up.

### Added — Sprint 2.2 (packages, entitlements, feature flags)
- Plans module: packages with monthly/yearly prices (cents, VAT added at checkout), trials, storage/staff/branch limits, custom domain, monthly credits, referral guardrails, managed fee and included modules.
- Versioning: presentation edits change in place; any commercial change creates a new immutable version. Existing businesses keep their version (grandfathering); new subscriptions get the latest. Archive instead of delete.
- Module catalogue: modules can be sold before they are built and switch on when a module ships with the same entitlement key.
- Entitlements: package modules plus per-business grant/revoke exceptions with a reason; `entitled:{module}` route middleware shows a friendly "not in your package" page; menus hide modules a business does not have (`use-module` Gate ability).
- Business plan page for WURK staff: change package (first package starts its trial) and module exceptions; every change audited.
- Features module (Laravel Pennant): modules declare flags; WURK staff choose off (kill switch), opt-in (standard for everyone after 90 days), percentage rollout (stable per business) or on; owners opt in from Settings → New features. Rule changes purge cached values; opt-ins are kept separately.

### Fixed
- `wurk:make-module` refreshes the autoloader itself (the app could not boot until `composer dump-autoload` was run); a missing module provider now fails with a clear message.
- Creating a package with an empty description failed (found by the browser test).

### Added — Sprint 2.1 (super admin back office)
- Admin module: WURK staff dashboard (businesses, staff, shard capacity, recent platform activity).
- Businesses: list with search and filters, detail with web addresses and audit link, manual creation with owner invitation, suspend / reactivate with a recorded reason.
- WURK staff: invite by email, change roles (always one super admin), deactivate / reactivate (signs out everywhere and removes every platform permission at once); custom staff roles from platform permissions.
- Invitations: signed, single-use, 7-day links stored as hashes; accepting proves control of the email and counts as the first sign-in factor (staff still set up an authenticator); a newer invitation replaces an older one.
- Navigation shows each back office only its own items (by route host).
- Browser test signs WURK staff in with a real authenticator code and runs the admin (create business, staff, roles), accessibility-scanned.

### Fixed
- Toast close button meets the WCAG 2.2 minimum touch target (now 28×28 px); found by the mobile accessibility scan in CI.

### Changed
- Docker images pin FrankenPHP 1.13.0 (Debian Trixie); the dev image builds with buildx and caching.

## [0.2.0] — 2026-10-21 — Sprint 1: Platform core

### Added — Sprint 1.4 (audit logging)
- Audit module: append-only, tamper-evident trail — one SHA-256 hash chain for the platform (central database) and one per business (on its shard). Each event links to the previous one; gapless sequence numbers per chain.
- Security events recorded automatically and synchronously: sign-in success and failure, code requests, MFA on/off, password changes, sessions revoked, sign-out, staff and membership grants, business created and status changes, every role and branch-access change.
- `AuditLog::record()` for any module and an `Auditable` model trait (create/update/delete with before/after values); secrets redacted at any depth.
- Request ids on every response (`X-Request-Id`), shared with logs, jobs and audit events.
- `audit:verify` checks every chain and reports edits, gaps and removed events.
- Audit log viewer for WURK staff (platform chain) and owners (their business), with filters and CSV export; hidden from people without permission.

### Fixed
- Concurrent audit writers on MySQL could deadlock and lose events; proven fixed with 8 parallel writers (400/400 events, chain intact, three runs).

### Added — Sprint 1.3 (access control)
- Access module (ADR-0009): permissions declared by each module with a platform or business scope; system roles defined in code (super-admin, finance, support, managed-services; owner, branch-manager, staff); custom roles from declared permissions only.
- Checks through Laravel's Gate: declared permissions answered by Access, everything else falls through to policies. Business permissions apply only inside the current business; super admins get no business permissions inside a business.
- Team records, role assignments and branch assignments on the shard; `BelongsToBranch` limits branch-restricted members to their branches.
- Guards: a business always keeps an owner; WURK always keeps a super admin; custom roles cannot mix scopes or be deleted while assigned.
- Policies for businesses and branches; navigation items hidden without the required permission.
- `permissions:sync` (validates system roles, prunes undeclared permissions from custom roles on the central database and every shard); `identities:create --role` and `--staff-role`.
- Exhaustive permission-matrix test (every role × permission × place).

### Added — Sprint 1.2 (WURK ID)
- Identity module: one WURK ID per person (email and/or mobile), business memberships, platform staff.
- Sign-in with one-time codes (6 digits, 5 minutes, 5 attempts, 30-second resend, hourly limits per address and IP; stored only as keyed hashes; neutral responses) or password (min 10 characters, ~98 000 common passwords rejected, lockout after 5 failures).
- Authenticator-app MFA (RFC 6238, replay-protected) with ten single-use recovery codes; mandatory for WURK staff, optional for everyone else. Nobody is signed in until every required factor passes.
- Sessions: admin 30-minute idle / 8-hour maximum; back office 2-hour idle with optional 30-day remember-me; device list and "sign out other devices"; password change signs out other devices.
- Screens: sign in, code entry, password, MFA challenge and set-up, recovery codes, forgot password, choose business, account & security; business home and admin placeholders.
- `identities:create` to bootstrap staff and team members; `scripts/e2e-setup` for isolated browser-test environments.
- Security events dispatched for the Audit module (Sprint 1.4).

### Fixed
- Business handles can no longer capture platform paths on the back-office host (Laravel matches domain routes first); an architecture test keeps every platform path reserved.

### Added — Sprint 1.1 (tenancy core)
- Tenancy module: shards, tenants and domains in the central database; branches in shard databases.
- Tenant context: shard connection per shard, tenant in log and queued-job context, scoped cache keys and storage, URL defaults; reset at Octane request boundaries and after every queued job.
- Isolation guard: `BelongsToTenant` filters every query to the current tenant, fills `tenant_id`, and throws on use without context or on another tenant's rows. `CentralModel` marks central data; an architecture test requires every model to be one or the other.
- Resolution by back-office path (`app.{root}/{handle}`) and by host (subdomains and custom domains), cached by id and invalidated on change; `Route::tenant()` and `Route::tenantSite()` route macros.
- Commands: `shards:register`, `shards:migrate` (shard-by-shard, stops on first failure), `tenants:create`, `tenants:run`.
- CI job running the full suite on MySQL 8 with two real shard databases; local dev stack creates and registers two shards.

## [0.1.0] — 2026-10-21 — Sprint 0: Foundations

### Added — Sprint 0.1 (skeleton)
- Laravel 13 application with React 19, TypeScript 6 (strict), Inertia v3, Tailwind CSS v4, Vite 8, pnpm.
- Module system: `modules/*/module.json` manifests, `ModuleRegistry` (discovery, validation, dependency ordering, cycle detection), `ModuleServiceProvider` base class (migrations, web routes, versioned `/api/v1` routes, contribution declarations).
- `php artisan wurk:modules` and `php artisan wurk:make-module`.
- Platform module (kernel, temporary welcome page) and Health module (`/health/live`, `/health/ready`, pluggable checks via `HealthCheck` contract).
- Module-namespaced Inertia pages (`Module::Page`).
- Architecture tests enforcing module boundaries, strict types, no debug helpers, `env()` only in config.
- Pest, Larastan (level 8), Pint, ESLint, Prettier, Vitest.
- Docker Compose dev stack (FrankenPHP + Octane, Vite, MySQL 8.4, Redis, Meilisearch, Mailpit); `scripts/dev` and `scripts/check`.

### Added — Sprint 0.2 (CI)
- GitHub Actions CI: change detection, quick checks on draft PRs, full tests and build when ready for review, Docker dev-image build when Docker files change, a single always-running `CI` summary job used as the required status check.
- Concurrency cancellation of superseded runs; Composer, pnpm and PHPStan caches.
- Branch protection on `develop` and `main` (PR required, `CI` must pass).
- Feature tests no longer depend on a compiled Vite build.

### Added — Sprint 0.3 (design system)
- Brand palette engine (OKLCH): one brand colour → 12-step light and dark scales plus brand-tinted neutrals, with a WCAG 2.2 contrast guard (muted text ≥ 4.5:1, body text ≥ 7:1, focus ring ≥ 3:1, text on solid fills ≥ 4.5:1). Tested on 16 edge-case colours and 300 random colours.
- Generated design tokens (`resources/css/tokens.css`, `pnpm tokens`), semantic colour roles, type scale (Inter + Plus Jakarta Sans, self-hosted), radius, elevation, motion; light, dark and system themes without a flash on load; reduced-motion support.
- Components: Button, Input, Textarea, Field (label/hint/error wiring), Select, Checkbox, Switch, Radio group, Card, Badge, Avatar, Tooltip, Dropdown menu, Dialog (bottom sheet on mobile), Sheet, Tabs, Toast, Table, Skeleton, EmptyState, StatCard, PageHeader.
- Back-office AppShell: collapsible sidebar, top bar (search ⌘K, notifications, theme, account), mobile bottom navigation and menu sheet, skip link; navigation built from module `menu()` declarations.
- `/_design` showcase (not in production) with live brand colour lab and contrast report.
- Playwright end-to-end smoke and axe accessibility checks (desktop and mobile, light and dark) in CI, with failure annotations on the PR.
- `Table` requires a `label` and its scroll area is keyboard-focusable; `Field` supports composite controls (e.g. Select) via a render function.

### Added — Sprint 0.4 (production image)
- Cloud-agnostic production Docker image: multi-stage build, FrankenPHP + Octane, non-root user, tuned OPcache/JIT, liveness `HEALTHCHECK`, logs to stderr.
- One image, four roles via the entrypoint: `serve`, `worker`, `scheduler`, `migrate`.
- CI builds, lints (hadolint) and smoke-tests the image: health endpoints, home page, showcase not exposed, non-root, no `X-Powered-By`, Docker health `healthy`.
- `docs/deployment/docker.md`.

### Fixed
- Branch protection now applies to administrators (an admin merge could bypass the required `CI` check).

### Changed
- Removed the framework default `/up` route in favour of the Health module.
