"use strict";
(function () {
  const routeBase = window.ROUTE_BASE || "";
  const el = document.getElementById("map");
  if (!el) return;
  const canEdit = el.dataset.canEdit === "1";
  let map;
  let layer;

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
    renderMarkers(data);
  }

  document.addEventListener("DOMContentLoaded", function () {
    initMap();
    load();
  });
})();
