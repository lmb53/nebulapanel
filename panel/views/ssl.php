<?php
require_once APP_ROOT . '/lib/mod_ssl.php';
require_once APP_ROOT . '/lib/mod_sites.php';
$available = ssl_available();
$certbotAvailable = ssl_certbot_available();
$list = $available ? ssl_list() : ['certs' => []];
$certs = $list['certs'] ?? [];
$loadError = $list['error'] ?? null;
$sslSites = sites_list();
$validCount = count(array_filter($certs, fn($c) => $c['valid'] && ($c['days'] === null || $c['days'] > 14)));
?>
<div class="page-header">
  <div><h1 class="page-title">SSL Certificates</h1><p class="page-subtitle"><?= count($certs) ?> certificate<?= count($certs) === 1 ? '' : 's' ?> · Let's Encrypt and uploaded certificates</p></div>
  <?php if ($certbotAvailable): ?><div class="page-actions"><button class="btn btn-secondary" data-ssl-renew-all><i data-lucide="refresh-cw"></i>Renew all</button></div><?php endif; ?>
</div>

<?php if (!$available): ?>
  <div class="card"><div class="empty-state"><div class="es-icon"><i data-lucide="shield-check"></i></div><div style="font-weight:600;color:var(--text-secondary)">SSL management unavailable</div><div style="font-size:13px;margin-top:4px">The privileged helper is missing. Re-run <span class="mono">install.sh</span>.</div></div></div>
