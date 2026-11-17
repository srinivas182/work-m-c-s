# Load testing WURK

**Sprint 18.3b.** Acceptance criterion 12 asks for load tests that show WURK handles its target scale. This page covers the scripts in `load/k6`, how to run them, and what counts as a pass. The full run happens on **staging in Sprint 17**, on infrastructure the same shape as production. A local run proves only that the scripts work.

## Targets (PRD §15, decision 18-B)

| | Target |
|---|---|
| Businesses | 10,000 on staging (far above launch; the architecture is built for 500,000) |
| Traffic | 1,000 requests a second in total, sustained for 15 minutes after a 5-minute ramp |
| Speed | 95% of requests answered within **300 ms** (sign-in: 600 ms, because password hashing is deliberately slow) |
| Errors | Fewer than **0.1%** of requests failing; at least 99.9% of checks passing |
| Pages | Lighthouse mobile ≥ 90 on every template (already gated in CI) |

## The journeys (`load/k6/journeys.js`)

| Journey | Share at target | What it does |
|---|---|---|
| Public site | 600/s | A visitor on a business's website loads the booking widget's services |
| Booking | 200/s | A customer looks for times on a given day |
| Back office | 180/s | An owner, signed in once, opens the dashboard, today's bookings and customers |
| Sign-in | 20/s | Signing in with a password, as owners do each morning |

Published website pages are pre-rendered HTML served from cache, so their load is mostly on the CDN and web server. The Lighthouse gate covers their speed.

## Running it

1. **Data.** On staging, never production: `php artisan wurk:seed-load --businesses=10000 --customers=200 --bookings=60 --products=20`
   - This spreads businesses over the shards. Each gets an owner who can sign in, customers, a bookable service with hours, bookings over the next 14 days, and products.
   - It writes `storage/app/load-test.json`, which the scripts read.
   - It is safe to run again: existing businesses are left as they are.
   - The command refuses to run in production.
2. **Many visitors from a few machines.** Set `WURK_LOAD_TEST_TOKEN` on staging (also refused in production). Pass the same value as `LOAD_TOKEN`.
   - Each virtual user then appears as its own visitor (`X-Wurk-Load-Visitor`), so per-visitor rate limits behave as they would for real, separate people.
3. **Run from a machine near staging, in the same South African region:**

   ```
   k6 run -e PROFILE=target \
     -e TARGET=https://app.staging.wurk.co.za -e ROOT_DOMAIN=staging.wurk.co.za -e SITE_SCHEME=https \
     -e LOAD_TOKEN=… -e DATA=load-test.json \
     load/k6/journeys.js
   ```

   A single k6 machine comfortably sends 1,000 requests a second. Use two if its CPU passes 70%.
4. **Watch while it runs:** Watchtower (errors, slow pages, queue backlogs), the database and Redis dashboards, and the queue lengths.
5. **Record the result** in `docs/sprints/sprint-17.md`. Keep k6's summary, the p95 per journey, the error rate, and anything Watchtower raised.

**Smoke run on any machine.** This is the default profile: a few visitors for 30 seconds. It is judged only on errors and checks.

```
php artisan wurk:seed-load --businesses=5      # with APP_ENV=e2e locally
k6 run -e LOAD_TOKEN=<your local token> load/k6/journeys.js
```

CI runs the smoke profile on every change ready for review, in the performance-gate job, so the scripts can't quietly break.

## What else is already guarded in CI

| Guard | Where |
|---|---|
| No N+1 queries on the busiest screens (1 vs 26 rows) | `tests/Feature/Performance/QueryBudgetTest.php` |
| Fair queuing: a booking message goes before another business's 1,000-customer campaign | `modules/Campaigns/tests/Feature/FairQueuesTest.php` |
| Lighthouse mobile ≥ 90 and layout shift < 0.1 on all 24 templates | E2E · performance gate |
| Slow pages in production: more than 5% of requests over 300 ms in an hour | Watchtower (18.0b) |

## If a journey misses its target

1. **Look at Watchtower's slow pages for that hour.** They show the route, its share over 300 ms, and its slowest request.
2. **Check the database first:** missing indexes, N+1 queries (add the screen to `QueryBudgetTest`), and shard balance (`shards` capacity).
3. **Then check caching:** published pages, settings, entitlements.
4. **Then capacity:** web workers (`OCTANE_WORKERS`), queue workers, the database instance size.
5. **Re-run the same profile** and record before and after.
