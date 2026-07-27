<?php
/** Validated, snapshot-first panel self-update support. */

function su_version_file(): string { return APP_ROOT . '/data/version.json'; }

function su_current(): ?array
{
    $value = @json_decode((string) @file_get_contents(su_version_file()), true);
    return is_array($value) && !empty($value['sha']) ? $value : null;
}

function su_write_version(string $sha, string $ref, string $archiveHash = ''): void
{
    write_json_file(su_version_file(), [
        'sha'=>$sha, 'ref'=>$ref, 'applied_at'=>date('c'), 'archive_sha256'=>$archiveHash,
    ]);
}

function su_remote_latest(): array
{
    global $config;
    $repo = (string) ($config['repo'] ?? '');
    $ref = (string) ($config['repo_ref'] ?? 'main');
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)
        || !preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $ref)
        || str_contains($ref, '..')) {
        return ['ok'=>false,'error'=>'The update repository is invalid.'];
    }
    $sha = preg_match('/^[a-f0-9]{40}$/i', $ref) ? strtolower($ref) : '';
    if ($sha === '' && has_cmd('git')) {
        $remote = 'https://github.com/' . $repo . '.git';
        foreach (['refs/heads/' . $ref, 'refs/tags/' . $ref . '^{}', 'refs/tags/' . $ref] as $remoteRef) {
            [$code, $output] = run_cmd('git ls-remote ' . escapeshellarg($remote) . ' ' . escapeshellarg($remoteRef), 30);
            if ($code === 0 && preg_match('/^([a-f0-9]{40})\s/im', $output, $match)) {
                $sha = strtolower($match[1]);
                break;
            }
        }
    }
    // API fallback supports minimal hosts without Git. A timestamp avoids the
    // stale branch-head cache that can point codeload at an orphaned commit.
    if ($sha === '') {
        $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref) . '?nebula=' . time();
        [$ok, $body] = http_get($url, 30);
        $json = $ok ? json_decode($body, true) : null;
        $sha = is_array($json) ? strtolower((string) ($json['sha'] ?? '')) : '';
    }
    if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
        return ['ok'=>false,'error'=>'Could not resolve the latest GitHub commit. Check outbound HTTPS and Git access.'];
    }
    $message = $date = $author = '';
    [$metaOk, $metaBody] = http_get('https://api.github.com/repos/' . $repo . '/commits/' . $sha, 20);
    $meta = $metaOk ? json_decode($metaBody, true) : null;
    if (is_array($meta)) {
        $message = (string) ($meta['commit']['message'] ?? '');
        $date = (string) ($meta['commit']['committer']['date'] ?? ($meta['commit']['author']['date'] ?? ''));
        $author = (string) ($meta['commit']['author']['name'] ?? '');
    }
    return ['ok'=>true,'sha'=>$sha,'message'=>$message,'date'=>$date,'author'=>$author];
}

function su_check(): array
{
    global $config;
    $current=su_current();$remote=su_remote_latest();if(empty($remote['ok']))return $remote;
    $currentSha=$current['sha']??null;
    return ['ok'=>true,'repo'=>$config['repo']??'','ref'=>$config['repo_ref']??'main','current_sha'=>$currentSha,'latest_sha'=>$remote['sha'],'update_available'=>$currentSha===null||$currentSha!==$remote['sha'],'known'=>$currentSha!==null,'message'=>$remote['message'],'date'=>$remote['date'],'author'=>$remote['author']];
}

