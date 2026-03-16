# CLAUDE.md — みまもりウェブ

> Claude Code が「どこを見て、どこを直すか」を即座に判断するための地図。

---

## 1. Overview

WordPress テーマ「みまもりウェブ」。GA4 / GSC / GBP データを集約し、クライアント向けダッシュボード・分析・月次レポート・AIチャットを提供する SaaS 型テーマ。

**設計思想**: 表示は `page-*.php`、ロジックは REST API（`inc/`）で分離。

---

## 2. Quick Index — 作業開始時にまず見る場所

| やりたいこと | 見るファイル |
|---|---|
| REST API を追加・修正 | `inc/class-gcrev-api.php` → `register_routes()` |
| GA4/GSC データ取得を変更 | `inc/gcrev-api/modules/class-ga4-fetcher.php`, `class-gsc-fetcher.php` |
| 管理画面を追加・修正 | `inc/gcrev-api/admin/class-*-page.php` |
| 月次レポート生成を変更 | `inc/gcrev-api/modules/class-report-generator.php`, `class-monthly-report-service.php` |
| AIチャットを修正 | `functions.php`（`mimamori_process_chat_with_trace`） |
| UI テンプレートを変更 | `page-*.php` + `template-parts/` |
| JS を修正 | `assets/js/` |
| CSS を修正 | `sass/` → `compass compile` → `css/` |
| Cron/定期処理 | `inc/gcrev-api/class-gcrev-bootstrap.php` |
| 権限・設定・認証 | `inc/gcrev-api/utils/class-config.php` |

---

## 3. システム構造マップ

### Level A — 大枠

```
Client（ブラウザ）
  ↓ page-*.php（表示のみ）
  ↓ REST API（fetch）
  ↓ Modules（ビジネスロジック）
  ↓ Data Store（DB / Transient / Post Meta）
  ↓ External APIs（GA4 / GSC / GBP / Gemini）
```

### Level B — ディレクトリと代表ファイル

```
wp/
├── functions.php                      # テーマ設定・CPT・ヘルパー・AIチャット・実績CV系REST
├── style.css                          # テーマメタ情報
│
├── inc/
│   ├── class-gcrev-api.php            # REST API メインクラス（52エンドポイント, 7600行）
│   └── gcrev-api/
│       ├── class-gcrev-bootstrap.php  # Cron 登録・初期化
│       │
│       ├── utils/                     # ユーティリティ（15クラス）
│       │   ├── class-config.php           # SA認証・ユーザー設定・権限チェック
│       │   ├── class-date-helper.php      # 日付範囲計算
│       │   ├── class-area-detector.php    # エリア検出
│       │   ├── class-html-extractor.php   # HTML解析
│       │   ├── class-crypto.php           # 暗号化（AES-256-GCM）
│       │   ├── class-cron-logger.php      # Cron実行ログ
│       │   ├── class-db-optimizer.php     # DBインデックス最適化
│       │   ├── class-error-notifier.php   # エラー通知メール
│       │   ├── class-prefetch-scheduler.php  # スロット分散プリフェッチ
│       │   ├── class-rate-limiter.php     # APIレート制限
│       │   ├── class-ai-json-parser.php   # AI応答JSONパース
│       │   ├── class-qa-question-generator.php  # QA質問生成
│       │   ├── class-qa-scorer.php        # QAスコアリング
│       │   ├── class-qa-triage.php        # QA障害分類
│       │   └── class-qa-report-writer.php # QAレポート出力
│       │
│       ├── modules/                   # ビジネスロジック（10クラス）
│       │   ├── class-ai-client.php            # Gemini API連携
│       │   ├── class-ga4-fetcher.php          # GA4データ取得
│       │   ├── class-gsc-fetcher.php          # GSCデータ取得
│       │   ├── class-dashboard-service.php    # ダッシュボードKPI集計
│       │   ├── class-highlights.php           # ハイライト抽出
│       │   ├── class-report-generator.php     # レポート生成（マルチパス）
│       │   ├── class-report-repository.php    # レポート永続化（CPT post meta）
│       │   ├── class-monthly-report-service.php  # 月次レポートオーケストレーション
│       │   ├── class-qa-runner.php            # QA実行エンジン
│       │   └── class-updates-api.php          # 更新情報REST API
│       │
│       └── admin/                     # 管理画面（8クラス）
│           ├── class-gbp-settings-page.php          # GBP設定
│           ├── class-client-management-page.php     # クライアント管理
│           ├── class-cron-monitor-page.php          # Cronモニター
│           ├── class-cv-settings-page.php           # CV設定
│           ├── class-deploy-page.php                # デプロイ管理（dev限定）
│           ├── class-notification-settings-page.php # 通知設定
│           ├── class-payment-settings-page.php      # 決済設定
│           └── class-qa-report-page.php             # QAレポート
│
├── inc/cli/                           # WP-CLI コマンド
│   ├── class-gcrev-cli.php            # 汎用CLI（キャッシュ・トークン等）
│   └── class-mimamori-qa-cli.php      # QA専用CLI
│
├── page-*.php                         # ページテンプレート（27ファイル）
├── template-parts/                    # 再利用UIコンポーネント
│   ├── period-selector.php                # 期間セレクター
│   ├── mimamori-ai-chat.php               # AIチャット
│   └── analysis-help.php                  # ヘルプ表示
│
├── assets/
│   ├── js/                            # JSモジュール
│   │   ├── period-selector.js             # 期間選択
│   │   ├── period-display.js              # 期間表示
│   │   ├── gcrev-legend-solo.js           # チャート凡例
│   │   ├── mimamori-ai-chat.js            # AIチャットUI
│   │   ├── mimamori-account-menu.js       # アカウントメニュー
│   │   └── mimamori-updates-bell.js       # 更新通知ベル
│   └── css/                           # JS付随CSS
│
├── sass/                              # SASSソース（6ファイル）
├── css/                               # コンパイル済みCSS（10ファイル）
├── config.rb                          # Compass設定
│
├── scripts/                           # シェルスクリプト
│   ├── deploy.sh                          # Dev→Prod デプロイ
│   ├── rollback.sh                        # ロールバック
│   ├── snapshot.sh                        # バックアップ
│   └── qa-nightly.sh                      # QA夜間バッチ
│
├── .github/workflows/                 # GitHub Actions
│   ├── deploy-dev.yml                     # main push → Dev自動デプロイ
│   └── deploy-prod.yml                    # 手動 → Prod デプロイ + タグ作成
│
├── header.php / footer.php / sidebar.php
└── reference/                         # HTMLプロトタイプ（参照のみ）
```

