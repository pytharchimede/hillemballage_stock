(function () {
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
  const elDepot = document.getElementById("sq-depot");
  const elSearch = document.getElementById("sq-search");
  const elProducts = document.getElementById("sq-products");
  const elCart = document.getElementById("sq-cart");
  const elTotal = document.getElementById("sq-total");
  const elSubmit = document.getElementById("sq-submit");
  const elClientOpen = document.getElementById("sq-client-open");
  const elClientSelected = document.getElementById("sq-client-selected");
  const elPaid = document.getElementById("sq-paid");
  const elCashAll = document.getElementById("sq-cash-all");
  const elHint = document.getElementById("sq-hint");
  const elRoundInfo = document.getElementById("sq-round-info");
  const elCollect = document.getElementById("sq-collect");
  const elCollectSubmit = document.getElementById("sq-collect-submit");
  const elCollectHint = document.getElementById("sq-collect-hint");
  const elClientStats = document.getElementById("sq-client-stats");

  let currentRound = null; // {id, depot_id, items: []}

  let products = [];
  let cart = [];
  let selectedClient = null; // {id, name, phone}
  let roundStats = null; // stats backend {items, totals}

  function renderSelectedClient() {
    if (!elClientSelected) return;
    if (selectedClient && selectedClient.id) {
      const bal =
        typeof selectedClient.balance === "number" ? selectedClient.balance : 0;
      const label = `${selectedClient.name || "Client"}${
        selectedClient.id ? " (#" + selectedClient.id + ")" : ""
      }${
        selectedClient.phone ? " • " + selectedClient.phone : ""
      } • Solde: ${formatFCFA(bal)}`;
      elClientSelected.textContent = label;
      elClientSelected.classList.remove("muted");
      elClientSelected.style.fontWeight = "600";
      loadClientStats();
    } else {
      elClientSelected.textContent = "Aucun client sélectionné";
      elClientSelected.classList.add("muted");
      elClientSelected.style.fontWeight = "";
      if (elClientStats) elClientStats.textContent = "";
    }
  }

  function formatAmount(v) {
    return String(v || 0);
  }

  function formatFCFA(v) {
    try {
      return new Intl.NumberFormat("fr-FR").format(v || 0) + " FCFA";
    } catch (_) {
      return String(v || 0) + " FCFA";
    }
  }

  function computeTotal() {
    const t = cart.reduce((a, it) => a + it.unit_price * it.quantity, 0);
    elTotal.textContent = formatFCFA(t);
    if (elCashAll && elCashAll.checked) {
      elPaid.value = t > 0 ? String(t) : "";
    }
    updateHint(t);
    return t;
  }

  function updateHint(totalVal) {
    if (!elHint) return;
    const t =
      typeof totalVal === "number"
        ? totalVal
        : cart.reduce((a, it) => a + it.unit_price * it.quantity, 0);
    const bal =
      selectedClient && typeof selectedClient.balance === "number"
        ? selectedClient.balance
        : 0;
    const paid = parseInt(elPaid?.value || "0", 10) || 0;
    const remaining = Math.max(0, t - paid);
    elHint.textContent = `Total: ${formatFCFA(t)} • Solde client: ${formatFCFA(
      bal
    )} • Reste à payer: ${formatFCFA(remaining)}`;
  }

  function renderCart() {
    if (!cart.length) {
      elCart.innerHTML = '<div class="muted">Aucun article</div>';
      computeTotal();
      return;
    }
    let h =
      '<table class="excel"><thead><tr><th>Produit</th><th>Qté</th><th>PU</th><th>Sous-total</th><th></th></tr></thead><tbody>';
    cart.forEach((it, idx) => {
      const sub = it.unit_price * it.quantity;
      h += `<tr>
        <td>${it.name}</td>
        <td>
          <button class="btn-ghost small" data-dec="${idx}">−</button>
          <span style="display:inline-block;min-width:24px;text-align:center">${
            it.quantity
          }</span>
          <button class="btn-ghost small" data-inc="${idx}">+</button>
        </td>
        <td>${formatFCFA(it.unit_price)}</td>
        <td>${formatFCFA(sub)}</td>
        <td><button class="btn-ghost small" data-rm="${idx}">Retirer</button></td>
      </tr>`;
    });
    h += "</tbody></table>";
    elCart.innerHTML = h;
    elCart.querySelectorAll("[data-inc]").forEach((b) =>
      b.addEventListener("click", () => {
        const i = parseInt(b.getAttribute("data-inc"), 10);
        const it = cart[i];
        const prod = products.find((p) => p.id === it.product_id);
        const stock =
          (prod && (prod.stock_depot ?? prod.stock_total ?? 0)) || 0;
        if (it.quantity + 1 > stock) {
          window.showToast && window.showToast("error", "Stock insuffisant");
          return;
        }
        it.quantity += 1;
        renderCart();
        computeTotal();
      })
    );
    elCart.querySelectorAll("[data-dec]").forEach((b) =>
      b.addEventListener("click", () => {
        const i = parseInt(b.getAttribute("data-dec"), 10);
        const it = cart[i];
        it.quantity -= 1;
        if (it.quantity <= 0) cart.splice(i, 1);
        renderCart();
        computeTotal();
      })
    );
    elCart.querySelectorAll("[data-rm]").forEach((b) =>
      b.addEventListener("click", () => {
        const i = parseInt(b.getAttribute("data-rm"), 10);
        cart.splice(i, 1);
        renderCart();
        computeTotal();
      })
    );
    computeTotal();
  }

  function renderProducts(list) {
    if (!list.length) {
      elProducts.innerHTML = '<div class="muted">Aucun produit</div>';
      return;
    }
    let h =
      '<div class="grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px">';
    list.forEach((p) => {
      const stock = p.stock_depot ?? p.stock_total ?? 0;
      h += `<button class="card" data-p="${
        p.id
      }" style="padding:8px;text-align:left">
        <div style="font-weight:600">${p.name}</div>
        <div class="muted" style="font-size:12px">PU: ${formatFCFA(
          p.unit_price
        )}</div>
        <div class="muted" style="font-size:12px">Stock: ${stock}</div>
      </button>`;
    });
    h += "</div>";
    elProducts.innerHTML = h;
    elProducts.querySelectorAll("[data-p]").forEach((btn) =>
      btn.addEventListener("click", () => {
        const pid = parseInt(btn.getAttribute("data-p"), 10);
        const prod = products.find((x) => x.id === pid);
        if (!prod) return;
        const stock = prod.stock_depot ?? prod.stock_total ?? 0;
        const existing = cart.find((c) => c.product_id === pid);
        const nextQty = (existing ? existing.quantity : 0) + 1;
        if (nextQty > stock) {
          window.showToast && window.showToast("error", "Stock insuffisant");
          return;
        }
        if (existing) existing.quantity += 1;
        else
          cart.push({
            product_id: pid,
            name: prod.name,
            unit_price: prod.unit_price || 0,
            quantity: 1,
          });
        renderCart();
      })
    );
  }

  // Chargement automatique du stock du livreur (tournée ouverte)
  async function loadSellerRoundProducts() {
    try {
      // 1. Récupérer l'utilisateur courant
      const meResp = await fetch(BASE + "/api/v1/auth/me", {
        headers: authHeaders(),
      });
      const me = meResp.ok ? await meResp.json() : null;
      const userId = me?.id;
      if (!userId) {
        elProducts.innerHTML =
          '<div class="muted">Utilisateur non identifié</div>';
        return;
      }
      // 2. Récupérer les tournées ouvertes
      const roundsResp = await fetch(
        BASE + "/api/v1/seller-rounds?status=open",
        {
          headers: authHeaders(),
        }
      );
      const rounds = roundsResp.ok ? await roundsResp.json() : [];
      // 3. Filtrer la tournée du livreur
      const myRounds = (rounds || []).filter((r) => r.user_id === userId);
      if (!myRounds.length) {
        elProducts.innerHTML =
          '<div class="muted">Aucune tournée ouverte pour vous</div>';
        return;
      }
      // Choisir la dernière (id max)
      const round = myRounds.sort((a, b) => (b.id || 0) - (a.id || 0))[0];
      currentRound = round || null;
      await loadRoundStats();
      const depotId = round.depot_id || 0;
      if (elDepot) {
        // Insérer silencieusement le dépôt pour réutiliser la logique existante
        elDepot.innerHTML = "";
        const opt = document.createElement("option");
        opt.value = String(depotId);
        opt.textContent = round.depot_name || "Dépôt #" + depotId;
        elDepot.appendChild(opt);
        elDepot.value = String(depotId);
      }
      // 4. Charger les produits du dépôt (prix, infos) puis restreindre au stock attribué
      const prodResp = await fetch(
        BASE + `/api/v1/products?depot_id=${depotId}&only_in_stock=1`,
        { headers: authHeaders() }
      );
      let depotProducts = prodResp.ok ? await prodResp.json() : [];
      depotProducts = Array.isArray(depotProducts) ? depotProducts : [];
      const assigned = Array.isArray(round.items) ? round.items : [];
      // Mapper les produits uniquement attribués, et fixer le stock à la quantité assignée (approximation)
      const statItems = (roundStats?.items || []).reduce((acc, si) => {
        acc[si.product_id] = si;
        return acc;
      }, {});
      products = assigned
        .map((it) => {
          const full = depotProducts.find((p) => p.id === it.product_id) || {};
          const st = statItems[it.product_id] || {};
          const remaining =
            st.qty_remaining != null ? st.qty_remaining : it.qty_assigned || 0;
          return {
            id: it.product_id,
            name: it.name || full.name || "#" + it.product_id,
            unit_price: full.unit_price || 0,
            stock_depot: remaining,
            stock_total: remaining,
            qty_assigned: st.qty_assigned || it.qty_assigned || 0,
            qty_sold: st.qty_sold || 0,
            qty_returned: st.qty_returned || 0,
          };
        })
        .filter((p) => p.stock_depot > 0);
      if (!products.length) {
        elProducts.innerHTML =
          '<div class="muted">Aucun produit attribué</div>';
        renderRoundInfo();
        return;
      }
      applySearch();
      renderRoundInfo();
    } catch (e) {
      elProducts.innerHTML =
        '<div class="muted">Erreur chargement stock tournée</div>';
      renderRoundInfo();
    }
  }

  // Ancienne fonction de chargement par dépôt conservée pour compatibilité mais non utilisée désormais
  async function loadProducts() {
    loadSellerRoundProducts();
  }

  function applySearch() {
    const q = (elSearch.value || "").toLowerCase().trim();
    const filtered = q
      ? products.filter(
          (p) =>
            (p.name || "").toLowerCase().includes(q) ||
            (p.sku || "").toLowerCase().includes(q)
        )
      : products;
    renderProducts(filtered);
  }

  // Suppression de la sélection interactive du dépôt (tournée automatique)
  if (elSearch) elSearch.addEventListener("input", applySearch);
  if (elCashAll) elCashAll.addEventListener("change", () => computeTotal());
  if (elPaid) elPaid.addEventListener("input", () => updateHint());
  if (elCollectSubmit) {
    elCollectSubmit.addEventListener("click", async () => {
      if (!selectedClient || !selectedClient.id) {
        window.showToast && window.showToast("error", "Sélectionner un client");
        return;
      }
      const amt = parseInt(elCollect.value, 10) || 0;
      if (amt <= 0) {
        window.showToast && window.showToast("error", "Montant invalide");
        return;
      }
      try {
        const r = await fetch(
          BASE + "/api/v1/clients/" + selectedClient.id + "/collect",
          {
            method: "POST",
            headers: { "Content-Type": "application/json", ...authHeaders() },
            body: JSON.stringify({ amount: amt }),
          }
        );
        const j = r.ok ? await r.json() : null;
        if (!r.ok) {
          window.showToast && window.showToast("error", "Recouvrement échoué");
          return;
        }
        window.showToast &&
          window.showToast(
            "success",
            "Recouvrement appliqué " + formatFCFA(amt)
          );
        if (typeof j.balance === "number") {
          selectedClient.balance = j.balance;
        }
        elCollect.value = "";
        renderSelectedClient();
        updateHint();
        loadClientStats();
      } catch (_) {
        window.showToast && window.showToast("error", "Erreur réseau");
      }
    });
  }

  if (elSubmit) {
    elSubmit.addEventListener("click", async () => {
      const depotId = parseInt(elDepot?.value, 10) || 0; // fixé automatiquement via la tournée
      if (!depotId) {
        window.showToast && window.showToast("error", "Aucune tournée / dépôt");
        return;
      }
      if (!cart.length) {
        window.showToast && window.showToast("error", "Panier vide");
        return;
      }
      if (!selectedClient || !selectedClient.id) {
        window.showToast &&
          window.showToast("error", "Sélectionner ou créer un client");
        return;
      }
      const total = computeTotal();
      const paid = parseInt(elPaid.value, 10) || 0;
      const body = {
        depot_id: depotId,
        client_id: selectedClient.id,
        seller_round_id:
          currentRound && currentRound.id ? currentRound.id : null,
        items: cart.map((it) => ({
          product_id: it.product_id,
          quantity: it.quantity,
          unit_price: it.unit_price,
        })),
        payment_amount: paid,
      };
      console.log("Submitting sale", body);
      try {
        const r = await fetch(BASE + "/api/v1/sales", {
          method: "POST",
          headers: { "Content-Type": "application/json", ...authHeaders() },
          body: JSON.stringify(body),
        });
        if (!r.ok) {
          const errText = await r.text();
          console.error("Erreur API vente:", errText); // Ajout log explicite
          window.showToast &&
            window.showToast("error", "Vente échouée: " + errText);
          return;
        }
        const j = await r.json();
        window.showToast &&
          window.showToast("success", "Vente créée #" + (j.sale?.id || ""));
        if (currentRound && currentRound.id) {
          await loadRoundStats();
          const statItems2 = (roundStats?.items || []).reduce((acc, si) => {
            acc[si.product_id] = si;
            return acc;
          }, {});
          products.forEach((p) => {
            const st = statItems2[p.id] || {};
            p.stock_depot =
              st.qty_remaining != null ? st.qty_remaining : p.stock_depot;
            p.stock_total = p.stock_depot;
            p.qty_sold = st.qty_sold || 0;
          });
          applySearch();
          renderRoundInfo();
        }
        // Reset
        cart = [];
        renderCart();
        elPaid.value = "";
        applySearch();
        updateHint(0);
        const credit = Math.max(0, total - paid);
        if (credit > 0 && window.showToast) {
          window.showToast("info", "Crédit client: " + formatFCFA(credit));
        }
        // Rafraîchir solde client
        try {
          const rc = await fetch(
            BASE + "/api/v1/clients/" + selectedClient.id,
            { headers: authHeaders() }
          );
          if (rc.ok) {
            const cj = await rc.json();
            if (cj && typeof cj.balance === "number") {
              selectedClient.balance = cj.balance;
              renderSelectedClient();
              updateHint();
              loadClientStats();
            }
          }
        } catch (_) {}
      } catch (e) {
        window.showToast && window.showToast("error", "Erreur réseau");
      }
    });
  }

  // --- Client picker / creator modal ---
  async function fetchClients() {
    try {
      const r = await fetch(BASE + "/api/v1/clients", {
        headers: authHeaders(),
      });
      if (!r.ok) return [];
      return (await r.json()) || [];
    } catch (_) {
      return [];
    }
  }

  function openClientModal() {
    const dlg = document.createElement("div");
    dlg.className = "modal";
    dlg.innerHTML = `<div class="modal-content">
      <h3>Client</h3>
      <div class="grid-2" style="gap:12px">
        <div>
          <div class="muted" style="margin-bottom:6px">Client existant</div>
          <input type="text" id="cl-search" class="form-control compact" placeholder="Rechercher nom/téléphone" />
          <div id="cl-list" style="margin-top:8px; max-height:320px; overflow:auto">Chargement...</div>
        </div>
        <div>
          <div class="muted" style="margin-bottom:6px">Nouveau client</div>
          <label class="muted">Nom</label>
          <input type="text" id="cl-new-name" class="form-control compact" placeholder="Ex: Koffi" />
          <label class="muted" style="margin-top:6px">Téléphone</label>
          <input type="text" id="cl-new-phone" class="form-control compact" placeholder="Ex: 0700000000" />
          <label class="muted" style="margin-top:6px">Adresse</label>
          <input type="text" id="cl-new-address" class="form-control compact" placeholder="Optionnel" />
          <div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end">
            <button id="cl-cancel" class="btn-ghost">Annuler</button>
            <button id="cl-create" class="btn">Créer et sélectionner</button>
          </div>
        </div>
      </div>
    </div>`;
    document.body.appendChild(dlg);

    const elSearchBox = dlg.querySelector("#cl-search");
    const elList = dlg.querySelector("#cl-list");
    const elCancel = dlg.querySelector("#cl-cancel");
    const elCreate = dlg.querySelector("#cl-create");

    elCancel.addEventListener("click", () => dlg.remove());

    let clients = [];
    function renderList(rows) {
      if (!rows.length) {
        elList.innerHTML = '<div class="muted">Aucun client</div>';
        return;
      }
      let h = '<div style="display:flex; flex-direction:column; gap:6px">';
      rows.forEach((c) => {
        const bal = typeof c.balance === "number" ? c.balance : 0;
        const label = `${c.name || "Client"}${c.id ? " (#" + c.id + ")" : ""}${
          c.phone ? " • " + c.phone : ""
        } • Solde: ${formatFCFA(bal)}`;
        h += `<button class="btn-ghost" data-c="${c.id}" style="text-align:left">${label}</button>`;
      });
      h += "</div>";
      elList.innerHTML = h;
      elList.querySelectorAll("[data-c]").forEach((btn) =>
        btn.addEventListener("click", () => {
          const id = parseInt(btn.getAttribute("data-c"), 10);
          const cli = clients.find((x) => x.id === id);
          if (cli) {
            selectedClient = {
              id: cli.id,
              name: cli.name,
              phone: cli.phone || "",
              balance: typeof cli.balance === "number" ? cli.balance : 0,
            };
            renderSelectedClient();
            dlg.remove();
          }
        })
      );
    }

    fetchClients().then((rows) => {
      clients = rows || [];
      renderList(clients);
    });

    elSearchBox.addEventListener("input", () => {
      const q = (elSearchBox.value || "").toLowerCase().trim();
      const filtered = q
        ? clients.filter(
            (c) =>
              (c.name || "").toLowerCase().includes(q) ||
              (c.phone || "").toLowerCase().includes(q)
          )
        : clients;
      renderList(filtered);
    });

    elCreate.addEventListener("click", async () => {
      const name = dlg.querySelector("#cl-new-name").value.trim();
      const phone = dlg.querySelector("#cl-new-phone").value.trim();
      const address = dlg.querySelector("#cl-new-address").value.trim();
      if (!name) {
        window.showToast && window.showToast("error", "Nom requis");
        return;
      }
      try {
        const r = await fetch(BASE + "/api/v1/clients", {
          method: "POST",
          headers: { "Content-Type": "application/json", ...authHeaders() },
          body: JSON.stringify({ name, phone, address }),
        });
        if (!r.ok) {
          const t = await r.text();
          window.showToast &&
            window.showToast("error", t || "Création échouée");
          return;
        }
        const j = await r.json();
        selectedClient = { id: j.id, name, phone, balance: 0 };
        renderSelectedClient();
        dlg.remove();
      } catch (_) {
        window.showToast && window.showToast("error", "Erreur réseau");
      }
    });
  }

  if (elClientOpen) {
    elClientOpen.addEventListener("click", (e) => {
      e.preventDefault();
      openClientModal();
    });
  }

  function renderRoundInfo() {
    if (!elRoundInfo) return;
    if (!currentRound) {
      elRoundInfo.textContent = "Aucune tournée active.";
      return;
    }
    const items = Array.isArray(currentRound.items) ? currentRound.items : [];
    if (!items.length) {
      elRoundInfo.textContent = `Tournée #${currentRound.id}: aucun article.`;
      return;
    }
    let h = `Tournée #${currentRound.id} – Articles attribués: `;
    h += items
      .map(
        (it) =>
          `${(it.name || "#" + it.product_id).replace(/<[^>]*>/g, "")} x ${
            it.qty_assigned || 0
          }`
      )
      .join(", ");
    // Ajouter info ventes courantes
    const totals = roundStats?.totals || null;
    if (totals) {
      const credit = Math.max(
        0,
        (totals.sales_amount || 0) - (totals.payments_amount || 0)
      );
      h += ` | Vendu: ${formatFCFA(
        totals.sales_amount || 0
      )} • Payé: ${formatFCFA(
        totals.payments_amount || 0
      )} • Crédit: ${formatFCFA(credit)}`;
    }
    elRoundInfo.textContent = h;
  }

  // Init
  // Chargement initial: tournée du livreur
  loadSellerRoundProducts();
  renderCart();
  renderSelectedClient();
  updateHint(0);
  async function loadRoundStats() {
    if (!currentRound || !currentRound.id) return;
    try {
      const r = await fetch(
        BASE + "/api/v1/seller-rounds/" + currentRound.id + "/stats",
        { headers: authHeaders() }
      );
      if (r.ok) {
        roundStats = await r.json();
      }
    } catch (_) {}
  }
  async function loadClientStats() {
    if (!selectedClient || !selectedClient.id || !elClientStats) return;
    try {
      const r = await fetch(
        BASE + "/api/v1/clients/" + selectedClient.id + "/stats",
        { headers: authHeaders() }
      );
      if (!r.ok) {
        elClientStats.textContent = "";
        return;
      }
      const j = await r.json();
      const sales = formatFCFA(j.sales_today || 0);
      const payments = formatFCFA(j.payments_today || 0);
      const credit = formatFCFA(j.balance || 0);
      elClientStats.textContent = `Ventes du jour: ${sales} • Recouvrements du jour: ${payments} • Crédit actuel: ${credit}`;
    } catch (_) {
      elClientStats.textContent = "";
    }
  }
})();
