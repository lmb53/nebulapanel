<?php
require APP_ROOT . '/lib/files.php';
$abs=fm_resolve((string)($_GET['path']??''));
if($abs===null||!is_dir($abs))json_out(['ok'=>false,'error'=>'Folder not found.'],404);
$listing=fm_list($abs);$entries=[];
foreach($listing['dirs'] as $dir)$entries[]=['name'=>$dir['name'],'path'=>$dir['rel'],'dir'=>true,'href'=>url('files',['path'=>$dir['rel']])];
foreach($listing['files'] as $file)$entries[]=['name'=>$file['name'],'path'=>$file['rel'],'dir'=>false,'href'=>url('file-edit',['path'=>$file['rel']])];
json_out(['ok'=>true,'entries'=>$entries]);
