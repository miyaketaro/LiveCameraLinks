# 04_DATA_SPECIFICATION_v1.0

# LiveCameraLinks データ仕様書

## 1. 目的
本書は LiveCameraLinks のデータモデルを定義する。

## 2. カメラ識別子

LiveCameraLinks では、カメラ識別子を次の2項目で管理する。

### cameraId
世界共通の正式ID。永続IDとして扱い、一度発番した値は変更・再利用しない。

形式:

```text
CC-RR-NNNNNN
```

- `CC`: 国コード。原則 ISO 3166-1 alpha-2（例: JP, US, FR）
- `RR`: 地域コード。日本では都道府県コード2桁を使用
- `NNNNNN`: 地域内6桁連番

例:

```text
JP-13-000001
JP-13-000184
JP-27-000001
US-CA-000001
```

### slug
人間・開発者が理解しやすい意味ID。既存の `id` 値を移行時に `slug` として保持する。

例:

```text
road_tokyo_takebashi_jct_c1
```

### ルール
1. `cameraId` をシステム上の正式IDとする。
2. `slug` は検索・開発・デバッグ・可読性のために保持する。
3. `cameraId` はカメラ名、URL、カテゴリ、配信元、所在地表記が変わっても原則変更しない。
4. 終了・削除したカメラの `cameraId` は欠番として残し、別カメラへ再利用しない。
5. 既に `cameraId` が存在するレコードは自動付番処理で再発番しない。
6. 旧 `id` は移行時に `slug` へコピーし、移行完了後は互換性確認のうえ段階的に廃止する。
7. `countryCode`、`regionCode`、`cameraNo` は `cameraId` と重複するため、Cameraレコードには原則保持しない。

## 3. エンティティ

### Camera
必須:
- cameraId
- slug
- name

主な項目:
- placeId
- routeId
- providerId
- latitude
- longitude
- officialUrl
- streamUrl
- status
- category
- mediaType
- themes[]
- viewTargets[]
- providerMessage

例:

```json
{
  "cameraId": "JP-13-000184",
  "slug": "road_tokyo_takebashi_jct_c1",
  "name": "首都高C1・竹橋JCT付近",
  "category": "road",
  "status": "active"
}
```

### Place
- id
- name
- prefecture
- latitude
- longitude
- weatherId
- trafficId
- parkingIds[]
- cameraIds[]

### Route
- id
- name
- startPlaceId
- destinationPlaceId
- waypointIds[]
- cameraIds[]

### Parking
- id
- placeId
- name
- latitude
- longitude
- capacity
- status

### Provider
- id
- name
- type
- officialSite

### Weather
- placeId
- condition
- temperature
- precipitation

### Traffic
- placeId
- congestion
- regulation

## 4. 日本の地域コード
日本では JIS 都道府県コードに合わせた2桁コードを使用する。

主要例:

```text
01 北海道
13 東京都
14 神奈川県
19 山梨県
20 長野県
26 京都府
27 大阪府
28 兵庫県
```

全国展開時は47都道府県すべてをマスター管理する。

## 5. マスターデータ

```text
data/master/
- prefectures.json
- regions.json
- categories.json
- providers.json
- badges.json
- tags.json
```

`prefectures.json` には、少なくとも国コード・都道府県コード・slug・名称を保持する。

例:

```json
{
  "countryCode": "JP",
  "regionCode": "13",
  "slug": "tokyo",
  "name": "東京都"
}
```

## 6. cameraId 発番

### 初回移行
1. 各Cameraの既存 `id` を `slug` にコピーする。
2. 既存 `cameraId` がある場合は変更しない。
3. `cameraId` がないCameraのみ発番対象とする。
4. 同一地域で既に使われている最大番号の次から採番する。
5. 並び順変更によって既発番IDを変更しない。

### 新規登録
新規Camera承認時に、その地域の次番号を1件だけ発番する。

### 削除・終了
番号を再利用しない。終了データを保持する場合は `status` で管理する。

## 7. JSON配置

```text
camera/
├─ camera_index.json
├─ road/
│  ├─ index.json
│  └─ tokyo/
│     ├─ index.json
│     └─ chiyoda.json
├─ station/
├─ river/
├─ city/
├─ tourism/
├─ parking/
├─ airport/
└─ port/
```

Cameraレコードはカテゴリ別・地域別JSONに保持する。

## 8. 互換性

移行期間中は次の順に識別子を解決する。

```text
cameraId → slug → 旧 id
```

新コードでは `cameraId` を優先する。
既存のお気に入り・履歴・旧URL等が `id` に依存している場合は、完全移行前に互換処理を実装する。

## 9. 座標
Place・Camera・ParkingはWGS84の緯度経度を保持する。

## 10. 基本思想
Place（目的地）を中心に Camera・Weather・Traffic・Parking・Route を関連付ける。

Cameraは世界展開を前提として、表示名やURLとは独立した永続 `cameraId` を持つ。
