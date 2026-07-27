<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) {
    fwrite(STDERR, "Run this recovery command locally as root.\n");
    exit(1);
}

$root = dirname(__DIR__);
$data = $root . '/data';
$command = $argv[1] ?? '';
if (!is_dir($data)) {
    fwrite(STDERR, "Panel data directory is missing.\n");
    exit(1);
}

$atomic = static function (string $path, array $value): void {
    $tmp = tempnam(dirname($path), '.recovery-');
    if ($tmp === false || file_put_contents($tmp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not stage recovery state.');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace recovery state.');
    }
};

try {
    if ($command === 'issue-bootstrap') {
        $token = bin2hex(random_bytes(32));
        $atomic($data . '/bootstrap.json', ['hash'=>hash('sha256',$token),'expires_at'=>time()+3600]);
        echo "Bootstrap token (single-use, one hour): $token\n";
        exit(0);
    }
    if ($command !== 'reset-admin') {
        fwrite(STDERR, "Usage: nebula-recovery reset-admin | issue-bootstrap\n");
        exit(2);
    }
    $username = trim((string) fgets(STDIN));
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) || strlen($password) < 12 || strlen($password) > 1024) {
        throw new RuntimeException('Username or password does not meet the panel baseline.');
    }
    $normal = strtolower(preg_replace('/[^a-z0-9]/i','',$password) ?? '');
    $userNormal = strtolower(preg_replace('/[^a-z0-9]/i','',$username) ?? '');
    if (in_array($normal,['password','password123','password1234','admin123456','administrator','letmein123456','qwerty123456','welcome123456','changeme1234','123456789012'],true)
        || (strlen($userNormal)>=3 && str_contains($normal,$userNormal))) {
        throw new RuntimeException('Password is in the local common-password policy or contains the username.');
    }
    $hash = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>4,'threads'=>2]);
    if ($hash === false) throw new RuntimeException('Password hashing failed.');
    $path = $data . '/panel-users.json';
    $stored = json_decode((string) @file_get_contents($path), true);
    $users = is_array($stored['users'] ?? null) ? $stored['users'] : [];
    $index = null;
    foreach ($users as $i=>$user) {
        if (($user['role']??'') === 'admin') { $index=$i; break; }
    }
    $record = $index === null ? ['id'=>1,'created'=>date('c')] : $users[$index];
    $record['username']=$username;$record['hash']=$hash;$record['role']='admin';$record['enabled']=true;
    $record['session_version']=max(1,(int)($record['session_version']??1))+1;$record['updated']=date('c');
    if ($index === null) $users[]=$record; else $users[$index]=$record;
    $atomic($path,['version'=>1,'users'=>array_values($users)]);
    $atomic($data.'/admin.json',['username'=>$username,'hash'=>$hash,'updated'=>date('c')]);
    foreach (glob($data.'/sessions/*') ?: [] as $session) {
        if (is_file($session) && !is_link($session)) @unlink($session);
    }
    echo "Administrator reset. Existing sessions were revoked.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
