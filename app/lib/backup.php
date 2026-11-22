<?php
/* Incremental rsync snapshots for BackupManager, PHP 7.4. */
declare(strict_types=1);

function bm_rsync_processes(string $mount): array {
    $root = $mount . '/backup/';
    $found = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        $args = @file_get_contents($file);
        if ($args === false || $args === '') continue;
        $parts = explode("\0", rtrim($args, "\0"));
        if (basename($parts[0] ?? '') !== 'rsync') continue;
        foreach ($parts as $arg) {
            // Match the exact destination argument, not --link-dest or --log-file.
            if (preg_match('#^' . preg_quote($root, '#') . '(backup_[0-9]{8}_[0-9]{6})/[^/]+/$#D', $arg, $m)) {
                $found[(int) basename(dirname($file))] = $m[1];
                break;
            }
        }
    }
    return $found;
}

function bm_backup_running(string $mount): bool {
    return bm_rsync_processes($mount) !== [];
}

function bm_running_snapshot(string $mount): ?string {
    $names = array_values(bm_rsync_processes($mount));
    return $names ? $names[0] : null;
}

function bm_snapshot_status(string $mount, string $snapshot): string {
    $file = $mount . '/backup/' . $snapshot . '/.backupmanager-status';
    $status = is_file($file) && !is_link($file) ? trim((string) @file_get_contents($file)) : '';
    if ($status === 'Error' || $status === 'Cancelled') return $status;
    if (in_array($snapshot, bm_rsync_processes($mount), true)) return 'Running';
    if ($status === 'Running' || ($status !== '' && $status !== 'Complete')) return 'Error - Incomplete';
    return $status === 'Complete' ? 'Complete' : 'Error - Incomplete';
}

function bm_backfill_snapshots(string $mount, array $names): int {
    if (bm_backup_running($mount)) throw new RuntimeException('Cannot import snapshots during a backup.');
    $root = $mount . '/backup';
    if (is_link($root) || !is_dir($root)) throw new RuntimeException('Backup directory unavailable.');
    $count = 0;
    foreach ($names as $name) {
        if (!is_string($name) || !preg_match('/^backup_[0-9]{8}_[0-9]{6}$/D', $name)) continue;
        $dir = $root . '/' . $name;
        $marker = $dir . '/.backupmanager-status';
        if (is_link($dir) || !is_dir($dir) || is_link($marker) || file_exists($marker)) continue;
        if (@file_put_contents($marker, "Complete\n", LOCK_EX) === false) {
            throw new RuntimeException('Could not mark snapshot ' . $name . ' as Complete.');
        }
        $count++;
    }
    return $count;
}

function bm_set_snapshot_status(string $mount, string $snapshot, string $status): void {
    $file = $mount . '/backup/' . $snapshot . '/.backupmanager-status';
    if (!is_dir(dirname($file)) || is_link($file)) return;
    // Never overwrite a cancellation recorded by the Stop button.
    if ($status !== 'Cancelled' && trim((string) @file_get_contents($file)) === 'Cancelled') return;
    @file_put_contents($file, $status . "\n");
}

function bm_stop_backup(string $mount): int {
    $processes = bm_rsync_processes($mount);
    foreach (array_unique(array_values($processes)) as $snapshot) {
        bm_set_snapshot_status($mount, $snapshot, 'Cancelled');
    }
    foreach (array_keys($processes) as $pid) {
        if ($pid > 1) {
            // Only PIDs whose live rsync destination matched this disk.
            exec('/bin/kill -TERM ' . (int) $pid . ' 2>/dev/null');
        }
    }
    return count($processes);
}

function bm_delete_snapshot(string $mount, string $snapshot): void {
    if (!preg_match('/^backup_[0-9]{8}_[0-9]{6}$/D', $snapshot)) {
        throw new RuntimeException('Invalid backup directory.');
    }
    if (bm_backup_running($mount)) {
        throw new RuntimeException('Cannot delete a snapshot while a backup is running.');
    }
    $root = $mount . '/backup';
    if (is_link($root) || !is_dir($root)) throw new RuntimeException('Backup directory unavailable.');
    $target = $root . '/' . $snapshot;
    if (is_link($target) || !is_dir($target) || dirname((string) realpath($target)) !== realpath($root)) {
        throw new RuntimeException('Snapshot directory unavailable.');
    }
    $current = $root . '/current';
    $isCurrent = is_link($current) && realpath($current) === realpath($target);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $ok = $entry->isDir() && !$entry->isLink() ? @rmdir($path) : @unlink($path);
        if (!$ok) throw new RuntimeException('Could not remove ' . $path);
    }
    if (!@rmdir($target)) throw new RuntimeException('Could not remove snapshot directory.');
    if ($isCurrent && !@unlink($current)) {
        throw new RuntimeException('Snapshot deleted, but could not remove current symlink.');
    }
}

