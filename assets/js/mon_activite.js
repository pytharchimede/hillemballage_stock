(function () {
  const BASE = (window.API_BASE || "").replace(/\/$/, "");
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

  const elRounds = document.getElementById("ma-rounds");
  const elStatus = document.getElementById("ma-status");
  const elFrom = document.getElementById("ma-from");
  const elTo = document.getElementById("ma-to");
  const elSearch = document.getElementById("ma-search");
  const elExportCsv = document.getElementById("ma-export-csv");
  const elExportPdf = document.getElementById("ma-export-pdf");
  const elDetail = document.getElementById("ma-round-detail");
  const elQ = document.getElementById("ma-q");
  const elViewMode = document.getElementById("ma-view-mode");
  let me = null;
  let rows = [];
  let filtered = [];

  async function loadMe() {
    const r = await fetch(BASE + "/api/v1/auth/me", { headers: authHeaders() });
    if (r.ok) me = await r.json();
  }

  function renderRounds() {
    const list = filtered && filtered.length ? filtered : rows;
    if (!list.length) {
      elRounds.innerHTML = '<div class="muted">Aucune tournée</div>';
      return;
    }
    const mode = (elViewMode?.value || "grid").trim();
    let h = "";
    if (mode === "carousel") {
      h +=
        '<div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:6px">';
    } else {
      h +=
        '<div class="grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px">';
    }
    list.forEach((r) => {
      const title = `#${r.id} • ${r.status}`;
      const sub = `Ouverture: ${r.assigned_at || ""} ${
        r.closed_at ? " • Clôture: " + r.closed_at : ""
      }`;
      const styleCard = mode === "carousel" ? "min-width:260px" : "";
      h += `<div class="card" data-r="${r.id}" style="padding:10px;${styleCard}">
        <div style="font-weight:600">${title}</div>
        <div class="muted" style="font-size:12px">${sub}</div>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn-ghost" data-view="${r.id}">Voir</button>
          <button class="btn-ghost" data-export-pdf="${r.id}">PDF</button>
          <button class="btn-ghost" data-export-csv="${r.id}">CSV</button>
        </div>
      </div>`;
    });
    h += "</div>";
    elRounds.innerHTML = h;
    elRounds
      .querySelectorAll("[data-view]")
      .forEach((b) =>
        b.addEventListener("click", () =>
          loadDetail(parseInt(b.getAttribute("data-view"), 10))
        )
      );
    elRounds
      .querySelectorAll("[data-export-pdf]")
      .forEach((b) =>
        b.addEventListener("click", () =>
          exportRound(parseInt(b.getAttribute("data-export-pdf"), 10), "pdf")
        )
      );
    elRounds
      .querySelectorAll("[data-export-csv]")
      .forEach((b) =>
        b.addEventListener("click", () =>
          exportRound(parseInt(b.getAttribute("data-export-csv"), 10), "csv")
        )
      );
  }

  async function loadRounds() {
    if (!me) await loadMe();
    if (!me || !me.id) {
      elRounds.innerHTML =
        '<div class="muted">Utilisateur non authentifié</div>';
      return;
    }
    const qs = new URLSearchParams();
    const st = (elStatus.value || "").trim();
    if (st) qs.set("status", st);
    qs.set("user_id", String(me.id));
    const f = (elFrom.value || "").trim();
    if (f) qs.set("from", f);
    const t = (elTo.value || "").trim();
    if (t) qs.set("to", t);
    try {
      const r = await fetch(BASE + "/api/v1/seller-rounds?" + qs.toString(), {
        headers: authHeaders(),
      });
      rows = r.ok ? await r.json() : [];
      applyQuickFilter();
      renderRounds();
    } catch (_) {
      rows = [];
      renderRounds();
    }
  }

  function applyQuickFilter() {
    const q = (elQ?.value || "").trim().toLowerCase();
    if (!q) {
      filtered = rows.slice();
      return;
    }
    filtered = rows.filter((r) => {
      const idMatch =
        ("#" + String(r.id)).toLowerCase().includes(q) ||
        String(r.id).includes(q);
      const user = (r.user_name || "#" + r.user_id || "").toLowerCase();
      const depot = (r.depot_name || String(r.depot_id) || "").toLowerCase();
      return (
        idMatch ||
        user.includes(q) ||
        depot.includes(q) ||
        (r.status || "").toLowerCase().includes(q)
      );
    });
  }

  async function loadDetail(id) {
    try {
      const r = await fetch(BASE + "/api/v1/seller-rounds/" + id + "/stats", {
        headers: authHeaders(),
      });
      if (!r.ok) {
        elDetail.textContent = "Impossible de charger le détail.";
        return;
      }
      const j = await r.json();
      const items = Array.isArray(j.items) ? j.items : [];
      let h =
        '<table class="excel"><thead><tr><th>Produit</th><th>Attribué</th><th>Vendu</th><th>Retourné</th><th>Reste</th></tr></thead><tbody>';
      items.forEach((it) => {
        h += `<tr><td>${it.name || "#" + it.product_id}</td><td>${
          it.qty_assigned || 0
        }</td><td>${it.qty_sold || 0}</td><td>${it.qty_returned || 0}</td><td>${
          it.qty_remaining || 0
        }</td></tr>`;
      });
      h += "</tbody></table>";
      const totals = j.totals || {};
      h += `<div style="margin-top:8px">Vendu: ${
        totals.sales_amount || 0
      } FCFA • Payé: ${totals.payments_amount || 0} FCFA</div>`;
      elDetail.innerHTML = h;
    } catch (_) {
      elDetail.textContent = "Erreur de chargement du détail.";
    }
  }

  function exportList(fmt) {
    if (!me || !me.id) {
      return;
    }
    const qs = new URLSearchParams();
    const st = (elStatus.value || "").trim();
    if (st) qs.set("status", st);
    qs.set("user_id", String(me.id));
    const f = (elFrom.value || "").trim();
    if (f) qs.set("from", f);
    const t = (elTo.value || "").trim();
    if (t) qs.set("to", t);
    qs.set("format", fmt);
    const url = BASE + "/api/v1/seller-rounds/export?" + qs.toString();
    window.open(url, "_blank");
  }

  function exportRound(id, fmt) {
    const url = BASE + "/api/v1/seller-rounds/" + id + "/export?format=" + fmt;
    window.open(url, "_blank");
  }

  if (elSearch) elSearch.addEventListener("click", loadRounds);
  if (elExportCsv)
    elExportCsv.addEventListener("click", () => exportList("csv"));
  if (elExportPdf)
    elExportPdf.addEventListener("click", () => exportList("pdf"));
  if (elQ)
    elQ.addEventListener("input", () => {
      applyQuickFilter();
      renderRounds();
    });
  if (elViewMode) elViewMode.addEventListener("change", renderRounds);

  loadRounds();
})();
