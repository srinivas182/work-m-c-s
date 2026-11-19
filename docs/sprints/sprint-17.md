# Sprint 17 — Cloud & going live

| | |
|---|---|
| **Status** | Approved by WURK (19 Nov 2026: "Approve all"), decisions A–H as recommended |
| **Release** | v1.0.0 (first production release) |
| **Depends on** | v0.27.0 (Sprint 26). Builds on: the cloud-agnostic production image (FrankenPHP + Octane; `serve`, `worker`, `scheduler`, `migrate` roles), shards and rollouts (Sprints 1 and 18), Watchtower, `/health/*`, backup records (`system:backup-taken`), k6 load scripts, the restore runbook and the UAT script (Sprint 18) |
| **Next** | Live operation; feature work resumes from the backlog |

## Why

WURK is built and tested, but it runs only in CI and locally. Before the first paying business, it needs:

1. **A home in South Africa:** close to customers, with POPIA in mind. That means a managed database with point-in-time restore, Redis, file storage with versioning, and containers that scale.
2. **Repeatable environments:** staging that mirrors production, both described in code, so nothing is clicked together by hand and nothing differs between them.
3. **Safe releases:** an image built once, migrations run on the central database and every shard, rolled out without downtime, and rolled back automatically if the health checks fail.
4. **Owners' own domains** (`www.glowsalon.co.za`) with SSL that renews itself. This was moved here from Sprint 5.
5. **Proof at real scale:** the external pen test, the full-scale load test and the timed restore drill. Sprint 18 prepared them; they mean something only on real infrastructure.

## What the market does (Nov 2026)

| Option | Strengths | Gap for WURK |
|---|---|---|
| **AWS Africa (Cape Town), `af-south-1`** | Three availability zones. Managed MySQL (RDS / Aurora), ElastiCache (Redis/Valkey), S3, ECS Fargate, Secrets Manager, CloudWatch. The widest set of managed services in Africa | The only AWS region in Africa, so any second-region backup copy leaves South Africa. Prices are about 20% above the cheapest AWS region. The region must be enabled ("opt-in") on the account |
| **Azure South Africa North (Johannesburg)** + **South Africa West (Cape Town)** | Two regions in the country, so backups can be copied without leaving South Africa. Azure Database for MySQL Flexible Server, Container Apps, Azure Cache for Redis, Blob storage | Instance prices in South Africa North rank near the top of Azure's regions. South Africa West is a restricted, backup-paired region rather than a full one |
| **Google Cloud Johannesburg (`africa-south1`)** | Cloud SQL for MySQL, Cloud Run, Memorystore | One region in Africa. WURK has no GCP history, and the managed-service range is narrower |
| **Laravel Cloud / Forge / Vapor** | Made for Laravel: deploys, queues and scaling with little setup | **Laravel Cloud has no African region** (US, Canada, Europe, Middle East, Asia-Pacific only). Forge can provision AWS servers in Cape Town, but that gives servers to run, not managed containers |
| **Local hosts** (e.g. Teraco-based providers, Afrihost, Xneelo) | Rand pricing, local support | No managed multi-AZ MySQL with point-in-time restore, and no managed container platform at WURK's target scale |
| **Cloudflare for SaaS** (custom hostnames) | Owners point their domain at WURK. Cloudflare issues and renews SSL, and its WAF and CDN cover every site. 100 custom hostnames are included on every plan (Free included), then **$0.10 per hostname a month**, up to 50,000 | Needs `wurk.co.za` on Cloudflare (already a client dependency). It is the HTTP edge, not hosting |

**WURK's edge:** one image, the same in CI, staging and production. Infrastructure is described in code, releases are automatic and reversible, and every business is isolated by tenant and shard. This meets the platform rules: zero-downtime deploys, automatic rollback, and customer data never lost.

