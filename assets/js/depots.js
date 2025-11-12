(function () {
  async function load() {
    const token = localStorage.getItem("api_token") || "";
    const r = await fetch("/api/v1/depots", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (!r.ok) return;
    const data = await r.json();
    const tb = document.querySelector("#depots-table tbody");
    tb.innerHTML = data
      .map(
        (d) =>
          `<tr><td>${d.name}</td><td>${d.code}</td><td>${
            d.latitude ?? ""
          }</td><td>${d.longitude ?? ""}</td></tr>`
      )
      .join("");
  }
  document
    .getElementById("depot-form")
    .addEventListener("submit", async (e) => {
      e.preventDefault();
      const form = e.target;
      const payload = Object.fromEntries(new FormData(form).entries());
      const token = localStorage.getItem("api_token") || "";
      const r = await fetch("/api/v1/depots", {
        method: "POST",
        headers: { ...(token ? { Authorization: "Bearer " + token } : {}) },
        body: new URLSearchParams(payload),
      });
      if (r.ok) {
        form.reset();
        load();
      }
    });
  load();
})();
