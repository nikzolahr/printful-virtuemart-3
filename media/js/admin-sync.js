(function () {
  function byId(id) { return document.getElementById(id); }
  function log(out, msg) { out.textContent += (out.textContent ? '\n' : '') + msg; }
  function fmt(x) { try { return JSON.stringify(x, null, 2); } catch(e){ return String(x); } }

  function runSync() {
    const out  = byId('pf-sync-output');
    const opts = (typeof Joomla !== 'undefined' && Joomla.getOptions) ? Joomla.getOptions('plgVmextPrintful') : null;
    if (!out) return;

    out.textContent = '';
    if (!opts || !opts.ajaxUrl || !opts.tokenKey) {
      log(out, 'Konfiguration fehlt (ajaxUrl / tokenKey).');
      return;
    }

    const fd = new FormData();
    fd.append(opts.tokenKey, 1);

    log(out, 'Starte Synchronisierung …');
    fetch(opts.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(async (r) => {
        const ct = r.headers.get('content-type') || '';
        const payload = ct.includes('json') ? await r.json() : await r.text();
        log(out, 'HTTP ' + r.status);
        log(out, fmt(payload));

        if (typeof payload === 'object' && payload) {
          const parts = [];
          if ('apiBase' in payload) parts.push('apiBase=' + payload.apiBase);
          if ('tokenType' in payload) parts.push('tokenType=' + payload.tokenType);
          if ('fetched' in payload) parts.push('fetched=' + payload.fetched);
          if ('processed' in payload) parts.push('processed=' + payload.processed);
          if ('created' in payload) parts.push('created=' + payload.created);
          if ('updated' in payload) parts.push('updated=' + payload.updated);
          if (parts.length) log(out, '⟶ ' + parts.join(' · '));
        }
      })
      .catch((e) => log(out, 'Fehler: ' + e.message));
  }

  document.addEventListener('DOMContentLoaded', function () {
    const btn = byId('pf-sync-btn');
    if (btn) btn.addEventListener('click', runSync);
  });

  window.PfSync = { run: runSync };
})();