---

## 4. 重要ファイル・ディレクトリ（サーバー側）

### wp-config.php

| 項目 | 詳細 |
|---|---|
| **場所** | `/home/kusanagi/mimamori-dev/DocumentRoot/wp-config.php` |
| **役割** | WordPress設定 + みまもりウェブ固有定数 |
| **注意** | Dev/Prod で値が異なる。直接編集時はバックアップ必須 |

**みまもりウェブ固有の定数:**

| 定数 | 用途 |
|---|---|
| `GCREV_VENDOR_PATH` | Composer vendor ディレクトリパス |
| `GCREV_SA_PATH` | GCPサービスアカウントJSONパス |
| `GCREV_GCP_PROJECT_ID` | GCPプロジェクトID（省略時SA JSONから自動取得） |
| `GCREV_GCP_LOCATION` | Vertex AIリージョン（省略時 `us-central1`） |
| `MIMAMORI_ENV` | `development` or `production` |
| `MIMAMORI_PROD_THEME_PATH` | 本番テーマパス（dev のみ） |
| `MIMAMORI_SNAPSHOT_DIR` | スナップショット保存先（dev のみ） |
| `MIMAMORI_SCRIPTS_DIR` | スクリプトディレクトリ（dev のみ） |
| `MIMAMORI_UPDATES_INGEST_TOKEN` | 更新情報APIトークン |
| `GCREV_ENCRYPTION_KEY` | トークン暗号化キー（Base64, 32byte） |

### サービスアカウントJSON

| 項目 | 詳細 |
|---|---|
| **場所** | `/home/kusanagi/mimamori-dev/secrets/gcrev-insight-fd0cc85fabe2.json` |
| **役割** | GA4 / GSC / Vertex AI (Gemini) の認証 |
| **参照箇所** | `class-config.php` → `get_sa_path()` |
| **絶対禁止** | 内容をコード・ログ・出力に含めないこと |

### Composer vendor

