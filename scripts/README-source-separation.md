# LiveCameraLinks 候補発見元分離 v1

このツールは、監査で検出した候補索引由来の情報を、

- 公開 Camera JSON
- 非公開 provenance / Candidates データ

へ分離するための移行ツールです。

## 安全設計

既定では **DRY RUN** です。

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\separate-source-provenance.ps1
```

この状態では Camera JSON を変更しません。

## DRY RUN 出力

```text
reports/source-separation/
├─ public-fields-to-move.csv
├─ slug-changes.csv
├─ manual-provider-review.csv
└─ private-provenance-preview.json
```

### public-fields-to-move.csv
公開 Camera JSON から非公開側へ移動予定のフィールド。

対象:
- referencePageUrl
- batchSource
- healthReason
- auditStatus

### slug-changes.csv
候補索引サイト名を含む slug の正規化予定。

例:

```text
cametan_tokyo_adachi_cityscape
→
tokyo_adachi_cityscape
```

### manual-provider-review.csv
自動変更してはいけない URL / provider 系の要確認一覧。

元配信元を確認してから手作業または別工程で修正する。

## 非公開保存先

本適用時は既定で:

```text
C:\Users\a\Documents\LiveCameraLinks-private\
└─ collector\
   └─ candidates\
      └─ provenance-migrated.json
```

公開GitHubリポジトリの外に保存する。

## 本適用

DRY RUN結果を確認した後のみ:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\separate-source-provenance.ps1 -Apply
```

## この工程で自動的にしないこと

- `url`
- `providerPageUrl`
- `directStreamUrl`
- `source`
- `publisher`
- `provider`

は自動修正しない。

元配信元を確認してから修正する。

## cameraIdについて

この移行の間は旧 `id` を互換性のため残す。
候補発見元の整理完了後に `assign-camera-ids.ps1 -Apply` を実施する。