// Delete only an older snapshot, never the running snapshot or the current base.
function bm_thin_oldest(string $mount, string $running): ?string {
    $root = $mount . '/backup';
    $current = $root . '/current';
    $protected = is_link($current) ? basename((string) realpath($current)) : '';
    $names = [];
    foreach (scandir($root) ?: [] as $name) {
        if (!preg_match('/^backup_[0-9]{8}_[0-9]{6}$/D', $name)
            || $name === $running || $name === $protected
            || is_link($root . '/' . $name) || !is_dir($root . '/' . $name)) continue;
        $names[] = $name;
    }
    sort($names, SORT_STRING);
    if (!$names) return null;
    $name = $names[0];
    $dir = $root . '/' . $name;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        $ok = $item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path);
        if (!$ok) throw new RuntimeException('Could not thin ' . $path);
    }
    if (!@rmdir($dir)) throw new RuntimeException('Could not remove old backup ' . $name);
    return $name;
}

function bm_log_has_disk_full(string $log): bool {
    if (!is_file($log)) return false;
    $handle = @fopen($log, 'rb');
    if ($handle === false) return false;
    $size = filesize($log);
    if ($size !== false && $size > 131072) fseek($handle, -131072, SEEK_END);
    $tail = stream_get_contents($handle);
    fclose($handle);
    return is_string($tail) && stripos($tail, 'No space left on device') !== false;
}

