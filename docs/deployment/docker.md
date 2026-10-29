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

### Version-specific release steps

Run these once, after `migrate`, when deploying the version shown (any other command is passed straight to the container):

| From version | Step | Why |
|---|---|---|
| v0.14.0 | `docker run --rm --env-file production.env wurk:<version> php artisan crm:link-existing` | Makes contacts of customers from before contacts existed (bookings, class places, orders). Safe to run again. |

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