| 項目 | 詳細 |
|---|---|
| **場所** | `/home/kusanagi/mimamori-dev/gcrev-insight/vendor/` |
| **役割** | Google APIs / Gemini SDK 等の PHP ライブラリ |
| **読込** | `inc/class-gcrev-api.php` 冒頭で `GCREV_VENDOR_PATH` → fallback |
| **原則** | 手動編集禁止。更新は `composer update` で |
| **composer.json** | ローカルの `/gcrev-insight/composer.json` で管理 |

### テーマディレクトリ（デプロイ先）

| 環境 | パス |
|---|---|
| **Dev** | `/home/kusanagi/mimamori-dev/DocumentRoot/wp-content/themes/mimamori/` |
| **Prod** | `/home/kusanagi/mimamori/DocumentRoot/wp-content/themes/mimamori/` |

---

## 5. REST API

### 名前空間

| 名前空間 | 用途 | エンドポイント数 |
|---|---|---|
| `gcrev_insights/v1` | ダッシュボード・レポート・CV管理 | 21 |
| `gcrev/v1` | 分析・MEO・CV Review | 25 |
| `mimamori/v1` | AIチャット・音声・更新情報 | 6 |

### 権限パターン

| パターン | 対象 | チェック方法 |
|---|---|---|
| 一般ユーザー | ほとんどのエンドポイント | `Gcrev_Config::check_permission()` → `is_user_logged_in()` |
| 管理者限定 | キャッシュ全削除・ユーザー一覧等 | `current_user_can('manage_options')` |
| 本人 or 管理者 | 実績CV編集 | `gcrev_can_edit_actual_cv($user_id)` |
| トークン認証 | 更新情報 ingest | `check_ingest_token()` ヘッダー検証 |

**Nonce は使わない**（REST `permission_callback` パターンで統一）。

### 主要エンドポイント一覧

<details>
<summary>gcrev_insights/v1（クリックで展開）</summary>

| ルート | メソッド | 概要 |
|---|---|---|
| `/dashboard` | GET | ダッシュボードデータ |
| `/kpi` | GET | KPIトレンド |
| `/clear-cache` | POST | 全キャッシュ削除（管理者） |
| `/clear-my-cache` | POST | 自分のキャッシュ削除 |
| `/save-client-info` | POST | クライアント情報保存 |
| `/save-client-settings` | POST | クライアント設定保存 |
| `/generate-persona` | POST | ペルソナ生成（Gemini） |
| `/report/generation-count` | GET | レポート生成回数 |
| `/generate-report` | POST | レポート生成（マルチパス） |
| `/report/reset-generation-count` | POST | 生成回数リセット（管理者） |
| `/report/current` | GET | 今月のレポート |
| `/report/history` | GET | レポート履歴 |
| `/report/(?P<report_id>\d+)` | GET | 特定レポート取得 |
| `/actual-cv` | GET/POST | 実績CV取得・保存 |
| `/actual-cv/users` | GET | CV全ユーザー（管理者） |
| `/actual-cv/routes` | GET/POST | CV経路取得・保存 |
| `/ga4-key-events` | GET | GA4キーイベント一覧 |

</details>

<details>
<summary>gcrev/v1（クリックで展開）</summary>

| ルート | メソッド | 概要 |
|---|---|---|
| `/dashboard/kpi` | GET | ダッシュボードKPI v2 |
| `/dashboard/trends` | GET | 12ヶ月メトリクストレンド |
| `/dashboard/drilldown` | GET | ドリルダウン（地域/ページ/ソース） |
| `/analysis/source` | GET | 流入元分析 |
| `/analysis/region` | GET | 地域分析 |
| `/analysis/region-trend` | GET | 地域トレンド |
| `/analysis/page` | GET | ページ分析 |
| `/analysis/keywords` | GET | 検索キーワード分析（GSC） |
| `/analysis/cv` | GET | CV分析 |
| `/meo/dashboard` | GET | MEOダッシュボード |
| `/meo/location` | POST | MEOロケーション保存 |
| `/meo/location-id` | POST | MEOロケーションID設定 |
| `/meo/gbp-locations` | GET | GBPロケーション一覧 |
| `/meo/select-location` | POST | GBPロケーション選択 |
| `/report/generate-manual` | POST | レポート手動生成 |
| `/report/check-prev2-data` | GET | 前々月データ確認 |
| `/cv-review` | GET | CVレビュー取得 |
| `/cv-review/update` | POST | CVレビュー更新 |
| `/cv-review/bulk-update` | POST | CVレビュー一括更新 |

