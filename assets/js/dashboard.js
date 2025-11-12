(() => {
  function getCookie(name) {
    const parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }

  async function fetchSummary() {
    const token = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    const headers = token ? { Authorization: "Bearer " + token } : {};
    const r = await fetch("/api/v1/summary", { headers });
    if (!r.ok) {
      const c = document.getElementById("client-credit");
      if (c) c.innerHTML = '<div class="muted">Connectez-vous</div>';
      return;
    }
    const data = await r.json();
    renderDaily(data.daily.rows, data.daily.total_montant);
    renderBalances(
      (data.top_balances || []).map((x) => ({
        name: x.name,
        balance: parseInt(x.balance, 10),
      }))
    );
    const st = document.getElementById("stock-summary");
    if (st) st.innerHTML = `<div class="big">${data.stock_total}</div>`;
    renderQuickStats(data.quick_stats);
    renderSparkline(data.sparkline || []);
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

  function renderQuickStats(q) {
    if (!q) return;
    let ca = document.getElementById("qs-ca");
    let sales = document.getElementById("qs-sales");
    let clients = document.getElementById("qs-clients");
    if (ca) ca.textContent = q.ca_today;
    if (sales) sales.textContent = q.sales_today;
    if (clients) clients.textContent = q.active_clients;
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

  const btn = document.getElementById("btn-refresh");
  if (btn) {
    btn.addEventListener("click", () => {
      fetchSummary();
    });
  }

  fetchSummary();
})();
