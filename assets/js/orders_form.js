(function () {
  const routeBase = window.ROUTE_BASE || "";
  const form = document.getElementById("order-form");
  const depotEl = document.getElementById("o_depot");
  const targetEl = document.getElementById("o_target");
  const refInput = document.getElementById("o_reference");
  const supplierInput = document.getElementById("o_supplier");
  const msg = document.getElementById("order-msg");
  const tbl = document.getElementById("items-table");
  const tbody = tbl ? tbl.querySelector("tbody") : null;
  const totalEl = document.getElementById("order-total");
  const searchInput = document.getElementById("prod-search");
  const searchMenu = document.getElementById("prod-search-menu");
  let debounceT;
  const items = new Map(); // key: product_id, value: {id,name,qty,current,price}

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
  function escapeHtml(s) {
    if (s == null) return "";
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function render() {
    if (!tbody) return;
    let total = 0;
    tbody.innerHTML = Array.from(items.values())
      .map((it) => {
        const subtotal = (it.qty || 0) * (it.price || 0);
        total += subtotal;
        const after = (it.current || 0) + (it.qty || 0);
        return `<tr data-id="${it.id}">
        <td>${escapeHtml(it.name)}</td>
        <td style="text-align:right">${it.current || 0}</td>
        <td><input class="form-control it-qty" type="number" min="0" value="${
          it.qty || 0
        }" style="min-width:120px" /></td>
        <td style="text-align:right">${after}</td>
        <td><input class="form-control it-price" type="number" min="0" value="${
          it.price || 0
        }" style="min-width:110px" /></td>
        <td style="text-align:right">${subtotal}</td>
        <td><button class="btn secondary btn-del" type="button" title="Retirer"><i class="fa fa-trash"></i></button></td>
      </tr>`;
      })
      .join("");
    if (totalEl) totalEl.textContent = String(total);
    // wire events
    tbody.querySelectorAll(".it-qty").forEach((inp) => {
      inp.addEventListener("input", function () {
        const tr = this.closest("tr");
        const id = parseInt(tr.getAttribute("data-id"), 10);
        const it = items.get(id);
        if (!it) return;
        it.qty = Math.max(0, parseInt(this.value || "0", 10));
        render();
      });
    });
    tbody.querySelectorAll(".it-price").forEach((inp) => {
      inp.addEventListener("input", function () {
        const tr = this.closest("tr");
        const id = parseInt(tr.getAttribute("data-id"), 10);
        const it = items.get(id);
        if (!it) return;
        it.price = Math.max(0, parseInt(this.value || "0", 10));
        render();
      });
    });
    tbody.querySelectorAll(".btn-del").forEach((btn) => {
      btn.addEventListener("click", function () {
        const tr = this.closest("tr");
        const id = parseInt(tr.getAttribute("data-id"), 10);
        items.delete(id);
        render();
      });
    });
  }

  async function loadDepots() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/depots";
    if (token) url += "?api_token=" + encodeURIComponent(token);
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
    const rows = await r.json();
    depotEl.innerHTML = rows
      .map(
        (d) =>
          `<option value="${d.id}">${escapeHtml(d.name)}${
            d.code ? " (" + escapeHtml(d.code) + ")" : ""
          }</option>`
      )
      .join("");
  }

  async function propose() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const target = parseInt(targetEl.value || "10", 10);
    const dep = depotEl.value ? parseInt(depotEl.value, 10) : "";
    let url =
      routeBase +
      "/api/v1/orders/proposals?target=" +
      encodeURIComponent(target) +
      (dep ? "&depot_id=" + dep : "");
    if (token) url += "&api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/orders/proposals?target=" +
        encodeURIComponent(target) +
        (dep ? "&depot_id=" + dep : "") +
        (token ? "&api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const j = await r.json();
    (j.items || []).forEach((it) => {
      items.set(it.product_id, {
        id: it.product_id,
        name: it.product_name,
        qty: it.suggested_qty,
        current: it.current_stock,
        price: it.unit_cost,
      });
    });
    render();
  }

  function debounce(fn, ms) {
    let t;
    return function () {
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }
  const doProductSearch = debounce(async function () {
    const q = (searchInput.value || "").trim();
    if (q.length < 2) {
      if (searchMenu) {
        searchMenu.style.display = "none";
      }
      return;
    }
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/products?q=" + encodeURIComponent(q);
    if (token) url += "&api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/products?q=" +
        encodeURIComponent(q) +
        (token ? "&api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const rows = await r.json();
    if (!searchMenu) return;
    if (!rows || rows.length === 0) {
      searchMenu.innerHTML = '<div class="combo-empty">Aucun résultat</div>';
      searchMenu.style.display = "block";
      return;
    }
    searchMenu.innerHTML = rows
      .map((p) => {
        const stock = p.stock_total || 0;
        return `<div class="combo-item" data-id="${
          p.id
        }" data-name="${escapeHtml(p.name)}" data-price="${
          p.unit_price
        }" data-stock="${stock}">
        ${escapeHtml(p.name)} <span class="muted">${escapeHtml(
          p.sku || ""
        )}</span> · <strong>${stock}</strong>
      </div>`;
      })
      .join("");
    searchMenu.style.display = "block";
  }, 250);

  function hideMenuOutside() {
    document.addEventListener("click", function (ev) {
      if (!searchMenu || !searchInput) return;
      if (!searchMenu.contains(ev.target) && ev.target !== searchInput) {
        searchMenu.style.display = "none";
      }
    });
  }

  function wireSearch() {
    if (!searchInput) return;
    searchInput.addEventListener("input", doProductSearch);
    if (searchMenu) {
      searchMenu.addEventListener("click", function (ev) {
        const it = ev.target.closest(".combo-item");
        if (!it) return;
        const id = parseInt(it.getAttribute("data-id"), 10);
        const name = it.getAttribute("data-name");
        const price = parseInt(it.getAttribute("data-price") || "0", 10);
        const stock = parseInt(it.getAttribute("data-stock") || "0", 10);
        if (!items.has(id))
          items.set(id, { id, name, qty: 1, current: stock, price });
        else {
          const ex = items.get(id);
          ex.qty = (ex.qty || 0) + 1;
        }
        render();
        searchMenu.style.display = "none";
        searchInput.value = "";
      });
    }
    hideMenuOutside();
  }

  if (form) {
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      msg.textContent = "";
      const arr = Array.from(items.values()).filter((x) => (x.qty || 0) > 0);
      if (arr.length === 0) {
        msg.className = "alert alert-error";
        msg.textContent = "Ajoutez au moins un produit";
        return;
      }
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = routeBase + "/api/v1/orders";
      if (token) url += "?api_token=" + encodeURIComponent(token);
      const payload = {
        reference:
          (document.getElementById("o_reference")?.value || "").trim() ||
          undefined,
        supplier:
          (document.getElementById("o_supplier")?.value || "").trim() ||
          undefined,
        status: document.getElementById("o_status")?.value || "draft",
        depot_id: depotEl.value ? parseInt(depotEl.value, 10) : 1,
        items: arr.map((it) => ({
          product_id: it.id,
          quantity: it.qty || 0,
          unit_cost: it.price || 0,
        })),
      };
      let r = await fetch(url, {
        method: "POST",
        headers: Object.assign(
          { "Content-Type": "application/json" },
          authHeaders(token)
        ),
        body: JSON.stringify(payload),
      });
      if (r.status === 401) {
        token = (await refreshSessionToken()) || token;
        url =
          routeBase +
          "/api/v1/orders" +
          (token ? "?api_token=" + encodeURIComponent(token) : "");
        r = await fetch(url, {
          method: "POST",
          headers: Object.assign(
            { "Content-Type": "application/json" },
            authHeaders(token)
          ),
          body: JSON.stringify(payload),
        });
      }
      if (!r.ok) {
        msg.className = "alert alert-error";
        try {
          const j = await r.json();
          msg.textContent = "Erreur: " + (j.error || r.status);
        } catch (_) {
          msg.textContent = "Erreur lors de la création.";
        }
        return;
      }
      const j = await r.json();
      msg.className = "alert alert-success";
      msg.textContent = "Commande créée (" + (j.reference || "") + ")";
      items.clear();
      render();
    });
  }

  // init
  loadDepots();
  wireSearch();
  const btnProp = document.getElementById("btn-propose");
  if (btnProp) btnProp.addEventListener("click", propose);

  // Auto-reference generation
  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }
  const now = new Date();
  const baseTs =
    now.getFullYear() +
    "" +
    pad(now.getMonth() + 1) +
    pad(now.getDate()) +
    "-" +
    pad(now.getHours()) +
    pad(now.getMinutes()) +
    pad(now.getSeconds());
  function slug(s) {
    return (s || "")
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "")
      .substring(0, 12); // limit length
  }
  function updateReference() {
    if (!refInput) return;
    const sup = supplierInput ? supplierInput.value.trim() : "";
    const supSlug = slug(sup);
    refInput.value = "PO-" + (supSlug ? supSlug + "-" : "") + baseTs;
  }
  updateReference();
  if (supplierInput) {
    supplierInput.addEventListener("input", function () {
      updateReference();
    });
    supplierInput.addEventListener("blur", function () {
      updateReference();
    });
  }
})();
