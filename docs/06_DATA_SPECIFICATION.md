# LiveCameraLinks データ仕様書

**Project:** LiveCameraLinks
**Document:** 06_DATA_SPECIFICATION
**Version:** v0.4.0
**Last Update:** 2026-10-04

---

# 1. 目的

本書は LiveCameraLinks で利用する全データ(JSON)の仕様を定義する。

Single Source of Truth として data フォルダを採用する。

---

# 2. データ構成

data/

- camera_index.json
- camera_candidates.json
- place_index.json
- place_candidates.json
- route_index.json
- prefectures.json
- tags.json
- weather.json
- traffic.json
- favorites.json

---

# 3. Camera

管理対象

- 道路
- 河川
- 駅
- 繁華街
- 観光地
- 空港
- 港

---

# 4. Place

目的地管理

例

- 箱根
- 京都
- 河口湖
- 松本
- 草津

---

# 5. Route

経路管理

例

東京 → 箱根

東京 → 河口湖

東京 → 松本

---

# 6. Master

prefectures.json

47都道府県

tags.json

検索タグ管理

---

# 7. Candidate

AI探索結果

camera_candidates.json

place_candidates.json

---

# 8. Version

Version管理はJSON毎に保持する。