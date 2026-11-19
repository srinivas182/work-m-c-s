# WURK's cloud, as code (Sprint 17)

WURK runs on **AWS Africa (Cape Town, `af-south-1`)**: ECS Fargate, RDS for MySQL, ElastiCache (Valkey), S3 and CloudFront.
Cloudflare sits in front of everything. The whole environment is described here in **OpenTofu**, so staging and
production are built the same way and every change is reviewed in a pull request. Nothing is set up by hand.

| Folder | What it is |
|---|---|
| `bootstrap/` | Run once per AWS account. Creates the encrypted bucket for OpenTofu's state and the lock table. |
| `modules/network` | The private network across three availability zones: public, app and data subnets, NAT, flow logs. |
| `modules/database` | MySQL 8: the central database plus one instance per shard. Encrypted, TLS only, point-in-time restore, credentials in Secrets Manager. |
| `modules/cache` | Valkey for cache, sessions and queues. Encrypted, with an auth token and failover in production. |
| `modules/files` | The private, versioned files bucket (with a `tenants/<id>/` prefix per business), the logs bucket, and CloudFront for public website files. |
| `modules/app` | The image registry and the ECS cluster with `serve`, `worker`, `scheduler`, `ssr` and `clamav`. Also the load balancer (Cloudflare only), autoscaling, roles and logs. |
| `modules/edge` | Cloudflare (17.3): DNS for WURK's addresses, the ACM certificates (validated through Cloudflare), and in production the zone's TLS settings and the fallback origin for owners' own domains. |
| `modules/backup` | Daily AWS Backup of every database and the files bucket, copied to the backups account's locked vault, with each finished backup reported to WURK (17.4). |
| `modules/monitoring` | CloudWatch alarms into one SNS topic, delivered to WURK's Watchtower (signature-checked) and optionally by email (17.5). |
| `envs/uptime` | Better Stack checking WURK from outside, and the optional status page (17.5). |
| `envs/backups` | WURK's backups account: the locked off-site vault (17.4). |
| `modules/deploy` | The release pipeline's role, for GitHub OIDC (17.2). |
| `modules/stack` | One environment: everything above, with its encryption key. |
| `envs/staging`, `envs/production` | The two environments and their sizes. |

## Sizes at launch (decision F)

| | Staging | Production |
|---|---|---|
| Databases | central + 2 shards, `db.t4g.small`, single-AZ, 7 days' PITR | central + 2 shards, `db.m6g.large`, **Multi-AZ**, **35 days' PITR** |
| Cache | `cache.t4g.small`, no replica | `cache.m6g.large` + 1 replica, automatic failover |
| Web (`serve`) | 1–4 × 1 vCPU / 2 GB | 3–20 × 2 vCPU / 4 GB |
| Queue workers | 1–3 × 0.5 vCPU / 1 GB | 2–10 × 1 vCPU / 2 GB |
| NAT gateways | 1 | 3 (one per zone) |
| Deletion protection | off | on |

Before the first apply, these sizes are priced in the AWS Pricing Calculator and the estimate is shared with WURK. **Nothing is applied without WURK's go-ahead and budget.**

## First time, per account

You need:
- AWS accounts for production, staging and backups, in one AWS Organization, with `af-south-1` enabled;
- an IAM Identity Center role for the engineers (never root keys);
- `wurk.co.za` on Cloudflare, with Cloudflare for SaaS enabled on the zone. Note the zone ID.
- A Cloudflare API token **for OpenTofu**, limited to the `wurk.co.za` zone, with these permissions: Zone Settings Edit, DNS Edit, SSL and Certificates Edit, Custom Hostnames Edit. Export it as `CLOUDFLARE_API_TOKEN` when you run OpenTofu, and never write it in a file.

```bash
cd infra/bootstrap
tofu init && tofu apply -var account_id=<account id>          # state bucket + lock table (local state, keep it safe)

cd ../envs/staging
tofu init -backend-config=bucket=wurk-tofu-state-<account id>
tofu plan -var account_id=<id> -var image_tag=<version> -var cloudflare_zone_id=<zone> -out staging.tfplan
tofu apply staging.tfplan
```

Then, once:

1. **Set `APP_KEY`** in the secret `wurk/<env>/app`. Copy it from the current environment if it has one, because the key decrypts stored data; only a brand-new environment gets a new key. Never change it afterwards. The `REDIS_PASSWORD` in the same secret is written by OpenTofu.
   ```bash
   aws secretsmanager get-secret-value --secret-id wurk/staging/app --query SecretString --output text \
     | jq --arg k "$APP_KEY" '.APP_KEY=$k' \
     | aws secretsmanager put-secret-value --secret-id wurk/staging/app --secret-string file:///dev/stdin
   ```
