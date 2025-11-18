(function () {
  const routeBase = window.ROUTE_BASE || "";
  let stateDepots = [];
  let debounceT;
  let depotsPopulated = false;

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

  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function renderUsers(users) {
    const grid = document.getElementById("users-grid");
    const empty = document.getElementById("users-empty");
    if (!grid) return;
    if (!users || users.length === 0) {
      grid.innerHTML = "";
      if (empty) empty.style.display = "block";
      return;
    }
    if (empty) empty.style.display = "none";
    const depotMap = {};
    stateDepots.forEach((d) => (depotMap[d.id] = d));
    grid.innerHTML = users
      .map(function (u) {
        const dep =
          u.depot_id && depotMap[u.depot_id] ? depotMap[u.depot_id] : null;
        const dlabel = dep
          ? `${dep.name}${dep.code ? " (" + dep.code + ")" : ""}`
          : '<span class="muted">Non assigné</span>';
        const roleBadge = `<span class="badge">${escapeHtml(
          u.role || ""
        )}</span>`;
        const inactive = u.active === 0;
        const activeBadge = inactive
          ? '<span class="badge danger">Inactif</span>'
          : '<span class="badge success">Actif</span>';
        const photo = u.photo_path
          ? `<div class="avatar" style="background-image:url('${escapeHtml(
              u.photo_path
            )}');"></div>`
          : `<div class="avatar avatar-fallback"><i class="fa fa-user"></i></div>`;
        let resetInfo = "";
        if (u.last_reset_at) {
          resetInfo = `<span class="badge" title="Dernière réinitialisation">🔑 ${escapeHtml(
            formatRelative(u.last_reset_at)
          )}</span>`;
        }
        return `
        <div class="card-client" data-id="${u.id}">
          <div class="cl-header">${photo}</div>
          <div class="cl-body">
            <div class="cl-name">${escapeHtml(u.name || "")}</div>
            <div class="cl-phone"><i class="fa fa-envelope"></i> ${escapeHtml(
              u.email || ""
            )}</div>
            <div class="cl-balance">${roleBadge} · ${dlabel} · ${activeBadge} ${resetInfo}</div>
          </div>
          <div class="cl-actions">
            <a class="btn" href="${routeBase}/users/edit?id=${u.id}" title="Modifier"><i class="fa fa-pencil"></i></a>
            <a class="btn" href="${routeBase}/users/export?id=${u.id}" title="Exporter PDF"><i class="fa fa-id-card"></i></a>
            ${
              inactive
                ? `<button class="btn activate-btn" data-id="${u.id}" title="Activer"><i class="fa fa-toggle-on"></i></button>`
                : `<button class="btn deactivate-btn" data-id="${u.id}" title="Désactiver"><i class="fa fa-toggle-off"></i></button>`
            }
            <button class="btn reset-btn" data-id="${
              u.id
            }" title="Réinitialiser mot de passe"><i class="fa fa-key"></i></button>
          </div>
        </div>`;
      })
      .join("");
    grid.querySelectorAll(".deactivate-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.getAttribute("data-id"), 10);
        if (!confirm("Désactiver cet utilisateur ?")) return;
        toggleActive(id, false);
      });
    });
    grid.querySelectorAll(".activate-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.getAttribute("data-id"), 10);
        toggleActive(id, true);
      });
    });
    grid.querySelectorAll(".reset-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = parseInt(btn.getAttribute("data-id"), 10);
        if (!confirm("Réinitialiser le mot de passe ?")) return;
        resetPassword(id);
      });
    });
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const grid = document.getElementById("users-grid");
    const empty = document.getElementById("users-empty");
    const depotFilter = document.getElementById("depot-filter");
    if (!depotsPopulated) {
      let depUrl =
        routeBase +
        "/api/v1/depots" +
        (token ? "?api_token=" + encodeURIComponent(token) : "");
      let dr = await fetch(depUrl, { headers: authHeaders(token) });
      if (dr.status === 401) {
        token = (await refreshSessionToken()) || token;
        depUrl =
          routeBase +
          "/api/v1/depots" +
          (token ? "?api_token=" + encodeURIComponent(token) : "");
        dr = await fetch(depUrl, { headers: authHeaders(token) });
      }
      stateDepots = dr.ok ? await dr.json() : [];
      if (depotFilter) {
        const selected = depotFilter.value || "";
        depotFilter.innerHTML =
          '<option value="">Tous</option>' +
          stateDepots
            .map(
              (d) =>
                `<option value="${d.id}">${d.name}${
                  d.code ? " (" + d.code + ")" : ""
                }</option>`
            )
            .join("");
        if (selected) depotFilter.value = selected;
      }
      depotsPopulated = true;
    }
    const role = document.getElementById("role-filter")?.value || "";
    const q = document.getElementById("q-filter")?.value || "";
    const dep = document.getElementById("depot-filter")?.value || "";
    const active = document.getElementById("active-filter")?.value || "";
    const photo = document.getElementById("photo-filter")?.value || "";
    const params = new URLSearchParams();
    if (role) params.set("role", role);
    if (q) params.set("q", q);
    if (dep) params.set("depot_id", dep);
    if (active !== "") params.set("active", active);
    if (photo !== "") params.set("has_photo", photo);
    let baseUsersUrl =
      routeBase +
      "/api/v1/users" +
      (params.toString() ? "?" + params.toString() : "");
    let url =
      baseUsersUrl +
      (baseUsersUrl.indexOf("?") > -1 ? "&" : "?") +
      (token ? "api_token=" + encodeURIComponent(token) : "");
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      baseUsersUrl =
        routeBase +
        "/api/v1/users" +
        (params.toString() ? "?" + params.toString() : "");
      url =
        baseUsersUrl +
        (baseUsersUrl.indexOf("?") > -1 ? "&" : "?") +
        (token ? "api_token=" + encodeURIComponent(token) : "");
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) {
      if (grid) grid.innerHTML = "";
      if (empty) {
        try {
          const err = await r.json();
          empty.textContent = `Erreur ${r.status}: ${
            err.error || "Impossible de charger la liste"
          }`;
        } catch (_) {
          empty.textContent = `Erreur ${r.status}: Impossible de charger la liste`;
        }
        empty.style.display = "block";
      }
      return;
    }
    const users = await r.json();
    renderUsers(users);
  }

  const rf = document.getElementById("role-filter");
  if (rf) rf.addEventListener("change", load);
  const df = document.getElementById("depot-filter");
  if (df) df.addEventListener("change", load);
  const qf = document.getElementById("q-filter");
  if (qf)
    qf.addEventListener("input", function () {
      clearTimeout(debounceT);
      debounceT = setTimeout(load, 300);
    });
  const resetBtn = document.getElementById("btn-reset-filters");
  if (resetBtn)
    resetBtn.addEventListener("click", function () {
      if (qf) qf.value = "";
      if (rf) rf.value = "";
      if (df) df.value = "";
      const af = document.getElementById("active-filter");
      if (af) af.value = "";
      const pf = document.getElementById("photo-filter");
      if (pf) pf.value = "";
      load();
    });
  const af = document.getElementById("active-filter");
  if (af) af.addEventListener("change", load);
  const pf = document.getElementById("photo-filter");
  if (pf) pf.addEventListener("change", load);

  load();

  async function toggleActive(id, active) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url =
      routeBase +
      "/api/v1/users/" +
      id +
      (active ? "/activate" : "/deactivate");
    if (token) url += "?api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { method: "PATCH", headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url =
        routeBase +
        "/api/v1/users/" +
        id +
        (active ? "/activate" : "/deactivate");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      r = await fetch(url, { method: "PATCH", headers: authHeaders(token) });
    }
    if (!r.ok) {
      alert("Erreur changement statut");
      return;
    }
    load();
  }

  async function resetPassword(id) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/users/" + id + "/reset-password";
    if (token) url += "?api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { method: "POST", headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/users/" + id + "/reset-password";
      if (token) url += "?api_token=" + encodeURIComponent(token);
      r = await fetch(url, { method: "POST", headers: authHeaders(token) });
    }
    if (!r.ok) {
      try {
        const j = await r.json();
        alert("Erreur: " + (j.error || r.status));
      } catch (_) {
        alert("Erreur serveur");
      }
      return;
    }
    try {
      const j = await r.json();
      if (!j || !j.password) {
        showPasswordModal("", "Erreur: mot de passe non reçu", null, id);
      } else {
        showPasswordModal(j.password, j.mask || "", j.log_id || null, id);
      }
    } catch (e) {
      showPasswordModal("", "Erreur parsing réponse", null, id);
    }
  }
  // Modal helpers
  const pwModal = document.getElementById("pw-reset-modal");
  const pwVal = document.getElementById("pw-reset-value");
  const pwCopy = document.getElementById("pw-reset-copy");
  const pwClose = document.getElementById("pw-reset-close");
  const pwMask = document.getElementById("pw-reset-mask");

  function showPasswordModal(password, mask, logId, userId) {
    if (pwVal) pwVal.value = password && password.length ? password : "";
    if (pwMask) pwMask.textContent = mask ? mask : "";
    if (pwModal) pwModal.style.display = "flex";
  }
  if (pwClose)
    pwClose.addEventListener("click", () => {
      if (pwModal) pwModal.style.display = "none";
    });
  if (pwCopy)
    pwCopy.addEventListener("click", () => {
      if (!pwVal) return;
      const txt = pwVal.value;
      navigator.clipboard
        .writeText(txt)
        .then(() => {
          pwCopy.innerHTML = '<i class="fa fa-check"></i>';
          setTimeout(
            () => (pwCopy.innerHTML = '<i class="fa fa-copy"></i>'),
            1500
          );
        })
        .catch(() => {
          alert("Copie impossible");
        });
    });

  // Logs modal
  const logModal = document.getElementById("pw-log-modal");
  const logBody = document.getElementById("pw-log-body");
  const logClose = document.getElementById("pw-log-close");
  if (logClose)
    logClose.addEventListener("click", () => {
      if (logModal) logModal.style.display = "none";
    });
  window.LOAD_PW_LOG = async function () {
    if (!logBody) return;
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/users/reset-logs";
    if (token) url += "?api_token=" + encodeURIComponent(token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/users/reset-logs";
      if (token) url += "?api_token=" + encodeURIComponent(token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) {
      logBody.innerHTML =
        '<div class="muted" style="padding:.75rem">Erreur chargement journal</div>';
      return;
    }
    const rows = await r.json();
    if (!rows || rows.length === 0) {
      logBody.innerHTML =
        '<div class="muted" style="padding:.75rem">Aucun reset enregistré.</div>';
      return;
    }
    const tbl = [
      '<table class="pw-log-table"><thead><tr><th>ID</th><th>Utilisateur</th><th>Admin</th><th>Masque</th><th>Date</th></tr></thead><tbody>',
    ];
    rows.forEach((rw) => {
      tbl.push(
        "<tr><td>" +
          rw.id +
          "</td><td>" +
          escapeHtml(rw.user_name || "#" + rw.user_id) +
          "</td><td>" +
          escapeHtml(rw.admin_name || "#" + rw.admin_id) +
          "</td><td>" +
          escapeHtml(rw.password_mask || "") +
          "</td><td>" +
          escapeHtml(rw.created_at || "") +
          "</td></tr>"
      );
    });
    tbl.push("</tbody></table>");
    logBody.innerHTML = tbl.join("");
  };
  // Bouton journal
  const btnLog = document.getElementById("btn-show-reset-log");
  if (btnLog)
    btnLog.addEventListener("click", () => {
      const m = document.getElementById("pw-log-modal");
      if (m) {
        m.style.display = "flex";
        window.LOAD_PW_LOG && window.LOAD_PW_LOG();
      }
    });
  // Relative time helper
  function formatRelative(ts) {
    const d = new Date(ts);
    if (isNaN(d.getTime())) return ts;
    const diffMs = Date.now() - d.getTime();
    const sec = Math.floor(diffMs / 1000);
    if (sec < 60) return "il y a " + sec + "s";
    const min = Math.floor(sec / 60);
    if (min < 60) return "il y a " + min + "m";
    const h = Math.floor(min / 60);
    if (h < 24) return "il y a " + h + "h";
    const day = Math.floor(h / 24);
    if (day < 7) return "il y a " + day + "j";
    return d.toISOString().substring(0, 10);
  }
})();
