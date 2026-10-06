/* LiveCameraLinks camera-preview.js v17.4.3
 * Right-side media renderer only.
 * Identifier priority: cameraId -> slug -> legacy id
 */
(() => {
  "use strict";

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

  function allIds(camera) {
    return [camera?.cameraId, camera?.slug, camera?.id].filter(Boolean);
  }

  function getDirectUrl(camera) {
    return camera.directStreamUrl || camera.streamUrl || "";
  }

  function getProviderUrl(camera) {
    return camera.providerPageUrl || camera.officialUrl || camera.url || "";
  }

  function getYouTubeId(url) {
    const value = String(url || "");
    const patterns = [
      /youtube\.com\/watch\?v=([^&]+)/i,
      /youtube\.com\/live\/([^?&/]+)/i,
      /youtu\.be\/([^?&/]+)/i,
      /youtube\.com\/embed\/([^?&/]+)/i
    ];

    for (const pattern of patterns) {
      const match = value.match(pattern);
      if (match?.[1]) return match[1];
    }
    return "";
  }

  function getImageUrl(camera) {
    return camera.thumbnailUrl ||
           camera.imageUrl ||
           camera.snapshotUrl ||
           camera.previewImageUrl ||
           "";
  }

  function youtubeThumb(camera, videoId) {
    const thumb = getImageUrl(camera) ||
      `https://i.ytimg.com/vi/${encodeURIComponent(videoId)}/hqdefault.jpg`;

    return `<button class="camera-preview-button-v174"
      type="button"
      data-youtube-id="${esc(videoId)}"
      aria-label="${esc(camera.name || "ライブカメラ")}を再生">
      <img src="${esc(thumb)}" alt="${esc(camera.name || "ライブカメラ")} 映像プレビュー" loading="lazy">
      <span class="camera-preview-play-v174" aria-hidden="true"></span>
    </button>`;
  }

  function imagePreview(camera, imageUrl) {
    const href = getDirectUrl(camera) || getProviderUrl(camera);
    if (!href) {
      return `<div class="camera-preview-link-v174"><img src="${esc(imageUrl)}" alt="${esc(camera.name || "ライブカメラ")} カメラ画像" loading="lazy"></div>`;
    }

    return `<a class="camera-preview-link-v174"
      href="${esc(href)}" target="_blank" rel="noopener noreferrer">
      <img src="${esc(imageUrl)}" alt="${esc(camera.name || "ライブカメラ")} カメラ画像" loading="lazy">
    </a>`;
  }

  function fallback(camera) {
    const href = getDirectUrl(camera) || getProviderUrl(camera);
    if (!href) {
      return '<div class="camera-preview-fallback-v174">プレビュー情報なし</div>';
    }

    return `<a class="camera-preview-link-v174"
      href="${esc(href)}" target="_blank" rel="noopener noreferrer">
      <span class="camera-preview-fallback-v174">配信元で現在の映像を確認</span>
    </a>`;
  }

  function render(camera) {
    const videoId = getYouTubeId(getDirectUrl(camera));
    if (videoId) return youtubeThumb(camera, videoId);

    const image = getImageUrl(camera);
    if (image) return imagePreview(camera, image);

    return fallback(camera);
  }

  function player(videoId, camera) {
    return `<div class="camera-preview-player-v174">
      <iframe
        src="https://www.youtube.com/embed/${esc(videoId)}?autoplay=1&playsinline=1"
        title="${esc(camera.name || "ライブカメラ")}"
        allow="autoplay; encrypted-media; picture-in-picture"
        allowfullscreen></iframe>
    </div>`;
  }

  function hydrate(container, cameras) {
    if (!container) return;

    const map = new Map();
    for (const camera of cameras || []) {
      for (const id of allIds(camera)) map.set(String(id), camera);
    }

    container.querySelectorAll("[data-camera-preview-id]").forEach(element => {
      const id = String(element.dataset.cameraPreviewId || "");
      const camera = map.get(id);
      if (!camera) return;
      element.innerHTML = render(camera);
    });

    container.querySelectorAll(".camera-preview-button-v174").forEach(button => {
      button.addEventListener("click", () => {
        const wrap = button.closest("[data-camera-preview-id]");
        if (!wrap) return;

        const id = String(wrap.dataset.cameraPreviewId || "");
        const camera = map.get(id);
        const videoId = String(button.dataset.youtubeId || "");

        if (!camera || !videoId) return;
        wrap.innerHTML = player(videoId, camera);
      });
    });
  }

  window.LiveCameraPreview = {
    render,
    hydrate,
    getYouTubeId,
    primaryId
  };
})();
