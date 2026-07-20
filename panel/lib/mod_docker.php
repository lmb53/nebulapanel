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
