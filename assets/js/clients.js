(function () {
  const routeBase = window.ROUTE_BASE || "";

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

  async function load() {
    const grid = document.getElementById("clients-grid");
    const empty = document.getElementById("clients-empty");
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl("/api/v1/clients");
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
              apiUrl("/api/v1/clients") +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              headers: { Authorization: "Bearer " + token },
            });
          }
        }
      } catch (_) {}
    }
    if (!r.ok) {
      if (grid) {
        grid.innerHTML = "";
      }
      if (empty) {
        empty.style.display = "block";
      }
      return;
    }
    const data = await r.json();

    if (grid) {
      if (!data || data.length === 0) {
        grid.innerHTML = "";
        if (empty) empty.style.display = "block";
      } else {
        if (empty) empty.style.display = "none";
        const cards = data
          .map(function (c) {
            const photo = c.photo_path
              ? `<img src="${resolveImg(c.photo_path)}" alt="${escapeHtml(
                  c.name || "Client"
                )}" class="avatar">`
              : `<div class="avatar avatar-fallback"><i class="fa fa-user"></i></div>`;
            const tel = c.phone ? String(c.phone) : "";
            const telHref = tel ? `tel:${tel.replace(/\s+/g, "")}` : "#";
            const balance =
              typeof c.balance !== "undefined"
                ? parseInt(c.balance, 10) || 0
                : 0;
            const balFmt =
              new Intl.NumberFormat().format(Math.abs(balance)) + " FCFA";
            const balLabel =
              balance === 0
                ? '<span class="muted">Solde: 0 FCFA</span>'
                : balance > 0
                ? `<span style="color:#b50000;font-weight:700">Solde dû: ${balFmt}</span>`
                : `<span style="color:#1a7f37;font-weight:700">Avoir: ${balFmt}</span>`;
            return `
            <div class="card-client" data-id="${c.id}">
              <div class="cl-header">${photo}</div>
              <div class="cl-body">
                <div class="cl-name">${escapeHtml(c.name || "Client")}</div>
                <div class="cl-phone">${
                  tel
                    ? `<i class='fa fa-phone'></i> ${escapeHtml(tel)}`
                    : '<span class="muted">Téléphone non renseigné</span>'
                }</div>
                <div class="cl-balance">${balLabel}</div>
              </div>
              <div class="cl-actions">
                <a class="btn secondary" ${
                  tel ? "" : 'disabled aria-disabled="true"'
                } href="${telHref}" title="Appeler"><i class="fa fa-phone"></i></a>
                <a class="btn" href="${routeBase}/clients/edit?id=${c.id}" title="Modifier"><i class="fa fa-pencil"></i></a>
              </div>
            </div>`;
          })
          .join("");
        grid.innerHTML = cards;
      }
    }

    const tb = document.querySelector("#clients-table tbody");
    if (tb) {
      tb.innerHTML = (data || [])
        .map(function (c) {
          return `<tr><td>${
            c.photo_path
              ? `<img src='${resolveImg(
                  c.photo_path
                )}' style='height:40px;border-radius:50%'>`
              : ""
          }</td><td>${escapeHtml(c.name || "")}</td><td>${escapeHtml(c.phone || "")}</td></tr>`;
        })
        .join("");
    }
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  load();
})();
