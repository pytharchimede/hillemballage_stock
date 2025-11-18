(function () {
  // Lire APP_BASE depuis meta
  var meta = document.querySelector('meta[name="app-base"]');
  var APP_BASE = meta ? meta.getAttribute("content") : "";
  if (!window.APP_BASE) window.APP_BASE = APP_BASE || "";

  // Toggle nav hamburger
  try {
    var t = document.getElementById("navToggle");
    var n = document.getElementById("mainNav");
    if (t && n) {
      t.addEventListener("click", function () {
        n.classList.toggle("open");
      });
    }
  } catch (e) {}

  // Sync token from cookie to localStorage (no-op if already set)
  try {
    var name = "api_token";
    var parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) {
      var token = parts.pop().split(";").shift();
      if (token && !localStorage.getItem("api_token")) {
        localStorage.setItem("api_token", token);
      }
    }
  } catch (e) {}

  // Afficher uniquement les liens autorisés
  try {
    var token = localStorage.getItem("api_token") || "";
    fetch((APP_BASE || "") + "/api/v1/auth/me", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    })
      .then(function (r) {
        return r.ok ? r.json() : null;
      })
      .then(function (d) {
        if (!d || !d.permissions) return;
        var perms = d.permissions || {};
        document
          .querySelectorAll("#mainNav a[data-entity]")
          .forEach(function (a) {
            var ent = a.getAttribute("data-entity");
            var act = a.getAttribute("data-action") || "view";
            var ok = false;
            if (perms["*"] && perms["*"][act]) ok = true;
            else if (perms[ent] && perms[ent][act]) ok = true;
            if (ok) a.style.display = "inline-block";
            else a.style.display = "none";
          });
      })
      .catch(function () {});
  } catch (e) {}
})();
