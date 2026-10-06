# cameraId / slug 移行

## 1. まずプレビュー
リポジトリ直下で:

```powershell
powershell -ExecutionPolicy Bypass -File .\assign-camera-ids.ps1
```

この段階ではJSONを書き換えません。

## 2. 重複がないことを確認
- Duplicate cameraId: 0
- Duplicate slug: 0 が理想
- New cameraId 件数を確認

## 3. 実適用
確認後:

```powershell
powershell -ExecutionPolicy Bypass -File .\assign-camera-ids.ps1 -Apply
```

## 重要
- 既存 cameraId は変更しない
- 既存 id は slug にコピー
- cameraId 欠番は再利用しない
- index.json / camera_index.json は直接変更しない
