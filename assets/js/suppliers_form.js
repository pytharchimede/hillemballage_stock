(function () {
  const form = document.getElementById("supplier-form");
  const routeBase = form?.getAttribute("data-route-base") || "";
  const catSelect = document.getElementById("category-select");
  const catSearch = document.getElementById("category-search");
  const catSuggest = document.getElementById("category-suggestions");
  const catHidden = document.getElementById("categories-json");
  const geoSearch = document.getElementById("geo-search");
  const geoSuggest = document.getElementById("geo-suggestions");
  const latInput = document.getElementById("lat");
  const lonInput = document.getElementById("lon");
  const dropzone = document.getElementById("doc-dropzone");
  const fileInput = document.getElementById("doc-input");
  const docList = document.getElementById("doc-list");
  const docsHidden = document.getElementById("documents-json");

  if (!form) return;

  let allCategories = [];
  let selectedCategories = [];
  let uploadedDocs = [];

  fetch(routeBase + "/api/v1/categories")
    .then((r) => (r.ok ? r.json() : []))
    .then((d) => {
      if (Array.isArray(d)) {
        allCategories = d.map((x) => ({
          id: x.id || x.slug || x.name,
          name: x.name || String(x),
        }));
      } else if (Array.isArray(d.items)) {
        allCategories = d.items.map((x) => ({
          id: x.id || x.slug || x.name,
          name: x.name,
        }));
      }
    })
    .catch(() => {
      allCategories = [];
    });

  function renderCategoryBadges() {
    catSelect.innerHTML = "";
    selectedCategories.forEach((c) => {
      const el = document.createElement("span");
      el.textContent = c.name;
      el.style.display = "inline-block";
      el.style.padding = "4px 8px";
      el.style.borderRadius = "999px";
      el.style.background = "#eef2ff";
      el.style.color = "#4338ca";
      el.style.fontSize = "12px";
      el.style.cursor = "pointer";
      el.title = "Cliquez pour retirer";
      el.addEventListener("click", () => {
        selectedCategories = selectedCategories.filter((sc) => sc.id !== c.id);
        renderCategoryBadges();
      });
      catSelect.appendChild(el);
    });
    catHidden.value = JSON.stringify(selectedCategories.map((c) => c.name));
  }

  catSearch.addEventListener("input", () => {
    const q = catSearch.value.trim().toLowerCase();
    if (!q) {
      catSuggest.style.display = "none";
      catSuggest.innerHTML = "";
      return;
    }
    const found = allCategories
      .filter(
        (c) =>
          c.name.toLowerCase().includes(q) &&
          !selectedCategories.find((sc) => sc.id === c.id)
      )
      .slice(0, 10);
    catSuggest.innerHTML = found
      .map(
        (c) =>
          `<div data-id="${c.id}" style="padding:.5rem;cursor:pointer">${c.name}</div>`
      )
      .join("");
    catSuggest.style.display = found.length ? "block" : "none";
  });
  catSuggest.addEventListener("click", (e) => {
    const id = e.target.getAttribute("data-id");
    if (!id) return;
    const c = allCategories.find((x) => String(x.id) === String(id));
    if (c) {
      selectedCategories.push(c);
      renderCategoryBadges();
      catSuggest.style.display = "none";
      catSearch.value = "";
    }
  });

  // Leaflet map + marker
  if (typeof L !== "undefined") {
    let map = L.map("map");
    let marker = L.marker([0, 0], { draggable: true }).addTo(map);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap",
    }).addTo(map);
    map.setView([14.6937, -17.4441], 12);
    marker.setLatLng([14.6937, -17.4441]);
    latInput.value = "14.6937";
    lonInput.value = "-17.4441";
    marker.on("dragend", () => {
      const p = marker.getLatLng();
      latInput.value = String(p.lat);
      lonInput.value = String(p.lng);
    });
  }

  // Nominatim autocomplete
  let geoTimer = null;
  geoSearch.addEventListener("input", () => {
    const q = geoSearch.value.trim();
    if (geoTimer) clearTimeout(geoTimer);
    if (!q) {
      geoSuggest.style.display = "none";
      geoSuggest.innerHTML = "";
      return;
    }
    geoTimer = setTimeout(() => {
      fetch(
        `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(
          q
        )}&addressdetails=1&limit=8`
      )
        .then((r) => r.json())
        .then((items) => {
          geoSuggest.innerHTML = items
            .map(
              (it) =>
                `<div data-lat="${it.lat}" data-lon="${it.lon}" style="padding:.5rem;cursor:pointer">${it.display_name}</div>`
            )
            .join("");
          geoSuggest.style.display = items.length ? "block" : "none";
        })
        .catch(() => {
          geoSuggest.style.display = "none";
        });
    }, 300);
  });
  geoSuggest.addEventListener("click", (e) => {
    const lat = e.target.getAttribute("data-lat");
    const lon = e.target.getAttribute("data-lon");
    if (!lat || !lon) return;
    const p = [parseFloat(lat), parseFloat(lon)];
    if (typeof L !== "undefined") {
      const map = L.map("map");
      // If map is already initialized above, use that reference; otherwise fallback setView won't work.
    }
    // We still update fields and marker if available
    try {
      const mapEl = document.getElementById("map");
      if (mapEl && mapEl._leaflet_id) {
        const mapInst = mapEl._leaflet_id && L; // indicator only
      }
    } catch {}
    latInput.value = String(p[0]);
    lonInput.value = String(p[1]);
    geoSuggest.style.display = "none";
  });

  function renderDocs() {
    docList.innerHTML = uploadedDocs
      .map(
        (d, i) =>
          `<div class="card" style="padding:.5rem;display:flex;align-items:center;gap:.5rem"><span>${
            d.name || d.filename
          }</span><button type="button" data-i="${i}" class="btn secondary">Retirer</button></div>`
      )
      .join("");
    Array.from(docList.querySelectorAll("button[data-i]")).forEach((btn) => {
      btn.addEventListener("click", () => {
        const idx = parseInt(btn.getAttribute("data-i"));
        uploadedDocs.splice(idx, 1);
        renderDocs();
      });
    });
    docsHidden.value = JSON.stringify(uploadedDocs);
  }
  function uploadFiles(files) {
    const arr = Array.from(files);
    if (!arr.length) return;
    const formData = new FormData();
    arr.forEach((f) => formData.append("files[]", f));
    dropzone.textContent = "Téléversement…";
    fetch(routeBase + "/api/v1/uploads/supplier-docs", {
      method: "POST",
      body: formData,
    })
      .then((r) => r.json())
      .then((d) => {
        dropzone.textContent =
          "Déposez vos fichiers ici ou cliquez pour sélectionner";
        if (Array.isArray(d?.files)) {
          uploadedDocs = uploadedDocs.concat(d.files);
          renderDocs();
        } else if (d?.error) {
          alert("Upload: " + d.error);
        }
      })
      .catch((err) => {
        dropzone.textContent =
          "Déposez vos fichiers ici ou cliquez pour sélectionner";
        console.error(err);
        alert("Erreur lors de l’upload");
      });
  }
  ["dragenter", "dragover"].forEach((evt) =>
    dropzone.addEventListener(evt, (e) => {
      e.preventDefault();
      dropzone.style.background = "#f8fafc";
    })
  );
  ["dragleave", "drop"].forEach((evt) =>
    dropzone.addEventListener(evt, (e) => {
      e.preventDefault();
      dropzone.style.background = "";
    })
  );
  dropzone.addEventListener("drop", (e) => {
    uploadFiles(e.dataTransfer.files);
  });
  dropzone.addEventListener("click", () => fileInput.click());
  fileInput.addEventListener("change", () => uploadFiles(fileInput.files));

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    renderCategoryBadges();
    renderDocs();
    const payload = Object.fromEntries(new FormData(form).entries());
    payload.active = !!payload.active;
    if (payload.categories_json) {
      try {
        payload.categories = JSON.parse(payload.categories_json);
      } catch {
        payload.categories = [];
      }
      delete payload.categories_json;
    }
    if (payload.documents_json) {
      try {
        payload.documents = JSON.parse(payload.documents_json);
      } catch {
        payload.documents = [];
      }
      delete payload.documents_json;
    }
    fetch(routeBase + "/api/v1/suppliers", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .then((d) => {
        if (d && d.ok) {
          alert("Fournisseur créé");
          window.location.href = routeBase + "/suppliers";
        } else {
          alert("Erreur: " + (d.error || "inconnue"));
        }
      })
      .catch((err) => {
        alert("Erreur");
        console.error(err);
      });
  });
})();
