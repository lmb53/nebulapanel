<?php
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_domains.php';
$sites = sites_list();
$serverIps = domain_server_ips();
?>
<div class="page-header">
  <div><h1 class="page-title">Domains</h1><p class="page-subtitle">Public DNS status for your hosted websites</p></div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= e(url('websites')) ?>#add"><i data-lucide="plus"></i>Add website</a></div>
</div>
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Server addresses</h3><span class="muted">Point domain A/AAAA records here</span></div>
  <div class="card-pad flex gap-2" style="flex-wrap:wrap">
    <?php if (!$serverIps): ?><span class="text-tertiary">No public address detected locally.</span><?php endif; ?>
    <?php foreach ($serverIps as $ip): ?><button type="button" class="code-chip" data-copy="<?= e($ip) ?>" title="Copy <?= e($ip) ?>"><span class="mono"><?= e($ip) ?></span><i data-lucide="copy" aria-hidden="true"></i></button><?php endforeach; ?>
  </div>
</div>
<div class="card">
  <div class="card-header"><h3>Tracked domains</h3><span class="muted"><?= count($sites) ?> configured</span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Domain</th><th>DNS addresses</th><th>Status</th><th>SSL</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $site):
      $domain = (string) ($site['domain'] ?? '');
      $records = domain_dns_records($domain);
      $addresses = array_values(array_filter(array_map('domain_record_value', array_filter($records, fn($r) => in_array($r['type'] ?? '', ['A', 'AAAA'], true)))));
      $points = domain_points_here($records, $serverIps);
    ?>
      <tr>
        <td style="font-weight:600"><a href="<?= e((!empty($site['ssl']) ? 'https://' : 'http://') . $domain) ?>" target="_blank" rel="noopener" class="ext-link"><?= e($domain) ?><i data-lucide="external-link" aria-label="(opens in a new tab)"></i></a></td>
        <td class="mono text-tertiary" style="font-size:12px"><?= e($addresses ? implode(', ', $addresses) : 'No A/AAAA record') ?></td>
        <td><?php if ($points === true): ?><span class="badge badge-emerald"><span class="bdot"></span>Points here</span><?php elseif ($points === false): ?><span class="badge badge-orange"><span class="bdot"></span>Points elsewhere</span><?php else: ?><span class="badge badge-slate">Unknown</span><?php endif; ?></td>
        <td><span class="badge <?= !empty($site['ssl']) ? 'badge-emerald' : 'badge-slate' ?>"><?= !empty($site['ssl']) ? 'HTTPS' : 'HTTP' ?></span></td>
        <td class="actions-col"><a class="btn btn-secondary btn-sm" href="<?= e(url('dns', ['domain' => $domain])) ?>"><i data-lucide="network"></i>Records</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sites): ?><tr class="empty-row"><td colspan="5">No domains yet. Domains are added when you create a website.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-copy]').forEach((chip) => chip.addEventListener('click', async () => {
    const ok = await window.Nebula.copyText(chip.dataset.copy);
    window.Nebula.toast(ok ? 'Copied ' + chip.dataset.copy : 'Copy failed', ok ? 'success' : 'error');
  }));
});
</script>
