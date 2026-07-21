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
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        return ['ok'=>false,'error'=>'The update repository is invalid.'];
    }
    $url = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref);
    [$ok,$body] = http_get($url,30);
    if (!$ok || $body === '') return ['ok'=>false,'error'=>'Could not reach GitHub (rate limit or network?).'];
    $json = json_decode($body,true);
    if (!is_array($json) || !preg_match('/^[a-f0-9]{40}$/i',(string)($json['sha']??''))) return ['ok'=>false,'error'=>'Unexpected response from GitHub.'];
    return ['ok'=>true,'sha'=>$json['sha'],'message'=>$json['commit']['message']??'','date'=>$json['commit']['committer']['date']??($json['commit']['author']['date']??''),'author'=>$json['commit']['author']['name']??''];
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
    foreach(preg_split('/\r?\n/',$listing) as $entry){if($entry===''||str_starts_with($entry,'/')||preg_match('#(^|/)\.\.(/|$)#',$entry)){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Downloaded archive contains an unsafe path.','log'=>$log];}}

    $add('Extracting and validating…');
    [$extractCode,$extractOutput]=run_cmd('tar -xzf '.escapeshellarg($tar).' -C '.escapeshellarg($work).' 2>&1',120);
    if($extractCode!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Extract failed: '.trim($extractOutput),'log'=>$log];}
    $src=su_find_panel_dir($work);
    if($src===null){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Downloaded archive has no panel directory.','log'=>$log];}
    foreach(['index.php','lib/bootstrap.php','lib/auth.php','lib/helpers.php','bin/nebula-helper'] as $required){if(!is_file($src.'/'.$required)){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Staged release is incomplete (missing '.$required.').','log'=>$log];}}
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){if($file->getExtension()!=='php')continue;[$code,,$error]=run_cmd(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()),15);if($code!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>'Staged PHP validation failed: '.trim($error),'log'=>$log];}}

    $add('Creating a required snapshot and applying the validated release…');
    [$applyCode,$applyOutput]=helper_cmd('panel-update '.escapeshellarg($src),300);
    if($applyCode!==0){run_cmd('rm -rf '.escapeshellarg($work));return ['ok'=>false,'error'=>trim($applyOutput)?:'Privileged update apply failed. Re-run install.sh to refresh the helper.','log'=>$log];}
    su_write_version($sha,$ref,$archiveHash);run_cmd('rm -rf '.escapeshellarg($work));
    audit('selfupdate.apply',$repo.'@'.$ref.' -> '.substr($sha,0,12).' archive '.substr($archiveHash,0,12));
    $snapshot=preg_match('/snapshot=([^\s]+)/',$applyOutput,$match)?$match[1]:null;
    $add('Updated to '.substr($sha,0,12).'.');
    return ['ok'=>true,'log'=>$log,'new_sha'=>$sha,'snapshot'=>$snapshot,'archive_sha256'=>$archiveHash];
}

function su_find_panel_dir(string $root): ?string
{
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){if($file->getFilename()==='bootstrap.php'&&basename(dirname($file->getPathname()))==='lib'){$dir=dirname(dirname($file->getPathname()));if(is_file($dir.'/index.php'))return $dir;}}
    return null;
}
