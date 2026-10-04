// ==========================================
// LiveCameraLinks Build 0.1.7
// map.js - robust SVG circle markers
// ==========================================

const LIVE_CAMERA_REGIONS = [
  { name: "東京", lat: 35.681236, lng: 139.767125, url: "./tokyo/" },
  { name: "箱根", lat: 35.2324, lng: 139.1069, url: "./kanagawa/hakone/" },
  { name: "京都", lat: 35.0116, lng: 135.7681, url: "./kyoto/kyoto/" },
  { name: "河口湖", lat: 35.5171, lng: 138.7518, url: "./yamanashi/kawaguchiko/" },
  { name: "富士山", lat: 35.3606, lng: 138.7274, url: "./yamanashi/fujisan/" },
  { name: "白川郷", lat: 36.2606, lng: 136.9062, url: "./gifu/shirakawago/" },
  { name: "松本", lat: 36.2380, lng: 137.9720, url: "./nagano/matsumoto/" },
  { name: "小田原", lat: 35.2564, lng: 139.1550, url: "./kanagawa/odawara/" },
  { name: "熱海", lat: 35.0959, lng: 139.0717, url: "./shizuoka/atami/" }
];

let liveCameraMap = null;
let liveCameraMarkerLayer = null;
let liveCameraMarkers = new Map();

function ensureRegionLabelStyles() {
  if (document.getElementById("lcl-region-label-style")) return;

  const style = document.createElement("style");
  style.id = "lcl-region-label-style";
  style.textContent = `
    .leaflet-tooltip.lcl-region-tooltip {
      background: rgba(255,255,255,.96);
      color: #222;
      border: 1px solid rgba(0,0,0,.22);
      border-radius: 999px;
      box-shadow: 0 1px 5px rgba(0,0,0,.22);
      font-weight: 700;
      font-size: 12px;
      padding: 4px 7px;
    }

    .leaflet-tooltip.lcl-region-tooltip::before {
      display: none;
    }

    .lcl-popup-title {
      font-size: 15px;
      font-weight: 700;
      margin-bottom: 7px;
    }

    .lcl-popup-link {
      color: #1565c0;
      text-decoration: none;
      font-weight: 700;
    }
  `;
  document.head.appendChild(style);
}

function initializeMap() {
  const mapElement = document.getElementById("map");

  if (!mapElement) {
    console.error("[LCL 0.1.7] #map was not found");
    return;
  }

  if (typeof L === "undefined") {
    console.error("[LCL 0.1.7] Leaflet was not loaded");
    return;
  }

  ensureRegionLabelStyles();

  if (liveCameraMap) {
    setTimeout(() => liveCameraMap.invalidateSize(), 0);
    return;
  }

  liveCameraMap = L.map("map", {
    zoomControl: true,
    scrollWheelZoom: true
  });

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors"
  }).addTo(liveCameraMap);

  liveCameraMarkerLayer = L.layerGroup().addTo(liveCameraMap);

  LIVE_CAMERA_REGIONS.forEach(region => {
    const marker = L.circleMarker([region.lat, region.lng], {
      radius: 9,
      color: "#ffffff",
      weight: 3,
      fillColor: "#e53935",
      fillOpacity: 1,
      opacity: 1
    });

    marker.bindTooltip(region.name, {
      permanent: true,
      direction: "right",
      offset: [10, 0],
      className: "lcl-region-tooltip"
    });

    marker.bindPopup(`
      <div class="lcl-popup-title">${region.name}</div>
      <a class="lcl-popup-link" href="${region.url}">この地域を見る →</a>
    `);

    marker.addTo(liveCameraMarkerLayer);
    liveCameraMarkers.set(region.name, marker);
  });

  const bounds = L.latLngBounds(
    LIVE_CAMERA_REGIONS.map(region => [region.lat, region.lng])
  );

  liveCameraMap.fitBounds(bounds.pad(0.12));

  setTimeout(() => {
    liveCameraMap.invalidateSize();
  }, 150);

  console.info(
    `[LCL 0.1.7] regional markers loaded: ${liveCameraMarkers.size}`
  );
}

function focusRegionByName(keyword) {
  if (!liveCameraMap) initializeMap();
  if (!liveCameraMap) return false;

  const normalized = String(keyword || "").trim();
  if (!normalized) return false;

  const region = LIVE_CAMERA_REGIONS.find(item =>
    item.name.includes(normalized) || normalized.includes(item.name)
  );

  if (!region) {
    console.info("[LCL 0.1.7] no marker match:", normalized);
    return false;
  }

  const marker = liveCameraMarkers.get(region.name);

  liveCameraMap.setView([region.lat, region.lng], 11, {
    animate: false
  });

  if (marker) {
    marker.openPopup();
  }

  return true;
}

window.initializeMap = initializeMap;
window.focusRegionByName = focusRegionByName;

window.addEventListener("load", () => {
  setTimeout(() => {
    initializeMap();
    if (liveCameraMap) liveCameraMap.invalidateSize();
  }, 50);
});