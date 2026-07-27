<?php
/**
 * Docker module — lists containers/images and performs container/image
 * actions through the validating root helper. Docker remains administrator-only
 * because control of its daemon is root-equivalent.
 */

function dk_available(): bool
{
    return has_cmd('docker');
}

/** Validate a docker id / name / image ref. */
function dk_id_ok(string $s): bool
{
    return (bool) preg_match('#^[A-Za-z0-9_.:/-]{1,128}$#', $s);
}

function dk_pinned_image_ok(string $image): bool
{
    if ($image === '' || strlen($image) > 255 || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/@:-]+$#', $image) !== 1) return false;
    if (preg_match('/@sha256:[a-f0-9]{64}$/', $image)) return true;
    $lastSlash = strrpos($image, '/');
    $tag = strrpos($image, ':');
    return $tag !== false && ($lastSlash === false || $tag > $lastSlash)
        && strtolower(substr($image, $tag + 1)) !== 'latest';
}

/** List all containers. */
function dk_containers(): array
{
    [$code, $out] = helper_cmd('docker-query containers');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code), 'containers' => []];
    }
    $containers = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') {
            continue;
        }
        $j = json_decode($line, true);
        if (!is_array($j)) {
            continue;
        }
        $containers[] = [
            'id'     => $j['ID'] ?? '',
            'name'   => $j['Names'] ?? '',
            'image'  => $j['Image'] ?? '',
            'status' => $j['Status'] ?? '',
            'state'  => $j['State'] ?? '',
            'ports'  => dk_parse_ports((string) ($j['Ports'] ?? '')),
        ];
    }
    return ['ok' => true, 'containers' => $containers];
}

/**
 * Turn docker's Ports column into a compact, de-duplicated list.
 *
 * Docker prints one entry per published address, so a single mapping shows up
 * twice on dual-stack hosts ("0.0.0.0:8080->80/tcp, :::8080->80/tcp"); both
 * collapse to one "8080 → 80/tcp". Entries without an arrow are exposed-only
 * ports, which are kept (flagged) so a container with no published port still
 * says what it listens on.
 */
function dk_parse_ports(string $raw): array
{
    $out = [];
    foreach (preg_split('/\s*,\s*/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
        if (strpos($entry, '->') !== false) {
            [$host, $container] = explode('->', $entry, 2);
            $colon = strrpos($host, ':');
            $hostPort = $colon === false ? trim($host) : trim(substr($host, $colon + 1));
            $key = $hostPort . '->' . $container;
            $out[$key] = ['host' => $hostPort, 'container' => trim($container), 'published' => true];
        } else {
            $out[$entry] = ['host' => '', 'container' => trim($entry), 'published' => false];
        }
    }
    return array_values($out);
}

/** List images. */
function dk_images(): array
{
    [$code, $out] = helper_cmd('docker-query images');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code), 'images' => []];
    }
    $images = [];
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if ($line === '') {
            continue;
        }
        $j = json_decode($line, true);
        if (!is_array($j)) {
            continue;
        }
        $images[] = [
            'id'   => $j['ID'] ?? '',
            'repo' => $j['Repository'] ?? '',
            'tag'  => $j['Tag'] ?? '',
            'size' => $j['Size'] ?? '',
        ];
    }
    return ['ok' => true, 'images' => $images];
}

function dk_volumes(): array
{
    [$code,$out]=helper_cmd('docker-query volumes');
    if($code!==0)return ['ok'=>false,'error'=>sudo_error($out,$code),'volumes'=>[]];$items=[];
    foreach(preg_split('/\r?\n/',trim($out)) as $line){$j=json_decode($line,true);if(!is_array($j))continue;$items[]=['name'=>$j['Name']??'','driver'=>$j['Driver']??'','mountpoint'=>$j['Mountpoint']??''];}
    return ['ok'=>true,'volumes'=>$items];
}

function dk_networks(): array
{
    [$code,$out]=helper_cmd('docker-query networks');
    if($code!==0)return ['ok'=>false,'error'=>sudo_error($out,$code),'networks'=>[]];$items=[];
    foreach(preg_split('/\r?\n/',trim($out)) as $line){$j=json_decode($line,true);if(!is_array($j))continue;$items[]=['id'=>$j['ID']??'','name'=>$j['Name']??'','driver'=>$j['Driver']??'','scope'=>$j['Scope']??''];}
    return ['ok'=>true,'networks'=>$items];
}

function dk_version(): string
{
    [$code,$out]=helper_cmd('docker-query version');return $code===0?trim($out):'';
}

