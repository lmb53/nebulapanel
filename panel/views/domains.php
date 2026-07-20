<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_domains.php';
$sites = sites_list();
$serverIps = domain_server_ips();
?>
<div class="page-header">
  <div><h1 class="page-title">Domains</h1><p class="page-subtitle">Public DNS status for your hosted websites</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(url('websites')) ?>"><i data-lucide="plus"></i>Add Domain</a></div>
</div>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Server addresses</h3><span class="muted">Point domain A/AAAA records here</span></div>
  <div class="card-pad flex gap-2" style="flex-wrap:wrap">
    <?php if (!$serverIps): ?><span class="text-tertiary">No public address detected locally.</span><?php endif; ?>
    <?php foreach ($serverIps as $ip): ?><span class="badge badge-blue mono"><?= e($ip) ?></span><?php endforeach; ?>
  </div>
</div>
<div class="card">
  <div class="card-header"><h3>Tracked domains</h3><span class="muted"><?= count($sites) ?> configured</span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Domain</th><th>DNS addresses</th><th>Status</th><th>SSL</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $site):
      $domain = (string) ($site['domain'] ?? '');
      $records = domain_dns_records($domain);
      $addresses = array_values(array_filter(array_map('domain_record_value', array_filter($records, fn($r) => in_array($r['type'] ?? '', ['A', 'AAAA'], true)))));
      $points = domain_points_here($records, $serverIps);
    ?>
      <tr>
        <td style="font-weight:600"><a href="<?= e((!empty($site['ssl']) ? 'https://' : 'http://') . $domain) ?>" target="_blank" rel="noopener"><?= e($domain) ?></a></td>
        <td class="mono text-tertiary" style="font-size:12px"><?= e($addresses ? implode(', ', $addresses) : 'No A/AAAA record') ?></td>
        <td><?php if ($points === true): ?><span class="badge badge-emerald"><span class="bdot"></span>Points here</span><?php elseif ($points === false): ?><span class="badge badge-orange">Check DNS</span><?php else: ?><span class="badge badge-slate">Unknown</span><?php endif; ?></td>
        <td><span class="badge <?= !empty($site['ssl']) ? 'badge-emerald' : 'badge-slate' ?>"><?= !empty($site['ssl']) ? 'HTTPS' : 'HTTP' ?></span></td>
        <td style="text-align:right"><a class="btn btn-secondary btn-sm" href="<?= e(url('dns', ['domain' => $domain])) ?>"><i data-lucide="network"></i>Records</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sites): ?><tr><td colspan="5" style="text-align:center;padding:28px" class="text-tertiary">No domains yet. Add a website to begin.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
