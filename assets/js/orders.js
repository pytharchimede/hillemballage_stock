// Orders listing script
(function () {
  const routeBase = window.ROUTE_BASE || "";
  let debounceT;
  let depots = [];

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
  function authHeaders(token) {
    return token ? { Authorization: "Bearer " + token } : {};
  }
  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function renderOrders(rows) {
    const grid = document.getElementById("orders-grid");
    const empty = document.getElementById("orders-empty");
    if (!grid) return;
    if (!rows || rows.length === 0) {
      grid.innerHTML = "";
      if (empty) empty.style.display = "block";
      return;
    }
    if (empty) empty.style.display = "none";
    grid.innerHTML = rows
      .map(function (o) {
        const ref = escapeHtml(o.reference || "");
        const sup = escapeHtml(o.supplier || "");
        const st = escapeHtml(o.status || "");
        const total = o.total_amount || 0;
        const actions = [
          `<a class="btn" href="${routeBase}/orders/export?id=${o.id}" title="PDF"><i class="fa fa-file-pdf"></i></a>`,
        ];
        if (st !== "received") {
          actions.push(
            `<button class="btn receive-btn" data-id="${o.id}" title="Marquer reçu"><i class="fa fa-check"></i></button>`
          );
        }
        return `
        <div class="card-client" data-id="${o.id}">
          <div class="cl-body">
            <div class="cl-name">${ref}</div>
            <div class="cl-phone">Fournisseur: ${
              sup || '<span class="muted">N/A</span>'
            }</div>
            <div class="cl-balance"><span class="badge">${st}</span> · Total: ${total}</div>
          </div>
          <div class="cl-actions">${actions.join("")}</div>
        </div>`;
      })
      .join("");
    grid.querySelectorAll(".receive-btn").forEach((btn) => {
      btn.addEventListener("click", function () {
        const id = parseInt(this.getAttribute("data-id"), 10);
        markReceived(id);
      });
    });
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const status = document.getElementById("status-filter")?.value || "";
    const q = document.getElementById("q-filter")?.value || "";
    const params = new URLSearchParams();
    if (status) params.set("status", status);
    if (q) params.set("q", q);
    let url =
      routeBase +
      "/api/v1/orders" +
      (params.toString() ? "?" + params.toString() : "");
    url +=
      (url.indexOf("?") > -1 ? "&" : "?") +
      (token ? "api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/orders" +
        (params.toString() ? "?" + params.toString() : "");
      url +=
        (url.indexOf("?") > -1 ? "&" : "?") +
        (token ? "api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) {
      const grid = document.getElementById("orders-grid");
      const empty = document.getElementById("orders-empty");
      if (grid) grid.innerHTML = "";
      if (empty) {
        try {
          const err = await r.json();
          empty.textContent = `Erreur ${r.status}: ${
            err.error || "Impossible de charger"
          }`;
        } catch (_) {
          empty.textContent = `Erreur ${r.status}: Impossible de charger`;
        }
        empty.style.display = "block";
      }
      return;
    }
    let rows = await r.json();
    renderOrders(rows);
  }

  async function loadDepots() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url =
      routeBase +
      "/api/v1/depots" +
      (token ? "?api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/depots" +
        (token ? "?api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    depots = await r.json();
    const sel = document.getElementById("depot-filter");
    if (sel) {
      sel.innerHTML =
        '<option value="">Sélection dépôt…</option>' +
        depots
          .map(
            (d) =>
              `<option value="${d.id}">${escapeHtml(d.name)}${
                d.code ? " (" + escapeHtml(d.code) + ")" : ""
              }</option>`
          )
          .join("");
    }
  }

  async function markReceived(id) {
    const sel = document.getElementById("depot-filter");
    const depotId = sel && sel.value ? parseInt(sel.value, 10) : 0;
    if (depotId <= 0) {
      alert("Choisissez un dépôt de réception.");
      return;
    }
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url =
      routeBase +
      "/api/v1/orders/" +
      id +
      "/receive" +
      (token ? "?api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, {
      method: "PATCH",
      headers: Object.assign(
        { "Content-Type": "application/json" },
        authHeaders(token)
      ),
      body: JSON.stringify({ depot_id: depotId }),
    });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/orders/" +
        id +
        "/receive" +
        (token ? "?api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, {
        method: "PATCH",
        headers: Object.assign(
          { "Content-Type": "application/json" },
          authHeaders(token)
        ),
        body: JSON.stringify({ depot_id: depotId }),
      });
    }
    if (!r.ok) {
      try {
        const j = await r.json();
        alert("Erreur: " + (j.error || r.status));
      } catch (_) {
        alert("Erreur serveur.");
      }
      return;
    }
    load();
  }

  const sf = document.getElementById("status-filter");
  if (sf) sf.addEventListener("change", load);
  const qf = document.getElementById("q-filter");
  if (qf)
    qf.addEventListener("input", function () {
      clearTimeout(debounceT);
      debounceT = setTimeout(load, 300);
    });
  const br = document.getElementById("btn-reset");
  if (br)
    br.addEventListener("click", function () {
      if (sf) sf.value = "";
      if (qf) qf.value = "";
      load();
    });

  const df = document.getElementById("depot-filter");
  if (df)
    df.addEventListener("change", function () {
      /* used only for markReceived */
    });

  loadDepots().then(load);
})();
