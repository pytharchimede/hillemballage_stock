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
  async function load() {
    const holder = document.getElementById("product-view");
    if (!holder) return;
    const id = holder.getAttribute("data-id") || "0";
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = apiUrl("/api/v1/products/" + id);
    if (token)
      url +=
        (url.indexOf("?") === -1 ? "?" : "&") +
        "api_token=" +
        encodeURIComponent(token);
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
              apiUrl("/api/v1/products/" + id) +
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
      holder.innerHTML = '<div class="muted">Produit introuvable.</div>';
      return;
    }
    const p = await r.json();
    const src = resolveImg(p.image_path);
    const img = p.image_path
      ? `<img src="${src}" alt="${p.name}" style="max-width:240px;border-radius:8px">`
      : "";
    const inactive =
      typeof p.active !== "undefined" && String(p.active) === "0"
        ? '<span class="badge-inactive">Inactif</span>'
        : "";
    holder.innerHTML = `
      <div style="display:flex; gap:1.2rem; align-items:flex-start; flex-wrap:wrap">
        <div>${img}</div>
        <div>
          <div class="title" style="font-size:1.1rem;font-weight:600">${
            p.name
          } ${inactive}</div>
          <div class="sku muted">SKU: ${p.sku}</div>
          <div class="price" style="margin:.4rem 0 1rem">${
            p.unit_price
          } FCFA</div>
          ${
            p.description
              ? `<div style="max-width:560px">${p.description}</div>`
              : ""
          }
          <div style="margin-top:1rem; display:flex; gap:.5rem">
            <a class="btn" href="${apiUrl(
              "/products/edit?id=" + p.id
            )}"><i class="fa fa-pencil"></i> Modifier</a>
            <a class="btn secondary" href="${apiUrl(
              "/products"
            )}"><i class="fa fa-arrow-left"></i> Retour</a>
          </div>
        </div>
      </div>
    `;
  }
  load();
})();
