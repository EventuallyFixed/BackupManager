# BackupManager — Stage 1 prototype

For WD My Cloud OS5, initially targeting WDMyCloudEX4100 (armhf).

## Implemented
- Read mounted ext4 destination filesystems from `/proc/mounts` (excluding source volume and descendants).
- Select a destination disk.
- Discover direct child directories of `/mnt/HD/HD_a2` via symbolic links in `/shares` (excludes `Volume_1`).
- Save per-destination source selections to `<mount>/.backupmanager/config.json` using the filesystem UUID.
- List existing `backup_YYYYMMDD_HHMMSS` snapshots, newest first.
- Show timestamps as local NAS time without UTC conversion.
- Keep Start Backup disabled until the backup engine is implemented; show invalid/missing configuration status.

## Not yet implemented
Backup execution, concurrency lock and disk-switch lock, retention, deletion, progress, logs, WD APKG packaging, authentication/privilege separation.

## Important deployment note
This is **not yet a production-ready NAS application**. The PHP file is a prototype for review and testing in a trusted environment. It writes per-disk configuration and must **not** be exposed on an unauthenticated web endpoint. In production, privileged configuration writes and backup operations should be handled by a restricted backend helper with authentication and authorization checks.

The config directory is created only when saving a configuration. Backup and log directories are not created during discovery.

## Files
- `web/index.php` — stage 1 prototype UI and discovery logic.
