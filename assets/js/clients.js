(function () {
  async function load() {
    const token = localStorage.getItem("api_token") || "";
    const r = await fetch("/api/v1/clients", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (!r.ok) return;
    const data = await r.json();
    const tb = document.querySelector("#clients-table tbody");
    tb.innerHTML = data
      .map(
        (c) =>
          `<tr><td>${
            c.photo_path
              ? `<img src='${c.photo_path}' style='height:40px;border-radius:50%'>`
              : ""
          }</td><td>${c.name}</td><td>${c.phone || ""}</td></tr>`
      )
      .join("");
  }
  document
    .getElementById("client-form")
    .addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const token = localStorage.getItem("api_token") || "";
      const r = await fetch("/api/v1/clients", {
        method: "POST",
        headers: token ? { Authorization: "Bearer " + token } : {},
        body: fd,
      });
      if (r.ok) {
        e.target.reset();
        load();
      }
    });
  load();
})();
