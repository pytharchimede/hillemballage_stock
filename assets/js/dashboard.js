(() => {
  function getCookie(name) {
    const parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }

  const els = {
    period: document.getElementById("period-select"),
    depot: document.getElementById("depot-select"),
    depotLabel: document.getElementById("depot-label"),
  };

  async function fetchSummary() {
    const token = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    const headers = token ? { Authorization: "Bearer " + token } : {};
    const q = new URLSearchParams();
    if (els.period && els.period.value) q.set("days", els.period.value);
    if (els.depot && els.depot.style.display !== "none" && els.depot.value)
      q.set("depot_id", els.depot.value);
    const r = await fetch(
      "/api/v1/summary" + (q.toString() ? "?" + q.toString() : ""),
      { headers }
    );
    if (!r.ok) {
      const c = document.getElementById("client-credit");
      if (c) c.innerHTML = '<div class="muted">Connectez-vous</div>';
      return;
    }
    const data = await r.json();
    // Role hint
    const rh = document.getElementById("role-hint");
    if (rh && data.visibility && data.visibility.role) {
      rh.textContent = `Rôle: ${data.visibility.role}`;
    }

    // Show depot select for admin only
    if (data.visibility && data.visibility.role === "admin") {
      if (els.depotLabel) els.depotLabel.style.display = "inline-block";
      if (els.depot) {
        els.depot.style.display = "inline-block";
        if (!els.depot.dataset.loaded) await loadDepots(headers);
      }
    } else {
      if (els.depotLabel) els.depotLabel.style.display = "none";
      if (els.depot) els.depot.style.display = "none";
    }

    applyVisibility(data.visibility || {});

    renderDaily(data.daily.rows, data.daily.total_montant);
    renderBalances(
      (data.top_balances || []).map((x) => ({
        name: x.name,
        balance: parseInt(x.balance, 10),
      }))
    );
    renderQuickStats(data.quick_stats, data.stock_total);
    renderSparkline(data.sparkline || []);

    renderRevenue30(data.revenue_30d || []);
    renderTopProducts(data.top_products_30d || []);
    renderOrdersStatus(data.orders_status || []);
    renderSalesByUser(data.sales_by_user_30d || []);
    renderLowStock(data.low_stock || []);
    renderLatestSales(data.latest_sales || []);
  }

  function renderDaily(rows, total) {
    const c = document.getElementById("daily-sales");
    if (!c) return;
    let html = `<table class="excel"><thead><tr><th>Article</th><th>PU</th><th>Sorties</th><th>Retourné</th><th>Vendu</th><th>Montant</th></tr></thead><tbody>`;
    rows.forEach((r) => {
      html += `<tr><td>${r.name}</td><td>${r.unit_price}</td><td>${r.sorties}</td><td>${r.retourne}</td><td>${r.vendu}</td><td>${r.montant}</td></tr>`;
    });
    html += `</tbody><tfoot><tr><th colspan="5">Total</th><th>${total}</th></tr></tfoot></table>`;
    c.innerHTML = html;
  }

  function renderBalances(list) {
    const c = document.getElementById("client-credit");
    if (!c) return;
    if (!list.length) {
      c.innerHTML = '<div class="muted">Aucun crédit</div>';
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>Client</th><th>Solde</th></tr></thead><tbody>';
    list.forEach((x) => {
      h += `<tr><td>${x.name}</td><td>${x.balance}</td></tr>`;
    });
    h += "</tbody></table>";
    c.innerHTML = h;
  }

  function renderQuickStats(q, stockTotal) {
    if (!q) return;
    let ca = document.getElementById("qs-ca");
    let sales = document.getElementById("qs-sales");
    let clients = document.getElementById("qs-clients");
    let recv = document.getElementById("qs-receivables");
    let st = document.getElementById("qs-stock");
    if (ca) ca.textContent = q.ca_today;
    if (sales) sales.textContent = q.sales_today;
    if (clients) clients.textContent = q.active_clients;
    if (recv) recv.textContent = q.receivables_total ?? "—";
    if (st) st.textContent = stockTotal ?? "—";
  }

  function renderSparkline(points) {
    const el = document.getElementById("sparkline");
    if (!el) return;
    if (!points.length) {
      el.innerHTML = "";
      return;
    }
    const values = points.map((p) => p.value);
    const max = Math.max(...values, 1);
    const w = 160,
      h = 40,
      step = w / (values.length - 1);
    let d = "";
    values.forEach((v, i) => {
      const x = i * step;
      const y = h - (v / max) * (h - 4) - 2;
      d += (i === 0 ? "M" : "L") + x + "," + y;
    });
    // area fill
    let area = d + " L " + w + "," + h + " L 0," + h + " Z";
    el.innerHTML = `<svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">
        <path d="${area}" fill="rgba(255,212,0,0.25)"></path>
        <path d="${d}" fill="none" stroke="#FFC700" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></path>
      </svg>`;
  }

  async function loadDepots(headers) {
    try {
      const r = await fetch("/api/v1/depots", { headers });
      if (!r.ok) return;
      const rows = await r.json();
      if (!Array.isArray(rows)) return;
      if (!els.depot) return;
      els.depot.innerHTML = "";
      const optAll = document.createElement("option");
      optAll.value = "";
      optAll.textContent = "Tous les dépôts";
      els.depot.appendChild(optAll);
      rows.forEach((d) => {
        const o = document.createElement("option");
        o.value = d.id;
        o.textContent = `${d.name}${d.code ? " (" + d.code + ")" : ""}`;
        els.depot.appendChild(o);
      });
      els.depot.dataset.loaded = "1";
    } catch (_) {}
  }

  function applyVisibility(v) {
    const toggle = (id, show) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.style.display = show ? "" : "none";
    };
    // Finance-driven blocks
    toggle("kpi-receivables", !!v.finance);
    toggle("block-revenue", !!v.finance);
    // Stocks
    toggle("kpi-stock", !!v.stocks);
    toggle("card-low-stock", !!v.stocks);
    // Clients
    toggle("card-clients", !!v.clients);
    // Orders
    toggle("card-orders", !!v.orders);
    // Users
    toggle("card-users", !!v.users);
  }

  function renderRevenue30(points) {
    const el = document.getElementById("chartRevenue30");
    if (!el || !window.Chart) return;
    const labels = points.map((p) => p.date);
    const data = points.map((p) => p.value);
    new Chart(el.getContext("2d"), {
      type: "line",
      data: {
        labels,
        datasets: [
          {
            label: "Chiffre d'affaires",
            data,
            borderColor: "#0d6efd",
            backgroundColor: "rgba(13,110,253,.15)",
            tension: 0.2,
            fill: true,
          },
        ],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: { x: { display: false } },
      },
    });
  }

  function renderTopProducts(rows) {
    const el = document.getElementById("chartTopProducts");
    if (!el || !window.Chart) return;
    const labels = rows.map((r) => r.name);
    const data = rows.map((r) => parseInt(r.total, 10));
    new Chart(el.getContext("2d"), {
      type: "bar",
      data: {
        labels,
        datasets: [{ label: "Recette", data, backgroundColor: "#FFC107" }],
      },
      options: { plugins: { legend: { display: false } }, indexAxis: "y" },
    });
  }

  function renderOrdersStatus(rows) {
    const el = document.getElementById("chartOrdersStatus");
    if (!el || !window.Chart) return;
    const labels = rows.map((r) => r.status);
    const data = rows.map((r) => parseInt(r.c, 10));
    new Chart(el.getContext("2d"), {
      type: "doughnut",
      data: {
        labels,
        datasets: [
          {
            data,
            backgroundColor: [
              "#0d6efd",
              "#198754",
              "#dc3545",
              "#fd7e14",
              "#6c757d",
            ],
          },
        ],
      },
      options: { plugins: { legend: { position: "bottom" } } },
    });
  }

  function renderSalesByUser(rows) {
    const el = document.getElementById("chartByUser");
    if (!el || !window.Chart) return;
    const labels = rows.map((r) => r.name || "#" + r.id);
    const data = rows.map((r) => parseInt(r.total, 10));
    new Chart(el.getContext("2d"), {
      type: "bar",
      data: {
        labels,
        datasets: [{ label: "CA", data, backgroundColor: "#20c997" }],
      },
      options: { plugins: { legend: { display: false } } },
    });
  }

  function renderLowStock(rows) {
    const c = document.getElementById("low-stock");
    if (!c) return;
    if (!rows.length) {
      c.innerHTML = '<div class="muted">Aucune alerte</div>';
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>Produit</th><th>Stock</th></tr></thead><tbody>';
    rows.forEach((r) => {
      h += `<tr><td>${r.name}</td><td>${r.qty}</td></tr>`;
    });
    h += "</tbody></table>";
    c.innerHTML = h;
  }

  function renderLatestSales(rows) {
    // Optionnel: à brancher si on ajoute un bloc pour lister les dernières ventes
  }

  const btn = document.getElementById("btn-refresh");
  if (btn) {
    btn.addEventListener("click", () => {
      fetchSummary();
    });
  }

  if (els.period) els.period.addEventListener("change", fetchSummary);
  if (els.depot) els.depot.addEventListener("change", fetchSummary);

  fetchSummary();
})();
