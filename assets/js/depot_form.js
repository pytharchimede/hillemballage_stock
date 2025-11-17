"use strict";
(function () {
  const form = document.getElementById("depot-form");
  if (!form) return;
  const routeBase = window.ROUTE_BASE || "";
  const mode = form.dataset.mode || "create";
  const depotId = parseInt(form.dataset.depotId || "0", 10);

  const nameInput = document.getElementById("dep-name");
  const codeInput = document.getElementById("dep-code");
  const mgrInput = document.getElementById("dep-manager");
  const phoneInput = document.getElementById("dep-phone");
  const addrInput = document.getElementById("dep-address");
  const latInput = document.getElementById("dep-lat");
  const lngInput = document.getElementById("dep-lng");
  const suggest = document.getElementById("addr-suggest");
  const mainCheckbox = document.getElementById("dep-main");
  const mgrHidden = document.getElementById("dep-manager-id");
  const mgrSuggest = document.getElementById("manager-suggest");

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
    } catch (_) {}
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

  // Map setup
  let map, marker;
  function ensureMap() {
    if (map) return;
    map = L.map("map");
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
    }).addTo(map);
    map.setView([5.345317, -4.024429], 11); // Abidjan par défaut
    map.on("click", function (e) {
      setMarker(e.latlng.lat, e.latlng.lng);
    });
  }
  function setMarker(lat, lng) {
    if (!map) ensureMap();
    if (marker) {
      marker.setLatLng([lat, lng]);
    } else {
      marker = L.marker([lat, lng], { draggable: true }).addTo(map);
      marker.on("dragend", function () {
        var p = marker.getLatLng();
        latInput.value = p.lat;
        lngInput.value = p.lng;
      });
    }
    map.setView([lat, lng], 15);
    latInput.value = lat;
    lngInput.value = lng;
  }

  // Address autocomplete (Nominatim)
  let acTimer = null;
  function hideSuggest() {
    if (suggest) suggest.style.display = "none";
  }
  function showSuggest(items) {
    if (!suggest) return;
    if (!items || items.length === 0) {
      suggest.style.display = "none";
      suggest.innerHTML = "";
      return;
    }
    suggest.innerHTML = items
      .map(function (it, idx) {
        return (
          '<div class="combo-item" data-idx="' +
          idx +
          '">' +
          (it.display_name || "") +
          "</div>"
        );
      })
      .join("");
    suggest.style.display = "block";
  }
  async function searchAddress(q) {
    if (!q || q.trim().length < 3) {
      showSuggest([]);
      return;
    }
    try {
      const url =
        "https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=5&q=" +
        encodeURIComponent(q);
      const r = await fetch(url, { headers: { Accept: "application/json" } });
      if (!r.ok) {
        showSuggest([]);
        return;
      }
      const data = await r.json();
      showSuggest(data || []);
      // store data for select
      addrInput._ac = data || [];
    } catch (_) {
      showSuggest([]);
    }
  }
  if (addrInput && suggest) {
    addrInput.addEventListener("input", function () {
      clearTimeout(acTimer);
      var v = addrInput.value;
      acTimer = setTimeout(function () {
        searchAddress(v);
      }, 250);
    });
    suggest.addEventListener("click", function (e) {
      const el = e.target.closest(".combo-item");
      if (!el) return;
      const idx = parseInt(el.getAttribute("data-idx") || "-1", 10);
      const arr = addrInput._ac || [];
      if (idx >= 0 && idx < arr.length) {
        const it = arr[idx];
        addrInput.value = it.display_name || "";
        setMarker(parseFloat(it.lat), parseFloat(it.lon));
        hideSuggest();
      }
    });
    document.addEventListener("click", function (e) {
      if (
        e.target !== addrInput &&
        e.target !== suggest &&
        !(suggest.contains && suggest.contains(e.target))
      )
        hideSuggest();
    });
  }

  // --- Auto code generation (create mode) ---
  function genCode(name) {
    const clean = (name || "Depot").replace(/[^A-Za-z0-9]/g, "").toUpperCase();
    const prefix = clean.slice(0, 3) || "DEP";
    const t = Date.now().toString(36).toUpperCase();
    return prefix + "-" + t.slice(-6);
  }
  function refreshCode() {
    if (mode !== "create" || !codeInput) return;
    codeInput.value = genCode(((nameInput && nameInput.value) || "").trim());
  }
  if (codeInput) codeInput.readOnly = true;
  if (mode === "create" && nameInput && codeInput) {
    refreshCode();
    nameInput.addEventListener("input", refreshCode);
  }

  // --- Manager autocomplete ---
  let allManagers = [];
  function renderMgrSuggestions(list) {
    if (!mgrSuggest) return;
    if (!list || list.length === 0) {
      mgrSuggest.style.display = "none";
      mgrSuggest.innerHTML = "";
      return;
    }
    mgrSuggest.innerHTML = list
      .map(function (u) {
        return (
          '<div class="combo-item" data-id="' +
          u.id +
          '">' +
          (u.name || "") +
          ' <span class="muted">(' +
          (u.email || u.role || "") +
          ")</span></div>"
        );
      })
      .join("");
    mgrSuggest.style.display = "block";
  }
  function filterManagers(q) {
    q = (q || "").trim().toLowerCase();
    if (!q) return allManagers.slice(0, 8);
    return allManagers
      .filter(function (u) {
        return (
          (u.name || "").toLowerCase().indexOf(q) !== -1 ||
          (u.email || "").toLowerCase().indexOf(q) !== -1
        );
      })
      .slice(0, 8);
  }
  async function loadManagers() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/users?role=gerant";
    if (token) url = withToken(url, token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/users?role=gerant";
      if (token) url = withToken(url, token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const data = await r.json();
    allManagers = Array.isArray(data) ? data : [];
  }
  if (mgrInput) {
    mgrInput.addEventListener("input", function () {
      if (!allManagers.length) {
        loadManagers().then(function () {
          renderMgrSuggestions(filterManagers(mgrInput.value));
        });
      } else {
        renderMgrSuggestions(filterManagers(mgrInput.value));
      }
    });
    document.addEventListener("click", function (e) {
      if (
        e.target !== mgrInput &&
        e.target !== mgrSuggest &&
        !(mgrSuggest && mgrSuggest.contains && mgrSuggest.contains(e.target))
      ) {
        if (mgrSuggest) mgrSuggest.style.display = "none";
      }
    });
  }
  if (mgrSuggest) {
    mgrSuggest.addEventListener("click", function (e) {
      const el = e.target.closest(".combo-item");
      if (!el) return;
      const id = parseInt(el.getAttribute("data-id") || "0", 10);
      const u = allManagers.find(function (x) {
        return x.id === id;
      });
      if (!u) return;
      if (mgrHidden) mgrHidden.value = String(id);
      if (mgrInput) mgrInput.value = u.name || "";
      mgrSuggest.style.display = "none";
    });
  }

  async function preload() {
    ensureMap();
    if (mode === "edit" && depotId) {
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = withToken(routeBase + "/api/v1/depots/" + depotId, token);
      let r = await fetch(url, { headers: authHeaders(token) });
      if (r.status === 401) {
        token = (await refreshSessionToken()) || token;
        url = withToken(routeBase + "/api/v1/depots/" + depotId, token);
        r = await fetch(url, { headers: authHeaders(token) });
      }
      if (r.ok) {
        const d = await r.json();
        if (nameInput) nameInput.value = d.name || "";
        if (codeInput) codeInput.value = d.code || "";
        if (mgrInput) mgrInput.value = d.manager_name || "";
        if (mgrHidden)
          mgrHidden.value = d.manager_user_id ? String(d.manager_user_id) : "";
        if (phoneInput) phoneInput.value = d.phone || "";
        if (addrInput) addrInput.value = d.address || "";
        if (mainCheckbox) {
          mainCheckbox.checked = !!d.is_main;
          mainCheckbox.disabled = true;
        }
        if (d.latitude && d.longitude)
          setMarker(parseFloat(d.latitude), parseFloat(d.longitude));
      }
    }
  }

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    // simple phone sanity
    if (
      phoneInput &&
      phoneInput.value &&
      !/^[+0-9\s-]{6,}$/.test(phoneInput.value)
    ) {
      alert("Téléphone invalide");
      phoneInput.focus();
      return;
    }
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    // If manager not selected but name provided, create user first
    async function ensureManagerUserId() {
      if (!mgrInput) return null;
      const name = (mgrInput.value || "").trim();
      const hasId = mgrHidden && mgrHidden.value;
      if (!name || hasId) return hasId ? parseInt(mgrHidden.value, 10) : null;
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = routeBase + "/api/v1/users";
      if (token) url = withToken(url, token);
      const email = "gerant." + Date.now().toString(36) + "@local";
      const body = { name: name, email: email, role: "gerant" };
      let rr = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify(body),
      });
      if (rr.status === 401) {
        token = (await refreshSessionToken()) || token;
        url = routeBase + "/api/v1/users";
        if (token) url = withToken(url, token);
        rr = await fetch(url, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            ...authHeaders(token),
          },
          body: JSON.stringify(body),
        });
      }
      if (!rr.ok) return null;
      // API returns {created:true} only; refetch list to find ID
      await loadManagers();
      const found = allManagers.find(
        (u) => (u.name || "").toLowerCase() === name.toLowerCase()
      );
      return found ? found.id : null;
    }
    const maybeMgrId = await ensureManagerUserId();
    if (maybeMgrId) {
      payload.manager_user_id = maybeMgrId;
      if (mgrHidden) mgrHidden.value = String(maybeMgrId);
    }
    if (mode === "create" && mainCheckbox)
      payload.is_main = mainCheckbox.checked ? 1 : 0;
    const method = mode === "edit" ? "PATCH" : "POST";
    let url =
      mode === "edit"
        ? routeBase + "/api/v1/depots/" + depotId
        : routeBase + "/api/v1/depots";
    if (token) url = withToken(url, token);
    let r = await fetch(url, {
      method,
      headers: { "Content-Type": "application/json", ...authHeaders(token) },
      body: JSON.stringify(payload),
    });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        mode === "edit"
          ? routeBase + "/api/v1/depots/" + depotId
          : routeBase + "/api/v1/depots";
      if (token) url = withToken(url, token);
      r = await fetch(url, {
        method,
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify(payload),
      });
    }
    if (!r.ok) {
      try {
        const j = await r.json();
        alert("Erreur: " + (j.error || r.status));
      } catch (_) {
        alert("Erreur lors de la sauvegarde");
      }
      return;
    }
    window.location.href = routeBase + "/depots";
  });

  preload();
})();
