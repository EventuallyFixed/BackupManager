#!/usr/bin/env bash
set -Eeuo pipefail

# Standalone Backup Manager build orchestrator.
# Uses the existing isolated WD-NAS-App-Builder staging workspace.
# Only BackupManager staging and release paths are modified.
# Does not access or modify a NAS.

BM_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_SOURCE="$BM_ROOT/app"

STAGE="${BUILDER_STAGE:-$HOME/Nextcloud/src/BackupManager_Staging}"
BUILDER_APP="$STAGE/apps/BackupManager"
HELPERS="$STAGE/apps/build_helpers.sh"
APKG_HELPERS="$STAGE/apps/apkg_helpers.sh"

DOCKER_IMAGE="wd_builder:latest"
MODEL="${MODEL_OVERRIDE:-WDMyCloudEX4100}"

VERSION="$(awk -F: '/^Version:/ {
    gsub(/^[ \t]+|[ \t]+$/, "", $2); print $2
}' "$APP_SOURCE/apkg.rc")"

if [[ -z "$VERSION" ]]; then
    echo "ERROR: Could not read app version from app/apkg.rc."
    exit 1
fi

STAGING_BACKUPS="$STAGE/staging-backups"
RELEASE_BACKUPS="$STAGE/release-backups"
STAGE_RELEASE="$STAGE/packages/BackupManager/$VERSION"
FINAL_RELEASE="$BM_ROOT/releases/$VERSION"

STAMP="$(date +%Y%m%d-%H%M%S)"
APP_BACKUP="$STAGING_BACKUPS/BackupManager-before-build-$STAMP"
RELEASE_BACKUP="$RELEASE_BACKUPS/$VERSION-before-build-$STAMP"

die() {
    echo "ERROR: $*" >&2
    exit 1
}

echo "Backup Manager standalone build"
echo "Version: $VERSION"
echo "Model:   $MODEL"
echo "Source:  $APP_SOURCE"
echo "Stage:   $STAGE"

# Validate the source and isolated builder.
[[ -d "$APP_SOURCE" ]] || die "App source directory is missing."
[[ -f "$APP_SOURCE/apkg.rc" ]] || die "app/apkg.rc is missing."
[[ -f "$APP_SOURCE/build.sh" ]] || die "app/build.sh is missing."
[[ -f "$APP_SOURCE/web/index.php" ]] || die "app/web/index.php is missing."
[[ -f "$APP_SOURCE/lib/auth.php" ]] || die "app/lib/auth.php is missing."
[[ -f "$HELPERS" ]] || die "Builder build_helpers.sh is missing at $HELPERS. Set BUILDER_STAGE to the existing builder workspace."
[[ -f "$APKG_HELPERS" ]] || die "Builder apkg_helpers.sh is missing."
[[ -f "$STAGE/build.sh" ]] || die "Builder root build.sh is missing."
[[ -d "$STAGE/apps" ]] || die "Builder apps directory is missing."

# Confirm Docker and the required image before changing staging contents.
command -v sudo >/dev/null || die "sudo is not available."
sudo docker image inspect "$DOCKER_IMAGE" >/dev/null ||
    die "Docker image $DOCKER_IMAGE is unavailable."

# Refuse unexpected paths or a builder workspace that resolves to the
# original WD-NAS-App-Builder checkout.
STAGE_REAL="$(realpath "$STAGE")"
ORIGINAL_BUILDER="${ORIGINAL_BUILDER:-$HOME/NextCloud/src/WD-NAS-App-Builder-main}"

[[ ! -e "$ORIGINAL_BUILDER" || "$STAGE_REAL" != "$(realpath "$ORIGINAL_BUILDER")" ]] ||
    die "Refusing to use the original builder workspace."

[[ "$STAGE_REAL" != "$BM_ROOT" ]] ||
    die "Builder staging directory must be separate from the BackupManager checkout."

# Limit the model to names supported by this builder.
case " WDCloud WDMyCloud WDMyCloudMirror WDMyCloudEX4100 WDMyCloudEX2100 MyCloudEX2Ultra MyCloudPR4100 MyCloudPR2100 WDMyCloudDL4100 WDMyCloudDL2100 " in
    *" $MODEL "*) ;;
    *) die "Unsupported model name: $MODEL" ;;
esac

# Never silently replace an already-published standalone release.
[[ ! -e "$FINAL_RELEASE" ]] ||
    die "Release destination already exists: $FINAL_RELEASE"

mkdir -p "$STAGING_BACKUPS" "$RELEASE_BACKUPS"

