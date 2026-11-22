#!/bin/sh
. "$1/helpers.sh" "$0" "$1";
# Per-disk settings are stored on the backup disks and are not removed on upgrades.
log "BackupManager upgrade: preserving per-disk configuration"
