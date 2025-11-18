(() => {
  function getCookie(name) {
    const parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }
  function authHeaders() {
    const token = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    return token ? { Authorization: "Bearer " + token } : {};
  }

  const els = {
    from: document.getElementById("from"),
    to: document.getElementById("to"),
    client: document.getElementById("client-select"),
    filter: document.getElementById("btn-filter"),
    table: document.getElementById("sales-table"),
  };

  const clientMap = new Map();

  async function loadClients() {
    try {
      const r = await fetch("/api/v1/clients", { headers: authHeaders() });
      if (!r.ok) return;
      const rows = await r.json();
      if (!Array.isArray(rows)) return;
      els.client.innerHTML = '<option value="">Tous</option>';
      rows.forEach((c) => {
        const o = document.createElement("option");
        o.value = c.id;
        o.textContent = c.name || "Client #" + c.id;
        els.client.appendChild(o);
        clientMap.set(String(c.id), c.name || "Client #" + c.id);
      });
    } catch (_) {}
  }

  function buildQuery() {
    const q = new URLSearchParams();
    if (els.from && els.from.value) q.set("from", els.from.value);
    if (els.to && els.to.value) q.set("to", els.to.value);
    if (els.client && els.client.value) q.set("client_id", els.client.value);
    return q.toString();
  }

  async function loadSales() {
    if (!els.table) return;
    els.table.textContent = "Chargement...";
    try {
      const query = buildQuery();
      const r = await fetch("/api/v1/sales" + (query ? "?" + query : ""), {
        headers: authHeaders(),
      });
      if (!r.ok) {
        els.table.innerHTML =
          '<div class="muted">Aucune donnée ou accès refusé</div>';
        return;
      }
      const rows = await r.json();
      if (!Array.isArray(rows) || rows.length === 0) {
        els.table.innerHTML = '<div class="muted">Aucune vente</div>';
        return;
      }
      let html =
        '<table class="excel"><thead><tr>' +
        "<th>#</th><th>Client</th><th>Vendeur</th><th>Dépôt</th>" +
        "<th>Total</th><th>Payé</th><th>Statut</th><th>Date</th>" +
        "</tr></thead><tbody>";
      rows.forEach((s) => {
        const cname = clientMap.get(String(s.client_id)) || (s.client_id ?? "");
        html += `<tr>
          <td>${s.id}</td>
          <td>${cname}</td>
          <td>${s.user_id ?? ""}</td>
          <td>${s.depot_id ?? ""}</td>
          <td>${s.total_amount ?? 0}</td>
          <td>${s.amount_paid ?? 0}</td>
          <td>${s.status || ""}</td>
          <td>${s.sold_at || ""}</td>
        </tr>`;
      });
      html += "</tbody></table>";
      els.table.innerHTML = html;
    } catch (e) {
      els.table.innerHTML = '<div class="muted">Erreur de chargement</div>';
    }
  }

  if (els.filter) els.filter.addEventListener("click", loadSales);

  // Init date range par défaut = aujourd'hui
  try {
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, "0");
    const dd = String(today.getDate()).padStart(2, "0");
    const d = `${yyyy}-${mm}-${dd}`;
    if (els.from && !els.from.value) els.from.value = d;
    if (els.to && !els.to.value) els.to.value = d;
  } catch (_) {}

  loadClients();
  loadSales();
})();
