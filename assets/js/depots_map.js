"use strict";
(function () {
  const routeBase = window.ROUTE_BASE || "";
  const el = document.getElementById("map");
  if (!el) return;
  const canEdit = el.dataset.canEdit === "1";
  let map;
  let layer;
  let allDepots = [];
  let filterCenter = null; // {lat, lon}
  let centerMarker = null;

  function readCookieToken() {
    try {
      const name = "api_token=";
      return (
        document.cookie
          .split(";")
          .map((c) => c.trim())
          .find((c) => c.indexOf(name) === 0)
          ?.substring(name.length) || ""
      );
    } catch (e) {
      return "";
    }
  }
  async function refreshSessionToken() {
    try {
      const r = await fetch(routeBase + "/api/v1/auth/session-token");
      if (r.ok) {
        const j = await r.json();
        if (j && j.token) {
          localStorage.setItem("api_token", j.token);
          document.cookie = "api_token=" + j.token + "; path=/";
          return j.token;
        }
      }
    } catch (e) {}
    return null;
  }
  function authHeaders(token) {
    return token ? { Authorization: "Bearer " + token } : {};
  }
  function withApiToken(url, token) {
    return (
      url +
      (url.indexOf("?") === -1 ? "?" : "&") +
      "api_token=" +
      encodeURIComponent(token || "")
    );
  }

  function escapeHtml(str) {
    return (str || "").replace(
      /[&<>"']/g,
      (s) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        }[s])
    );
  }
  function iconDepot(isMain) {
    const color = isMain ? "#f2c200" : "#666";
    const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='28' height='40' viewBox='0 0 28 40'>
    <path fill='${color}' stroke='#222' stroke-width='1' d='M14 0C6.5 0 0 6 0 13.5 0 24 14 40 14 40S28 24 28 13.5C28 6 21.5 0 14 0z'/>
    <circle cx='14' cy='14' r='6' fill='white' stroke='#222' stroke-width='1'/>
  </svg>`;
    return L.icon({
      iconUrl: "data:image/svg+xml;base64," + btoa(svg),
      iconSize: [28, 40],
      iconAnchor: [14, 40],
      tooltipAnchor: [0, -40],
    });
  }

  function tooltipHtml(d) {
    return `<div class='map-hover'>
      <strong>${escapeHtml(d.name)} <span class='muted'>(${escapeHtml(
      d.code || ""
    )})</span>${
      d.is_main
        ? " <span class='badge-main-depot'><i class='fas fa-star'></i> Principal</span>"
        : ""
    }</strong><br>
      ${
        d.manager_name
          ? `<i class='fas fa-user-tie'></i> ${escapeHtml(d.manager_name)}<br>`
          : ""
      }
      ${
        d.phone ? `<i class='fas fa-phone'></i> ${escapeHtml(d.phone)}<br>` : ""
      }
      ${
        d.address
          ? `<i class='fas fa-location-dot'></i> ${escapeHtml(d.address)}`
          : ""
      }
      <div style='margin-top:6px'>
        <a class='btn-ghost' href='${routeBase}/depots/edit?id=${
      d.id
    }'><i class='fas fa-pen'></i> Modifier</a>
        ${
          d.latitude && d.longitude
            ? `<a class='btn-ghost' target='_blank' href='https://www.openstreetmap.org/?mlat=${d.latitude}&mlon=${d.longitude}#map=15/${d.latitude}/${d.longitude}'><i class='fas fa-location-arrow'></i> OSM</a>`
            : ""
        }
      </div>
    </div>`;
  }

  function initMap() {
    map = L.map("map");
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
    }).addTo(map);
    layer = L.layerGroup().addTo(map);
  }

  function renderMarkers(list) {
    layer.clearLayers();
    const bounds = [];
    list.forEach((d) => {
      if (!d.latitude || !d.longitude) return;
      const lat = parseFloat(d.latitude);
      const lon = parseFloat(d.longitude);
      if (!lat || !lon) return;
      const marker = L.marker([lat, lon], {
        icon: iconDepot(!!d.is_main),
        draggable: canEdit,
      });
      marker.bindTooltip(tooltipHtml(d), {
        direction: "top",
        offset: [0, -42],
        opacity: 0.95,
        className: "depot-hover-tip",
        permanent: false,
      });
      if (canEdit) {
        marker.on("dragend", async () => {
          const { lat: newLat, lng: newLon } = marker.getLatLng();
          let token =
            localStorage.getItem("api_token") || readCookieToken() || "";
          let url = routeBase + "/api/v1/depots/" + d.id + "/geo";
          let r = await fetch(url, {
            method: "PATCH",
            headers: {
              "Content-Type": "application/json",
              ...authHeaders(token),
            },
            body: JSON.stringify({ latitude: newLat, longitude: newLon }),
          });
          if (r.status === 401) {
            token = (await refreshSessionToken()) || token;
            r = await fetch(url, {
              method: "PATCH",
              headers: {
                "Content-Type": "application/json",
                ...authHeaders(token),
              },
              body: JSON.stringify({ latitude: newLat, longitude: newLon }),
            });
          }
          if (!r.ok) {
            window.showToast &&
              window.showToast("error", "Echec maj géolocalisation");
          } else {
            window.showToast &&
              window.showToast("success", "Position mise à jour");
          }
        });
      }
      layer.addLayer(marker);
      bounds.push([lat, lon]);
    });
    if (bounds.length) {
      map.fitBounds(bounds, { padding: [40, 40] });
    } else {
      map.setView([5.35, -4.02], 6);
    }
    // Show center radius circle if radius filter active
    const radiusKm = parseFloat(
      document.getElementById("map-radius")?.value || "0"
    );
    if (radiusKm > 0 && filterCenter) {
      // remove previous center marker and circle layer group if any
      if (centerMarker) {
        centerMarker.remove();
      }
      centerMarker = L.circle([filterCenter.lat, filterCenter.lon], {
        radius: radiusKm * 1000,
        color: "#f2c200",
        weight: 1,
        fillOpacity: 0.05,
      }).addTo(map);
    } else if (centerMarker) {
      centerMarker.remove();
      centerMarker = null;
    }
  }

  async function load() {
    let token = localStorage.getItem("api_token") || readCookieToken() || "";
    let url = routeBase + "/api/v1/depots";
    if (token) url = withApiToken(url, token);
    let r = await fetch(url, { headers: authHeaders(token) });
    if (r.status === 401) {
      token = (await refreshSessionToken()) || token;
      url = routeBase + "/api/v1/depots";
      if (token) url = withApiToken(url, token);
      r = await fetch(url, { headers: authHeaders(token) });
    }
    if (!r.ok) return;
    const data = await r.json();
    if (!Array.isArray(data)) return;
    allDepots = data;
    renderMarkers(allDepots);
    wireFilters();
  }

  function haversineKm(lat1, lon1, lat2, lon2) {
    function toRad(v) {
      return (v * Math.PI) / 180;
    }
    const R = 6371;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a =
      Math.sin(dLat / 2) ** 2 +
      Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
  }

  function applyFilter() {
    const qEl = document.getElementById("map-search");
    const radiusEl = document.getElementById("map-radius");
    const q = ((qEl && qEl.value) || "").trim().toLowerCase();
    const radiusKm =
      parseFloat(radiusEl && radiusEl.value ? radiusEl.value : "0") || 0;
    let center = filterCenter;
    if (radiusKm > 0 && !center) {
      const c = map.getCenter();
      center = { lat: c.lat, lon: c.lng };
      filterCenter = center;
    }
    const filtered = allDepots.filter((d) => {
      // Text filter
      if (q) {
        const text = [
          d.name || "",
          d.code || "",
          d.manager_name || "",
          d.address || "",
        ]
          .join(" ")
          .toLowerCase();
        if (text.indexOf(q) === -1) return false;
      }
      // Radius filter
      if (radiusKm > 0 && center) {
        if (!d.latitude || !d.longitude) return false;
        const dist = haversineKm(
          center.lat,
          center.lon,
          parseFloat(d.latitude),
          parseFloat(d.longitude)
        );
        if (dist > radiusKm) return false;
      }
      return true;
    });
    renderMarkers(filtered);
    // Toast if none
    if (filtered.length === 0 && window.showToast) {
      window.showToast("info", "Aucun dépôt pour ce filtre");
    }
  }

  function wireFilters() {
    const qEl = document.getElementById("map-search");
    const radiusEl = document.getElementById("map-radius");
    const geoBtn = document.getElementById("map-geo-btn");
    const resetBtn = document.getElementById("map-reset-btn");
    if (qEl && !qEl._wired) {
      qEl._wired = true;
      qEl.addEventListener("input", applyFilter);
    }
    if (radiusEl && !radiusEl._wired) {
      radiusEl._wired = true;
      radiusEl.addEventListener("input", applyFilter);
    }
    if (geoBtn && !geoBtn._wired) {
      geoBtn._wired = true;
      geoBtn.addEventListener("click", () => {
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(
            (pos) => {
              filterCenter = {
                lat: pos.coords.latitude,
                lon: pos.coords.longitude,
              };
              map.setView([filterCenter.lat, filterCenter.lon], 12);
              applyFilter();
              window.showToast &&
                window.showToast("success", "Centre géolocalisé fixé");
            },
            (err) => {
              window.showToast &&
                window.showToast("error", "Géolocalisation refusée");
            },
            { enableHighAccuracy: true, timeout: 8000 }
          );
        } else {
          window.showToast &&
            window.showToast("error", "Géolocalisation non supportée");
        }
      });
    }
    if (resetBtn && !resetBtn._wired) {
      resetBtn._wired = true;
      resetBtn.addEventListener("click", () => {
        filterCenter = null;
        if (qEl) qEl.value = "";
        if (radiusEl) radiusEl.value = "0";
        renderMarkers(allDepots);
        window.showToast && window.showToast("info", "Filtres réinitialisés");
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    initMap();
    load();
  });
})();
