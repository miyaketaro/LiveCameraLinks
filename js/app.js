// ==========================================
// LiveCameraLinks Build 0.1.7
// app.js
// ==========================================

document.addEventListener("DOMContentLoaded", () => {
  console.info("[LCL 0.1.7] app loaded");

  initializeMap();

  const searchButton = document.getElementById("searchButton");
  const destination = document.getElementById("destination");

  if (!searchButton || !destination) {
    console.warn("[LCL 0.1.7] search controls were not found");
    return;
  }

  const runSearch = () => {
    const keyword = destination.value.trim();
    if (!keyword) return;

    const found = focusRegionByName(keyword);

    if (!found) {
      console.info("[LCL 0.1.7] destination not found:", keyword);
    }
  };

  searchButton.addEventListener("click", runSearch);

  destination.addEventListener("keydown", event => {
    if (event.key === "Enter") {
      event.preventDefault();
      runSearch();
    }
  });
});