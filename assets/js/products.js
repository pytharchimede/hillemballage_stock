(function () {
  const routeBase = window.ROUTE_BASE || "";

  // Fonction API globale corrigée
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

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl("/api/v1/products");
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
              apiUrl("/api/v1/products") +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              headers: { Authorization: "Bearer " + token },
            });
          }
        }
      } catch (_) {}
    }
    if (!r.ok) return;
    const data = await r.json();
    const grid = document.getElementById("products-grid");
    const empty = document.getElementById("products-empty");
    if (!data || data.length === 0) {
      if (grid) grid.innerHTML = "";
      if (empty) empty.style.display = "block";
      return;
    }
    if (empty) empty.style.display = "none";
    const cards = data
      .map(function (p) {
        const img = p.image_path
          ? `<img src="${resolveImg(p.image_path)}" alt="${p.name}">`
          : `<i class="fa fa-image" style="font-size:42px;color:#bbb"></i>`;
        const inactive =
          typeof p.active !== "undefined" && String(p.active) === "0";
        const badge = inactive
          ? `<span class="badge-inactive">Inactif</span>`
          : "";
        const toggleTitle = inactive ? "Activer" : "Désactiver";
        const toggleIcon = inactive ? "fa-toggle-on" : "fa-toggle-off";
        return `
        <div class="card-product" data-id="${
          p.id
        }" data-active="${inactive ? "0" : "1"}">
          <div class="thumb">${img}</div>
          <div class="body">
            <div class="title">${p.name} ${badge}</div>
            <div class="sku">SKU: ${p.sku}</div>
            <div class="price">${p.unit_price} FCFA</div>
          </div>
          <div class="actions">
            <div class="left">
              <a class="btn secondary" title="Fiche" href="${apiUrl(
                "/products/view?id=" + p.id
              )}"><i class="fa fa-eye"></i></a>
            </div>
            <div class="right" style="display:flex;gap:.4rem">
              <button class="btn tertiary btn-toggle" title="${toggleTitle}"><i class="fa ${toggleIcon}"></i></button>
              <a class="btn" title="Modifier" href="${apiUrl(
                "/products/edit?id=" + p.id
              )}"><i class="fa fa-pencil"></i></a>
            </div>
          </div>
        </div>`;
      })
      .join("");
    if (grid) grid.innerHTML = cards;
  }

  // Support de l'ancien formulaire inline (optionnel)
  const form = document.getElementById("product-form");

  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const fd = new FormData(e.target);
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = apiUrl("/api/v1/products");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      let r = await fetch(url, {
        method: "POST",
        headers: token ? { Authorization: "Bearer " + token } : {},
        body: fd,
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
                apiUrl("/api/v1/products") +
                "?api_token=" +
                encodeURIComponent(token);
              r = await fetch(url, {
                method: "POST",
                headers: { Authorization: "Bearer " + token },
                body: fd,
              });
            }
          }
        } catch (_) {}
      }

      if (r.ok) {
        e.target.reset();
        load();
      }
    });
  }

  load();

  // Toggle activation via event delegation
  document.addEventListener("click", async function (ev) {
    const btn = ev.target.closest(".btn-toggle");
    if (!btn) return;
    const card = btn.closest(".card-product");
    if (!card) return;
    ev.preventDefault();
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    const id = card.getAttribute("data-id");
    const current = card.getAttribute("data-active") === "1";
    const next = current ? 0 : 1;
    let url = apiUrl("/api/v1/products/" + id);
    if (token)
      url +=
        (url.indexOf("?") === -1 ? "?" : "&") +
        "api_token=" +
        encodeURIComponent(token);
    let r = await fetch(url, {
      method: "PATCH",
      headers: Object.assign(
        { "Content-Type": "application/json" },
        token ? { Authorization: "Bearer " + token } : {}
      ),
      body: JSON.stringify({ active: next }),
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
              apiUrl("/api/v1/products/" + id) +
              "?api_token=" +
              encodeURIComponent(token);
            r = await fetch(url, {
              method: "PATCH",
              headers: {
                "Content-Type": "application/json",
                Authorization: "Bearer " + token,
              },
              body: JSON.stringify({ active: next }),
            });
          }
        }
      } catch (_) {}
    }
    if (r.ok) {
      load();
    }
  });

  // Admin: normalize image paths
  const fixBtn = document.getElementById("fix-img-btn");
  const fixStatus = document.getElementById("fix-img-status");
  if (fixBtn) {
    fixBtn.addEventListener("click", async function () {
      if (fixStatus) {
        fixStatus.style.display = "inline";
        fixStatus.textContent = "Traitement en cours…";
      }
      let token = localStorage.getItem("api_token") || readCookieToken() || "";
      let url = apiUrl("/api/v1/admin/fix-product-images");
      if (token) url += "?api_token=" + encodeURIComponent(token);
      let r = await fetch(url, {
        method: "POST",
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
                apiUrl("/api/v1/admin/fix-product-images") +
                "?api_token=" +
                encodeURIComponent(token);
              r = await fetch(url, {
                method: "POST",
                headers: { Authorization: "Bearer " + token },
              });
            }
          }
        } catch (_) {}
      }
      if (r.status === 403) {
        if (fixStatus)
          fixStatus.textContent = "Action non autorisée (admin requis).";
        return;
      }
      if (!r.ok) {
        if (fixStatus) fixStatus.textContent = "Echec de la normalisation.";
        return;
      }
      const out = await r.json();
      if (fixStatus)
        fixStatus.textContent = `Normalisés: ${out.normalized || 0}`;
      load();
    });
  }
})();
