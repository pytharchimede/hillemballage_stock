(function () {
  const routeBase = window.ROUTE_BASE || "";
  let stateDepots = [];

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

  function renderUsers(users) {
    const tb = document.querySelector("#users-table tbody");
    const roleOptions = ["admin", "gerant", "livreur"];
    tb.innerHTML = users
      .map((u) => {
        const roleSel = `<select class=\"u-role\" data-id=\"${
          u.id
        }\">${roleOptions
          .map(
            (r) =>
              `<option value=\"${r}\" ${
                u.role === r ? "selected" : ""
              }>${r}</option>`
          )
          .join("")}</select>`;
        const depotSel = `<select class=\"u-depot\" data-id=\"${
          u.id
        }\"><option value=\"\"></option>${stateDepots
          .map(
            (d) =>
              `<option value=\"${d.id}\" ${
                u.depot_id == d.id ? "selected" : ""
              }>${d.name} (${d.code || ""})</option>`
          )
          .join("")}</select>`;
        const saveBtn = `<button class=\"btn u-save\" data-id=\"${u.id}\">Mettre à jour</button>`;
        return `<tr>
          <td>${u.name}</td>
          <td>${u.email}</td>
          <td>${roleSel}</td>
          <td>${depotSel}</td>
          <td>${saveBtn}</td>
        </tr>`;
      })
      .join("");
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    // Load depots
    let depUrl = routeBase + "/api/v1/depots";
    let dr = await fetch(depUrl, { headers: authHeaders(token) });
    if (dr.status === 401) {
      token = (await refreshSessionToken()) || token;
      dr = await fetch(depUrl, { headers: authHeaders(token) });
    }
    if (dr.ok) {
      stateDepots = await dr.json();
    } else {
      stateDepots = [];
    }
    // Load users
    let url = routeBase + "/api/v1/users";
    const role = document.getElementById("role-filter")?.value || "";
    if (role) url += "?role=" + encodeURIComponent(role);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const users = await r.json();
    renderUsers(users);
  }

  document.getElementById("user-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const form = e.target;
    const payload = Object.fromEntries(new FormData(form).entries());
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/users";
    let r = await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...authHeaders(token),
      },
      body: JSON.stringify(payload),
    });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      r = await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          ...authHeaders(token),
        },
        body: JSON.stringify(payload),
      });
    }
    if (r.ok) {
      form.reset();
      load();
    }
  });

  const rf = document.getElementById("role-filter");
  if (rf) rf.addEventListener("change", load);

  // Handle inline save
  const tb = document.querySelector("#users-table tbody");
  if (tb) {
    tb.addEventListener("click", async (e) => {
      const btn = e.target.closest(".u-save");
      if (!btn) return;
      const id = parseInt(btn.getAttribute("data-id"), 10);
      const role =
        tb.querySelector(`select.u-role[data-id="${id}"]`)?.value || "";
      const depot =
        tb.querySelector(`select.u-depot[data-id="${id}"]`)?.value || "";
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = routeBase + "/api/v1/users/" + id;
      btn.disabled = true;
      let rr = await fetch(url, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify({
          role: role || null,
          depot_id: depot ? parseInt(depot, 10) : null,
        }),
      });
      if (rr.status === 401) {
        token = (await refreshSessionToken()) || token;
        rr = await fetch(url, {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            ...authHeaders(token),
          },
          body: JSON.stringify({
            role: role || null,
            depot_id: depot ? parseInt(depot, 10) : null,
          }),
        });
      }
      btn.disabled = false;
      if (!rr.ok) {
        window.showToast && window.showToast("error", "Mise à jour échouée");
      } else {
        window.showToast &&
          window.showToast("success", "Utilisateur mis à jour");
      }
    });
  }

  load();
})();