</details>

<details>
<summary>mimamori/v1（クリックで展開）</summary>

| ルート | メソッド | 概要 |
|---|---|---|
| `/ai-chat` | POST | AIチャット（マルチターン対応） |
| `/voice-transcribe` | POST | 音声テキスト変換 |
| `/updates` | GET | 更新情報一覧 |
| `/updates/unread-count` | GET | 未読数 |
| `/updates/mark-read` | POST | 既読にする |
| `/updates/ingest` | POST | 更新情報登録（GitHub Actions用） |

</details>

### API 追加・修正時のチェックリスト

- [ ] `permission_callback` を設定（`__return_true` 禁止）
- [ ] 入力: `sanitize_text_field()`, `absint()` 等で必ずサニタイズ
- [ ] SQL: `$wpdb->prepare()` 必須（例外なし）
- [ ] 出力: `wp_json_encode($data, JSON_UNESCAPED_UNICODE)`
- [ ] エスケープ: HTML出力には `esc_html()`, `esc_attr()`, `esc_url()`
- [ ] バリデーション: 日付は `/^\d{4}-\d{2}$/` 等の正規表現
- [ ] 正規表現に変数: `preg_quote()` 必須

---

## 6. データ（DB・キャッシュ・Cron）

### カスタムテーブル

| テーブル | 用途 | 定義場所 |
|---|---|---|
| `{prefix}gcrev_actual_cvs` | 実績CV記録 | `functions.php:4556` |
| `{prefix}gcrev_cv_routes` | CV経路設定 | `functions.php:4534` |
| `{prefix}gcrev_cv_review` | CVレビューログ | `functions.php:4586` |
| `{prefix}gcrev_cron_logs` | Cronジョブログ | `class-cron-logger.php:51` |
| `{prefix}gcrev_cron_log_details` | Cronユーザー詳細 | `class-cron-logger.php:68` |

- テーブル作成: `dbDelta()` — `after_setup_theme` フックで実行
- クエリ: `$wpdb->prepare()` 必須
- Upsert: `$wpdb->replace()`

### カスタム投稿タイプ

| CPT | 用途 |
|---|---|
| `news` | お知らせ（public） |
| `mimamori_update` | 更新履歴（non-public） |

### Transient キャッシュ

| プレフィックス | 用途 | TTL |
|---|---|---|
| `gcrev_dash_{user_id}_{range}` | ダッシュボード | 24h（90d/180d/365d は 48h） |
| `gcrev_report_{user_id}_*` | レポートHTML | 可変 |
| `gcrev_effcv_{user_id}_{month}` | 実績CV月次集計 | 2h |
| `gcrev_phone_tap_*` | 電話タップCV | — |
| `gcrev_ga4cv_*` | GA4 CV | — |
| `gcrev_source_*` | 流入元分析 | — |
| `gcrev_region_*` | 地域分析 | — |
| `gcrev_keywords_*` | キーワード分析 | — |
| `gcrev_meo_*` | MEOデータ | — |
| `gcrev_trend_*` | メトリクストレンド | — |
| `gcrev_lock_*` | 重複実行防止ロック | 2h |

**キャッシュ無効化:**

| 関数 | 場所 | 削除対象 |
|---|---|---|
| `gcrev_invalidate_user_cv_cache($user_id)` | `functions.php:4877` | dash / report / effcv |
| `invalidate_effective_cv_transients($user_id)` | `class-gcrev-api.php:526` | effcv |

### Cron ジョブ

> **重要**: Cron は `MIMAMORI_ENV === 'production'` でのみ登録される。Dev 環境では動かない。

| イベント | 時刻 | 目的 | 関連クラス |
|---|---|---|---|
| `gcrev_prefetch_daily_event` | 03:10 | GA4/GSCデータ先読み | `Bootstrap` → `API::prefetch_chunk()` |
| `gcrev_monthly_report_generate_event` | 04:00 | 月次レポート自動生成 | `Bootstrap` → `API::auto_generate_monthly_reports()` |
| `gcrev_monthly_report_finalize_event` | 23:00 | レポート確定・公開 | `Bootstrap` → `API::auto_finalize_monthly_reports()` |
| `gcrev_cron_log_cleanup_event` | 02:00 | 90日超ログ削除 | `Bootstrap` → `Cron_Logger::cleanup_old(90)` |

