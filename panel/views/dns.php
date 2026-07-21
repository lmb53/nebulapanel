<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_dns.php';
$sites=sites_list();$domains=array_values(array_filter(array_map(fn($s)=>(string)($s['domain']??''),$sites)));
$selected=(string)($_GET['domain']??($domains[0]??''));if(!in_array($selected,$domains,true))$selected=(string)($domains[0]??'');
$records=$selected!==''?dns_zone_records($selected):[];$nameservers=dns_nameservers();
$badge=['A'=>'badge-blue','AAAA'=>'badge-purple','CNAME'=>'badge-emerald','MX'=>'badge-orange','TXT'=>'badge-slate','NS'=>'badge-red','SRV'=>'badge-blue','CAA'=>'badge-orange'];
?>
<div class="page-header">
  <div><h1 class="page-title">DNS Manager</h1><p class="page-subtitle"><?= count($domains) ?> zone<?= count($domains)===1?'':'s' ?> · authoritative records served by Nebula Panel</p></div>
</div>
<div class="notice notice-warning" style="margin-bottom:16px"><i data-lucide="info"></i><div><strong>Delegate domains to your Nebula nameservers</strong><div>At your registrar, set nameservers to <span class="mono"><?= e(implode(', ',$nameservers)) ?></span>. Records below are then published by this server.</div></div></div>
<?php if(!$domains): ?><div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="network"></i></div><div style="font-weight:600">No DNS zones yet</div><div style="font-size:13px;margin-top:4px">Add a website first; its domain will become available here.</div></div></div><?php else: ?>
<div class="dns-layout">
  <aside class="card dns-zones"><div class="card-header"><h3>Zones</h3><span class="badge badge-slate"><?= count($domains) ?></span></div><div class="card-pad">
    <?php foreach($domains as $domain): ?><a class="dns-zone<?= $domain===$selected?' active':'' ?>" href="<?= e(url('dns',['domain'=>$domain])) ?>"><span class="flex items-center gap-2"><i data-lucide="globe"></i><span><?= e($domain) ?></span></span><span class="badge badge-emerald"><span class="bdot"></span></span></a><?php endforeach; ?>
  </div></aside>
  <div class="card">
    <div class="card-header"><div><h3><?= e($selected) ?> · DNS records</h3><span class="muted"><?= count($records) ?> custom records · NS and SOA are generated automatically</span></div></div>
    <div class="table-wrap"><table class="data-table dns-record-table"><thead><tr><th>Type</th><th>Name</th><th>Value / target</th><th>TTL</th><th>Priority</th><th></th></tr></thead><tbody>
      <?php foreach($records as $record): ?><tr><td><span class="badge <?= e($badge[$record['type']]??'badge-slate') ?>"><?= e($record['type']) ?></span></td><td class="mono"><?= e($record['name']) ?></td><td class="mono text-tertiary" style="max-width:420px;white-space:normal;word-break:break-all"><?= e($record['value']) ?></td><td class="mono"><?= (int)$record['ttl'] ?></td><td class="mono"><?= $record['type']==='MX'?(int)($record['priority']??10):'–' ?></td><td style="text-align:right"><button class="icon-btn" style="color:var(--red-400)" data-dns-delete="<?= e($record['id']) ?>" title="Delete record"><i data-lucide="trash-2"></i></button></td></tr><?php endforeach; ?>
      <?php if(!$records): ?><tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:28px">No custom records. Add the first record below.</td></tr><?php endif; ?>
    </tbody><tfoot><tr class="dns-add-row"><td><select class="select" id="dnsType"><?php foreach(['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'] as $type): ?><option><?= e($type) ?></option><?php endforeach; ?></select></td><td><input class="input mono" id="dnsName" value="@" placeholder="@ or www"></td><td><input class="input mono" id="dnsValue" placeholder="Value or target"></td><td><input class="input mono" id="dnsTtl" value="3600" type="number" min="60"></td><td><input class="input mono" id="dnsPriority" value="10" type="number" min="0"></td><td><button class="btn btn-primary btn-sm" id="dnsAdd"><i data-lucide="plus"></i>Add</button></td></tr></tfoot></table></div>
  </div>
</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula;const domain=<?= json_encode($selected) ?>;const type=document.getElementById('dnsType'),priority=document.getElementById('dnsPriority');const sync=()=>{priority.disabled=type?.value!=='MX';};type?.addEventListener('change',sync);sync();document.getElementById('dnsAdd')?.addEventListener('click',async()=>{const res=await apiPost('dns',{action:'add',domain,type:type.value,name:document.getElementById('dnsName').value,value:document.getElementById('dnsValue').value,ttl:+document.getElementById('dnsTtl').value,priority:+priority.value});if(res.ok){toast(res.published===false?(res.warning||'Record saved; DNS publishing is not configured'):'DNS record published',res.published===false?'warning':'success');setTimeout(()=>location.reload(),450);}else toast(res.error||'Could not add record','error');});document.querySelectorAll('[data-dns-delete]').forEach(btn=>btn.addEventListener('click',async()=>{if(!confirm('Delete this DNS record?'))return;const res=await apiPost('dns',{action:'delete',domain,id:btn.dataset.dnsDelete});if(res.ok){toast('DNS record deleted','success');setTimeout(()=>location.reload(),350);}else toast(res.error||'Could not delete record','error');}));});
</script>
