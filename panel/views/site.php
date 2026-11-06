<?php
/**
 * One website, everything in one place: overview, domain & DNS, SSL, PHP,
 * databases, files and backups. Each tab reuses the existing module and API,
 * and links to the full page for anything not covered here.
 */
require_once APP_ROOT . '/lib/mod_sites.php';
require_once APP_ROOT . '/lib/mod_backups.php';
require_once APP_ROOT . '/lib/mod_db.php';
require_once APP_ROOT . '/lib/mod_dns.php';
require_once APP_ROOT . '/lib/mod_domains.php';
require_once APP_ROOT . '/lib/mod_ssl.php';
require_once APP_ROOT . '/lib/files.php';

$siteId = (string) ($_GET['id'] ?? '');
$site = preg_match('/^[a-f0-9]{32}$/', $siteId) ? backup_find_site($siteId) : null;
if (!$site):
?>
<div class="page-header"><div><h1 class="page-title">Website not found</h1><p class="page-subtitle">It may have been deleted, or the link is out of date.</p></div></div>
<?= requirement_missing('globe', 'This website does not exist', 'Choose a website from the Websites page.', [['label' => 'All websites', 'href' => url('websites'), 'icon' => 'arrow-left', 'primary' => true]]) ?>
<?php
return;
endif;

$domain = (string) $site['domain'];
$docroot = (string) ($site['docroot'] ?? '');
$ssl = !empty($site['ssl']);
$siteUrl = ($ssl ? 'https://' : 'http://') . $domain;
$helper = helper_available();

// Usage
$diskUsed = 0; $fileCount = 0;
if ($helper && sv_managed_path_ok($docroot, $siteId)) {
    [$c, $o] = helper_cmd('site-stats ' . escapeshellarg($siteId), 60);
    if ($c === 0) { [$diskUsed, $fileCount] = array_map('intval', array_pad(explode("\t", trim($o), 2), 2, 0)); }
}

// DNS
$records = domain_dns_records($domain);
$serverIps = domain_server_ips();
$pointsHere = domain_points_here($records, $serverIps);
$zone = dns_zones()[$domain] ?? null;
$zoneRecords = is_array($zone) ? array_values((array) ($zone['records'] ?? [])) : [];

// SSL
$cert = null;
if ($helper) {
    $sslList = ssl_list();
    foreach ((array) ($sslList['certs'] ?? []) as $candidate) {
        $names = preg_split('/[\s,]+/', strtolower((string) ($candidate['domains'] ?? $candidate['name'] ?? ''))) ?: [];
        if (in_array($domain, $names, true) || strtolower((string) ($candidate['name'] ?? '')) === $domain) { $cert = $candidate; break; }
    }
}

// PHP
$phpVersions = $helper ? php_versions() : [];

// Databases
$linkedDbs = backup_site_databases($domain);
$dbServer = db_available() ? db_server_status() : ['ready' => false, 'message' => 'No MySQL/MariaDB client is installed.'];
$dbSizes = [];
if ($linkedDbs && $dbServer['ready']) {
    foreach ((array) (db_list()['databases'] ?? []) as $db) { $dbSizes[$db['name']] = (int) $db['size']; }
}

// Files
$filesPath = fm_link_path($docroot);
$listing = $filesPath !== null && is_dir($docroot) ? fm_list($docroot, 40) : null;

// Backups
$siteBackups = backup_list($siteId);
$schedule = backup_schedule();
$scheduled = !empty($schedule['enabled']) && (!$schedule['sites'] || in_array($siteId, $schedule['sites'], true));

$tab = static fn(string $id, string $icon, string $label, bool $active = false, string $extra = ''): string =>
    '<button class="tab' . ($active ? ' active' : '') . '" type="button" data-tab-target="site-' . $id . '" data-tab-hash="' . $id . '" data-tab-panel-group="#sitePanels"><i data-lucide="' . $icon . '"></i>' . $label . $extra . '</button>';
