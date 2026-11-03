<?php
/**
 * Resource history — a compact, fixed-retention record of CPU, memory, disk
 * and load samples, so "why was the site slow last night?" has an answer.
 *
 * Samples are written by the background scheduler every five minutes and,
 * as a fallback for installs without the timer, opportunistically by the
 * dashboard's metrics poll. Each row is [unix_ts, cpu%, mem%, disk%, load1].
 */

const HISTORY_INTERVAL = 300;
const HISTORY_RETENTION = 8 * 86400;

function history_file(): string
{
    return DATA_DIR . '/metrics-history.json';
}

function history_load(): array
{
    $rows = @json_decode((string) @file_get_contents(history_file()), true);
    if (!is_array($rows)) { return []; }
    return array_values(array_filter($rows, static fn($row) => is_array($row) && isset($row[0]) && is_numeric($row[0])));
}

/** Take a live sample. CPU is measured since the previous cached sample. */
function history_sample(): array
{
    $mem = mem_info();
    $disk = disk_info('/');
    $load = load_avg();
    return [
        'cpu' => cpu_usage(),
        'mem' => $mem ? round($mem['used'] / max(1, $mem['total']) * 100, 1) : null,
        'disk' => $disk ? round($disk['used'] / max(1, $disk['total']) * 100, 1) : null,
        'load' => isset($load[0]) ? round((float) $load[0], 2) : null,
    ];
}

/**
 * Append a sample unless one was recorded recently. $force records even when
 * the interval has not elapsed (but never twice within a minute).
 * Returns true when a row was written.
 */
function history_record(bool $force = false, ?array $sample = null, ?int $now = null): bool
{
    $now = $now ?? time();
    $path = history_file();
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) { fclose($lock); }
        return false;
    }
    try {
        $rows = history_load();
        $last = $rows ? (int) $rows[count($rows) - 1][0] : 0;
        $minGap = $force ? 60 : HISTORY_INTERVAL - 10;
        if ($last > 0 && $now - $last < $minGap) { return false; }
        $sample = $sample ?? history_sample();
        $rows[] = [$now, $sample['cpu'] ?? null, $sample['mem'] ?? null, $sample['disk'] ?? null, $sample['load'] ?? null];
        $cutoff = $now - HISTORY_RETENTION;
        $rows = array_values(array_filter($rows, static fn($row) => (int) $row[0] >= $cutoff));
        return write_json_file($path, $rows);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Supported chart ranges: seconds covered and bucket width. */
function history_ranges(): array
{
    return [
        '6h' => ['span' => 6 * 3600, 'bucket' => 300, 'label' => 'Last 6 hours'],
        '24h' => ['span' => 86400, 'bucket' => 600, 'label' => 'Last 24 hours'],
        '7d' => ['span' => 7 * 86400, 'bucket' => 3600, 'label' => 'Last 7 days'],
    ];
}

/**
 * Downsampled series for a range. Each point averages the samples in its
 * bucket and also carries the CPU peak, so short spikes are not averaged away.
 */
function history_series(string $range, ?int $now = null): array
{
    $ranges = history_ranges();
    if (!isset($ranges[$range])) { $range = '24h'; }
    $now = $now ?? time();
    $span = $ranges[$range]['span'];
    $bucket = $ranges[$range]['bucket'];
    $from = $now - $span;
    $buckets = [];
    foreach (history_load() as $row) {
        $ts = (int) $row[0];
        if ($ts < $from || $ts > $now + 60) { continue; }
        $key = intdiv($ts, $bucket) * $bucket;
        $b = &$buckets[$key];
        $b = $b ?? ['n' => [0, 0, 0, 0], 's' => [0.0, 0.0, 0.0, 0.0], 'peak' => null];
        for ($i = 0; $i < 4; $i++) {
            $value = $row[$i + 1] ?? null;
            if ($value === null || !is_numeric($value)) { continue; }
            $b['n'][$i]++;
            $b['s'][$i] += (float) $value;
        }
        if (isset($row[1]) && is_numeric($row[1])) { $b['peak'] = max((float) ($b['peak'] ?? 0), (float) $row[1]); }
        unset($b);
    }
    ksort($buckets);
    $points = [];
    foreach ($buckets as $ts => $b) {
        $avg = [];
        for ($i = 0; $i < 4; $i++) { $avg[$i] = $b['n'][$i] ? round($b['s'][$i] / $b['n'][$i], $i === 3 ? 2 : 1) : null; }
        $points[] = ['t' => $ts, 'cpu' => $avg[0], 'mem' => $avg[1], 'disk' => $avg[2], 'load' => $avg[3], 'cpu_peak' => $b['peak']];
    }
    $all = history_load();
    return [
        'ok' => true,
        'range' => $range,
        'label' => $ranges[$range]['label'],
        'from' => $from,
        'to' => $now,
        'bucket' => $bucket,
        'points' => $points,
        'oldest' => $all ? (int) $all[0][0] : null,
        'samples' => count($all),
    ];
}
