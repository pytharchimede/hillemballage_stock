"use strict";
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

  function withApiToken(url, token) {
    return (
      url +
      (url.indexOf("?") === -1 ? "?" : "&") +
      "api_token=" +
      encodeURIComponent(token || "")
    );
  }
  function authHeaders(token) {
    return token ? { Authorization: "Bearer " + token } : {};
  }

  function render(depots) {
    const grid = document.getElementById("depots-grid");
    const empty = document.getElementById("depots-empty");
    if (!Array.isArray(depots) || depots.length === 0) {
      if (grid) grid.innerHTML = "";
      if (empty) empty.style.display = "block";
      return;
    }
    if (empty) empty.style.display = "none";
    grid.innerHTML = depots
      .map((d) => {
        const addr = d.address
          ? `<div class="muted"><i class="fas fa-location-dot"></i> ${d.address}</div>`
          : "";
        const phone = d.phone
          ? `<div><i class="fas fa-phone"></i> ${d.phone}</div>`
          : "";
        const manager = d.manager_name
          ? `<div><i class="fas fa-user-tie"></i> ${d.manager_name}</div>`
          : "";
        const main = d.is_main
          ? `<span class="badge-main-depot"><i class="fas fa-star"></i> Principal</span>`
          : "";
        const mainBtn = d.is_main
          ? `<button class="btn btn-outline-main" disabled title="Déjà principal"><i class="fas fa-star"></i> Principal</button>`
          : `<button class="btn btn-outline-main btn-set-main" data-id="${d.id}"><i class="fas fa-star"></i> Définir principal</button>`;
        const thumb =
          d.latitude && d.longitude
            ? `<div class="thumb"><img alt="carte" src="https://staticmap.openstreetmap.de/staticmap.php?center=${d.latitude},${d.longitude}&zoom=14&size=480x220&markers=${d.latitude},${d.longitude},lightblue1"></div>`
            : `<div class="thumb"><i class="fas fa-map" style="color:#bbb; font-size:26px"></i></div>`;
        const viewBtn =
          d.latitude && d.longitude
            ? `<a class="btn-ghost" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=${d.latitude}&mlon=${d.longitude}#map=15/${d.latitude}/${d.longitude}"><i class="fas fa-location-arrow"></i> Ouvrir carte</a>`
            : "";
        const mainClass = d.is_main ? " main-depot" : "";
        return `
        <div class="card card-product${mainClass}">
          ${thumb}
          <div class="card-body">
            <div class="card-title">${d.name} <span class="muted">(${
          d.code || ""
        })</span> ${main}</div>
            ${manager}
            ${phone}
            ${addr}
          </div>
          <div class="card-actions">
            <a class="btn" href="${routeBase}/depots/edit?id=${
          d.id
        }"><i class="fas fa-pen"></i> Modifier</a>
            ${mainBtn}
            ${viewBtn}
          </div>
        </div>`;
      })
      .join("");
  }

  function applyFilter() {
    const s = document.getElementById("depots-search");
    const q = (s && s.value ? s.value : "").trim().toLowerCase();
    if (!q) {
      render(stateDepots);
      return;
    }
    const filtered = stateDepots.filter((d) => {
      const text = [
        d.name || "",
        d.code || "",
        d.manager_name || "",
        d.address || "",
      ]
        .join(" ")
        .toLowerCase();
      return text.indexOf(q) !== -1;
    });
    render(filtered);
  }

  async function setMainDepot(id) {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/depots/" + id;
    let r = await fetch(url, {
      method: "PATCH",
      headers: { "Content-Type": "application/json", ...authHeaders(token) },
      body: JSON.stringify({ is_main: 1 }),
    });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      r = await fetch(url, {
        method: "PATCH",
        headers: { "Content-Type": "application/json", ...authHeaders(token) },
        body: JSON.stringify({ is_main: 1 }),
      });
    }
    if (!r.ok) {
      window.showToast && window.showToast("error", "Echec de la mise à jour");
      return false;
    }
    stateDepots = stateDepots.map((d) => ({
      ...d,
      is_main: d.id === id ? 1 : 0,
    }));
    render(stateDepots);
    window.showToast &&
      window.showToast("success", "Dépôt défini comme principal");
    return true;
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/depots";
    if (token) url = withApiToken(url, token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/depots";
      if (token) url = withApiToken(url, token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const data = await r.json();
    stateDepots = Array.isArray(data) ? data : [];
    render(stateDepots);
    const s = document.getElementById("depots-search");
    if (s && !s._wired) {
      s._wired = true;
      s.addEventListener("input", applyFilter);
    }
    const grid = document.getElementById("depots-grid");
    if (grid && !grid._wired) {
      grid._wired = true;
      grid.addEventListener("click", async (e) => {
        const btn = e.target.closest(".btn-set-main");
        if (!btn) return;
        const id = parseInt(btn.getAttribute("data-id"), 10);
        if (!id) return;
        btn.disabled = true;
        await setMainDepot(id);
        btn.disabled = false;
      });
    }
  }

  load();
})();
