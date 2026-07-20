<?php
require_once APP_ROOT . '/lib/mod_docker.php';
$available = dk_available();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Docker</h1>
    <p class="page-subtitle">Manage containers and images</p>
  </div>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state">
    <div class="es-icon"><i data-lucide="box"></i></div>
    <div style="font-weight:600;color:var(--text-secondary)">Docker not installed</div>
    <div style="font-size:13px;margin-top:4px">The <span class="mono">docker</span> command was not found on this system.</div>
  </div></div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Containers</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Image</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="dkContainers">
          <tr><td colspan="4" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Images</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Repository:Tag</th><th>Size</th><th style="text-align:right">Actions</th></tr></thead>
        <tbody id="dkImages">
          <tr><td colspan="3" class="text-tertiary" style="text-align:center;padding:24px">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="muted" style="font-size:12px;margin-top:12px">
    Requires a sudoers rule allowing <span class="mono">sudo docker</span>.
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const { apiGet, apiPost, toast } = window.Nebula;

    function badge(state) {
      const cls = state === 'running' ? 'badge-emerald' : 'badge-slate';
      const span = document.createElement('span');
      span.className = 'badge ' + cls;
      const dot = document.createElement('span');
      dot.className = 'bdot';
      span.appendChild(dot);
      return span;
    }

    function actionBtn(cls, icon, title, id, op) {
      const b = document.createElement('button');
      b.className = 'btn ' + cls + ' btn-sm';
      b.title = title;
      b.dataset.dkId = id;
      b.dataset.dkOp = op;
      const i = document.createElement('i');
      i.setAttribute('data-lucide', icon);
      b.appendChild(i);
      return b;
    }

    async function load() {
      let data;
      try { data = await apiGet('docker'); }
      catch (e) { toast('Failed to load Docker data', 'error'); return; }

      const cbody = document.getElementById('dkContainers');
      cbody.innerHTML = '';
      const containers = data.containers || [];
      if (!containers.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 4; td.className = 'text-tertiary';
        td.style.textAlign = 'center'; td.style.padding = '24px';
        td.textContent = 'No containers.';
        tr.appendChild(td); cbody.appendChild(tr);
      } else {
        containers.forEach((c) => {
          const tr = document.createElement('tr');
          const tdName = document.createElement('td');
          tdName.style.fontWeight = '600';
          tdName.textContent = c.name;
          const tdImage = document.createElement('td');
          tdImage.className = 'mono'; tdImage.style.fontSize = '12.5px';
          tdImage.textContent = c.image;
          const tdStatus = document.createElement('td');
          const b = badge(c.state);
          b.appendChild(document.createTextNode(c.status || c.state));
          tdStatus.appendChild(b);
          const tdActions = document.createElement('td');
          tdActions.style.textAlign = 'right';
          tdActions.appendChild(actionBtn('btn-secondary', 'play', 'Start', c.id, 'start'));
          tdActions.appendChild(actionBtn('btn-secondary', 'rotate-cw', 'Restart', c.id, 'restart'));
          tdActions.appendChild(actionBtn('btn-secondary', 'square', 'Stop', c.id, 'stop'));
          tdActions.appendChild(actionBtn('btn-danger', 'trash-2', 'Remove', c.id, 'remove'));
          tr.appendChild(tdName); tr.appendChild(tdImage);
          tr.appendChild(tdStatus); tr.appendChild(tdActions);
          cbody.appendChild(tr);
        });
      }

      const ibody = document.getElementById('dkImages');
      ibody.innerHTML = '';
      const images = data.images || [];
      if (!images.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3; td.className = 'text-tertiary';
        td.style.textAlign = 'center'; td.style.padding = '24px';
        td.textContent = 'No images.';
        tr.appendChild(td); ibody.appendChild(tr);
      } else {
        images.forEach((im) => {
          const tr = document.createElement('tr');
          const tdRepo = document.createElement('td');
          tdRepo.className = 'mono'; tdRepo.style.fontSize = '12.5px';
          tdRepo.textContent = (im.repo || '') + ':' + (im.tag || '');
          const tdSize = document.createElement('td');
          tdSize.textContent = im.size;
          const tdActions = document.createElement('td');
          tdActions.style.textAlign = 'right';
          const rm = actionBtn('btn-danger', 'trash-2', 'Remove', im.id, 'image_remove');
          tdActions.appendChild(rm);
          tr.appendChild(tdRepo); tr.appendChild(tdSize); tr.appendChild(tdActions);
          ibody.appendChild(tr);
        });
      }

      if (window.lucide) lucide.createIcons();
    }

    document.getElementById('dkContainers')?.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-dk-op]');
      if (!btn) return;
      const id = btn.dataset.dkId;
      const op = btn.dataset.dkOp;
      if (op === 'remove' && !confirm('Remove this container?')) return;
      const res = await apiPost('docker', { action: 'container', id, op });
      if (res.ok) { toast('Done', 'success'); load(); }
      else toast(res.error || 'Failed', 'error');
    });

    document.getElementById('dkImages')?.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-dk-op]');
      if (!btn) return;
      if (!confirm('Remove this image?')) return;
      const res = await apiPost('docker', { action: 'image_remove', id: btn.dataset.dkId });
      if (res.ok) { toast('Image removed', 'success'); load(); }
      else toast(res.error || 'Failed', 'error');
    });

    load();
  });
  </script>
<?php endif; ?>
