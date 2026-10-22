#!/bin/sh
# WURK container entrypoint.
#   serve      (default) cache configuration, then run Octane on FrankenPHP
#   worker     run a queue worker
#   scheduler  run the task scheduler
#   migrate    run database migrations (use as a one-off release step)
#   ssr        server-side renderer for wurk.co.za (point web containers at it
#              with INERTIA_SSR_URL=http://<ssr-host>:13714)
#   <anything> run that command, e.g. `php artisan tinker`
set -eu

warm_caches() {
  # Configuration comes from runtime environment variables, so caches are built
  # at start-up rather than at image build time.
  php artisan optimize --no-interaction
}

case "${1:-serve}" in
  serve)
    warm_caches
    exec php artisan octane:frankenphp \
      --host=0.0.0.0 \
      --port="${PORT:-8000}" \
      --workers="${OCTANE_WORKERS:-auto}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}"
    ;;
  worker)
    warm_caches
    exec php artisan queue:work --sleep=1 --tries=3 --max-time=3600
    ;;
  scheduler)
    warm_caches
    exec php artisan schedule:work
    ;;
  ssr)
    SSR_HOST="${SSR_HOST:-0.0.0.0}" exec node bootstrap/ssr/ssr.js
    ;;
  migrate)
    exec php artisan migrate --force --no-interaction
    ;;
  *)
    exec "$@"
    ;;
esac
