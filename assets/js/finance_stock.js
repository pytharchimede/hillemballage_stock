(function () {
  const BASE = (window.ROUTE_BASE || window.APP_BASE || "").replace(/\/$/, "");
  function getCookie(name) {
    const parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }
  function authHeaders() {
    const t = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    return t ? { Authorization: "Bearer " + t } : {};
  }
  async function ensureToken() {
    let t = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    if (t) return t;
    try {
      const resp = await fetch(BASE + "/api/v1/auth/session-token", {
        credentials: "same-origin",
      });
      if (!resp.ok) return "";
      const data = await resp.json();
      const tok = data && data.token ? String(data.token) : "";
      if (tok) {
        localStorage.setItem("api_token", tok);
        try {
          document.cookie = "api_token=" + tok + "; path=/";
        } catch (e) {}
      }
      return tok;
    } catch (e) {
      return "";
    }
  }
  async function fetchWithAuth(url, opts = {}, canRetry = true) {
    if (!(localStorage.getItem("api_token") || getCookie("api_token"))) {
      await ensureToken();
    }
    const headers = Object.assign({}, opts.headers || {}, authHeaders());
    let r = await fetch(url, Object.assign({}, opts, { headers }));
    if (r.status === 401 && canRetry) {
      await ensureToken();
      const headers2 = Object.assign({}, opts.headers || {}, authHeaders());
      r = await fetch(url, Object.assign({}, opts, { headers: headers2 }));
    }
    return r;
  }
  function fmtFCFA(v) {
    try {
      return new Intl.NumberFormat("fr-FR").format(v || 0) + " FCFA";
    } catch (e) {
      return String(v || 0) + " FCFA";
    }
  }

  const elDepot = document.getElementById("fs-depot");
  const elFrom = document.getElementById("fs-from");
  const elTo = document.getElementById("fs-to");
  const elApply = document.getElementById("fs-apply");
  const elReset = document.getElementById("fs-reset");
  const elByDepot = document.getElementById("fs-by-depot");
  const elClients = document.getElementById("fs-clients");
  const btnCsv = document.getElementById("fs-export-csv");
  const btnPdf = document.getElementById("fs-export-pdf");

  async function loadDepots() {
    try {
      const r = await fetchWithAuth(BASE + "/api/v1/depots");
      if (!r.ok) throw new Error("depots");
      const rows = await r.json();
      elDepot.innerHTML = "";
      if (Array.isArray(rows) && rows.length > 1) {
        const all = document.createElement("option");
        all.value = "";
        all.textContent = "Tous les dépôts";
        elDepot.appendChild(all);
      }
      rows.forEach((d) => {
        const o = document.createElement("option");
        o.value = d.id;
        o.textContent = d.name + (d.code ? " (" + d.code + ")" : "");
        elDepot.appendChild(o);
      });
      elDepot.dataset.loaded = "1";
    } catch (e) {
      elDepot.innerHTML = '<option value="">—</option>';
    }
  }

  function query() {
    const q = new URLSearchParams();
    if (elDepot && elDepot.value) q.set("depot_id", elDepot.value);
    if (elFrom && elFrom.value) q.set("from", elFrom.value);
    if (elTo && elTo.value) q.set("to", elTo.value);
    return q;
  }

  async function refresh() {
    try {
      const q = query();
      const r = await fetchWithAuth(
        BASE +
          "/api/v1/finance-stock" +
          (q.toString() ? "?" + q.toString() : "")
      );
      if (!r.ok) {
        elByDepot.textContent = "Erreur";
        elClients.textContent = "Erreur";
        return;
      }
      const j = await r.json();
      renderByDepot(
        j.by_depot || [],
        j.stock_totals || { qty: 0, valuation: 0 }
      );
      renderClients(j.client_balances || [], j.receivables_total || 0);
    } catch (e) {
      elByDepot.textContent = "Erreur";
      elClients.textContent = "Erreur";
    }
  }

  function renderByDepot(rows, totals) {
    if (!rows.length) {
      elByDepot.innerHTML = '<div class="muted">Aucune donnée</div>';
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>Dépôt</th><th>Quantité</th><th>Valorisation</th></tr></thead><tbody>';
    rows.forEach((r) => {
      h += `<tr><td>${r.depot_name}</td><td>${r.qty | 0}</td><td>${fmtFCFA(
        r.valuation | 0
      )}</td></tr>`;
    });
    h += `</tbody><tfoot><tr><th>Total</th><th>${
      totals.qty || 0
    }</th><th>${fmtFCFA(totals.valuation || 0)}</th></tr></tfoot></table>`;
    elByDepot.innerHTML = h;
  }

  function renderClients(rows, total) {
    if (!rows.length) {
      elClients.innerHTML = '<div class="muted">Aucun solde</div>';
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>Client</th><th>Solde</th></tr></thead><tbody>';
    rows.forEach((c) => {
      h += `<tr><td>${c.name}</td><td>${fmtFCFA(c.balance | 0)}</td></tr>`;
    });
    h += `</tbody><tfoot><tr><th>Encours total</th><th>${fmtFCFA(
      total || 0
    )}</th></tr></tfoot></table>`;
    elClients.innerHTML = h;
  }

  (async function initPage() {
    await ensureToken();
    if (!elDepot.dataset.loaded) await loadDepots();
    await refresh();
  })();

  if (elApply) elApply.addEventListener("click", refresh);
  if (elReset)
    elReset.addEventListener("click", () => {
      if (elDepot) elDepot.value = "";
      if (elFrom) elFrom.value = "";
      if (elTo) elTo.value = "";
      refresh();
    });

  function withToken(q) {
    const t = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    if (t) q.set("api_token", t);
    return q;
  }
  if (btnCsv)
    btnCsv.addEventListener("click", async () => {
      await ensureToken();
      const q = withToken(query());
      window.open(
        BASE +
          "/api/v1/finance-stock/export" +
          (q.toString() ? "?" + q.toString() : ""),
        "_blank"
      );
    });
  if (btnPdf)
    btnPdf.addEventListener("click", async () => {
      await ensureToken();
      const q = withToken(query());
      window.open(
        BASE +
          "/api/v1/finance-stock/export-pdf" +
          (q.toString() ? "?" + q.toString() : ""),
        "_blank"
      );
    });
})();
