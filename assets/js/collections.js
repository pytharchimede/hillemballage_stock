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
  const elDepot = document.getElementById("rc-depot");
  const elUser = document.getElementById("rc-user");
  const elFrom = document.getElementById("rc-from");
  const elTo = document.getElementById("rc-to");
  const elExpCsv = document.getElementById("rc-export-csv");
  const elExpPdf = document.getElementById("rc-export-pdf");
  const elScopeHint = document.getElementById("rc-scope-hint");
  const elClientInfo = document.getElementById("rc-client-info");
  const elLedgerCsv = document.getElementById("rc-ledger-csv");
  const elLedgerPdf = document.getElementById("rc-ledger-pdf");

  async function loadClients() {
    try {
      const r = await fetch("/api/v1/clients", { headers: authHeaders() });
      if (!r.ok) throw new Error("clients");
      const rows = await r.json();
      elClient.innerHTML = "";
      rows.forEach((c) => {
        const o = document.createElement("option");
        o.value = c.id;
        const bl = c.balance || 0;
        const lim = c.credit_limit || 0;
        const left =
          lim > 0 ? ` | plafond ${lim}, reste ${Math.max(0, lim - bl)}` : "";
        o.textContent = `${c.name} (#${c.id}) — solde ${bl}${left}`;
        elClient.appendChild(o);
      });
    } catch (_) {
      elClient.innerHTML = '<option value="">Aucun</option>';
    }
  }

  async function loadDepotsAndUsers() {
    // depots: admin -> tous; sinon -> un seul (API renvoie déjà selon scope)
    try {
      const r = await fetch("/api/v1/depots", { headers: authHeaders() });
      const rows = r.ok ? await r.json() : [];
      if (elDepot) {
        elDepot.innerHTML = '<option value="">(tous dépôts)</option>';
        rows.forEach((d) => {
          const o = document.createElement("option");
          o.value = d.id;
          o.textContent = `${d.name} (#${d.id})`;
          elDepot.appendChild(o);
        });
      }
    } catch {}
    await loadUsers();
  }

  async function loadUsers() {
    if (!elUser) return;
    const dep = (elDepot && elDepot.value) || "";
    try {
      const r = await fetch(
        `/api/v1/users/brief?role=livreur${dep ? `&depot_id=${dep}` : ""}`,
        { headers: authHeaders() }
      );
      const rows = r.ok ? await r.json() : [];
      elUser.innerHTML = '<option value="">(tous agents)</option>';
      rows.forEach((u) => {
        const o = document.createElement("option");
        o.value = u.id;
        o.textContent = `${u.name} (#${u.id})`;
        elUser.appendChild(o);
      });
    } catch {}
  }

  async function loadSales(clientId) {
    try {
      // afficher infos client (solde/plafond)
      try {
        const ci = await fetch(`/api/v1/clients/${clientId}`, {
          headers: authHeaders(),
        });
        if (ci.ok) {
          const c = await ci.json();
          const bl = c.balance || 0;
          const lim = c.credit_limit || 0;
          let txt = `Solde: ${bl}`;
          if (lim > 0) {
            const left = lim - bl;
            txt += ` | Plafond: ${lim} | Reste autorisé: ${left}`;
            elClientInfo.style.color = left < 0 ? "#c00" : "";
          } else {
            elClientInfo.style.color = "";
          }
          elClientInfo.textContent = txt;
        }
      } catch {}
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

  function exportReceivables(fmt) {
    const dep =
      elDepot && elDepot.value
        ? `&depot_id=${encodeURIComponent(elDepot.value)}`
        : "";
    const usr =
      elUser && elUser.value
        ? `&user_id=${encodeURIComponent(elUser.value)}`
        : "";
    const f =
      elFrom && elFrom.value ? `&from=${encodeURIComponent(elFrom.value)}` : "";
    const t = elTo && elTo.value ? `&to=${encodeURIComponent(elTo.value)}` : "";
    const token = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    const tk = token ? `&api_token=${encodeURIComponent(token)}` : "";
    const url = `/api/v1/receivables/export?format=${fmt}${dep}${usr}${f}${t}${tk}`;
    window.open(url, "_blank");
  }

  function exportLedger(fmt) {
    const cid = parseInt(elClient && elClient.value, 10);
    if (!cid) return;
    const token = (
      localStorage.getItem("api_token") ||
      getCookie("api_token") ||
      ""
    ).trim();
    const tk = token ? `&api_token=${encodeURIComponent(token)}` : "";
    const url = `/api/v1/clients/${cid}/ledger/export?format=${fmt}${tk}`;
    window.open(url, "_blank");
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

  if (elDepot)
    elDepot.addEventListener("change", () => {
      loadUsers();
    });
  if (elExpCsv)
    elExpCsv.addEventListener("click", () => exportReceivables("csv"));
  if (elExpPdf)
    elExpPdf.addEventListener("click", () => exportReceivables("pdf"));
  if (elLedgerCsv)
    elLedgerCsv.addEventListener("click", () => exportLedger("csv"));
  if (elLedgerPdf)
    elLedgerPdf.addEventListener("click", () => exportLedger("pdf"));

  loadClients();
  loadDepotsAndUsers();
})();
