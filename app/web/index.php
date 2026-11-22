<?php
/** BackupManager stage 1: mounted disk discovery, snapshot listing, per-disk source configuration. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
bm_auth_gate();

const SOURCE_ROOT = '/mnt/HD/HD_a2';
const SHARE_ROOT = '/shares';
const CONFIG_DIR = '.backupmanager';
const CONFIG_FILE = 'config.json';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mountDecode(string $s): string { return preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $s); }
function isUsbBlockDevice(string $device): bool {
    if (strpos($device, '/dev/') !== 0) return false;
    $name = basename($device);
    // Map /dev/mapper/LUKS names to their dm-N kernel block device.
    $stat = @stat($device);
    if ($stat === false) return false;
    $major = ($stat['rdev'] >> 8) & 0xfff;
    $minor = ($stat['rdev'] & 0xff) | (($stat['rdev'] >> 12) & 0xfff00);
    $sys = realpath('/sys/dev/block/' . $major . ':' . $minor);
    if ($sys === false) return false;
    return blockPathIsUsb($sys, []);
}
function blockPathIsUsb(string $sys, array $seen): bool {
    if (isset($seen[$sys])) return false;
    $seen[$sys] = true;
    // Direct USB devices contain a USB bus segment in their sysfs ancestry.
    if (preg_match('#/usb[0-9]+/#', $sys)) return true;
    // Device-mapper layers expose underlying block devices as slaves.
    foreach (glob($sys . '/slaves/*') ?: [] as $slave) {
        $underlying = realpath($slave);
        if ($underlying !== false && blockPathIsUsb($underlying, $seen)) return true;
    }
    return false;
}
function mounts(): array {
    $out = [];
    foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 3) continue;
        [$device,$mount,$type] = $p;
        $mount = mountDecode($mount);
        if ($type !== 'ext4' || strpos($mount, '/mnt/') !== 0 || $mount === SOURCE_ROOT || strpos($mount, SOURCE_ROOT . '/') === 0 || !isUsbBlockDevice(mountDecode($device))) continue;
        if (!is_dir($mount)) continue;
        $out[$mount] = ['path'=>$mount, 'device'=>mountDecode($device), 'type'=>$type];
    }
    ksort($out);
    return $out;
}
function diskUuid(string $device): ?string {
    if (strpos($device, '/dev/') !== 0) return null;
    $cmd = '/usr/bin/blkid -s UUID -o value ' . escapeshellarg($device) . ' 2>/dev/null';
    $value = trim((string) shell_exec($cmd));
    return preg_match('/^[a-zA-Z0-9-]{8,80}$/D', $value) ? $value : null;
}
function sources(): array {
    $out = []; $base = realpath(SOURCE_ROOT);
    if ($base === false) return [];
    foreach (scandir(SHARE_ROOT) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $link = SHARE_ROOT . '/' . $name;
        if (!is_link($link)) continue;
        $target = realpath($link);
        if ($target === false || !is_dir($target) || dirname($target) !== $base) continue;
        $out[$name] = $target;
    }
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}
function configPath(string $mount): string { return $mount . '/' . CONFIG_DIR . '/' . CONFIG_FILE; }
function readConfig(string $mount): ?array {
    $file = configPath($mount);
    if (!is_file($file) || is_link($file)) return null;
    $config = json_decode((string) @file_get_contents($file), true);
    if (!is_array($config) || ($config['version'] ?? null) !== 1 || !isset($config['sources']) || !is_array($config['sources'])) return null;
    return $config;
}
function snapshots(string $mount): array {
    $dir = $mount . '/backup';
    if (!is_dir($dir) || is_link($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $name) {
        if (!preg_match('/^backup_(\d{8})_(\d{6})$/D', $name, $m) || !is_dir($dir . '/' . $name) || is_link($dir . '/' . $name)) continue;
        $dt = DateTimeImmutable::createFromFormat('!Ymd_His', $m[1] . '_' . $m[2]);
        if (!$dt || $dt->format('Ymd_His') !== $m[1] . '_' . $m[2]) continue;
        $out[] = ['name'=>$name, 'date'=>$dt->format('d M Y, H:i:s')];
    }
    usort($out, static fn($a,$b) => strcmp($b['name'], $a['name']));
    return $out;
}
require_once __DIR__ . '/../lib/backup.php';
$disks = mounts();
$selected = (string) ($_POST['disk'] ?? $_GET['disk'] ?? '');
if (!isset($disks[$selected])) $selected = (count($disks) ? key($disks) : '');
if (($_GET['action'] ?? '') === 'backup-status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['running' => $selected !== '' && bm_backup_running($selected),
        'snapshot' => $selected !== '' ? bm_running_snapshot($selected) : null]);
    exit;
}
$available = sources();
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['bm_csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); $error='Invalid request token.'; }
    elseif (($_POST['action'] ?? '') === 'save' && isset($disks[$selected])) {
        $requested = $_POST['sources'] ?? [];
        if (!is_array($requested)) $requested = [];
        $requested = array_values(array_unique(array_filter($requested, 'is_string')));
        if (!$requested || array_diff($requested, array_keys($available))) $error = 'Select at least one available share.';
        else {
            $uuid = diskUuid($disks[$selected]['device']);
            if ($uuid === null) $error = 'Cannot identify the destination filesystem UUID.';
            else {
                $folder = $selected . '/' . CONFIG_DIR;
                if (is_link($folder) || (file_exists($folder) && !is_dir($folder))) $error='Unsafe configuration directory.';
                elseif ((!is_dir($folder) && !@mkdir($folder, 0700)) || !is_dir($folder)) $error='Cannot create configuration directory.';
                else {
                    $payload = json_encode(['version'=>1,'filesystem_uuid'=>$uuid,'sources'=>$requested], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $tmp = @tempnam($folder, '.config-');
                    if ($tmp === false || @file_put_contents($tmp, $payload . "\n", LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, configPath($selected))) {
                        if ($tmp !== false) @unlink($tmp);
                        $error='Could not save configuration.';
                    } else $message='Configuration saved for this disk.';
                }
            }
        }
    }
}
$config = $selected !== '' ? readConfig($selected) : null;
$uuid = $selected !== '' ? diskUuid($disks[$selected]['device']) : null;
$valid = $config !== null && $uuid !== null && ($config['filesystem_uuid'] ?? '') === $uuid && count($config['sources']) > 0 && count(array_intersect($config['sources'], array_keys($available))) > 0 && count(array_diff($config['sources'], array_keys($available))) === 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup' && $error === '') {
    if (!$valid || $selected === '') {
        $error = 'A valid USB destination configuration is required.';
    } else {
        // Allow long-running rsync without holding the administrator session lock.
        session_write_close();
        @set_time_limit(0);
        try {
            $message = bm_run_backup($selected, $disks[$selected], $available, $config);
        } catch (Throwable $e) {
            if ($e->getMessage() === 'Another backup is already running.') {
                $message = 'Backup is already in progress.';
            } else {
                $error = $e->getMessage();
            }
        }
    }
}
$checked = $config['sources'] ?? [];
$backups = $selected !== '' ? snapshots($selected) : [];
$running = $selected !== '' && bm_backup_running($selected);
$runningSnapshot = $running ? bm_running_snapshot($selected) : null;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BackupManager</title>
<style>body{font:15px system-ui,sans-serif;background:#f5f7fa;color:#1d2939;margin:0}main{max-width:950px;margin:35px auto;padding:0 18px}header{display:flex;align-items:center;justify-content:space-between}h1{font-size:26px}h2{font-size:19px;margin-top:0}.panel{background:white;border:1px solid #e0e5ec;border-radius:12px;padding:22px;margin:18px 0}select,button{font:inherit;padding:9px 12px;border-radius:7px;border:1px solid #b8c1cc}select{width:100%;max-width:650px}button{background:#165caa;color:white;border-color:#165caa;cursor:pointer}button:disabled{background:#d5d9df;border-color:#d5d9df;color:#697586;cursor:not-allowed}.spinner{display:none;width:15px;height:15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;vertical-align:-3px;margin-right:8px;animation:bm-spin .7s linear infinite}button.is-running .spinner{display:inline-block}@keyframes bm-spin{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.spinner{animation-duration:2s}}.muted{color:#667085}.warn{color:#a15c00}.good{color:#167047}.error{color:#b42318}table{border-collapse:collapse;width:100%}th,td{text-align:left;border-bottom:1px solid #e7eaf0;padding:11px 8px}th{color:#667085}label.share{display:flex;gap:12px;align-items:center;padding:10px;border-bottom:1px solid #eee}code{font-size:12px;overflow-wrap:anywhere}.small{font-size:13px}</style></head><body><main><header><h1>BackupManager</h1><span class="muted">WD My Cloud OS5 · Stage 1</span></header>
<?php if ($message): ?><p class="good"><?=h($message)?></p><?php endif; ?><?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>
<section class="panel"><h2>Destination disk</h2><?php if (!$disks): ?><p class="warn">No mounted USB ext4 destination disks detected.</p><?php else: ?><form method="get"><select name="disk" onchange="this.form.submit()" aria-label="Destination disk"><?php foreach ($disks as $path=>$disk): ?><option value="<?=h($path)?>" <?=$path===$selected?'selected':''?>><?=h($path)?></option><?php endforeach; ?></select><noscript><button>Choose disk</button></noscript></form><p class="small muted">Device mapper: <?=h($disks[$selected]['device'] ?? 'Unavailable')?></p><p class="small muted">Filesystem UUID: <?=h($uuid ?? 'Unavailable')?></p><?php endif; ?></section>
<?php if ($selected !== ''): ?><section class="panel"><h2>Backup status</h2><?php if (!$valid): ?><p class="warn">No valid backup configuration for this disk. Backups are disabled until you save a valid selection.</p><?php endif; ?><?php if ($valid): ?><form method="post" id="backup-form"><input type="hidden" name="csrf" value="<?=h($_SESSION['bm_csrf'])?>"><input type="hidden" name="disk" value="<?=h($selected)?>"><input type="hidden" name="action" value="backup"><button type="submit" id="backup-button" <?=$running?'disabled class="is-running" aria-busy="true"':''?>><span class="spinner" aria-hidden="true"></span><span id="backup-button-label"><?=$running?'Backup running…':'Start backup'?></span></button></form><?php else: ?><button disabled>Start backup</button><?php endif; ?></section>
<section class="panel"><h2>Available backups (<?=count($backups)?>)</h2><table><thead><tr><th>Backup directory</th><th>Date and time (NAS local)</th></tr></thead><tbody><?php foreach ($backups as $b): ?><tr><td><code><?=h($b['name'])?></code><span class="backup-running" data-snapshot="<?=h($b['name'])?>" <?=$runningSnapshot===$b['name']?'':'hidden'?>>(running)</span></td><td><?=h($b['date'])?></td></tr><?php endforeach; ?><?php if (!$backups): ?><tr><td colspan="2" class="muted">No backups found on this disk.</td></tr><?php endif; ?></tbody></table></section>
<section class="panel"><h2>Configuration — backup sources</h2><p class="muted">Select shares linked directly to <?=h(SOURCE_ROOT)?>. Settings are saved on this destination disk.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['bm_csrf'])?>"><input type="hidden" name="disk" value="<?=h($selected)?>"><input type="hidden" name="action" value="save"><?php foreach ($available as $name=>$path): ?><label class="share"><input type="checkbox" name="sources[]" value="<?=h($name)?>" <?=in_array($name,$checked,true)?'checked':''?>><strong><?=h($name)?></strong><code class="muted"><?=h($path)?></code></label><?php endforeach; ?><?php if (!$available): ?><p class="warn">No eligible shares detected.</p><?php endif; ?><p><button <?=!$available?'disabled':''?>>Save configuration</button></p></form></section><?php endif; ?>
</main><script>
(function () {
    var form = document.getElementById('backup-form');
    var button = document.getElementById('backup-button');
    var label = document.getElementById('backup-button-label');
    if (!form || !button) return;
    var statusUrl = '?disk=' + encodeURIComponent(<?=json_encode($selected)?>) + '&action=backup-status';
    function showRunning(running, snapshot) {
        document.querySelectorAll('.backup-running').forEach(function (tag) {
            tag.hidden = !(running && snapshot === tag.getAttribute('data-snapshot'));
        });
        button.disabled = running;
        button.classList.toggle('is-running', running);
        if (running) button.setAttribute('aria-busy', 'true');
        else button.removeAttribute('aria-busy');
        label.textContent = running ? 'Backup running…' : 'Start backup';
    }
    function poll() {
        fetch(statusUrl, {credentials: 'same-origin', cache: 'no-store'})
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (data && typeof data.running === 'boolean') showRunning(data.running, data.snapshot);
            })
            .catch(function () {});
    }
    form.addEventListener('submit', function () { showRunning(true, null); });
    // Check the actual destination lock, including after reload or navigation.
    poll();
    window.setInterval(poll, 3000);
    window.addEventListener('pageshow', poll);
}());
</script></body></html>
