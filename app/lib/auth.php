<?php
/* BackupManager local administrator authentication, PHP 7.4. */
declare(strict_types=1);
function bm_data_dir(): string {
    $path = dirname(__DIR__) . '/../_BackupManager';
    $real = realpath($path);
    if ($real === false || is_link($path) || !is_dir($real)) throw new RuntimeException('Private data directory unavailable.');
    return $real;
}
function bm_admin_file(): string { return bm_data_dir() . '/admin.json'; }
function bm_token_file(): string { return bm_data_dir() . '/setup-token'; }
function bm_read_admin(): ?array {
    $path = bm_admin_file();
    if (is_link($path)) throw new RuntimeException('Invalid administrator file.');
    if (!is_file($path)) return null;
    $value = json_decode((string)file_get_contents($path), true);
    if (!is_array($value) || !isset($value['hash']) || !is_string($value['hash'])) throw new RuntimeException('Invalid administrator configuration.');
    return $value;
}
function bm_save_admin(string $password): void {
    $dir = bm_data_dir();
    $tmp = tempnam($dir, '.admin-');
    if ($tmp === false) throw new RuntimeException('Cannot create administrator file.');
    $data = json_encode(['hash'=>password_hash($password, PASSWORD_DEFAULT)]);
    if ($data === false || file_put_contents($tmp, $data, LOCK_EX) === false || !chmod($tmp, 0600) || !rename($tmp, bm_admin_file())) {
        @unlink($tmp); throw new RuntimeException('Cannot save administrator credentials.');
    }
}
function bm_auth_gate(): void {
    if (PHP_SAPI === 'cli') return;
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        http_response_code(403); exit('BackupManager requires HTTPS.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
    if (!isset($_SESSION['bm_csrf'])) $_SESSION['bm_csrf'] = bin2hex(random_bytes(24));
    $admin = bm_read_admin();
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bm_auth_action'])) {
        if (!hash_equals($_SESSION['bm_csrf'], (string)($_POST['bm_auth_csrf'] ?? ''))) $error='Invalid request token.';
        elseif ($_POST['bm_auth_action'] === 'logout') {
            $_SESSION = []; session_regenerate_id(true);
        } elseif ($_POST['bm_auth_action'] === 'setup' && $admin === null) {
            $tokenPath = bm_token_file();
            $token = !is_link($tokenPath) && is_file($tokenPath) ? trim((string)file_get_contents($tokenPath)) : '';
            $password = (string)($_POST['password'] ?? '');
            if ($token !== '' && hash_equals($token, (string)($_POST['setup_token'] ?? '')) && strlen($password) >= 12) {
                bm_save_admin($password); @unlink($tokenPath); $admin = bm_read_admin();
                session_regenerate_id(true); $_SESSION['bm_authenticated']=true;
            } else $error='Invalid setup code or password shorter than 12 characters.';
        } elseif ($_POST['bm_auth_action'] === 'login' && $admin !== null) {
            if (password_verify((string)($_POST['password'] ?? ''), $admin['hash'])) {
                session_regenerate_id(true); $_SESSION['bm_authenticated']=true;
            } else $error='Invalid password.';
        }
    }
    if ($admin !== null && !empty($_SESSION['bm_authenticated'])) return;
    header('Content-Type: text/html; charset=utf-8');
    $csrf=htmlspecialchars($_SESSION['bm_csrf'], ENT_QUOTES, 'UTF-8');
    $heading=$admin === null ? 'Administrator setup' : 'Administrator login';
    $action=$admin === null ? 'setup' : 'login';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>BackupManager login</title><style>body{font:16px system-ui;max-width:420px;margin:8vh auto;padding:20px}input,button{display:block;margin:12px 0;padding:10px;width:100%;box-sizing:border-box}</style></head><body><h1>BackupManager</h1><h2>'.htmlspecialchars($heading,ENT_QUOTES,'UTF-8').'</h2>';
    if ($error !== '') echo '<p role="alert">'.htmlspecialchars($error,ENT_QUOTES,'UTF-8').'</p>';
    echo '<form method="post"><input type="hidden" name="bm_auth_action" value="'.$action.'"><input type="hidden" name="bm_auth_csrf" value="'.$csrf.'">';
    if ($admin === null) echo '<label>One-time setup code<input name="setup_token" autocomplete="off" required></label>';
    echo '<label>Password<input type="password" name="password" autocomplete="'.($admin===null?'new-password':'current-password').'" required></label><button>'.($admin===null?'Create administrator':'Sign in').'</button></form></body></html>';
    exit;
}
