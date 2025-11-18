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
  const elClient = document.getElementById("sq-client");
  const elPaid = document.getElementById("sq-paid");
  const elCashAll = document.getElementById("sq-cash-all");

  let products = [];
  let cart = [];

  function formatAmount(v) {
    return String(v || 0);
  }

  function computeTotal() {
    const t = cart.reduce((a, it) => a + it.unit_price * it.quantity, 0);
    elTotal.textContent = formatAmount(t);
    if (elCashAll && elCashAll.checked) {
      elPaid.value = t > 0 ? String(t) : "";
    }
    return t;
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
          <span style="display:inline-block;min-width:24px;text-align:center">${it.quantity}</span>
          <button class="btn-ghost small" data-inc="${idx}">+</button>
        </td>
        <td>${it.unit_price}</td>
        <td>${sub}</td>
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
      h += `<button class="card" data-p="${p.id}" style="padding:8px;text-align:left">
        <div style="font-weight:600">${p.name}</div>
        <div class="muted" style="font-size:12px">PU: ${p.unit_price}</div>
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

  async function loadDepots() {
    try {
      const r = await fetch(BASE + "/api/v1/depots", {
        headers: authHeaders(),
      });
      const rows = (await r.json()) || [];
      elDepot.innerHTML = "";
      const ph = document.createElement("option");
      ph.value = "";
      ph.textContent = "— Sélectionner —";
      elDepot.appendChild(ph);
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

  async function loadProducts() {
    const dep = parseInt(elDepot.value, 10) || 0;
    if (!dep) {
      elProducts.innerHTML = '<div class="muted">Choisir un dépôt</div>';
      return;
    }
    try {
      const r = await fetch(
        BASE + `/api/v1/products?depot_id=${dep}&only_in_stock=1`,
        {
          headers: authHeaders(),
        }
      );
      products = (await r.json()) || [];
      applySearch();
    } catch (_) {
      elProducts.textContent = "Erreur";
    }
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

  if (elDepot)
    elDepot.addEventListener("change", () => {
      cart = [];
      renderCart();
      loadProducts();
    });
  if (elSearch) elSearch.addEventListener("input", applySearch);
  if (elCashAll) elCashAll.addEventListener("change", () => computeTotal());

  if (elSubmit) {
    elSubmit.addEventListener("click", async () => {
      const depotId = parseInt(elDepot.value, 10) || 0;
      if (!depotId) {
        window.showToast && window.showToast("error", "Choisir un dépôt");
        return;
      }
      if (!cart.length) {
        window.showToast && window.showToast("error", "Panier vide");
        return;
      }
      const total = computeTotal();
      const paid = parseInt(elPaid.value, 10) || 0;
      const clientId = parseInt(elClient.value, 10) || 0;
      const body = {
        depot_id: depotId,
        client_id: clientId,
        items: cart.map((it) => ({
          product_id: it.product_id,
          quantity: it.quantity,
          unit_price: it.unit_price,
        })),
        payment_amount: paid,
      };
      try {
        const r = await fetch(BASE + "/api/v1/sales", {
          method: "POST",
          headers: { "Content-Type": "application/json", ...authHeaders() },
          body: JSON.stringify(body),
        });
        if (!r.ok) {
          const errText = await r.text();
          window.showToast &&
            window.showToast("error", "Vente échouée: " + errText);
          return;
        }
        const j = await r.json();
        window.showToast &&
          window.showToast("success", "Vente créée #" + (j.sale?.id || ""));
        // Reset
        cart = [];
        renderCart();
        elPaid.value = "";
        applySearch();
      } catch (e) {
        window.showToast && window.showToast("error", "Erreur réseau");
      }
    });
  }

  // Init
  loadDepots();
  renderCart();
})();