?>
<div class="page-header">
  <div>
    <div class="text-tertiary" style="font-size:12px;margin-bottom:4px"><a href="<?= e(url('websites')) ?>"><i data-lucide="arrow-left" style="width:12px;height:12px;vertical-align:-1px"></i> Websites</a></div>
    <h1 class="page-title"><?= e($domain) ?></h1>
    <p class="page-subtitle">
      <?php if (!empty($site['php'])): ?><span class="badge badge-slate">PHP <?= e($site['php']) ?></span><?php endif; ?>
      <span class="badge <?= $ssl ? 'badge-emerald' : 'badge-slate' ?>"><span class="bdot"></span><?= $ssl ? 'HTTPS' : 'No SSL' ?></span>
      <?php if ($pointsHere === true): ?><span class="badge badge-emerald">DNS points here</span><?php elseif ($pointsHere === false): ?><span class="badge badge-orange">DNS not pointing here</span><?php endif; ?>
      <span class="mono text-tertiary" style="font-size:12px"><?= e($siteId) ?></span>
    </p>
  </div>
  <div class="page-actions"><a class="btn btn-secondary" href="<?= e($siteUrl) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Visit site</a></div>
</div>

<div class="tabs page-tabs" id="siteTabs">
  <?= $tab('overview', 'layout-dashboard', 'Overview', true) ?>
  <?= $tab('domain', 'earth', 'Domain &amp; DNS') ?>
  <?= $tab('ssl', 'shield-check', 'SSL', false, $cert && ($cert['days'] ?? 99) <= 21 ? ' <span class="badge badge-orange">' . (int) $cert['days'] . 'd</span>' : '') ?>
  <?= $tab('php', 'code-2', 'PHP') ?>
  <?= $tab('databases', 'database', 'Databases', false, $linkedDbs ? ' <span class="badge badge-slate">' . count($linkedDbs) . '</span>' : '') ?>
  <?= $tab('files', 'folder-tree', 'Files') ?>
  <?= $tab('backups', 'archive-restore', 'Backups', false, $siteBackups ? ' <span class="badge badge-slate">' . count($siteBackups) . '</span>' : '') ?>
</div>

