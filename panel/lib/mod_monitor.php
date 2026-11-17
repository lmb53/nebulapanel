<?php
/**
 * Monitoring module — process introspection via `ps`.
 * Degrades gracefully (returns []/0) when ps is unavailable.
 */

/**
 * Top processes sorted by CPU usage.
 * @return array<int,array{pid:int,user:string,cpu:float,mem:float,rss:int,command:string}>
 */
function top_processes(int $limit = 20): array
{
    // Cached briefly: the dashboard polls this and sampling takes ~0.4 s.
    return (array) cache_remember('top-processes-' . $limit, 3, static fn() => top_processes_sample($limit));
}

/**
 * Current CPU use per process, measured over a short interval from
 * /proc/<pid>/stat (like top). `ps pcpu` is the average over the whole
 * lifetime of a process, so a busy-but-old process looked idle and a
 * long-finished burst kept a process on top forever.
 */
function top_processes_sample(int $limit): array
{
    if (!is_dir('/proc/self')) {
        return [];
    }
    $read = static function (): array {
        $rows = [];
        foreach (scandir('/proc') ?: [] as $pid) {
            if (!ctype_digit($pid)) { continue; }
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) { continue; }
            $close = strrpos($stat, ')');
            if ($close === false) { continue; }
            $fields = explode(' ', substr($stat, $close + 2));
            // utime and stime are fields 14 and 15 (index 11, 12 after the name); rss is 24.
            $rows[(int) $pid] = [
                'ticks' => (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0),
                'rss_pages' => (int) ($fields[21] ?? 0),
                'comm' => substr($stat, strpos($stat, '(') + 1, $close - strpos($stat, '(') - 1),
            ];
        }
        return $rows;
    };
    $first = $read();
    $started = microtime(true);
    usleep(400000);
    $second = $read();
    $elapsed = max(0.05, microtime(true) - $started);
    $hz = 100; // USER_HZ on Linux
    $page = 4096;
    $memTotal = (int) ((mem_info()['total'] ?? 0));
    $rows = [];
    foreach ($second as $pid => $now) {
        $before = $first[$pid]['ticks'] ?? $now['ticks'];
        $cpu = max(0, ($now['ticks'] - $before) / $hz / $elapsed * 100);
        $rss = $now['rss_pages'] * $page;
        $owner = @fileowner('/proc/' . $pid);
        $user = (string) $owner;
        if ($owner !== false && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($owner);
            if (is_array($info)) { $user = (string) $info['name']; }
        }
        $cmdline = str_replace("\0", ' ', (string) @file_get_contents('/proc/' . $pid . '/cmdline'));
        $rows[] = [
            'pid' => $pid,
            'user' => $user,
            'cpu' => round($cpu, 1),
            'mem' => $memTotal > 0 ? round($rss / $memTotal * 100, 1) : 0.0,
            'rss' => $rss,
            'command' => trim($cmdline) !== '' ? process_display_name($cmdline) : $now['comm'],
        ];
    }
    usort($rows, static fn($a, $b) => [$b['cpu'], $b['rss']] <=> [$a['cpu'], $a['rss']]);
    return array_slice($rows, 0, $limit);
}

function process_count(): int
{
    $count = 0;
    foreach (scandir('/proc') ?: [] as $entry) {
        if (ctype_digit($entry)) { $count++; }
    }
    return $count;
}

/** A short program name from a full command line ("/usr/sbin/nginx -g …" → "nginx"). */
function process_display_name(string $args): string
{
    $args = trim($args);
    if ($args === '') { return '?'; }
    if ($args[0] === '[') { return trim($args, '[]'); } // kernel thread
    $first = strtok($args, ' ');
    $name = basename((string) $first);
    $name = rtrim($name, ':');
    return $name !== '' ? $name : $args;
}

