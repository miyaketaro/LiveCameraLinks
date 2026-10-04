# LiveCameraLinks データモデル

**Project:** LiveCameraLinks
**Document:** 08_DATA_MODEL
**Version:** v0.4.0

---

# データモデル

Place

↓

Camera

↓

Weather

↓

Traffic

↓

Route

↓

User

↓

Favorite

---

# Camera

Camera

↓

Provider

↓

Official URL

↓

YouTube URL

↓

Map

---

# Place

Place

↓

Camera

↓

Route

↓

Weather

---

# Route

Start

↓

Waypoint

↓

Destination

↓

Camera

---

# Master

Prefecture

↓

City

↓

Place

↓

Camera

---

# AI Workflow

Search

↓

camera_candidates

↓

AI Validation

↓

Human Approval

↓

camera_index

↓

Website

---

# Single Source of Truth

すべての画面は

data/

配下のJSONを参照する。

HTMLは表示専用とする。

---

# 将来構想

JSON

↓

SQLite

↓

PostgreSQL

↓

Cloud Database

へ移行可能な設計とする。