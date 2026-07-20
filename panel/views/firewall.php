<?php
require_once APP_ROOT . '/lib/mod_firewall.php';
$available = fw_available();
$status = $available ? fw_status() : null;
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Firewall</h1>
    <p class="page-subtitle">UFW rules</p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="shield-off"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">ufw is not available</div>
    <div style="font-size:13px;margin-top:4px">The <span class="mono">ufw</span> command was not found on this system.</div>
  </div></div>
<?php else: ?>
  <?php if (!$status['ok']): ?>
    <div class="card"><div class="empty-state">
      <div class="es-icon"><i data-lucide="shield-alert"></i></div>
      <div style="font-weight:600;color:var(--text-secondary)">Could not read firewall status</div>
      <div style="font-size:13px;margin-top:4px"><?= e($status['error']) ?></div>
    </div></div>
  <?php else: ?>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><h3>Status</h3></div>
      <div class="card-pad">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <?php if ($status['active']): ?>
            <span class="badge badge-emerald"><span class="bdot"></span>Active</span>
          <?php else: ?>
            <span class="badge badge-slate"><span class="bdot"></span>Inactive</span>
          <?php endif; ?>
          <button class="btn btn-secondary btn-sm" data-fw="enable"><i data-lucide="shield-check"></i>Enable</button>
          <button class="btn btn-danger btn-sm" data-fw="disable"><i data-lucide="shield-x"></i>Disable</button>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><h3>Add rule</h3></div>
      <div class="card-pad">
        <div class="grid" style="grid-template-columns:160px 1fr 160px auto;gap:12px;align-items:end">
          <div>
            <label class="field-label">Action</label>
            <select class="input" id="fwAction">
              <option value="allow">allow</option>
              <option value="deny">deny</option>
              <option value="reject">reject</option>
            </select>
          </div>
          <div>
            <label class="field-label">Port / service</label>
            <input class="input mono" id="fwPort" placeholder="22 or http">
          </div>
          <div>
            <label class="field-label">Protocol</label>
            <select class="input" id="fwProto">
              <option value="tcp">tcp</option>
              <option value="udp">udp</option>
              <option value="any">any</option>
            </select>
          </div>
          <button class="btn btn-primary" id="fwAdd"><i data-lucide="plus"></i>Add rule</button>
        </div>
        <div style="font-size:12px;color:var(--text-tertiary);margin-top:10px">
          Requires a sudoers rule for ufw.
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>Rules</h3></div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th style="width:60px">#</th><th>Rule</th><th style="text-align:right">Actions</th></tr></thead>
          <tbody id="fwBody">
            <?php foreach ($status['rules'] as $r): ?>
              <tr data-num="<?= (int) $r['num'] ?>">
                <td class="mono" style="color:var(--blue-400)"><?= (int) $r['num'] ?></td>
                <td class="mono" style="font-size:12.5px"><?= e($r['raw']) ?></td>
                <td style="text-align:right"><button class="btn btn-danger btn-sm" data-fw-del="<?= (int) $r['num'] ?>"><i data-lucide="trash-2"></i></button></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$status['rules']): ?>
              <tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">No firewall rules yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const { apiPost, toast } = window.Nebula;
  document.querySelectorAll('[data-fw]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.fw;
      const res = await apiPost('firewall', { action });
      if (res.ok) { toast('Firewall ' + action + 'd', 'success'); setTimeout(() => location.reload(), 500); }
      else toast(res.error || 'Failed', 'error');
    });
  });
  document.getElementById('fwAdd')?.addEventListener('click', async () => {
    const ufwAction = document.getElementById('fwAction').value;
    const port = document.getElementById('fwPort').value;
    const proto = document.getElementById('fwProto').value;
    const res = await apiPost('firewall', { action: 'add', ufwAction, port, proto });
    if (res.ok) { toast('Rule added', 'success'); setTimeout(() => location.reload(), 500); }
    else toast(res.error || 'Failed', 'error');
  });
  document.querySelectorAll('[data-fw-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this firewall rule?')) return;
      const res = await apiPost('firewall', { action: 'delete', num: +btn.dataset.fwDel });
      if (res.ok) { toast('Deleted', 'success'); btn.closest('tr').remove(); }
      else toast(res.error || 'Failed', 'error');
    });
  });
  if (window.lucide) lucide.createIcons();
});
</script>
