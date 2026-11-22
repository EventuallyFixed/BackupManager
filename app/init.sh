#!/bin/sh
. "$1/helpers.sh" "$0" "$1";
set -eu
log "creating web path: ${APP_WEB_PATH}"
mkdir -p "${APP_WEB_PATH}"
ln -sf "${APP_PATH}"/web/* "${APP_WEB_PATH}"
chown -R root:root "${APP_PATH}"
find "${APP_PATH}" -type d -exec chmod 755 {} \;
find "${APP_PATH}" -type f -exec chmod 644 {} \;
for script in install.sh init.sh start.sh stop.sh clean.sh preinst.sh remove.sh apkg_helpers.sh helpers.sh; do
    [ ! -f "${APP_PATH}/${script}" ] || chmod 755 "${APP_PATH}/${script}"
done
log "BackupManager web path initialized"
