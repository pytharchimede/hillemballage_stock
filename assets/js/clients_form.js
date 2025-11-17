(function () {
  const routeBase = window.ROUTE_BASE || "";
  const clientId = window.CLIENT_ID || 0;
  const form = document.getElementById("client-form");
  const msg = document.getElementById("client-form-msg");
  const dz = document.getElementById("photo-dropzone");
  const fileInput = document.getElementById("c_photo");
  const preview = document.getElementById("photo-preview");
  const latEl = document.getElementById("lat");
  const lngEl = document.getElementById("lng");
  const btnUseLoc = document.getElementById("btn-use-location");
  const titleEl = document.getElementById("client-form-title");
  const addrInput = document.getElementById("c_address");
  const addrCombo = document.getElementById("addr-combo");
  const addrMenu = addrCombo ? addrCombo.querySelector(".combo-menu") : null;
  const btnPickImage = document.getElementById("btn-pick-image");

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

  // Dropzone behavior
  if (dz) {
    const openPicker = () => fileInput && fileInput.click();
    const setPreview = (file) => {
      if (!file) return;
      const reader = new FileReader();
      reader.onload = function (e) {
        preview.src = e.target.result;
        preview.style.display = "block";
        dz.classList.add("has-image");
      };
      reader.readAsDataURL(file);
    };
    dz.addEventListener("click", openPicker);
    dz.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        openPicker();
      }
    });
    dz.addEventListener("dragover", (e) => {
      e.preventDefault();
      dz.classList.add("drag");
    });
    dz.addEventListener("dragleave", () => dz.classList.remove("drag"));
    dz.addEventListener("drop", (e) => {
      e.preventDefault();
      dz.classList.remove("drag");
      const f = e.dataTransfer.files && e.dataTransfer.files[0];
      if (f) {
        fileInput.files = e.dataTransfer.files;
        setPreview(f);
      }
    });
    fileInput.addEventListener("change", (e) => {
      const f = e.target.files && e.target.files[0];
      if (f) setPreview(f);
    });
  }
  if (btnPickImage) {
    btnPickImage.addEventListener("click", function (e) {
      e.preventDefault();
      if (fileInput) fileInput.click();
    });
  }
  if (preview) {
    preview.addEventListener("click", function () {
      if (fileInput) fileInput.click();
    });
  }

  // Leaflet map
  let map, marker;
  function initMap() {
    map = L.map("client-map").setView([5.353, -4.003], 12);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap",
    }).addTo(map);
    function setPoint(lat, lng) {
      if (marker) {
        marker.setLatLng([lat, lng]);
      } else {
        marker = L.marker([lat, lng]).addTo(map);
      }
      latEl.value = String(lat);
      lngEl.value = String(lng);
    }
    map.on("click", function (e) {
      setPoint(e.latlng.lat, e.latlng.lng);
    });
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function (pos) {
        const lat = pos.coords.latitude,
          lng = pos.coords.longitude;
        if (!clientId) map.setView([lat, lng], 14);
      });
    }
    if (btnUseLoc) {
      btnUseLoc.addEventListener("click", function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (pos) {
          const lat = pos.coords.latitude,
            lng = pos.coords.longitude;
          map.setView([lat, lng], 15);
          if (!marker) marker = L.marker([lat, lng]).addTo(map);
          else marker.setLatLng([lat, lng]);
          latEl.value = String(lat);
          lngEl.value = String(lng);
        });
      });
    }
  }

  // Address autocomplete via Nominatim
  function debounce(fn, ms) {
    let t;
    return function () {
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }
  async function searchAddress(q) {
    if (!q || q.trim().length < 3) {
      if (addrMenu) addrMenu.style.display = "none";
      return;
    }
    const url = `https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=8&q=${encodeURIComponent(
      q
    )}`;
    const r = await fetch(url, { headers: { Accept: "application/json" } });
    if (!r.ok) return;
    const rows = await r.json();
    if (!addrMenu) return;
    if (!rows || rows.length === 0) {
      addrMenu.innerHTML = `<div class="combo-empty">Aucun résultat</div>`;
      addrMenu.style.display = "block";
      return;
    }
    addrMenu.innerHTML = rows
      .map(function (it) {
        const label = (it.display_name || "").replace(/</g, "&lt;");
        return `<div class="combo-item" data-lat="${it.lat}" data-lon="${it.lon}" data-label="${label}">${label}</div>`;
      })
      .join("");
    addrMenu.style.display = "block";
  }
  const debouncedAddr = debounce(function (e) {
    searchAddress(e.target.value);
  }, 300);
  if (addrInput) {
    addrInput.addEventListener("input", debouncedAddr);
    document.addEventListener("click", function (ev) {
      if (!addrCombo) return;
      if (!addrCombo.contains(ev.target)) {
        if (addrMenu) addrMenu.style.display = "none";
      }
    });
    if (addrMenu) {
      addrMenu.addEventListener("click", function (ev) {
        const item = ev.target.closest(".combo-item");
        if (!item) return;
        const lat = parseFloat(item.getAttribute("data-lat"));
        const lon = parseFloat(item.getAttribute("data-lon"));
        const label = item.getAttribute("data-label") || "";
        if (!isNaN(lat) && !isNaN(lon)) {
          if (!map) initMap();
          if (map) {
            map.setView([lat, lon], 16);
          }
          if (!marker) marker = L.marker([lat, lon]).addTo(map);
          else marker.setLatLng([lat, lon]);
          latEl.value = String(lat);
          lngEl.value = String(lon);
        }
        if (addrInput) addrInput.value = label.replace(/&lt;/g, "<");
        addrMenu.style.display = "none";
      });
    }
  }

  async function ensureAuth() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    if (!token) {
      try {
        const tr = await fetch(apiUrl("/api/v1/auth/session-token"));
        if (tr.ok) {
          const tj = await tr.json();
          if (tj && tj.token) {
            token = tj.token;
            localStorage.setItem("api_token", token);
            document.cookie = "api_token=" + token + "; path=/";
          }
        }
      } catch (_) {}
    }
    return localStorage.getItem("api_token") || readCookieToken() || "";
  }

  async function fetchClient(id) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl("/api/v1/clients/" + id);
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
              apiUrl("/api/v1/clients/" + id) +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              headers: { Authorization: "Bearer " + token },
            });
          }
        }
      } catch (_) {}
    }
    if (!r.ok) return null;
    return await r.json();
  }

  if (form) {
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      msg.textContent = "";
      const token = await ensureAuth();
      if (!clientId) {
        // Create
        const fd = new FormData(form);
        let url = apiUrl("/api/v1/clients");
        if (token) url += "?api_token=" + encodeURIComponent(token);
        const r = await fetch(url, {
          method: "POST",
          headers: token ? { Authorization: "Bearer " + token } : {},
          body: fd,
        });
        if (r.ok) {
          msg.className = "alert alert-success";
          msg.textContent = "Client créé avec succès";
          form.reset();
          if (preview) {
            preview.src = "";
            preview.style.display = "none";
          }
          if (marker) {
            try {
              map.removeLayer(marker);
            } catch (_) {}
            marker = null;
          }
          latEl.value = "";
          lngEl.value = "";
        } else {
          msg.className = "alert alert-error";
          try {
            const j = await r.json();
            msg.textContent = "Erreur: " + (j.error || r.status);
          } catch (_) {
            msg.textContent = "Erreur lors de la création.";
          }
        }
      } else {
        // Update
        const fd = new FormData();
        fd.append("name", document.getElementById("c_name").value || "Client");
        fd.append("phone", document.getElementById("c_phone").value || "");
        fd.append(
          "address",
          document.getElementById("c_address")
            ? document.getElementById("c_address").value || ""
            : ""
        );
        if (fileInput && fileInput.files && fileInput.files[0])
          fd.append("photo", fileInput.files[0]);
        let url = apiUrl("/api/v1/clients/" + clientId);
        if (token) url += "?api_token=" + encodeURIComponent(token);
        let r = await fetch(url, {
          method: "PATCH",
          headers: token ? { Authorization: "Bearer " + token } : {},
          body: fd,
        });
        if (!r.ok) {
          msg.className = "alert alert-error";
          try {
            const j = await r.json();
            msg.textContent = "Erreur: " + (j.error || r.status);
          } catch (_) {
            msg.textContent = "Erreur lors de la mise à jour.";
          }
          return;
        }
        // Geo update if provided
        const lat = latEl.value.trim();
        const lng = lngEl.value.trim();
        if (lat && lng) {
          let geoUrl = apiUrl("/api/v1/clients/" + clientId + "/geo");
          if (token) geoUrl += "?api_token=" + encodeURIComponent(token);
          await fetch(geoUrl, {
            method: "PATCH",
            headers: Object.assign(
              { "Content-Type": "application/json" },
              token ? { Authorization: "Bearer " + token } : {}
            ),
            body: JSON.stringify({
              latitude: parseFloat(lat),
              longitude: parseFloat(lng),
            }),
          });
        }
        msg.className = "alert alert-success";
        msg.textContent = "Client mis à jour.";
      }
    });
  }

  function applyClientToForm(c) {
    if (!c) return;
    if (titleEl) titleEl.textContent = "Modifier le client";
    const name = document.getElementById("c_name");
    if (name) name.value = c.name || "";
    const phone = document.getElementById("c_phone");
    if (phone) phone.value = c.phone || "";
    const address = document.getElementById("c_address");
    if (address) address.value = c.address || "";
    if (c.photo_path) {
      preview.src = resolveImg(c.photo_path);
      preview.style.display = "block";
      dz.classList.add("has-image");
    }
    if (
      typeof c.latitude !== "undefined" &&
      typeof c.longitude !== "undefined" &&
      c.latitude !== null &&
      c.longitude !== null
    ) {
      const lat = parseFloat(c.latitude),
        lng = parseFloat(c.longitude);
      if (!isNaN(lat) && !isNaN(lng)) {
        if (!marker) marker = L.marker([lat, lng]).addTo(map);
        else marker.setLatLng([lat, lng]);
        latEl.value = String(lat);
        lngEl.value = String(lng);
        map.setView([lat, lng], 15);
      }
    }
  }

  // init
  initMap();
  if (clientId) {
    ensureAuth().then(() => fetchClient(clientId).then(applyClientToForm));
  }
})();
