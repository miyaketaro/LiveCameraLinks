# LiveCameraLinks ID-aware loader/store v1

## 上書き
- `assets/js/camera-loader.js`
- `assets/js/camera-store.js`

## 新規追加
- `test-camera-id-compat-v1.html`

## 識別子優先順位
1. `cameraId`
2. `slug`
3. 旧 `id`

## 確認URL
`http://localhost:8000/test-camera-id-compat-v1.html`

千代田区JSONが読み込める状態なら、
`OK: cameraId → slug → 旧id の互換参照が正常です`
と表示される。
