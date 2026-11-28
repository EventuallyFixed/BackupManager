#!/bin/sh
. "$1/helpers.sh" "$0" "$1";
log "Removing BackupManager application files: ${APP_PATH}"
rm -rf "${APP_PATH}"
