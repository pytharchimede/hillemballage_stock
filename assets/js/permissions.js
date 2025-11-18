(function () {
  const BASE = window.APP_BASE || "";
  const token = localStorage.getItem("api_token") || "";
  const headers = token
    ? { Authorization: "Bearer " + token, "Content-Type": "application/json" }
    : { "Content-Type": "application/json" };
  const userSelect = document.getElementById("permUserSelect");
  const userSearchWrapper = document.querySelector(
    '.select-search[data-target="permUserSelect"]'
  );
  const userSearchInput = userSearchWrapper
    ? userSearchWrapper.querySelector("input")
    : null;
  const tableBody = document.querySelector("#permTable tbody");
  const statusEl = document.getElementById("permStatus");
  const reloadBtn = document.getElementById("reloadPerm");
  const saveBtn = document.getElementById("savePerm");
  let entities = {};
  let currentExplicit = {};
  let currentEffective = {};
  let currentUserId = null;
  let dirty = {};

  function toast(msg) {
    statusEl.textContent = msg;
    setTimeout(() => {
      if (statusEl.textContent === msg) statusEl.textContent = "";
    }, 4000);
  }

  function fetchJSON(url, opts) {
    return fetch(url, opts).then(async (r) => {
      let data = null;
      try {
        data = await r.json();
      } catch (e) {}
      if (!r.ok) {
        const msg =
          data && data.error
            ? data.error + (data.details ? " (" + data.details + ")" : "")
            : "HTTP " + r.status;
        throw new Error(msg);
      }
      return data;
    });
  }

  function loadEntities() {
    return fetchJSON(BASE + "/api/v1/permissions/entities", { headers });
  }
  // Récupération normalisée de la liste utilisateurs (toujours un tableau)
  async function fetchUsers() {
    const res = await fetchJSON(BASE + "/api/v1/users", { headers }).catch(
      () => []
    );
    if (Array.isArray(res)) return res;
    if (res && Array.isArray(res.users)) return res.users;
    return [];
  }
  let allUsersCache = [];

  // Charge et rend les options utilisateurs
  async function loadUsers() {
    const users = await fetchUsers();
    allUsersCache = users;
    renderUserOptions(users);
    return users;
  }

  function renderUserOptions(list) {
    userSelect.innerHTML = "";
    list.forEach((u) => {
      const opt = document.createElement("option");
      opt.value = u.id;
      opt.textContent = u.name ? `${u.name} (#${u.id})` : `#${u.id}`;
      userSelect.appendChild(opt);
    });
  }

  function filterUsers(term) {
    term = term.trim().toLowerCase();
    if (!term) {
      renderUserOptions(allUsersCache);
      return;
    }
    const filtered = allUsersCache.filter((u) => {
      const label = (u.name ? u.name : "") + " " + u.id;
      return label.toLowerCase().includes(term);
    });
    renderUserOptions(filtered);
  }
  function loadUserPerm(uid) {
    return fetchJSON(BASE + "/api/v1/permissions/user?user_id=" + uid, {
      headers,
    });
  }
  function buildMatrix() {
    tableBody.innerHTML = "";
    Object.keys(entities)
      .sort()
      .forEach((ent) => {
        const actions = entities[ent];
        actions.forEach((act) => {
          const allowed =
            currentEffective[ent] && currentEffective[ent][act] ? true : false;
          const explicit =
            (currentExplicit[ent] && currentExplicit[ent][act]) !== undefined;
          const row = document.createElement("tr");
          row.innerHTML =
            "<td>" +
            ent +
            "</td><td>" +
            act +
            "</td>" +
            '<td><input type="checkbox" data-entity="' +
            ent +
            '" data-action="' +
            act +
            '" ' +
            (allowed ? "checked" : "") +
            " /></td>";
          if (explicit) row.classList.add("explicit");
          tableBody.appendChild(row);
        });
      });
    tableBody.querySelectorAll("input[type=checkbox]").forEach((cb) => {
      cb.addEventListener("change", () => {
        const ent = cb.getAttribute("data-entity");
        const act = cb.getAttribute("data-action");
        if (!dirty[ent]) dirty[ent] = {};
        dirty[ent][act] = cb.checked;
        cb.closest("tr").classList.add("explicit");
      });
    });
  }

  function refresh() {
    if (!currentUserId) {
      toast("Sélectionner un utilisateur");
      return;
    }
    dirty = {};
    loadUserPerm(currentUserId)
      .then((d) => {
        currentExplicit = d.explicit || {};
        currentEffective = d.effective || {};
        buildMatrix();
      })
      .catch((e) => toast("Erreur chargement: " + e.message));
  }

  function save() {
    if (!currentUserId) {
      toast("Utilisateur manquant");
      return;
    }
    const changes = [];
    Object.keys(dirty).forEach((ent) => {
      Object.keys(dirty[ent]).forEach((act) => {
        changes.push({ entity: ent, action: act, allowed: dirty[ent][act] });
      });
    });
    if (!changes.length) {
      toast("Aucun changement");
      return;
    }
    fetchJSON(BASE + "/api/v1/permissions/user", {
      method: "POST",
      headers,
      body: JSON.stringify({ user_id: currentUserId, changes }),
    })
      .then((r) => {
        currentExplicit = r.explicit || {};
        currentEffective = r.effective || {};
        dirty = {};
        buildMatrix();
        toast("Permissions sauvegardées");
      })
      .catch((e) => toast("Erreur sauvegarde: " + e.message));
  }

  async function init() {
    try {
      const [ents, users] = await Promise.all([loadEntities(), loadUsers()]);
      entities = ents.entities || ents || {};
      // Sélectionne le premier utilisateur et charge ses permissions
      if (users.length) {
        currentUserId = users[0].id;
        userSelect.value = String(currentUserId);
        refresh();
      }
    } catch (e) {
      toast("Erreur initialisation: " + e.message);
    }
  }

  userSelect.addEventListener("change", () => {
    currentUserId = parseInt(userSelect.value, 10) || null;
    if (currentUserId) refresh();
  });

  if (userSearchInput) {
    let debounceTimer;
    userSearchInput.addEventListener("input", () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        filterUsers(userSearchInput.value);
      }, 180);
    });
  }
  reloadBtn.addEventListener("click", refresh);
  saveBtn.addEventListener("click", save);
  init();
})();
