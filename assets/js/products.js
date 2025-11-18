(function () {
  const routeBase = window.ROUTE_BASE || "";
  let _me = null;

  // Fonction API globale corrigée
  function apiUrl(path) {
    return routeBase + path;
  }
  function resolveImg(path) {
    if (!path) return "";
    if (/^https?:/i.test(path)) return path;
    if (path.startsWith(routeBase + "/")) return path;
    if (path.startsWith("/")) return routeBase + path;
    return routeBase + "/" + path;
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

  async function load() {
    if (!_me) {
      try {
        const rme = await fetch(apiUrl("/api/v1/auth/me"));
        if (rme.ok) _me = await rme.json();
      } catch (e) {}
    }
    const perms = (_me && _me.permissions) || {};
    const role = (_me && _me.role) || "";
    // Références filtres
    const elQ = document.getElementById("ps-q");
    const elDepotWrap = document.getElementById("ps-depot-wrap");
    const elDepot = document.getElementById("ps-depot");
    const elDepotFilter = document.getElementById("ps-depot-filter");
    const elOnly = document.getElementById("ps-only-in-stock");
    const elExportCsv = document.getElementById("ps-export-csv");
    const elExportPdf = document.getElementById("ps-export-pdf");
    const psHint = document.getElementById("ps-hint");
    // Masquer les actions top selon permissions/role
    try {
      const newBtn = document.getElementById("btn-new-product");
      if (newBtn) {
        const ok =
          (perms["*"] && perms["*"].edit) ||
          (perms.products && perms.products.edit);
        newBtn.style.display = ok ? "inline-block" : "none";
      }
      const fixBtn = document.getElementById("fix-img-btn");
      if (fixBtn && fixBtn.getAttribute("data-role") === "admin-only") {
        fixBtn.style.display = role === "admin" ? "inline-block" : "none";
      }
    } catch (_) {}
    // Afficher/peupler dépôts pour admin/gérant
    if (elDepotWrap) {
      elDepotWrap.style.display =
        role === "admin" || role === "gerant" ? "flex" : "none";
    }
    if (elDepot && (role === "admin" || role === "gerant")) {
      const depots = await fetchDepots();
      elDepot.innerHTML = "";
      const optAll = document.createElement("option");
      optAll.value = "";
      optAll.textContent = "Tous les dépôts";
      elDepot.appendChild(optAll);
      (depots || []).forEach((d) => {
        const o = document.createElement("option");
        o.value = d.id;
        o.textContent = d.name + (d.code ? " (" + d.code + ")" : "");
        elDepot.appendChild(o);
      });
      if (elDepotFilter && !elDepotFilter._bind) {
        elDepotFilter._bind = true;
        elDepotFilter.addEventListener("input", function () {
          const qf = (elDepotFilter.value || "").toLowerCase().trim();
          for (let i = 0; i < elDepot.options.length; i++) {
            const t = (elDepot.options[i].text || "").toLowerCase();
            elDepot.options[i].style.display =
              qf && !t.includes(qf) ? "none" : "";
          }
        });
      }
    }

    function currentFilters() {
      const q = elQ && elQ.value ? elQ.value.trim() : "";
      const only = !!(elOnly && elOnly.checked);
      let depotId = "";
      if (elDepot && (role === "admin" || role === "gerant"))
        depotId = elDepot.value || "";
      return { q, only_in_stock: only, depot_id: depotId };
    }

    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl("/api/v1/products");
    const f = currentFilters();
    const qp = [];
    if (f.q) qp.push("q=" + encodeURIComponent(f.q));
    if (f.depot_id !== "")
      qp.push("depot_id=" + encodeURIComponent(f.depot_id));
    if (f.only_in_stock) qp.push("only_in_stock=1");
    if (qp.length) url += "?" + qp.join("&");
    if (token)
      url +=
        (url.indexOf("?") === -1 ? "?" : "&") +
        "api_token=" +
        encodeURIComponent(token);
    let r = await fetch(url, {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (r.status === 401) {
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            localStorage.setItem("api_token", tj.token);
            document.cookie = "api_token=" + tj.token + "; path=/";
            token = tj.token;
            url =
              apiUrl("/api/v1/products") +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              headers: { Authorization: "Bearer " + token },
            });
          }
        }
      } catch (_) {}
    }
    if (!r.ok) return;
    const data = await r.json();
    if (psHint) {
      const parts = [];
      if (f.q) parts.push('Recherche: "' + f.q + '"');
      if (f.depot_id && elDepot)
        parts.push(
          "Dépôt: " + (elDepot.selectedOptions[0]?.text || f.depot_id)
        );
      if (f.only_in_stock) parts.push("Seulement en stock");
      psHint.textContent = parts.join(" • ");
    }
    const grid = document.getElementById("products-grid");
    const empty = document.getElementById("products-empty");
    if (!data || data.length === 0) {
      if (grid) grid.innerHTML = "";
      if (empty) empty.style.display = "block";
      return;
    }
    if (empty) empty.style.display = "none";
    const canEditProducts =
      (perms["*"] && perms["*"].edit) ||
      (perms.products && perms.products.edit);
    const canEditStocks =
      (perms["*"] && perms["*"].edit) || (perms.stocks && perms.stocks.edit);
    const canTransfer =
      (perms["*"] && perms["*"].edit) ||
      (perms.transfers && perms.transfers.edit);
    const cards = data
      .map(function (p) {
        const img = p.image_path
          ? `<img src="${resolveImg(p.image_path)}" alt="${p.name}">`
          : `<i class="fa fa-image" style="font-size:42px;color:#bbb"></i>`;
        const inactive =
          typeof p.active !== "undefined" && String(p.active) === "0";
        const badge = inactive
          ? `<span class="badge-inactive">Inactif</span>`
          : "";
        const toggleTitle = inactive ? "Activer" : "Désactiver";
        const toggleIcon = inactive ? "fa-toggle-on" : "fa-toggle-off";
        const stockVal =
          typeof p.stock_depot !== "undefined" && p.stock_depot !== null
            ? p.stock_depot
            : p.stock_total;
        const stock =
          typeof stockVal !== "undefined"
            ? `<div class="stock">Stock: <a href="#" class="stock-detail-link" data-pid="${p.id}"><strong>${stockVal}</strong></a></div>`
            : "";
        return `
        <div class="card-product" data-id="${
          p.id
        }" data-active="${inactive ? "0" : "1"}">
          <div class="thumb">${img}</div>
          <div class="body">
            <div class="title">${p.name} ${badge}</div>
            <div class="sku">SKU: ${p.sku}</div>
            <div class="price">${p.unit_price} FCFA</div>
            ${stock}
          </div>
          <div class="actions">
            <div class="left">
              <a class="btn secondary" title="Fiche" href="${apiUrl(
                "/products/view?id=" + p.id
              )}"><i class="fa fa-eye"></i></a>
            </div>
            <div class="right" style="display:flex;gap:.4rem">
              ${
                canEditProducts
                  ? `<button class="btn tertiary btn-toggle" title="${toggleTitle}"><i class="fa ${toggleIcon}"></i></button>`
                  : ""
              }
              ${
                canEditProducts
                  ? `<a class="btn" title="Modifier" href="${apiUrl(
                      "/products/edit?id=" + p.id
                    )}"><i class="fa fa-pencil"></i></a>`
                  : ""
              }
              ${
                canEditStocks
                  ? `<button class="btn secondary btn-stock-in" title="Entrée en stock"><i class="fa fa-arrow-down"></i></button>`
                  : ""
              }
              ${
                canTransfer
                  ? `<button class="btn secondary btn-stock-transfer" title="Transférer stock"><i class="fa fa-right-left"></i></button>`
                  : ""
              }
            </div>
          </div>
          <div class="inline-panel" style="display:none; padding:.5rem .75rem; border-top:1px dashed #eee"></div>
        </div>`;
      })
      .join("");
    if (grid) grid.innerHTML = cards;

    // Listeners filtres -> reload
    function scheduleReload() {
      load();
    }
    if (elQ && !elQ._bind) {
      elQ._bind = true;
      elQ.addEventListener("input", scheduleReload);
    }
    if (elDepot && !elDepot._bind) {
      elDepot._bind = true;
      elDepot.addEventListener("change", scheduleReload);
    }
    if (elOnly && !elOnly._bind) {
      elOnly._bind = true;
      elOnly.addEventListener("change", scheduleReload);
    }

    // Export buttons
    function openExport(fmt) {
      let base = apiUrl("/api/v1/products/export");
      const q = [];
      if (f.q) q.push("q=" + encodeURIComponent(f.q));
      if (f.depot_id !== "")
        q.push("depot_id=" + encodeURIComponent(f.depot_id));
      if (f.only_in_stock) q.push("only_in_stock=1");
      q.push("format=" + encodeURIComponent(fmt));
      const t = localStorage.getItem("api_token") || readCookieToken() || "";
      if (t) q.push("api_token=" + encodeURIComponent(t));
      const href = base + (q.length ? "?" + q.join("&") : "");
      window.open(href, "_blank");
    }
    if (elExportCsv && !elExportCsv._bind) {
      elExportCsv._bind = true;
      elExportCsv.addEventListener("click", function () {
        openExport("csv");
      });
    }
    if (elExportPdf && !elExportPdf._bind) {
      elExportPdf._bind = true;
      elExportPdf.addEventListener("click", function () {
        openExport("pdf");
      });
    }
  }

  // Support de l'ancien formulaire inline (optionnel)
  const form = document.getElementById("product-form");

  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const fd = new FormData(e.target);
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = apiUrl("/api/v1/products");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      let r = await fetch(url, {
        method: "POST",
        headers: token ? { Authorization: "Bearer " + token } : {},
        body: fd,
      });
      if (r.status === 401) {
        try {
          const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
          if (tr.ok) {
            const tj = await tr.json();
            if (tj && tj.token) {
              localStorage.setItem("api_token", tj.token);
              document.cookie = "api_token=" + tj.token + "; path=/";
              token = tj.token;
              url =
                apiUrl("/api/v1/products") +
                "?api_token=" +
                encodeURIComponent(token);
              r = await fetch(url, {
                method: "POST",
                headers: { Authorization: "Bearer " + token },
                body: fd,
              });
            }
          }
        } catch (_) {}
      }

      if (r.ok) {
        e.target.reset();
        load();
      }
    });
  }

  load();

  // Popover helpers
  let _currentPopover = null;
  function closePopover() {
    if (_currentPopover && _currentPopover.parentNode) {
      _currentPopover.parentNode.removeChild(_currentPopover);
    }
    _currentPopover = null;
    document.removeEventListener("click", outsideClickHandler, true);
    window.removeEventListener("scroll", closePopover, true);
    window.removeEventListener("resize", closePopover, true);
  }
  function outsideClickHandler(e) {
    if (!_currentPopover) return;
    if (!_currentPopover.contains(e.target)) {
      closePopover();
    }
  }
  function showPopover(anchorEl, innerHtml) {
    closePopover();
    const pop = document.createElement("div");
    pop.className = "popover";
    pop.innerHTML = innerHtml;
    pop.style.position = "absolute";
    pop.style.zIndex = 1000;
    document.body.appendChild(pop);
    const rect = anchorEl.getBoundingClientRect();
    const top = rect.bottom + window.scrollY + 6;
    const left = rect.left + window.scrollX;
    pop.style.top = top + "px";
    pop.style.left = left + "px";
    _currentPopover = pop;
    setTimeout(() => {
      document.addEventListener("click", outsideClickHandler, true);
      window.addEventListener("scroll", closePopover, true);
      window.addEventListener("resize", closePopover, true);
    }, 0);
  }

  async function fetchProductStocks(productId) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl(
      "/api/v1/stocks?product_id=" + encodeURIComponent(productId)
    );
    if (token) url += "&api_token=" + encodeURIComponent(token);
    let r = await fetch(url, {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (r.status === 401) {
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            localStorage.setItem("api_token", tj.token);
            document.cookie = "api_token=" + tj.token + "; path=/";
            token = tj.token;
            url =
              apiUrl(
                "/api/v1/stocks?product_id=" + encodeURIComponent(productId)
              ) +
              "&api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              headers: { Authorization: "Bearer " + token },
            });
          }
        }
      } catch (_) {}
    }
    if (!r.ok) return [];
    return await r.json();
  }

  // Toggle activation via event delegation
  document.addEventListener("click", async function (ev) {
    const stockLink = ev.target.closest(".stock-detail-link");
    if (stockLink) {
      ev.preventDefault();
      const pid = parseInt(stockLink.getAttribute("data-pid"), 10) || 0;
      if (!pid) return;
      const rows = await fetchProductStocks(pid);
      if (!rows || rows.length === 0) {
        showPopover(
          stockLink,
          '<div style="padding:.5rem .75rem;min-width:220px">Aucun stock par dépôt.</div>'
        );
        return;
      }
      const html =
        '<div style="padding:.5rem .75rem;min-width:260px">' +
        '<div style="font-weight:600;margin-bottom:.5rem">Stock par dépôt</div>' +
        '<div style="max-height:240px;overflow:auto">' +
        rows
          .map(function (r) {
            const name = (r.depot_name || "").replace(/</g, "&lt;");
            const code = (r.depot_code || "").replace(/</g, "&lt;");
            return (
              '<div style="display:flex;justify-content:space-between;gap:.75rem;padding:.25rem 0;border-bottom:1px dashed #eee">' +
              "<span>" +
              name +
              (code ? ' <span style="opacity:.6">(' + code + ")</span>" : "") +
              "</span>" +
              '<span style="font-weight:600">' +
              r.quantity +
              "</span>" +
              "</div>"
            );
          })
          .join("") +
        "</div>" +
        "</div>";
      showPopover(stockLink, html);
      return;
    }
    const btn = ev.target.closest(".btn-toggle");
    if (!btn) return;
    const card = btn.closest(".card-product");
    if (!card) return;
    ev.preventDefault();
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const id = card.getAttribute("data-id");
    const current = card.getAttribute("data-active") === "1";
    const next = current ? 0 : 1;
    let url = apiUrl("/api/v1/products/" + id);
    if (token)
      url +=
        (url.indexOf("?") === -1 ? "?" : "&") +
        "api_token=" +
        encodeURIComponent(token);
    let r = await fetch(url, {
      method: "PATCH",
      headers: Object.assign(
        { "Content-Type": "application/json" },
        token ? { Authorization: "Bearer " + token } : {}
      ),
      body: JSON.stringify({ active: next }),
    });
    if (r.status === 401) {
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            localStorage.setItem("api_token", tj.token);
            document.cookie = "api_token=" + tj.token + "; path=/";
            token = tj.token;
            url =
              apiUrl("/api/v1/products/" + id) +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              method: "PATCH",
              headers: {
                "Content-Type": "application/json",
                Authorization: "Bearer " + token,
              },
              body: JSON.stringify({ active: next }),
            });
          }
        }
      } catch (_) {}
    }
    if (r.ok) {
      load();
    }
  });

  // Cached depots
  let _depotsCache = null;
  async function fetchDepots() {
    if (_depotsCache) return _depotsCache;
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl("/api/v1/depots");
    if (token) url += "?api_token=" + encodeURIComponent(token);
    let r = await fetch(url, {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (r.status === 401) {
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            localStorage.setItem("api_token", tj.token);
            document.cookie = "api_token=" + tj.token + "; path=/";
            token = tj.token;
            url =
              apiUrl("/api/v1/depots") +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              headers: { Authorization: "Bearer " + token },
            });
          }
        }
      } catch (_) {}
    }
    if (!r.ok) return [];
    _depotsCache = await r.json();
    return _depotsCache;
  }

  function renderStockInPanel(card, productId) {
    const panel = card.querySelector(".inline-panel");
    if (!panel) return;
    panel.innerHTML =
      '<div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap">' +
      '<select class="dep-select" style="min-width:180px"></select>' +
      '<input type="number" class="qty-input" min="1" step="1" placeholder="Quantité" style="width:130px">' +
      '<button class="btn do-stock-in">Entrer</button>' +
      '<button class="btn ghost cancel-panel">Annuler</button>' +
      "</div>";
    panel.style.display = "block";
    fetchDepots().then((ds) => {
      const sel = panel.querySelector(".dep-select");
      sel.innerHTML = ds
        .map((d) => `<option value="${d.id}">${d.name} (${d.code})</option>`)
        .join("");
    });
    panel._mode = "in";
    panel._pid = productId;
  }

  function renderTransferPanel(card, productId) {
    const panel = card.querySelector(".inline-panel");
    if (!panel) return;
    panel.innerHTML =
      '<div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap">' +
      '<select class="dep-from" style="min-width:180px"></select>' +
      '<span style="opacity:.7">→</span>' +
      '<select class="dep-to" style="min-width:180px"></select>' +
      '<input type="number" class="qty-input" min="1" step="1" placeholder="Quantité" style="width:130px">' +
      '<button class="btn do-transfer">Transférer</button>' +
      '<button class="btn ghost cancel-panel">Annuler</button>' +
      "</div>";
    panel.style.display = "block";
    fetchDepots().then((ds) => {
      const from = panel.querySelector(".dep-from");
      const to = panel.querySelector(".dep-to");
      const opts = ds
        .map((d) => `<option value="${d.id}">${d.name} (${d.code})</option>`)
        .join("");
      from.innerHTML = opts;
      to.innerHTML = opts;
    });
    panel._mode = "transfer";
    panel._pid = productId;
  }

  document.addEventListener("click", async function (ev) {
    const inBtn = ev.target.closest(".btn-stock-in");
    const tfBtn = ev.target.closest(".btn-stock-transfer");
    const cancel = ev.target.closest(".cancel-panel");
    const doIn = ev.target.closest(".do-stock-in");
    const doTf = ev.target.closest(".do-transfer");
    const card = ev.target.closest(".card-product");
    if (!card) return;
    if (inBtn) {
      ev.preventDefault();
      renderStockInPanel(card, parseInt(card.getAttribute("data-id"), 10));
      return;
    }
    if (tfBtn) {
      ev.preventDefault();
      renderTransferPanel(card, parseInt(card.getAttribute("data-id"), 10));
      return;
    }
    if (cancel) {
      ev.preventDefault();
      const panel = card.querySelector(".inline-panel");
      if (panel) {
        panel.style.display = "none";
        panel.innerHTML = "";
      }
      return;
    }
    if (doIn) {
      ev.preventDefault();
      const panel = card.querySelector(".inline-panel");
      const sel = panel.querySelector(".dep-select");
      const qtyEl = panel.querySelector(".qty-input");
      const depotId = parseInt(sel.value, 10) || 0;
      const qty = parseInt(qtyEl.value, 10) || 0;
      const pid = panel._pid;
      if (!depotId || qty <= 0) {
        window.showToast &&
          window.showToast("error", "Choisissez un dépôt et une quantité");
        return;
      }
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = apiUrl("/api/v1/stock/movement");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      let r = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          ...(token ? { Authorization: "Bearer " + token } : {}),
        },
        body: JSON.stringify({
          depot_id: depotId,
          product_id: pid,
          type: "in",
          quantity: qty,
        }),
      });
      if (r.status === 401) {
        try {
          const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
          if (tr.ok) {
            const tj = await tr.json();
            if (tj && tj.token) {
              localStorage.setItem("api_token", tj.token);
              document.cookie = "api_token=" + tj.token + "; path=/";
              token = tj.token;
              url =
                apiUrl("/api/v1/stock/movement") +
                "?api_token=" +
                encodeURIComponent(token);
              r = await fetch(url, {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  Authorization: "Bearer " + token,
                },
                body: JSON.stringify({
                  depot_id: depotId,
                  product_id: pid,
                  type: "in",
                  quantity: qty,
                }),
              });
            }
          }
        } catch (_) {}
      }
      if (!r.ok) {
        window.showToast &&
          window.showToast("error", "Entrée en stock échouée");
        return;
      }
      window.showToast &&
        window.showToast("success", "Entrée en stock enregistrée");
      panel.style.display = "none";
      panel.innerHTML = "";
      load();
      return;
    }
    if (doTf) {
      ev.preventDefault();
      const panel = card.querySelector(".inline-panel");
      const from = parseInt(panel.querySelector(".dep-from").value, 10) || 0;
      const to = parseInt(panel.querySelector(".dep-to").value, 10) || 0;
      const qty = parseInt(panel.querySelector(".qty-input").value, 10) || 0;
      const pid = panel._pid;
      if (!from || !to || from === to || qty <= 0) {
        window.showToast &&
          window.showToast(
            "error",
            "Sélectionnez deux dépôts différents et une quantité"
          );
        return;
      }
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = apiUrl("/api/v1/stock/transfer");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      let r = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          ...(token ? { Authorization: "Bearer " + token } : {}),
        },
        body: JSON.stringify({
          from_depot_id: from,
          to_depot_id: to,
          product_id: pid,
          quantity: qty,
        }),
      });
      if (r.status === 401) {
        try {
          const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
          if (tr.ok) {
            const tj = await tr.json();
            if (tj && tj.token) {
              localStorage.setItem("api_token", tj.token);
              document.cookie = "api_token=" + tj.token + "; path=/";
              token = tj.token;
              url =
                apiUrl("/api/v1/stock/transfer") +
                "?api_token=" +
                encodeURIComponent(token);
              r = await fetch(url, {
                method: "POST",
                headers: {
                  "Content-Type": "application/json",
                  Authorization: "Bearer " + token,
                },
                body: JSON.stringify({
                  from_depot_id: from,
                  to_depot_id: to,
                  product_id: pid,
                  quantity: qty,
                }),
              });
            }
          }
        } catch (_) {}
      }
      if (!r.ok) {
        const j = await r.json().catch(() => ({}));
        const msg = j && j.error ? j.error : "Transfert échoué";
        window.showToast && window.showToast("error", msg);
        return;
      }
      window.showToast && window.showToast("success", "Transfert effectué");
      panel.style.display = "none";
      panel.innerHTML = "";
      load();
      return;
    }
  });

  // Admin: normalize image paths
  const fixBtn = document.getElementById("fix-img-btn");
  const fixStatus = document.getElementById("fix-img-status");
  if (fixBtn) {
    fixBtn.addEventListener("click", async function () {
      if (fixStatus) {
        fixStatus.style.display = "inline";
        fixStatus.textContent = "Traitement en cours…";
      }
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = apiUrl("/api/v1/admin/fix-product-images");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      let r = await fetch(url, {
        method: "POST",
        headers: token ? { Authorization: "Bearer " + token } : {},
      });
      if (r.status === 401) {
        try {
          const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
          if (tr.ok) {
            const tj = await tr.json();
            if (tj && tj.token) {
              localStorage.setItem("api_token", tj.token);
              document.cookie = "api_token=" + tj.token + "; path=/";
              token = tj.token;
              url =
                apiUrl("/api/v1/admin/fix-product-images") +
                "?api_token=" +
                encodeURIComponent(token);
              r = await fetch(url, {
                method: "POST",
                headers: { Authorization: "Bearer " + token },
              });
            }
          }
        } catch (_) {}
      }
      if (r.status === 403) {
        if (fixStatus)
          fixStatus.textContent = "Action non autorisée (admin requis).";
        return;
      }
      if (!r.ok) {
        if (fixStatus) fixStatus.textContent = "Echec de la normalisation.";
        return;
      }
      const out = await r.json();
      if (fixStatus)
        fixStatus.textContent = `Normalisés: ${out.normalized || 0}`;
      load();
    });
  }
})();