function bm_run_backup(string $mount, array $disk, array $available, array $config): string {
    $uuid = diskUuid($disk['device']);
    if ($uuid === null || ($config['filesystem_uuid'] ?? '') !== $uuid) {
        throw new RuntimeException('Destination filesystem UUID has changed.');
    }
    if (!isset(mounts()[$mount]) || !isUsbBlockDevice($disk['device'])) {
        throw new RuntimeException('USB destination is no longer mounted.');
    }
    if (!is_writable($mount)) throw new RuntimeException('USB destination is not writable.');
    $names = $config['sources'] ?? null;
    if (!is_array($names) || !$names || count(array_diff($names, array_keys($available))) !== 0) {
        throw new RuntimeException('Configured source shares are unavailable.');
    }
    $configDir = $mount . '/.backupmanager';
    if (is_link($configDir) || !is_dir($configDir)) {
        throw new RuntimeException('Destination configuration directory is unavailable.');
    }
    if (bm_backup_running($mount)) {
        throw new RuntimeException('Another backup is already running.');
    }
    // Process detection replaces the old lock; no persistent PID or lock file.
    // Simultaneous submissions may still race before rsync starts.
        // Recheck destination before beginning.
        if (!isset(mounts()[$mount]) || diskUuid($disk['device']) !== $uuid) {
            throw new RuntimeException('Destination changed before backup started.');
        }
        $root = $mount . '/backup';
        $logs = $mount . '/log';
        foreach ([$root, $logs] as $dir) {
            if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
                throw new RuntimeException('Unsafe backup or log directory.');
            }
            if (!is_dir($dir) && !mkdir($dir, 0755)) {
                throw new RuntimeException('Cannot create backup or log directory.');
            }
        }
        $stamp = date('Ymd_His');
        $snapshot = 'backup_' . $stamp;
        $target = $root . '/' . $snapshot;
        if (file_exists($target) || is_link($target) || !mkdir($target, 0755)) {
            throw new RuntimeException('Cannot create a unique snapshot directory.');
        }
        $runningLink = $root . '/running';
        if (is_link($runningLink) || file_exists($runningLink)) {
            throw new RuntimeException('A running backup pointer already exists; inspect it before starting another backup.');
        }
        if (!symlink($snapshot, $runningLink)) {
            throw new RuntimeException('Could not create running backup pointer.');
        }
        bm_set_snapshot_status($mount, $snapshot, 'Running');
        try {
        $current = $root . '/current';
        $previous = null;
        if (is_link($current)) {
            $resolved = realpath($current);
            if ($resolved !== false && dirname($resolved) === realpath($root)
                && preg_match('/^backup_[0-9]{8}_[0-9]{6}$/D', basename($resolved))) {
                $previous = $resolved;
            }
        } elseif (file_exists($current)) {
            throw new RuntimeException('The current snapshot path is not a symbolic link.');
        }
        $log = $logs . '/rsync_timemachine_' . $stamp . '.log';
        foreach ($names as $name) {
            if (!is_string($name) || !isset($available[$name]) || $name === ''
                || $name === '.' || $name === '..' || strpos($name, '/') !== false) {
                throw new RuntimeException('Invalid source share name.');
            }
            $source = $available[$name];
            if (!is_dir($source)) throw new RuntimeException('Source share disappeared: ' . $name);
            $destination = $target . '/' . $name;
            if (!is_dir($destination) && !mkdir($destination, 0755)) throw new RuntimeException('Cannot create snapshot share directory.');
            // Original rsync options, without -R: -aP --delete --log-file and --link-dest.
            // A per-share link destination keeps the same directory structure each run.
            $cmd = '/usr/sbin/rsync -aP --delete'
                . ' --log-file=' . escapeshellarg($log);
            // Preserve the exclusions from the supplied rsync script.
            $excludes = [
                'docker_nas_bin', '.USB', '.groupingDb', '.systemfile',
                '.twonkymedia', '.wdmc', 'Nas_Prog', 'SmartWare',
                'TimeMachineBackup', 'Transmission', 'lost+found',
                'otaclient', 'restsdk-data', 'restsdk-info', 'plex_conf', '_un'
            ];
            if ($name === 'docker') {
                $excludes[] = 'nextcloud_data/appdata*';
                $excludes[] = 'mariadb/var/lib/mysql';
                $excludes[] = 'nextcloud';
                // The original command included nextcloud/config before
                // excluding nextcloud; preserve its order and intent.
            }
            foreach ($excludes as $exclude) {
                $cmd .= ' --exclude=' . escapeshellarg($exclude);
            }
            if ($previous !== null && is_dir($previous . '/' . $name)) {
                $cmd .= ' --link-dest=' . escapeshellarg($previous . '/' . $name);
            }
            $cmd .= ' ' . escapeshellarg($source . '/')
                . ' ' . escapeshellarg($destination . '/')
                . ' >> ' . escapeshellarg($log) . ' 2>&1';
            // On ENOSPC, remove one old snapshot and retry this share in place.
            // Rsync can reuse files already copied into the running snapshot.
            while (true) {
                $offset = is_file($log) ? (int) filesize($log) : 0;
                $exit = 0;
                exec($cmd, $ignored, $exit);
                if (bm_snapshot_status($mount, $snapshot) === 'Cancelled') {
                    throw new RuntimeException('Backup cancelled. Incomplete snapshot retained.');
                }
                if ($exit === 0) break;
                clearstatcache(true, $log);
                $handle = @fopen($log, 'rb');
                $newLog = '';
                if ($handle !== false) {
                    fseek($handle, $offset);
                    $newLog = (string) stream_get_contents($handle);
                    fclose($handle);
                }
                if (stripos($newLog, 'No space left on device') === false) {
                    throw new RuntimeException('rsync failed for ' . $name . ' (exit ' . $exit . '). See ' . $log . '. Incomplete snapshot retained; current unchanged.');
                }
                $removed = bm_thin_oldest($mount, $snapshot);
                if ($removed === null) {
                    throw new RuntimeException('Disk full, but no older snapshot can be safely removed. Current backup preserved; incomplete snapshot retained.');
                }
                @file_put_contents($log, "\nBackupManager: disk full; removed oldest backup " . $removed . " and retrying " . $name . ".\n", FILE_APPEND);
            }
        }
        $newLink = $root . '/.current-' . $stamp;
        if (!symlink($snapshot, $newLink) || !rename($newLink, $current)) {
            @unlink($newLink);
            throw new RuntimeException('Backup finished but could not update the current snapshot link.');
        }
        bm_set_snapshot_status($mount, $snapshot, 'Complete');
        @unlink($runningLink);
        return 'Backup completed: ' . $snapshot;
        } catch (Throwable $e) {
            bm_set_snapshot_status($mount, $snapshot, 'Error');
            if (is_link($runningLink) && readlink($runningLink) === $snapshot) @unlink($runningLink);
            throw $e;
        }
}
