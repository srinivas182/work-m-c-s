# Going live (Sprint 17.6)

This is the checklist that takes WURK from code to its first paying business. Steps 1–5 build staging and prove it; step 6 is production. Each line says who does it. **WURK** means WURK's people, and **eng** means the engineers.

## 1. Accounts and access (WURK)

- [ ] **AWS Organization** with three accounts: production, staging and backups.
  - `af-south-1` (Cape Town) is enabled in each.
  - Billing is in WURK's name, and cost alerts are set (17.1).
  - Every root user has MFA, and its keys are put away.
- [ ] **IAM Identity Center** roles for the engineers. Nobody uses long-lived keys.
- [ ] The **monthly budget is approved.**
  - Eng prices `infra/README.md` → "Sizes at launch" in the AWS Pricing Calculator first.
- [ ] `wurk.co.za` is on **Cloudflare**, with Cloudflare for SaaS enabled. Two tokens are made (`infra/README.md`):
  - one for OpenTofu;
  - one for WURK's own domain service.
- [ ] **Better Stack** account, and the API token for `envs/uptime`.
- [ ] **Sentry** project (decision G), with its DSN ready for Admin → Providers.
- [ ] **GitHub environments** `staging` and `production`. Production has required reviewers (`infra/README.md` → Releases).
- [ ] **Pen test firm** engaged (CREST-accredited, grey-box) and dates booked. Lead time is about 2–3 weeks.

## 2. Build staging (eng)

- [ ] `infra/bootstrap` in each account.
- [ ] `infra/envs/backups` (the locked vault).
- [ ] `infra/envs/staging`, with `offsite_vault_arn`, `cloudflare_zone_id` and `production_deploy_role_arn` once production's role exists.
- [ ] Set **`APP_KEY`** in `wurk/staging/app` (new for staging), plus **`CLOUDFLARE_API_TOKEN`** (WURK's domain token).
- [ ] Staging's edge certificate: Cloudflare Advanced Certificate Manager for `*.staging.wurk.co.za`, or a staging domain of its own (`infra/README.md`).
- [ ] **First release:** tag `v1.0.0-rc.1`. The Release workflow builds, deploys to staging and runs the smoke checks.
- [ ] **Register the shards** once: `scripts/ecs-task staging 'php artisan shards:register shard-1 --host=… --database=wurk --username=wurk --password=… --if-missing'`, the same for `shard-2`, then `shards:migrate`.
- [ ] `infra/envs/uptime`, then add the Better Stack webhook to `/_wurk/uptime/<token>`.
- [ ] In **Admin** (staging):
  - the first super admin, with MFA;
  - Watchtower recipients (the on-call people);
  - provider keys in **test mode**;
  - Sentry.

## 3. Prove staging (eng with WURK)

| Check | How | Pass |
|---|---|---|
| Load at target scale | Run `scripts/ecs-task staging 'php artisan wurk:seed-load --businesses=10000 …'` first, then the k6 journeys at the full profile (`docs/performance/load-testing.md`) | p95 ≤ 300 ms (sign-in 600 ms), errors < 0.1%, for 15 min at 1,000 req/s |
| Restore drill | `scripts/drill-restore staging shard-1 10`, then `system:drill-recorded` | Data lost ≤ 15 min, back within 4 h; shown on Admin → System |
| Release and rollback | Tag a release that fails its health check on purpose (e.g. `serve` exits on start). Then tag a good one | Rolled back automatically; **Admin → System → Releases** shows "Rolled back", and HQ got the email |
| Alarms reach on-call | In CloudWatch, set `wurk-staging-crit-no-scheduler` to ALARM (`aws cloudwatch set-alarm-state`). Stop the scheduler. Fill a queue. Skip a backup | Each one arrives as a Watchtower email, and a Better Stack alert when the app is unreachable |
| Owner's own domain | A test domain → Website → Your domain → CNAME → "Working" → main address | Its site loads over HTTPS, and `<handle>.staging.wurk.co.za` redirects to it |
| Pen test | The firm tests staging; eng fixes; the firm retests | No open critical or high findings (ASVS checklist updated) |
| UAT | `docs/uat/acceptance-script.md` with WURK, including criteria 2 and 12 | Signed off |

## 4. Build production (eng)

- [ ] `infra/envs/production`, with `offsite_vault_arn`, `cloudflare_zone_id` and `staging_repository_arn`. Then re-apply staging with `production_deploy_role_arn`.
- [ ] `wurk/production/app`: a **new `APP_KEY`**, kept safe. It's never changed: encrypted data and signed links depend on it. Also `CLOUDFLARE_API_TOKEN`.
- [ ] Optional: `TF_VAR_alert_emails` for alarms when WURK itself is down.

## 5. Cut over (eng, at a quiet hour)

- [ ] **Release:** tag `v1.0.0`. Staging deploys and gets the smoke checks; WURK **approves** production; production deploys.
- [ ] Register the production shards (as in step 2) and run `shards:migrate`.
- [ ] **DNS is already in `modules/edge`.** Check `https://wurk.co.za`, `app.`, `admin.`, `my.`, `help.` and a business site with `scripts/smoke-remote wurk.co.za v1.0.0`.
- [ ] **TLS and HSTS on the real load balancer** (ASVS 9.1):
  - `curl -sI https://app.wurk.co.za` shows `strict-transport-security`.
  - SSL Labs gives A or better.
  - `TRUSTED_PROXIES` is the VPC (set by `infra/`), so WURK sees HTTPS.
- [ ] **First super admin** with MFA. Add the staff roles and the Watchtower recipients.
- [ ] **Provider keys, live,** in Admin → Providers, each with "Test connection":
  - WhatsApp (and the templates submitted);
  - SMS;
  - email;
  - Paystack;
  - the AI providers;
  - Africa's Talking (USSD);
  - Sentry.
- [ ] **Live tests**, each done once with a real phone and card:
  - a WhatsApp sign-in code;
  - a booking with a deposit paid by card, then refunded;
  - an SMS;
  - a USSD booking;
  - a shop order paid by EFT.
- [ ] **Better Stack** points at production, and the status page is on, if chosen.

## 6. After going live

- [ ] Watch Admin → Watchtower and the alarms for the first 48 hours.
- [ ] **The first backups:**
  - [ ] each database and the files bucket shows on Admin → System the next morning;
  - [ ] the copies are in the backups account's vault.
- [ ] Book the quarterly restore drill (`docs/runbooks/restore.md` → Drill).
- [ ] From now on, releases follow SemVer. A tag deploys, and production waits for approval.
