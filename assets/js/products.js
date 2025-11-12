(function () {
  async function load() {
    const token = localStorage.getItem("api_token") || "";
    const r = await fetch("/api/v1/products", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (!r.ok) return;
    const data = await r.json();
    const tb = document.querySelector("#products-table tbody");
    const base = window.ROUTE_BASE || "";
    tb.innerHTML = data
      .map(
        (p) =>
          `<tr>
            <td>${
              p.image_path
                ? `<img src='${p.image_path}' style='height:40px'>`
                : ""
            }</td>
            <td>${p.name}</td>
            <td>${p.sku}</td>
            <td>${p.unit_price}</td>
            <td><a class="btn" href="${base}/products/edit?id=${
            p.id
          }">Modifier</a></td>
          </tr>`
      )
      .join("");
  }
  // If an inline form exists (legacy), keep supporting it, otherwise skip
  const form = document.getElementById("product-form");
  if (form) {
    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const token = localStorage.getItem("api_token") || "";
      const r = await fetch("/api/v1/products", {
        method: "POST",
        headers: token ? { Authorization: "Bearer " + token } : {},
        body: fd,
      });
      if (r.ok) {
        e.target.reset();
        load();
      }
    });
  }
  load();
})();
