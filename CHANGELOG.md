# Changelog

All notable changes to WURK are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [Unreleased]

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
