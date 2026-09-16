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
    if (!session || typeof session.session_id !== 'string' || typeof session.token !== 'string') {
      throw new Error('Invalid DEV session');
    }
    localStorage.setItem(KEY, JSON.stringify({
      session_id: session.session_id,
      token: session.token,
      scope: 'dev',
      saved_at: new Date().toISOString(),
    }));
  };

  const clear = () => localStorage.removeItem(KEY);

  const postAuthenticated = async (endpoint, body) => {
    const session = load();
    if (!session) throw new Error('DEV session required');
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'X-KiCom-Dev-Session': session.session_id,
        'X-KiCom-Dev-Token': session.token,
      },
      body: JSON.stringify(body),
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (_) { data = { ok: false, code: 'DEV_RESPONSE_INVALID', raw: text }; }
    if (response.status === 401 && ['DEV_SESSION_REVOKED','DEV_SESSION_EXPIRED','DEV_SESSION_IDLE_EXPIRED','DEV_SESSION_TOKEN_REJECTED'].includes(data.code)) {
      clear();
    }
    return { http_status: response.status, ...data };
  };

  // Relative endpoint is intentional: the DEV zone is installed under /dev/.
  // A root-absolute /dev-api.php would escape that directory and return 404.
  const request = (operation, payload = {}, endpoint = 'dev-api.php') =>
    postAuthenticated(endpoint, { operation, payload });

  const expansionRequest = (operation) =>
    postAuthenticated('dev-expansion.php', { operation });

  const status = () => request('DEV_SESSION_STATUS');
  const expansionStatus = () => expansionRequest('STATUS');
  const expandSandbox = () => expansionRequest('EXECUTE_SANDBOX');
  const revoke = async (reason = 'browser logout') => {
    try { return await request('DEV_SESSION_REVOKE', { reason }); }
    finally { clear(); }
  };

  return { load, save, clear, request, status, expansionStatus, expandSandbox, revoke };
})();