# Preserve the existing staging app and release before the builder can
# replace or clean either one.
if [[ -e "$BUILDER_APP" ]]; then
    [[ ! -e "$APP_BACKUP" ]] || die "App backup destination already exists."
    mv "$BUILDER_APP" "$APP_BACKUP"
    echo "Preserved previous staging app: $APP_BACKUP"
fi

if [[ -e "$STAGE_RELEASE" ]]; then
    [[ ! -e "$RELEASE_BACKUP" ]] || die "Release backup destination already exists."
    mkdir -p "$(dirname "$RELEASE_BACKUP")"
    cp -a "$STAGE_RELEASE" "$RELEASE_BACKUP"
    echo "Preserved previous staging release: $RELEASE_BACKUP"
fi

# Stage only the NAS-installable runtime payload.
cp -a "$APP_SOURCE" "$BUILDER_APP"
# WD executes lifecycle scripts directly, so they must be executable in
# the packaged archive, not only after init.sh runs on the NAS.
for script in install.sh init.sh start.sh stop.sh clean.sh preinst.sh remove.sh; do
    [[ -f "$BUILDER_APP/$script" ]] ||
        die "Required lifecycle script missing: $script"
    chmod 755 "$BUILDER_APP/$script"
done
chmod 755 "$BUILDER_APP/build.sh"

# Check the staged copy is identical to the source, apart from the
# executable bit applied to build.sh.
diff -qr \
    --exclude=build.sh \
    "$APP_SOURCE" "$BUILDER_APP" ||
    die "Staged app differs from standalone app source."

if find "$BUILDER_APP" \
    \( -name .git -o -name development -o -name packages \) \
    -print | grep -q .; then
    die "Unwanted development or Git content found in staged app."
fi

# Run the existing builder. The original builder checkout is not mounted.
echo
echo "Starting Docker build..."

sudo docker run --rm \
    -v "$STAGE:/data" \
    -e MODEL_OVERRIDE="$MODEL" \
    -w /data/apps/BackupManager \
    "$DOCKER_IMAGE" \
    /bin/bash -lc './build.sh'

# Check expected outputs before publishing.
ARCHIVE="$STAGE_RELEASE/BackupManager_${VERSION}_armhf.tar.gz"
BINARY="$STAGE_RELEASE/BackupManager_${VERSION}_${MODEL}.bin"

[[ -s "$ARCHIVE" ]] || die "ARM archive was not produced."
[[ -s "$BINARY" ]] || die "Model package was not produced."

# Verify executable permissions as stored in the actual archive.
for script in install.sh init.sh start.sh stop.sh clean.sh preinst.sh remove.sh; do
    mode="$(tar -tvzf "$ARCHIVE" "./$script" | awk 'NR == 1 {print $1}')" ||
        die "Could not inspect packaged permissions for $script"
    [[ "$mode" == -rwx* ]] ||
        die "Packaged lifecycle script is not executable: $script ($mode)"
done

gzip -t "$ARCHIVE" || die "ARM archive failed gzip integrity check."

# Read the archive listing once. Piping tar into grep -q under pipefail can
# produce a false failure when grep exits early and tar receives SIGPIPE.
ARCHIVE_LIST="$(tar -tzf "$ARCHIVE")" ||
    die "Could not read ARM archive contents."

for required in web/index.php lib/auth.php init.sh install.sh apkg.rc; do
    if ! grep -Eq "(^|/)${required}$" <<< "$ARCHIVE_LIST"; then
        die "Required runtime file missing from archive: $required"
    fi
done

if grep -E '(^|/)(\\.git|development|packages)(/|$)' <<< "$ARCHIVE_LIST" >/dev/null; then
    die "Archive contains unwanted Git, development or package content."
fi

# Verify the build helper restored the app's build script.
[[ -f "$BUILDER_APP/build.sh" ]] ||
    die "Builder did not restore build.sh."
[[ ! -f "$STAGE/apps/build_BackupManager.sh" ]] ||
    die "Temporary build script remains in builder apps directory."

# Publish into a new release directory without replacing existing releases.
PUBLISH_TMP="$BM_ROOT/releases/.${VERSION}.tmp-$STAMP"
[[ ! -e "$PUBLISH_TMP" ]] || die "Temporary publish directory already exists."

mkdir -p "$PUBLISH_TMP"
cp -a "$ARCHIVE" "$BINARY" "$PUBLISH_TMP/"

mkdir -p "$BM_ROOT/releases"
mv "$PUBLISH_TMP" "$FINAL_RELEASE"

echo
echo "Build and publish completed."
echo "Release directory: $FINAL_RELEASE"
sha256sum "$FINAL_RELEASE"/*

echo
echo "Previous staging data and release backups were retained."
