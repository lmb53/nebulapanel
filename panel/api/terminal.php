<?php
/** POST api/terminal {command} — run a non-interactive shell command (30s). */
require_post();
csrf_check();
require_capability('terminal.execute');

$body = read_json_body();
$cmd = trim((string) ($body['command'] ?? ''));
if ($cmd === '') {
    json_out(['ok' => false, 'error' => 'Empty command'], 400);
}

audit('terminal.exec', substr($cmd, 0, 300));
[$code, $out, $err] = run_cmd($cmd, 30);
json_out(['ok' => true, 'code' => $code, 'stdout' => $out, 'stderr' => $err]);
