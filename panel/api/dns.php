<?php
require APP_ROOT . '/lib/mod_dns.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post(); csrf_check(); $body=read_json_body(); $action=(string)($body['action']??''); $domain=(string)($body['domain']??'');
    if ($action==='add') $res=dns_record_add($domain,$body);
    elseif($action==='delete') $res=dns_record_delete($domain,(string)($body['id']??''));
    else $res=['ok'=>false,'error'=>'Unknown action.'];
    json_out($res,!empty($res['ok'])?200:400);
}
json_out(['ok'=>true,'nameservers'=>dns_nameservers(),'zones'=>dns_zones()]);
