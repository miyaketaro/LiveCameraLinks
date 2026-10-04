// ==========================================
// LiveCameraLinks Build 0.1.8
// app.js - accumulated camera data connection
// ==========================================

const LCL_PRIORITY = {
  "道路": 0, "街・道路": 1, "駅": 2, "鉄道": 3, "街": 4,
  "観光": 5, "名所": 6, "自然": 7, "港": 8, "海": 9,
  "海岸": 10, "スキー場": 11, "河川": 20
};

function esc(value) {
  return String(value ?? "")
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#39;");
}

function validHttpUrl(url) {
  return typeof url === "string" && /^https?:\/\//i.test(url);
}

function cameraSearchText(camera) {
  return [
    camera.name, camera.place, camera.region, camera.kind, camera.source,
    ...(Array.isArray(camera.aliases) ? camera.aliases : [])
  ].filter(Boolean).join(" ").toLowerCase();
}

function sortCameras(items) {
  return [...items].sort((a,b) => {
    const pa = LCL_PRIORITY[a.kind] ?? 50;
    const pb = LCL_PRIORITY[b.kind] ?? 50;
    if (pa !== pb) return pa - pb;
    return String(a.place || "").localeCompare(String(b.place || ""), "ja");
  });
}

function mapUrl(camera) {
  const lat = Number(camera.lat);
  const lng = Number(camera.lng);
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    return `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=16/${lat}/${lng}`;
  }
  return `https://www.openstreetmap.org/search?query=${encodeURIComponent(camera.place || camera.name || "")}`;
}

function routeUrl(camera) {
  const lat = Number(camera.lat);
  const lng = Number(camera.lng);
  if (Number.isFinite(lat) && Number.isFinite(lng)) {
    return `https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=;${lat},${lng}`;
  }
  return mapUrl(camera);
}

function actionLink(url, label, className="") {
  if (!validHttpUrl(url)) {
    return `<span class="camera-action is-disabled ${className}" aria-disabled="true">${label}</span>`;
  }
  return `<a class="camera-action ${className}" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${label}</a>`;
}

function cameraCard(camera) {
  const provider = camera.providerPageUrl || camera.url || "";
  const direct = camera.directStreamUrl || "";
  const meta = [camera.place, camera.kind, camera.source].filter(Boolean);

  return `
    <article class="camera-card" data-camera-id="${esc(camera.id)}">
      <div class="camera-card-top">
        <div class="camera-icon">${esc(camera.icon || "📷")}</div>
        <div class="camera-card-title">
          <h3>${esc(camera.name || "ライブカメラ")}</h3>
          <p>${esc(meta.join(" · "))}</p>
        </div>
      </div>
      ${camera.desc ? `<p class="camera-desc">${esc(camera.desc)}</p>` : ""}
      <div class="camera-badges">
        ${camera.kind ? `<span>${esc(camera.kind)}</span>` : ""}
        ${camera.lastChecked ? `<span>確認 ${esc(camera.lastChecked)}</span>` : ""}
        ${camera.healthStatus ? `<span>${esc(camera.healthStatus)}</span>` : ""}
      </div>
      <div class="camera-actions">
        ${actionLink(provider, "🏢 配信元")}
        ${actionLink(direct, "▶ 映像ビュー")}
        ${actionLink(mapUrl(camera), "🗺 地図")}
        ${actionLink(routeUrl(camera), "🚗 経路")}
      </div>
    </article>
  `;
}

function renderCameras(items, query="") {
  const list = document.getElementById("camera-list");
  const count = document.getElementById("cameraCount");
  if (!list || !count) return;

  const sorted = sortCameras(items);
  const DISPLAY_LIMIT = 80;
  const shown = sorted.slice(0, DISPLAY_LIMIT);

  count.textContent = query
    ? `検索結果 ${sorted.length}件 / 全${window.LIVE_CAMERA_COUNT || sorted.length}件`
    : `掲載 ${window.LIVE_CAMERA_COUNT || sorted.length}地点`;

  if (!shown.length) {
    list.innerHTML = `<div class="camera-empty">該当するライブカメラが見つかりませんでした。</div>`;
    if (window.showCameraResultsOnMap) window.showCameraResultsOnMap([]);
    return;
  }

  const limitNote = sorted.length > DISPLAY_LIMIT
    ? `<div class="camera-limit-note">該当 ${sorted.length}件のうち先頭${DISPLAY_LIMIT}件を表示しています。目的地を詳しく入力すると絞り込めます。</div>`
    : "";

  list.innerHTML = limitNote + `<div class="camera-grid">${shown.map(cameraCard).join("")}</div>`;

  if (window.showCameraResultsOnMap) {
    window.showCameraResultsOnMap(shown);
  }
}

function searchCameras(keyword) {
  const q = String(keyword || "").trim().toLowerCase();
  const all = Array.isArray(window.LIVE_CAMERAS) ? window.LIVE_CAMERAS : [];
  if (!q) return all;
  return all.filter(camera => cameraSearchText(camera).includes(q));
}

document.addEventListener("DOMContentLoaded", () => {
  console.info(`[LCL 0.1.8] app loaded / cameras: ${window.LIVE_CAMERA_COUNT || 0}`);

  initializeMap();

  const all = Array.isArray(window.LIVE_CAMERAS) ? window.LIVE_CAMERAS : [];
  // Initial screen: roads first, then station/city/tourism; rivers come later.
  renderCameras(all);

  const searchButton = document.getElementById("searchButton");
  const destination = document.getElementById("destination");

  if (searchButton && destination) {
    const runSearch = () => {
      const keyword = destination.value.trim();
      const results = searchCameras(keyword);
      renderCameras(results, keyword);

      if (keyword && window.focusRegionByName) {
        window.focusRegionByName(keyword);
      }

      document.querySelector(".camera-panel")?.scrollIntoView({
        behavior:"smooth",
        block:"start"
      });
    };

    searchButton.addEventListener("click", runSearch);
    destination.addEventListener("keydown", event => {
      if (event.key === "Enter") {
        event.preventDefault();
        runSearch();
      }
    });
  }
});