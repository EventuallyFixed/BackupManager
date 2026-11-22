<?php
/* Calendar-based snapshot retention, PHP 7.4. */
declare(strict_types=1);

function bm_pruning_defaults(): array {
    return [
        ['keep'=>0, 'unit'=>'day', 'period'=>1, 'span'=>'day'],
        ['keep'=>1, 'unit'=>'day', 'period'=>7, 'span'=>'day'],
        ['keep'=>1, 'unit'=>'week', 'period'=>7, 'span'=>'week'],
        ['keep'=>1, 'unit'=>'month', 'period'=>3, 'span'=>'month'],
        ['keep'=>1, 'unit'=>'six_months', 'period'=>1, 'span'=>'year'],
        ['keep'=>1, 'unit'=>'year', 'period'=>100, 'span'=>'year'],
    ];
}

// Rows apply in order. A zero keep count means retain every snapshot.
function bm_pruning_validate($rows): array {
    if (!is_array($rows) || count($rows) > 20) throw new RuntimeException('Invalid pruning rules.');
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Invalid pruning row.');
        $keep = filter_var($row['keep'] ?? null, FILTER_VALIDATE_INT);
        $period = filter_var($row['period'] ?? null, FILTER_VALIDATE_INT);
        $unit = (string) ($row['unit'] ?? '');
        $span = (string) ($row['span'] ?? '');
        if ($keep === false || $keep < 0 || $keep > 100 || $period === false || $period < 1 || $period > 100
            || !in_array($unit, ['day','week','month','six_months','year'], true)
            || !in_array($span, ['day','week','month','year'], true)) {
            throw new RuntimeException('Invalid pruning rule. Check counts and time periods.');
        }
        $out[] = compact('keep', 'unit', 'period', 'span');
    }
    return $out;
}

function bm_pruning_bucket(DateTimeImmutable $date, string $unit): string {
    if ($unit === 'day') return $date->format('Y-m-d');
    if ($unit === 'week') return $date->format('o-W');
    if ($unit === 'month') return $date->format('Y-m');
    if ($unit === 'six_months') return $date->format('Y') . '-' . ($date->format('n') <= 6 ? 'H1' : 'H2');
    return $date->format('Y');
}

function bm_pruning_window(DateTimeImmutable $now, DateTimeImmutable $date, string $span): int {
    if ($span === 'day') return (int) $now->diff($date)->days;
    if ($span === 'week') {
        $a = $now->modify('monday this week');
        $b = $date->modify('monday this week');
        return (int) floor(($a->getTimestamp() - $b->getTimestamp()) / 604800);
    }
    if ($span === 'month') return ((int)$now->format('Y') - (int)$date->format('Y')) * 12 + (int)$now->format('n') - (int)$date->format('n');
    return (int)$now->format('Y') - (int)$date->format('Y');
}

// Rule windows are consecutive: first current day, then previous seven days, etc.
function bm_pruning_plan(string $mount, array $rules): array {
    $rules = bm_pruning_validate($rules);
    $items = snapshots($mount);
    usort($items, static function ($a, $b) { return strcmp($a['name'], $b['name']); });
    $now = new DateTimeImmutable('today');
    $keep = [];
    $groups = [];
    $offsets = ['day'=>0,'week'=>0,'month'=>0,'year'=>0];
    foreach ($items as $item) {
        $date = DateTimeImmutable::createFromFormat('!Ymd_His', substr($item['name'], 7));
        if (!$date || $date > new DateTimeImmutable()) continue;
        foreach ($rules as $i=>$rule) {
            $age = bm_pruning_window($now, $date, $rule['span']);
            $start = 0;
            for ($j=0;$j<$i;$j++) {
                if ($rules[$j]['span'] === $rule['span']) $start += $rules[$j]['period'];
            }
            // Earlier rows take precedence; their windows are tested first.
            if ($age < $start || $age >= $start + $rule['period']) continue;
            $bucket = bm_pruning_bucket($date, $rule['unit']);
            $groups[$i][$bucket][] = $item['name'];
            break;
        }
    }
    foreach ($groups as $i=>$buckets) {
        foreach ($buckets as $names) {
            foreach (array_slice($names, 0, $rules[$i]['keep'] === 0 ? count($names) : $rules[$i]['keep']) as $name) $keep[$name] = true;
        }
    }
    // Protect the newest successful snapshot and the current incremental base.
    $latest = null;
    foreach ($items as $item) {
        if (bm_snapshot_status($mount, $item['name']) === 'Complete') $latest = $item['name'];
    }
    if ($latest !== null) $keep[$latest] = true;
    $current = $mount . '/backup/current';
    if (is_link($current)) {
        $name = basename((string) realpath($current));
        $keep[$name] = true;
    }
    $running = $mount . '/backup/running';
    if (is_link($running)) $keep[basename((string) realpath($running))] = true;
    $delete = [];
    foreach ($items as $item) {
        $name = $item['name'];
        if (isset($keep[$name])) continue;
        if (bm_snapshot_status($mount, $name) !== 'Complete') continue;
        $delete[] = $name;
    }
    return $delete;
}

function bm_pruning_apply(string $mount, array $names): int {
    if (bm_backup_running($mount) || is_link($mount . '/backup/running')) {
        throw new RuntimeException('Cannot prune while a backup is running.');
    }
    $count = 0;
    foreach ($names as $name) {
        bm_delete_snapshot($mount, $name);
        $count++;
    }
    return $count;
}
