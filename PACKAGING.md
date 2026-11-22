# WD My Cloud OS5 packaging (development)

The WD-NAS-App-Builder integration is based on the existing EncDiskManager 0.8.6 workflow.

## Builder staging

Copy the contents of `app/` into `<builder>/apps/BackupManager/` and copy `web/index.php` into `<builder>/apps/BackupManager/web/index.php`. The builder supplies `apps/build_helpers.sh` and `apps/apkg_helpers.sh`; do not commit generated helpers to this repository.

From the staged builder app directory, run `./build.sh` inside the same builder Docker image/environment used for EncDiskManager. Set `MODEL_OVERRIDE=WDMyCloudEX4100` to target the EX4100.

Do not install until the package has passed syntax and security checks. The Stage 1 web prototype is not authenticated and is not suitable for NAS deployment yet.

## Expected payload layout

- `app/apkg.rc`
- `app/build.sh`
- `app/install.sh`, `init.sh`, `start.sh`, `stop.sh`, `clean.sh`, `preinst.sh`, `remove.sh`
- `app/logo.svg`
- `app/web/index.php` (staged from repository `web/index.php`)

The actual builder expects the PHP entry point under `app/web/` in its staged application tree.
