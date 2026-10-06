/* LiveCameraLinks camera-card.js v17.4.14
 * v17.4.5 badge policy:
 * - Show at most 5 badges on each card.
 * - Display order:
 *   1. mediaType
 *   2. category label
 *   3. themes
 * - Keep detailed scene data in JSON (themes / viewTargets / description).
 * - Strict LIVE badge: show only when liveStatus === "live".
 * - Identifier priority: cameraId -> slug -> legacy id.
 */
(() => {
  "use strict";

  const FAVORITE_KEY = "livecameralinks_favorites";
  const MAX_FAVORITES = 10;
  const MAX_BADGES = 5;

  const CATEGORY_LABELS = {
    road: "道路",
    station: "駅",
    city: "繁華街",
    river: "河川",
    tourism: "観光",
    parking: "駐車場",
    airport: "空港",
    port: "港"
  };

  function esc(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function primaryId(camera) {
    return camera?.cameraId || camera?.slug || camera?.id || "";
  }

  function aliases(camera) {
    return [
      camera?.cameraId,
      camera?.slug,
      camera?.id
    ].filter(Boolean);
  }

  function getFavorites() {
    try {
      const data = JSON.parse(
        localStorage.getItem(FAVORITE_KEY) || "[]"
      );
      return Array.isArray(data) ? data : [];
    } catch (_) {
      return [];
    }
  }

  function saveFavorites(ids) {
    localStorage.setItem(
      FAVORITE_KEY,
      JSON.stringify([...new Set(ids)].slice(0, MAX_FAVORITES))
    );
  }

  function isFavorite(camera) {
    const set = new Set(getFavorites());
    return aliases(camera).some(id => set.has(id));
  }

  function toggleFavorite(camera) {
    const ids = aliases(camera);
    const main = primaryId(camera);
    let favorites = getFavorites();

    const active = ids.some(id => favorites.includes(id));
    favorites = favorites.filter(id => !ids.includes(id));

    if (!active && main) favorites.unshift(main);

    saveFavorites(favorites);
    return !active;
  }

  function providerUrl(camera) {
    return (
      camera.providerPageUrl ||
      camera.officialUrl ||
      camera.url ||
      ""
    );
  }

  function directUrl(camera) {
    return (
      camera.directStreamUrl ||
      camera.streamUrl ||
      ""
    );
  }

  function hasCoords(camera) {
    return (
      Number.isFinite(Number(camera.lat ?? camera.latitude)) &&
      Number.isFinite(Number(camera.lng ?? camera.longitude))
    );
  }

  function coords(camera) {
    return {
      lat: Number(camera.lat ?? camera.latitude),
      lng: Number(camera.lng ?? camera.longitude)
    };
  }

  function routeUrl(camera) {
    if (!hasCoords(camera)) return "";

    const { lat, lng } = coords(camera);

    return (
      "https://www.openstreetmap.org/directions" +
      `?to=${encodeURIComponent(lat)}%2C${encodeURIComponent(lng)}`
    );
  }

  function isLive(camera) {
    return (
      String(camera.liveStatus || "")
        .toLowerCase()
        .trim() === "live"
    );
  }

  function categoryLabel(camera) {
    const category = String(camera.category || "").trim();
    if (!category) return "";

    return CATEGORY_LABELS[category] || category;
  }

  function buildBadgeLabels(camera) {
    const labels = [];

    function push(label) {
      const value = String(label || "").trim();
      if (!value) return;

      if (!labels.includes(value)) {
        labels.push(value);
      }
    }

    // 1. Media
    push(camera.mediaType);

    // 2. Major category
    push(categoryLabel(camera));

    // 3. Purpose / scene themes
    if (Array.isArray(camera.themes)) {
      for (const theme of camera.themes) {
        push(theme);
      }
    }

    return labels.slice(0, MAX_BADGES);
  }

  function renderBadges(camera) {
    return buildBadgeLabels(camera)
      .map(label => esc(label))
      .join("　");
  }

  function action(label, url, extraClass = "") {
    if (!url) return "";

    return `
      <a
        class="camera-action-v174 ${extraClass}"
        href="${esc(url)}"
        target="_blank"
        rel="noopener noreferrer"
      >${esc(label)}</a>
    `;
  }

  function renderCameraCard(camera) {
    const pid = primaryId(camera);
    const provider = providerUrl(camera);
    const direct = directUrl(camera);
    const route = routeUrl(camera);

    const publisher =
      camera.publisher ||
      camera.source ||
      camera.provider ||
      "";

    const place =
      camera.place ||
      camera.region ||
      "";

    const kind =
      camera.kind ||
      categoryLabel(camera) ||
      "";

    const desc =
      camera.viewTarget ||
      camera.desc ||
      camera.description ||
      "配信元で現在の映像をご確認ください。";

    const badges = renderBadges(camera);

    const pm = camera.providerMessage;

    const pr =
      pm &&
      pm.enabled === true &&
      String(pm.text || "").trim()
        ? `
          <div class="camera-pr-message-v174">
            <span class="camera-pr-label-v174">[PR]</span>
            <span class="camera-pr-text-v174">${esc(pm.text)}</span>
          </div>
        `
        : "";

    const favActive = isFavorite(camera);

    return `
      <article
        class="card camera-card-v174"
        id="camera-${esc(pid)}"
        data-camera-id="${esc(camera.cameraId || "")}"
        data-camera-slug="${esc(camera.slug || "")}"
        data-camera-legacy-id="${esc(camera.id || "")}"
      >
        <div class="camera-left-v174">
          <div class="camera-main-v174">
            <div class="camera-heading-v174">
              <div class="camera-leading-v174">
                <div class="icon">${esc(camera.icon || "📷")}</div>
              </div>

              <div class="camera-heading-text-v174">
                <div class="name">${esc(camera.name || "")}</div>
                <div class="meta">
                  ${esc(place)}
                  ${kind ? ` · ${esc(kind)}` : ""}
                </div>
              </div>
            </div>

            <div class="camera-desc-v174 camera-attributes-v174">${badges}</div>

            <div class="camera-desc-v174">${esc(desc)}</div>
          </div>

          <div class="camera-pr-slot-v174">${pr}</div>
        </div>

        <div class="camera-right-v174">
          <div class="camera-action-frame-v174">
            ${isLive(camera)
              ? '<span class="camera-live-v174">● LIVE</span>'
              : ""
            }

            <div class="camera-actions-v174">
              ${action("配信元", provider, "primary")}
              ${action("映像", direct)}

              ${
                hasCoords(camera)
                  ? `
                    <button
                      class="camera-action-v174 camera-map-btn-v174"
                      type="button"
                      data-map-name="${esc(camera.name || "")}"
                      data-map-lat="${coords(camera).lat}"
                      data-map-lng="${coords(camera).lng}"
                    >地図</button>
                  `
                  : ""
              }

              ${action("経路", route)}

              <button
                class="fav-toggle camera-favorite-top-v174${favActive ? " active" : ""}"
                type="button"
                data-favorite-id="${esc(pid)}"
                aria-label="${favActive ? "お気に入りから削除" : "お気に入りに追加"}"
                title="${favActive ? "お気に入りから削除" : "お気に入りに追加"}"
              >${favActive ? "★" : "☆"}</button>
            </div>
          </div>

          <div
            class="camera-preview-v174"
            data-camera-preview-id="${esc(pid)}"
          ></div>

          <div class="camera-provider-footer-v174">
            <span class="camera-provider-label-v174">配信元：</span>
            <strong>${esc(publisher)}</strong>
          </div>
        </div>
      </article>
    `;
  }

  function bind(container, cameras) {
    const byAnyId = new Map();

    for (const camera of cameras || []) {
      for (const id of aliases(camera)) {
        byAnyId.set(String(id), camera);
      }
    }

    container.querySelectorAll(".fav-toggle").forEach(button => {
      button.addEventListener("click", () => {
        const article = button.closest(".camera-card-v174");

        const id =
          article?.dataset.cameraId ||
          article?.dataset.cameraSlug ||
          article?.dataset.cameraLegacyId ||
          "";

        const camera = byAnyId.get(id);
        if (!camera) return;

        const active = toggleFavorite(camera);

        button.textContent = active ? "★" : "☆";
        button.classList.toggle("active", active);

        const label = active
          ? "お気に入りから削除"
          : "お気に入りに追加";

        button.setAttribute("aria-label", label);
        button.title = label;
      });
    });

    container.querySelectorAll(".camera-map-btn-v174").forEach(button => {
      button.addEventListener("click", () => {
        const name = button.dataset.mapName || "";
        const lat = Number(button.dataset.mapLat);
        const lng = Number(button.dataset.mapLng);

        if (typeof window.LCOpenMap === "function") {
          window.LCOpenMap(name, lat, lng);
          return;
        }

        window.open(
          "https://www.openstreetmap.org/" +
          `?mlat=${encodeURIComponent(lat)}` +
          `&mlon=${encodeURIComponent(lng)}` +
          `#map=16/${encodeURIComponent(lat)}/${encodeURIComponent(lng)}`,
          "_blank",
          "noopener"
        );
      });
    });
  }

  function renderCameraCards(container, cameras) {
    if (!container) return;

    if (!Array.isArray(cameras) || cameras.length === 0) {
      container.innerHTML =
        '<div class="empty">カメラがありません。</div>';
      return;
    }

    container.innerHTML = cameras
      .map(renderCameraCard)
      .join("");

    bind(container, cameras);
  }

  window.LiveCameraCard = {
    renderCameraCard,
    renderCameraCards,
    getFavorites,
    toggleFavorite,
    primaryId,
    isLive,
    buildBadgeLabels
  };
})();
