# Running WURK in production (Docker)

The production image is cloud-agnostic: it runs the same on AWS, Azure or any container host. Cloud-specific infrastructure code is added once the hosting provider is chosen.

## Build

```bash
docker build -f docker/production/Dockerfile -t wurk:<version> .
```

Multi-stage build: production Composer dependencies (no dev packages) → production frontend build → FrankenPHP runtime with Laravel Octane. The final image contains no Composer, no Node and no test code.

## Run

One image, four roles — choose with the container command:

| Command | Purpose | Notes |
|---|---|---|
| `serve` (default) | Web server (Octane on FrankenPHP, port 8000) | Scale horizontally behind a load balancer |
| `worker` | Queue worker | Run as many as queue volume needs |
| `scheduler` | Task scheduler | Exactly **one** per environment |
| `migrate` | Run database migrations | One-off release step before rolling out new web containers |

```bash
docker run -d -p 8000:8000 --env-file production.env wurk:<version>
docker run --rm --env-file production.env wurk:<version> migrate
```

On start-up each role caches configuration, routes, views and events (`php artisan optimize`) from the runtime environment.

### Malware scanning (ClamAV) — from v0.14.0

Files visitors attach to forms are held until ClamAV scans them. Run it next to WURK's containers, on the same private network, with a volume for its virus list (it updates itself several times a day):

```bash
docker run -d --name clamav --network wurk --restart unless-stopped -v clamav:/var/lib/clamav clamav/clamav:1.4
```

Point WURK at it with `CLAMAV_HOST=clamav` (and `CLAMAV_PORT=3310`, the default). It needs about **1.5 GB of memory** and takes 1–2 minutes to load its virus list after starting; until then, and whenever it's unavailable, uploaded files stay "being checked" and are released automatically once it's back. Never expose port 3310 to the internet.

### Version-specific release steps

Run these once, after `migrate`, when deploying the version shown (any other command is passed straight to the container):

