<?php
require_once APP_ROOT . '/lib/mod_mail.php';
require_once APP_ROOT . '/lib/mod_sites.php';

$status    = mail_status();
$installed = (bool) ($status['installed'] ?? false);
$helper    = (bool) ($status['helper'] ?? false);
$state     = mail_state();
$domains   = array_keys($state['domains']);
$rcInstalled = mail_roundcube_installed();
$rcUrl       = mail_roundcube()['url'] ?? null;

$selected = (string) ($_GET['domain'] ?? ($domains[0] ?? ''));
if (!in_array($selected, $domains, true)) {
    $selected = (string) ($domains[0] ?? '');
}
$managedZones = array_map(fn($s) => (string) ($s['domain'] ?? ''), sites_list());

$svcBadge = static function (string $v): string {
    return $v === 'active' ? 'badge-emerald' : ($v === 'inactive' ? 'badge-slate' : 'badge-orange');
};
$dnsBadge = ['A' => 'badge-blue', 'AAAA' => 'badge-purple', 'CNAME' => 'badge-emerald', 'MX' => 'badge-orange', 'TXT' => 'badge-slate'];

// Per-domain data for the selected zone.
$mailboxes = $selected === '' ? [] : array_values(array_filter($state['accounts'], fn($a) => str_ends_with((string) ($a['email'] ?? ''), '@' . $selected)));
$aliases   = $selected === '' ? [] : array_values(array_filter($state['aliases'], fn($a) => str_ends_with((string) ($a['from'] ?? ''), '@' . $selected)));
$dnsRecords = $selected === '' ? [] : mail_dns_records($selected);
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Email</h1>
    <p class="page-subtitle">Mailboxes, aliases, DKIM &amp; DNS · Postfix + Dovecot + OpenDKIM + Roundcube</p>
  </div>
  <?php if ($installed && $rcInstalled && $rcUrl): ?>
    <a class="btn btn-primary" href="<?= e($rcUrl) ?>" target="_blank" rel="noopener"><i data-lucide="mail"></i>Open Webmail</a>
  <?php endif; ?>
</div>

<?php if (!$helper): ?>
  <div class="notice notice-warning" style="margin-bottom:16px"><i data-lucide="shield-alert"></i>
    <div><strong>Privileged helper required</strong><div>The <span class="mono">nebula-helper</span> is not installed. Re-run <span class="mono">install.sh</span> to enable email management.</div></div>
  </div>
<?php endif; ?>

<!-- Stack status ---------------------------------------------------------- -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Mail server</h3>
    <?php if ($installed): ?><span class="badge badge-emerald"><span class="bdot"></span>Installed</span><?php else: ?><span class="badge badge-slate">Not installed</span><?php endif; ?>
  </div>
  <div class="card-pad">
    <?php if ($installed): ?>
      <div class="flex items-center gap-2" style="flex-wrap:wrap;margin-bottom:14px">
        <span class="badge <?= e($svcBadge((string) $status['postfix'])) ?>">Postfix: <?= e($status['postfix']) ?></span>
        <span class="badge <?= e($svcBadge((string) $status['dovecot'])) ?>">Dovecot: <?= e($status['dovecot']) ?></span>
        <span class="badge <?= e($svcBadge((string) $status['opendkim'])) ?>">OpenDKIM: <?= e($status['opendkim']) ?></span>
      </div>
      <div class="muted" style="font-size:13px">
        Mail host <span class="mono"><?= e((string) $status['hostname']) ?></span>
        <?php if (!empty($status['ip'])): ?> · IP <span class="mono"><?= e((string) $status['ip']) ?></span><?php endif; ?>
        · IMAP <span class="mono">143 (STARTTLS)</span> · POP3 <span class="mono">110</span> · SMTP submission <span class="mono">587</span>
      </div>
    <?php else: ?>
      <p style="color:var(--text-secondary);margin:0 0 16px">
        Install and auto-configure a complete mail stack — Postfix (SMTP), Dovecot (IMAP/POP3),
        and OpenDKIM signing — in one click. Virtual mailboxes are file-backed; no database setup
        is required. This installs packages and may take a few minutes.
      </p>
      <button class="btn btn-primary" id="mailSetup"<?= $helper ? '' : ' disabled' ?>><i data-lucide="download"></i>Install &amp; configure mail server</button>
      <div class="card hidden" id="mailSetupLogCard" style="margin-top:16px">
        <div class="card-header"><h3>Setup output</h3></div>
        <pre class="mono" id="mailSetupLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:40vh;overflow:auto"></pre>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($installed): ?>
