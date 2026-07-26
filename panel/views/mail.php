<?php
require_once APP_ROOT . '/lib/mod_mail.php';
require_once APP_ROOT . '/lib/mod_sites.php';

$status    = mail_status();
$installed = (bool) ($status['installed'] ?? false);
$helper    = (bool) ($status['helper'] ?? false);
$state     = mail_state();
$domains   = array_keys($state['domains']);
$wmInstalled = mail_webmail_installed();
$wmUrl       = mail_webmail()['url'] ?? null;
$wmLabel     = mail_webmail_label();

$selected = (string) ($_GET['domain'] ?? ($domains[0] ?? ''));
if (!in_array($selected, $domains, true)) {
    $selected = (string) ($domains[0] ?? '');
}
$managedZones = array_map(fn($s) => (string) ($s['domain'] ?? ''), sites_list());

$svcBadge = static function (string $v): string {
    return $v === 'active' ? 'badge-emerald' : ($v === 'inactive' ? 'badge-slate' : 'badge-orange');
};
$svcState = static function (string $v): string {
    return $v === 'active' ? 'ok' : ($v === 'inactive' ? 'off' : 'warn');
};
$dnsBadge = ['A' => 'badge-blue', 'AAAA' => 'badge-purple', 'CNAME' => 'badge-emerald', 'MX' => 'badge-orange', 'TXT' => 'badge-slate'];

$mailboxes  = $selected === '' ? [] : array_values(array_filter($state['accounts'], fn($a) => str_ends_with((string) ($a['email'] ?? ''), '@' . $selected)));
$aliases    = $selected === '' ? [] : array_values(array_filter($state['aliases'], fn($a) => str_ends_with((string) ($a['from'] ?? ''), '@' . $selected)));
$dnsRecords = $selected === '' ? [] : mail_dns_records($selected);
$totalMb = count($state['accounts']);
?>
<style>
.mail-page .stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:4px}
.mail-page .stat{background:var(--bg-surface-2);border:1px solid var(--border-subtle);border-radius:var(--radius-md);padding:14px 16px;display:flex;flex-direction:column;gap:6px}
.mail-page .stat .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);display:flex;align-items:center;gap:6px}
.mail-page .stat .lbl svg{width:14px;height:14px}
.mail-page .stat .val{font-size:18px;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:8px}
.mail-page .dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
.mail-page .dot.ok{background:var(--emerald-400);box-shadow:0 0 0 3px var(--emerald-glow)}
.mail-page .dot.off{background:var(--slate-500)}
.mail-page .dot.warn{background:var(--orange-400);box-shadow:0 0 0 3px var(--orange-glow)}
.mail-page .domain-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.mail-page .domain-chips{display:flex;gap:6px;flex-wrap:wrap}
.mail-page .dns-note{font-size:11px;color:var(--text-tertiary);margin-top:3px}
.mail-page h4.sec{margin:2px 0 12px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-tertiary)}
</style>
<div class="mail-page">
<div class="page-header">
  <div>
    <h1 class="page-title">Email</h1>
    <p class="page-subtitle">Self-hosted mail — Postfix · Dovecot · OpenDKIM · Webmail</p>
  </div>
  <?php if ($installed && $wmInstalled && $wmUrl): ?>
    <a class="btn btn-primary" href="<?= e($wmUrl) ?>" target="_blank" rel="noopener"><i data-lucide="mail"></i>Open Webmail</a>
  <?php endif; ?>
</div>

<?php if (!$helper): ?>
  <div class="notice notice-warning" style="margin-bottom:16px"><i data-lucide="shield-alert"></i>
    <div><strong>Privileged helper required</strong><div>The <span class="mono">nebula-helper</span> is not installed. Re-run <span class="mono">install.sh</span> to enable email management.</div></div>
  </div>
<?php endif; ?>

