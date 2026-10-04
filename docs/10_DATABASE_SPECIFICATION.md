# LiveCameraLinks データベース仕様書

**Project:** LiveCameraLinks
**Document:** 10_DATABASE_SPECIFICATION
**Version:** v0.4.0
**Last Update:** 2026-10-04

---

# 1. 目的

本書は LiveCameraLinks のデータ構造を定義する。

現在は JSON をデータベースとして利用する。

将来的に SQLite / PostgreSQL へ移行可能な設計とする。

---

# 2. Camera

camera_index.json

主キー

id

項目

- id
- name
- prefecture
- city
- placeId
- category
- subCategory
- provider
- officialUrl
- streamUrl
- latitude
- longitude
- status
- quality
- tags
- lastVerified

---

# 3. Place

place_index.json

項目

- id
- name
- prefecture
- city
- latitude
- longitude
- tags

---

# 4. Route

route_index.json

項目

- id
- from
- to
- waypoints
- cameras

---

# 5. Prefecture

prefectures.json

項目

- code
- name
- region

---

# 6. Tag

tags.json

項目

- id
- name
- category

---

# 7. Weather

weather.json

将来追加

---

# 8. Traffic

traffic.json

将来追加

---

# 9. Favorite

favorites.json

項目

- user
- cameraId
- placeId

---

# 10. データ管理方針

Single Source of Truth

すべての画面は

data/

配下のJSONを参照する。

HTMLにはデータを保持しない。

---

# 11. 将来

JSON

↓

SQLite

↓

PostgreSQL

↓

Cloud Database

へ移行可能な設計とする。