<!-- Roundcube ------------------------------------------------------------- -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Roundcube webmail</h3>
    <?php if ($rcInstalled): ?><span class="badge badge-emerald">Installed</span><?php else: ?><span class="badge badge-slate">Not installed</span><?php endif; ?>
  </div>
  <div class="card-pad">
    <?php if ($rcInstalled): ?>
      <a class="btn btn-primary" href="<?= e((string) $rcUrl) ?>" target="_blank" rel="noopener"><i data-lucide="mail"></i>Open Webmail</a>
      <span class="mono" style="margin-left:12px;color:var(--blue-400)"><?= e((string) $rcUrl) ?></span>
      <div class="muted" style="font-size:13px;margin-top:12px">Users log in with their full email address and mailbox password.</div>
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
        <button class="btn btn-danger btn-sm" id="rcRemove"><i data-lucide="trash-2"></i>Remove Roundcube</button>
      </div>
    <?php else: ?>
      <p style="color:var(--text-secondary);margin:0 0 14px">Give your users a browser-based inbox. Roundcube is installed to its own random URL and pre-configured against this server's IMAP/SMTP (SQLite storage — nothing else to set up).</p>
      <button class="btn btn-primary" id="rcInstall"><i data-lucide="download"></i>Install Roundcube webmail</button>
      <div class="card hidden" id="rcLogCard" style="margin-top:16px">
        <div class="card-header"><h3>Install output</h3></div>
        <pre class="mono" id="rcLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:40vh;overflow:auto"></pre>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Domains + detail ------------------------------------------------------ -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><h3>Mail domains</h3><span class="badge badge-slate"><?= count($domains) ?></span></div>
  <div class="card-pad">
    <div class="flex items-center gap-2" style="flex-wrap:wrap;margin-bottom:14px">
      <input class="input mono" id="domainInput" placeholder="example.com" style="max-width:260px">
      <button class="btn btn-primary btn-sm" id="domainAdd"><i data-lucide="plus"></i>Add domain</button>
    </div>
    <?php if (!$domains): ?>
      <div class="muted" style="font-size:13px">No mail domains yet. Add one above, then create mailboxes on it.</div>
    <?php else: ?>
      <div class="flex items-center gap-2" style="flex-wrap:wrap">
        <?php foreach ($domains as $d): ?>
          <a class="btn btn-sm <?= $d === $selected ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(url('mail', ['domain' => $d])) ?>"><i data-lucide="globe"></i><?= e($d) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($selected !== ''): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <div><h3><?= e($selected) ?></h3><span class="muted"><?= count($mailboxes) ?> mailbox<?= count($mailboxes) === 1 ? '' : 'es' ?> · <?= count($aliases) ?> alias<?= count($aliases) === 1 ? '' : 'es' ?></span></div>
    <button class="btn btn-danger btn-sm" data-domain-delete="<?= e($selected) ?>"><i data-lucide="trash-2"></i>Delete domain</button>
  </div>
  <div class="card-pad">

    <!-- Mailboxes -->
    <h4 style="margin:0 0 10px;font-size:14px">Mailboxes</h4>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Address</th><th>Created</th><th></th></tr></thead><tbody>
      <?php foreach ($mailboxes as $m): ?>
        <tr>
          <td class="mono"><?= e((string) $m['email']) ?></td>
          <td class="mono text-tertiary"><?= e(substr((string) ($m['created'] ?? ''), 0, 10)) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn btn-ghost btn-sm" data-account-passwd="<?= e((string) $m['email']) ?>" title="Change password"><i data-lucide="key-round"></i></button>
            <button class="icon-btn" style="color:var(--red-400)" data-account-delete="<?= e((string) $m['email']) ?>" title="Delete mailbox"><i data-lucide="trash-2"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$mailboxes): ?><tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:20px">No mailboxes yet.</td></tr><?php endif; ?>
    </tbody><tfoot><tr>
      <td><div class="flex items-center gap-1"><input class="input mono" id="mbUser" placeholder="user" style="max-width:120px"><span class="mono muted">@<?= e($selected) ?></span></div></td>
      <td><input class="input" id="mbPass" type="password" placeholder="Mailbox password (min 8)"></td>
      <td style="text-align:right"><button class="btn btn-primary btn-sm" id="mbAdd"><i data-lucide="plus"></i>Add mailbox</button></td>
    </tr></tfoot></table></div>

    <!-- Aliases -->
    <h4 style="margin:22px 0 10px;font-size:14px">Aliases &amp; forwarders</h4>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Alias address</th><th>Forwards to</th><th></th></tr></thead><tbody>
      <?php foreach ($aliases as $a): ?>
        <tr>
          <td class="mono"><?= e((string) $a['from']) ?></td>
          <td class="mono text-tertiary"><?= e((string) $a['to']) ?></td>
          <td style="text-align:right"><button class="icon-btn" style="color:var(--red-400)" data-alias-delete-from="<?= e((string) $a['from']) ?>" data-alias-delete-to="<?= e((string) $a['to']) ?>" title="Delete alias"><i data-lucide="trash-2"></i></button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$aliases): ?><tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:20px">No aliases yet.</td></tr><?php endif; ?>
    </tbody><tfoot><tr>
      <td><div class="flex items-center gap-1"><input class="input mono" id="alUser" placeholder="info" style="max-width:120px"><span class="mono muted">@<?= e($selected) ?></span></div></td>
      <td><input class="input mono" id="alDest" placeholder="destination@example.com"></td>
      <td style="text-align:right"><button class="btn btn-primary btn-sm" id="alAdd"><i data-lucide="plus"></i>Add alias</button></td>
    </tr></tfoot></table></div>

    <!-- DNS + DKIM -->
    <h4 style="margin:22px 0 10px;font-size:14px">DNS records for deliverability</h4>
    <div class="notice notice-warning" style="margin-bottom:12px"><i data-lucide="info"></i>
      <div>
        <strong>Add these records at wherever <?= e($selected) ?>'s DNS is hosted.</strong>
        <div>MX + SPF + DKIM + DMARC together let this server send mail that lands in the inbox.
        <?php if (in_array($selected, $managedZones, true)): ?>
          Since this is a panel-managed DNS zone, you can publish them automatically.
        <?php endif; ?></div>
      </div>
    </div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr></thead><tbody>
      <?php foreach ($dnsRecords as $r): $val = ($r['type'] === 'MX' ? ((int) $r['priority'] . ' ') : '') . $r['value']; ?>
        <tr>
          <td><span class="badge <?= e($dnsBadge[$r['type']] ?? 'badge-slate') ?>"><?= e($r['type']) ?></span></td>
          <td class="mono"><?= e((string) $r['name']) ?></td>
          <td class="mono text-tertiary" style="max-width:460px;white-space:normal;word-break:break-all"><?= e($val) ?><div class="muted" style="font-size:11px;margin-top:2px"><?= e((string) ($r['note'] ?? '')) ?></div></td>
          <td style="text-align:right"><button class="icon-btn" data-copy="<?= e($val) ?>" title="Copy value"><i data-lucide="copy"></i></button></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if (in_array($selected, $managedZones, true)): ?>
      <div style="margin-top:14px"><button class="btn btn-secondary" id="dnsPublish" data-domain="<?= e($selected) ?>"><i data-lucide="upload-cloud"></i>Publish these records to the DNS zone</button></div>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>
