# LiveCameraLinks 外部候補索引痕跡監査 v1

この監査は **読み取り専用** です。`camera/**/*.json` は変更しません。

## 配置

```text
LiveCameraLinks/
├─ camera/
├─ scripts/
│  └─ audit-external-source-traces.ps1
└─ reports/
```

## 実行

リポジトリ直下で:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\audit-external-source-traces.ps1
```

## 出力

```text
reports/source-audit/
├─ external-source-traces.csv
├─ external-source-traces.json
└─ summary.txt
```

## 主な検出対象

- Cametan
- livecam.asia
- LiveAtlas
- Fujiyama.TV
- HIR-NET
- とまり木
- livecombs
- ライブカメラDB

## 監査対象フィールド

`id`, `slug`, `source`, `publisher`, `providerPageUrl`, `directStreamUrl`,
`url`, `referencePageUrl`, `desc`, `healthReason`, `auditStatus`,
`batchSource` など。

## 重要

この監査結果を見てから、
1. 公開Camera JSONに不要な候補索引名を除去
2. 必要な探索来歴を非公開Candidates/Collectorへ退避
3. 元配信元を再確認
4. LiveCameraLinks独自説明文へ修正
5. その後 `cameraId` を正式適用

の順で進める。
