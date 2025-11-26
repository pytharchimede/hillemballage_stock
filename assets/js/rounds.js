(() => {
  const BASE = window.APP_BASE || "";
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

  function formatAmount(val) {
    val = Number(val) || 0;
    return val.toLocaleString("fr-FR") + " FCFA";
  }

  const elDepot = document.getElementById("sr-depot");
  const elSeller = document.getElementById("sr-seller");
  const elProduct = document.getElementById("sr-product");
  const elQty = document.getElementById("sr-qty");
  const elAdd = document.getElementById("sr-add-item");
  const elItems = document.getElementById("sr-items");
  const elCreate = document.getElementById("sr-create");
  const elMsg = document.getElementById("sr-create-msg");
  const elOpen = document.getElementById("sr-open");
  const elClosed = document.getElementById("sr-closed");

  const items = [];

  async function loadDepots() {
    try {
      const r = await fetch(BASE + "/api/v1/depots", {
        headers: authHeaders(),
      });
      if (!r.ok) throw new Error("depots");
      const rows = await r.json();
      elDepot.innerHTML = "";
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "— Mon dépôt —";
      elDepot.appendChild(opt);
      rows.forEach((d) => {
        const o = document.createElement("option");
        o.value = d.id;
        o.textContent = `${d.name}${d.code ? " (" + d.code + ")" : ""}`;
        elDepot.appendChild(o);
      });
    } catch (_) {
      elDepot.innerHTML = '<option value="">—</option>';
    }
  }

  async function loadSellers() {
    try {
      const dep = parseInt(elDepot.value, 10) || "";
      const q = dep ? "?role=livreur&depot_id=" + dep : "?role=livreur";
      const r = await fetch(BASE + "/api/v1/users/brief" + q, {
        headers: authHeaders(),
      });
      if (!r.ok) throw new Error("users");
      const rows = await r.json();
      elSeller.innerHTML = "";
      const ph = document.createElement("option");
      ph.value = "";
      ph.textContent = "Sélectionner un livreur";
      elSeller.appendChild(ph);
      rows.forEach((u) => {
        const o = document.createElement("option");
        o.value = u.id;
        o.textContent = `${u.name} (#${u.id})`;
        elSeller.appendChild(o);
      });
    } catch (_) {
      elSeller.innerHTML = '<option value="">(aucun utilisateur)</option>';
    }
  }

  async function loadProducts() {
    try {
      const dep = parseInt(elDepot.value, 10) || "";
      const q = dep ? `?depot_id=${dep}&only_in_stock=1` : "";
      const r = await fetch(BASE + "/api/v1/products" + q, {
        headers: authHeaders(),
      });
      const rows = (await r.json()) || [];
      elProduct.innerHTML = "";
      rows.forEach((p) => {
        const o = document.createElement("option");
        o.value = p.id;
        const stock = p.stock_depot ?? p.stock_total ?? 0;
        o.textContent = `${p.name} (stock:${stock})`;
        o.dataset.stock = String(stock);
        if (dep) {
          elProduct.appendChild(o);
        } else if (stock > 0) {
          elProduct.appendChild(o);
        }
      });
      if (!elProduct.options.length) {
        const ph = document.createElement("option");
        ph.value = "";
        ph.textContent = "Aucun produit en stock";
        elProduct.appendChild(ph);
      }
    } catch (_) {}
  }

  function renderItems() {
    if (!items.length) {
      elItems.innerHTML = '<div class="muted">Aucun article</div>';
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>Produit</th><th>Qté</th><th></th></tr></thead><tbody>';
    items.forEach((it, idx) => {
      h += `<tr><td>${it.name}</td><td>${it.quantity}</td><td><button data-i="${idx}" class="btn btn-ghost small rm">Retirer</button></td></tr>`;
    });
    h += "</tbody></table>";
    elItems.innerHTML = h;
    elItems.querySelectorAll("button.rm").forEach((b) =>
      b.addEventListener("click", (e) => {
        const i = parseInt(e.currentTarget.getAttribute("data-i"), 10);
        if (!isNaN(i)) {
          items.splice(i, 1);
          renderItems();
        }
      })
    );
  }

  if (elAdd) {
    elAdd.addEventListener("click", () => {
      const pid = parseInt(elProduct.value, 10);
      const qty = parseInt(elQty.value, 10);
      if (!pid || !qty || qty <= 0) return;
      const stock =
        parseInt(
          elProduct.options[elProduct.selectedIndex]?.dataset.stock || "0",
          10
        ) || 0;
      // Somme des quantités déjà ajoutées pour ce produit
      const used = items
        .filter((it) => it.product_id === pid)
        .reduce((a, b) => a + (b.quantity || 0), 0);
      if (qty + used > stock) {
        const rest = Math.max(0, stock - used);
        elMsg.textContent = `Quantité demandée (${qty}) dépasse le stock disponible (${rest} restant).`;
        if (window.showToast) {
          window.showToast("error", `Stock insuffisant: ${rest} restant`);
        }
        return;
      }
      const name =
        elProduct.options[elProduct.selectedIndex]?.textContent || `#${pid}`;
      items.push({ product_id: pid, quantity: qty, name });
      elQty.value = "";
      renderItems();
    });
  }

  if (elCreate) {
    elCreate.addEventListener("click", async () => {
      elMsg.textContent = "";
      if (!items.length) {
        elMsg.textContent = "Ajouter au moins un article.";
        return;
      }
      let userId = parseInt(elSeller.value, 10) || 0;
      if (!userId) {
        elMsg.textContent = "Sélectionner un livreur.";
        return;
      }
      const depotId = parseInt(elDepot.value, 10) || undefined;
      try {
        const r = await fetch(BASE + "/api/v1/seller-rounds", {
          method: "POST",
          headers: { "Content-Type": "application/json", ...authHeaders() },
          body: JSON.stringify({ depot_id: depotId, user_id: userId, items }),
        });
        if (!r.ok) throw new Error(await r.text());
        const j = await r.json();
        elMsg.textContent = `Tournée créée (#${j.round_id})`;
        items.splice(0, items.length);
        renderItems();
        loadRounds();
      } catch (e) {
        elMsg.textContent = "Erreur création tournée";
      }
    });
  }

  async function loadRounds() {
    try {
      const r1 = await fetch(BASE + "/api/v1/seller-rounds?status=open", {
        headers: authHeaders(),
      });
      const open = (await r1.json()) || [];
      renderRounds(elOpen, open, true);
    } catch (_) {
      elOpen.textContent = "Erreur";
    }
    try {
      const r2 = await fetch(BASE + "/api/v1/seller-rounds?status=closed", {
        headers: authHeaders(),
      });
      const closed = (await r2.json()) || [];
      renderRounds(elClosed, closed, false);
    } catch (_) {
      elClosed.textContent = "Erreur";
    }
  }

  function renderRounds(container, rows, closable) {
    if (!rows.length) {
      container.innerHTML = '<div class="muted">Aucune donnée</div>';
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>#</th><th>Dépôt</th><th>Livreur</th><th>Statut</th><th>Assignée</th><th>Cash</th><th>Actions</th></tr></thead><tbody>';
    rows.forEach((r) => {
      h += `<tr><td>${r.id}</td><td>${r.depot_name || r.depot_id}</td><td>${
        r.user_name || "#" + r.user_id
      }</td><td>${r.status}</td><td>${(r.assigned_at || "")
        .toString()
        .slice(0, 19)}</td><td>${r.cash_turned_in || 0}</td><td>`;
      if (closable)
        h += `<button class="btn small" data-close="${r.id}">Clôturer</button>`;
      h += `</td></tr>`;
      if (Array.isArray(r.items) && r.items.length) {
        h +=
          `<tr><td colspan="7"><div class="muted">Articles: ` +
          r.items
            .map((i) => `${i.name || "#" + i.product_id} x ${i.qty_assigned}`)
            .join(", ") +
          `</div></td></tr>`;
      }
    });
    h += "</tbody></table>";
    container.innerHTML = h;
    if (closable) {
      container.querySelectorAll("button[data-close]").forEach((b) => {
        b.addEventListener("click", () =>
          openCloseDialog(parseInt(b.getAttribute("data-close"), 10))
        );
      });
    }
  }

  function openCloseDialog(roundId) {
    const dlg = document.createElement("div");
    dlg.className = "modal";
    dlg.innerHTML = `<div class="modal-content"><h3>Clôturer tournée #${roundId}</h3>
      <div id="close-items">Chargement items...</div>
      <div id="close-summary" class="muted" style="margin-top:8px;font-size:12px"></div>
      <div style="margin-top:8px">
        <label class="muted">Cash remis (auto)</label>
        <input id="close-cash" type="number" min="0" class="form-control" style="width:160px" readonly />
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end">
        <button id="close-cancel" class="btn btn-ghost">Annuler</button>
        <button id="close-submit" class="btn">Clôturer</button>
      </div></div>`;
    document.body.appendChild(dlg);

    const closeItems = dlg.querySelector("#close-items");
    const btnCancel = dlg.querySelector("#close-cancel");
    const btnSubmit = dlg.querySelector("#close-submit");

    btnCancel.addEventListener("click", () => dlg.remove());

    // Charger les stats backend directement
    fetch(BASE + `/api/v1/seller-rounds/${roundId}/stats`, {
      headers: authHeaders(),
    })
      .then((r) => {
        if (!r.ok) {
          closeItems.textContent = `Erreur stats (HTTP ${r.status})`;
          console.error("Réponse API non OK :", r);
          return null;
        }
        return r.json();
      })
      .then((stats) => {
        if (!stats || !Array.isArray(stats.items)) {
          closeItems.textContent = "Stats indisponibles";
          console.error("Réponse stats API :", stats);
          return;
        }
        let h =
          '<table class="excel"><thead><tr><th>Article</th><th>Attribué</th><th>Vendu</th><th>Retourné (auto)</th><th>Reste</th></tr></thead><tbody>';
        stats.items.forEach((it) => {
          const name =
            typeof it.name !== "undefined" ? it.name : "#" + it.product_id;
          const assigned = it.qty_assigned || 0;
          const sold = it.qty_sold || 0;
          const returned = it.qty_returned || 0;
          const remaining =
            typeof it.qty_remaining !== "undefined"
              ? it.qty_remaining
              : Math.max(0, assigned - sold - returned);
          const autoReturn = Math.max(0, assigned - sold);
          h += `<tr><td>${name}</td><td>${assigned}</td><td>${sold}</td><td>${autoReturn}</td><td>${remaining}</td></tr>`;
        });
        h += "</tbody></table>";
        closeItems.innerHTML = h;
        const payments = stats.totals?.payments_amount || 0;
        const salesAmt = stats.totals?.sales_amount || 0;
        const credit = Math.max(0, salesAmt - payments);
        const cs = dlg.querySelector("#close-summary");
        if (cs) {
          cs.textContent = `Vendu: ${formatAmount(
            salesAmt
          )} • Payé: ${formatAmount(payments)} • Crédit: ${formatAmount(
            credit
          )}`;
        }
        dlg.querySelector("#close-cash").value = String(payments);
        dlg.dataset.roundStatsItems = JSON.stringify(stats.items);
        dlg.dataset.roundStatsPayments = String(payments);
      })
      .catch((err) => {
        closeItems.textContent = "Erreur stats (exception JS)";
        console.error("Erreur JS stats API :", err);
      });

    btnSubmit.addEventListener("click", async () => {
      // Calcul auto des retours via stats (assigné - vendu)
      let returns = [];
      try {
        const items = JSON.parse(dlg.dataset.roundStatsItems || "[]");
        returns = items
          .map((it) => {
            const assigned = it.qty_assigned || 0;
            const sold = it.qty_sold || 0;
            const ret = Math.max(0, assigned - sold);
            return { product_id: it.product_id, quantity: ret };
          })
          .filter((r) => r.quantity > 0);
      } catch (_) {}
      const cash = parseInt(dlg.dataset.roundStatsPayments || "0", 10) || 0;
      try {
        const r = await fetch(BASE + `/api/v1/seller-rounds/${roundId}`, {
          method: "PATCH",
          headers: { "Content-Type": "application/json", ...authHeaders() },
          body: JSON.stringify({ returns, cash_turned_in: cash }),
        });
        if (!r.ok) throw new Error(await r.text());
        dlg.remove();
        loadRounds();
      } catch (e) {
        alert("Erreur de clôture");
      }
    });
  }

  loadDepots();
  loadSellers();
  loadProducts();
  renderItems();
  loadRounds();

  // Mettre à jour les listes quand le dépôt change
  if (elDepot) {
    elDepot.addEventListener("change", () => {
      loadSellers();
      loadProducts();
    });
  }
  // Mettre à jour la contrainte de quantité selon le stock sélectionné
  function syncQtyMax() {
    const stock =
      parseInt(
        elProduct?.options[elProduct.selectedIndex]?.dataset.stock || "0",
        10
      ) || 0;
    if (elQty) {
      elQty.max = String(stock);
    }
  }
  if (elProduct) {
    elProduct.addEventListener("change", syncQtyMax);
    syncQtyMax();
  }
})();
