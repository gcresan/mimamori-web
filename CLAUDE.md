# CLAUDE.md — GCREV INSIGHT WordPress Theme

## Overview

WordPress テーマ「GCREV INSIGHT」。GA4/GSC/GBP データを集約し、クライアント向けダッシュボード・分析・月次レポートを提供する SaaS 型テーマ。

## Architecture

```
wp/
├── functions.php          # テーマ設定・CPT登録・実績CV用REST・ヘルパー関数
├── inc/
│   ├── class-gcrev-api.php              # REST APIメインクラス（25+エンドポイント）
│   └── gcrev-api/
│       ├── class-gcrev-bootstrap.php    # Cron登録・初期化
│       ├── utils/
│       │   ├── class-config.php         # サービスアカウント・ユーザー設定・権限チェック
│       │   ├── class-date-helper.php    # 日付範囲計算
│       │   ├── class-area-detector.php  # エリア検出
│       │   └── class-html-extractor.php # HTML解析
│       ├── modules/
│       │   ├── class-ai-client.php          # Gemini API連携
│       │   ├── class-ga4-fetcher.php        # GA4データ取得
│       │   ├── class-gsc-fetcher.php        # GSCデータ取得
│       │   ├── class-report-repository.php  # レポート永続化（post meta）
│       │   ├── class-report-generator.php   # レポート生成（マルチパス）
│       │   ├── class-highlights.php         # ハイライト抽出
│       │   ├── class-monthly-report-service.php # 月次レポートオーケストレーション
│       │   └── class-dashboard-service.php  # ダッシュボードKPI集計
│       └── admin/
│           └── class-gbp-settings-page.php  # GBP設定画面
├── page-*.php             # 表示専用テンプレート（16ファイル）
├── template-parts/        # 再利用可能UIコンポーネント
├── assets/js/             # JSモジュール（period-selector等）
├── sass/ → css/           # Compass/SASSビルド（config.rb）
└── header.php / footer.php / sidebar.php
```

## Key Conventions

### Separation of Concerns
- **functions.php**: テーマ設定・CPT・ヘルパー・実績CV系REST
- **inc/**: API・ビジネスロジック（クラスベース）
- **page-*.php**: 表示のみ。ロジックは REST API 経由で取得

### REST API
- 名前空間: `gcrev_insights/v1`, `gcrev/v1`
- 権限: `Gcrev_Config::check_permission()` → `is_user_logged_in()`
- 管理者限定: `current_user_can('manage_options')`
- 実績CV: `gcrev_can_edit_actual_cv($user_id)` → 本人 OR admin
- Nonce は使わない（REST permission_callback パターン）

### Database
- カスタムテーブル: `{prefix}gcrev_actual_cvs`, `{prefix}gcrev_cv_routes`
- スキーマ作成: `dbDelta()`
- クエリ: 必ず `$wpdb->prepare()` を使用
- Upsert: `$wpdb->replace()`

### Caching
- Transient ベース（プレフィックス: `gcrev_dash_`, `gcrev_report_`, `gcrev_effcv_`）
- TTL: ダッシュボード 24h（180d/365d は 48h）
- 無効化: `gcrev_invalidate_user_cv_cache()`

### Cron Jobs
- `gcrev_prefetch_daily_event` — 03:10 daily
- `gcrev_monthly_report_generate_event` — 04:00 daily
- `gcrev_monthly_report_finalize_event` — 23:00 daily

## Coding Rules (MUST FOLLOW)

### Security — WordPress 慣習厳守
- 出力エスケープ: `esc_html()`, `esc_attr()`, `esc_url()`
- SQL: `$wpdb->prepare()` 必須（例外なし）
- 入力サニタイズ: `sanitize_text_field()`, `absint()`, etc.
- 正規表現に変数を入れる場合: `preg_quote()` 必須
- REST 権限: `permission_callback` を必ず設定（`__return_true` 禁止）
- JSON出力: `wp_json_encode($data, JSON_UNESCAPED_UNICODE)`

### Diff-based Changes
- 既存の構造・パターンを壊さない
- 差分ベースで最小限の変更
- 新ファイル作成は必要な場合のみ

### PHP Style
- クラスファイル命名: `class-{name}.php`（WordPress 慣習）
- 日本語 Unicode 保持: `JSON_UNESCAPED_UNICODE`
- タイムゾーン: `wp_timezone()` を使用
- バリデーション: 正規表現パターン（例: `/^\d{4}-\d{2}$/` for month）

### Templates (page-*.php)
- 未ログインチェック: `if (!is_user_logged_in()) { wp_safe_redirect(...); exit; }`
- パンくず用: `set_query_var()` でタイトル等を渡す
- テキスト装飾はテンプレート内の関数で処理

### Assets
- JS: `assets/js/` に配置、`wp_enqueue_script()` で読み込み
- CSS: `sass/` → `css/` Compass ビルド（`config.rb`）
- JS namespace: `window.GCREV`
- カスタムイベント: `gcrev:periodChange`

## Build

```bash
# SASS コンパイル（Compass）
compass watch    # 開発時
compass compile  # ビルド

# PHP lint
php -l <file>
```

## Data Flow

```
Client → page-*.php (表示) → REST API (fetch) → Modules → Data Store
                                                    ↓
                                              GA4 / GSC / Gemini
```

## Custom Post Types
- `news` — お知らせ

## WP-Members Integration
- ログイン後リダイレクト先: `/mypage/dashboard/`
- 登録: `page-register.php` → `[wpmem_form register]`
