'use strict';

/**
 * Browser client for KiCom DEV Zone.
 * DEV credentials are reusable and are sent in headers, never query strings.
 * Local storage is acceptable here because the credential is DEV-scoped and
 * cannot cross the production/self-update/kernel/recovery boundary.
 */
window.KiComDev = (() => {
  const KEY = 'kicom.dev.session.v1';

  const load = () => {
    try {
      const raw = localStorage.getItem(KEY);
      if (!raw) return null;
      const row = JSON.parse(raw);
      if (!row || typeof row.session_id !== 'string' || typeof row.token !== 'string') return null;
      return row;
    } catch (_) {
      return null;
    }
  };

  const save = (session) => {
    if (!session || typeof session.session_id !== 'string' || typeof session.token !== 'string') throw new Error('Invalid DEV session');
    localStorage.setItem(KEY, JSON.stringify({ session_id: session.session_id, token: session.token, scope: 'dev', saved_at: new Date().toISOString() }));
  };

  const clear = () => localStorage.removeItem(KEY);

  const postAuthenticated = async (endpoint, body) => {
    const session = load();
    if (!session) throw new Error('DEV session required');
    const response = await fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-KiCom-Dev-Session': session.session_id, 'X-KiCom-Dev-Token': session.token },
      body: JSON.stringify(body),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (_) { data = { ok: false, code: 'DEV_RESPONSE_INVALID', raw: text }; }
    if (response.status === 401 && ['DEV_SESSION_REVOKED','DEV_SESSION_EXPIRED','DEV_SESSION_IDLE_EXPIRED','DEV_SESSION_TOKEN_REJECTED'].includes(data.code)) clear();
    return { http_status: response.status, ...data };
  };

  const request = (operation, payload = {}, endpoint = 'dev-api.php') => postAuthenticated(endpoint, { operation, payload });
  const expansionRequest = operation => postAuthenticated('dev-expansion.php', { operation });
  const artifactRequest = (operation, payload = {}) => postAuthenticated('dev-artifact.php', { operation, payload });

  const status = () => request('DEV_SESSION_STATUS');
  const expansionStatus = () => expansionRequest('STATUS');
  const expandSandbox = () => expansionRequest('EXECUTE_SANDBOX');
  const upgradeSandboxLiving = () => expansionRequest('UPGRADE_SANDBOX_LIVING');
  const artifactStatus = () => artifactRequest('STATUS');
  const verifyArtifact = (url, sha256, format = 'auto') => artifactRequest('VERIFY', { url, sha256, format });
  const installArtifact = (url, sha256, format = 'auto') => artifactRequest('INSTALL', { url, sha256, format });
  const querySandboxPerception = async () => {
    const result = await expansionRequest('QUERY_SANDBOX_PERCEPTION');
    if (result && result.ok && result.report) window.dispatchEvent(new CustomEvent('kicom:perception-report', { detail: result.report }));
    return result;
  };
  const revoke = async (reason = 'browser logout') => { try { return await request('DEV_SESSION_REVOKE', { reason }); } finally { clear(); } };

  const arr = v => Array.isArray(v) ? v : [];
  const short = id => typeof id === 'string' && id.length > 18 ? id.slice(0, 12) + '…' : (id || '–');
  const when = value => { if (!value) return '–'; const d = new Date(value); return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString(); };
  const changeText = summary => {
    const s = summary && typeof summary === 'object' ? summary : {}; const parts = [];
    if ('overall_before' in s || 'overall_after' in s) parts.push(`Zustand ${s.overall_before || 'UNKNOWN'} → ${s.overall_after || 'UNKNOWN'}`);
    if ('neighbors_before' in s || 'neighbors_after' in s) parts.push(`Nachbarn ${s.neighbors_before ?? 0} → ${s.neighbors_after ?? 0}`);
    Object.entries(s).forEach(([k, v]) => { if (['overall_before','overall_after','neighbors_before','neighbors_after'].includes(k)) return; if (['string','number','boolean'].includes(typeof v) || v === null) parts.push(`${k}=${v}`); });
    return parts.join(' · ') || 'Änderung erkannt';
  };
  const compactActions = actions => {
    const out = [];
    arr(actions).forEach(a => { const prev = out[out.length - 1]; const same = prev && prev.ts === a.ts && prev.action === a.action && prev.target === a.target && prev.result === a.result; if (same) prev.count += 1; else out.push({ ...a, count: 1 }); });
    return out;
  };

  const ensureExperiencePanel = () => {
    if (document.getElementById('cell-experience-card')) return;
    const heading = [...document.querySelectorAll('h2')].find(h => h.textContent.trim() === 'Zellwahrnehmung');
    const card = heading ? heading.closest('.card') : null; if (!card) return;
    const section = document.createElement('section'); section.className = 'card'; section.id = 'cell-experience-card';
    section.innerHTML = '<h2>Zellgedächtnis</h2><div id="cell-experience-status" class="status">Noch keine Erinnerung abgefragt</div><div class="muted">Begrenzter, sanitiserter Ausschnitt aus append-only Wahrnehmungs- und Handlungshistorie. Die vollständigen Historien bleiben privat in der Zelle.</div><pre id="cell-experience-view">Noch keine signierte Wahrnehmungsabfrage.</pre>';
    card.insertAdjacentElement('afterend', section);
  };

  const renderExperience = report => {
    ensureExperiencePanel(); const sEl = document.getElementById('cell-experience-status'); const view = document.getElementById('cell-experience-view'); if (!sEl || !view) return;
    const mem = report && typeof report.experience_memory === 'object' ? report.experience_memory : {};
    const worldChanges = arr(mem.recent_world_changes), perceptionChanges = arr(mem.recent_perception_changes), actions = arr(mem.recent_actions), compactedActions = compactActions(actions), possibilityChanges = arr(mem.recent_possibility_changes);
    const constraints = arr(report?.constraints).filter(c => String(c?.state || '').toUpperCase() === 'FORBIDDEN'); const lines = [];
    lines.push(`WAHRNEHMUNGSERINNERUNG (${worldChanges.length} letzte Weltänderungen)`); if (!worldChanges.length) lines.push('  Noch keine Änderung im sichtbaren Erinnerungsfenster.');
    worldChanges.forEach(c => { const s = c.knowledge_summary || {}; lines.push(`  ${when(c.ts)} · ${c.trigger || '–'} · ${short(c.before_world_id)} → ${short(c.after_world_id)} · bekannt ${s.known ?? 0} / unbekannt ${s.unknown ?? 0} / verboten ${s.forbidden ?? 0}`); });
    if (perceptionChanges.length) { lines.push(''); lines.push(`ROHWAHRNEHMUNGSÄNDERUNGEN (${perceptionChanges.length})`); perceptionChanges.forEach(c => lines.push(`  ${when(c.ts)} · ${c.trigger || '–'} · ${changeText(c.summary)}`)); }
    lines.push(''); lines.push(`HANDLUNGSGEDÄCHTNIS (${actions.length} letzte Handlungen)`); if (!actions.length) lines.push('  Noch keine Handlung im sichtbaren Erinnerungsfenster.');
    compactedActions.forEach(a => lines.push(`  ${when(a.ts)} · ${a.action || '–'} → ${a.target || '–'} · ${String(a.result || 'unknown').toUpperCase()}${a.count > 1 ? ' · ×' + a.count : ''}`));
    lines.push(''); lines.push(`MÖGLICHKEITSERINNERUNG (${possibilityChanges.length} Änderungen)`); if (!possibilityChanges.length) lines.push('  Keine Änderung der Möglichkeiten im sichtbaren Erinnerungsfenster.');
    possibilityChanges.forEach(c => { const p = arr(c.possibilities); const sample = p.slice(0, 5).map(x => `${x.id || '–'}=${x.state || 'UNKNOWN'}`).join(', '); lines.push(`  ${when(c.ts)} · ${p.length} Möglichkeiten${sample ? ' · ' + sample : ''}`); });
    if (constraints.length) { lines.push(''); lines.push(`FESTE GRENZEN (${constraints.length})`); constraints.forEach(c => lines.push(`  ${c.subject || '–'}: FORBIDDEN · ${c.reason || '–'}`)); }
    lines.push(''); lines.push(`Erinnerung: ${mem.retention || 'unbekannt'} · vollständige Historie privat=${mem.complete_history_private === true ? 'ja' : '–'}`);
    view.textContent = lines.join('\n'); sEl.textContent = `${actions.length} erinnerte Handlungen · ${worldChanges.length} Weltänderungen · ${possibilityChanges.length} Möglichkeitsänderungen`; sEl.className = 'status ok';
  };

  const ensureArtifactPanel = () => {
    if (document.getElementById('dev-artifact-card')) return;
    const heading = [...document.querySelectorAll('h2')].find(h => h.textContent.trim() === 'Entwicklerzugang');
    const card = heading ? heading.closest('.card') : null; if (!card) return;
    const section = document.createElement('section'); section.className = 'card'; section.id = 'dev-artifact-card';
    section.innerHTML = '<h2>Artifact Import</h2><div id="artifact-status" class="status">Noch nicht geprüft</div><div class="muted">SHA-gebundener HTTPS-Import ausschließlich nach <code>/dev/</code>. Download wird gestaged, geprüft und erst dann installiert. Ersetzte Dateien werden vorher archiviert.</div><div class="row"><input id="artifact-url" type="url" autocomplete="off" placeholder="https://…/artifact.zip oder bundle.json"></div><div class="row"><input id="artifact-sha" autocomplete="off" spellcheck="false" maxlength="64" placeholder="SHA-256 (64 Hex-Zeichen)"><select id="artifact-format"><option value="auto">Format automatisch</option><option value="bundle">KiCom Bundle JSON</option><option value="zip">ZIP</option></select></div><div class="row"><button id="artifact-check" class="secondary">Download prüfen</button><button id="artifact-install">Prüfen & installieren</button></div><pre id="artifact-result">bereit</pre>';
    card.insertAdjacentElement('afterend', section);

    const run = async install => {
      const url = document.getElementById('artifact-url').value.trim(); const sha = document.getElementById('artifact-sha').value.trim().toLowerCase(); const format = document.getElementById('artifact-format').value;
      const statusEl = document.getElementById('artifact-status'), resultEl = document.getElementById('artifact-result');
      if (!/^https:\/\//i.test(url)) { statusEl.textContent = 'HTTPS-Link erforderlich'; statusEl.className = 'status err'; return; }
      if (!/^[a-f0-9]{64}$/.test(sha)) { statusEl.textContent = 'Gültiger SHA-256 erforderlich'; statusEl.className = 'status err'; return; }
      document.getElementById('artifact-check').disabled = true; document.getElementById('artifact-install').disabled = true;
      statusEl.textContent = install ? 'Download, Prüfung und Installation laufen …' : 'Download und Prüfung laufen …'; statusEl.className = 'status';
      try {
        const r = install ? await installArtifact(url, sha, format) : await verifyArtifact(url, sha, format);
        resultEl.textContent = JSON.stringify(r, null, 2);
        if (!r.ok) throw new Error(r.code || 'Artifact operation failed');
        statusEl.textContent = install ? `Installiert · ${r.files ?? 0} Dateien · Archiv retained` : `Verifiziert · ${r.files ?? 0} Dateien · ${r.total_bytes ?? 0} Bytes`;
        statusEl.className = 'status ok';
      } catch (e) { statusEl.textContent = e.message || 'Artifact-Fehler'; statusEl.className = 'status err'; }
      finally { const enabled = !!load(); document.getElementById('artifact-check').disabled = !enabled; document.getElementById('artifact-install').disabled = !enabled; }
    };
    document.getElementById('artifact-check').addEventListener('click', () => run(false));
    document.getElementById('artifact-install').addEventListener('click', () => run(true));
  };

  const installUi = () => {
    ensureExperiencePanel(); ensureArtifactPanel();
    window.addEventListener('kicom:perception-report', event => renderExperience(event.detail || {}));
    const enabled = !!load(); const check = document.getElementById('artifact-check'), install = document.getElementById('artifact-install'); if (check) check.disabled = !enabled; if (install) install.disabled = !enabled;
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installUi, { once: true }); else installUi();

  return { load, save, clear, request, status, expansionStatus, expandSandbox, upgradeSandboxLiving, querySandboxPerception, artifactStatus, verifyArtifact, installArtifact, revoke };
})();
