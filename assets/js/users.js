(function () {
  async function load() {
    const token = localStorage.getItem("api_token") || "";
    const r = await fetch("/api/v1/users", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (!r.ok) return;
    const data = await r.json();
    const tb = document.querySelector("#users-table tbody");
    tb.innerHTML = data
      .map(
        (u) =>
          `<tr><td>${u.name}</td><td>${u.email}</td><td>${u.role}</td><td>${
            u.depot_id || ""
          }</td></tr>`
      )
      .join("");
  }
  document.getElementById("user-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const form = e.target;
    const payload = Object.fromEntries(new FormData(form).entries());
    const token = localStorage.getItem("api_token") || "";
    const r = await fetch("/api/v1/users", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(token ? { Authorization: "Bearer " + token } : {}),
      },
      body: JSON.stringify(payload),
    });
    if (r.ok) {
      form.reset();
      load();
    }
  });
  load();
})();
