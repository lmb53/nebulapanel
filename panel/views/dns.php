<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_domains.php';
$sites = sites_list();
$selected = (string) ($_GET['domain'] ?? ($sites[0]['domain'] ?? ''));
$allowed = array_column($sites, 'domain');
if (!in_array($selected, $allowed, true)) { $selected = (string) ($allowed[0] ?? ''); }
$records = $selected !== '' ? domain_dns_records($selected) : [];
?>
<div class="page-header">
  <div><h1 class="page-title">DNS</h1><p class="page-subtitle">Inspect the live public records returned for a hosted domain</p></div>
  <?php if ($sites): ?><form method="get" class="page-actions"><input type="hidden" name="r" value="dns"><select class="select" name="domain" onchange="this.form.submit()"><?php foreach ($sites as $site): $d=(string)$site['domain']; ?><option value="<?= e($d) ?>" <?= $d === $selected ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></form><?php endif; ?>
</div>
<div class="card">
  <div class="card-header"><h3><?= $selected ? e($selected) . ' — public records' : 'DNS records' ?></h3><span class="muted">Resolver view</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Name</th><th>Value</th><th>TTL</th></tr></thead><tbody>
  <?php foreach ($records as $record): ?>
    <tr><td><span class="badge badge-blue"><?= e($record['type'] ?? '?') ?></span></td><td class="mono"><?= e($record['host'] ?? $selected) ?></td><td class="mono text-tertiary" style="max-width:540px;white-space:normal;word-break:break-all"><?= e(domain_record_value($record)) ?></td><td class="mono"><?= e($record['ttl'] ?? '—') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$records): ?><tr><td colspan="4" style="text-align:center;padding:28px" class="text-tertiary"><?= $selected ? 'No supported public records were returned.' : 'Add a website first.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<div class="card" style="margin-top:16px"><div class="card-pad"><div class="flex gap-2 items-center"><i data-lucide="info" style="color:var(--blue-400)"></i><span class="text-tertiary" style="font-size:12.5px">Nebula reads public DNS here; edit authoritative records at your DNS provider. This avoids silently running an unsecured nameserver on the panel host.</span></div></div></div>
