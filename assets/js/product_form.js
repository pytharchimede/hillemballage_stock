"use strict";
(function () {
  const form = document.getElementById("product-form");
  if (!form) return;
  const mode = form.dataset.mode || "create";
  const routeBase = window.ROUTE_BASE || "";
  const token = localStorage.getItem("api_token") || "";
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
  async function loadDepots() {
    if (!depotSelect) return;
    const r = await fetch("/api/v1/depots", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (!r.ok) return;
    const data = await r.json();
    depotSelect.innerHTML = data
      .map(function (d) {
        return (
          '<option value="' +
          d.id +
          '">' +
          d.name +
          " (" +
          d.code +
          ")</option>"
        );
      })
      .join("");
  }
  if (depotSearch && depotSelect) {
    depotSearch.addEventListener("input", function () {
      const q = depotSearch.value.toLowerCase();
      for (var i = 0; i < depotSelect.options.length; i++) {
        var opt = depotSelect.options[i];
        var txt = (opt.text || "").toLowerCase();
        opt.style.display = txt.indexOf(q) !== -1 ? "" : "none";
      }
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
          if (n) n.value = p.name || "";
          if (s) s.value = p.sku || "";
          if (u) u.value = p.unit_price || 0;
        }
      }
    }
    await loadDepots();
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
})();
