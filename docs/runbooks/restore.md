# Runbook: moving and restoring a business

**Sprints 18.4a–c.** Use this page when data has to come back from a backup: one business, a whole shard, or the central database. It also covers moving a business to another shard, migrations, and the restore drill.

**Targets** (PRD §15): no more than **15 minutes** of data lost (RPO), and working again within **4 hours** (RTO). Admin → System shows the latest backups, the migration state of each database, and how the last drills did.

Each business lives on one shard database. Its rows sit beside other businesses' rows in the same tables, so none of these commands ever touches another business. Each one copies **every table with a `tenant_id`**, parents before children.

## Move a business to another shard

Use this to rebalance shards, or to put a large business on a shard of its own.

1. **Make sure the destination shard is migrated** (`php artisan shards:migrate --shard=shard-2`; each shard's schema version is in the `shards` table). If it's behind, a missing table or column stops the move before anything changes.
2. Run the move:
   ```
   php artisan tenants:move glow-salon --to=shard-2
   ```
   - The business's pages answer **503 "being moved to a new server — back in a few minutes"** while it copies, so nothing changes mid-copy.
   - The move is all or nothing. Every row is copied, then the two copies are compared table by table (row counts and a SHA-256 of every row). Only when they match does the business switch to the new shard.
   - If anything fails, the partial copy is deleted, the business stays where it was, and its pages come back. The `tenant_moves` row says why.
   - Background jobs aren't paused. If one writes to the business during the copy, the comparison fails and the move stops safely. Run it again, ideally at a quiet hour (for example, not while one of its campaigns is sending).
3. The old copy stays on the old shard for **7 days**, in case it's needed. After that, `tenants:clear-moved` (scheduled daily at 04:13) deletes it.

A typical small business moves in seconds. The pause lasts at most 30 minutes, even if the process dies.

## Restore one business from a backup

Use this when a business has lost data, for example through a mistake, a bad import, or a support request that went wrong. Other businesses on the shard are not affected.

1. **Restore the shard's backup, to the point in time before the problem, as a separate database.** Never restore over the live shard.
   - Sprint 17 covers how to do this on the managed database (snapshot or point-in-time restore to a new instance).
   - Locally, load the dump into a new database.
2. **Point the `restore` connection at it** with `RESTORE_DB_HOST`, `RESTORE_DB_PORT`, `RESTORE_DB_DATABASE`, `RESTORE_DB_USERNAME` and `RESTORE_DB_PASSWORD`. Use a read-only user if you can.
3. **Choose one of two ways:**
   - **Replace what the business has now** on its own shard:
     ```
     php artisan tenants:restore glow-salon --replace
     ```
     - What it has now is first saved to `storage/app/private/restore-safety/<business>-<time>.jsonl.gz`.
     - Its rows are then replaced with the backup's.
     - If the restore fails, the saved rows are put back.
     - Keep the file until the owner confirms everything is right.
   - **Restore it onto another shard**:
     ```
     php artisan tenants:restore glow-salon --to=shard-3
     ```
     - The business switches to the restored copy.
     - What it had now stays on its old shard for 7 days. That makes it easy to look up anything added since the backup.
4. Both ways compare the restored rows with the backup before finishing. The command reports how many rows came back.
5. **Tell the owner what was restored, and from when.** Anything added after the backup's time is not in the restored copy. With `--to`, it can be found on the old shard for 7 days.
6. Drop the restored backup database when you're done.

**Files** such as photos and documents are stored separately, in object storage with versioning (Sprint 17). They aren't part of a database restore.

## Remove an archived business for good

`TenantProvisioner::purge()` deletes an **archived** business's rows on its shard and its files (`tenants/<id>/`), and records `purged_at`. It refuses an active business.

Agent demo sites use it: a demo archived for **7 days** is removed for good by the daily `agents:sweep-demos`.

## Records

Every move and restore is recorded in `tenant_moves`: what happened, from where, to where, rows per table, whether it worked, the error if it didn't, who started it, and when the old copy goes.

## Restore a whole shard

Use this when a shard database is lost or damaged. Every business on it is affected.

1. **Restore its backup as a new database**, never over the damaged one. Use the managed database's point-in-time restore (Sprint 17) to just before the problem. Locally, load the dump.
2. **Point the shard at it:**
   ```
   php artisan shards:repoint shard-1 --host=<new host> --database=<new database>
   ```
   - Options left out keep the current values.
   - The new database is **health-checked first**: every migration is there and every business table can be read. If it isn't healthy, the shard stays where it was.
   - Queue workers restart by themselves.
3. **Reload the web servers** with `php artisan octane:reload` on each, or redeploy, so they connect to the new database too.
4. **Run `php artisan shards:migrate --shard=shard-1`**. It applies anything the backup is missing and checks the shard again.
5. Watchtower's shard check turns green within a minute. **Tell the businesses on the shard** what period, if any, was lost.

## Restore the central database

The central database holds accounts, businesses, billing and settings. Without it nothing works, so the whole platform is down until it's back.

1. **Put WURK in maintenance mode** (`php artisan down`), or stop the web containers.
2. Restore its backup to just before the problem as a new database, and point `DB_HOST` / `DB_DATABASE` at it.
3. Deploy (or restart every container) with the new settings, and run `php artisan migrate`.
4. Bring WURK back up (`php artisan up`). Check `/health/ready` and Watchtower.

Shards are separate: they keep everything that happened to businesses' own data, so nothing there is lost.

## Files

Photos, documents and exports are kept in object storage with **versioning** (Sprint 17). They are restored by restoring earlier versions of objects under `tenants/<business id>/`, not from database backups.

## Migrations: canary first

`php artisan shards:migrate` is run by the release step (`migrate`, after the central database). It works shard by shard:

1. The **canary shard** goes first: `WURK_CANARY_SHARD`, otherwise the first by name.
2. Each shard is **health-checked** after its migrations: every migration is there and every business table can be read.
3. On a failure the rollout **stops**, and that shard's migrations from this run are **rolled back**. Every other shard stays on the previous version, and the previous release keeps working.

`shards:migrate --canary` migrates only the canary, so it can be watched for a while before the rest.

**A migration that fails part-way through a table change** can leave that one table half-changed: MySQL doesn't undo table changes. The rollout still stops at that shard. Fix the migration, or restore the shard as above if its data is affected. Then run `shards:migrate` again.

## Backups and Watchtower

Backups are taken outside WURK: the managed database's automatic snapshots and point-in-time log, and object-storage versioning (set up in Sprint 17).

**Each backup job reports in:**
```
php artisan system:backup-taken shard-1 --kind=snapshot --size=<bytes> --location=<where, no secrets>
```

Set `BACKUP_TARGETS` (for example `central,shard-1,shard-2,files`) to the parts that must have a recent backup. **Watchtower raises a problem** when one has none within `BACKUP_MAX_AGE_HOURS` (26), so a backup that quietly stops is noticed the next day.

## The restore drill

Run a drill **every quarter**, and after any change to how backups are taken. Record each one with `php artisan system:drill-recorded`. Admin → System shows whether it met the targets.

**Locally:** `MYSQL_PWD=<password> ./scripts/dr-drill [businesses]` builds its own databases and runs two timed drills:

1. **A lost shard.** The shard's database is dropped, restored from its backup as a new database, repointed and health-checked.
2. **One business losing its customers.** The business is restored alone with `tenants:restore --replace`.

Both drills are recorded.

**Staging (Sprint 17)** runs the same steps against the managed database's point-in-time restore. That is the drill that counts for the targets.

| Drill (local, 14 Nov 2026) | Data | Back in | Data lost | Result |
|---|---|---|---|---|
| Lost shard, 40 businesses over 2 shards | 4,000 customers, 2,400 bookings on the shard | 4 s | up to the backup's time | Passed |
| One business | 200 customers | 2 s | up to the backup's time | Passed |
| Lost shard, 300 businesses over 2 shards | 30,000 customers, 9,000 bookings, 3,000 products on the shard | 5 s | up to the backup's time | Passed |
| One business (same run) | 200 customers | 4 s | up to the backup's time | Passed |

Restoring from a logical dump grows with the data: about 6,000 customer rows a second here. A shard with 5,000 businesses would take minutes, well inside 4 hours. The managed database's snapshot restore (Sprint 17) doesn't replay rows, so it depends far less on size. **Data lost is set by the backup**, not by the restore: a nightly dump can lose up to a day. The 15-minute target needs the managed database's point-in-time recovery (continuous log backup), which Sprint 17 sets up and drills on staging.
