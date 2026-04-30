# GCP / OAuth 構成メモ（みまもりウェブ）

> みまもりウェブにおける GCP プロジェクト・OAuth・外部 Google 連携の構成を記録する。
> 構成変更時は必ず本ドキュメントを更新すること。

---

## 概要

みまもりウェブでは、**本番の GCP 基盤**と**OAuth / 外部 Google 連携基盤**が別プロジェクトになっている。

| 役割 | GCP プロジェクト |
|---|---|
| 本番 GCP 基盤 | `Mimamori Web Production` |
| OAuth / 外部 Google 連携 / 開発環境 | `GCREV INSIGHT` |

現時点では、この構成を**無理に統合しない**方針とする。

---

## 本番環境

### 環境名

`mimamori`

### GCP プロジェクト

`Mimamori Web Production`

### 用途

- サービスアカウント認証
- Vertex AI（Gemini）
- GA4（Google Analytics Data API）
- Search Console（GSC）

### 設定（wp-config.php 定数）

| 定数 | 値 |
|---|---|
| `GCREV_SA_PATH` | `/home/kusanagi/mimamori/secrets/mimamori-web-production-8f86880f344c.json` |
| `GCREV_GCP_PROJECT_ID` | `mimamori-web-production` |
| `MIMAMORI_ENV` | `production` |

### 補足

- 本番の GCP 処理基盤は `Mimamori Web Production` を使用
- サービスアカウント系の処理（GA4・GSC・Vertex AI）は本番側プロジェクトを参照

---

## 開発環境 / OAuth / 外部 Google 連携

### 環境名

`mimamori-dev`

### GCP プロジェクト

`GCREV INSIGHT`

### 用途

- 開発環境用の GCP プロジェクト
- GBP OAuth 管理
- Google Ads API / OAuth 管理
- GBP 関連 API
- 開発時の Google 系連携確認

### サービスアカウント

- ファイル: `/home/kusanagi/mimamori-dev/secrets/gcrev-insight-fd0cc85fabe2.json`
- アカウント: `gcrev-insight-sa@gcrev-insight.iam.gserviceaccount.com`

### 有効化済み API

- Google Analytics Data API
- Business Profile Performance API
- Google Search Console API
- Google Ads API
- My Business Account Management API
- My Business Business Information API
- Vertex AI API

### OAuth クライアント

| クライアント名 | 用途 |
|---|---|
| `GCREV INSIGHT GBP` | GBP（Googleビジネスプロフィール）連携 |
| `Mimamori Web Google Ads API` | Google Ads API 連携（デスクトップアプリ） |

---

## GBP 連携

### 現在の構成

本番の GBP 連携は、WordPress オプション（`gcrev_gbp_client_id` / `gcrev_gbp_client_secret`）に保存された OAuth 情報を使用している。

### 確認済み事項

- `gcrev_gbp_client_id` は `GCREV INSIGHT GBP` の client_id
- `gcrev_gbp_client_secret` あり

### 結論

- **本番の GBP OAuth は `GCREV INSIGHT` 側を利用している**
- GBP は申請・審査の都合もあるため、**現時点では移行しない**

---

## Google Ads API

### 現在の構成

- Google Ads API / OAuth は **`GCREV INSIGHT` 側で管理**
- 本番コード上の `GOOGLE_ADS_CLIENT_ID` / `GOOGLE_ADS_CLIENT_SECRET` / `GOOGLE_ADS_REFRESH_TOKEN` も、この前提で運用

### wp-config.php 定数

| 定数 | 説明 |
|---|---|
| `GOOGLE_ADS_DEVELOPER_TOKEN` | Google Ads API 開発者トークン |
| `GOOGLE_ADS_CLIENT_ID` | OAuth クライアント ID |
| `GOOGLE_ADS_CLIENT_SECRET` | OAuth クライアントシークレット |
| `GOOGLE_ADS_REFRESH_TOKEN` | OAuth リフレッシュトークン |
| `GOOGLE_ADS_LOGIN_CUSTOMER_ID` | MCC アカウント ID（ハイフンなし） |
| `GOOGLE_ADS_CUSTOMER_ID` | 対象広告アカウント ID（ハイフンなし） |

### 運用方針

- Google Ads API は当面 `GCREV INSIGHT` で管理する
- すでに設定済みの Developer Token / OAuth / Refresh Token をそのまま使用する
- 無理に `Mimamori Web Production` へ移行しない

---

## 構成サマリー

### 本番側

| 項目 | 内容 |
|---|---|
| GCP プロジェクト | `Mimamori Web Production` |
| 主用途 | サービスアカウント、Vertex AI、GA4、Search Console |

### OAuth / 外部連携側

| 項目 | 内容 |
|---|---|
| GCP プロジェクト | `GCREV INSIGHT` |
| 主用途 | GBP OAuth、Google Ads API / OAuth、開発環境用 Google 連携 |

---

## 注意事項

- 本番のサービスアカウント系と OAuth 系で、使用プロジェクトが分かれている
- **勝手に統合・移行・削除しないこと**
- プロジェクト名変更は後回しでよい
- 将来的に整理する場合は、以下を改めて検討する:
  - OAuth 系を `GCREV INSIGHT` のまま維持するか
  - 本番用の OAuth 基盤を別途 `Mimamori Web Production` に作るか

---

## Claude Code への指示

Claude Code で GCP / OAuth / Google 連携まわりの修正を行う際は、以下を前提とすること。

1. **本番の GCP 基盤**は `Mimamori Web Production`
2. **GBP OAuth** は `GCREV INSIGHT`
3. **Google Ads API** も当面 `GCREV INSIGHT`
4. 本番 / 開発 / OAuth の役割が分かれているため、**構成変更は必ず事前確認のうえ行うこと**
5. secret / token / key の値をコード・ログ・ドキュメントに含めないこと
