// ==========================================
// LiveCameraLinks Build 0.1.8
// map.js
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
let liveCameraRegionLayer = null;
let liveCameraResultLayer = null;
let liveCameraMarkers = new Map();

function ensureRegionLabelStyles() {
  if (document.getElementById("lcl-region-label-style")) return;
  const style = document.createElement("style");
  style.id = "lcl-region-label-style";
  style.textContent = `
    .leaflet-tooltip.lcl-region-tooltip {
      background: rgba(255,255,255,.96);
      color:#222;
      border:1px solid rgba(0,0,0,.22);
      border-radius:999px;
      box-shadow:0 1px 5px rgba(0,0,0,.22);
      font-weight:700;
      font-size:12px;
      padding:4px 7px;
    }
    .leaflet-tooltip.lcl-region-tooltip::before { display:none; }
  `;
  document.head.appendChild(style);
}

function initializeMap() {
  const mapElement = document.getElementById("map");
  if (!mapElement || typeof L === "undefined") return;

  ensureRegionLabelStyles();

  if (liveCameraMap) {
    setTimeout(() => liveCameraMap.invalidateSize(), 0);
    return;
  }

  liveCameraMap = L.map("map", { zoomControl:true, scrollWheelZoom:true });

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom:19,
    attribution:"&copy; OpenStreetMap contributors"
  }).addTo(liveCameraMap);

  liveCameraRegionLayer = L.layerGroup().addTo(liveCameraMap);
  liveCameraResultLayer = L.layerGroup().addTo(liveCameraMap);

  LIVE_CAMERA_REGIONS.forEach(region => {
    const marker = L.circleMarker([region.lat, region.lng], {
      radius:9, color:"#fff", weight:3,
      fillColor:"#e53935", fillOpacity:1, opacity:1
    });

    marker.bindTooltip(region.name, {
      permanent:true,
      direction:"right",
      offset:[10,0],
      className:"lcl-region-tooltip"
    });

    marker.bindPopup(`<b>${escapeMapHtml(region.name)}</b><br><a href="${region.url}">この地域を見る →</a>`);
    marker.addTo(liveCameraRegionLayer);
    liveCameraMarkers.set(region.name, marker);
  });

  const bounds = L.latLngBounds(LIVE_CAMERA_REGIONS.map(r => [r.lat,r.lng]));
  liveCameraMap.fitBounds(bounds.pad(0.12));
  setTimeout(() => liveCameraMap.invalidateSize(), 100);
}

function escapeMapHtml(value) {
  return String(value ?? "")
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;");
}

function focusRegionByName(keyword) {
  if (!liveCameraMap) initializeMap();
  const normalized = String(keyword || "").trim();
  const region = LIVE_CAMERA_REGIONS.find(item =>
    item.name.includes(normalized) || normalized.includes(item.name)
  );
  if (!region || !liveCameraMap) return false;
  liveCameraMap.setView([region.lat,region.lng],11,{animate:false});
  const marker = liveCameraMarkers.get(region.name);
  if (marker) marker.openPopup();
  return true;
}

function showCameraResultsOnMap(cameras) {
  if (!liveCameraMap) initializeMap();
  if (!liveCameraMap || !liveCameraResultLayer) return;

  liveCameraResultLayer.clearLayers();

  const points = [];
  (cameras || []).slice(0,80).forEach(camera => {
    const lat = Number(camera.lat);
    const lng = Number(camera.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    const marker = L.circleMarker([lat,lng], {
      radius:5,
      color:"#fff",
      weight:2,
      fillColor:"#1565c0",
      fillOpacity:.95
    });

    marker.bindPopup(
      `<b>${escapeMapHtml(camera.name)}</b><br>` +
      `${escapeMapHtml(camera.place || "")}<br>` +
      `<small>${escapeMapHtml(camera.source || "")}</small>`
    );
    marker.addTo(liveCameraResultLayer);
    points.push([lat,lng]);
  });

  if (points.length === 1) {
    liveCameraMap.setView(points[0],12,{animate:false});
  } else if (points.length > 1) {
    liveCameraMap.fitBounds(L.latLngBounds(points).pad(0.15));
  }
}

window.initializeMap = initializeMap;
window.focusRegionByName = focusRegionByName;
window.showCameraResultsOnMap = showCameraResultsOnMap;

window.addEventListener("load", () => {
  setTimeout(() => {
    initializeMap();
    if (liveCameraMap) liveCameraMap.invalidateSize();
  }, 50);
});