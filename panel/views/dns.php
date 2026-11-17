<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_dns.php';
$sites=sites_list();$domains=array_values(array_filter(array_map(fn($s)=>(string)($s['domain']??''),$sites)));
$selected=(string)($_GET['domain']??($domains[0]??''));if(!in_array($selected,$domains,true))$selected=(string)($domains[0]??'');
$records=$selected!==''?dns_zone_records($selected):[];$nameservers=dns_nameservers();
$badge=['A'=>'badge-blue','AAAA'=>'badge-blue','CNAME'=>'badge-purple','MX'=>'badge-purple','TXT'=>'badge-slate','NS'=>'badge-slate','SRV'=>'badge-slate','CAA'=>'badge-slate'];
?>
<div class="page-header">
  <div><h1 class="page-title">DNS</h1><p class="page-subtitle"><?= count($domains) ?> zone<?= count($domains)===1?'':'s' ?> · authoritative records served by Nebula Panel</p></div>
</div>
<?php if($domains): ?><div class="notice notice-info" style="margin-bottom:16px"><i data-lucide="info"></i><div><strong>Delegate domains to your Nebula nameservers</strong><div>At your registrar, set the nameservers to <?php foreach($nameservers as $i=>$ns): ?><?= $i ? ' and ' : '' ?><span class="mono"><?= e($ns) ?></span><?php endforeach; ?>. Records below are then published by this server.</div></div></div><?php endif; ?>
<?php if(!$domains): ?><?= requirement_missing('network', 'No DNS zones yet', 'Each website’s domain gets a zone here automatically.', [['label' => 'Add website', 'href' => url('websites') . '#add', 'icon' => 'plus', 'primary' => true]]) ?><?php else: ?>
<div class="dns-layout">
  <aside class="card dns-zones"><div class="card-header"><h3>Zones</h3><span class="badge badge-slate"><?= count($domains) ?></span></div><div class="card-pad">
    <?php foreach($domains as $domain): ?><a class="dns-zone<?= $domain===$selected?' active':'' ?>"<?= $domain===$selected?' aria-current=page':'' ?> href="<?= e(url('dns',['domain'=>$domain])) ?>"><span class="flex items-center gap-2"><i data-lucide="globe"></i><span><?= e($domain) ?></span></span><i data-lucide="chevron-right" aria-hidden="true"></i></a><?php endforeach; ?>
  </div></aside>
  <div class="card">
    <div class="card-header"><div><h3><?= e($selected) ?> · DNS records</h3><span class="muted"><?= count($records) ?> custom record<?= count($records) === 1 ? '' : 's' ?> · NS and SOA are generated automatically</span></div>
      <div class="flex gap-2" style="flex-wrap:wrap">
        <input class="input input-sm table-filter" type="search" placeholder="Filter records…" aria-label="Filter records" data-table-filter=".dns-record-table">
        <select class="select input-sm" id="dnsTemplate" aria-label="Add records from a template" style="width:auto"><option value="">Add from template…</option><?php foreach (dns_templates($selected) as $key => $template): ?><option value="<?= e($key) ?>"><?= e($template['label']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-secondary btn-sm" type="button" id="dnsImportOpen"><i data-lucide="file-up"></i>Import zone file</button>
      </div></div>
    <div class="table-wrap"><table class="data-table dns-record-table"><thead><tr><th>Type</th><th>Name</th><th>Value / target</th><th class="num">TTL</th><th class="num">Priority</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
      <?php foreach($records as $record): ?><tr><td><span class="badge <?= e($badge[$record['type']]??'badge-slate') ?>"><?= e($record['type']) ?></span></td><td class="mono"><?= e($record['name']) ?></td><td class="mono text-tertiary" style="max-width:420px;white-space:normal;word-break:break-all"><?= e($record['value']) ?></td><td class="mono num"><?= (int)$record['ttl'] ?></td><td class="mono num"><?= $record['type']==='MX'?(int)($record['priority']??10):'—' ?></td><td class="actions-col"><button class="icon-btn" type="button" data-dns-edit="<?= e(js_json($record)) ?>" title="Edit record" aria-label="Edit <?= e($record['type'] . ' ' . $record['name']) ?>"><i data-lucide="pencil"></i></button><button class="icon-btn danger" type="button" data-dns-delete="<?= e($record['id']) ?>" title="Delete record" aria-label="Delete <?= e($record['type'] . ' ' . $record['name']) ?>"><i data-lucide="trash-2"></i></button></td></tr><?php endforeach; ?>
      <?php if(!$records): ?><tr class="empty-row"><td colspan="6">No custom records. Add the first record below.</td></tr><?php endif; ?>
    </tbody><tfoot><tr class="dns-add-row"><td><select class="select input-sm" id="dnsType" aria-label="Record type"><?php foreach(['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'] as $type): ?><option><?= e($type) ?></option><?php endforeach; ?></select></td><td><input class="input input-sm mono" id="dnsName" value="@" placeholder="@ or www" aria-label="Record name"></td><td><input class="input input-sm mono" id="dnsValue" placeholder="Value or target" aria-label="Record value"></td><td><input class="input input-sm mono" id="dnsTtl" value="3600" type="number" min="60" aria-label="TTL in seconds"></td><td><input class="input input-sm mono" id="dnsPriority" value="10" type="number" min="0" aria-label="MX priority"></td><td class="actions-col"><button class="btn btn-primary btn-sm" type="button" id="dnsAdd"><i data-lucide="plus"></i>Add</button><button class="btn btn-ghost btn-sm hidden" type="button" id="dnsCancelEdit">Cancel</button></td></tr></tfoot></table></div>
  </div>
</div>
<div class="drawer-overlay hidden" id="dnsImportDrawer"><div class="drawer"><div class="drawer-header"><div><strong>Import a zone file</strong><div class="muted">Paste records exported from your current DNS provider (BIND format)</div></div><button class="icon-btn" type="button" data-close-dns-import aria-label="Close" title="Close"><i data-lucide="x"></i></button></div>
  <div class="drawer-body"><label class="field-label" for="dnsImportText">Zone file</label><textarea class="input mono" id="dnsImportText" rows="16" spellcheck="false" placeholder="@ 3600 IN A 203.0.113.10&#10;www 3600 IN CNAME example.com.&#10;@ 3600 IN MX 10 mail.example.com."></textarea><div class="field-help">SOA and NS records are skipped (this server generates them). Records already in the zone are kept as they are.</div></div>
  <div class="drawer-footer"><button class="btn btn-secondary" type="button" data-close-dns-import>Cancel</button><button class="btn btn-primary" type="button" id="dnsImportRun"><i data-lucide="file-up"></i>Import</button></div></div></div>
<?php endif; ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, confirm: ask, setFieldError } = window.Nebula;
  const domain = <?= js_json($selected) ?>;
  const $ = (id) => document.getElementById(id);
  const type = $('dnsType'), priority = $('dnsPriority'), add = $('dnsAdd'), cancel = $('dnsCancelEdit');
  let editing = null;
  const sync = () => { if (type && priority) priority.disabled = type.value !== 'MX'; };
  type?.addEventListener('change', sync); sync();
  const published = (res, what) => toast(res.published === false ? (res.warning || 'Saved; DNS publishing is not configured') : what, res.published === false ? 'warning' : 'success');
  const reset = () => { editing = null; add.innerHTML = '<i data-lucide="plus"></i>Add'; cancel.classList.add('hidden'); $('dnsName').value = '@'; $('dnsValue').value = ''; $('dnsTtl').value = 3600; if (window.lucide) lucide.createIcons(); };
  cancel?.addEventListener('click', reset);
  document.querySelectorAll('[data-dns-edit]').forEach((btn) => btn.addEventListener('click', () => {
    const r = JSON.parse(btn.dataset.dnsEdit);
    editing = r.id; type.value = r.type; $('dnsName').value = r.name; $('dnsValue').value = r.value; $('dnsTtl').value = r.ttl; priority.value = r.priority ?? 10; sync();
    add.innerHTML = '<i data-lucide="save"></i>Save'; cancel.classList.remove('hidden'); if (window.lucide) lucide.createIcons();
    $('dnsValue').focus();
  }));
  add?.addEventListener('click', async () => {
    const valueInput = $('dnsValue');
    if (!valueInput.value.trim()) { setFieldError(valueInput, 'Enter a value.'); valueInput.focus(); return; }
    const body = { action: editing ? 'update' : 'add', domain, id: editing, type: type.value, name: $('dnsName').value, value: valueInput.value, ttl: +$('dnsTtl').value, priority: +priority.value };
    const res = await apiPost('dns', body);
    if (res.ok) { published(res, editing ? 'DNS record updated' : 'DNS record published'); setTimeout(() => location.reload(), 450); }
    else toast(res.error || 'Could not save the record', 'error');
  });
  document.querySelectorAll('[data-dns-delete]').forEach((btn) => btn.addEventListener('click', async () => {
    if (!await ask({ title: 'Delete this DNS record?', message: 'The change is published to the zone immediately.', danger: true })) return;
    const res = await apiPost('dns', { action: 'delete', domain, id: btn.dataset.dnsDelete });
    if (res.ok) { toast('DNS record deleted', 'success'); setTimeout(() => location.reload(), 350); } else toast(res.error || 'Could not delete record', 'error');
  }));
  $('dnsTemplate')?.addEventListener('change', async (event) => {
    const select = event.target, key = select.value;
    if (!key) return;
    const label = select.options[select.selectedIndex].textContent;
    select.value = '';
    if (!await ask({ title: 'Add “' + label + '” records?', message: 'Existing records are kept, except that mail templates replace the domain’s MX and SPF records.', confirmLabel: 'Add records', icon: 'network' })) return;
    const res = await apiPost('dns', { action: 'template', domain, template: key });
    if (res.ok) { published(res, (res.added || 0) + ' record' + (res.added === 1 ? '' : 's') + ' added'); setTimeout(() => location.reload(), 500); } else toast(res.error || 'Could not apply the template', 'error');
  });
  const drawer = $('dnsImportDrawer');
  $('dnsImportOpen')?.addEventListener('click', () => drawer.classList.remove('hidden'));
  document.querySelectorAll('[data-close-dns-import]').forEach((b) => b.addEventListener('click', () => drawer.classList.add('hidden')));
  $('dnsImportRun')?.addEventListener('click', async () => {
    const res = await apiPost('dns', { action: 'import', domain, zone: $('dnsImportText').value });
    if (res.ok) {
      published(res, (res.added || 0) + ' record' + (res.added === 1 ? '' : 's') + ' imported' + ((res.skipped || []).length ? ' · ' + res.skipped.length + ' line(s) skipped: ' + res.skipped.slice(0, 3).join('; ') : ''));
      drawer.classList.add('hidden'); setTimeout(() => location.reload(), 900);
    } else toast(res.error || 'Import failed', 'error');
  });
});
</script>
