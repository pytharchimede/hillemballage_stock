(function () {
  const routeBase = window.ROUTE_BASE || "";
  const form = document.getElementById("client-new-form");
  const msg = document.getElementById("client-new-msg");
  const dz = document.getElementById("photo-dropzone");
  const fileInput = document.getElementById("c_photo");
  const preview = document.getElementById("photo-preview");
  const latEl = document.getElementById("lat");
  const lngEl = document.getElementById("lng");
  const btnUseLoc = document.getElementById("btn-use-location");

  function apiUrl(path) {
    return routeBase + path;
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
        map.setView([lat, lng], 14);
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

  if (form) {
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      msg.textContent = "";
      const fd = new FormData(form);
      const token = await ensureAuth();
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
    });
  }

  // init
  initMap();
})();