**スロット分散プリフェッチ** (`Gcrev_Prefetch_Scheduler`):
- 4スロット × 30分間隔（03:10, 03:40, 04:10, 04:40）
- ユーザー割当: `user_id % slot_count`
- チャンク処理: 5ユーザー/チャンク、自己チェーン

---

## 7. コーディング規約（MUST FOLLOW）

### 絶対にやってはいけないこと

| 禁止事項 | 理由 |
|---|---|
| `permission_callback => '__return_true'` | 認証なしAPI公開 |
| `$wpdb->query()` で直接変数埋め込み | SQLインジェクション |
| `echo $variable` のエスケープなし出力 | XSS |
| `json_encode()` を使う | `wp_json_encode($data, JSON_UNESCAPED_UNICODE)` を使う |
| 正規表現に `preg_quote()` なしで変数 | ReDoS・意図しないマッチ |
| `date()` / `new DateTime()` | `wp_timezone()` + `DateTimeImmutable` を使う |
| 新ファイル不要な場面でファイル作成 | 既存ファイルへの差分変更を優先 |
| `error_log()` をデバッグに使う | KUSANAGI環境で出力されない。`file_put_contents` を使う（後述§7.1） |

### セキュリティ — WordPress 慣習厳守

- 出力: `esc_html()`, `esc_attr()`, `esc_url()`
- SQL: `$wpdb->prepare()` 必須
- 入力: `sanitize_text_field()`, `absint()`, etc.
- REST: `permission_callback` 必ず設定
- JSON: `wp_json_encode($data, JSON_UNESCAPED_UNICODE)`

### 7.1 デバッグ・ログ出力ルール（MUST FOLLOW）

#### KUSANAGI 環境では `error_log()` を使わない

KUSANAGI + PHP-FPM 環境では `error_log()` の出力先が不定で、REST APIハンドラーや Cron コールバックから呼んでも **ログが一切出力されない** ことがある。デバッグ目的のログは以下のパターンを使うこと。

#### 正しいデバッグログの書き方

```php
// 基本形
file_put_contents('/tmp/gcrev_<機能名>_debug.log',
    date('Y-m-d H:i:s') . " <メッセージ>\n",
    FILE_APPEND
);

// 例: GBP API デバッグ
file_put_contents('/tmp/gcrev_gbp_debug.log',
    date('Y-m-d H:i:s') . " fetch_metrics: status={$status}, body=" . substr($body, 0, 500) . "\n",
    FILE_APPEND
);

// 例: 配列・オブジェクトの出力
file_put_contents('/tmp/gcrev_gbp_debug.log',
    date('Y-m-d H:i:s') . " response: " . wp_json_encode($data, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND
);
```

#### ファイル名規約

| ログファイル | 用途 |
|---|---|
| `/tmp/gcrev_gbp_debug.log` | GBP/MEO API デバッグ |
| `/tmp/gcrev_ga4_debug.log` | GA4 API デバッグ |
| `/tmp/gcrev_gsc_debug.log` | GSC API デバッグ |
| `/tmp/gcrev_report_debug.log` | レポート生成デバッグ |
| `/tmp/gcrev_chat_debug.log` | AIチャット デバッグ |
| `/tmp/gcrev_cron_debug.log` | Cron 処理デバッグ |

**命名パターン**: `/tmp/gcrev_<機能名>_debug.log`

#### ログ出力時の注意事項

1. **タイムスタンプ必須**: 各行に `date('Y-m-d H:i:s')` を付ける
2. **FILE_APPEND**: 常に追記モード。上書き（`FILE_APPEND` なし）は禁止
3. **レスポンスボディは切り詰める**: 巨大なレスポンスは `substr($body, 0, 500)` 等で先頭だけ出力
4. **機密情報の出力禁止**: アクセストークン、シークレット、パスワードは絶対にログに含めない
5. **デバッグログは原則残す**: 外部API呼び出しのエラーパスには常にログを入れておく（正常パスのログは不要）

#### 外部API呼び出しには必ずエラーログを入れる

```php
$response = wp_remote_get($url, [...]);
if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
    $code = is_wp_error($response)
        ? $response->get_error_message()
        : wp_remote_retrieve_response_code($response);
    file_put_contents('/tmp/gcrev_<機能名>_debug.log',
        date('Y-m-d H:i:s') . " API ERROR: code={$code}, url={$url}\n"
        . substr(wp_remote_retrieve_body($response), 0, 500) . "\n",
        FILE_APPEND
    );
    return []; // or appropriate fallback
}
```

