(() => {
  const BASE = window.APP_BASE || "/hill_new/public";

  function getCookie(name) {
    const parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }

  const els = {
    period: document.getElementById("period-select"),
    depot: document.getElementById("depot-select"),
    depotLabel: document.getElementById("depot-label"),
    threshold: document.getElementById("threshold-select"),
  };

  function applyVisibility(v) {
    const toggle = (id, show) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.style.display = show ? "block" : "none";
    };
    toggle("kpi-receivables", !!v.finance);
    toggle("block-revenue", !!v.finance);
    toggle("kpi-cash-today", !!v.finance);
    toggle("kpi-collections-today", !!v.finance);
    toggle("kpi-stock", !!v.stocks);
    toggle("kpi-stock-valuation", !!v.stocks);
    toggle("card-low-stock", !!v.stocks);
    toggle("card-clients", !!v.clients);
    toggle("card-orders", !!v.orders);
    toggle("card-users", !!v.users);
    toggle("card-top-products", !!(v.finance || v.orders));
    toggle("quick-actions", v.role === "livreur" || !!(v.sales || v.clients));
  }

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
    if (els.threshold && els.threshold.value)
      q.set("threshold", els.threshold.value);

    const r = await fetch(
      BASE + "/api/v1/summary" + (q.toString() ? "?" + q.toString() : ""),
      { headers }
    );
    if (!r.ok) return;

    const data = await r.json();

    // Visibility
    applyVisibility(data.visibility || {});

    // Quick stats
    renderQuickStats(data.quick_stats, data.stock_total);

    // Sparkline
    renderSparkline(data.sparkline || []);

    // Revenue 30d
    renderRevenue30(data.revenue_30d || []);

    // Top products 30d
    renderTopProducts(data.top_products_30d || []);

    // Low stock
    renderLowStock(data.low_stock || [], els.threshold?.value);

    // Top client balances
    renderBalances(data.top_balances || []);

    // Daily sales (tu peux adapter pour ton tableau)
    if (data.daily?.rows && data.daily?.total_montant !== undefined) {
      renderDaily(data.daily.rows, data.daily.total_montant);
    }

    // Role hint
    const rh = document.getElementById("role-hint");
    if (rh && data.visibility?.role)
      rh.textContent = `Rôle: ${data.visibility.role}`;
  }

  function renderQuickStats(q, stockTotal) {
    if (!q) return;
    document.getElementById("qs-ca").textContent = q.ca_today ?? "—";
    document.getElementById("qs-sales").textContent = q.sales_today ?? "—";
    document.getElementById("qs-clients").textContent = q.active_clients ?? "—";
    const recv = document.getElementById("qs-receivables");
    if (recv) recv.textContent = q.receivables_total ?? "—";
    const st = document.getElementById("qs-stock");
    if (st) st.textContent = stockTotal ?? "—";
    const sv = document.getElementById("qs-stock-valuation");
    if (sv) sv.textContent = q.stock_valuation ?? "—";
  }

  function renderSparkline(points) {
    const el = document.getElementById("sparkline");
    if (!el || !points.length) return;
    const values = points.map((p) => p.value);
    const w = 160,
      h = 40,
      step = w / (values.length - 1);
    let d = "";
    const max = Math.max(...values, 1);
    values.forEach((v, i) => {
      const x = i * step;
      const y = h - (v / max) * (h - 4) - 2;
      d += (i === 0 ? "M" : "L") + x + "," + y;
    });
    const area = d + " L " + w + "," + h + " L 0," + h + " Z";
    el.innerHTML = `<svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">
      <path d="${area}" fill="rgba(255,212,0,0.25)"></path>
      <path d="${d}" fill="none" stroke="#FFC700" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></path>
    </svg>`;
  }

  function renderRevenue30(points) {
    const el = document.getElementById("chartRevenue30");
    if (!el || !window.Chart) return;
    new Chart(el.getContext("2d"), {
      type: "line",
      data: {
        labels: points.map((p) => p.date),
        datasets: [
          {
            label: "Chiffre d'affaires",
            data: points.map((p) => p.value),
            borderColor: "#0d6efd",
            backgroundColor: "rgba(13,110,253,0.15)",
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
    new Chart(el.getContext("2d"), {
      type: "bar",
      data: {
        labels: rows.map((r) => r.name),
        datasets: [
          {
            label: "Recette",
            data: rows.map((r) => parseInt(r.total, 10)),
            backgroundColor: "#FFC107",
          },
        ],
      },
      options: { plugins: { legend: { display: false } }, indexAxis: "y" },
    });
  }

  function renderLowStock(rows, threshold) {
    const c = document.getElementById("low-stock");
    if (!c) return;
    if (!rows.length) {
      c.innerHTML = "<div class='muted'>Aucune alerte</div>";
      return;
    }
    let html = `<table class="excel"><thead><tr><th>Produit</th><th>Stock</th></tr></thead><tbody>`;
    rows.forEach((r) => {
      html += `<tr><td>${r.name}</td><td>${r.qty}</td></tr>`;
    });
    html += "</tbody></table>";
    c.innerHTML = html;
    if (threshold)
      document.getElementById(
        "low-stock-title"
      ).textContent = `Produits en alerte stock (≤ ${threshold})`;
  }

  function renderBalances(data) {
    const el = document.getElementById("client-credit");
    if (!el) return;
    if (!data.length) {
      el.innerHTML = "<span class='muted'>Aucun client débiteur</span>";
      return;
    }
    let html = `<table class="table compact" style="width:100%"><thead><tr><th>Client</th><th>Solde dû</th></tr></thead><tbody>`;
    data.forEach((b) => {
      html += `<tr><td>${b.name}</td><td style="text-align:right">${parseInt(
        b.balance,
        10
      ).toLocaleString()} FCFA</td></tr>`;
    });
    html += "</tbody></table>";
    el.innerHTML = html;
  }

  function renderDaily(rows, total) {
    const c = document.getElementById("daily-sales");
    if (!c) return;
    let html = `<table class="excel"><thead><tr><th>Article</th><th>PU</th><th>Sorties</th><th>Retourné</th><th>Vendu</th><th>Montant</th></tr></thead><tbody>`;
    rows.forEach((r) => {
      html += `<tr><td>${r.name}</td><td>${r.unit_price ?? 0}</td><td>${
        r.sorties ?? 0
      }</td><td>${r.retourne ?? 0}</td><td>${r.vendu ?? 0}</td><td>${
        r.montant ?? 0
      }</td></tr>`;
    });
    html += `<tfoot><tr><th colspan="5">Total</th><th>${
      total ?? 0
    }</th></tr></tfoot></table>`;
    c.innerHTML = html;
  }

  // Refresh button
  const btn = document.getElementById("btn-refresh");
  if (btn) btn.addEventListener("click", fetchSummary);

  if (els.period) els.period.addEventListener("change", fetchSummary);
  if (els.depot) els.depot.addEventListener("change", fetchSummary);
  if (els.threshold) els.threshold.addEventListener("change", fetchSummary);

  fetchSummary();
})();
