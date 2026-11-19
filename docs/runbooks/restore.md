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
   - In the cloud, use point-in-time restore to a **new** instance (see "In the cloud" below), or `scripts/drill-restore`, which does it for you.
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

## In the cloud (Sprint 17.4)

WURK's backups are kept in four places:

| Kept where | How far back | Restores |
|---|---|---|
| Each database's point-in-time log (RDS) | 35 days in production, 7 in staging, to the second | A database at any moment, as a new instance |
| Daily snapshots (AWS Backup, 01:00 SAST) | 35 days | A database or the files bucket on that day |
| Copies in WURK's **backups account** (locked vault, same region) | 90 days | When the production account itself is lost or compromised |
| Snapshots taken before every release (`scripts/deploy`) | Until deleted | The databases just before a release |

Files (photos, documents, exports) are also **versioned**: an overwritten or deleted file can be brought back for 90 days.

Every finished backup reports to WURK (`system:backup-taken`, run by EventBridge). If one is late, Admin → System shows it and Watchtower raises a problem.

### Restore a database to a moment

```bash
aws rds restore-db-instance-to-point-in-time \
  --source-db-instance-identifier wurk-production-shard-1 \
  --target-db-instance-identifier wurk-production-shard-1-restored \
  --restore-time 2027-03-01T09:55:00Z \
  --db-subnet-group-name wurk-production --vpc-security-group-ids <the mysql security group> --no-publicly-accessible
aws rds wait db-instance-available --db-instance-identifier wurk-production-shard-1-restored
```

Then do one of two things:
- **Bring back one business:** point the `restore` connection at the new instance (`RESTORE_DB_*`) and run `tenants:restore <business> --replace`, as described above.
- **Replace the whole shard** (it's lost or damaged): `php artisan shards:repoint shard-1 --host=<new endpoint> --database=wurk --username=wurk --password=…`. Keep the old instance until you're sure.

**The central database** works the same way, but keep its address unchanged. An RDS address comes from the instance's name, so:
1. Rename the damaged instance (`aws rds modify-db-instance --db-instance-identifier wurk-production-central --new-db-instance-identifier wurk-production-central-damaged --apply-immediately`).
2. Rename the restored one to `wurk-production-central`.

The app reconnects on its own and nothing in `infra/` changes. Run `tofu plan` afterwards to confirm that.

### Restore files

Restore earlier versions of objects under `tenants/<business id>/` in the files bucket. Use `aws s3api list-object-versions` and then `copy-object` with `--copy-source <bucket>/<key>?versionId=<id>`. A whole bucket on a day can come from its AWS Backup recovery point, restored to a new bucket.

### When the production account is lost

The copies in the backups account's vault can't be deleted early by anyone, including an administrator of that account. To rebuild:
1. Create the new account and apply `infra/envs/production` there.
2. Copy the recovery points back from the backups account's vault.
3. Restore each one.

## Drill

Do this once a quarter on staging, and before going live (acceptance 4):

```bash
scripts/drill-restore staging shard-1 10   # restores shard-1 to 10 minutes ago as a new instance, timed
```

The script prints three things:
- the new instance's endpoint;
- how to check it;
- the `system:drill-recorded` command with the timings.

The drill counts when **no more than 15 minutes of data are lost** and the database is **working again within 4 hours**. Delete the drill instance afterwards; the script prints that command too.
