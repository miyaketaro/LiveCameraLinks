# LiveCameraLinks YouTube LIVE Collector — prototype

YouTube Data API v3 を使い、現在ライブ配信中の「ライブカメラ候補」を検索して JSON / CSV に保存する試作版です。

## 必要なもの

- PHP 8 以上
- PHP cURL / mbstring
- YouTube Data API v3 の API キー

## 使い方

1. `config.example.php` を `config.php` にコピー
2. `config.php` の `youtube_api_key` に API キーを設定
3. 必要なら `queries` を変更
4. コマンドラインで実行

```bash
php collector.php
```

実行すると `output/` に以下を保存します。

- `candidates-YYYYMMDD-HHMMSS.json`
- `candidates-YYYYMMDD-HHMMSS.csv`

## 現在の試作仕様

- `search.list` に `type=video` + `eventType=live` を指定
- 1検索あたり最大50件
- 同じ動画IDは自動で重複排除
- `videos.list` でライブ配信メタデータを再確認
- 「道路 / 駅 / 繁華街 / 観光 / 雪道 / 河川」をキーワード分類
- ゲーム・ニュース・雑談などは候補スコアを下げる
- APIキーは配布ファイルへ直接埋め込まない

## 注意

`regionCode=JP` は「日本で視聴可能な結果」の指定であり、設置地点が日本国内であることを保証しません。タイトル・説明・チャンネル情報などを使った追加判定が必要です。

この試作版は候補抽出用です。livecameralinks.com へ自動公開する前に、人または追加ロジックで配信元・所在地・定点カメラ性を確認する設計を推奨します。
