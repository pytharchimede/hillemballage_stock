(() => {
  function getCookie(name) {
    const parts = ("; " + document.cookie).split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }
  function authHeaders() {
    const token = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    return token ? { Authorization: "Bearer " + token } : {};
  }

  const elClient = document.getElementById("rc-client");
  const elLoad = document.getElementById("rc-load");
  const elSales = document.getElementById("rc-sales");

  async function loadClients() {
    try {
      const r = await fetch("/api/v1/clients", { headers: authHeaders() });
      if (!r.ok) throw new Error("clients");
      const rows = await r.json();
      elClient.innerHTML = "";
      rows.forEach((c) => {
        const o = document.createElement("option");
        o.value = c.id;
        o.textContent = `${c.name} (#${c.id}) — solde ${c.balance || 0}`;
        elClient.appendChild(o);
      });
    } catch (_) {
      elClient.innerHTML = '<option value="">Aucun</option>';
    }
  }

  async function loadSales(clientId) {
    try {
      const r = await fetch(`/api/v1/sales?client_id=${clientId}`, {
        headers: authHeaders(),
      });
      if (!r.ok) throw new Error("sales");
      const rows = await r.json();
      const unpaid = rows.filter((s) => s.total_amount - s.amount_paid > 0);
      if (!unpaid.length) {
        elSales.innerHTML =
          '<div class="muted">Aucune créance pour ce client.</div>';
        return;
      }
      let h =
        '<table class="excel"><thead><tr><th>#</th><th>Date</th><th>Total</th><th>Payé</th><th>Reste</th><th>Paiement</th><th>Méthode</th><th></th></tr></thead><tbody>';
      unpaid.forEach((s) => {
        const rest = s.total_amount - s.amount_paid;
        h +=
          `<tr><td>${s.id}</td><td>${(s.sold_at || "")
            .toString()
            .slice(0, 19)}</td><td>${s.total_amount}</td><td>${
            s.amount_paid
          }</td><td>${rest}</td>` +
          `<td><input type="number" min="1" max="${rest}" data-id="${s.id}" class="form-control compact pay-amt" style="width:120px"></td>` +
          `<td><input type="text" data-id="${s.id}" class="form-control compact pay-met" style="width:120px" placeholder="(optionnel)"></td>` +
          `<td><button class="btn small do-pay" data-id="${s.id}">Enregistrer</button></td></tr>`;
      });
      h += "</tbody></table>";
      elSales.innerHTML = h;
      elSales
        .querySelectorAll("button.do-pay")
        .forEach((b) => b.addEventListener("click", doPay));
    } catch (_) {
      elSales.textContent = "Erreur de chargement";
    }
  }

  async function doPay(e) {
    const id = parseInt(e.currentTarget.getAttribute("data-id"), 10);
    const amt =
      parseInt(
        elSales.querySelector(`input.pay-amt[data-id="${id}"]`).value,
        10
      ) || 0;
    const met =
      elSales.querySelector(`input.pay-met[data-id="${id}"]`).value || null;
    if (!amt || amt <= 0) return;
    try {
      const r = await fetch(`/api/v1/sales/${id}/payments`, {
        method: "POST",
        headers: { "Content-Type": "application/json", ...authHeaders() },
        body: JSON.stringify({ amount: amt, method: met }),
      });
      if (!r.ok) throw new Error(await r.text());
      await r.json();
      // refresh current client
      const cid = parseInt(elClient.value, 10);
      if (cid) loadSales(cid);
    } catch (_) {
      alert("Erreur enregistrement paiement");
    }
  }

  if (elLoad)
    elLoad.addEventListener("click", () => {
      const cid = parseInt(elClient.value, 10);
      if (cid) loadSales(cid);
    });

  loadClients();
})();
