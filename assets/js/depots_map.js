(() => {
  const el = document.getElementById("map");
  if (!el) return;
  const canEdit = el.dataset.canEdit === "1";

  const map = L.map("map").setView([5.35, -4.02], 12);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap",
  }).addTo(map);

  async function authToken() {
    // Use session-based fetch by reading from localStorage token if set for demo
    return localStorage.getItem("api_token") || "";
  }

  async function loadDepots() {
    const token = await authToken();
    const res = await fetch("/api/v1/depots", {
      headers: { Authorization: token ? "Bearer " + token : "" },
    });
    if (!res.ok) {
      console.warn("Need token for depots");
      return;
    }
    const depots = await res.json();
    depots
      .filter((d) => d.latitude && d.longitude)
      .forEach((d) => addMarker(d));
  }

  function addMarker(d) {
    const m = L.marker([d.latitude, d.longitude], { draggable: canEdit }).addTo(
      map
    );
    m.bindPopup(`<b>${d.name}</b><br>Code: ${d.code || ""}`);
    if (canEdit) {
      m.on("dragend", async () => {
        const { lat, lng } = m.getLatLng();
        const token = await authToken();
        const r = await fetch(`/api/v1/depots/${d.id}/geo`, {
          method: "PATCH",
          headers: {
            "Content-Type": "application/json",
            Authorization: token ? "Bearer " + token : "",
          },
          body: JSON.stringify({ latitude: lat, longitude: lng }),
        });
        if (!r.ok) alert("Erreur de mise à jour");
      });
    }
  }

  loadDepots();
})();