**これは「あると便利」ではなく必須**。外部APIの呼び出しでエラーログがないと、障害時にサーバーログを読んでも何が起きたか分からず、毎回デバッグコードの追加→デプロイ→再現を繰り返すことになる。

#### サーバーでのログ確認

```bash
# リアルタイム監視
tail -f /tmp/gcrev_gbp_debug.log

# 直近のエラーを確認
cat /tmp/gcrev_gbp_debug.log

# ログファイル削除（蓄積が不要になったら）
rm /tmp/gcrev_*_debug.log
```

#### 本番環境のログ

| ログ | パス | 備考 |
|---|---|---|
| WordPress debug.log | `wp-content/debug.log` | `WP_DEBUG_LOG=true` 時のみ |
| PHP-FPM error log | `/var/opt/kusanagi/log/php-fpm/www-error.log` | PHP Fatal等 |
| デバッグ用一時ログ | `/tmp/gcrev_*_debug.log` | 上記パターンで手動作成 |
| QA夜間バッチログ | `/home/kusanagi/mimamori-dev/logs/qa-nightly.log` | crontab出力 |

#### OPcache による反映遅延

コードをデプロイしても OPcache が古いバイトコードを返す場合がある。ログが期待通りに出ない場合はまず OPcache をクリアする：

```bash
systemctl restart php-fpm
```

### Diff-based Changes

- 既存の構造・パターンを壊さない
- 差分ベースで最小限の変更
- 新ファイル作成は必要な場合のみ

### PHP Style

- クラスファイル命名: `class-{name}.php`（WordPress 慣習）
- タイムゾーン: `wp_timezone()` を使用
- バリデーション: 正規表現パターン（例: `/^\d{4}-\d{2}$/`）
- 日本語: `JSON_UNESCAPED_UNICODE` 必須

### Templates (page-*.php)

- 未ログインチェック: `if (!is_user_logged_in()) { wp_safe_redirect(...); exit; }`
- パンくず用: `set_query_var()` でタイトル等を渡す
- テキスト装飾はテンプレート内の関数で処理

### Assets

- JS: `assets/js/` に配置、`wp_enqueue_script()` で読み込み
- CSS: `sass/` → `css/` Compass ビルド（`config.rb`）
- JS namespace: `window.GCREV`
- カスタムイベント: `gcrev:periodChange`

### ワークフロー（MUST FOLLOW）

- **作業完了時は必ず `git commit` & `git push` する**（ユーザーに確認せず自動で実行）
- push 先は `main` ブランチ → GitHub Actions で Dev サーバーに自動デプロイされる

---

## 8. 典型タスク別：変更箇所ガイド

### 8-1. 新しい管理画面 / 設定項目を増やす

| 手順 | ファイル |
|---|---|
| 1. 管理ページクラス作成 | `inc/gcrev-api/admin/class-{name}-page.php` |
| 2. メニュー登録 | クラス内 `add_submenu_page('gcrev-insight', ...)` |
| 3. Bootstrap で読み込み | `class-gcrev-bootstrap.php` の `require_once` に追加 |

**注意**: メニュー親スラッグは `gcrev-insight`（内部識別子、変更不可）。

### 8-2. 新しい REST エンドポイントを増やす

| 手順 | ファイル |
|---|---|
| 1. ルート登録 | `inc/class-gcrev-api.php` → `register_routes()` |
| 2. コールバック実装 | 同ファイル内にメソッド追加 |
| 3. 権限設定 | `permission_callback` を必ず設定 |

**チェック**: §5 のチェックリスト参照。

### 8-3. GA4 / GSC / GBP 集計ロジックを修正する

| 対象 | ファイル |
|---|---|
| GA4 データ取得 | `inc/gcrev-api/modules/class-ga4-fetcher.php` |
| GSC データ取得 | `inc/gcrev-api/modules/class-gsc-fetcher.php` |
| GBP/MEO | `inc/class-gcrev-api.php` 内の `rest_get_meo_*` メソッド |
| KPI 集計 | `inc/gcrev-api/modules/class-dashboard-service.php` |

**注意**: API変更後はキャッシュ Transient のプレフィックス・TTL を確認（§6 参照）。

### 8-4. 月次レポートの保存 / 表示を変える

