#!/bin/sh
. "$1/helpers.sh" "$0" "$1";
set -eu
log "creating web path: ${APP_WEB_PATH}"
[ -d "${APP_PATH}/web" ] || { log "ERROR: web directory missing"; exit 1; }
[ -f "${APP_PATH}/web/index.php" ] || { log "ERROR: web/index.php missing"; exit 1; }
[ -f "${APP_PATH}/lib/auth.php" ] || { log "ERROR: lib/auth.php missing"; exit 1; }
[ ! -L "${APP_WEB_PATH}" ] || { log "ERROR: web path is a symlink"; exit 1; }
mkdir -p "${APP_WEB_PATH}"
ln -sf "${APP_PATH}"/web/* "${APP_WEB_PATH}"
chown -R root:root "${APP_PATH}"
find "${APP_PATH}" -type d -exec chmod 755 {} \;
find "${APP_PATH}" -type f -exec chmod 644 {} \;
for script in install.sh init.sh start.sh stop.sh clean.sh preinst.sh remove.sh apkg_helpers.sh helpers.sh; do
    [ ! -f "${APP_PATH}/${script}" ] || chmod 755 "${APP_PATH}/${script}"
done
log "BackupManager web path initialized"

# Private administrator data survives app upgrades.
DATA_DIR="${APPS_PATH}/_BackupManager"
if [ -L "$DATA_DIR" ]; then
    log "ERROR: Private data directory cannot be a symbolic link."
    exit 1
fi
mkdir -p "$DATA_DIR"
chown root:root "$DATA_DIR"
chmod 700 "$DATA_DIR"
if [ ! -f "$DATA_DIR/admin.json" ] && [ ! -f "$DATA_DIR/setup-token" ]; then
    umask 077
    if ! php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' > "$DATA_DIR/setup-token"; then
        rm -f "$DATA_DIR/setup-token"
        log "ERROR: Could not generate administrator setup code"
        exit 1
    fi
    chown root:root "$DATA_DIR/setup-token"
    chmod 600 "$DATA_DIR/setup-token"
    log "Administrator setup code available at $DATA_DIR/setup-token"
fi
