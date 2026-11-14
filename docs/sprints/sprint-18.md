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
| **18.2 Isolation sweep (criterion 7)** | An **automated isolation test over every tenant route**: two businesses on different shards, every route enumerated from the router, called as business A's owner with business B's IDs → must be 403/404, never B's data. Same for signed public links (bookings, records, payments, portal) with tampered or cross-business tokens, file downloads, exports and the Ask WURK tools. New routes are covered automatically. Plus: **mass-assignment check** (every model guarded or validated input only), **IDOR check on files** (every disk path goes through a tenant check). |
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

## Verification (before closing)

Every spec item and agreed addition, against the code and its tests.
