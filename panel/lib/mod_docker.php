<?php
/**
 * Docker module — lists containers/images and performs container/image
 * actions via `sudo docker`. Requires a sudoers rule allowing `sudo docker`.
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

/** List all containers. */
function dk_containers(): array
{
    [$code, $out] = sudo_cmd('docker ps -a --format ' . escapeshellarg('{{json .}}'));
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
        ];
    }
    return ['ok' => true, 'containers' => $containers];
}

/** List images. */
function dk_images(): array
{
    [$code, $out] = sudo_cmd('docker images --format ' . escapeshellarg('{{json .}}'));
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
    [$code,$out]=sudo_cmd('docker volume ls --format '.escapeshellarg('{{json .}}'));
    if($code!==0)return ['ok'=>false,'error'=>sudo_error($out,$code),'volumes'=>[]];$items=[];
    foreach(preg_split('/\r?\n/',trim($out)) as $line){$j=json_decode($line,true);if(!is_array($j))continue;$items[]=['name'=>$j['Name']??'','driver'=>$j['Driver']??'','mountpoint'=>$j['Mountpoint']??''];}
    return ['ok'=>true,'volumes'=>$items];
}

function dk_networks(): array
{
    [$code,$out]=sudo_cmd('docker network ls --format '.escapeshellarg('{{json .}}'));
    if($code!==0)return ['ok'=>false,'error'=>sudo_error($out,$code),'networks'=>[]];$items=[];
    foreach(preg_split('/\r?\n/',trim($out)) as $line){$j=json_decode($line,true);if(!is_array($j))continue;$items[]=['id'=>$j['ID']??'','name'=>$j['Name']??'','driver'=>$j['Driver']??'','scope'=>$j['Scope']??''];}
    return ['ok'=>true,'networks'=>$items];
}

function dk_version(): string
{
    [$code,$out]=sudo_cmd('docker version --format '.escapeshellarg('{{.Server.Version}}'));return $code===0?trim($out):'';
}

function dk_container_create(array $input): array
{
    $name=trim((string)($input['name']??''));$image=trim((string)($input['image']??''));$restart=(string)($input['restart']??'unless-stopped');
    if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/',$name))return ['ok'=>false,'error'=>'Invalid container name.'];
    if(!dk_id_ok($image))return ['ok'=>false,'error'=>'Invalid image reference.'];
    if(!in_array($restart,['no','always','unless-stopped','on-failure'],true))return ['ok'=>false,'error'=>'Invalid restart policy.'];
    $args=['docker','run','-d','--name',$name,'--restart',$restart];
    foreach(preg_split('/[\s,]+/',trim((string)($input['ports']??'')),-1,PREG_SPLIT_NO_EMPTY) as $port){if(!preg_match('/^(?:[0-9.]+:)?\d{1,5}:\d{1,5}(?:\/(tcp|udp))?$/',$port))return ['ok'=>false,'error'=>'Invalid port mapping: '.$port];$args[]='-p';$args[]=$port;}
    foreach(preg_split('/\r?\n/',trim((string)($input['env']??'')),-1,PREG_SPLIT_NO_EMPTY) as $env){if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.{0,1024}$/',$env))return ['ok'=>false,'error'=>'Invalid environment entry.'];$args[]='-e';$args[]=$env;}
    foreach(preg_split('/\r?\n/',trim((string)($input['volumes']??'')),-1,PREG_SPLIT_NO_EMPTY) as $volume){if(!preg_match('#^[A-Za-z0-9_.:/-]+(?::ro|:rw)?$#',$volume))return ['ok'=>false,'error'=>'Invalid volume mount.'];$args[]='-v';$args[]=$volume;}
    $args[]=$image;$cmd=implode(' ',array_map('escapeshellarg',$args));[$code,$out]=sudo_cmd($cmd,180);audit('docker.create',$name.' from '.$image.' (exit '.$code.')');return $code===0?['ok'=>true,'id'=>trim($out)]:['ok'=>false,'error'=>sudo_error($out,$code)];
}

function dk_image_pull(string $image): array
{
    if(!dk_id_ok($image))return ['ok'=>false,'error'=>'Invalid image reference.'];[$code,$out]=sudo_cmd('docker pull '.escapeshellarg($image),300);audit('docker.pull',$image.' (exit '.$code.')');return $code===0?['ok'=>true]:['ok'=>false,'error'=>sudo_error($out,$code)];
}
function dk_named_resource(string $kind,string $op,string $name): array
{
    if(!in_array($kind,['volume','network'],true)||!in_array($op,['create','rm'],true)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/',$name))return ['ok'=>false,'error'=>'Invalid Docker resource.'];
    if($kind==='network'&&$op==='rm'&&in_array($name,['bridge','host','none'],true))return ['ok'=>false,'error'=>'Built-in networks cannot be removed.'];
    [$code,$out]=sudo_cmd('docker '.$kind.' '.$op.' '.escapeshellarg($name));audit('docker.'.$kind.'.'.$op,$name.' (exit '.$code.')');return $code===0?['ok'=>true]:['ok'=>false,'error'=>sudo_error($out,$code)];
}

/** Perform a container action: start|stop|restart|remove. */
function dk_container_action(string $id, string $action): array
{
    if (!dk_id_ok($id)) {
        return ['ok' => false, 'error' => 'Invalid container id.'];
    }
    if (in_array($action, ['start', 'stop', 'restart'], true)) {
        $cmd = 'docker ' . $action . ' ' . escapeshellarg($id);
    } elseif ($action === 'remove') {
        $cmd = 'docker rm -f ' . escapeshellarg($id);
    } else {
        return ['ok' => false, 'error' => 'Invalid action.'];
    }
    [$code, $out] = sudo_cmd($cmd);
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
    [$code, $out] = sudo_cmd('docker rmi ' . escapeshellarg($id));
    audit('docker.rmi', $id . ' (exit ' . $code . ')');
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true];
}

/** Reclaim disk by pruning dangling (untagged) images. Never touches tagged images. */
function dk_image_prune(): array
{
    [$code, $out] = sudo_cmd('docker image prune -f', 120);
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
    [$code, $out] = sudo_cmd('docker logs --tail ' . $lines . ' ' . escapeshellarg($id) . ' 2>&1', 60);
    if ($code !== 0) {
        return ['ok' => false, 'error' => sudo_error($out, $code)];
    }
    return ['ok' => true, 'logs' => $out];
}
