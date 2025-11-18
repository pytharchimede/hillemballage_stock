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

  // Helper d'échappement HTML pour un rendu sûr
  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function renderUsers(users) {
    // Cards grid
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
        return `
        <div class="card-client" data-id="${u.id}">
          <div class="cl-header"><div class="avatar avatar-fallback"><i class="fa fa-user"></i></div></div>
          <div class="cl-body">
            <div class="cl-name">${escapeHtml(u.name || "")}</div>
            <div class="cl-phone"><i class="fa fa-envelope"></i> ${escapeHtml(
              u.email || ""
            )}</div>
            <div class="cl-balance">${roleBadge} · ${dlabel}</div>
          </div>
          <div class="cl-actions">
            <a class="btn" href="${routeBase}/users/edit?id=${u.id}" title="Modifier"><i class="fa fa-pencil"></i></a>
          </div>
        </div>`;
      })
      .join("");
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const grid = document.getElementById("users-grid");
    const empty = document.getElementById("users-empty");
    // Load depots once and populate filter without resetting current selection
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
      if (dr.ok) {
        stateDepots = await dr.json();
      } else {
        stateDepots = [];
      }
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

    // Load users with filters
    const role = document.getElementById("role-filter")?.value || "";
    const q = document.getElementById("q-filter")?.value || "";
    const dep = document.getElementById("depot-filter")?.value || "";
    const params = new URLSearchParams();
    if (role) params.set("role", role);
    if (q) params.set("q", q);
    if (dep) params.set("depot_id", dep);
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

  // formulaire déplacé sur /users/new et /users/edit

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
      load();
    });

  // Inline save supprimé (édition via /users/edit)

  load();
})();
