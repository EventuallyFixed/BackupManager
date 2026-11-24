# Backup Manager
Incremental Backups on the WD Ex4100/OS5 NAS

Intended for WD My Cloud OS5, initially targeting the WD MyCloud EX4100 model (armhf - Marvell Armada).

## Implemented
- Read mounted ext4 destination filesystems from `/proc/mounts` (excluding source volume and descendants): See companion development - [EncDiskManager](https://github.com/EventuallyFixed/EncDiskManager)
- Select a destination disk.
- Discover direct child directories of `/mnt/HD/HD_a2` via symbolic links in `/shares` (excludes `Volume_1`).
- Save per-destination source selections to `<mount>/.backupmanager/config.json` using the filesystem UUID.
- List existing `backup_YYYYMMDD_HHMMSS` snapshots, newest first.
- Show timestamps as local NAS time without UTC conversion.
- Keep Start Backup disabled until the backup engine is implemented; show invalid/missing configuration status.

## Important deployment note
If you choose to install this it is _at your own risk_: **Be sure and have backups of your data before embarking**.

This is a simple PHP based application that manages backups using 'rsync' in a manner similar to Apple's Time Machine, where incremental backups happen after the first complete backup. It uses Hard Links so files are copied from backup to backup without additional disk space being consumed. While it writes per-disk configuration to the attached backup disk, the pruning function acts globally, when used. If there is not enough space on the disk the oldest backup is removed and it will try again.

The config directory on the attached disk is created only when saving a configuration, and only on the target disk. Backup and log directories are not created during discovery but on creation of the backup.

For an EX4100 with the standard application path, the setup code is stored at:

```sh
cat /mnt/HD/HD_a2/Nas_Prog/_BackupManager/setup-token
```

Run this in an appropriately privileged SSH session on your NAS. Keep the code private. It is removed after administrator setup.

# Main Features

## List of backups
Selecting a disk attached via USB changes the details beneath
<img width="963" height="679" alt="Backups" src="https://github.com/user-attachments/assets/bff0ed70-0558-44f2-a4db-478fe70bd989" />

## Per-disk Configuration
The application knows it 'owns' the disk by writing a configuration there.
<img width="967" height="707" alt="Configuration" src="https://github.com/user-attachments/assets/ae0e2db9-7a38-43e2-a04f-74765197c5cb" />

## Backup Pruning
A wide range of options to prune the backups on the selected disk
<img width="959" height="786" alt="Pruning" src="https://github.com/user-attachments/assets/a5b35241-90c2-4750-8e10-f56bcfac8e6c" />

