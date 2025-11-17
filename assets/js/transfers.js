(function () {
  const routeBase = window.ROUTE_BASE || "";
  const form = document.getElementById("transfer-form");
  if (!form) return;
  let token = localStorage.getItem("api_token") || "";
  let headers = token ? { Authorization: "Bearer " + token } : {};
  const msg = document.getElementById("transfer-msg");
  let DEPOTS = [];
  let PRODUCTS = [];

  async function ensureAuth() {
    if (!token) {
      try {
        const tr = await fetch(routeBase + "/api/v1/auth/session-token");
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            token = tj.token;
            localStorage.setItem("api_token", token);
            document.cookie = "api_token=" + token + "; path=/";
            headers = { Authorization: "Bearer " + token };
          }
        }
      } catch (_) {}
    }
  }

  async function loadDepots() {
    await ensureAuth();
    let url = routeBase + "/api/v1/depots";
    if (token) url += "?api_token=" + encodeURIComponent(token);
    const r = await fetch(url, { headers });
    if (!r.ok) return [];
    return await r.json();
  }
  async function loadProducts() {
    await ensureAuth();
    let url = routeBase + "/api/v1/products";
    if (token) url += "?api_token=" + encodeURIComponent(token);
    const r = await fetch(url, { headers });
    if (!r.ok) return [];
    return await r.json();
  }
  function optionLabelDepot(d) {
    const code = d.code ? ` (${d.code})` : "";
    const manager = d.manager_name ? ` — ${d.manager_name}` : "";
    return `${d.name}${code}${manager}`;
  }
  function optionLabelProduct(p) {
    const sku = p.sku ? ` [${p.sku}]` : "";
    return `${p.name}${sku}`;
  }
  function fillSelect(sel, items, valueKey, labelFn) {
    const opts = items
      .map((i) => `<option value="${i[valueKey]}">${labelFn(i)}</option>`)
      .join("");
    sel.innerHTML = opts;
  }
  function bindSelectSearch(wrapperEl, sourceItems, labelFn, valueKey) {
    const input = wrapperEl.querySelector('input[type="text"]');
    const select = wrapperEl.querySelector("select");
    if (!input || !select) return;
    const render = (term) => {
      const t = (term || "").toLowerCase().trim();
      const filtered = !t
        ? sourceItems
        : sourceItems.filter((it) => {
            const label = labelFn(it).toLowerCase();
            return label.includes(t);
          });
      const current = select.value;
      fillSelect(select, filtered, valueKey, labelFn);
      // Try to keep previous selection if still visible
      if (
        current &&
        Array.from(select.options).some((o) => o.value === current)
      ) {
        select.value = current;
      }
    };
    input.addEventListener("input", () => render(input.value));
    // Initial render with empty term (already filled by caller, but keep consistent)
    render("");
  }

  async function init() {
    const [depots, products] = await Promise.all([
      loadDepots(),
      loadProducts(),
    ]);
    DEPOTS = depots;
    PRODUCTS = products;
    const fromSel = document.getElementById("from_depot");
    const toSel = document.getElementById("to_depot");
    const prodSel = document.getElementById("product_id");
    fillSelect(fromSel, DEPOTS, "id", optionLabelDepot);
    fillSelect(toSel, DEPOTS, "id", optionLabelDepot);
    fillSelect(prodSel, PRODUCTS, "id", optionLabelProduct);

    // Bind search boxes
    const wrapFrom = document.querySelector(
      '.select-search[data-target="from_depot"]'
    );
    const wrapTo = document.querySelector(
      '.select-search[data-target="to_depot"]'
    );
    const wrapProd = document.querySelector(
      '.select-search[data-target="product_id"]'
    );
    if (wrapFrom) bindSelectSearch(wrapFrom, DEPOTS, optionLabelDepot, "id");
    if (wrapTo) bindSelectSearch(wrapTo, DEPOTS, optionLabelDepot, "id");
    if (wrapProd)
      bindSelectSearch(wrapProd, PRODUCTS, optionLabelProduct, "id");
    loadHistory();
  }

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    msg.textContent = "";
    const payload = {
      from_depot_id: parseInt(form.from_depot_id.value, 10),
      to_depot_id: parseInt(form.to_depot_id.value, 10),
      product_id: parseInt(form.product_id.value, 10),
      quantity: parseInt(form.quantity.value, 10),
    };
    await ensureAuth();
    let url = routeBase + "/api/v1/stock/transfer";
    if (token) url += "?api_token=" + encodeURIComponent(token);
    const r = await fetch(url, {
      method: "POST",
      headers: Object.assign({ "Content-Type": "application/json" }, headers),
      body: JSON.stringify(payload),
    });
    if (r.ok) {
      msg.className = "alert alert-success";
      msg.textContent = "Transfert effectué avec succès.";
      form.reset();
      loadHistory();
    } else {
      try {
        const err = await r.json();
        msg.className = "alert alert-error";
        msg.textContent = "Erreur: " + (err.error || r.status);
      } catch (_) {
        msg.className = "alert alert-error";
        msg.textContent = "Erreur lors du transfert.";
      }
    }
  });

  async function loadHistory() {
    await ensureAuth();
    const tbody = document.getElementById("transfers-history-body");
    const empty = document.getElementById("transfers-history-empty");
    if (tbody) {
      tbody.innerHTML =
        '<tr><td colspan="5" style="opacity:.7">Chargement…</td></tr>';
    }
    let url = routeBase + "/api/v1/stock/transfers?limit=200";
    if (token) url += "&api_token=" + encodeURIComponent(token);
    const r = await fetch(url, { headers });
    if (!r.ok) {
      if (tbody)
        tbody.innerHTML = '<tr><td colspan="5">Erreur de chargement</td></tr>';
      return;
    }
    const rows = await r.json();
    if (!rows || rows.length === 0) {
      if (tbody) tbody.innerHTML = "";
      if (empty) empty.style.display = "block";
      return;
    }
    if (empty) empty.style.display = "none";
    const trHtml = rows
      .map(function (t) {
        const d = new Date(t.moved_at.replace(" ", "T"));
        const dateStr = isNaN(d.getTime())
          ? t.moved_at || ""
          : d.toLocaleString();
        const from =
          (t.from_depot_name || "") +
          (t.from_depot_code ? " (" + t.from_depot_code + ")" : "");
        const to =
          (t.to_depot_name || "") +
          (t.to_depot_code ? " (" + t.to_depot_code + ")" : "");
        return (
          "<tr>" +
          "<td>" +
          escapeHtml(dateStr) +
          "</td>" +
          "<td>" +
          escapeHtml(t.product_name || "") +
          "</td>" +
          "<td>" +
          escapeHtml(from) +
          "</td>" +
          "<td>" +
          escapeHtml(to) +
          "</td>" +
          '<td style="text-align:right">' +
          (t.quantity || 0) +
          "</td>" +
          "</tr>"
        );
      })
      .join("");
    if (tbody) tbody.innerHTML = trHtml;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  init();
})();
