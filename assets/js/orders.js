(function () {
  function addLine() {
    const c = document.getElementById("order-items");
    const div = document.createElement("div");
    div.className = "order-item";
    div.innerHTML = `<label>Produit ID<input type="number" name="product_id[]" min="1" required></label>
      <label>Qté<input type="number" name="quantity[]" min="1" required></label>
      <label>Coût unitaire<input type="number" name="unit_cost[]" min="0" required></label>`;
    c.appendChild(div);
  }
  document.getElementById("add-line").addEventListener("click", addLine);

  async function load() {
    const token = localStorage.getItem("api_token") || "";
    const r = await fetch("/api/v1/orders", {
      headers: token ? { Authorization: "Bearer " + token } : {},
    });
    if (!r.ok) return;
    const data = await r.json();
    const tb = document.querySelector("#orders-table tbody");
    tb.innerHTML = data
      .map(
        (o) =>
          `<tr><td>${o.reference}</td><td>${o.supplier || ""}</td><td>${
            o.status
          }</td><td>${o.total_amount}</td><td>${o.ordered_at}</td></tr>`
      )
      .join("");
  }

  document
    .getElementById("order-form")
    .addEventListener("submit", async (e) => {
      e.preventDefault();
      const f = e.target;
      const fd = new FormData(f);
      const productIds = fd.getAll("product_id[]");
      const quantities = fd.getAll("quantity[]").map(Number);
      const unitCosts = fd.getAll("unit_cost[]").map(Number);
      const items = productIds
        .map((pid, i) => ({
          product_id: Number(pid),
          quantity: quantities[i] || 0,
          unit_cost: unitCosts[i] || 0,
        }))
        .filter((x) => x.product_id && x.quantity > 0);
      const payload = {
        reference: f.reference.value || undefined,
        supplier: f.supplier.value || undefined,
        depot_id: Number(f.depot_id.value || 1),
        items,
      };
      const token = localStorage.getItem("api_token") || "";
      const r = await fetch("/api/v1/orders", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          ...(token ? { Authorization: "Bearer " + token } : {}),
        },
        body: JSON.stringify(payload),
      });
      if (r.ok) {
        f.reset();
        document.getElementById("order-items").innerHTML = "";
        addLine();
        load();
      }
    });

  addLine();
  load();
})();
