(function () {
  const routeBase = window.ROUTE_BASE || "";
  const userId = window.USER_ID || 0;
  const form = document.getElementById("user-form");
  const msg = document.getElementById("user-form-msg");
  const titleEl = document.getElementById("user-form-title");
  const nameEl = document.getElementById("u_name");
  const emailEl = document.getElementById("u_email");
  const roleEl = document.getElementById("u_role");
  const depotEl = document.getElementById("u_depot");
  const pwField = document.getElementById("pw-field");
  const dz = document.getElementById("user-photo-dropzone");
  const fileInput = document.getElementById("u_photo");
  const preview = document.getElementById("user-photo-preview");

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

  function collectPermissions() {
    const map = {};
    document.querySelectorAll(".perm").forEach(function (cb) {
      const ent = cb.getAttribute("data-entity");
      const act = cb.getAttribute("data-action");
      if (!map[ent]) map[ent] = { view: false, edit: false, delete: false };
      if (cb.checked) map[ent][act] = true;
    });
    return map;
  }
  function applyPermissions(perms) {
    if (!perms) return;
    document.querySelectorAll(".perm").forEach(function (cb) {
      const ent = cb.getAttribute("data-entity");
      const act = cb.getAttribute("data-action");
      const on = perms[ent] && !!perms[ent][act];
      cb.checked = !!on;
    });
  }

  async function loadDepots() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/depots";
    if (token) url += "?api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/depots";
      if (token) url += "?api_token=" + encodeURIComponent(token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const depots = await r.json();
    depotEl.innerHTML =
      '<option value=""></option>' +
      depots
        .map(function (d) {
          return `<option value="${
            d.id
          }">${d.name} ${d.code ? "(" + d.code + ")" : ""}</option>`;
        })
        .join("");
  }

  async function loadUser(id) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/users/" + id;
    if (token) url += "?api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/users/" + id;
      if (token) url += "?api_token=" + encodeURIComponent(token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return null;
    return await r.json();
  }

  // Dropzone behavior (preview only; backend persistence non implémentée)
  if (dz) {
    const openPicker = () => fileInput && fileInput.click();
    const setPreview = (file) => {
      if (!file) return;
      const reader = new FileReader();
      reader.onload = function (e) {
        if (preview) {
          preview.src = e.target.result;
          preview.style.display = "block";
        }
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
    if (fileInput) {
      fileInput.addEventListener("change", (e) => {
        const f = e.target.files && e.target.files[0];
        if (f) setPreview(f);
      });
    }
    if (preview) {
      preview.addEventListener("click", openPicker);
    }
  }

  if (form) {
    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      msg.textContent = "";
      const payload = {
        name: nameEl.value || "",
        email: emailEl.value || "",
        role: roleEl.value || "gerant",
        depot_id: depotEl.value ? parseInt(depotEl.value, 10) : null,
        permissions: collectPermissions(),
      };
      const pw = document.getElementById("u_password")?.value || "";
      if (!userId && !pw) {
        msg.className = "alert alert-error";
        msg.textContent = "Mot de passe requis";
        return;
      }
      if (pw) payload.password = pw;
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url =
        routeBase + (userId ? "/api/v1/users/" + userId : "/api/v1/users");
      if (token)
        url +=
          (url.indexOf("?") === -1 ? "?" : "&") +
          "api_token=" +
          encodeURIComponent(token);
      let method = userId ? "PATCH" : "POST";
      // Sur création uniquement: si fichier choisi, envoyer en FormData (le backend ignorera la photo tant que non géré)
      let r;
      const hasFile =
        !userId && fileInput && fileInput.files && fileInput.files[0];
      if (hasFile && method === "POST") {
        const fd = new FormData();
        Object.keys(payload).forEach((k) => {
          if (k === "permissions") {
            fd.append("permissions", JSON.stringify(payload[k] || {}));
          } else if (payload[k] !== null && typeof payload[k] !== "undefined") {
            fd.append(k, String(payload[k]));
          }
        });
        fd.append("photo", fileInput.files[0]);
        r = await fetch(url, {
          method,
          headers: authHeaders(token),
          body: fd,
        });
      } else {
        r = await fetch(url, {
          method,
          headers: Object.assign(
            { "Content-Type": "application/json" },
            authHeaders(token)
          ),
          body: JSON.stringify(payload),
        });
      }
      if (r.status === 401) {
        token = (await refreshSessionToken()) || token;
        url =
          routeBase + (userId ? "/api/v1/users/" + userId : "/api/v1/users");
        if (token)
          url +=
            (url.indexOf("?") === -1 ? "?" : "&") +
            "api_token=" +
            encodeURIComponent(token);
        if (hasFile && method === "POST") {
          const fd = new FormData();
          Object.keys(payload).forEach((k) => {
            if (k === "permissions")
              fd.append("permissions", JSON.stringify(payload[k] || {}));
            else if (payload[k] !== null && typeof payload[k] !== "undefined")
              fd.append(k, String(payload[k]));
          });
          fd.append("photo", fileInput.files[0]);
          r = await fetch(url, {
            method,
            headers: authHeaders(token),
            body: fd,
          });
        } else {
          r = await fetch(url, {
            method,
            headers: Object.assign(
              { "Content-Type": "application/json" },
              authHeaders(token)
            ),
            body: JSON.stringify(payload),
          });
        }
      }
      if (r.ok) {
        msg.className = "alert alert-success";
        msg.textContent = userId
          ? "Utilisateur mis à jour"
          : "Utilisateur créé";
        if (!userId) {
          form.reset();
          if (preview) {
            preview.src = "";
            preview.style.display = "none";
          }
          if (dz) dz.classList.remove("has-image");
        }
      } else {
        msg.className = "alert alert-error";
        try {
          const j = await r.json();
          msg.textContent = "Erreur: " + (j.error || r.status);
        } catch (_) {
          msg.textContent = "Erreur lors de l'enregistrement";
        }
      }
    });
  }

  // init
  loadDepots().then(function () {
    if (userId) {
      loadUser(userId).then(function (u) {
        if (!u) return;
        if (titleEl) titleEl.textContent = "Modifier l'utilisateur";
        nameEl.value = u.name || "";
        emailEl.value = u.email || "";
        roleEl.value = u.role || "gerant";
        if (u.depot_id) depotEl.value = String(u.depot_id);
        try {
          const perms = u.permissions
            ? JSON.parse(u.permissions)
            : u.permissions || {};
          applyPermissions(perms);
        } catch (e) {
          /* ignore */
        }
        if (pwField) {
          /* en édition, mdp optionnel */
        }
      });
    }
  });
})();