function dk_container_create(array $input): array
{
    $name=trim((string)($input['name']??''));$image=trim((string)($input['image']??''));$restart=(string)($input['restart']??'unless-stopped');
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/',$name))return ['ok'=>false,'error'=>'Invalid container name.'];
    if(!dk_pinned_image_ok($image))return ['ok'=>false,'error'=>'Use an image with an explicit non-latest tag or digest.'];
    if(!in_array($restart,['no','always','unless-stopped','on-failure'],true))return ['ok'=>false,'error'=>'Invalid restart policy.'];
    $ports = [];
    $envs = [];
    $volumes = [];
    foreach(preg_split('/[\s,]+/',trim((string)($input['ports']??'')),-1,PREG_SPLIT_NO_EMPTY) as $port){
        if(!preg_match('/^(?:(127\.0\.0\.1|\[::1\]):)?(\d{1,5}):(\d{1,5})(?:\/(tcp|udp))?$/',$port,$match)
            || (int)$match[2]<1 || (int)$match[2]>65535 || (int)$match[3]<1 || (int)$match[3]>65535)
            return ['ok'=>false,'error'=>'Invalid port mapping: '.$port];
        $ports[]=(($match[1]??'')!==''?$match[1]:'127.0.0.1').':'.$match[2].':'.$match[3].(!empty($match[4])?'/'.$match[4]:'');
    }
    foreach(preg_split('/\r?\n/',trim((string)($input['env']??'')),-1,PREG_SPLIT_NO_EMPTY) as $env){if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.{0,1024}$/',$env))return ['ok'=>false,'error'=>'Invalid environment entry.'];$envs[]=$env;}
    foreach(preg_split('/\r?\n/',trim((string)($input['volumes']??'')),-1,PREG_SPLIT_NO_EMPTY) as $volume){if(!preg_match('#^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}:/[A-Za-z0-9_./-]+(?::ro|:rw)?$#',$volume))return ['ok'=>false,'error'=>'Only named-volume mounts are allowed.'];$volumes[]=$volume;}
    $cmd='docker-run '.escapeshellarg($name).' '.escapeshellarg($image).' '.escapeshellarg($restart)
        .' '.escapeshellarg(base64_encode(implode("\n",$ports)))
        .' '.escapeshellarg(base64_encode(implode("\n",$envs)))
        .' '.escapeshellarg(base64_encode(implode("\n",$volumes)));
    [$code,$out]=helper_cmd($cmd,180);audit('docker.create',$name.' from '.$image.' (exit '.$code.')');return $code===0?['ok'=>true,'id'=>trim($out)]:['ok'=>false,'error'=>sudo_error($out,$code)];
}

function dk_image_pull(string $image): array
{
    if(!dk_pinned_image_ok($image))return ['ok'=>false,'error'=>'Use an image with an explicit non-latest tag or digest.'];[$code,$out]=helper_cmd('docker-pull '.escapeshellarg($image),300);audit('docker.pull',$image.' (exit '.$code.')');return $code===0?['ok'=>true]:['ok'=>false,'error'=>sudo_error($out,$code)];
}
function dk_named_resource(string $kind,string $op,string $name): array
{
    if(!in_array($kind,['volume','network'],true)||!in_array($op,['create','rm'],true)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/',$name))return ['ok'=>false,'error'=>'Invalid Docker resource.'];
    if($kind==='network'&&$op==='rm'&&in_array($name,['bridge','host','none'],true))return ['ok'=>false,'error'=>'Built-in networks cannot be removed.'];
    [$code,$out]=helper_cmd('docker-resource '.escapeshellarg($kind).' '.escapeshellarg($op).' '.escapeshellarg($name));audit('docker.'.$kind.'.'.$op,$name.' (exit '.$code.')');return $code===0?['ok'=>true]:['ok'=>false,'error'=>sudo_error($out,$code)];
}

/** Perform a container action: start|stop|restart|remove. */
function dk_container_action(string $id, string $action): array
{
    if (!dk_id_ok($id)) {
        return ['ok' => false, 'error' => 'Invalid container id.'];
    }
    if (in_array($action, ['start', 'stop', 'restart'], true)) {
        $cmd = 'docker-container ' . $action . ' ' . escapeshellarg($id);
    } elseif ($action === 'remove') {
        $cmd = 'docker-container remove ' . escapeshellarg($id);
    } else {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    [$code, $out] = helper_cmd($cmd);
    audit('docker.' . $action, $id . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true];
}

/** Remove an image. */
function dk_image_remove(string $id): array
{
    if (!dk_id_ok($id)) {
        return ['ok' => false, 'error' => 'Invalid image id.'];
    }
    [$code, $out] = helper_cmd('docker-image-remove ' . escapeshellarg($id));
    audit('docker.rmi', $id . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true];
}

/** Reclaim disk by pruning dangling (untagged) images. Never touches tagged images. */
function dk_image_prune(): array
{
    [$code, $out] = helper_cmd('docker-image-prune', 120);
    audit('docker.prune', 'dangling images (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    // `docker image prune` prints a "Total reclaimed space: …" summary line.
    $reclaimed = '';
    if (preg_match('/Total reclaimed space:\s*(.+)$/mi', $out, $m)) {
        $reclaimed = trim($m[1]);
    }
    return ['ok' => true, 'reclaimed' => $reclaimed];
}

/** Tail a single container's combined stdout/stderr logs. */
function dk_container_logs(string $id, int $lines = 200): array
{
    if (!dk_id_ok($id)) {
        return ['ok' => false, 'error' => 'Invalid container id.'];
    }
    $lines = max(1, min(2000, $lines));
    [$code, $out] = helper_cmd('docker-logs ' . escapeshellarg($id) . ' ' . $lines, 60);
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'logs' => $out];
}
