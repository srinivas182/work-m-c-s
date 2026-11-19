@if ($status === 'live')
WURK {{ $version }} is now running on {{ $environment }}. Migrations and release steps finished, and every service passed its health checks.
@elseif ($status === 'rolled_back')
WURK {{ $version }} didn't pass its health checks on {{ $environment }}, so the previous version was put back automatically. No data was lost: migrations only ever add to the database.
@else
WURK {{ $version }} could not be deployed to {{ $environment }}. The running version wasn't changed.
@endif
@if ($note)

What happened: {{ $note }}
@endif

Watchtower: https://{{ config('wurk.domains.admin') }}/watchtower