Sources:
- [AWS Africa (Cape Town) region](https://aws.amazon.com/blogs/aws/now-open-aws-africa-cape-town-region/) and [af-south-1 pricing and availability](https://cloudprice.net/aws/region/af-south-1)
- [Azure South Africa North pricing and availability](https://cloudprice.net/region/southafricanorth) and [Azure's South African datacenters](https://azure.microsoft.com/en-us/blog/microsoft-opens-first-datacenters-in-africa-with-general-availability-of-microsoft-azure/)
- [Google Cloud Johannesburg](https://cloudprice.net/gcp/regions/africa-south1)
- [Laravel Cloud regions (API)](https://laravel.com/cloud/docs/api/meta/list-regions.md)
- [Cloudflare for SaaS plans](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/)

## What's already in place (not repeated here)

- **The production image and its roles; ClamAV next to it:** docker.md.
- **Shards, moving businesses between shards, and staged rollouts:** Tenancy (Sprints 1 and 18).
- **Health checks** (`/health/live`, `/ready`, `/heartbeat`), Watchtower alerts, HSTS and security headers: Sprint 18.
- **Load testing:** `wurk:seed-load` and the k6 journeys.
- **Recovery and acceptance:** the restore runbook and the UAT script.
- **Domain mapping:** `tenant_domains` with verification, and the `custom_domain` entitlement per package.

## Scope

### 17.1 Infrastructure as code: staging and production
- **OpenTofu** (the open-source fork of Terraform) modules in `infra/`, one state per environment, kept in an encrypted, locked remote backend. Each environment gets:
  - a private network across three availability zones;
  - a load balancer that only takes traffic from Cloudflare;
  - managed MySQL 8: the central database plus N shard databases, Multi-AZ in production, single-AZ in staging;
  - Redis for cache, sessions and queues;
  - object storage with versioning, encryption and block-public-access;
  - a container registry;
  - container services for `serve` (autoscaled on CPU and requests), `worker` (autoscaled on queue depth), a single `scheduler`, and ClamAV;
  - a secrets store.
- **Secrets:**
  - Infrastructure secrets live only in the secrets store, never in the repo: `APP_KEY`, database and Redis passwords, the Cloudflare API token.
  - Provider keys stay in Admin → Providers, as the 18 Nov rule requires.
- **Files** move to object storage: `FILESYSTEM_DISK=s3`. Each business's files stay under `tenants/<id>/`, and `storage/app/trust` gets its own private bucket prefix.
- **Staging mirrors production**, on smaller sizes. A shard count of 2 in staging tests the per-shard paths.

### 17.2 Releases: build once, migrate, roll out, roll back
- **GitHub Actions on a tag `v*`:**
  1. Build and scan the image and push it to the registry, with its digest recorded.
  2. Deploy to staging and run the smoke and e2e checks against staging.
  3. Wait for a manual approval.
  4. Deploy to production.
- **Each deploy:**
  1. Record a fresh database snapshot (`system:backup-taken`).
  2. Run `migrate` as a one-off task on the central database, then on each shard in the rollout order (canary shard first, Sprint 18).
  3. Do a rolling update of `serve` and `worker`, with health checks on `/health/ready`.
  4. Restart the workers gracefully.
  5. Clear caches.
  6. Notify the WURK team.
- **Rollback:** if health checks or the error rate fail within 10 minutes, the previous image is restored automatically. Migrations are written to be backwards compatible (expand, then contract), so the old image runs on the new schema and no rollback ever drops data.
- **The version-specific steps in docker.md** become scripted release tasks wherever they're commands.

### 17.3 Owners' own domains, with SSL
- **`wurk.co.za` on Cloudflare:** DNS for the apex, `app.`, `admin.`, `my.` and `help.`, plus a wildcard for `<handle>.wurk.co.za`. WAF and rate rules at the edge. The origin accepts only Cloudflare.
- **Settings → Your domain** (for packages with `custom_domain`):
  - The owner types `www.glowsalon.co.za`.
  - WURK adds it as a Cloudflare for SaaS custom hostname and shows the one CNAME record to add, with step-by-step help for common South African registrars.
  - WURK checks it until it is active, then shows the SSL status.
  - The domain can be made the primary address, and the `wurk.co.za` address then redirects to it.
- **Apex domains** (`glowsalon.co.za` without `www`): the owner is guided to use `www` and redirect the apex, or to use their DNS host's CNAME flattening.
- **Admin → Domains:** every custom hostname with its status, errors and re-check.
- **When a domain lapses**, WURK's address keeps working and the owner is told.
- **The Cloudflare API token** is an infrastructure secret. The custom hostname count is shown in Admin → Metrics, since it's billed per hostname.

### 17.4 Backups and disaster recovery
- **Databases:** automated snapshots and point-in-time restore on every database. 35 days in production and 7 in staging.
- **Files:** object storage versioning with a 90-day noncurrent-version lifecycle.
- **Off-site copy of the backups** (decision C).
- **Backup checks:** each backup job reports to `system:backup-taken`, and Watchtower raises an alert when one is late (Sprint 18).
- **The restore runbook gains the cloud steps:** point-in-time restore to a new instance, swap the shard connection, and restore earlier object versions under `tenants/<id>/`.
- **Timed DR drill on staging:** restore a shard to 10 minutes before an injected fault. Target: RPO ≤ 15 min, RTO ≤ 4 h, as set in Sprint 18.

### 17.5 Monitoring and alerts
- **Logs:** structured JSON, gathered centrally with 30 days' retention. Personal data is redacted as today.
- **Metrics and alarms:**
  - load balancer 5xx and latency (p95);
  - container CPU and memory;
  - queue depth and oldest job;
  - database CPU, connections, free storage and replica lag;
  - Redis memory;
  - certificate and custom-hostname errors.
- **External uptime** from **Better Stack** (Sprint 18, decision G):
  - `/health/ready`, a public site and `/health/heartbeat`;
  - a public status page at `status.wurk.co.za`.
- **Alerts go to the on-call contact set in settings**, never written into the code. Both Watchtower and the cloud alarms raise them.
- **Error tracking:** Sentry, with personal data scrubbing, or the cloud's equivalent. The DSN is set in Admin → Providers.

### 17.6 Going live
On staging, with production-like data from `wurk:seed-load`:
- **The full-scale k6 load test:** the Sprint 18 journeys at the target scale.
- **The external grey-box penetration test** by a CREST-accredited firm, which WURK engages. Then fixes and a retest, until no critical or high findings are open.
- **The timed DR drill.**
- **The UAT script** with WURK (criteria 2 and 12).

On production:
- **Go-live checklist:**
  - DNS cut-over, `TRUSTED_PROXIES`, and HSTS checked on the real load balancer (ASVS 9.1);
  - the first super admin with MFA;
  - provider keys entered in Admin;
  - WhatsApp, payments and SMS tested live.
- **Release v1.0.0**, with the full upgrade guide for the infrastructure.

Also: rebalance the MySQL CI parts again (the slowest took 11.5 of 12 minutes on the v0.27.0 release).

## Decisions

| # | Decision | Recommendation |
|---|---|---|
| A | Cloud | **AWS Africa (Cape Town).** Three availability zones; RDS for MySQL Multi-AZ with point-in-time restore; ElastiCache; S3; ECS Fargate; the broadest managed range in Africa; and the most hiring and support depth in South Africa. **Azure is the alternative** if keeping backup copies inside South Africa is a must (decision C), since it has two South African regions |
| B | Container platform | **ECS on Fargate.** It runs the existing image as-is, with no servers or Kubernetes to run. EKS (Kubernetes) adds operating cost that WURK doesn't need at this scale; plain EC2 servers would mean patching them |
| C | Off-site backup copy | **Copy snapshots to a second AWS account in the same Cape Town region**, so a compromised production account can't delete them. Data stays in South Africa. **Optionally** add a copy to `eu-west-1` (Ireland): POPIA §72 allows it with adequate protection, which the EU has, and it guards against losing the whole region. Recommended later, not at launch |
| D | Infrastructure as code | **OpenTofu.** Terraform-compatible, open licence, and plain files reviewed in pull requests. AWS CDK or CloudFormation would tie WURK to AWS |
| E | Custom domains | **Cloudflare for SaaS.** 100 hostnames free, then $0.10 each a month. SSL is issued and renewed automatically, behind the same WAF. Package prices already decide who gets a custom domain (`custom_domain`) |
| F | Database layout | **One RDS instance per shard**, central separate. Start with **2 shards in production**, sized small, and add shards as businesses grow (the shard rollouts from Sprint 18). Aurora MySQL is an option once a shard needs it. It costs more at small sizes |
| G | Error tracking | **Sentry** (EU data region, personal data scrubbed), key in Admin → Providers. Alternatively CloudWatch only, which is cheaper and has fewer insights |
| H | Version number | **v1.0.0** for the first production release. The upgrade steps from then on follow SemVer strictly |

## What WURK needs to provide

| Item | Needed by | Notes |
|---|---|---|
| AWS Organization with three accounts (production, staging, backups), `af-south-1` enabled, billing in WURK's name | 17.1 | Root user with MFA. WURK gives the engineers an IAM Identity Center role, never root keys |
| Monthly cloud budget and alert thresholds | 17.1 | The spec sets sizes from the AWS Pricing Calculator in 17.1, and the estimate is shared before anything is created |
| Cloudflare account; `wurk.co.za` moved to Cloudflare DNS | 17.3 | Already listed in the sprint plan. Cloudflare for SaaS is enabled on the zone |
| Better Stack account (status page optional, paid) | 17.5 | Decision G of Sprint 18 |
| Sentry organization (if decision G) | 17.5 | |
| Pen test firm engaged; dates booked | 17.6 | About 2–3 weeks' lead time (Sprint 18, decision A) |
| On-call contact (who gets alerts) | 17.5 | Set in settings, not in code |

## Acceptance

1. Staging and production are created from `infra/` alone. Running the plan again shows no changes, and nothing was clicked together by hand.
2. Tagging a version deploys it to staging, then, after approval, to production, without downtime. A version with a failing health check is rolled back automatically, with no data lost.
3. An owner adds `www.<their domain>` and follows the one CNAME instruction. Their site then loads there over HTTPS within the documented time, and the `wurk.co.za` address redirects to it.
4. A shard is restored to a point in time on staging within the RTO and RPO targets. The drill is recorded in the runbook.
5. The full-scale load test meets the Sprint 18 budgets on staging.
6. The external pen test has no open critical or high findings.
7. Alerts reach the on-call contact for a stopped scheduler, a failing health check, a full queue and a late backup.
8. v1.0.0 runs in production with live WhatsApp, payments and SMS, and the UAT script is passed with WURK.

## Agreed (19 Nov 2026)

- Decisions A–H as recommended: AWS Africa (Cape Town) on ECS Fargate, OpenTofu, Cloudflare for SaaS, backup copies in a separate AWS account in the same region, one RDS instance per shard (2 to start), Sentry, v1.0.0.
- Nothing is applied to AWS until WURK provides the accounts and approves the budget estimate.

## Parts

17.1 → 17.2 → 17.3 → 17.4 → 17.5 → 17.6, each with one CI run. Parts 17.1 and 17.2 need the AWS accounts. Until those exist, the code is written and validated in CI (`tofu validate`, `tflint`, `checkov`, and a plan against a mocked provider). Nothing is applied without WURK's go-ahead and budget. 17.3's WURK side (Settings → Your domain, Admin → Domains) can be built and tested against Cloudflare's API with a test zone.

## Progress

| Part | PR | Notes |
|---|---|---|
| 17.1 Infrastructure as code | #281 | OpenTofu in `infra/`: network, MySQL per shard, Valkey, files bucket + CloudFront, ECS Fargate roles and autoscaling. Disks switch to S3 prefixes with `FILES_DRIVER=s3`; RDS CA in the image; a CI job validates, lints (TFLint + AWS rules) and scans (Checkov). Not applied: waits for WURK's accounts and budget |
| 17.2 Releases | #282 | Tag → build once → staging → smoke → approval → the same image to production. `scripts/deploy` (snapshots, migrate central and shards, `release:tasks`, rolling update, 10-minute watch, automatic rollback), `release:finished` (Admin → System and HQ email), GitHub OIDC deploy roles (no AWS keys). Caches: each new container rebuilds its own; the shared Redis cache is kept, since it holds rate limits and pending actions |
| 17.3 Owners' own domains | #283 | Website → Your domain: one CNAME to `sites.<root>`, Cloudflare for SaaS issues the certificate (custom origin `origin.<root>`, Full (strict)), checks every 5 minutes, main-address 301 from `<handle>.<root>` (never POSTs, payment notices or signed links), lapse → back to the WURK address with a push; let go after 7 days unpointed. Admin → Domains. `infra/modules/edge`: DNS, ACM certificates validated through Cloudflare, zone TLS settings, fallback origin, Cloudflare's IP ranges from its API |
| 17.4 Backups and DR | #284 | AWS Backup daily for every database and the files bucket (35 days), copied to a locked vault in WURK's backups account in the same region (decision C); each finished backup recorded through EventBridge → `system:backup-taken`; `scripts/drill-restore` (timed point-in-time restore to a new instance, prints the checks and the drill record); restore runbook's cloud steps. The drill itself runs on staging (17.6) |
