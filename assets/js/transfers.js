(function () {
  const form = document.getElementById("transfer-form");
  if (!form) return;
  const token = localStorage.getItem("api_token") || "";
  const headers = token ? { Authorization: "Bearer " + token } : {};
  const msg = document.getElementById("transfer-msg");

  async function loadDepots() {
    const r = await fetch("/api/v1/depots", { headers });
    if (!r.ok) return [];
    return await r.json();
  }
  async function loadProducts() {
    const r = await fetch("/api/v1/products", { headers });
    if (!r.ok) return [];
    return await r.json();
  }
  function fillSelect(sel, items, valueKey, labelKey) {
    sel.innerHTML = items
      .map((i) => `<option value="${i[valueKey]}">${i[labelKey]}</option>`)
      .join("");
  }

  async function init() {
    const [depots, products] = await Promise.all([
      loadDepots(),
      loadProducts(),
    ]);
    fillSelect(document.getElementById("from_depot"), depots, "id", "name");
    fillSelect(document.getElementById("to_depot"), depots, "id", "name");
    fillSelect(document.getElementById("product_id"), products, "id", "name");
  }

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    msg.textContent = "";
    const payload = {
      from_depot_id: parseInt(form.from_depot_id.value, 10),
      to_depot_id: parseInt(form.to_depot_id.value, 10),
      product_id: parseInt(form.product_id.value, 10),
      quantity: parseInt(form.quantity.value, 10),
    };
    const r = await fetch("/api/v1/stock/transfer", {
      method: "POST",
      headers: Object.assign({ "Content-Type": "application/json" }, headers),
      body: JSON.stringify(payload),
    });
    if (r.ok) {
      msg.className = "alert alert-success";
      msg.textContent = "Transfert effectué avec succès.";
      form.reset();
    } else {
      try {
        const err = await r.json();
        msg.className = "alert alert-error";
        msg.textContent = "Erreur: " + (err.error || r.status);
      } catch (_) {
        msg.className = "alert alert-error";
        msg.textContent = "Erreur lors du transfert.";
      }
    }
  });

  init();
})();
