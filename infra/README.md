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
- the ACM certificate for `wurk.co.za` and `*.wurk.co.za` (or the `staging.` ones), validated through Cloudflare DNS.

```bash
cd infra/bootstrap
tofu init && tofu apply -var account_id=<account id>          # state bucket + lock table (local state, keep it safe)

cd ../envs/staging
tofu init -backend-config=bucket=wurk-tofu-state-<account id>
tofu plan -var account_id=<id> -var image_tag=<version> -var certificate_arn=<arn> -out staging.tfplan
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
4. **Point Cloudflare at the load balancer** (`load_balancer_dns`), proxied, with SSL mode *Full (strict)*. Point `files.wurk.co.za` at `files_cloudfront_domain` with a DNS-only CNAME. See 17.3.

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
