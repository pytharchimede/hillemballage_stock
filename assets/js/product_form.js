"use strict";
(function () {
  const form = document.getElementById("product-form");
  if (!form) return;
  const mode = form.dataset.mode || "create";
  const routeBase = window.ROUTE_BASE || "";
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

  // --- Searchable depot select ---
  const depotSelect = document.getElementById("depot-select");
  const depotSearch = document.getElementById("depot-search");
  const depotLoading = document.getElementById("depot-loading");
  const depotEmptyAlert = document.getElementById("depot-empty-alert");
  const qtyInitInput = document.querySelector('[name="initial_quantity"]');
  const submitBtn = form.querySelector('button[type="submit"]');
  let allDepots = [];

  function renderDepots(filter) {
    if (!depotSelect) return;
    const q = (filter || "").toLowerCase();
    const items = allDepots.filter(function (d) {
      const txt = (d.name + " " + (d.code || "")).toLowerCase();
      return txt.indexOf(q) !== -1;
    });
    depotSelect.innerHTML = items
      .map(function (d) {
        return (
          '<option value="' +
          d.id +
          '">' +
          d.name +
          (d.code ? " (" + d.code + ")" : "") +
          "</option>"
        );
      })
      .join("");
  }

  function updateEmptyState() {
    const empty = allDepots.length === 0;
    if (depotEmptyAlert)
      depotEmptyAlert.style.display = empty ? "block" : "none";
    if (depotSelect) depotSelect.disabled = empty;
    if (depotSearch) depotSearch.disabled = empty;
    if (submitBtn && qtyInitInput) {
      const needDepot = (parseInt(qtyInitInput.value || "0", 10) || 0) > 0;
      submitBtn.disabled = empty && needDepot; // Empêche transfert initial sans dépôt
    }
  }

  function toggleDepotRequirement() {
    if (!depotSelect || !qtyInitInput) return;
    const needDepot = (parseInt(qtyInitInput.value || "0", 10) || 0) > 0;
    depotSelect.required = needDepot;
    updateEmptyState();
  }
  async function loadDepots() {
    if (!depotSelect) return;
    if (depotLoading) depotLoading.style.display = "block";
    // show temporary option
    depotSelect.innerHTML = "<option disabled>Chargement…</option>";
    depotSelect.disabled = true;
    const depotsUrl =
      "/api/v1/depots" +
      (token ? "?api_token=" + encodeURIComponent(token) : "");
    const r = await fetch(depotsUrl, {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (r.status === 401) {
      console.warn("Non authentifié pour /api/v1/depots");
      if (depotEmptyAlert) {
        depotEmptyAlert.style.display = "block";
        depotEmptyAlert.innerHTML =
          'Session expirée. Veuillez <a href="' +
          routeBase +
          '/login">vous reconnecter</a>.';
      }
      if (depotLoading) depotLoading.style.display = "none";
      return;
    }
    if (!r.ok) {
      console.warn("Echec chargement dépôts", r.status);
      if (depotLoading) depotLoading.style.display = "none";
      return;
    }
    allDepots = await r.json();
    try {
      console.debug(
        "Depots loaded:",
        Array.isArray(allDepots) ? allDepots.length : "n/a"
      );
    } catch (_) {}
    if (!Array.isArray(allDepots)) allDepots = [];
    renderDepots("");
    updateEmptyState();
    // Préselectionner le premier dépôt s'il existe
    if (allDepots.length > 0 && depotSelect && !depotSelect.value) {
      depotSelect.value = String(allDepots[0].id);
    }
    if (depotLoading) depotLoading.style.display = "none";
    depotSelect.disabled = allDepots.length === 0;
  }
  if (depotSearch && depotSelect) {
    depotSearch.addEventListener("input", function () {
      renderDepots(depotSearch.value);
    });
  }

  async function preload() {
    if (mode === "edit") {
      const id = parseInt(form.dataset.productId || "0", 10);
      if (id) {
        const r = await fetch("/api/v1/products/" + id, {
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
        }
      }
    }
    await loadDepots();
    toggleDepotRequirement();
    // Si aucun dépôt chargé et token depuis cookie seulement, retenter après un court délai
    if (allDepots.length === 0) {
      setTimeout(loadDepots, 500);
    }
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    const id = parseInt(form.dataset.productId || "0", 10);
    const fd = new FormData(form);
    const opts = {
      method: mode === "edit" ? "PATCH" : "POST",
      headers: token ? { Authorization: "Bearer " + token } : {},
      body: fd,
    };
    const url = mode === "edit" ? "/api/v1/products/" + id : "/api/v1/products";
    const r = await fetch(url, opts);
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