<?php else: ?>
  <?php if ($loadError): ?><div class="card" style="margin-bottom:16px"><div class="card-pad" style="color:var(--red-400);font-size:13px">Could not read certificates: <span class="mono"><?= e($loadError) ?></span></div></div><?php endif; ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-header"><h3>Certificates</h3><span class="muted"><?= count($certs) ?> total · <?= (int) $validCount ?> healthy</span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Domain(s)</th><th>Issuer</th><th>Expires</th><th>Renewal</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php if (!$certs): ?><tr><td colspan="6" class="text-tertiary" style="text-align:center;padding:28px">No certificates yet. Issue or upload one below.</td></tr><?php endif; ?>
      <?php foreach ($certs as $cert): ?>
        <?php $name=(string)$cert['name'];$days=$cert['days'];$valid=(bool)$cert['valid'];$custom=!empty($cert['custom']);if(!$valid){$badgeClass='badge-red';$badgeText='Expired';}elseif($days!==null&&$days<=14){$badgeClass='badge-orange';$badgeText='Expiring';}else{$badgeClass='badge-emerald';$badgeText='Valid';} ?>
        <tr>
          <td><div style="font-weight:600"><?= e($name) ?></div><?php if (!empty($cert['domains']) && $cert['domains'] !== $name): ?><div class="mono text-tertiary" style="font-size:11.5px;margin-top:3px"><?= e($cert['domains']) ?></div><?php endif; ?></td>
          <td><?= e((string) ($cert['issuer'] ?? "Let's Encrypt")) ?></td>
          <td><div class="mono"><?= e($cert['expiry'] ?: 'unknown') ?></div><?php if ($days !== null): ?><div class="text-tertiary" style="font-size:11.5px;margin-top:3px"><?= (int)$days ?> day<?= $days===1?'':'s' ?></div><?php endif; ?></td>
          <td><?= $custom ? '<span class="badge badge-slate">Manual</span>' : '<span class="badge badge-emerald"><span class="bdot"></span>Automatic</span>' ?></td>
          <td><span class="badge <?= $badgeClass ?>"><span class="bdot"></span><?= e($badgeText) ?></span></td>
          <td><div class="flex gap-1" style="justify-content:flex-end"><?php if (!$custom): ?><button class="btn btn-ghost btn-sm" data-ssl-renew="<?= e($name) ?>" title="Renew now"><i data-lucide="refresh-cw"></i></button><?php endif; ?><button class="btn btn-ghost btn-sm" data-ssl-del="<?= e($name) ?>" title="Delete"><i data-lucide="trash-2"></i></button></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <?php if ($certbotAvailable): ?>
  <div class="card" style="margin-bottom:16px"><div class="card-header"><h3>Issue Let's Encrypt certificate</h3></div><div class="card-pad">
    <div class="grid" style="grid-template-columns:1fr 1fr auto;gap:12px;align-items:end"><div><label class="field-label" for="sslDomain">Domain</label><input class="input mono" id="sslDomain" placeholder="shop.example.com" autocomplete="off"></div><div><label class="field-label" for="sslEmail">Email</label><input class="input mono" id="sslEmail" placeholder="optional" autocomplete="off"></div><button class="btn btn-primary" id="sslIssue"><i data-lucide="shield-check"></i>Issue Certificate</button></div>
    <div style="font-size:12px;color:var(--text-tertiary);margin-top:12px">The website must already exist and its DNS must point to this server.</div>
  </div></div>
  <?php endif; ?>

  <div class="card"><div class="card-header"><div><h3>Upload custom certificate</h3><span class="muted">PEM encoded certificate and matching private key</span></div></div><div class="card-pad">
    <div class="grid" style="grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;align-items:end">
      <div><label class="field-label" for="sslUploadDomain">Website</label><select class="select" id="sslUploadDomain"><option value="">Select website…</option><?php foreach($sslSites as $site): ?><option value="<?= e($site['domain']??'') ?>"><?= e($site['domain']??'') ?></option><?php endforeach; ?></select></div>
      <div><label class="field-label" for="sslCertFile">Certificate (.pem/.crt)</label><input class="input" id="sslCertFile" type="file" accept=".pem,.crt,.cer"></div>
      <div><label class="field-label" for="sslKeyFile">Private key (.key/.pem)</label><input class="input" id="sslKeyFile" type="file" accept=".key,.pem"></div>
      <div><label class="field-label" for="sslChainFile">CA chain (optional)</label><input class="input" id="sslChainFile" type="file" accept=".pem,.crt,.cer"></div>
    </div>
    <div class="flex items-center justify-between" style="margin-top:14px;gap:16px"><div style="font-size:12px;color:var(--text-tertiary)">The matching key is stored root-only and is never written to panel state or returned by the API.</div><button class="btn btn-primary" id="sslUpload"><i data-lucide="upload"></i>Upload &amp; install</button></div>
  </div></div>

  <script>
  document.addEventListener('DOMContentLoaded',()=>{const{apiPost,toast}=window.Nebula;
    document.getElementById('sslIssue')?.addEventListener('click',async()=>{const domain=document.getElementById('sslDomain').value.trim(),email=document.getElementById('sslEmail').value.trim();if(!domain){toast('Enter a domain','warning');return;}toast('Requesting certificate…','info');const res=await apiPost('ssl',{action:'issue',domain,email});if(res.ok){toast('Certificate issued','success');setTimeout(()=>location.reload(),600);}else toast(res.error||'Failed','error');});
    document.querySelectorAll('[data-ssl-renew]').forEach(btn=>btn.addEventListener('click',async()=>{const name=btn.dataset.sslRenew;toast('Renewing '+name+'…','info');const res=await apiPost('ssl',{action:'renew',name});if(res.ok){toast('Renewed','success');setTimeout(()=>location.reload(),600);}else toast(res.error||'Failed','error');}));
    document.querySelector('[data-ssl-renew-all]')?.addEventListener('click',async()=>{if(!confirm('Renew all eligible certificates?'))return;const res=await apiPost('ssl',{action:'renew'});if(res.ok){toast('Renewal complete','success');setTimeout(()=>location.reload(),600);}else toast(res.error||'Failed','error');});
    document.querySelectorAll('[data-ssl-del]').forEach(btn=>btn.addEventListener('click',async()=>{const name=btn.dataset.sslDel;if(!confirm('Delete certificate '+name+'? This cannot be undone.'))return;const res=await apiPost('ssl',{action:'delete',name});if(res.ok){toast('Deleted','success');setTimeout(()=>location.reload(),500);}else toast(res.error||'Failed','error');}));
    document.getElementById('sslUpload')?.addEventListener('click',async()=>{const domain=document.getElementById('sslUploadDomain').value,certFile=document.getElementById('sslCertFile').files[0],keyFile=document.getElementById('sslKeyFile').files[0],chainFile=document.getElementById('sslChainFile').files[0];if(!domain||!certFile||!keyFile){toast('Choose a website, certificate, and private key','warning');return;}if([certFile,keyFile,chainFile].filter(Boolean).some(file=>file.size>65536)){toast('Each PEM file must be smaller than 64 KB','error');return;}const button=document.getElementById('sslUpload');button.disabled=true;try{const[certificate,private_key,chain]=await Promise.all([certFile.text(),keyFile.text(),chainFile?chainFile.text():Promise.resolve('')]);const res=await apiPost('ssl',{action:'upload',domain,certificate,private_key,chain});if(res.ok){toast('Custom certificate installed','success');setTimeout(()=>location.reload(),600);}else{toast(res.error||'Upload failed','error');button.disabled=false;}}catch(error){toast('Could not read the selected files','error');button.disabled=false;}});
  });
  </script>
<?php endif; ?>