| 対象 | ファイル |
|---|---|
| レポート生成ロジック | `inc/gcrev-api/modules/class-report-generator.php` |
| レポート保存（CPT） | `inc/gcrev-api/modules/class-report-repository.php` |
| 月次オーケストレーション | `inc/gcrev-api/modules/class-monthly-report-service.php` |
| ハイライト抽出 | `inc/gcrev-api/modules/class-highlights.php` |
| 表示テンプレート | `page-report-latest.php`, `page-report-archive.php` |

### 8-5. UI（テンプレート / template-parts）を変更する

| 対象 | ファイル |
|---|---|
| ダッシュボード | `page-dashboard.php` |
| 分析ページ | `page-analysis-*.php`（7ファイル） |
| AIチャット UI | `template-parts/mimamori-ai-chat.php` + `assets/js/mimamori-ai-chat.js` |
| 期間セレクター | `template-parts/period-selector.php` + `assets/js/period-selector.js` |
| ヘッダー/フッター | `header.php`, `footer.php` |

**注意**: ページテンプレートにはロジックを書かない。データは REST API 経由で取得。

### 8-6. JS のイベント / period-selector 周りを触る

| 対象 | ファイル |
|---|---|
| 期間セレクター | `assets/js/period-selector.js` |
| カスタムイベント | `gcrev:periodChange` — 期間変更時に発火 |
| AIチャット | `assets/js/mimamori-ai-chat.js` |
| 更新通知 | `assets/js/mimamori-updates-bell.js` |

**namespace**: `window.GCREV`

### 8-7. SASS → CSS ビルド

```bash
# 開発時（ファイル監視）
compass watch

# ビルド
compass compile

# 設定ファイル: config.rb
# ソース: sass/ → 出力: css/
# output_style: expanded（本番も expanded）
```

**注意**: コンパイル後の `css/` は Git にコミットする。`sass/` はデプロイ対象外。

### 8-8. AIチャットを修正する

| 対象 | ファイル |
|---|---|
| チャット処理パイプライン | `functions.php` → `mimamori_process_chat_with_trace()` |
| フォローアップ解決 | `functions.php` → `mimamori_resolve_followup_context()` |
| パラメータ解決 | `functions.php` → `mimamori_resolve_params()` |
| Gemini API呼び出し | `inc/gcrev-api/modules/class-ai-client.php` |
| REST エンドポイント | `mimamori/v1/ai-chat` → `functions.php` |
| フロントエンド | `assets/js/mimamori-ai-chat.js` + `template-parts/mimamori-ai-chat.php` |

**パイプライン**: `メッセージ受信 → フォローアップ解決 → パラメータ解決 → インテント分類 → 決定的クエリ → プランナーパス → Gemini応答`

---

## 9. デプロイ

### フロー

```
ローカル開発 → git push main
  ↓ GitHub Actions (deploy-dev.yml)
Dev サーバー自動デプロイ
  ↓ 手動実行 (deploy-prod.yml or deploy.sh)
Prod サーバーデプロイ + タグ作成
```

### WP-CLI（サーバー実行）

```bash
# 基本形（root で sudo 実行）
sudo -u kusanagi /opt/kusanagi/php/bin/php /opt/kusanagi/bin/wp <command> \
  --path=/home/kusanagi/mimamori-dev/DocumentRoot

# キャッシュクリア
sudo -u kusanagi /opt/kusanagi/php/bin/php /opt/kusanagi/bin/wp cache flush \
  --path=/home/kusanagi/mimamori-dev/DocumentRoot

# QA実行（dev のみ）
sudo -u kusanagi /opt/kusanagi/php/bin/php /opt/kusanagi/bin/wp mimamori qa run \
  --user_id=1 --mode=quick --path=/home/kusanagi/mimamori-dev/DocumentRoot
```

### ドメイン

| 環境 | ドメイン |
|---|---|
| **Dev** | `dev.mimamori-web.jp`（ハイフンあり） |
| **Prod** | `mimamori-web.jp` |
| **旧ドメイン** | `dev.mimamoriweb.jp` — **使用禁止** |

---

## 10. トラブルシューティング

### REST API が 401/403 を返す

1. ログイン状態を確認（`is_user_logged_in()` が false の可能性）
2. `Gcrev_Config::check_permission()` の条件を確認
3. 管理者限定エンドポイントに一般ユーザーでアクセスしていないか
4. Cookieベース認証: `wp_rest` nonce 不要だが、Cookie は必要

