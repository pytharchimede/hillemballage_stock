(function () {
  const routeBase = window.ROUTE_BASE || "";
  const API = routeBase + "/api/v1";

  const grid = document.getElementById("suppliers-grid");
  const empty = document.getElementById("suppliers-empty");
  const form = document.getElementById("suppliers-search-form");
  const inputQ = document.getElementById("suppliers-search-q");
  const btnCsv = document.getElementById("suppliers-export-csv");
  const btnPdf = document.getElementById("suppliers-export-pdf");

  const latInput = document.getElementById("supplier-lat");
  const lonInput = document.getElementById("supplier-lon");
  const btnSaveGeo = document.getElementById("supplier-save-geo");

  let selectedSupplier = null;

  function fetchSuppliers() {
    const q = (inputQ.value || "").trim();
    const url = API + "/suppliers" + (q ? "?q=" + encodeURIComponent(q) : "");
    fetch(url)
      .then((r) => r.json())
      .then((d) => {
        const rows = d.suppliers || [];
        renderGrid(rows);
      })
      .catch((err) => {
        console.error("suppliers load error", err);
      });
  }

  function renderGrid(rows) {
    grid.innerHTML = "";
    if (!rows.length) {
      empty.style.display = "block";
      return;
    }
    empty.style.display = "none";
    rows.forEach((r) => {
      const card = document.createElement("div");
      card.className = "card-item";
      card.innerHTML = `
        <div class="card-title">${escapeHtml(r.name || "")}</div>
        <div class="muted">${escapeHtml(r.email || "")} · ${escapeHtml(
        r.phone || ""
      )}</div>
        <div>${escapeHtml(r.city || "")} ${
        r.country ? "(" + escapeHtml(r.country) + ")" : ""
      }</div>
        <div class="muted">${r.active ? "Actif" : "Inactif"}</div>
        <div style="margin-top:.4rem;display:flex;gap:.4rem">
          <button class="btn secondary" data-action="locate">Localiser</button>
        </div>
      `;
      card
        .querySelector('[data-action="locate"]')
        .addEventListener("click", () => {
          selectedSupplier = r;
          centerMap(r);
        });
      grid.appendChild(card);
    });
  }

  function escapeHtml(s) {
    return String(s).replace(
      /[&<>"]/g,
      (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
    );
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    fetchSuppliers();
  });
  btnCsv.addEventListener("click", (e) => {
    e.preventDefault();
    const q = (inputQ.value || "").trim();
    const url =
      API +
      "/suppliers/export?format=csv" +
      (q ? "&q=" + encodeURIComponent(q) : "");
    window.open(url, "_blank");
  });
  btnPdf.addEventListener("click", (e) => {
    e.preventDefault();
    const q = (inputQ.value || "").trim();
    const url =
      API +
      "/suppliers/export?format=pdf" +
      (q ? "&q=" + encodeURIComponent(q) : "");
    window.open(url, "_blank");
  });

  // Leaflet map like depots
  const map = L.map("supplier-map").setView([5.345, -4.027], 11);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 18,
    attribution: "&copy; OpenStreetMap",
  }).addTo(map);
  const marker = L.marker([5.345, -4.027], { draggable: true }).addTo(map);
  // Expose for autocomplete module
  window.__supplier_map = map;
  window.__supplier_marker = marker;

  marker.on("dragend", () => {
    const m = marker.getLatLng();
    latInput.value = m.lat.toFixed(6);
    lonInput.value = m.lng.toFixed(6);
  });

  function centerMap(r) {
    const lat = parseFloat(r.latitude || "");
    const lon = parseFloat(r.longitude || "");
    if (!isNaN(lat) && !isNaN(lon)) {
      map.setView([lat, lon], 13);
      marker.setLatLng([lat, lon]);
      latInput.value = lat.toFixed(6);
      lonInput.value = lon.toFixed(6);
    } else {
      // fallback: try city geocode later or keep default
      latInput.value = "";
      lonInput.value = "";
    }
  }

  btnSaveGeo.addEventListener("click", (e) => {
    e.preventDefault();
    if (!selectedSupplier) {
      alert("Sélectionnez un fournisseur dans la liste.");
      return;
    }
    const lat = parseFloat(latInput.value || "");
    const lon = parseFloat(lonInput.value || "");
    if (isNaN(lat) || isNaN(lon)) {
      alert("Latitude/Longitude invalides");
      return;
    }
    fetch(API + "/suppliers/" + selectedSupplier.id, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ latitude: lat, longitude: lon }),
    })
      .then((r) => r.json())
      .then((d) => {
        alert("Localisation mise à jour");
        selectedSupplier.latitude = lat;
        selectedSupplier.longitude = lon;
      })
      .catch((err) => {
        console.error("save geo error", err);
        alert("Erreur");
      });
  });

  fetchSuppliers();
})();

// --- Autocomplétion adresse (Nominatim) ---
(function () {
  const geoInput = document.getElementById("supplier-geo-search");
  const geoSug = document.getElementById("supplier-geo-suggestions");
  const latInput = document.getElementById("supplier-lat");
  const lonInput = document.getElementById("supplier-lon");
  if (!geoInput || !geoSug) return;
  let timer = null;
  geoInput.addEventListener("input", () => {
    const q = (geoInput.value || "").trim();
    if (timer) clearTimeout(timer);
    if (!q) {
      geoSug.style.display = "none";
      geoSug.innerHTML = "";
      return;
    }
    timer = setTimeout(() => {
      const url =
        "https://nominatim.openstreetmap.org/search?format=json&q=" +
        encodeURIComponent(q);
      fetch(url)
        .then((r) => r.json())
        .then((list) => {
          geoSug.innerHTML = "";
          (list || []).slice(0, 8).forEach((item) => {
            const div = document.createElement("div");
            div.style.padding = ".3rem .4rem";
            div.style.cursor = "pointer";
            div.textContent = item.display_name;
            div.addEventListener("click", () => {
              const lat = parseFloat(item.lat),
                lon = parseFloat(item.lon);
              try {
                window.__supplier_map.setView([lat, lon], 14);
              } catch (e) {}
              try {
                window.__supplier_marker.setLatLng([lat, lon]);
              } catch (e) {}
              if (latInput) latInput.value = lat.toFixed(6);
              if (lonInput) lonInput.value = lon.toFixed(6);
              geoSug.style.display = "none";
              geoSug.innerHTML = "";
            });
            geoSug.appendChild(div);
          });
          geoSug.style.display = geoSug.childElementCount ? "block" : "none";
        })
        .catch(() => {
          geoSug.style.display = "none";
        });
    }, 300);
  });
  document.addEventListener("click", (e) => {
    if (e.target !== geoInput) {
      geoSug.style.display = "none";
    }
  });
})();
