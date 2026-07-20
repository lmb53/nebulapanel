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
    if (!has_cmd('ps')) {
        return [];
    }
    [$code, $out] = run_cmd('ps -eo pid,user,pcpu,pmem,rss,comm --sort=-pcpu');
    if ($code !== 0 || $out === '') {
        return [];
    }
    $lines = preg_split('/\r?\n/', trim($out));
    array_shift($lines); // drop header line
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $p = preg_split('/\s+/', $line, 6);
        if (count($p) < 6) {
            continue;
        }
        $rows[] = [
            'pid'     => (int) $p[0],
            'user'    => $p[1],
            'cpu'     => (float) $p[2],
            'mem'     => (float) $p[3],
            'rss'     => (int) $p[4] * 1024, // ps reports RSS in KB
            'command' => $p[5],
        ];
        if (count($rows) >= $limit) {
            break;
        }
    }
    return $rows;
}

/** Total number of processes (0 if unavailable). */
function process_count(): int
{
    if (!has_cmd('ps')) {
        return 0;
    }
    [$code, $out] = run_cmd('ps -e --no-headers');
    if ($code !== 0 || trim($out) === '') {
        return 0;
    }
    return count(preg_split('/\r?\n/', trim($out)));
}