<?php if (!$installed): ?>
  <!-- ===== Not installed: setup ===== -->
  <div class="card">
    <div class="card-header"><h3>Mail server</h3><span class="badge badge-slate">Not installed</span></div>
    <div class="card-pad">
      <p style="color:var(--text-secondary);margin:0 0 16px;max-width:70ch">
        Install and auto-configure a complete mail stack in one click — <strong>Postfix</strong> (SMTP + submission),
        <strong>Dovecot</strong> (IMAP/POP3 with authenticated sending), and <strong>OpenDKIM</strong> signing.
        Mailboxes are virtual and file-backed, so there's no database to configure. This installs packages and may
        take a few minutes.
      </p>
      <button class="btn btn-primary" id="mailSetup"<?= $helper ? '' : ' disabled' ?>><i data-lucide="download"></i>Install &amp; configure mail server</button>
      <div class="card hidden" id="mailSetupLogCard" style="margin-top:16px">
        <div class="card-header"><h3>Setup output</h3></div>
        <pre class="mono" id="mailSetupLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:40vh;overflow:auto"></pre>
      </div>
    </div>
  </div>
<?php else: ?>
  <!-- ===== Installed: tabbed manager ===== -->
  <div class="card" style="margin-bottom:16px"><div class="card-pad">
    <div class="domain-bar">
      <div class="domain-chips">
        <?php foreach ($domains as $d): ?>
          <a class="btn btn-sm <?= $d === $selected ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(url('mail', ['domain' => $d])) ?>"><i data-lucide="globe"></i><?= e($d) ?></a>
        <?php endforeach; ?>
        <?php if (!$domains): ?><span class="muted" style="font-size:13px">No mail domains yet — add one to create mailboxes.</span><?php endif; ?>
      </div>
      <div style="flex:1 1 auto"></div>
      <input class="input mono" id="domainInput" placeholder="example.com" style="max-width:200px">
      <button class="btn btn-primary btn-sm" id="domainAdd"><i data-lucide="plus"></i>Add domain</button>
      <?php if ($selected !== ''): ?><button class="btn btn-danger btn-sm" data-domain-delete="<?= e($selected) ?>"><i data-lucide="trash-2"></i>Delete <?= e($selected) ?></button><?php endif; ?>
    </div>
  </div></div>

  <div class="card" style="margin-bottom:16px">
    <div class="tabs" role="tablist">
      <button class="tab active" type="button" data-tab-target="mail-overview" data-tab-panel-group="#mailPanels"><i data-lucide="gauge"></i>Overview</button>
      <button class="tab" type="button" data-tab-target="mail-stats" data-tab-panel-group="#mailPanels"><i data-lucide="bar-chart-3"></i>Stats</button>
      <button class="tab" type="button" data-tab-target="mail-boxes" data-tab-panel-group="#mailPanels"><i data-lucide="inbox"></i>Mailboxes</button>
      <button class="tab" type="button" data-tab-target="mail-aliases" data-tab-panel-group="#mailPanels"><i data-lucide="forward"></i>Aliases</button>
      <button class="tab" type="button" data-tab-target="mail-dns" data-tab-panel-group="#mailPanels"><i data-lucide="shield-check"></i>DNS &amp; DKIM</button>
      <button class="tab" type="button" data-tab-target="mail-webmail" data-tab-panel-group="#mailPanels"><i data-lucide="mail"></i>Webmail</button>
    </div>

    <div id="mailPanels" data-tab-panels>

      <!-- Overview -->
      <div id="mail-overview" data-tab-panel class="card-pad">
        <div class="stat-row">
          <div class="stat"><span class="lbl"><i data-lucide="send"></i>Postfix (SMTP)</span><span class="val"><span class="dot <?= e($svcState((string) $status['postfix'])) ?>"></span><?= e(ucfirst((string) $status['postfix'])) ?></span></div>
          <div class="stat"><span class="lbl"><i data-lucide="inbox"></i>Dovecot (IMAP)</span><span class="val"><span class="dot <?= e($svcState((string) $status['dovecot'])) ?>"></span><?= e(ucfirst((string) $status['dovecot'])) ?></span></div>
          <div class="stat"><span class="lbl"><i data-lucide="shield-check"></i>OpenDKIM</span><span class="val"><span class="dot <?= e($svcState((string) $status['opendkim'])) ?>"></span><?= e(ucfirst((string) $status['opendkim'])) ?></span></div>
          <div class="stat"><span class="lbl"><i data-lucide="at-sign"></i>Mailboxes</span><span class="val"><?= (int) $totalMb ?> · <?= count($domains) ?> domain<?= count($domains) === 1 ? '' : 's' ?></span></div>
        </div>
        <div class="muted" style="font-size:13px;margin:16px 0 4px">
          Mail host <span class="mono"><?= e((string) $status['hostname']) ?></span><?php if (!empty($status['ip'])): ?> · IP <span class="mono"><?= e((string) $status['ip']) ?></span><?php endif; ?><br>
          Clients: IMAP <span class="mono">143 (STARTTLS)</span> · POP3 <span class="mono">110</span> · SMTP submission <span class="mono">587</span> · users log in with their full email address.
        </div>
        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-subtle);display:flex;gap:10px;flex-wrap:wrap">
          <button class="btn btn-secondary" id="mailReconfigure"><i data-lucide="refresh-cw"></i>Reconfigure / repair server</button>
          <button class="btn btn-secondary" id="mailReapply"><i data-lucide="upload"></i>Re-sync mailboxes to server</button>
          <button class="btn btn-secondary" id="mailDiag"><i data-lucide="stethoscope"></i>Diagnose login failures</button>
        </div>
        <div class="field-help" style="margin-top:8px"><strong>Reconfigure</strong> re-applies the Postfix/Dovecot/OpenDKIM config (use it if clients can't log in after an upgrade). <strong>Diagnose</strong> shows the real Dovecot reason behind “Login failed” / “Temporary authentication failure”.</div>
        <div class="card hidden" id="mailSetupLogCard" style="margin-top:16px">
          <div class="card-header"><h3>Output</h3></div>
          <pre class="mono" id="mailSetupLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:40vh;overflow:auto"></pre>
        </div>
        <div class="card hidden" id="mailDiagCard" style="margin-top:16px">
          <div class="card-header"><h3>Mail diagnostics</h3></div>
          <pre class="mono" id="mailDiagOut" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:50vh;overflow:auto"></pre>
        </div>
      </div>

      <!-- Stats -->
      <div id="mail-stats" data-tab-panel class="hidden card-pad">
        <div class="flex items-center gap-2" style="flex-wrap:wrap;margin-bottom:14px">
          <span class="field-label" style="margin:0">Period</span>
          <select class="select" id="statsDays" style="width:auto">
            <option value="7">Last 7 days</option>
            <option value="30" selected>Last 30 days</option>
            <option value="90">Last 90 days</option>
          </select>
          <button class="btn btn-secondary btn-sm" id="statsRefresh"><i data-lucide="refresh-cw"></i>Refresh</button>
          <span class="muted" id="statsSource" style="font-size:12px"></span>
        </div>
        <div id="statsError" class="notice notice-warning hidden" style="margin-bottom:14px"><i data-lucide="alert-triangle"></i><div id="statsErrorText"></div></div>
        <div class="stat-row" style="margin-bottom:16px">
          <div class="stat"><span class="lbl"><i data-lucide="send"></i>Sent</span><span class="val" id="stSent">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="inbox"></i>Received</span><span class="val" id="stRecv">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="undo-2"></i>Bounced</span><span class="val" id="stBounced">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="clock"></i>Deferred</span><span class="val" id="stDeferred">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="shield-x"></i>Rejected</span><span class="val" id="stRejected">–</span></div>
        </div>
        <div class="stat-row" style="margin-bottom:16px">
          <div class="stat"><span class="lbl"><i data-lucide="log-in"></i>Mailbox logins</span><span class="val" id="stLogins">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="key-round"></i>Failed logins</span><span class="val" id="stAuthFail">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="layers"></i>Queued now</span><span class="val" id="stQueue">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="hard-drive"></i>Mail stored</span><span class="val" id="stStored">–</span></div>
          <div class="stat"><span class="lbl"><i data-lucide="arrow-up-down"></i>Traffic</span><span class="val" id="stTraffic">–</span></div>
        </div>
        <div class="card" style="margin-bottom:16px">
          <div class="card-header"><h3>Delivery activity</h3><span class="muted" id="statsChartHint">Messages per day</span></div>
          <div class="card-pad"><div style="height:260px"><canvas id="mailChart"></canvas></div></div>
        </div>
        <div class="grid grid-2" style="gap:14px">
          <div class="card"><div class="card-header"><h3>Top senders</h3><span class="muted">outbound</span></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Address</th><th style="text-align:right">Messages</th></tr></thead><tbody id="stSenders"></tbody></table></div></div>
          <div class="card"><div class="card-header"><h3>Top recipients</h3><span class="muted">inbound</span></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Address</th><th style="text-align:right">Messages</th></tr></thead><tbody id="stRecipients"></tbody></table></div></div>
        </div>
        <div class="card" style="margin-top:14px"><div class="card-header"><h3>Mailbox storage</h3><span class="muted">on-disk maildirs</span></div>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Mailbox</th><th>Messages</th><th>Unread</th><th style="text-align:right">Size</th></tr></thead><tbody id="stMailboxes"></tbody></table></div></div>
        <div class="card" style="margin-top:14px"><div class="card-header"><h3>Delivery problems</h3><span class="muted">bounced · deferred · rejected</span></div>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>When</th><th>Type</th><th>Address</th><th>Reason</th></tr></thead><tbody id="stProblems"></tbody></table></div></div>
      </div>

      <!-- Mailboxes -->
      <div id="mail-boxes" data-tab-panel class="hidden card-pad">
        <?php if ($selected === ''): ?>
          <div class="empty-state"><div class="es-icon"><i data-lucide="inbox"></i></div><div style="font-weight:600">Add a mail domain to get started</div><div style="font-size:13px;margin-top:4px">Enter a domain above, then create mailboxes on it.</div></div>
        <?php else: ?>
          <h4 class="sec">Mailboxes on <?= e($selected) ?></h4>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Address</th><th>Created</th><th></th></tr></thead><tbody>
            <?php foreach ($mailboxes as $m): ?>
              <tr>
                <td class="mono"><?= e((string) $m['email']) ?></td>
                <td class="mono text-tertiary"><?= e(substr((string) ($m['created'] ?? ''), 0, 10)) ?></td>
                <td style="text-align:right;white-space:nowrap">
                  <div class="flex items-center" style="justify-content:flex-end;gap:2px">
                    <button class="icon-btn" data-account-passwd="<?= e((string) $m['email']) ?>" title="Change password"><i data-lucide="key-round"></i></button>
                    <button class="icon-btn" style="color:var(--red-400)" data-account-delete="<?= e((string) $m['email']) ?>" title="Delete mailbox"><i data-lucide="trash-2"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$mailboxes): ?><tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">No mailboxes yet — add one below.</td></tr><?php endif; ?>
          </tbody><tfoot><tr>
            <td><div class="flex items-center gap-1"><input class="input mono" id="mbUser" placeholder="user" style="max-width:130px"><span class="mono muted">@<?= e($selected) ?></span></div></td>
            <td><input class="input" id="mbPass" type="password" placeholder="Password (min 8)"></td>
            <td style="text-align:right"><button class="btn btn-primary btn-sm" id="mbAdd"><i data-lucide="plus"></i>Add mailbox</button></td>
          </tr></tfoot></table></div>
        <?php endif; ?>
      </div>

      <!-- Aliases -->
      <div id="mail-aliases" data-tab-panel class="hidden card-pad">
        <?php if ($selected === ''): ?>
          <div class="empty-state"><div class="es-icon"><i data-lucide="forward"></i></div><div style="font-weight:600">No domains yet</div><div style="font-size:13px;margin-top:4px">Add a mail domain to create aliases and forwarders.</div></div>
        <?php else: ?>
          <h4 class="sec">Aliases &amp; forwarders on <?= e($selected) ?></h4>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Alias address</th><th>Forwards to</th><th></th></tr></thead><tbody>
            <?php foreach ($aliases as $a): ?>
              <tr>
                <td class="mono"><?= e((string) $a['from']) ?></td>
                <td class="mono text-tertiary"><?= e((string) $a['to']) ?></td>
                <td style="text-align:right"><button class="icon-btn" style="color:var(--red-400)" data-alias-delete-from="<?= e((string) $a['from']) ?>" data-alias-delete-to="<?= e((string) $a['to']) ?>" title="Delete alias"><i data-lucide="trash-2"></i></button></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$aliases): ?><tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">No aliases yet — add one below.</td></tr><?php endif; ?>
          </tbody><tfoot><tr>
            <td><div class="flex items-center gap-1"><input class="input mono" id="alUser" placeholder="info" style="max-width:130px"><span class="mono muted">@<?= e($selected) ?></span></div></td>
            <td><input class="input mono" id="alDest" placeholder="destination@example.com"></td>
            <td style="text-align:right"><button class="btn btn-primary btn-sm" id="alAdd"><i data-lucide="plus"></i>Add alias</button></td>
          </tr></tfoot></table></div>
        <?php endif; ?>
      </div>

      <!-- DNS & DKIM -->
      <div id="mail-dns" data-tab-panel class="hidden card-pad">
        <?php if ($selected === ''): ?>
          <div class="empty-state"><div class="es-icon"><i data-lucide="shield-check"></i></div><div style="font-weight:600">No domains yet</div><div style="font-size:13px;margin-top:4px">Add a mail domain to see its deliverability records.</div></div>
        <?php else: ?>
          <div class="notice notice-warning" style="margin-bottom:14px"><i data-lucide="info"></i>
            <div><strong>Add these records wherever <?= e($selected) ?>'s DNS is hosted.</strong>
              <div>MX + SPF + DKIM + DMARC together let this server send mail that lands in the inbox.<?php if (in_array($selected, $managedZones, true)): ?> Since this is a panel-managed zone, you can publish them automatically.<?php endif; ?></div></div>
          </div>
          <div class="table-wrap"><table class="data-table"><thead><tr><th>Type</th><th>Name</th><th>Value</th><th></th></tr></thead><tbody>
            <?php foreach ($dnsRecords as $r): $val = ($r['type'] === 'MX' ? ((int) $r['priority'] . ' ') : '') . $r['value']; ?>
              <tr>
                <td><span class="badge <?= e($dnsBadge[$r['type']] ?? 'badge-slate') ?>"><?= e($r['type']) ?></span></td>
                <td class="mono"><?= e((string) $r['name']) ?></td>
                <td class="mono text-tertiary" style="max-width:460px;white-space:normal;word-break:break-all"><?= e($val) ?><div class="dns-note"><?= e((string) ($r['note'] ?? '')) ?></div></td>
                <td style="text-align:right"><button class="icon-btn" data-copy="<?= e($val) ?>" title="Copy value"><i data-lucide="copy"></i></button></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <?php if (in_array($selected, $managedZones, true)): ?>
            <div style="margin-top:14px"><button class="btn btn-secondary" id="dnsPublish" data-domain="<?= e($selected) ?>"><i data-lucide="upload-cloud"></i>Publish these records to the DNS zone</button></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Webmail -->
      <div id="mail-webmail" data-tab-panel class="hidden card-pad">
        <?php if ($wmInstalled): ?>
          <div class="stat-row" style="grid-template-columns:1fr"><div class="stat">
            <span class="lbl"><i data-lucide="mail"></i><?= e($wmLabel) ?> webmail</span>
            <span class="val"><span class="dot ok"></span>Installed</span>
          </div></div>
          <div style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <a class="btn btn-primary" href="<?= e((string) $wmUrl) ?>" target="_blank" rel="noopener"><i data-lucide="external-link"></i>Open Webmail</a>
            <span class="mono" style="color:var(--blue-400)"><?= e((string) $wmUrl) ?></span>
          </div>
          <div class="muted" style="font-size:13px;margin-top:12px">Users log in with their full email address and mailbox password.</div>
          <?php if ((mail_webmail()['kind'] ?? '') === 'snappymail'): ?>
            <div class="notice notice-warning" style="margin-top:14px"><i data-lucide="info"></i>
              <div><strong>SnappyMail is no longer supported by this panel.</strong>
                <div>It keeps working, but the panel now installs Roundcube only. Remove it below to install Roundcube instead — mail itself is unaffected, since mailboxes live in Dovecot rather than in the webmail client.</div></div>
            </div>
          <?php endif; ?>
          <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border-subtle)">
            <button class="btn btn-danger btn-sm" id="wmRemove"><i data-lucide="trash-2"></i>Remove <?= e($wmLabel) ?></button>
          </div>
        <?php else: ?>
          <p style="color:var(--text-secondary);margin:0 0 16px;max-width:70ch">Give your users a browser-based inbox. Roundcube installs to its own random URL, pre-configured against this server's local IMAP/SMTP with zero-config SQLite storage — users just log in with their full email address and mailbox password.</p>
          <div class="card" style="max-width:420px"><div class="card-pad">
            <div class="flex items-center gap-2" style="margin-bottom:6px"><i data-lucide="mail"></i><strong>Roundcube</strong><span class="badge badge-emerald">Webmail</span></div>
            <p class="muted" style="font-size:13px;margin:0 0 12px">The long-standing classic webmail client, installed and configured for this mail server in one click.</p>
            <button class="btn btn-primary" id="rcInstall"><i data-lucide="download"></i>Install Roundcube</button>
          </div></div>
          <div class="card hidden" id="wmLogCard" style="margin-top:16px">
            <div class="card-header"><h3>Install output</h3></div>
            <pre class="mono" id="wmLog" style="margin:0;padding:16px;font-size:12px;line-height:1.55;white-space:pre-wrap;max-height:40vh;overflow:auto"></pre>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
