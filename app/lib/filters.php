<?php
/* Configurable rsync include/exclude rules. */
declare(strict_types=1);

function bm_default_filters(): array {
    $patterns = ['docker_nas_bin','.USB','.groupingDb','.systemfile','.twonkymedia','.wdmc','Nas_Prog','SmartWare','TimeMachineBackup','Transmission','lost+found','otaclient','restsdk-data','restsdk-info','plex_conf','_un'];
    $rules = [];
    foreach ($patterns as $pattern) $rules[] = ['type'=>'exclude','pattern'=>$pattern,'share'=>'*'];
    foreach (['nextcloud_data/appdata*','mariadb/var/lib/mysql','nextcloud'] as $pattern) {
        $rules[] = ['type'=>'exclude','pattern'=>$pattern,'share'=>'docker'];
    }
    return $rules;
}

function bm_validate_filters($rules): array {
    if (!is_array($rules) || count($rules) > 100) throw new RuntimeException('Invalid rsync rules.');
    $result = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) throw new RuntimeException('Invalid rsync rule.');
        $type = $rule['type'] ?? '';
        $pattern = $rule['pattern'] ?? '';
        $share = $rule['share'] ?? '';
        if (!in_array($type, ['include','exclude'], true) || !is_string($pattern)
            || $pattern === '' || strlen($pattern) > 512 || strpos($pattern, "\0") !== false
            || !is_string($share) || ($share !== '*' && !preg_match('/^[A-Za-z0-9_.-]+$/D', $share))) {
            throw new RuntimeException('Invalid rsync filter type, pattern or share.');
        }
        $result[] = compact('type','pattern','share');
    }
    return $result;
}
