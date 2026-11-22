<?php
/* Incremental rsync snapshots for BackupManager, PHP 7.4. */
declare(strict_types=1);

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
    $lock = fopen($configDir . '/backup.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another backup is already running.');
    }
    try {
        // Recheck while holding the per-destination lock.
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
            if (!mkdir($destination, 0755)) throw new RuntimeException('Cannot create snapshot share directory.');
            // Original user-supplied rsync options: -aPr --delete --log-file and --link-dest.
            // A per-share link destination keeps the same directory structure each run.
            $cmd = '/usr/sbin/rsync -aPr --delete'
                . ' --log-file=' . escapeshellarg($log);
            if ($previous !== null && is_dir($previous . '/' . $name)) {
                $cmd .= ' --link-dest=' . escapeshellarg($previous . '/' . $name);
            }
            $cmd .= ' ' . escapeshellarg($source . '/./')
                . ' ' . escapeshellarg($destination . '/')
                . ' >> ' . escapeshellarg($log) . ' 2>&1';
            $exit = 0;
            exec($cmd, $ignored, $exit);
            if ($exit !== 0) {
                throw new RuntimeException('rsync failed for ' . $name . ' (exit ' . $exit . '). See ' . $log . '. Incomplete snapshot retained; current unchanged.');
            }
        }
        $newLink = $root . '/.current-' . $stamp;
        if (!symlink($snapshot, $newLink) || !rename($newLink, $current)) {
            @unlink($newLink);
            throw new RuntimeException('Backup finished but could not update the current snapshot link.');
        }
        return 'Backup completed: ' . $snapshot;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
