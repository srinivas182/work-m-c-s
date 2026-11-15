# Sprint 18 — Hardening (specification)

**Status:** 🚧 in progress. Approved by WURK (14 Nov 2026), including every recommendation and recommended addition.
**Plan:** penetration test; load testing at scale; shard rollout rehearsal; DR drill; accessibility; design polish; UAT (docs/sprint-plan.md). Runs **before** Sprint 17 (cloud) by WURK's decision of 12 Nov 2026.
**PRD:** §15 (99.9% availability; PITR + offsite backup in a second region, **RPO ≤ 15 min, RTO ≤ 4 h**; **p95 < 300 ms**; LCP < 1.5 s, Lighthouse ≥ 90; built for 500k businesses; fair queuing; annual pen test + dependency scanning; **WCAG 2.2 AA**; POPIA, data in South Africa), §18 acceptance criteria — especially **7** (automated isolation tests) and **12** (load tests at target scale, DR restore drill, pen test with no open critical/high).
**Carried in:** permanent purge of archived agent demos (Sprint 14 decision); the **Year 2038** `TIMESTAMP` issue noted in the plan.

## The honest constraint: hardening before the cloud

Sprint 17 builds the production and staging environments. Some hardening only means something **on those environments** — an external pen test, a load test at real scale, a restore drill from real backups. So this sprint is split cleanly:

- **Built and proven now (in the repo and CI):** everything that makes WURK secure, fast and recoverable by design — security headers, scanning on every change, an automated cross-business isolation test over every route, load-test scripts and performance budgets, backup/restore and shard tooling with rehearsals on a local multi-shard setup, accessibility checks on every screen, design polish, the UAT script.
- **Run for real in Sprint 17 (on staging, then production):** the external pen test, the full-scale load test, the timed DR drill. The tooling, scripts and runbooks from this sprint are what Sprint 17 runs — so criterion 12 is closed there, with evidence.

## What the market leaders do (and where they fall short)

| Area | Leaders | What they do well | Where WURK can do better |
|---|---|---|---|
| Security posture | **Shopify** (HackerOne bounty, public security page), **Stripe** (security.txt, strict CSP), Xero (ISO 27001, trust centre) | Published disclosure policy, strict headers, scanning in the pipeline, trust page for buyers | A **plain-language Trust page** for SA small businesses (where data lives, POPIA, backups, who can access it — incl. the 16.4 consent-based support access) alongside the technical security.txt |
| Tenant isolation | Salesforce (multi-tenant query guards), Shopify (pod isolation) | Isolation enforced in the data layer, not only in code review | An **automated test that tries every route with another business's IDs** on every change — criterion 7 proven continuously, not once |
| Performance | Shopify (published p95 budgets per page), Stripe (latency SLOs) | Budgets enforced, regressions caught before release | **Query-count and response budgets in CI** for the busiest screens and the public site, plus repeatable k6 scripts for staging |
| Resilience | Atlassian / GitLab (public RPO/RTO, published DR drills), AWS Well-Architected | Stated targets, drills on a schedule, runbooks | **RPO/RTO shown to WURK in Admin** (last backup, last successful restore test) — not just in a document |
| Accessibility | **GOV.UK** (accessibility statements), Shopify Polaris, Atlassian | Automated + manual checks, published statement | **axe on every screen in CI**, WCAG 2.2-specific checks (target size, focus not hidden), and accessible output from the **24 templates** businesses publish — most site builders leave that to the owner |
| UAT | Most SaaS: internal QA only | — | A **written acceptance script for criteria 1–12** run with WURK, every result recorded |

## 18.0 Watchtower — platform monitoring with AI incident reports (added by WURK, 14 Nov 2026, before 18.1)

**Ask:** one AI agent that watches the whole platform — bugs, crashes, downtime — and emails a report to the address HQ sets in Admin, written so it can be pasted straight to the engineering team (Claude) to fix.

### What the market leaders do

| Tool | What it does well | Gap for WURK |
|---|---|---|
| **Sentry** (+ "Seer" AI fixes) | Groups errors into issues, release tracking, regressions, AI root-cause | Error data (often with personal data) leaves South Africa — POPIA; per-event pricing at scale; knows nothing about businesses, packages or shards |
| **Laravel Nightwatch**, Flare | Laravel-native: requests, jobs, queries, scheduled tasks; Flare suggests fixes | Same data-residency question; no business context |
| **Better Stack**, UptimeRobot | External uptime checks from many locations, status pages, on-call | Only up/down — no idea *why* |
| Datadog / New Relic | Everything, with anomaly detection | Expensive; heavy for this stage |