<div id="sitePanels">
  <!-- Overview -->
  <section data-tab-panel id="site-overview">
    <div class="grid grid-4" style="margin-bottom:16px">
      <div class="stat-card"><div class="stat-val text"><?= e(human_bytes($diskUsed)) ?></div><div class="stat-label"><?= number_format($fileCount) ?> files in public/</div></div>
      <div class="stat-card"><div class="stat-val text"><?= $cert ? e(($cert['valid'] ?? true) ? (($cert['days'] ?? '?') . ' days') : 'Expired') : ($ssl ? 'HTTPS' : 'None') ?></div><div class="stat-label"><?= $cert ? 'Certificate validity left' : 'SSL certificate' ?></div></div>
      <div class="stat-card"><div class="stat-val text"><?= count($linkedDbs) ?></div><div class="stat-label">Linked database<?= count($linkedDbs) === 1 ? '' : 's' ?></div></div>
      <div class="stat-card"><div class="stat-val text"><?= $siteBackups ? e(fmt_datetime($siteBackups[0]['mtime'], false)) : 'Never' ?></div><div class="stat-label">Last backup<?= $scheduled ? ' · scheduled ' . e($schedule['frequency']) : '' ?></div></div>
    </div>
    <div class="card">
      <div class="card-header"><h3>Details</h3></div>
      <div class="card-pad">
        <div class="fm-prop-row"><span class="k">Domain</span><span class="v"><a href="<?= e($siteUrl) ?>" target="_blank" rel="noopener"><?= e($siteUrl) ?></a></span></div>
        <div class="fm-prop-row"><span class="k">Document root</span><span class="v mono"><?= e($docroot) ?></span></div>
        <div class="fm-prop-row"><span class="k">Web server</span><span class="v"><?= e(($site['server'] ?? 'nginx') === 'apache2' ? 'Apache' : 'Nginx') ?></span></div>
        <div class="fm-prop-row"><span class="k">PHP-FPM identity</span><span class="v mono">nebula-s-<?= e(substr($siteId, 0, 12)) ?></span></div>
        <?php if (!empty($site['created'])): ?><div class="fm-prop-row"><span class="k">Created</span><span class="v"><?= e(fmt_datetime($site['created'])) ?></span></div><?php endif; ?>
        <div class="flex gap-2" style="margin-top:14px;flex-wrap:wrap">
          <?php if ($filesPath !== null): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('files', ['path' => $filesPath])) ?>"><i data-lucide="folder-open"></i>Open files</a><?php endif; ?>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('logs')) ?>"><i data-lucide="scroll-text"></i>Logs</a>
          <button class="btn btn-secondary btn-sm" type="button" data-site-backup><i data-lucide="archive"></i>Back up now</button>
        </div>
      </div>
    </div>
  </section>

  <!-- Domain & DNS -->
  <section data-tab-panel id="site-domain" class="hidden">
    <div class="grid grid-2">
      <div class="card">
        <div class="card-header"><h3>Public DNS</h3><?php if ($pointsHere === true): ?><span class="badge badge-emerald"><span class="bdot"></span>Points to this server</span><?php elseif ($pointsHere === false): ?><span class="badge badge-orange"><span class="bdot"></span>Not pointing here</span><?php endif; ?></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Value</th></tr></thead><tbody>
          <?php foreach ($records as $record): ?><tr><td><span class="badge badge-slate mono"><?= e((string) ($record['type'] ?? '')) ?></span></td><td class="mono" style="word-break:break-all"><?= e(domain_record_value($record)) ?></td></tr><?php endforeach; ?>
          <?php if (!$records): ?><tr class="empty-row"><td colspan="2">No public DNS records found for <?= e($domain) ?>.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($pointsHere === false && $serverIps): ?><div class="card-pad field-help">Create an <span class="mono">A</span> record for <span class="mono"><?= e($domain) ?></span> pointing to <span class="mono"><?= e(implode(', ', $serverIps)) ?></span> at your DNS provider, or use the panel’s DNS zone.</div><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-header"><h3>Panel DNS zone</h3><a class="btn btn-secondary btn-sm" href="<?= e(url('dns', ['domain' => $domain])) ?>"><?= $zone ? 'Edit records' : 'Open DNS' ?><i data-lucide="arrow-right"></i></a></div>
        <?php if ($zone): ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Type</th><th>Value</th></tr></thead><tbody>
          <?php foreach (array_slice($zoneRecords, 0, 20) as $record): ?><tr><td class="mono"><?= e((string) ($record['name'] ?? '@')) ?></td><td><span class="badge badge-slate mono"><?= e((string) ($record['type'] ?? '')) ?></span></td><td class="mono" style="word-break:break-all"><?= e((string) ($record['value'] ?? '')) ?></td></tr><?php endforeach; ?>
          <?php if (!$zoneRecords): ?><tr class="empty-row"><td colspan="3">The zone has no records yet.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php else: ?>
        <div class="card-pad field-help" style="margin:0">This server is not authoritative for <?= e($domain) ?>. Host its DNS here from the DNS page and delegate to <span class="mono"><?= e(implode(', ', dns_nameservers())) ?></span>, or keep using your current DNS provider.</div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- SSL -->
  <section data-tab-panel id="site-ssl" class="hidden">
    <div class="card">
      <div class="card-header"><h3>SSL certificate</h3><a class="btn btn-secondary btn-sm" href="<?= e(url('ssl')) ?>">All certificates<i data-lucide="arrow-right"></i></a></div>
      <div class="card-pad">
        <?php if (!$helper): ?>
          <?= helper_missing_state('certificates', false) ?>
        <?php elseif ($cert): $days = $cert['days'] ?? null; ?>
          <div class="fm-prop-row"><span class="k">Certificate</span><span class="v mono"><?= e((string) $cert['name']) ?></span></div>
          <div class="fm-prop-row"><span class="k">Covers</span><span class="v mono" style="word-break:break-all"><?= e((string) ($cert['domains'] ?? $cert['name'])) ?></span></div>
          <div class="fm-prop-row"><span class="k">Expires</span><span class="v"><?= e(fmt_datetime((string) ($cert['expiry'] ?? ''), false)) ?> <span class="badge <?= !($cert['valid'] ?? true) || ($days !== null && $days <= 7) ? 'badge-red' : ($days !== null && $days <= 21 ? 'badge-orange' : 'badge-emerald') ?>"><?= ($cert['valid'] ?? true) ? e(($days ?? '?') . ' days left') : 'Expired' ?></span></span></div>
          <?php if ($days !== null && $days <= 21): ?><div class="notice notice-warning" style="margin-top:12px"><i data-lucide="triangle-alert"></i><div>Certbot renews certificates 30 days before they expire, so automatic renewal is probably failing. Renew now to see the error.</div></div><?php endif; ?>
          <div style="margin-top:14px"><button class="btn btn-secondary" type="button" data-site-renew="<?= e((string) $cert['name']) ?>"><i data-lucide="refresh-cw"></i>Renew now</button></div>
        <?php else: ?>
          <p class="field-help" style="margin-top:0">No certificate covers <?= e($domain) ?> yet. Let’s Encrypt must be able to reach the domain over HTTP<?= $pointsHere === false ? ' — <strong>its DNS does not point to this server yet</strong>' : '' ?>.</p>
          <form class="form-grid" id="siteSslForm" style="--cols:minmax(0,320px) auto;align-items:end" novalidate>
            <div><label class="field-label" for="siteSslEmail">Expiry notices email <span class="text-tertiary" style="font-weight:400">(optional)</span></label><input class="input" id="siteSslEmail" type="email" autocomplete="email" placeholder="admin@<?= e($domain) ?>"></div>
            <button class="btn btn-primary" type="submit"><i data-lucide="shield-plus"></i>Issue certificate</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- PHP -->
  <section data-tab-panel id="site-php" class="hidden">
    <div class="card">
      <div class="card-header"><h3>PHP version</h3><a class="btn btn-secondary btn-sm" href="<?= e(url('php')) ?>">PHP settings<i data-lucide="arrow-right"></i></a></div>
      <div class="card-pad">
        <?php if (!$phpVersions): ?>
          <p class="field-help" style="margin:0">No PHP-FPM versions are available. Install one from <a href="<?= e(url('apps')) ?>">Install Apps</a>.</p>
        <?php else: ?>
          <form class="form-grid" id="sitePhpForm" style="--cols:minmax(0,200px) auto;align-items:end" novalidate>
            <div><label class="field-label" for="sitePhp">Runs on</label>
              <select class="select" id="sitePhp"><?php foreach ($phpVersions as $v): ?><option value="<?= e($v) ?>"<?= ($site['php'] ?? '') === $v ? ' selected' : '' ?>>PHP <?= e($v) ?></option><?php endforeach; ?></select>
            </div>
            <button class="btn btn-primary" type="submit" id="sitePhpSave"><i data-lucide="save"></i>Switch version</button>
          </form>
          <div class="field-help" style="margin-top:10px">The site’s private PHP-FPM pool moves to the chosen version; ini settings and extensions are per version on the PHP page.</div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Databases -->
  <section data-tab-panel id="site-databases" class="hidden">
    <div class="card">
      <div class="card-header"><h3>Databases</h3><a class="btn btn-secondary btn-sm" href="<?= e(url('databases', ['website' => $domain])) ?>"><i data-lucide="plus"></i>Create or link</a></div>
      <?php if (!$dbServer['ready']): ?><div class="card-pad"><div class="notice notice-info"><i data-lucide="info"></i><div><?= e((string) $dbServer['message']) ?></div></div></div><?php endif; ?>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Database</th><th class="num">Size</th><th class="actions-col"><span class="sr-only">Actions</span></th></tr></thead><tbody>
        <?php foreach ($linkedDbs as $db): ?><tr><td class="mono"><?= e($db) ?></td><td class="num mono text-tertiary"><?= isset($dbSizes[$db]) ? e(human_bytes($dbSizes[$db])) : '—' ?></td><td class="actions-col"><?php if (role_route_allowed('phpmyadmin')): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('phpmyadmin')) ?>">phpMyAdmin</a><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$linkedDbs): ?><tr class="empty-row"><td colspan="3">No databases are linked to this website. Linked databases are included in its backups.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </section>

  <!-- Files -->
  <section data-tab-panel id="site-files" class="hidden">
    <div class="card">
      <div class="card-header"><div><h3>Files</h3><span class="muted mono"><?= e($docroot) ?></span></div><?php if ($filesPath !== null): ?><a class="btn btn-primary btn-sm" href="<?= e(url('files', ['path' => $filesPath])) ?>"><i data-lucide="folder-open"></i>Open in File Manager</a><?php endif; ?></div>
      <?php if ($listing === null): ?>
        <div class="card-pad field-help" style="margin:0">The document root is outside the File Manager root or is not readable by the panel.</div>
      <?php else: $entries = array_merge($listing['dirs'] ?? [], $listing['files'] ?? []); ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th class="num">Size</th><th>Modified</th></tr></thead><tbody>
          <?php foreach (array_slice($entries, 0, 40) as $entry): ?><tr><td><i data-lucide="<?= e($entry['icon'] ?? 'file') ?>" style="width:14px;height:14px;vertical-align:-2px;color:<?= e($entry['color'] ?? 'inherit') ?>"></i> <a href="<?= e($entry['href'] ?? '#') ?>"><?= e($entry['name']) ?></a></td><td class="num mono text-tertiary"><?= e($entry['size_h'] ?? '') ?></td><td class="text-tertiary"><?= e(fmt_datetime($entry['mtime'] ?? 0)) ?></td></tr><?php endforeach; ?>
          <?php if (!$entries): ?><tr class="empty-row"><td colspan="3">public/ is empty. Upload a .zip in the File Manager and extract it here.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if (count($entries) > 40 || !empty($listing['truncated'])): ?><div class="card-pad field-help" style="margin:0">Showing the first 40 items.</div><?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- Backups -->
  <section data-tab-panel id="site-backups" class="hidden">
    <div class="card">
      <div class="card-header"><div><h3>Backups</h3><span class="muted"><?= $scheduled ? 'Backed up automatically ' . e($schedule['frequency'] === 'weekly' ? 'every week' : 'every night') . ', keeping ' . (int) $schedule['keep'] : 'Not in the backup schedule' ?></span></div>
        <div class="flex gap-2"><button class="btn btn-primary btn-sm" type="button" data-site-backup><i data-lucide="archive"></i>Back up now</button><a class="btn btn-secondary btn-sm" href="<?= e(url('backups', ['site' => $siteId])) ?>"><i data-lucide="history"></i>Restore…</a></div></div>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Archive</th><th>Contents</th><th class="num">Size</th><th>Created</th></tr></thead><tbody>
        <?php foreach (array_slice($siteBackups, 0, 10) as $b): ?><tr><td class="mono" style="font-size:13px;word-break:break-all"><?= e($b['file']) ?></td><td><span class="badge badge-slate">Files</span><?php foreach ($b['databases'] as $db): ?> <span class="badge badge-blue mono"><?= e($db) ?></span><?php endforeach; ?></td><td class="num mono text-tertiary"><?= e(human_bytes($b['size'])) ?></td><td class="text-tertiary"><?= e(fmt_datetime($b['mtime'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$siteBackups): ?><tr class="empty-row"><td colspan="4">No backups of this website yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast, runTask, setBusy } = window.Nebula;
  const siteId = <?= json_encode($siteId) ?>, domain = <?= json_encode($domain) ?>;
  const reloadTo = (hash) => { location.hash = hash; location.reload(); };
  document.querySelectorAll('[data-site-backup]').forEach((btn) => btn.addEventListener('click', async () => {
    const res = await runTask({ title: 'Backing up ' + domain, endpoint: 'backups', body: { action: 'create', site_id: siteId, label: '', include_databases: true }, successMessage: 'Backup created' });
    if (res.ok) { toast('Backup created', 'success'); reloadTo('backups'); }
  }));
  document.getElementById('siteSslForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const res = await runTask({ title: 'Issuing certificate for ' + domain, endpoint: 'sites', body: { action: 'ssl', domain, email: document.getElementById('siteSslEmail').value.trim() }, initialStep: 'Requesting a certificate from Let’s Encrypt', successMessage: 'Certificate issued' });
    if (res.ok) { toast('Certificate issued', 'success'); reloadTo('ssl'); }
  });
  document.querySelector('[data-site-renew]')?.addEventListener('click', async (event) => {
    const res = await runTask({ title: 'Renewing certificate', endpoint: 'ssl', body: { action: 'renew', name: event.currentTarget.dataset.siteRenew }, successMessage: 'Renewed' });
    if (res.ok) { toast('Certificate renewed', 'success'); reloadTo('ssl'); }
  });
  document.getElementById('sitePhpForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = document.getElementById('sitePhpSave'); setBusy(button, true);
    const res = await apiPost('sites', { action: 'php', domain, version: document.getElementById('sitePhp').value });
    setBusy(button, false);
    if (res.ok) { toast('PHP version switched', 'success'); setTimeout(() => reloadTo('php'), 400); } else toast(res.error || 'Could not switch PHP', 'error');
  });
});
</script>
