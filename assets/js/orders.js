// Orders listing script (refactor clean version)
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
  function authHeaders(t) {
    return t ? { Authorization: "Bearer " + t } : {};
  }
  function escapeHtml(s) {
    if (s == null) return "";
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
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

  async function loadOrders() {
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
    let r;
    try {
      r = await fetch(url, { headers: authHeaders(token) });
    } catch (err) {
      showEmpty("Erreur réseau: impossible de contacter le serveur");
      console.error("Network error loading orders", err);
      return;
    }
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/orders" +
        (params.toString() ? "?" + params.toString() : "");
      url +=
        (url.indexOf("?") > -1 ? "&" : "?") +
        (token ? "api_token=" + encodeURIComponent(token) : "");
      try {
        r = await fetch(url, { headers: authHeaders(token) });
      } catch (err) {
        showEmpty("Erreur réseau après reconnexion");
        return;
      }
    }
    if (!r.ok) {
      const grid = document.getElementById("orders-grid");
      const empty = document.getElementById("orders-empty");
      if (grid) grid.innerHTML = "";
      if (empty) {
        try {
          const err = await r.json();
          empty.textContent =
            "Erreur " +
            r.status +
            ": " +
            (err.error || "Impossible de charger");
        } catch (_) {
          empty.textContent = "Erreur " + r.status + ": Impossible de charger";
        }
        empty.style.display = "block";
      }
      return;
    }
    const rows = await r.json();
    renderOrders(rows);
  }

  function showEmpty(msg) {
    const grid = document.getElementById("orders-grid");
    const empty = document.getElementById("orders-empty");
    if (grid) grid.innerHTML = "";
    if (empty) {
      empty.textContent = msg;
      empty.style.display = "block";
    }
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
      .map((o) => {
        const ref = escapeHtml(o.reference || "");
        const sup = escapeHtml(o.supplier || "");
        const st = escapeHtml(o.status || "");
        const total = o.total_amount || 0;
        const remaining =
          o.total_amount_remaining !== undefined
            ? o.total_amount_remaining
            : null;
        const actions = [
          `<a class="btn" href="${routeBase}/orders/export?id=${o.id}" title="PDF"><i class="fa fa-file-pdf"></i></a>`,
        ];
        if (st !== "received" && st !== "cancelled") {
          actions.push(
            `<button class="btn receive-btn" data-id="${o.id}" title="Réception"><i class="fa fa-check"></i></button>`
          );
          actions.push(
            `<button class="btn cancel-btn" data-id="${o.id}" title="Annuler"><i class="fa fa-times"></i></button>`
          );
        }
        return `<div class="card-client" data-id="${
          o.id
        }"><div class="cl-body"><div class="cl-name">${ref}</div><div class="cl-phone">Fournisseur: ${
          sup || '<span class="muted">N/A</span>'
        }</div><div class="cl-balance"><span class="badge">${st}</span> · Total: ${total}${
          (st === "partially_received" || st === "ordered") &&
          remaining !== null
            ? ` (reste: ${remaining})`
            : ""
        }</div></div><div class="cl-actions">${actions.join("")}</div></div>`;
      })
      .join("");
    grid.querySelectorAll(".receive-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.getAttribute("data-id"), 10);
        openReceiveModal(id);
      });
    });
    grid.querySelectorAll(".cancel-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.getAttribute("data-id"), 10);
        if (!confirm("Confirmer annulation de la commande ?")) return;
        cancelOrder(id);
      });
    });
  }

  async function cancelOrder(id) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url =
      routeBase +
      "/api/v1/orders/" +
      id +
      "/cancel" +
      (token ? "?api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, {
      method: "PATCH",
      headers: Object.assign(
        { "Content-Type": "application/json" },
        authHeaders(token)
      ),
    });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/orders/" +
        id +
        "/cancel" +
        (token ? "?api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, {
        method: "PATCH",
        headers: Object.assign(
          { "Content-Type": "application/json" },
          authHeaders(token)
        ),
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
    loadOrders();
  }

  // Modal & partial reception
  const modal = document.getElementById("receive-modal");
  const rmBody = document.getElementById("rm-body");
  const rmRef = document.getElementById("rm-ref");
  const rmMsg = document.getElementById("rm-msg");
  const rmCancel = document.getElementById("rm-cancel");
  const rmSubmit = document.getElementById("rm-submit");
  let currentReceiveId = null;

  function openReceiveModal(id) {
    currentReceiveId = id;
    loadOrderDetails(id);
  }
  function closeReceiveModal() {
    if (modal) modal.style.display = "none";
    if (rmBody) rmBody.innerHTML = "";
    if (rmMsg) rmMsg.textContent = "";
    currentReceiveId = null;
  }
  if (rmCancel) rmCancel.addEventListener("click", closeReceiveModal);

  async function loadOrderDetails(id) {
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
      (token ? "?api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/orders/" +
        id +
        (token ? "?api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) {
      alert("Impossible de charger la commande.");
      return;
    }
    const detail = await r.json();
    if (rmRef) rmRef.textContent = detail.order.reference;
    const orderItems = detail.items || [];
    if (rmBody) {
      rmBody.innerHTML = orderItems
        .map((it) => {
          const initial = it.initial_quantity || it.quantity || 0;
          const remaining = it.quantity || 0;
          return `<tr data-pid="${it.product_id}"><td>${escapeHtml(
            it.product_name
          )}</td><td style="text-align:right">${initial}</td><td style="text-align:right">${remaining}</td><td>${
            remaining > 0
              ? `<input type="number" class="form-control rm-qty" min="0" max="${remaining}" value="${remaining}" style="width:100px"/>`
              : '<span class="muted">0</span>'
          }</td></tr>`;
        })
        .join("");
    }
    if (modal) modal.style.display = "flex";
  }

  if (rmSubmit)
    rmSubmit.addEventListener("click", async () => {
      if (!currentReceiveId) return;
      const sel = document.getElementById("depot-filter");
      const depotId = sel && sel.value ? parseInt(sel.value, 10) : 0;
      if (depotId <= 0) {
        alert("Sélectionnez un dépôt.");
        return;
      }
      const rows = Array.from(rmBody.querySelectorAll("tr"));
      const itemsPayload = [];
      rows.forEach((tr) => {
        const pid = parseInt(tr.getAttribute("data-pid"), 10);
        const inp = tr.querySelector(".rm-qty");
        if (!inp) return;
        let q = parseInt(inp.value, 10);
        if (isNaN(q) || q <= 0) return;
        const max = parseInt(inp.getAttribute("max"), 10);
        if (q > max) q = max;
        itemsPayload.push({ product_id: pid, quantity: q });
      });
      if (itemsPayload.length === 0) {
        alert("Aucune quantité à recevoir.");
        return;
      }
      await submitReception(currentReceiveId, depotId, itemsPayload);
    });

  async function submitReception(id, depotId, itemsPayload) {
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
      body: JSON.stringify(
        itemsPayload
          ? { depot_id: depotId, items: itemsPayload }
          : { depot_id: depotId }
      ),
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
        body: JSON.stringify(
          itemsPayload
            ? { depot_id: depotId, items: itemsPayload }
            : { depot_id: depotId }
        ),
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
    try {
      const jr = await r.json();
      if (jr && jr.status) {
        alert("Statut: " + jr.status + " (reste: " + (jr.remaining || 0) + ")");
      }
    } catch (_) {}
    closeReceiveModal();
    loadOrders();
  }

  // Filters & events
  const sf = document.getElementById("status-filter");
  if (sf) sf.addEventListener("change", loadOrders);
  const qf = document.getElementById("q-filter");
  if (qf)
    qf.addEventListener("input", function () {
      clearTimeout(debounceT);
      debounceT = setTimeout(loadOrders, 300);
    });
  const br = document.getElementById("btn-reset");
  if (br)
    br.addEventListener("click", function () {
      if (sf) sf.value = "";
      if (qf) qf.value = "";
      loadOrders();
    });
  const df = document.getElementById("depot-filter");
  if (df)
    df.addEventListener("change", function () {
      /* used for receive depot selection */
    });

  // Init
  loadDepots().then(loadOrders);
})();