<?php endif; /* installed */ ?>

<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, streamPost, toast } = window.Nebula;
  const domain = <?= json_encode($selected) ?>;
  const reload = (ms = 500) => setTimeout(() => location.reload(), ms);

  // --- Stack setup (streamed) ---
  const setupBtn = document.getElementById('mailSetup');
  setupBtn?.addEventListener('click', async () => {
    setupBtn.disabled = true; setupBtn.textContent = 'Installing…';
    const card = document.getElementById('mailSetupLogCard'); const log = document.getElementById('mailSetupLog');
    card?.classList.remove('hidden'); if (log) log.textContent = '';
    const res = await streamPost('mail', { action: 'setup' }, (ev) => {
      if (ev.type === 'output' && ev.text && log) { log.textContent += ev.text; log.scrollTop = log.scrollHeight; }
    });
    if (res.ok) { toast('Mail server installed', 'success'); reload(800); }
    else { toast(res.error || 'Setup failed', 'error'); if (log && res.error) log.textContent += '\n' + res.error + '\n'; setupBtn.disabled = false; setupBtn.innerHTML = '<i data-lucide="download"></i>Install &amp; configure mail server'; if (window.lucide) lucide.createIcons(); }
  });

  // --- Roundcube ---
  const rcInstall = document.getElementById('rcInstall');
  rcInstall?.addEventListener('click', async () => {
    rcInstall.disabled = true; rcInstall.textContent = 'Installing…';
    const card = document.getElementById('rcLogCard'); const log = document.getElementById('rcLog');
    card?.classList.remove('hidden'); if (log) log.textContent = '';
    const res = await streamPost('mail', { action: 'roundcube-install' }, (ev) => {
      if (ev.type === 'output' && ev.text && log) { log.textContent += ev.text; log.scrollTop = log.scrollHeight; }
    });
    if (res.ok) { toast('Roundcube installed', 'success'); reload(800); }
    else { toast(res.error || 'Install failed', 'error'); rcInstall.disabled = false; rcInstall.innerHTML = '<i data-lucide="download"></i>Install Roundcube webmail'; if (window.lucide) lucide.createIcons(); }
  });
  document.getElementById('rcRemove')?.addEventListener('click', async () => {
    if (!confirm('Remove Roundcube? The installed files will be deleted.')) return;
    const res = await apiPost('mail', { action: 'roundcube-remove' });
    if (res.ok) { toast('Roundcube removed', 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  });

  // --- Domains ---
  document.getElementById('domainAdd')?.addEventListener('click', async () => {
    const val = document.getElementById('domainInput').value.trim();
    if (!val) return;
    const res = await apiPost('mail', { action: 'domain-add', domain: val });
    if (res.ok) { toast(res.warning || 'Domain added', res.warning ? 'warning' : 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  });
  document.querySelectorAll('[data-domain-delete]').forEach(b => b.addEventListener('click', async () => {
    const d = b.dataset.domainDelete;
    if (!confirm('Delete mail domain ' + d + '? Its mailboxes and aliases will be removed.')) return;
    const res = await apiPost('mail', { action: 'domain-delete', domain: d });
    if (res.ok) { toast('Domain deleted', 'success'); window.location = <?= json_encode(url('mail')) ?>; } else toast(res.error || 'Failed', 'error');
  }));

  // --- Mailboxes ---
  document.getElementById('mbAdd')?.addEventListener('click', async () => {
    const user = document.getElementById('mbUser').value.trim();
    const pass = document.getElementById('mbPass').value;
    if (!user || !pass) { toast('Enter a username and password', 'error'); return; }
    const res = await apiPost('mail', { action: 'account-add', email: user + '@' + domain, password: pass });
    if (res.ok) { toast(res.warning || 'Mailbox created', res.warning ? 'warning' : 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  });
  document.querySelectorAll('[data-account-delete]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Delete mailbox ' + b.dataset.accountDelete + '?')) return;
    const res = await apiPost('mail', { action: 'account-delete', email: b.dataset.accountDelete });
    if (res.ok) { toast('Mailbox deleted', 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  }));
  document.querySelectorAll('[data-account-passwd]').forEach(b => b.addEventListener('click', async () => {
    const pw = prompt('New password for ' + b.dataset.accountPasswd + ' (min 8 characters):');
    if (pw === null) return;
    const res = await apiPost('mail', { action: 'account-passwd', email: b.dataset.accountPasswd, password: pw });
    if (res.ok) { toast('Password changed', 'success'); } else toast(res.error || 'Failed', 'error');
  }));

  // --- Aliases ---
  document.getElementById('alAdd')?.addEventListener('click', async () => {
    const user = document.getElementById('alUser').value.trim();
    const dest = document.getElementById('alDest').value.trim();
    if (!user || !dest) { toast('Enter an alias and destination', 'error'); return; }
    const res = await apiPost('mail', { action: 'alias-add', from: user + '@' + domain, to: dest });
    if (res.ok) { toast(res.warning || 'Alias added', res.warning ? 'warning' : 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  });
  document.querySelectorAll('[data-alias-delete-from]').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Delete this alias?')) return;
    const res = await apiPost('mail', { action: 'alias-delete', from: b.dataset.aliasDeleteFrom, to: b.dataset.aliasDeleteTo });
    if (res.ok) { toast('Alias deleted', 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  }));

  // --- DNS ---
  document.getElementById('dnsPublish')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.disabled = true;
    const res = await apiPost('mail', { action: 'dns-publish', domain: btn.dataset.domain });
    if (res.ok) { toast(res.published === false ? (res.warning || 'Saved') : 'DNS records published', res.published === false ? 'warning' : 'success'); reload(); }
    else { toast(res.error || 'Failed', 'error'); btn.disabled = false; }
  });
  document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
    navigator.clipboard?.writeText(b.dataset.copy).then(() => toast('Copied', 'success'), () => toast('Copy failed', 'error'));
  }));
});
</script>
