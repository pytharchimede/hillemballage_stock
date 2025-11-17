"use strict";
(function () {
  const form = document.getElementById("product-form");
  if (!form) return;
  const mode = form.dataset.mode || "create";
  const routeBase = window.ROUTE_BASE || "";
  function apiUrl(path) {
    return (routeBase || "") + path;
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
  let token = localStorage.getItem("api_token") || readCookieToken() || "";
  const skuInput = document.getElementById("prod-sku");
  const nameInput = document.getElementById("prod-name");

  // --- Auto SKU generation (create mode) ---
  function genSku(name) {
    const clean = (name || "Produit")
      .replace(/[^A-Za-z0-9]/g, "")
      .toUpperCase();
    const prefix = clean.slice(0, 3) || "PRD";
    const timePart = Date.now().toString(36).toUpperCase();
    return prefix + "-" + timePart.slice(-6);
  }
  function refreshSku() {
    if (mode !== "create" || !skuInput) return;
    skuInput.value = genSku(((nameInput && nameInput.value) || "").trim());
  }
  if (mode === "create" && skuInput && nameInput) {
    refreshSku();
    nameInput.addEventListener("input", refreshSku);
  }

  // --- Image drag & drop preview ---
  const drop = document.getElementById("image-drop");
  const fileInput = document.getElementById("image-input");
  const preview = document.getElementById("image-preview");
  function renderPreview() {
    if (!fileInput || !preview || !drop) return;
    const f = fileInput.files && fileInput.files[0];
    if (f) {
      const url = URL.createObjectURL(f);
      preview.src = url;
      preview.style.display = "block";
      drop.classList.add("has-image");
    } else {
      preview.src = "";
      preview.style.display = "none";
      drop.classList.remove("has-image");
    }
  }
  if (drop && fileInput) {
    drop.addEventListener("click", function () {
      fileInput.click();
    });
    drop.addEventListener("dragover", function (e) {
      e.preventDefault();
      drop.classList.add("drag");
    });
    drop.addEventListener("dragleave", function () {
      drop.classList.remove("drag");
    });
    drop.addEventListener("drop", function (e) {
      e.preventDefault();
      drop.classList.remove("drag");
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
        fileInput.files = e.dataTransfer.files;
        renderPreview();
      }
    });
    fileInput.addEventListener("change", renderPreview);
  }

  // --- Dépôts: champ combo (input + menu + hidden) ---
  const comboInput = document.getElementById("depot-combo");
  const hiddenDepotId = document.getElementById("depot-id");
  const depotMenu = document.getElementById("depot-menu");
  const depotLoading = document.getElementById("depot-loading");
  const depotEmptyAlert = document.getElementById("depot-empty-alert");
  const qtyInitInput = document.querySelector('[name="initial_quantity"]');
  const submitBtn = form.querySelector('button[type="submit"]');
  let allDepots = [];
  let filteredDepots = [];
  let highlightedIndex = -1;
  let depotLoadAttempts = 0;

  function labelForDepot(d) {
    return d.name + (d.code ? " (" + d.code + ")" : "");
  }

  function renderMenu(items) {
    if (!depotMenu) return;
    if (!items || items.length === 0) {
      depotMenu.innerHTML = '<div class="combo-empty">Aucun résultat</div>';
      return;
    }
    depotMenu.innerHTML = items
      .map(function (d, i) {
        const active = i === highlightedIndex ? " active" : "";
        return (
          '<div class="combo-item' +
          active +
          '" data-index="' +
          i +
          '">' +
          labelForDepot(d) +
          "</div>"
        );
      })
      .join("");
  }

  function openMenu() {
    if (!depotMenu || !comboInput) return;
    depotMenu.style.display = "block";
  }
  function closeMenu() {
    if (!depotMenu) return;
    depotMenu.style.display = "none";
    highlightedIndex = -1;
  }

  function filterDepots(q) {
    const query = (q || "").trim().toLowerCase();
    if (!query) return allDepots.slice(0);
    return allDepots.filter(function (d) {
      const text = (d.name + " " + (d.code || "")).toLowerCase();
      return text.indexOf(query) !== -1;
    });
  }

  function selectDepotByIndex(idx) {
    if (!filteredDepots || idx < 0 || idx >= filteredDepots.length) return;
    const d = filteredDepots[idx];
    if (hiddenDepotId) hiddenDepotId.value = String(d.id);
    if (comboInput) comboInput.value = labelForDepot(d);
    closeMenu();
  }

  function updateEmptyState() {
    const empty = allDepots.length === 0;
    if (depotEmptyAlert)
      depotEmptyAlert.style.display = empty ? "block" : "none";
    if (comboInput) comboInput.disabled = empty;
    if (submitBtn && qtyInitInput) {
      const needDepot = (parseInt(qtyInitInput.value || "0", 10) || 0) > 0;
      submitBtn.disabled = empty && needDepot;
    }
  }

  function toggleDepotRequirement() {
    if (!qtyInitInput) return;
    const needDepot = (parseInt(qtyInitInput.value || "0", 10) || 0) > 0;
    // Les champs hidden ignorent required: on valide dans onSubmit.
    updateEmptyState();
  }

  async function loadDepots() {
    // Ne charge que si le combo existe sur la page (création)
    if (!comboInput) return;
    if (depotLoading) depotLoading.style.display = "block";
    if (comboInput) comboInput.disabled = true;
    // Rafraîchir le token à chaque tentative (cas post-login immédiat)
    token = localStorage.getItem("api_token") || readCookieToken() || "";
    const depotsUrl =
      apiUrl("/api/v1/depots") +
      (token ? "?api_token=" + encodeURIComponent(token) : "");
    const r = await fetch(depotsUrl, {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (r.status === 401) {
      console.warn("Non authentifié pour /api/v1/depots");
      // Essayer d'obtenir un token depuis la session web
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            localStorage.setItem("api_token", tj.token);
            document.cookie = "api_token=" + tj.token + "; path=/";
            token = tj.token;
            // Retenter immédiatement le chargement
            if (depotLoading) depotLoading.style.display = "none";
            return await loadDepots();
          }
        }
      } catch (_) {}
      if (depotEmptyAlert) {
        depotEmptyAlert.style.display = "block";
        depotEmptyAlert.innerHTML =
          'Session expirée. Veuillez <a href="' +
          routeBase +
          '/login">vous reconnecter</a>.';
      }
      if (depotLoading) depotLoading.style.display = "none";
      if (comboInput) comboInput.disabled = false;
      // Retenter rapidement (max 3) pour laisser le temps au token d'être synchronisé
      depotLoadAttempts++;
      if (depotLoadAttempts < 3) {
        setTimeout(loadDepots, 600);
      }
      return;
    }
    if (!r.ok) {
      console.warn("Echec chargement dépôts", r.status);
      if (depotLoading) depotLoading.style.display = "none";
      if (comboInput) comboInput.disabled = false;
      return;
    }
    allDepots = await r.json();
    if (depotEmptyAlert) depotEmptyAlert.style.display = "none";
    if (!Array.isArray(allDepots)) allDepots = [];
    try {
      console.debug(
        "Depots loaded:",
        Array.isArray(allDepots) ? allDepots.length : "n/a"
      );
    } catch (_) {}
    filteredDepots = allDepots.slice(0);
    renderMenu(filteredDepots);
    updateEmptyState();
    // Option: pré-sélectionner le premier dépôt s'il existe
    if (
      allDepots.length > 0 &&
      comboInput &&
      hiddenDepotId &&
      !hiddenDepotId.value
    ) {
      hiddenDepotId.value = String(allDepots[0].id);
      comboInput.value = labelForDepot(allDepots[0]);
    }
    if (depotLoading) depotLoading.style.display = "none";
    if (comboInput) comboInput.disabled = allDepots.length === 0;
  }

  // Interactions combo
  if (comboInput && depotMenu) {
    comboInput.addEventListener("focus", function () {
      filteredDepots = filterDepots(comboInput.value);
      highlightedIndex = -1;
      renderMenu(filteredDepots);
      openMenu();
    });
    comboInput.addEventListener("input", function () {
      filteredDepots = filterDepots(comboInput.value);
      highlightedIndex = -1;
      renderMenu(filteredDepots);
      openMenu();
    });
    comboInput.addEventListener("keydown", function (e) {
      if (depotMenu.style.display !== "block") return;
      if (e.key === "ArrowDown") {
        e.preventDefault();
        highlightedIndex = Math.min(
          (highlightedIndex < 0 ? -1 : highlightedIndex) + 1,
          filteredDepots.length - 1
        );
        renderMenu(filteredDepots);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        highlightedIndex = Math.max(highlightedIndex - 1, 0);
        renderMenu(filteredDepots);
      } else if (e.key === "Enter") {
        if (highlightedIndex >= 0) {
          e.preventDefault();
          selectDepotByIndex(highlightedIndex);
        }
      } else if (e.key === "Escape") {
        closeMenu();
      }
    });
    depotMenu.addEventListener("click", function (e) {
      const el = e.target.closest(".combo-item");
      if (!el) return;
      const idx = parseInt(el.getAttribute("data-index") || "-1", 10);
      if (idx >= 0) selectDepotByIndex(idx);
    });
    document.addEventListener("click", function (e) {
      if (!depotMenu) return;
      const within =
        e.target === comboInput ||
        e.target === depotMenu ||
        (depotMenu.contains && depotMenu.contains(e.target));
      if (!within) closeMenu();
    });
  }

  async function preload() {
    if (mode === "edit") {
      const id = parseInt(form.dataset.productId || "0", 10);
      if (id) {
        token = localStorage.getItem("api_token") || readCookieToken() || token;
        let pUrl = apiUrl("/api/v1/products/" + id);
        if (token)
          pUrl +=
            (pUrl.indexOf("?") === -1 ? "?" : "&") +
            "api_token=" +
            encodeURIComponent(token);
        const r = await fetch(pUrl, {
          headers: token ? { Authorization: "Bearer " + token } : {},
        });
        if (r.ok) {
          const p = await r.json();
          var n = form.querySelector('[name="name"]');
          var s = form.querySelector('[name="sku"]');
          var u = form.querySelector('[name="unit_price"]');
          var d = form.querySelector('[name="description"]');
          if (n) n.value = p.name || "";
          if (s) s.value = p.sku || "";
          if (u) u.value = p.unit_price || 0;
          if (d) d.value = p.description || "";
          // Afficher l'image existante si présente
          if (p.image_path && preview && drop) {
            try {
              const src = resolveImg(p.image_path);
              preview.src = src;
              preview.style.display = "block";
              drop.classList.add("has-image");
            } catch (_) {}
          }
        }
      }
    }
    await loadDepots();
    toggleDepotRequirement();
    // Si aucun dépôt chargé et token depuis cookie seulement, retenter après un court délai
    if (comboInput && allDepots.length === 0) {
      setTimeout(loadDepots, 500);
    }
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const id = parseInt(form.dataset.productId || "0", 10);
    // Validation combo dépôt si quantité initiale > 0
    if (qtyInitInput && comboInput) {
      const needDepot = (parseInt(qtyInitInput.value || "0", 10) || 0) > 0;
      if (needDepot && (!hiddenDepotId || !hiddenDepotId.value)) {
        alert("Veuillez choisir un dépôt pour le stock initial.");
        if (comboInput) comboInput.focus();
        return;
      }
    }
    // Rafraîchir le token juste avant submit
    token = localStorage.getItem("api_token") || readCookieToken() || "";
    const fd = new FormData(form);
    const isEdit = mode === "edit";
    if (isEdit) fd.append("_method", "PATCH");
    const opts = {
      method: "POST",
      headers: token ? { Authorization: "Bearer " + token } : {},
      body: fd,
    };
    let url = isEdit
      ? apiUrl("/api/v1/products/" + id)
      : apiUrl("/api/v1/products");
    if (token) {
      url +=
        (url.indexOf("?") === -1 ? "?" : "&") +
        "api_token=" +
        encodeURIComponent(token);
    }
    let r = await fetch(url, opts);
    if (r.status === 401) {
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            localStorage.setItem("api_token", tj.token);
            document.cookie = "api_token=" + tj.token + "; path=/";
            token = tj.token;
            // Rebuild URL with new token
            url = isEdit
              ? apiUrl("/api/v1/products/" + id)
              : apiUrl("/api/v1/products");
            url +=
              (url.indexOf("?") === -1 ? "?" : "&") +
              "api_token=" +
              encodeURIComponent(token);
            opts.headers = { Authorization: "Bearer " + token };
            r = await fetch(url, opts);
          }
        }
      } catch (_) {}
    }
    if (r.ok) {
      window.location.href = routeBase + "/products";
    } else {
      try {
        const err = await r.json();
        alert("Erreur: " + (err.error || r.status));
      } catch (_) {
        alert("Erreur lors de la sauvegarde.");
      }
    }
  });

  preload();
  if (qtyInitInput)
    qtyInitInput.addEventListener("input", toggleDepotRequirement);
})();
