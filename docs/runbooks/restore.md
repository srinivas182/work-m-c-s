# Runbook: moving and restoring a business

**Sprint 18.4a.** Use this page when a business's data has to come back from a backup, or a business has to move to another shard. The whole-shard restore, the canary migration rollout and the timed drill are added in 18.4b.

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
