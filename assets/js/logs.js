(function () {
  const routeBase = window.ROUTE_BASE || "";
  let currentPage = 1;
  let lastHasMore = false;

  function readCookieToken() {
    try {
      var name = "api_token=";
      var ca = document.cookie.split(";");
      for (var i = 0; i < ca.length; i++) {
        var c = ca[i].trim();
        if (c.indexOf(name) === 0) return c.substring(name.length, c.length);
      }
    } catch (e) {}
    return "";
  }
  function authHeaders(token) {
    return token ? { Authorization: "Bearer " + token } : {};
  }
  async function refreshSessionToken() {
    try {
      const r = await fetch(routeBase + "/api/v1/auth/session-token");
      if (r.ok) {
        const j = await r.json();
        if (j && j.token) {
          localStorage.setItem("api_token", j.token);
          document.cookie = "api_token=" + j.token + "; path=/";
          return j.token;
        }
      }
    } catch (_) {}
    return null;
  }

  const els = {
    action: document.getElementById("log-action"),
    entity: document.getElementById("log-entity"),
    user: document.getElementById("log-user"),
    from: document.getElementById("log-from"),
    to: document.getElementById("log-to"),
    q: document.getElementById("log-q"),
    limit: document.getElementById("log-limit"),
    btnSearch: document.getElementById("btn-log-search"),
    btnReset: document.getElementById("btn-log-reset"),
    btnExport: document.getElementById("btn-log-export"),
    btnExportPdf: document.getElementById("btn-log-export-pdf"),
    grid: document.getElementById("logs-grid"),
    empty: document.getElementById("logs-empty"),
    prev: document.getElementById("logs-prev"),
    next: document.getElementById("logs-next"),
    pageLabel: document.getElementById("logs-page"),
  };

  function paramsToQuery() {
    const p = new URLSearchParams();
    if (els.action && els.action.value) p.set("action", els.action.value);
    if (els.entity && els.entity.value)
      p.set("entity", els.entity.value.trim());
    if (els.user && els.user.value) p.set("user_id", els.user.value);
    if (els.from && els.from.value) p.set("from", els.from.value);
    if (els.to && els.to.value) p.set("to", els.to.value);
    if (els.q && els.q.value) p.set("q", els.q.value.trim());
    if (els.limit && els.limit.value) p.set("limit", els.limit.value);
    if (currentPage && currentPage > 0) p.set("page", String(currentPage));
    return p.toString();
  }

  function escapeHtml(s) {
    if (s === null || s === undefined) return "";
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function render(rows) {
    if (!els.grid) return;
    if (!rows || rows.length === 0) {
      els.grid.innerHTML = "";
      if (els.empty) els.empty.style.display = "block";
      return;
    }
    if (els.empty) els.empty.style.display = "none";
    const html = [
      '<table class="table"><thead><tr>\
      <th>ID</th><th>Utilisateur</th><th>Action</th><th>Entité</th><th>Entité ID</th><th>Route</th><th>Méthode</th><th>IP</th><th>Date</th>\
    </tr></thead><tbody>',
    ];
    rows.forEach((r) => {
      html.push(
        "<tr>" +
          "<td>" +
          escapeHtml(r.id) +
          "</td>" +
          "<td>" +
          escapeHtml(r.actor_name || "#" + (r.actor_user_id || "")) +
          "</td>" +
          "<td>" +
          escapeHtml(r.action) +
          "</td>" +
          "<td>" +
          escapeHtml(r.entity || "") +
          "</td>" +
          "<td>" +
          escapeHtml(r.entity_id || "") +
          "</td>" +
          "<td>" +
          escapeHtml(r.route) +
          "</td>" +
          "<td>" +
          escapeHtml(r.method) +
          "</td>" +
          "<td>" +
          escapeHtml(r.ip || "") +
          "</td>" +
          "<td>" +
          escapeHtml(r.created_at) +
          "</td>" +
          "</tr>"
      );
    });
    html.push("</tbody></table>");
    els.grid.innerHTML = html.join("");
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const qs = paramsToQuery();
    let url = routeBase + "/api/v1/audit-logs" + (qs ? "?" + qs : "");
    url +=
      (url.indexOf("?") > -1 ? "&" : "?") +
      (token ? "api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      let url2 = routeBase + "/api/v1/audit-logs" + (qs ? "?" + qs : "");
      url2 +=
        (url2.indexOf("?") > -1 ? "&" : "?") +
        (token ? "api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url2, { headers: authHeaders(token) });
    }
    if (!r.ok) {
      try {
        const j = await r.json();
        alert("Erreur " + r.status + ": " + (j.error || "Chargement"));
      } catch (_) {
        alert("Erreur chargement");
      }
      return;
    }
    const payload = await r.json();
    const rows = Array.isArray(payload) ? payload : payload.items || [];
    lastHasMore = !!(payload && payload.has_more);
    if (els.pageLabel) {
      const p = payload && payload.page ? payload.page : currentPage;
      els.pageLabel.textContent = "Page " + p;
    }
    if (els.prev)
      els.prev.disabled =
        (payload && payload.page ? payload.page : currentPage) <= 1;
    if (els.next) els.next.disabled = !lastHasMore;
    render(rows);
  }

  if (els.btnSearch) els.btnSearch.addEventListener("click", load);
  if (els.btnReset)
    els.btnReset.addEventListener("click", function () {
      if (els.action) els.action.value = "";
      if (els.entity) els.entity.value = "";
      if (els.user) els.user.value = "";
      if (els.from) els.from.value = "";
      if (els.to) els.to.value = "";
      if (els.q) els.q.value = "";
      if (els.limit) els.limit.value = "200";
      currentPage = 1;
      load();
    });
  if (els.prev)
    els.prev.addEventListener("click", function () {
      if (currentPage > 1) {
        currentPage--;
        load();
      }
    });
  if (els.next)
    els.next.addEventListener("click", function () {
      if (lastHasMore) {
        currentPage++;
        load();
      }
    });
  if (els.btnExport)
    els.btnExport.addEventListener("click", function () {
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      const qs = paramsToQuery();
      let url = routeBase + "/api/v1/audit-logs/export" + (qs ? "?" + qs : "");
      url +=
        (url.indexOf("?") > -1 ? "&" : "?") +
        (token ? "api_token=" + encodeURIComponent(token) : "");
      window.open(url, "_blank");
    });

  if (els.btnExportPdf)
    els.btnExportPdf.addEventListener("click", function () {
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      const qs = paramsToQuery();
      let url =
        routeBase + "/api/v1/audit-logs/export-pdf" + (qs ? "?" + qs : "");
      url +=
        (url.indexOf("?") > -1 ? "&" : "?") +
        (token ? "api_token=" + encodeURIComponent(token) : "");
      window.open(url, "_blank");
    });

  // Auto-load on page open
  load();
})();
