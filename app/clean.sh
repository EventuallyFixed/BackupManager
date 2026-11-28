#!/bin/sh
. "$1/helpers.sh" "$0" "$1";
log "removing web path: ${APP_WEB_PATH}"
rm -rf "${APP_WEB_PATH}"