### Cron が動かない

1. `MIMAMORI_ENV` が `production` か確認（dev では登録されない）
2. `wp cron event list` でイベント登録状況を確認
3. ロックトランジェント `gcrev_lock_*` が残っていないか（TTL: 2h）
4. `class-gcrev-bootstrap.php` の `maybe_schedule_events()` を確認

### キャッシュが効きすぎて反映されない

1. `gcrev_invalidate_user_cv_cache($user_id)` を呼ぶ
2. Transient を直接削除: `wp transient delete --all`
3. TTL 確認: ダッシュボード 24h, 長期間 48h, 実績CV 2h
4. OPcache: `systemctl restart php-fpm`

**重要**: APIレスポンス構造を変更した場合、古い形式のキャッシュが残っているとフロントエンドで表示崩れ・データ欠落が起きる。構造変更後は必ずサーバーでTransientをクリアすること：

```bash
sudo -u kusanagi /opt/kusanagi/php/bin/php /opt/kusanagi/bin/wp transient delete --all \
  --path=/home/kusanagi/mimamori-dev/DocumentRoot
```

### デバッグログが出力されない

1. `error_log()` はKUSANAGI環境で効かない → `file_put_contents('/tmp/gcrev_*_debug.log', ..., FILE_APPEND)` を使う（§7.1 参照）
2. OPcacheが古いコードをキャッシュしている → `systemctl restart php-fpm`
3. デプロイ後にパーミッション不足 → `chown -R kusanagi:kusanagi /home/kusanagi/mimamori-dev/DocumentRoot/wp-content/themes/mimamori/`

### ログインリダイレクトがおかしい

1. WP-Members 設定: ログイン後 → `/mypage/dashboard/`
2. `page-*.php` の未ログインチェック: `wp_safe_redirect(home_url('/'))` を確認
3. SSL リダイレクトループ: KUSANAGI の nginx 設定を確認

### vendor/autoload.php が見つからない

1. `GCREV_VENDOR_PATH` 定数が wp-config.php に正しく設定されているか
2. fallback: `dirname(__DIR__, 2) . '/vendor/autoload.php'` のパスを確認
3. `composer install` を実行して vendor を再構築

---

## 11. QA（品質管理）

### 夜間バッチ

- **スクリプト**: `scripts/qa-nightly.sh`（crontab で 03:30 実行）
- **モード**: `nightly` = 100問 / `quick` = 5問
- **出力先**: `wp-content/uploads/mimamori/qa_runs/`
- **自動削除**: 30日超の実行データ
- **環境制限**: `MIMAMORI_ENV=development` のみ

### QA → 品質改善フロー

```
Dev: QA夜間バッチ → レポート確認（管理画面）→ コード修正
  ↓ デプロイ
Prod: クライアントのAIチャット品質が向上
```

---

## 12. ビルド・Lint

```bash
# SASS コンパイル
compass watch    # 開発時
compass compile  # ビルド

# PHP 構文チェック
php -l <file>
```

---

## 付録: ページ階層（固定ページ URL 構造）

- **`/mypage/` 階層は廃止済み**。全ページはルート直下（`/slug/`）で運用する。
- 新しいページテンプレートを追加した場合は、WordPress 管理画面で固定ページを作成し、テンプレートを割り当てる。

| ページ | URL | テンプレート |
|---|---|---|
| ダッシュボード | `/dashboard/` | `page-dashboard.php` |
| 順位トラッキング | `/rank-tracker/` | `page-rank-tracker.php` |
| AI検索スコア | `/aio-score/` | `page-aio-score.php` |
| レポート | `/report-latest/` | `page-report-latest.php` |

## 付録: WP-Members

- ログイン後リダイレクト先: `/dashboard/`
- 登録: `page-register.php` → `[wpmem_form register]`

## 付録: 内部識別子（変更不可）

以下はコード内部の識別子であり、表示名とは無関係。変更すると破損する。

| 識別子 | 種類 | 用途 |
|---|---|---|
| `Gcrev_Insight_API` | クラス名 | メインAPIクラス |
| `gcrev_insights/v1` | REST名前空間 | v1 エンドポイント群 |
| `gcrev/v1` | REST名前空間 | v2 エンドポイント群 |
| `gcrev-insight` | メニュースラッグ | 管理画面メニュー親 |