<?php endif; /* installed */ ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, streamPost, toast, copyText } = window.Nebula;
  const domain = <?= json_encode($selected) ?>;
  const reload = (ms = 500) => setTimeout(() => location.reload(), ms);

  // Keep the active tab after a reload. Deferred so it runs after app.js has
  // wired the tab click handlers (its DOMContentLoaded listener runs after ours).
  document.querySelectorAll('.tabs .tab[data-tab-target^="mail-"]').forEach(t =>
    t.addEventListener('click', () => sessionStorage.setItem('mailTab', t.dataset.tabTarget)));
  setTimeout(() => {
    const savedTab = sessionStorage.getItem('mailTab');
    if (savedTab && savedTab !== 'mail-overview') document.querySelector('[data-tab-target="' + savedTab + '"]')?.click();
  }, 0);

  async function runStream(btn, action, logId, cardId, okMsg, restoreHtml) {
    btn.disabled = true; const orig = btn.innerHTML; btn.textContent = 'Working…';
    const card = document.getElementById(cardId), log = document.getElementById(logId);
    card?.classList.remove('hidden'); if (log) log.textContent = '';
    const res = await streamPost('mail', { action }, (ev) => {
      if (ev.type === 'output' && ev.text && log) { log.textContent += ev.text; log.scrollTop = log.scrollHeight; }
    });
    if (res.ok) { toast(okMsg, 'success'); reload(800); }
    else { toast(res.error || 'Failed', 'error'); if (log && res.error) log.textContent += '\n' + res.error + '\n'; btn.disabled = false; btn.innerHTML = restoreHtml || orig; if (window.lucide) lucide.createIcons(); }
  }

  document.getElementById('mailSetup')?.addEventListener('click', (e) =>
    runStream(e.currentTarget, 'setup', 'mailSetupLog', 'mailSetupLogCard', 'Mail server installed', '<i data-lucide="download"></i>Install &amp; configure mail server'));
  document.getElementById('mailReconfigure')?.addEventListener('click', (e) => {
    if (!confirm('Re-apply the mail server configuration now?')) return;
    runStream(e.currentTarget, 'setup', 'mailSetupLog', 'mailSetupLogCard', 'Mail server reconfigured', '<i data-lucide="refresh-cw"></i>Reconfigure / repair server');
  });

  document.getElementById('mailReapply')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget, orig = btn.innerHTML;
    btn.disabled = true; btn.textContent = 'Syncing…';
    const res = await apiPost('mail', { action: 'reapply' });
    toast(res.ok ? 'Mailboxes re-synced to the mail server' : (res.error || 'Re-sync failed'), res.ok ? 'success' : 'error');
    btn.disabled = false; btn.innerHTML = orig; if (window.lucide) lucide.createIcons();
  });

  document.getElementById('mailDiag')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget, orig = btn.innerHTML;
    const card = document.getElementById('mailDiagCard'), out = document.getElementById('mailDiagOut');
    btn.disabled = true; btn.textContent = 'Running…'; card.classList.remove('hidden'); out.textContent = 'Collecting diagnostics…';
    const res = await apiPost('mail', { action: 'diag' });
    out.textContent = res.ok ? (res.output || '(no output)') : (res.error || 'Diagnostics failed');
    btn.disabled = false; btn.innerHTML = orig; if (window.lucide) lucide.createIcons();
  });

  document.getElementById('rcInstall')?.addEventListener('click', (e) =>
    runStream(e.currentTarget, 'roundcube-install', 'wmLog', 'wmLogCard', 'Roundcube installed', '<i data-lucide="download"></i>Install Roundcube'));
  document.getElementById('wmRemove')?.addEventListener('click', async () => {
    if (!confirm('Remove the installed webmail client? Its files will be deleted.')) return;
    const res = await apiPost('mail', { action: 'webmail-remove' });
    if (res.ok) { toast('Webmail removed', 'success'); reload(); } else toast(res.error || 'Failed', 'error');
  });

  // Domains
  document.getElementById('domainAdd')?.addEventListener('click', async () => {
    const val = document.getElementById('domainInput').value.trim();
    if (!val) return;
    const res = await apiPost('mail', { action: 'domain-add', domain: val });
    if (res.ok) { toast(res.warning || 'Domain added', res.warning ? 'warning' : 'success'); setTimeout(() => location = <?= json_encode(url('mail')) ?> + '&domain=' + encodeURIComponent(val), 400); }
    else toast(res.error || 'Failed', 'error');
  });
  document.getElementById('domainInput')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') document.getElementById('domainAdd').click(); });
  document.querySelectorAll('[data-domain-delete]').forEach(b => b.addEventListener('click', async () => {
    const d = b.dataset.domainDelete;
    if (!confirm('Delete mail domain ' + d + '? Its mailboxes and aliases will be removed.')) return;
    const res = await apiPost('mail', { action: 'domain-delete', domain: d });
    if (res.ok) { toast('Domain deleted', 'success'); location = <?= json_encode(url('mail')) ?>; } else toast(res.error || 'Failed', 'error');
  }));

  // Mailboxes
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

  // Aliases
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

  // Stats — loaded on demand; parsing the mail log is not free.
  let statsChart = null, statsLoaded = false;
  const fmtBytes = (n) => {
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; let v = n;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return (v >= 10 || i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + u[i];
  };
  const mkRow = (cells) => {
    const tr = document.createElement('tr');
    cells.forEach(([text, cls, style]) => {
      const td = document.createElement('td');
      td.textContent = text; if (cls) td.className = cls; if (style) td.style.cssText = style;
      tr.append(td);
    });
    return tr;
  };
  const emptyRow = (body, cols, text) => {
    const tr = document.createElement('tr'), td = document.createElement('td');
    td.colSpan = cols; td.className = 'text-tertiary'; td.style.cssText = 'text-align:center;padding:22px'; td.textContent = text;
    tr.append(td); body.append(tr);
  };

  function renderStats(s) {
    const t = s.totals;
    document.getElementById('stSent').textContent = t.sent.toLocaleString();
    document.getElementById('stRecv').textContent = t.received.toLocaleString();
    document.getElementById('stBounced').textContent = t.bounced.toLocaleString();
    document.getElementById('stDeferred').textContent = t.deferred.toLocaleString();
    document.getElementById('stRejected').textContent = t.rejected.toLocaleString();
    document.getElementById('stLogins').textContent = t.logins.toLocaleString();
    document.getElementById('stAuthFail').textContent = t.auth_failed.toLocaleString();
    document.getElementById('stQueue').textContent = s.queue.total + (s.queue.deferred ? ' (' + s.queue.deferred + ' deferred)' : '');
    const stored = s.mailboxes.reduce((a, m) => a + m.bytes, 0);
    document.getElementById('stStored').textContent = fmtBytes(stored);
    document.getElementById('stTraffic').textContent = '↑' + fmtBytes(t.bytes_sent) + ' ↓' + fmtBytes(t.bytes_received);
    document.getElementById('statsSource').textContent = s.source ? 'Source: ' + s.source : '';

    const err = document.getElementById('statsError');
    if (s.warning) { err.classList.remove('hidden'); document.getElementById('statsErrorText').textContent = s.warning; }
    else err.classList.add('hidden');

    const ctx = document.getElementById('mailChart');
    if (ctx && window.Chart) {
      const labels = s.timeline.map(d => d.date.slice(5));
      if (statsChart) statsChart.destroy();
      statsChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label: 'Sent', data: s.timeline.map(d => d.sent), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.10)', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 },
            { label: 'Received', data: s.timeline.map(d => d.received), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.10)', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 },
            { label: 'Rejected', data: s.timeline.map(d => d.rejected), borderColor: '#f97316', tension: .35, pointRadius: 0, borderWidth: 2 },
          ],
        },
        options: {
          maintainAspectRatio: false, animation: false,
          plugins: { legend: { position: 'top', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true } } },
          scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.05)' }, ticks: { precision: 0 } } },
        },
      });
    }

    const senders = document.getElementById('stSenders'); senders.innerHTML = '';
    s.top_senders.forEach(r => senders.append(mkRow([[r.address, 'mono'], [String(r.count), 'mono', 'text-align:right']])));
    if (!s.top_senders.length) emptyRow(senders, 2, 'No outbound mail in this period.');

    const recips = document.getElementById('stRecipients'); recips.innerHTML = '';
    s.top_recipients.forEach(r => recips.append(mkRow([[r.address, 'mono'], [String(r.count), 'mono', 'text-align:right']])));
    if (!s.top_recipients.length) emptyRow(recips, 2, 'No inbound mail in this period.');

    const boxes = document.getElementById('stMailboxes'); boxes.innerHTML = '';
    s.mailboxes.forEach(m => boxes.append(mkRow([
      [m.email, 'mono'], [String(m.messages), 'mono'], [String(m.unread), 'mono'], [fmtBytes(m.bytes), 'mono', 'text-align:right'],
    ])));
    if (!s.mailboxes.length) emptyRow(boxes, 4, 'No maildirs on disk yet.');

    const probs = document.getElementById('stProblems'); probs.innerHTML = '';
    s.recent.forEach(p => probs.append(mkRow([
      [p.time, 'mono text-tertiary'], [p.kind], [p.address, 'mono'], [p.detail, 'text-tertiary', 'white-space:normal'],
    ])));
    if (!s.recent.length) emptyRow(probs, 4, 'No bounced, deferred or rejected mail — nice.');
  }

  async function loadStats() {
    const days = +document.getElementById('statsDays').value;
    const err = document.getElementById('statsError');
    document.getElementById('statsChartHint').textContent = 'Messages per day · loading…';
    const res = await apiPost('mail', { action: 'stats', days });
    document.getElementById('statsChartHint').textContent = 'Messages per day';
    if (!res.ok) {
      err.classList.remove('hidden');
      document.getElementById('statsErrorText').textContent = res.error || 'Could not collect mail statistics.';
      return;
    }
    renderStats(res);
  }
  document.getElementById('statsRefresh')?.addEventListener('click', loadStats);
  document.getElementById('statsDays')?.addEventListener('change', loadStats);
  document.querySelector('[data-tab-target="mail-stats"]')?.addEventListener('click', () => {
    if (!statsLoaded) { statsLoaded = true; loadStats(); }
  });

  // DNS
  document.getElementById('dnsPublish')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget; btn.disabled = true;
    const res = await apiPost('mail', { action: 'dns-publish', domain: btn.dataset.domain });
    if (res.ok) { toast(res.published === false ? (res.warning || 'Saved') : 'DNS records published', res.published === false ? 'warning' : 'success'); reload(); }
    else { toast(res.error || 'Failed', 'error'); btn.disabled = false; }
  });
  document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
    const ok = await copyText(b.dataset.copy);
    toast(ok ? 'Copied' : 'Copy failed', ok ? 'success' : 'error');
  }));
});
</script>