2. **Push the first image** to the registry in `repository_url` (17.2 automates this).
3. **Migrate the central database and register the shards.** The credentials are in `wurk/<env>/db`, and the release task does this in 17.2:
   `migrate`, then `php artisan shards:register shard-1 --host=<shard_1 endpoint> --database=wurk --username=wurk --password=… --if-missing`, the same for `shard-2`, then `shards:migrate`.
4. **Give WURK its own Cloudflare token for owners' domains.** It needs only the SSL and Certificates and Custom Hostnames permissions, on the `wurk.co.za` zone. Put it in the secret `wurk/<env>/app` as `CLOUDFLARE_API_TOKEN`, then redeploy. Until it's there, Website → Your domain says connecting domains isn't switched on yet.

The DNS records, the certificates and Cloudflare's settings are all in `modules/edge`, so nothing is clicked in Cloudflare.

**Staging's addresses** (`<business>.staging.wurk.co.za`) are two levels below the zone. Cloudflare's free edge certificate covers only one level. Staging therefore needs either Cloudflare's Advanced Certificate Manager for `*.staging.wurk.co.za`, or its own domain. The app, admin, origin and sites addresses work either way.

## Backups (17.4)

1. In WURK's backups account, enable cross-account backup in AWS Organizations (AWS Backup → Settings), then apply `envs/backups` with:
   - `-var account_id=…`;
   - `-var 'source_account_ids=["<production>","<staging>"]'`;
   - `-var organization_id=o-…`.

   This creates the locked vault. Its lock can be corrected for **3 days**; after that it is permanent.
2. Apply production and staging with `-var offsite_vault_arn=<the vault's ARN>`. Every daily backup is then copied there: production's kept 90 days, staging's 14.

How to restore and how to run the drill: `docs/runbooks/restore.md` → "In the cloud".

## Monitoring and alerts (17.5)

**Alarms in CloudWatch.** Every alarm goes to the SNS topic `wurk-<env>-alarms`.

| Alarm | When |
|---|---|
| `crit-5xx` | More than 2% of requests fail (5xx), in 3 of 5 minutes, when there's traffic |
| `crit-no-healthy-web` | No healthy web container behind the load balancer for 3 minutes |
| `crit-no-scheduler`, `crit-no-workers` | The scheduler, or every queue worker, has stopped for 5 minutes |
| `warn-slow-pages` | 95% of requests take more than 2 seconds, for most of 10 minutes |
| `warn-db-cpu-*`, `crit-db-storage-*`, `warn-db-memory-*` | A database's CPU above 80%, or less than 5 GB of storage or 256 MB of memory free |
| `warn-cache-memory` | The cache more than 80% full |

**Who is told.**
- **WURK receives the topic** at `/_wurk/alarms/<token>`. Each alarm becomes a Watchtower problem, emailed to the people in **Admin → Watchtower → settings**. An alarm going back to OK resolves it. WURK checks SNS's signature and the topic on every message.
- **When WURK itself is down,** give `-var 'alert_emails=["…"]'` (or `TF_VAR_alert_emails`) when applying, so the topic emails them too. Each address must confirm the subscription from AWS's email.
- **Better Stack** (`envs/uptime`, token in `BETTERUPTIME_API_TOKEN`) checks these from outside every 1–5 minutes:
  - `/health/ready`;
  - the scheduler's heartbeat;
  - the website;
  - certificates near expiry.

  In Better Stack, add a webhook integration to `https://app.wurk.co.za/_wurk/uptime/<token>` (the token is in Admin → Watchtower), so outages are recorded in Watchtower. The status page `status.wurk.co.za` is optional (`-var status_page=true`, a paid Better Stack feature).

**What Watchtower watches itself** (Sprint 18): a full queue, late backups, failed scheduled tasks, slow pages and server errors.

**Error tracking:** set the Sentry DSN in **Admin → Providers → Error tracking** (decision G). Server errors then go to Sentry as well, already masked.

**Logs:** every container logs JSON lines to CloudWatch (`/wurk/<env>/<role>`), kept 30 days, with personal data masked as everywhere in WURK.

## Owners' own domains (17.3)

