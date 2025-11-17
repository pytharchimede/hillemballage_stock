"use strict";
(function () {
  const routeBase = window.ROUTE_BASE || "";
  const tblBody = document.querySelector("#stocks-table tbody");
  const selProduct = document.getElementById("stock-product");
  const btnRefresh = document.getElementById("refresh-stock");
  let products = [];
  let depots = [];
  let currentProductId = 0;

  function readCookieToken() {
    try {
      const name = "api_token=";
      return (
        document.cookie
          .split(";")
          .map((c) => c.trim())
          .find((c) => c.indexOf(name) === 0)
          ?.substring(name.length) || ""
      );
    } catch (e) {
      return "";
    }
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
    } catch (e) {}
    return null;
  }
  function authHeaders(token) {
    return token ? { Authorization: "Bearer " + token } : {};
  }
  function withToken(url, token) {
    return (
      url +
      (url.indexOf("?") === -1 ? "?" : "&") +
      "api_token=" +
      encodeURIComponent(token || "")
    );
  }

  async function fetchProducts() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/products";
    if (token) url = withToken(url, token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/products";
      if (token) url = withToken(url, token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return [];
    return await r.json();
  }
  async function fetchDepots() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/depots";
    if (token) url = withToken(url, token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/depots";
      if (token) url = withToken(url, token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return [];
    return await r.json();
  }
  async function fetchStocks(productId) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url =
      routeBase + "/api/v1/stocks?product_id=" + encodeURIComponent(productId);
    if (token) url = withToken(url, token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/stocks?product_id=" +
        encodeURIComponent(productId);
      if (token) url = withToken(url, token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return [];
    return await r.json();
  }

  function renderProducts() {
    selProduct.innerHTML = products
      .map((p) => `<option value="${p.id}">${p.name} (${p.sku})</option>`)
      .join("");
    if (products.length) {
      currentProductId = products[0].id;
      selProduct.value = String(currentProductId);
      loadStocks();
    }
  }

  function renderStocks(rows) {
    const map = new Map(rows.map((r) => [r.depot_id, r]));
    const all = depots.map((d) => ({
      depot_id: d.id,
      depot_name: d.name,
      depot_code: d.code,
      quantity: map.get(d.id)?.quantity || 0,
    }));
    tblBody.innerHTML = all
      .map(
        (r) => `
      <tr>
        <td style="text-align:left">${r.depot_name}</td>
        <td style="text-align:left">${r.depot_code || ""}</td>
        <td>${r.quantity}</td>
        <td style="text-align:left">
          <button class="btn-ghost btn-in" data-depot="${
            r.depot_id
          }"><i class="fa fa-arrow-down"></i> Entrée</button>
          <button class="btn-ghost btn-tf" data-depot="${
            r.depot_id
          }"><i class="fa fa-right-left"></i> Transférer</button>
        </td>
      </tr>
    `
      )
      .join("");
  }

  async function loadStocks() {
    if (!currentProductId) return;
    const rows = await fetchStocks(currentProductId);
    renderStocks(rows);
  }

  document.addEventListener("change", function (ev) {
    if (ev.target === selProduct) {
      currentProductId = parseInt(selProduct.value, 10) || 0;
      loadStocks();
    }
  });
  btnRefresh && btnRefresh.addEventListener("click", loadStocks);

  document.addEventListener("click", async function (ev) {
    const inBtn = ev.target.closest(".btn-in");
    const tfBtn = ev.target.closest(".btn-tf");
    if (!inBtn && !tfBtn) return;
    const rowDepotId =
      parseInt((inBtn || tfBtn).getAttribute("data-depot"), 10) || 0;
    if (!rowDepotId) return;

    if (inBtn) {
      const qtyStr = prompt("Quantité à entrer:");
      const qty = parseInt(qtyStr, 10) || 0;
      if (qty <= 0) return;
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = routeBase + "/api/v1/stock/movement";
      if (token) url = withToken(url, token);
      let r = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify({
          depot_id: rowDepotId,
          product_id: currentProductId,
          type: "in",
          quantity: qty,
        }),
      });
      if (r.status === 401) {
        token = (await refreshSessionToken()) || token;
        url = routeBase + "/api/v1/stock/movement";
        if (token) url = withToken(url, token);
        r = await fetch(url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            ...authHeaders(token),
          },
          body: JSON.stringify({
            depot_id: rowDepotId,
            product_id: currentProductId,
            type: "in",
            quantity: qty,
          }),
        });
      }
      if (!r.ok) {
        window.showToast && window.showToast("error", "Entrée échouée");
        return;
      }
      window.showToast && window.showToast("success", "Entrée enregistrée");
      loadStocks();
    }

    if (tfBtn) {
      const toDepotStr = prompt("ID du dépôt de destination:");
      const toDepot = parseInt(toDepotStr, 10) || 0;
      if (!toDepot || toDepot === rowDepotId) {
        window.showToast &&
          window.showToast("error", "Dépôt destination invalide");
        return;
      }
      const qtyStr = prompt("Quantité à transférer:");
      const qty = parseInt(qtyStr, 10) || 0;
      if (qty <= 0) return;
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = routeBase + "/api/v1/stock/transfer";
      if (token) url = withToken(url, token);
      let r = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify({
          from_depot_id: rowDepotId,
          to_depot_id: toDepot,
          product_id: currentProductId,
          quantity: qty,
        }),
      });
      if (r.status === 401) {
        token = (await refreshSessionToken()) || token;
        url = routeBase + "/api/v1/stock/transfer";
        if (token) url = withToken(url, token);
        r = await fetch(url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            ...authHeaders(token),
          },
          body: JSON.stringify({
            from_depot_id: rowDepotId,
            to_depot_id: toDepot,
            product_id: currentProductId,
            quantity: qty,
          }),
        });
      }
      if (!r.ok) {
        const j = await r.json().catch(() => ({}));
        window.showToast &&
          window.showToast("error", j.error || "Transfert échoué");
        return;
      }
      window.showToast && window.showToast("success", "Transfert effectué");
      loadStocks();
    }
  });

  async function init() {
    products = await fetchProducts();
    depots = await fetchDepots();
    renderProducts();
  }
  init();
})();