| From version | Step | Why |
|---|---|---|
| v0.14.0 | Start ClamAV and set `CLAMAV_HOST` — see “Malware scanning (ClamAV)” below | **Before** the new version serves traffic: files visitors attach to forms are held until ClamAV scans them. |
| v0.14.0 | `docker run --rm --env-file production.env wurk:<version> php artisan crm:link-existing` | Makes contacts of customers from before contacts existed (bookings, class places, orders). Safe to run again. |
| v0.15.0 | DNS: point `my.wurk.co.za` at the app (as `app.` and `admin.`); set `WURK_MY_DOMAIN` if it differs | **My WURK** — customers' businesses in one place. `my` is a reserved handle, so no business can take it. |
| v0.15.0 | `docker run --rm --env-file production.env wurk:<version> php artisan portal:index-memberships` | Adds customers who already have portal accounts to My WURK. Safe to run again. |
| v0.15.0 | **Never change `APP_KEY`** (check it's the same value as before) | Customers' bank account numbers for payouts are **encrypted with it**, and wallet, identity and handoff passes are signed with it — a new key makes waiting payouts' account numbers unreadable. |
| v0.15.0 | Confirm the scheduler runs (as already required) | Unused rewards expire nightly at 02:30 (`referrals:expire-wallets`). |
| v0.16.0 | **Never change `APP_KEY`** (as for v0.15.0) | Agents' **bank account numbers** are encrypted with it, and demo **"Make it yours" links** are signed with it. |
| v0.16.0 | Confirm the industry templates are installed: `docker run --rm --env-file production.env wurk:<version> php artisan website:install-templates` (safe to run again) | Agents make **demo sites** from them — with none installed, the template list is empty and no demo can be made. |
| v0.16.0 | Confirm the scheduler runs (as already required) | Demo sites: reminders on days 10 and 13, archived on day 14 (`agents:sweep-demos`, daily 03:15). |
| v0.16.0 | Super admin, in Admin: **Agent commission** (percentages per level — **0% until set**, so no commission accrues before); **Billing settings → refund window** (default 30 days); **Agent payouts → minimum payout** (none until set) | Agents earn nothing until percentages are set; commission becomes payable when a payment's refund window closes. |
| v0.16.0 | Add agents in **Admin → Agents** (or `php artisan agents:create <phone or email>`) | Each gets a referral link, a QR code and access to `app.wurk.co.za/agent`. Finance can see agents and run payouts; support can see agents. |
| v0.17.0 | **Never change `APP_KEY`** (as before) | Campaign links, demo links and encrypted data depend on it. |
| v0.17.0 | Once, after migrating: `docker run --rm --env-file production.env wurk:<version> php artisan credits:rollup-usage --since=2026-01-01` | Fills Admin → Metrics with past credit use from each business's own records (credit history doesn't start empty). Safe to run again. |
| v0.17.0 | Once, after migrating: `… php artisan plans:snapshot-mrr` | Records today's subscriptions; MRR movement, churn and cohorts build from here (they need history, which starts on deploy). |
| v0.17.0 | Confirm the scheduler runs (as already required) | New jobs: `plans:snapshot-mrr` (23:50), `credits:rollup-usage` (01:30), `campaigns:send-due` (every 5 min), `reports:send-summaries` and `billing:usage-summaries` (hourly), and the queue worker for auto top-ups. |
| v0.17.0 | In **Admin → Profitability**: provider cost per 1 000 SMS / WhatsApp / email, and the running cost per business per month (decision A) | Until set, profit reports say what's missing — they never guess. |
| v0.17.0 | Finance and super admins see **Admin → Metrics**, **Profitability** and **Reconciliation** (`metrics.view`, `costs.manage`); `permissions:sync` as on every deploy | New platform permissions. |
| v0.18.0 | Set `WURK_SUPPORT_EMAIL` (default `support@wurk.co.za`) — or set it later in **Admin → Support inbox → Targets and canned replies** | New tickets and business replies are emailed there. |
| v0.18.0 | Confirm the scheduler and the queue worker run (as already required) | New jobs: `success:score` (02:37), `success:weekly-digest` (Mondays 06:41), `data:purge-exports` (hourly), `data:purge-deleted` (03:23). Full data exports are built by the queue worker. |
| v0.18.0 | Once, after migrating: `… php artisan success:score` | Fills **Admin → Business health** straight away instead of waiting for the night. |
| v0.18.0 | In **Admin → WURK AI**: check the "Ask WURK" model, the free questions (50) and the monthly cap (500); add the rate card's "Ask WURK: a question" for each country other than South Africa | South Africa's 1-credit price is added by the migration; without a price elsewhere, questions stop at the free allowance. |
| v0.18.0 | Support staff see **Support inbox** and **Business health** (`support.manage`, `health.view`, added to the Support role; `health.view` also to Finance); `permissions:sync` as on every deploy | New platform permissions; businesses get `support.tickets`, `support.access` and `data.export` (owners have them). |
| v0.18.0 | Write the first help articles in **Admin → Help centre** | "?", Ask WURK and Contact support answer from them; Business health and the inbox show searches and questions that found nothing. |
| v0.20.0 | In **Admin → Providers**, set and **Test connection** for the AI keys (Anthropic first; OpenAI or Google as fallbacks) | The AI receptionist and WURK's sales assistant need a working AI provider. Without one, chats fall back to businesses' own answers and the contact page. |
| v0.20.0 | Optionally adjust the rate card's "AI receptionist: a reply" (Admin → Credits) | New daily jobs: `website:sweep-conversations` (03:25) deletes AI receptionist conversations that led nowhere after 30 days; `ai:sweep-sales-conversations` (03:35) deletes sales assistant conversations after 30 days. |
| v0.20.0 | In **Admin → Security → WURK's contact addresses**, check the contact, support and security addresses | Until saved there, the server's `WURK_CONTACT_EMAIL`, `WURK_SUPPORT_EMAIL` and `WURK_SECURITY_EMAIL` are used. |
| v0.20.0 | In **Admin → Watchtower → Uptime checker**, make a webhook address and paste it into Better Stack or UptimeRobot | Replaces `WATCHTOWER_UPTIME_TOKEN`, which is used only until an address is made there. The old address stops working when a new one is made. |
| v0.20.0 | On **staging**, run `php artisan wurk:eval-receptionist` with real AI keys before switching the receptionist on for customers; aim for 90%+ | Runs the 120-conversation evaluation set against the real model and lists anything it got wrong. It creates a demo salon (`eval-salon`) and spends a little on AI. Refuses to run in production. |
| v0.20.0 | When ready, switch on **WURK's sales assistant** in Admin → WURK AI (greeting, monthly cap) | Off until switched on. WURK pays for it; its replies and cost this month are shown there. |
| v0.20.0 | Nothing else: PayFast, Yoco, Ozow and Peach keys left in Admin → Providers can be ignored | They were never used (WURK's keys for these are in Admin → Payment methods) and no longer appear there. |
| v0.23.0 | Migrate as usual (`docker run … wurk:<version> migrate`): central (`package_versions.prepaid`, `billing_accounts` pay-as-you-go columns) and every shard (`payment_accounts.options`, `in_person_payments`) | Adds Paystack's channel choice, cash and in-person recording, and pay as you go. Existing packages stay invoiced (prepaid is off). |
| v0.23.0 | Confirm the scheduler runs (as already required) | New job `billing:charge-prepaid` (00:10 SAST) takes pay-as-you-go plans from credits; a queued job re-checks mobile money prompts every 20 s (queue `critical`). |
| v0.23.0 | **Paystack:** check that **SnapScan** and **Capitec Pay** are switched on in WURK's Paystack dashboard; to let owners pay WURK by them, add WURK's Paystack secret key in **Admin → Payment methods → Paystack** and switch it on | Owners then see "Capitec Pay, SnapScan, EFT or card" in Settings → Billing. The card on file and debit orders are unchanged. |
| v0.23.0 | **Flutterwave (optional):** once WURK's Flutterwave business account is approved, add its secret key and a webhook **secret hash** (16+ characters) in **Admin → Payment methods → Flutterwave**, and in Flutterwave set the webhook URL to `https://<app domain>/payments/notify/flutterwave` with the same hash | WURK's second gateway, for mobile money and cards. Off until set. |
| v0.23.0 | **On WURK's own WhatsApp account,** submit two utility templates (English): `wurk_credits_low` — "{{1}} has about {{2}} days of WURK left. Top up any amount from R20 to keep going: {{3}}" and `wurk_credits_out` — "{{1}}'s WURK credits have run out. Everything keeps working for {{2}} more days. Top up from R20: {{3}}" | Pay-as-you-go warnings use them; until approved they go by SMS. |
| v0.23.0 | Decide which packages are **pay as you go** (Admin → Packages → a package → Pay as you go) and the grace period (Admin → Invoice settings, default 3 days) | The daily price is the monthly price ÷ the days in the month, at the best credit pack's price per credit. At least one credit pack must be on sale. |
| v0.23.0 | Nothing to switch on for mobile money (M-Pesa, MTN MoMo, Airtel Money) in South Africa | They're offered only to businesses in the countries each provider serves, which arrive with the country packs (Sprint 25). |
| v0.24.0 | Migrate as usual (`docker run … wurk:<version> migrate`): central (`agent_onboardings`, `money_weekly_stops`, the `ai.read_slip` credit rate) and every shard (`setup_interviews`, `money_invoices`, `money_reminder_stops`, `money_cashups`, `money_expenses`, `money_slips`, `money_settings`, `sales.settled_at` / `settled_how`) | Adds setup by talking or from what's online, the share kit, agent onboarding, and the new Money module. |
| v0.24.0 | Confirm the scheduler runs (as already required) | New jobs: `money:remind-invoices` (daily, 09:00 SAST) and `money:weekly-summaries` (Mondays, 08:20 SAST). |
| v0.24.0 | **Google Places:** create an API key with *Places API (New)* enabled (and billing on the Google Cloud project), and add it in **Admin → Providers → Google Places** | Lets owners and agents fill in setup from a Google listing (about $0.02–0.03 a lookup). Without it, websites and Facebook pages still work. |
| v0.24.0 | **On WURK's own WhatsApp account,** submit two utility templates (English): `wurk_weekly_summary` — "Hi {{1}}, last week at {{2}}: {{3}} {{4}} {{5}} Tip: {{6}} Reply STOP to stop these Monday summaries." and `wurk_agent_handover` — "{{1}} is ready on WURK, {{2}}. Sign in with your number: {{3}} Share your business: {{4}}" | The Monday summary waits for its template; the hand-over goes by SMS until approved. Each business's `wurk_payment_request` template is submitted automatically on its own account. |
| v0.24.0 | Optional: give WURK AI's new task **Reads expense slips** a provider that reads images (Anthropic or OpenAI) in **Admin → AI** | Defaults to Anthropic Claude Haiku. Reading a slip costs the business 1 credit (rate card: `ai.read_slip`). |
| v0.24.0 | Give cashiers the new permission **Count the till (cash-up)** in a role if they should cash up | Owners have it already; the rest of Money stays with `money.manage`. |
| v0.25.0 | Migrate as usual: central (`translations`, `translation_glossary`, `translation_languages`) and every shard (`contacts.locale`) | Adds languages, reviews and customers' languages. |
| v0.25.0 | Make sure the queue worker runs (as already required), and that WURK AI has a provider key in **Admin → WURK AI** | **Admin → Settings → Languages → Draft the missing texts with WURK AI** runs as a queued job, about 2,500 texts a language. |
| v0.25.0 | Give each reviewer a staff account with the role permission **Languages: review** (and HQ **Languages: manage**) | Reviewers correct texts in context; a language stays "preview" until 95% is reviewed. Start with isiZulu, Afrikaans and French. |
| v0.25.0 | Serve `/sw.js` and `/manifest.webmanifest` from the web root with no long cache on `sw.js` (the default static rules do) | The app opens without a signal and can be added to the home screen. Signing out clears what the phone kept. |
| v0.25.0 | CDN or proxy in front of business sites: forward the `Save-Data` header and the `wurk_lite` / `wurk_lang` cookies, and respect `Vary: Save-Data, Cookie` | Lite mode and the customer's language on business sites. |
| v0.26.0 | Migrate as usual: central (country packs and `country_tax_rates`, `reach_numbers`, `ussd_sessions`, `business_verifications`, `help_video_captions`) and every shard (`review_requests`, `reviews`, `review_stops`, `review_settings`, `refunds`, `money_invoices.refunded_cents`, `assistant_questions.channel`) | Country packs, USSD and SMS booking, reviews, the verified badge, refunds, the help line and video subtitles. |
| v0.26.0 | **Admin → Countries:** check each pack and **confirm its tax rate** before opening it (PwC figures, marked *to confirm*). Ghana's NHIL and GETFund levies are included | Only South Africa is open. A country opens for sign-up only with a confirmed rate in force. |
| v0.26.0 | **Africa's Talking:** open an account, request the shared USSD code and an SMS number per country, then in **Admin → Providers → USSD** add the username, API key, service code (e.g. `*384*1234#`), a long random webhook token and the SMS number. In Africa's Talking, set the USSD callback to `https://<app domain>/webhooks/ussd/africastalking/<token>` and the SMS incoming-messages URL to `https://<app domain>/webhooks/sms/africastalking/<token>` | USSD and SMS booking. Until then, Settings → Bookings by phone shows each business's number, ready for when the code is live. |
| v0.26.0 | **Admin → Credits:** set the per-country rates for `ussd` (each session; South Africa starts at 2 credits) and SMS | Businesses pay for USSD sessions and SMS replies in credits. With no rate, callers are asked to call the business. |
| v0.26.0 | WhatsApp templates: `wurk_review_request` is submitted automatically on each business's connected account. On **WURK's own** WhatsApp account, submit the utility template `wurk_help_reply` (English): "WURK support replied about {{1}}: {{2}} Reply here to continue." | Review requests on WhatsApp (SMS until approved); support's replies on the help line after 24 hours. |
| v0.26.0 | Keep `storage/app/trust` on a persistent, private, backed-up volume (like `storage/app`), never served by the web server | ID documents for the verified badge, encrypted with `APP_KEY`, deleted 90 days after the decision. Keep `APP_KEY` safe: without it the documents can't be read. |
| v0.26.0 | Confirm the scheduler and the queue worker run (as already required) | New jobs: `reviews:ask` (hourly at :20) and `trust:purge-documents` (daily, 03:10 SAST). Help-line answers and subtitle drafts run on the queue. |
| v0.26.0 | Give the people who check IDs a staff role with the permission **Check businesses’ ID documents and registrations**, and the language reviewers **Languages: review** | Admin → Verification and Admin → Languages → Video subtitles. |
| v0.26.0 | Help centre: add English subtitles (WebVTT) to each video in **Admin → Help centre**, then **Admin → Languages → [language] → Draft the missing subtitles** | Subtitles in each reviewed language, shown once approved. |
| v0.27.0 | Migrate as usual: central (`push_vapid_keys`, `push_subscriptions`) and every shard (`push_preferences`, `shifts`, `shift_requests`, `shift_settings`, `clock_entries`, `commission_rates`, `tips`, `loyalty_settings`, `loyalty_cards`, `loyalty_purchases`, `loyalty_rewards`, `bookings.started_at`, `bookings.reward_cents`) | Owner actions on WhatsApp, the WURK app and push, Needs you now, shifts and loyalty. |
| v0.27.0 | Nothing to set for push: WURK makes its Web Push (VAPID) keys on first use and keeps them encrypted with `APP_KEY`. Serve the app over HTTPS on the app domain | Push notifications. Changing `APP_KEY` means everyone switches notifications on again. |
| v0.27.0 | **Admin → WURK AI:** check the daily cap for owner requests on WhatsApp (50 per business by default) and the receptionist rate in **Admin → Credits** | Owner AI on WhatsApp is charged at the receptionist's rate. |
| v0.27.0 | WhatsApp templates: `wurk_loyalty_progress` is submitted automatically on each business's connected account ("Thank you, {{1}}! Your {{2}} loyalty card: {{3}}") | Customers are told their loyalty progress after each stamp. Until it's approved, they see it on their account and by replying "my card". |
| v0.22.0 | Nothing extra: `website:install-templates` now also imports WURK's chosen demo photo for every photo place (215, from Pexels; already-present photos are skipped). The server needs outbound HTTPS to `images.pexels.com` | New installations' designs show real photos at once. A photo that can't be fetched is listed and keeps its stand-in, and the install still succeeds. Set `WURK_TEMPLATE_DEMO_PHOTOS=false` or pass `--no-photos` to skip. |
| v0.21.0 | After migrating: `docker run --rm --env-file production.env wurk:<version> php artisan website:install-templates` (safe to run again) | Adds the 14 new categories and 54 new designs (78 in all). Existing businesses keep their sites. |
| v0.21.0 | HQ, in **Admin → Templates → a design → Demo photos**: add licensed photos for each photo place (or `php artisan website:import-template-photos photos.json` on a server that can reach Nappy, Pexels and Unsplash) | Until then, designs show placeholders or the earlier stand-ins. See `docs/templates/demo-photos.md`. |
| v0.21.0 | Nothing to do if you build from `docker/production/Dockerfile`: the image now includes **ffmpeg**. A custom image must install `ffmpeg` (with `ffprobe`), or point `WURK_FFMPEG` / `WURK_FFPROBE` at them | Website videos (video hero, Video section) are re-encoded on upload (H.264, ≤ 1280 wide, ≤ 30 s, no phone metadata) and get a still poster. Without ffmpeg, video uploads are refused with a clear message; photos are unaffected. Re-encoding a 20 MB clip takes a few seconds of one CPU. |
| v0.22.0 | WURK, in Meta: Business Verification for WURK's business portfolio, App Review for `whatsapp_business_management` and `whatsapp_business_messaging`, and Tech Provider onboarding; then an **Embedded Signup configuration** (WhatsApp, with the WhatsApp Business app onboarding feature) | Lets businesses connect their own numbers. Until approved, Meta allows up to 10 sign-ups a week in development mode. |
| v0.22.0 | In **Admin → Providers → WhatsApp**: add the **Meta app ID** and the **Embedded Signup configuration ID** next to the app secret; in Meta's app, set the webhook to `https://app.<your domain>/webhooks/messaging/meta` with the verify token, and subscribe to **messages** and **message_template_status_update** | Until both IDs are set, owners see "Coming soon" in Settings → WhatsApp. Customers' messages, statuses and template reviews for every connected business arrive at this one address. |
| v0.22.0 | On **WURK's own** WhatsApp account, submit the utility template `wurk_owner_summary` (English): `Good evening {{1}}, here's {{2}} for tomorrow: {{3}} bookings. Today: {{4}} new orders, {{5}} received, {{6}} chats waiting. {{7}}` | The owners' evening summary (Settings → WhatsApp). Until Meta approves it, summaries aren't delivered. Businesses' own templates (`wurk_follow_up`, `wurk_reminder`) are submitted automatically on each connected account. |
| v0.22.0 | In **Admin → WURK AI → Voice notes**: check the speech-to-text model (OpenAI `gpt-4o-transcribe`, fallback Gemini) and its per-minute prices; make sure the OpenAI or Google key is set in Admin → Providers. On staging, run `php artisan wurk:eval-receptionist --category=whatsapp` | Customers' voice notes are transcribed with it. The rate card has "AI: a voice note transcribed" (0.1 credits in South Africa — adjust in Admin → Credits). |
| v0.22.0 | Optionally set `WURK_VOICE_NOTE_DAYS` (default 30) | Voice notes' audio is deleted after this many days by the daily `website:sweep-conversations`; the transcript stays. |
| v0.22.0 | Confirm the scheduler runs (as already required) | New jobs: `messaging:sync-whatsapp-templates` (hourly) and `messaging:owner-summaries` (every 15 minutes). |
| v0.19.0 | Set `WATCHTOWER_EMAIL` (comma-separated) — or set the addresses later in **Admin → Watchtower** | Watchtower emails critical and high problems straight away and a daily report at 08:00 SAST. With no address, problems are still recorded in Admin but nobody is emailed. |
| v0.19.0 | Build the image with `--build-arg WURK_COMMIT=$(git rev-parse HEAD)`; keep `VERSION` in step with the release (or set `WURK_RELEASE`) | Every problem shows the release and commit it happened in; "fixed in" and regressions depend on it. |
| v0.19.0 | Confirm the scheduler runs on exactly one server (as already required); `permissions:sync` | New jobs: `watchtower:check` (every minute — system checks and alerts), `watchtower:digest` (08:00 SAST), `watchtower:sweep` (03:47). New platform permissions `watchtower.view` / `watchtower.manage` and an **Engineering** role; Support can view Watchtower. |
| v0.19.0 | Point the external uptime checker (Better Stack, Sprint 17) at `/health/ready`, a public site, and `/health/heartbeat` | `/health/heartbeat` returns 503 when the scheduler has stopped — the one problem Watchtower can't email about itself. It's separate from `/health/ready` so a stopped scheduler never takes web servers out of the load balancer. |
| v0.19.0 | Set `WATCHTOWER_UPTIME_TOKEN` (16+ letters and digits) and add the webhook `https://<app domain>/_wurk/uptime/<token>` in the uptime checker (Better Stack: incident webhook; UptimeRobot: alert contact "Webhook") | Outages are recorded for uptime %, and when one ends HQ gets what Watchtower saw while WURK was down. Without the token the endpoint doesn't exist. |
| v0.19.0 | Check **Admin → WURK AI → Watchtower** (default Claude Sonnet) and `WATCHTOWER_AI_DAILY_CAP` (50) | Watchtower's AI explains problems on WURK's own AI account; set `WATCHTOWER_AI=false` to turn it off. |
| v0.19.0 | Nothing to set for security headers (`WURK_CSP=enforce` by default). If a page misbehaves after deploying, set `WURK_CSP=report-only`, check the browser console, and tell engineering | The Content Security Policy blocks any script WURK didn't send with this request's nonce. Uploaded files are allowed from each disk's `url`/`endpoint` and S3 bucket; assets from `ASSET_URL`. |
| v0.19.0 | **Set `TRUSTED_PROXIES`** to the load balancer's addresses (or `*` when the servers are reachable only through it) | New setting: until it's set, WURK sees the load balancer's IP for everyone (rate limits would treat all visitors as one) and plain HTTP (no HSTS, no secure cookies). |
| v0.19.0 | Set `WURK_SECURITY_EMAIL` (default `security@wurk.co.za`) to a mailbox someone reads | Shown in `/.well-known/security.txt` and on wurk.co.za/security; researchers report problems there. |
| v0.19.0 | In the GitHub repository settings (owner): turn on **Dependabot security updates** and **secret scanning push protection** | `.github/dependabot.yml` covers routine updates; security updates and push protection are repository settings. |
| v0.19.0 | Nothing to do: the image now starts FrankenPHP with `docker/production/Caddyfile` (Octane's own plus security headers on static files) | When upgrading Octane, compare the file with Octane's `stubs/Caddyfile`. |
| v0.19.0 | **Queue workers must take the three queues**: the image's `worker` command already runs `queue:work --queue=critical,default,bulk`; a custom worker command must too | Booking messages are now on `critical` and campaigns on `bulk` — a worker on `default` alone would never send them. Run at least two workers so a long campaign batch never blocks booking messages. |
| v0.19.0 | Confirm the scheduler runs (as already required). For restores, the `RESTORE_DB_*` settings point at a restored backup only while one is being used (see docs/runbooks/restore.md) | New jobs: `tenants:clear-moved` (04:13) removes a moved business's old copy after 7 days; `agents:sweep-demos` now also removes archived demo sites for good after 7 days. |
| v0.19.0 | **Run the release step as usual (`docker run … wurk:<version> migrate`)** — it now migrates the shards after the central database. Optionally set `WURK_CANARY_SHARD` to the shard that should go first. Take a backup first: the 2038 migration rewrites every table with a date column (seconds with today's data) | Before this, the image's `migrate` ran only the central database's migrations. Shards are migrated one at a time, the canary first, each health-checked; a failure stops the rollout and rolls back that shard. |
| v0.19.0 | Once backups are set up (Sprint 17): have each backup job run `php artisan system:backup-taken <target> --kind=… --size=…`, and set `BACKUP_TARGETS` (e.g. `central,shard-1,files`); `BACKUP_MAX_AGE_HOURS` defaults to 26 | Admin → System shows the latest backup of each part; Watchtower raises a problem when one is overdue. Until `BACKUP_TARGETS` is set, nothing is checked. |
| v0.19.0 | Staging only: set `WURK_LOAD_TEST_TOKEN` before a load test, and unset it after (never in production — it's refused there) | Lets the k6 machines play many separate visitors; see docs/performance/load-testing.md. |
| every deploy | `docker run --rm --env-file production.env wurk:<version> php artisan permissions:sync` | Checks built-in roles and removes permissions modules no longer declare from custom roles (built-in roles update by themselves — v0.15.0 gives staff *Record sale* and branch managers *approve sales*). |

## Required environment

| Variable | Example | Notes |
|---|---|---|
| `APP_KEY` | `base64:…` | From the secrets manager. Never baked into the image |
| `APP_URL` | `https://wurk.co.za` | |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | Central database; tenant databases arrive with Sprint 1 |
| `REDIS_HOST`, `REDIS_PASSWORD` | | Cache, sessions, queues |
| `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | `redis` | |
| `MAIL_*` | | Transactional email |

Defaults already set in the image: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr` (logs go to the platform's log collector), `OCTANE_WORKERS=auto`, `OCTANE_MAX_REQUESTS=500`.

## Health checks

| Endpoint | Use |
|---|---|
| `GET /health/live` | Liveness — the process is up. Used by the image's built-in `HEALTHCHECK` |
| `GET /health/ready` | Readiness — database and cache reachable. Use for load balancer / orchestrator readiness probes; returns `503` when degraded |

## Security

- Runs as an unprivileged user (UID 10001); listens on 8000, so no root capabilities are needed.
- `expose_php` off; OPcache with timestamp validation disabled (code is immutable inside a container).
- Secrets only through environment variables / the cloud secrets manager.
- TLS terminates at the load balancer / CDN (Cloudflare for SaaS, ADR-0004).

## Audit trail: database privileges

The audit trail is append-only in the application; enforce it in the database too. The application's database user should have only `SELECT, INSERT` on `audit_events` and `SELECT, INSERT, UPDATE` on `audit_chain_heads`, on the central database and on every shard. Run `php artisan audit:verify` on a schedule (for example nightly) and alert on a non-zero exit code.

## CI verification

Every PR that touches Docker files or dependency lock files builds the image, lints the Dockerfile (hadolint), starts a container and checks: liveness and readiness return 200, the home page renders, the design showcase is **not** exposed (404), the process is not root, no `X-Powered-By` header, and Docker reports the container `healthy`.

## Server-side rendering (wurk.co.za)

The marketing site is rendered on the server so search engines get full HTML. Run the same image in its `ssr` role as a separate container (or sidecar) and point the web containers at it:

```sh
docker run -d --name wurk-ssr wurk:latest ssr          # listens on 0.0.0.0:13714
# web containers:
INERTIA_SSR_URL=http://wurk-ssr:13714
```

Only requests for the root domain use it; the back offices render in the browser. If the SSR container is down, pages still work and render in the browser, losing only the search-engine benefit until it's back. Keep it internal (no public port).
