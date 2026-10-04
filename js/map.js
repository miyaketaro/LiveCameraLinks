// ===========================================
// LiveCameraLinks 3.0
// map.js
// ===========================================

let map;

function initializeMap() {

    map = L.map("map");

    map.setView([36.2048, 138.2529], 6);

    L.tileLayer(
        "https://tile.openstreetmap.org/{z}/{x}/{y}.png",
        {
            maxZoom: 19,
            attribution: "© OpenStreetMap contributors"
        }
    ).addTo(map);

}