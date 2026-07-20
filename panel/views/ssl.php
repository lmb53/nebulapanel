<?php
require_once APP_ROOT . '/lib/mod_ssl.php';
$available = ssl_available();
$list = $available ? ssl_list() : ['certs' => []];
$certs = $list['certs'] ?? [];
$loadError = $list['error'] ?? null;

$validCount = count(array_filter($certs, fn($c) => $c['valid'] && ($c['days'] === null || $c['days'] > 14)));
?>
<div class="page-header">
  <div>
    <h1 class="page-title">SSL Certificates</h1>
    <p class="page-subtitle"><?= count($certs) ?> certificate<?= count($certs) === 1 ? '' : 's' ?> · Let's Encrypt via certbot</p>
  </div>
  <?php if ($available): ?>
  <div class="page-actions">
    <button class="btn btn-secondary" data-ssl-renew-all><i data-lucide="refresh-cw"></i>Renew all</button>
  </div>
  <?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="shield-check"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">SSL management unavailable</div>
    <div style="font-size:13px;margin-top:4px">certbot is not installed or the privileged helper is missing. Re-run <span class="mono">install.sh</span> and install <span class="mono">certbot</span>.</div>
  </div></div>
<?php else: ?>
  <?php if ($loadError): ?>
    <div class="card" style="margin-bottom:16px"><div class="card-pad" style="color:var(--red-400);font-size:13px">
      <i data-lucide="alert-triangle" style="width:15px;height:15px;vertical-align:-2px"></i>
      Could not read certificates: <span class="mono"><?= e($loadError) ?></span>
    </div></div>
  <?php endif; ?>

  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <h3>Certificates</h3>
      <span class="muted"><?= count($certs) ?> total · <?= (int) $validCount ?> healthy</span>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Domain(s)</th>
            <th>Issuer</th>
            <th>Expires</th>
            <th>Auto-renew</th>
            <th>Status</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$certs): ?>
            <tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:28px">No certificates yet. Issue one below to get started.</td></tr>
          <?php else: ?>
            <?php foreach ($certs as $cert): ?>
              <?php
                $name = (string) $cert['name'];
                $days = $cert['days'];
                $valid = (bool) $cert['valid'];
                if (!$valid) {
                    $badgeClass = 'badge-red';
                    $badgeText = 'Expired';
                } elseif ($days !== null && $days <= 14) {
                    $badgeClass = 'badge-orange';
                    $badgeText = 'Expiring';
                } else {
                    $badgeClass = 'badge-emerald';
                    $badgeText = 'Valid';
                }
              ?>
              <tr>
                <td>
                  <div style="font-weight:600"><?= e($name) ?></div>
                  <?php if (!empty($cert['domains']) && $cert['domains'] !== $name): ?>
                    <div class="mono text-tertiary" style="font-size:11.5px;margin-top:3px"><?= e($cert['domains']) ?></div>
                  <?php endif; ?>
                </td>
                <td>Let's Encrypt</td>
                <td>
                  <div class="mono"><?= e($cert['expiry'] ?: 'unknown') ?></div>
                  <?php if ($days !== null): ?>
                    <div class="text-tertiary" style="font-size:11.5px;margin-top:3px"><?= (int) $days ?> day<?= $days === 1 ? '' : 's' ?></div>
                  <?php endif; ?>
                </td>
                <td><div class="toggle on"><div class="knob"></div></div></td>
                <td><span class="badge <?= $badgeClass ?>"><span class="bdot"></span><?= e($badgeText) ?></span></td>
                <td>
                  <div class="flex gap-1" style="justify-content:flex-end">
                    <button class="btn btn-ghost btn-sm" data-ssl-renew="<?= e($name) ?>" title="Renew now"><i data-lucide="refresh-cw"></i></button>
                    <button class="btn btn-ghost btn-sm" data-ssl-del="<?= e($name) ?>" title="Delete"><i data-lucide="trash-2"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Issue new certificate</h3></div>
    <div class="card-pad">
      <div class="grid" style="grid-template-columns:1fr 1fr auto;gap:12px;align-items:end">
        <div>
          <label class="field-label" for="sslDomain">Domain</label>
          <input class="input mono" id="sslDomain" placeholder="shop.example.com" autocomplete="off">
        </div>
        <div>
          <label class="field-label" for="sslEmail">Email</label>
          <input class="input mono" id="sslEmail" placeholder="optional" autocomplete="off">
        </div>
        <button class="btn btn-primary" id="sslIssue"><i data-lucide="shield-check"></i>Issue Certificate</button>
      </div>
      <div style="font-size:12px;color:var(--text-tertiary);margin-top:12px">
        The domain must already exist as a website with DNS pointing to this server. A certificate is requested from Let's Encrypt and installed into the site's nginx config.
      </div>
    </div>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiPost, toast } = window.Nebula;

    document.getElementById('sslIssue')?.addEventListener('click', async () => {
      const domain = document.getElementById('sslDomain').value.trim();
      const email = document.getElementById('sslEmail').value.trim();
      if (!domain) { toast('Enter a domain', 'warning'); return; }
      toast('Requesting certificate…', 'info');
      const res = await apiPost('ssl', { action: 'issue', domain, email });
      if (res.ok) { toast('Certificate issued', 'success'); setTimeout(() => location.reload(), 600); }
      else toast(res.error || 'Failed', 'error');
    });

    document.querySelectorAll('[data-ssl-renew]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const name = btn.getAttribute('data-ssl-renew');
        toast('Renewing ' + name + '…', 'info');
        const res = await apiPost('ssl', { action: 'renew', name });
        if (res.ok) { toast('Renewed', 'success'); setTimeout(() => location.reload(), 600); }
        else toast(res.error || 'Failed', 'error');
      });
    });

    document.querySelector('[data-ssl-renew-all]')?.addEventListener('click', async () => {
      if (!confirm('Renew all eligible certificates?')) return;
      toast('Renewing all certificates…', 'info');
      const res = await apiPost('ssl', { action: 'renew' });
      if (res.ok) { toast('Renewal complete', 'success'); setTimeout(() => location.reload(), 600); }
      else toast(res.error || 'Failed', 'error');
    });

    document.querySelectorAll('[data-ssl-del]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const name = btn.getAttribute('data-ssl-del');
        if (!confirm('Delete certificate ' + name + '? This cannot be undone.')) return;
        const res = await apiPost('ssl', { action: 'delete', name });
        if (res.ok) { toast('Deleted', 'success'); setTimeout(() => location.reload(), 500); }
        else toast(res.error || 'Failed', 'error');
      });
    });
  });
  </script>
<?php endif; ?>