**Design:** build Watchtower **inside WURK** (data stays in SA, knows which businesses and packages are affected, uses the existing `Ai` module), **plus one external uptime checker** — a platform that is down cannot report its own downtime.

### What it watches

| Signal | How |
|---|---|
| **Crashes / bugs** | Every reported exception (web, queue, console, scheduler) → **fingerprinted** (exception class + file:line + normalised message) into an **incident**; counts, first/last seen, release, businesses affected, URL/route, surface. |
| **Front-end crashes** | React errors and unhandled promise rejections reported from the browser (rate-limited endpoint), grouped the same way. |
| **Failed jobs** | Queue job failures, retries exhausted. |
| **Scheduler & queues** | Heartbeat: each scheduled command records its last run; **missed runs** alerted. Queue **backlog/age** per queue above a threshold. |
| **Infrastructure** | Each shard + central DB, Redis, storage, mail and SMS providers, checked every minute (extends the Health module's checks). |
| **Slowness** | Requests over 2 s and endpoints whose p95 crosses 300 ms (PRD budget), sampled. |
| **Downtime** | **External checker** hits `/health/ready` and the public site every minute from outside; emails directly when WURK can't. Watchtower records **uptime %** from its results (webhook) and shows it. |

### The AI agent

For each **new** incident (and each **regression** — an incident back after it was marked fixed, or reappearing in a new release), the agent:
1. Reads the stack trace, the code around the failing lines (from the deployed release), recent similar incidents and the release notes.
2. Writes a **plain summary** (what broke, who is affected, how often since when), **likely cause**, **severity** (critical / high / normal — by spread, money paths, sign-in, data), and a **suggested fix**.
3. Produces a **"Fix request" block** — a self-contained Markdown report (incident ID, release/commit, route, trace, the relevant code lines, frequency, affected packages, reproduction hints) **with personal data removed** (emails, phones, names, IDs masked) — ready to paste here for a fix.

Uses the `Ai` module (WURK's own key, not businesses' credits); a daily cap; if AI is unavailable the report still goes, without the analysis.

### Alerts

- **Email to the address set in Admin → Settings → Watchtower** (one or more). Critical: immediately. High: immediately, grouped (one email per incident, not per error). Normal: in a **daily digest** (08:00 SAST).
- **No floods:** one alert per incident; re-alert only on regression, a 10× spike, or a severity rise. Quiet incidents auto-resolve after 7 days.
- **Downtime/recovery** emails from the external checker; Watchtower adds a post-incident summary when the platform is back (what failed, for how long).
- Optional later: WhatsApp/SMS for critical (credits), Slack.

### Admin → Watchtower

Incidents list (status: new / acknowledged / fixed in release X / ignored; severity; count; businesses affected), incident page (summary, AI analysis, trace, timeline, **Copy fix request** button), uptime, failed jobs, scheduler heartbeats, slow endpoints. Marking **"Fixed in v0.x"** makes a reappearance a regression. Permission `watchtower.view` / `watchtower.manage` (super-admin + a new `engineering` role).

### Decisions

| # | Decision | Status |
|---|---|---|
| F | Build in-house vs Sentry/Nightwatch | **In-house** (POPIA: data stays in SA; business context; no per-event bill). Revisit if WURK later wants Sentry for depth. **Accepted (WURK, 14 Nov 2026).** |
| G | External uptime checker | **Better Stack** (free tier: 10 monitors, 3-min checks, email; paid for 30-s checks + status page) or **UptimeRobot** (free: 50 monitors, 5-min). Recommend **Better Stack**; WURK creates the account when the cloud is live (Sprint 17) — Watchtower is wired for its webhook now. **Accepted (WURK, 14 Nov 2026).** |
| H | Auto-create GitHub issues | **Not now** — email + "Copy fix request" first. Later: a GitHub issue per critical incident (needs a GitHub token in production). **Accepted (WURK, 14 Nov 2026).** |

### Split

- **18.0a** Incident capture: fingerprinting, incidents table (central), back-end + front-end errors, failed jobs, scheduler heartbeats, queue backlog, infra checks; Admin → Watchtower list/detail; settings (emails); immediate + digest emails with anti-flood rules; personal-data masking.
- **18.0b** AI analysis & fix request: the agent (code context from the release, severity, cause, fix), regressions by release, slow endpoints, external-checker webhook + uptime, post-downtime summary.

## Parts

| Part | What |
|---|---|
| **18.1 Security baseline & scanning** | **Security headers** on every response (HSTS, CSP with nonces for the back office, `frame-ancestors`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`) — the public site and record links already have them; one middleware makes them universal, with a test per surface. **CI scanning**: `composer audit` and `pnpm audit` (fail on high/critical), **secret scanning** (gitleaks) on every PR, **static security rules** (Semgrep/PHPStan security rules), weekly **Dependabot**. **OWASP ZAP baseline** (passive DAST) against the e2e app in CI. **security.txt + responsible-disclosure page**; a plain **Trust page** on wurk.co.za. **OWASP ASVS Level 2 self-assessment** recorded in docs (each control: how WURK meets it, with the test). Session and cookie review (Secure, HttpOnly, SameSite; rotation on sign-in and MFA; idle timeout for staff). **Rate-limit coverage test**: every public POST and sign-in/OTP route must have a throttle — a new unthrottled route fails CI. |
| **18.2 Isolation sweep (criterion 7)** | An **automated isolation test over every tenant route**: two businesses on different shards, every route enumerated from the router, called as business A's owner with business B's IDs → must be 403/404, never B's data. Same for signed public links (bookings, records, payments, portal) with tampered or cross-business tokens, file downloads, exports and the Ask WURK tools. New routes are covered automatically. Plus: **mass-assignment check** (every model guarded or validated input only), **IDOR check on files** (every disk path goes through a tenant check). **From the ASVS review (18.1b):** email the person on a sign-in from a new device and on a password or two-step sign-in change; ask for the password or a code again before turning two-step sign-in off or starting a full data export. |
| **18.3 Performance & load** | **Synthetic data generator** (`wurk:seed-load`): N businesses across shards with realistic customers, bookings, orders, pages. **Budgets in CI** on the busiest screens and public pages: query count (N+1 guard), and response time on the seeded data. **k6 scripts** in the repo for the main journeys (public site, booking, checkout, back-office day view, sign-in), with thresholds p95 < 300 ms, error rate < 0.1%; a local multi-shard run now, the **full-scale run on staging in Sprint 17**. **Fair queuing test**: one business sending a 50k campaign doesn't delay another's booking confirmation. Fixes for whatever the budgets find (indexes, eager loading, caching). |
| **18.4 Resilience: backups, DR, shards** | **Backup & restore tooling**: per-shard full + binlog (PITR) backups, central DB, file storage; `wurk:restore` to a point in time on a separate host; **restore drill on the local multi-shard setup, timed** against RPO ≤ 15 min / RTO ≤ 4 h, with runbook. **Single-business restore** from a backup (WURK's side of decision 16-C). **Shard rollout rehearsal**: migrations rolled **canary shard first**, health-checked, then the rest; stop-and-roll-back on failure. **Move a business between shards** (`tenants:move`, copy → verify counts/checksums → switch → keep source read-only 7 days) — needed to rebalance at scale. **Year 2038**: `TIMESTAMP` → `DATETIME` on all tables, done now while there's little production data. **Admin → System**: last backup per shard, last restore test, migration state per shard. **Archived agent demos purged permanently** (Sprint 14 carry-over), using the tested purge path. |
| **18.5 Accessibility (WCAG 2.2 AA)** | **axe on every back-office and public screen** in CI (route crawl, not a sample), both themes, desktop and mobile. WCAG 2.2-specific checks: **target size ≥ 24 px**, **focus not obscured** by sticky headers/panels, accessible authentication (OTP/paste allowed), dragging alternatives (calendar, page editor). **Keyboard journeys** e2e: sign in, book, checkout, editor, Ask WURK. **All 24 templates** checked for contrast with each colour preset businesses can choose. **Accessibility statement** published. Fixes for everything found. |
| **18.6 Design polish & UAT** | **Consistency pass** over every screen: empty states, loading skeletons, error pages (404/403/419/500/503 in WURK's style), plain-language messages, mobile layouts, dark mode. **UAT script for criteria 1–12**: each step, expected result, who runs it; criteria needing the cloud (2 custom domain, 12 scale/DR/pen test) marked for Sprint 17. **Bug bash with WURK**; every finding logged and fixed or scheduled. |

## Recommended additions (from the review)

1. **Trust page** for businesses (plain language: where data lives, POPIA, backups, who at WURK can see your data and how you control it). *Sells to SA small businesses and costs little.*
2. **Isolation test over every route, automatically** — new routes covered without anyone remembering to.
3. **Backup/restore status in Admin** (last backup, last successful restore test per shard) — a drill that isn't visible quietly stops happening.
4. **Move a business between shards** — not in the plan, but needed before 500k businesses (rebalancing a busy shard); cheaper to build and rehearse now.
5. **Year 2038 fix now**, before production data grows.
6. **Accessible templates** — businesses' published sites meet WCAG 2.2 AA out of the box (most builders leave this to the owner).

## Decisions

| # | Decision | Status |
|---|---|---|
| A | **External pen test** — firm and timing | A **CREST-accredited firm** (several in SA), grey-box test of staging in **Sprint 17**, once it mirrors production; retest after fixes. WURK to engage the firm (≈ 2–3 weeks lead time — start now). **Accepted (WURK, 14 Nov 2026).** |
| B | **Load-test target numbers** | Use the PRD: p95 < 300 ms, error rate < 0.1%, at **10,000 businesses and 1,000 requests/second** on staging (well above launch; the architecture is built for 500k). **Accepted (WURK, 14 Nov 2026).** |
| C | **Bug bounty** | **Not at launch** — security.txt + disclosure policy now; a private bounty programme after the first pen test is clean. **Accepted (WURK, 14 Nov 2026).** |
| D | **UAT participants** | WURK team + **2–3 friendly real businesses** (e.g. a salon, a shop, a practice) for criteria 1, 4, 5. **Accepted (WURK, 14 Nov 2026)** — the businesses to be named by WURK before UAT. |
| E | **2038 migration** | Do it now. **Accepted (WURK, 14 Nov 2026).** |

## Order

**18.0a → 18.0b** (Watchtower) → 18.1 → 18.2 → 18.3 → 18.4 → 18.5 → 18.6, small parts, one full CI run each. Release as **v0.19.0**. Before closing: every spec item and agreed addition verified against code and tests. Then **Sprint 17** (cloud & going live), which runs the external pen test, the full-scale load test and the timed DR drill on staging using this sprint's tooling.

## Progress

| Part | PR | Notes |
|---|---|---|
| 18.0a Watchtower — capture & alerts | #180 | New central Watchtower module. Grouping key: exception class + first frame in WURK's own code + message with IDs/numbers taken out (browser crashes: message + script line with build hashes removed). Alerts are sent by the every-minute check, not inside the failing request. Recipients are set per deployment (`WATCHTOWER_EMAIL`) and in Admin — no address is built into the code. The fix request is in 18.0a already (without the AI analysis, which 18.0b adds). |
| 18.0b Watchtower — AI, slow pages, uptime | #181 | The AI is a background job (never blocks checks); high problems wait up to 3 minutes for it, critical never. Code context is read from the running release, only from `modules/` and `app/` (never vendor). Slow pages are sampled (2% of requests, every request over 2 s) and written after the response; the 300 ms check is "over 5% of the hour's sample above 300 ms". Outage start isn't emailed by Watchtower (the uptime checker already did); the end is, with what was seen meanwhile. A flaky axe finding on Support settings (mobile, a button mid-update) was seen once — for 18.5. |
| 18.1a Security baseline | #182 | 18.1 split: 18.1a = dependency advisories fixed, security headers + CSP, secure cookies, rate-limit coverage test; 18.1b = scanning in CI (audits, secrets, static rules, ZAP), Dependabot, security.txt, disclosure and Trust pages, ASVS L2 self-assessment. CSP uses a nonce per request (Vite's `useCspNonce`); inline styles stay allowed (React style attributes). Every e2e test now fails on a CSP violation (shared fixture). `happy-dom` (bundled by the page editor, never run by WURK) pinned to the patched major instead of waiting for the editor. |
| 18.1b Security scanning, disclosure, trust | #183 | Semgrep runs WURK's own rules (`.semgrep.yml`), not the online registry: deterministic, offline, each rule tested against a sample of bad code; two safe matches carry `nosemgrep` with the reason. ZAP runs in the performance-gate job (shortest); static files are out of scope for header rules in CI because `artisan serve` sends them without Laravel — production's Caddyfile adds the headers. The ASVS self-assessment found two small gaps (security-event emails; re-confirming before turning MFA off or exporting) — scheduled with 18.2. |
| 18.2a Isolation sweep | #184 | 18.2 split: 18.2a = the sweep (criterion 7) + mass-assignment rule; 18.2b = security-event emails + "confirm it's you" before a full export (from the ASVS review). Rows are generated from the schema (one per table, parents first, every text column marked) rather than per-module factories, so new tables are covered without anyone writing seed code; same-shard is the stronger case (cross-shard isolation is structural). Turning MFA off already requires a current authenticator code — the ASVS gap was only the export. |
| 18.2b Security emails, "Confirm it's you" | #185 | "New device" = a browser-and-system pair this person hasn't signed in with before (from their device list); the first sign-in never emails. A sign-in marks the session (`auth.signed_in_at`) so the 15-minute window isn't opened by a remembered device (whose session restarts look fresh). Turning MFA off already needed a current authenticator code. Only the full export uses `confirmed` for now; payout/bank details and team roles are candidates once WURK confirms. |
| 18.3a Fair queues, no N+1 | #186 | 18.3 split: 18.3a = queues, campaign batches, queued booking messages, query budgets; 18.3b = load data generator, k6 scripts, local smoke run, performance doc. Found while planning: "Send now" sent every message inside the request, and the scheduler sent all businesses' due campaigns one after another in one process. Fairness by **chaining** (each batch queues the next) rather than per-business queues — no new infrastructure, and it needs no tuning as businesses grow. Budgets compare 1 vs 26 rows built from the schema seeder (18.2a), so they stay valid as screens change. |
| 18.3b Load testing | #187 | k6 rather than Locust/JMeter: scripts in JavaScript next to the app, one binary, thresholds that fail the run. The data generator writes rows directly (fast enough for 10,000 businesses) and makes owners through the same command as everyone else. Staging needs many visitors from few machines: a secret header (refused in production) sets the visitor IP rather than raising rate limits. Smoke run locally on `artisan serve`: every journey passed, p95 under 150 ms. The full-scale run is Sprint 17's. |
| 18.4a Move, restore, remove a business | #188 | 18.4 split: 18.4a = one business's data (move between shards, restore from a backup, permanent removal, demo purge); 18.4b = canary shard migrations, the 2038 date fix, Admin → System, whole-shard restore runbook and timed drill. Row-level copy, chosen over database-native tools (mysqldump with WHERE, replication filters): shards hold many businesses in shared tables, so only WURK knows which rows are whose. It also works the same on SQLite and MySQL, and in tests. Verified by per-table SHA-256 of every row, not just counts. While it copies, the business's pages are paused (503 with a plain message). Background jobs aren't paused: a job that writes during the copy makes the comparison fail, and the move stops safely and can be run again. A `--replace` restore saves the current rows to a file first. A third shard database was added in CI for restore tests. |
| 18.4b Canary rollouts, 2038 | #189 | 18.4 split again: 18.4c = Admin → System, backup records, whole-shard restore runbook and the timed drill. Found: the production `migrate` step never migrated the shards. The canary is the first shard by name unless one is chosen; `--canary` lets someone watch it before the rest. Rollback only undoes batches this run created, so it never touches earlier releases' migrations. A migration that fails half-way through a MySQL table change can't be undone (MySQL doesn't roll back DDL) — the rollout stops at that shard, and the runbook covers it. 2038: one converter reading `information_schema`, run by one central and one shard migration; one-way on purpose (dates past 2038 can't go back to TIMESTAMP). |
| 18.4c Admin → System, backups, drill | #190 | Backups are taken by the platform (managed database snapshots and PITR, object-storage versioning — Sprint 17), not by WURK. So WURK *records* them (`system:backup-taken`) and watches for gaps, rather than running its own dumps: dumps would duplicate the managed backups and couldn't meet the 15-minute RPO. Drills are recorded with their timings and judged against RPO/RTO. The page lives in Watchtower (platform operations) and reads shard state through a new Tenancy contract. `shards:repoint` health-checks the restored copy before switching. Found while testing: moves didn't update shard business counts (placement relies on them). Local drill (`scripts/dr-drill`, 300 businesses): shard back in 5 s, one business in 4 s. The drill that counts is Sprint 17's, on staging. |
| 18.5a Every screen, WCAG 2.2 AA | #191 | 18.5 split: 18.5a = the crawl and the WCAG 2.2 "focus not obscured" check, with fixes; 18.5b = keyboard journeys, accessible sign-in (pasting codes), dragging alternatives, template contrast with every colour preset, the accessibility statement. The crawl lists routes from the router rather than following links, so a screen without a link to it is still checked, and a new route that can't be resolved fails it. A route is reviewed as "not a page" only with a reason. Its data is a dedicated business with one realistic record behind each detail screen, not the schema-driven rows of 18.2a, whose made-up values aren't what owners see. Focus visibility is checked by tabbing through each screen and testing that each focused control can be hit, which catches sticky bars that axe can't. The editor's canvas (Puck's drag handles) is left out of axe; its keyboard use is an 18.5b journey. The crawl runs as three extra CI parts (about 5 minutes each). |

## Verification (before closing)

Every spec item and agreed addition, against the code and its tests.
