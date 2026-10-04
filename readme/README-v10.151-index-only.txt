livecameralinks v10.151 index-only deployment package

Purpose:
- Replace ONLY tokyotokyo.net/index.html
- Keep all existing server-side files and folders unchanged.

DO NOT overwrite or delete:
- camera/
- error/
- styles.css
- tokyo-road.html
- tokyo-traffic.html
- route-tokyo-hakone.html
- route-tokyo-karuizawa.html
- route-tokyo-kawaguchiko.html
- route-tokyo-nikko.html
- route4.html
- route20.html
- shutoko-c1.html
- kannanana.html
- kanpachi.html
- route-source-policy-v10_23.json
- view-policy-v10_38.json

Deployment:
1. Download the current server index.html as a backup, e.g. index-backup-v10.45.html
2. Upload this package's index.html to the tokyotokyo.net root.
3. Overwrite ONLY the existing index.html.
4. Hard refresh browser (Ctrl+F5).
5. Check: main camera list, search, favorite, history, tokyo-road.html, tokyo-traffic.html.

Database:
- 1,789 cameras
- unique IDs: 1,789
- Includes initial-complete datasets through Kanto and Koshinetsu.
- Null-coordinate compatibility patch included: unknown coordinates are not treated as 0,0 for maps/routes.
