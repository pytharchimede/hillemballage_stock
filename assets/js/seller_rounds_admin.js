(function () {
  const routeBase = window.ROUTE_BASE || "";
  function authHeaders(token) {
    return token ? { Authorization: "Bearer " + token } : {};
  }
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

  const els = {
    roundId: document.getElementById("round-id"),
    btnLoad: document.getElementById("btn-load-round"),
    btnApply: document.getElementById("btn-apply"),
    roundSummary: document.getElementById("round-summary"),
    salesGrid: document.getElementById("sales-grid"),
    itemsGrid: document.getElementById("items-grid"),
    collectionsGrid: document.getElementById("collections-grid"),
  };

  let state = { round: null, sales: [], items: [], collections: [] };

  function escapeHtml(s) {
    if (s === null || s === undefined) return "";
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function render() {
    // Summary
    if (state.round && els.roundSummary) {
      els.roundSummary.innerHTML =
        "Tournée #" +
        escapeHtml(state.round.id) +
        " — Dépôt: " +
        escapeHtml(state.round.depot_id) +
        " — Statut: " +
        escapeHtml(state.round.status) +
        " — Assignée: " +
        escapeHtml(state.round.assigned_at || "");
    }
    // Sales
    if (els.salesGrid) {
      const html = [
        '<table class="table"><thead><tr><th>ID</th><th>Client</th><th>Total</th><th>Payé</th><th>Corriger Payé</th><th>Action</th></tr></thead><tbody>',
      ];
      state.sales.forEach((s) => {
        html.push(
          "<tr>" +
            "<td>" +
            escapeHtml(s.id) +
            "</td>" +
            "<td>" +
            escapeHtml(s.client_name || "#" + s.client_id) +
            "</td>" +
            "<td>" +
            escapeHtml(s.total_amount) +
            "</td>" +
            "<td>" +
            escapeHtml(s.paid_amount) +
            "</td>" +
            '<td><input type="number" class="inp-paid" data-id="' +
            escapeHtml(s.id) +
            '" value="' +
            escapeHtml(s.paid_amount || 0) +
            '" min="0" /></td>' +
            '<td><button class="btn small btn-danger del-sale" data-id="' +
            escapeHtml(s.id) +
            '"><i class="fa fa-trash"></i> Supprimer</button></td>' +
            "</tr>"
        );
      });
      html.push("</tbody></table>");
      els.salesGrid.innerHTML = html.join("");
    }
    // Items
    if (els.itemsGrid) {
      const html = [
        '<table class="table"><thead><tr><th>ID</th><th>Vente</th><th>Produit</th><th>Qté</th><th>Retours</th><th>PU</th><th>Corriger Qté</th><th>Corriger Retours</th><th>Corriger PU</th></tr></thead><tbody>',
      ];
      state.items.forEach((it) => {
        html.push(
          "<tr>" +
            "<td>" +
            escapeHtml(it.id) +
            "</td>" +
            "<td>" +
            escapeHtml(it.sale_id) +
            "</td>" +
            "<td>" +
            escapeHtml(it.product_name || "#" + it.product_id) +
            "</td>" +
            "<td>" +
            escapeHtml(it.quantity) +
            "</td>" +
            "<td>" +
            escapeHtml(it.returned_quantity || 0) +
            "</td>" +
            "<td>" +
            escapeHtml(it.unit_price || 0) +
            "</td>" +
            '<td><input type="number" class="inp-qty" data-id="' +
            escapeHtml(it.id) +
            '" value="' +
            escapeHtml(it.quantity || 0) +
            '" min="0" /></td>' +
            '<td><input type="number" class="inp-ret" data-id="' +
            escapeHtml(it.id) +
            '" value="' +
            escapeHtml(it.returned_quantity || 0) +
            '" min="0" /></td>' +
            '<td><input type="number" class="inp-up" data-id="' +
            escapeHtml(it.id) +
            '" value="' +
            escapeHtml(it.unit_price || 0) +
            '" min="0" /></td>' +
            "</tr>"
        );
      });
      html.push("</tbody></table>");
      els.itemsGrid.innerHTML = html.join("");
    }
    // Collections
    if (els.collectionsGrid) {
      const html = [
        '<table class="table"><thead><tr><th>ID</th><th>Client</th><th>Montant</th><th>Méthode</th><th>Corriger Montant</th><th>Corriger Méthode</th></tr></thead><tbody>',
      ];
      state.collections.forEach((c) => {
        html.push(
          "<tr>" +
            "<td>" +
            escapeHtml(c.id) +
            "</td>" +
            "<td>" +
            escapeHtml(c.client_id) +
            "</td>" +
            "<td>" +
            escapeHtml(c.amount || 0) +
            "</td>" +
            "<td>" +
            escapeHtml(c.method || "") +
            "</td>" +
            '<td><input type="number" class="inp-col-amt" data-id="' +
            escapeHtml(c.id) +
            '" value="' +
            escapeHtml(c.amount || 0) +
            '" min="0" /></td>' +
            '<td><input type="text" class="inp-col-meth" data-id="' +
            escapeHtml(c.id) +
            '" value="' +
            escapeHtml(c.method || "") +
            '" /></td>' +
            "</tr>"
        );
      });
      html.push("</tbody></table>");
      els.collectionsGrid.innerHTML = html.join("");
    }
    // Bind delete buttons
    document.querySelectorAll(".del-sale").forEach((btn) => {
      btn.addEventListener("click", async function () {
        const id = this.getAttribute("data-id");
        if (
          !confirm(
            "Supprimer la vente #" +
              id +
              " ? Cette action impacte la comptabilité."
          )
        )
          return;
        let token =
          localStorage.getItem("api_token") || readCookieToken() || "";
        const url = routeBase + "/api/v1/admin/sales/" + encodeURIComponent(id);
        let r = await fetch(url, {
          method: "DELETE",
          headers: authHeaders(token),
        });
        if (r.status === 401) {
          token = (await refreshSessionToken()) || token;
          r = await fetch(url, {
            method: "DELETE",
            headers: authHeaders(token),
          });
        }
        if (!r.ok) {
          try {
            const j = await r.json();
            alert("Erreur: " + (j.error || "Suppression"));
          } catch (_) {
            alert("Erreur suppression");
          }
          return;
        }
        // Remove sale from state and re-render
        state.sales = state.sales.filter((s) => String(s.id) !== String(id));
        state.items = state.items.filter(
          (it) => String(it.sale_id) !== String(id)
        );
        render();
      });
    });
  }

  async function loadRound() {
    const rid = parseInt(els.roundId.value || "0", 10);
    if (!rid) {
      alert("Saisir un ID de tournée");
      return;
    }
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/admin/rounds/" + rid;
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) {
      try {
        const j = await r.json();
        alert("Erreur: " + (j.error || "Chargement"));
      } catch (_) {
        alert("Erreur chargement");
      }
      return;
    }
    const j = await r.json();
    state.round = j.round || null;
    state.sales = j.sales || [];
    state.items = j.items || [];
    state.collections = j.collections || [];
    render();
  }

  async function applyCorrections() {
    const rid = parseInt(els.roundId.value || "0", 10);
    if (!rid) {
      alert("Saisir un ID de tournée");
      return;
    }
    // Collect corrections from inputs
    const items = Array.from(document.querySelectorAll(".inp-qty")).map(
      (inp) => ({
        id: parseInt(inp.getAttribute("data-id"), 10),
        quantity: parseInt(inp.value || "0", 10),
      })
    );
    document.querySelectorAll(".inp-ret").forEach((inp) => {
      const obj = items.find(
        (x) => x.id === parseInt(inp.getAttribute("data-id"), 10)
      );
      if (obj) obj.returned_quantity = parseInt(inp.value || "0", 10);
    });
    document.querySelectorAll(".inp-up").forEach((inp) => {
      const obj = items.find(
        (x) => x.id === parseInt(inp.getAttribute("data-id"), 10)
      );
      if (obj) obj.unit_price = parseInt(inp.value || "0", 10);
    });
    const sales = Array.from(document.querySelectorAll(".inp-paid")).map(
      (inp) => ({
        id: parseInt(inp.getAttribute("data-id"), 10),
        paid_amount: parseInt(inp.value || "0", 10),
      })
    );
    const cols = Array.from(document.querySelectorAll(".inp-col-amt")).map(
      (inp) => ({
        id: parseInt(inp.getAttribute("data-id"), 10),
        amount: parseInt(inp.value || "0", 10),
      })
    );
    document.querySelectorAll(".inp-col-meth").forEach((inp) => {
      const obj = cols.find(
        (x) => x.id === parseInt(inp.getAttribute("data-id"), 10)
      );
      if (obj) obj.method = String(inp.value || "");
    });

    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/admin/rounds/corrections";
    let r = await fetch(url, {
      method: "PATCH",
      headers: { "Content-Type": "application/json", ...authHeaders(token) },
      body: JSON.stringify({ round_id: rid, items, sales, collections: cols }),
    });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      r = await fetch(url, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify({
          round_id: rid,
          items,
          sales,
          collections: cols,
        }),
      });
    }
    if (!r.ok) {
      try {
        const j = await r.json();
        alert("Erreur: " + (j.error || "Corrections"));
      } catch (_) {
        alert("Erreur corrections");
      }
      return;
    }
    alert("Corrections appliquées");
    loadRound();
  }

  if (els.btnLoad) els.btnLoad.addEventListener("click", loadRound);
  if (els.btnApply) els.btnApply.addEventListener("click", applyCorrections);
})();
