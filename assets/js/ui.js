(function () {
  if (window.showToast) return;
  function ensureContainer() {
    var el = document.querySelector(".toast-container");
    if (!el) {
      el = document.createElement("div");
      el.className = "toast-container";
      document.body.appendChild(el);
    }
    return el;
  }
  function showToast(type, message, timeout) {
    try {
      var c = ensureContainer();
      var t = document.createElement("div");
      var cls = "toast";
      if (type === "success") cls += " toast-success";
      else if (type === "error") cls += " toast-error";
      else cls += " toast-info";
      t.className = cls;
      t.innerHTML = "<span>" + (message || "") + "</span>";
      c.appendChild(t);
      var to = setTimeout(function () {
        try {
          c.removeChild(t);
        } catch (e) {}
      }, Math.max(1800, timeout || 2600));
      t.addEventListener("click", function () {
        clearTimeout(to);
        try {
          c.removeChild(t);
        } catch (e) {}
      });
    } catch (e) {}
  }
  window.showToast = showToast;
})();