function su_apply(): array
{
    global $config;
    $log=[];$add=static function(string $message)use(&$log):void{$log[]=$message;};
    $repo=(string)($config['repo']??'');$ref=(string)($config['repo_ref']??'main');
    if(!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#',$repo))return ['ok'=>false,'error'=>'The update repository is invalid.','log'=>$log];
    $remote=su_remote_latest();$sha=(string)($remote['sha']??'');
    if(empty($remote['ok'])||!preg_match('/^[a-f0-9]{40}$/i',$sha))return ['ok'=>false,'error'=>$remote['error']??'Could not resolve the update commit.','log'=>$log];

    $work=APP_ROOT.'/data/_update';run_cmd('rm -rf '.escapeshellarg($work));
    if(!@mkdir($work,0700,true))return ['ok'=>false,'error'=>'Could not create work directory.','log'=>$log];
    $tar=$work.'/src.tar.gz';$url='https://codeload.github.com/'.$repo.'/tar.gz/'.rawurlencode($sha);
    $add('Downloading immutable commit '.substr($sha,0,12).'…');
    if(!http_download($url,$tar,300)){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Download failed.','log'=>$log];}
    $archiveHash=hash_file('sha256',$tar)?:'';

    [$listCode,$listing,$listError]=run_cmd('tar -tzf '.escapeshellarg($tar),120);
    if($listCode!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Downloaded archive is invalid: '.trim($listError?:$listing),'log'=>$log];}
    $topLevels=[];
    foreach(preg_split('/\r?\n/',$listing) as $entry){
        if($entry===''||str_starts_with($entry,'/')||preg_match('#(^|/)\.\.(/|$)#',$entry)){
            run_cmd('rm -rf '.escapeshellarg($work));
            return ['ok'=>false,'error'=>'Downloaded archive contains an unsafe path.','log'=>$log];
        }
        $topLevels[explode('/',$entry,2)[0]]=true;
    }
    if(count($topLevels)!==1){
        run_cmd('rm -rf '.escapeshellarg($work));
        return ['ok'=>false,'error'=>'Release archive must contain exactly one top-level directory.','log'=>$log];
    }
    [$typesCode,$types]=run_cmd('tar -tvzf '.escapeshellarg($tar),120);
    if($typesCode!==0||preg_match('/^[^d-]/m',$types)){
        run_cmd('rm -rf '.escapeshellarg($work));
        return ['ok'=>false,'error'=>'Release archive contains links or special files.','log'=>$log];
    }

    $add('Extracting and validating…');
    [$extractCode,$extractOutput]=run_cmd('tar -xzf '.escapeshellarg($tar).' -C '.escapeshellarg($work).' 2>&1',120);
    if($extractCode!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Extract failed: '.trim($extractOutput),'log'=>$log];}
    $src=su_find_panel_dir($work);
    if($src===null){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Downloaded archive has no panel directory.','log'=>$log];}
    foreach(['index.php','lib/bootstrap.php','lib/auth.php','lib/helpers.php','bin/nebula-helper'] as $required){if(!is_file($src.'/'.$required)){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Staged release is incomplete (missing '.$required.').','log'=>$log];}}
    $phpCli=su_php_cli();
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){if($file->getExtension()!=='php')continue;[$code,$stdout,$error]=run_cmd(escapeshellarg($phpCli).' -l '.escapeshellarg($file->getPathname()),15);if($code!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Staged PHP validation failed: '.(trim($error)?:trim($stdout)?:'lint of '.$file->getFilename().' exited '.$code),'log'=>$log];}}

    $add('Creating a required snapshot and applying the validated release…');
    [$applyCode,$applyOutput]=helper_cmd('panel-update '.escapeshellarg($src),300);
    if($applyCode!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>trim($applyOutput)?:'Privileged update apply failed. Re-run install.sh to refresh the helper.','log'=>$log];}
    su_write_version($sha,$ref,$archiveHash);run_cmd('rm -rf '.escapeshellarg($work));
    audit('selfupdate.apply',$repo.'@'.$ref.' -> '.substr($sha,0,12).' archive '.substr($archiveHash,0,12));
    $snapshot=preg_match('/snapshot=([^\s]+)/',$applyOutput,$match)?$match[1]:null;
    $add('Updated to '.substr($sha,0,12).'.');
    return ['ok'=>true,'log'=>$log,'new_sha'=>$sha,'snapshot'=>$snapshot,'archive_sha256'=>$archiveHash];
}

/**
 * A PHP *CLI* binary usable for `-l` lint checks. Under PHP-FPM, PHP_BINARY is
 * the php-fpm daemon, which does not support `-l` — using it makes every staged
 * validation fail (often with an empty error). Prefer a real `php` CLI, falling
 * back to a versioned CLI, then to PHP_BINARY only when it isn't the FPM SAPI.
 */
function su_php_cli(): string
{
    foreach (['php', 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'php8.5', 'php8.4', 'php8.3', 'php8.2', 'php8.1'] as $candidate) {
        if (has_cmd($candidate)) {
            return $candidate;
        }
    }
    if (PHP_BINARY !== '' && stripos(PHP_BINARY, 'fpm') === false && stripos(PHP_SAPI, 'fpm') === false) {
        return PHP_BINARY;
    }
    return 'php';
}

function su_find_panel_dir(string $root): ?string
{
    $entries=array_values(array_filter(scandir($root)?:[],fn($entry)=>$entry!=='.'&&$entry!=='..'&&$entry!=='src.tar.gz'));
    if(count($entries)!==1)return null;
    $top=$root.'/'.$entries[0];
    $candidates=[$top.'/panel',$top];
    $matches=array_values(array_filter($candidates,fn($dir)=>is_file($dir.'/index.php')&&is_file($dir.'/lib/bootstrap.php')));
    return count($matches)===1?$matches[0]:null;
}