1. The owner types `www.theirbusiness.co.za` in **Website → Your domain**. Their package must include a custom domain.
2. WURK adds it to Cloudflare for SaaS as a custom hostname. The hostname's origin is `origin.<root>`, so Cloudflare connects with that name and the load balancer's certificate is valid under Full (strict). The Host header stays the owner's domain, which is how WURK knows the business.
3. The owner adds one CNAME record, pointing at `sites.<root>`, where their domain is managed.
4. Cloudflare issues the domain's certificate (HTTP validation) and renews it from then on.
5. `domains:check` runs every 5 minutes and marks the domain working; the owner gets a push notification. The owner can then make it the **main address**, and page visits to `<handle>.<root>` get a 301 to it. Payment notices, signed links and anything that isn't a GET stay on the WURK address.
6. Once a day, `domains:check --all` finds domains that stopped pointing at WURK. The site goes back to its WURK address and the owner is told.

A domain that never points at WURK is let go after 7 days. Each custom hostname costs $0.10 a month after the first 100. **Admin → Domains** shows every business's domains and their state.

## Rules

- **Secrets never go in this folder, and never in tfvars that are committed.**
  - Infrastructure secrets (database and Redis passwords, `APP_KEY`) live in Secrets Manager.
  - Provider keys (payments, WhatsApp, AI, SMS) are set in WURK's **Admin → Providers**.
- **The state holds generated passwords.** It is encrypted with KMS in a private, versioned bucket. Only the deploy role and the engineers' admin role can read it.
- **Shard credentials** are stored in the central database by `shards:register`. To change a shard's password:
  1. Set the new password on RDS.
  2. Run `shards:repoint <shard> --password=…`.
  3. Update `wurk/<env>/db`.

  This is a planned step, so there is no automatic rotation.
- **Cloudflare's IP ranges** (`cloudflare_ipv4`) are the only addresses the load balancer accepts. Check them against https://www.cloudflare.com/ips-v4 before each apply. 17.3 reads them from Cloudflare's API.
- **CI checks every change to `infra/`:**
  - `tofu fmt`;
  - `tofu validate` for each environment;
  - TFLint with AWS's rules;
  - Checkov, with the skipped rules and their reasons in `.checkov.yaml`.

## Releases (17.2)

**A version tag deploys.** Push `v1.2.3` (matching `VERSION`) and `.github/workflows/release.yml` does the rest.

1. **Build the image once.** WURK's commit is baked into the image. The image is pushed to staging's registry, scanned, and stopped if any critical finding turns up.
2. **Deploy to staging** with `scripts/deploy staging v1.2.3`, then check it from outside with `scripts/smoke-remote`.
3. **Production waits for approval** (required reviewers on the GitHub environment). It then copies the **same image** (same digest) and runs `scripts/deploy production v1.2.3`.

**What `scripts/deploy` does**, in order:

1. Snapshots every database. The snapshots are kept until someone deletes them.
2. Runs one release task. It records the snapshots (`system:backup-taken`), migrates the central database and every shard (canary first), then runs `release:tasks`: each version's one-off steps once, plus `permissions:sync` on every deploy (`config/release.php`).
3. Rolls out `serve`, `worker`, `ssr` and `scheduler`. The scheduler is replaced, never doubled.
4. Watches for 10 minutes: the 5xx rate stays at 2% or below, and `/health/ready` answers from outside.
5. Records the outcome with `release:finished`. It shows on **Admin → System → Releases**, and HQ is emailed.

**When something fails:**

| What fails | What happens | Data |
|---|---|---|
| Migrations or release steps | The deploy stops, and the running version is untouched | The snapshots are there if a migration did damage |
| New containers don't become healthy | ECS's circuit breaker puts the previous version back | Unchanged |
| 5xx or health checks during the watch | `scripts/deploy` puts the previous task definitions back | Migrations only ever add (expand, then contract in a later release), so the previous version runs on the new schema |

**To roll back on purpose,** run the Release workflow by hand (*Run workflow*) with the older tag and the environment.

**Set up once in GitHub** (Settings → Environments):
- `staging`: variable `AWS_DEPLOY_ROLE_ARN`, from staging's `deploy_role_arn`.
- `production`: required reviewers, plus these variables:
  - `AWS_DEPLOY_ROLE_ARN`, from production's `deploy_role_arn`;
  - `STAGING_REPOSITORY_URI`, from staging's `repository_url`.

Then apply staging with `-var production_deploy_role_arn=…`, and production with `-var staging_repository_arn=…`. Production can then copy the tested image.
