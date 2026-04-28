# 戦略連動型 月次レポート機能 — 実装設計書

> **位置づけ**: 「数値ダッシュボード」を「経営判断レポート」に進化させる中核機能。
> **ステータス**: PR1（DB基盤・スキーマ・リポジトリ）コミット `97167ae` で完了済み。本書は **PR2 以降の設計書**。
> **基本方針**: 既存月次レポート（`gcrev_report_queue` / `class-ai-client.php` / Cron基盤）を再利用し、レイヤを増やすのではなく **新しい job_type を追加するだけ** で動かす。

---

## 0. 用語と前提

| 用語 | 意味 |
|---|---|
| **戦略 (strategy)** | クライアントごとの企画書相当のデータ（ターゲット・課題・差別化等）。`gcrev_client_strategy` に保存されたバージョン管理付き JSON。 |
| **戦略レポート (strategy report)** | 戦略 × 月次データ × AI で生成された「経営者向けの月次アクション提案」。`gcrev_strategy_reports` に保存。 |
| **数値月次レポート (monthly report)** | 既存の HTML レポート（`gcrev_report` CPT）。本機能で **置き換えない** — 並走させる。 |
| **PR1** | 実装済みの基盤（テーブル・Validator・Repository）。 |

**AIベンダー方針**: 既存の `Gcrev_AI_Client::call_gemini_api()`（Vertex AI Gemini）を本機能のデフォルトにする。
ユーザー指示文中の「Claude API」は **プロンプト雛形のラベル** として扱い、実装は Gemini を呼ぶ。
理由：① 認証・トークン・課金の運用が確立済み、② AI Client にプロバイダ切替の余地（OpenAI フォールバック）が既にある、③ 新規プロバイダ追加は本機能の本質ではない。
将来 Claude を足したくなったら `class-ai-client.php` に `call_claude_api()` メソッドを増やすだけで本機能側は無改修。

---

## 1. 機能仕様書

### 1.1 機能スコープ

| # | 機能 | 入口 | 利用者 |
|---|---|---|---|
| F1 | 戦略の登録・編集（手動） | 管理画面「戦略設定」 | 管理者 |
| F2 | 戦略の登録・編集（PDF取込） | 管理画面「戦略設定」→ PDFアップロード | 管理者 |
| F3 | 戦略のバージョン管理（draft / active / archived） | 同上 | 管理者 |
| F4 | 戦略レポート月次自動生成 | Cron（毎月5日 04:30） | システム |
| F5 | 戦略レポート手動生成（やり直し） | 管理画面「クライアント管理」 + クライアント側ページ | 管理者・クライアント本人 |
| F6 | 戦略レポート閲覧 | クライアント側ページ `/strategy-report/` | クライアント |
| F7 | 戦略レポート生成キュー監視 | 管理画面「レポートキュー」 | 管理者 |

### 1.2 ステータス遷移

#### 戦略 (`gcrev_client_strategy.status`)

```
draft ──(activate)──► active ──(新版が active になる)──► archived
  │                     │
  └─(delete)             └─ effective_until = 新版 effective_from の前日
```

- `draft`: 編集中（ユーザーごとに複数可・物理削除可）
- `active`: 有効中（ユーザーごとに1件のみ）
- `archived`: 過去版（物理削除禁止＝監査痕跡）

#### 戦略レポート (`gcrev_strategy_reports.status`)

```
pending ──► running ──► completed
   │           │
   │           └─► failed ──(リトライ)──► pending
   └─► skipped（戦略未設定 / データ不足）
```

### 1.3 月次レポート生成のトリガと前提条件

| トリガ | 条件 | 挙動 |
|---|---|---|
| 自動（Cron） | `MIMAMORI_ENV=production` かつ 毎月5日 04:30 | 全有効ユーザーをキュー投入。各ユーザーに対し「対象月時点で有効だった戦略」を `Gcrev_Strategy_Repository::get_active_for_month()` で引き、無ければ `skipped` |
| 手動（管理者） | 管理画面ボタン | 単一ユーザー × 単一月をキューに即追加（job_type=strategy_report） |
| 手動（クライアント本人） | クライアントUIボタン | 同上、ただしレート制限：同月内3回まで |

**「対象月」の解釈**: 当月5日に走る Cron は **前月分**（`year_month = 前月の YYYY-MM`）を生成する。

### 1.4 出力（戦略レポートの構造）

`gcrev_strategy_reports.report_json` に以下を保存：

```json
{
  "schema_version": "1.0",
  "year_month": "2026-03",
  "strategy_id": 42,
  "strategy_version": 3,
  "alignment_score": 64,
  "sections": {
    "conclusion": "今月の結論（経営者向けサマリー、3-5文）",
    "alignment": [
      { "topic": "...", "expected": "...", "actual": "...", "gap": "..." }
    ],
    "issues": [
      { "title": "...", "evidence": "...", "severity": "high|mid|low" }
    ],
    "causes": [
      { "issue_ref": 0, "cause": "..." }
    ],
    "actions": [
      { "title": "...", "owner": "...", "horizon": "this_month|next_month|quarter", "kpi": "..." }
    ],
    "this_month_todos": [
      { "title": "...", "due_date": "YYYY-MM-DD", "kpi": "..." }
    ]
  },
  "data_snapshot": {
    "ga4": { "...": "..." },
    "gsc": { "...": "..." },
    "meo": { "...": "..." },
    "competitors": [ "..." ]
  },
  "ai_meta": {
    "provider": "gemini",
    "model": "gemini-2.0-flash-001",
    "input_tokens": 12345,
    "output_tokens": 2345,
    "duration_ms": 18432
  }
}
```

`rendered_html` カラムには上記をテンプレート展開した HTML を別途キャッシュ（再描画コスト削減）。

### 1.5 NG / OK（実装制約）

| ❌ NG | ✅ OK |
|---|---|
| GA4/GSC の生レスポンスをそのまま AI に渡す | `format_data_for_prompt()` 相当で集計・要約してから渡す |
| REST から同期的に AI 呼び出し | キュー投入のみ。生成は Cron / バックグラウンドで |
| 出力を自由形式 | JSON スキーマ固定。逸脱したら `failed` 扱い |
| `error_log()` でデバッグ | `file_put_contents('/tmp/gcrev_strategy_debug.log', ..., FILE_APPEND)` |
| 戦略 active を直接 UPDATE | Repository 経由（archived 自動降格を経由する） |

---

## 2. DB設計

### 2.1 既存テーブル（PR1 で実装済み・本書では確認のみ）

#### `{prefix}gcrev_client_strategy`

| カラム | 型 | 用途 |
|---|---|---|
| `id` | BIGINT PK | |
| `user_id` | BIGINT | クライアント＝ WP ユーザーID |
| `version` | INT | ユーザー内通番（1, 2, 3...） |
| `status` | VARCHAR(16) | draft / active / archived |
| `source_type` | VARCHAR(16) | manual / pdf / pdf_edited |
| `source_file_id` | BIGINT NULL | アップロードPDFの WP attachment ID |
| `strategy_json` | LONGTEXT | Validator 正規化済み JSON |
| `effective_from` | DATE | 有効開始日 |
| `effective_until` | DATE NULL | 有効終了日（active は NULL） |
| `created_by` | BIGINT | 作成者 user_id |
| `created_at` / `updated_at` | DATETIME | |

**インデックス**: `idx_user_active(user_id, status, effective_from)`、`idx_user_version(user_id, version)`

#### `{prefix}gcrev_strategy_reports`

| カラム | 型 | 用途 |
|---|---|---|
| `id` | BIGINT PK | |
| `user_id` | BIGINT | |
| `year_month` | CHAR(7) | YYYY-MM |
| `strategy_id` | BIGINT | 生成時に使った戦略 |
| `status` | VARCHAR(16) | pending / running / completed / failed / skipped |
| `alignment_score` | TINYINT NULL | 0-100、戦略整合度スコア |
| `report_json` | LONGTEXT | §1.4 の構造 |
| `rendered_html` | LONGTEXT | テンプレ展開済み HTML |
| `ai_model`, `ai_input_tokens`, `ai_output_tokens` | | コスト追跡用 |
| `error_message` | TEXT | failed 時の原因 |
| `attempts` | INT | リトライ回数 |
| `generation_source` | VARCHAR(16) | cron / manual_admin / manual_user |
| `started_at` / `finished_at` / `created_at` / `updated_at` | DATETIME | |

**ユニーク制約**: `uniq_user_month(user_id, year_month)` → 同一ユーザー×同一月は1行。再生成は同じ行を UPDATE。

#### `{prefix}gcrev_report_queue`（既存・job_type 後付け済み）

PR1 で `job_type` カラムが追加済み。既存値は `monthly_report`（後方互換）、本機能は `strategy_report` を投入する。

### 2.2 本機能で追加するもの

**追加テーブルなし**。既存3テーブルで完結する。

ただし以下のオプションカラムを `gcrev_strategy_reports` に追加することを推奨（PR2初回マイグレーションで対応）：

| カラム | 型 | 用途 |
|---|---|---|
| `data_window_from` | DATE | レポート対象期間開始（再現性のため） |
| `data_window_to` | DATE | 同終了 |
| `prompt_hash` | CHAR(64) | プロンプト＋データの SHA-256。同一入力での重複生成検出 |

---

## 3. API連携処理（擬似コード）

### 3.1 REST エンドポイント一覧（追加分）

すべて `gcrev_insights/v1` 名前空間。`permission_callback` 必須。

| Method | Path | 認可 | 概要 |
|---|---|---|---|
| GET | `/strategy` | ログイン | 自分の active 戦略取得 |
| GET | `/strategy/active-for-month?year_month=YYYY-MM` | ログイン | 指定月時点の有効戦略 |
| GET | `/strategy/versions` | ログイン | 自分のバージョン履歴 |
| GET | `/strategy/draft/latest` | ログイン | 編集中 draft 復元 |
| POST | `/strategy/draft` | ログイン | draft 新規作成（手動入力 or PDF抽出結果） |
| PUT | `/strategy/draft/(?P<id>\d+)` | 本人 | draft 上書き保存 |
| DELETE | `/strategy/draft/(?P<id>\d+)` | 本人 | draft 削除 |
| POST | `/strategy/draft/(?P<id>\d+)/activate` | 本人 | draft → active 昇格（旧 active を archived に降格） |
| POST | `/strategy/extract-pdf` | ログイン | PDFアップロード → AI抽出 → draft 作成 |
| POST | `/strategy-report/generate` | ログイン | 自分の戦略レポートを今月分でキュー投入（レート制限あり） |
| GET | `/strategy-report/status?year_month=YYYY-MM` | ログイン | 生成ステータス取得 |
| GET | `/strategy-report/current` | ログイン | 最新の completed レポート |
| GET | `/strategy-report/(?P<id>\d+)` | 本人 or 管理者 | 指定レポート取得 |
| GET | `/strategy-report/history` | ログイン | 自分のレポート履歴 |
| POST | `/admin/strategy-report/generate` | 管理者 | 任意ユーザー×月でキュー投入 |
| GET | `/admin/strategy-report/queue` | 管理者 | キュー状態取得（既存 queue ページに統合） |

**登録場所**: `inc/class-gcrev-api.php` の `register_routes()` 内、L286 直後（既存 report 系ルート群の末尾）。

### 3.2 主要パイプライン擬似コード

#### 3.2.1 PDF抽出 → draft 生成

```
POST /strategy/extract-pdf
  ↓
[1] $_FILES['file'] を wp_handle_upload で WP メディアに保存
    → attachment_id 取得
[2] PDF からテキスト抽出（smalot/pdfparser を vendor 経由 or pdftotext shell）
    - vendor に既にあるか要確認。なければ shell_exec('pdftotext') を pcntl 安全に
[3] 抽出テキスト + 戦略スキーマ説明 を Gemini に投げる
    プロンプト: §4.2 の "PDF抽出プロンプト"
[4] AI 応答 JSON を Gcrev_Strategy_Schema_Validator::validate() に通す
    → 不正なら 422 で errors を返す
[5] Gcrev_Strategy_Repository::create_version(
       user_id, normalized_json,
       status='draft',
       source_type='pdf',
       source_file_id=$attachment_id,
       created_by=current_user_id()
    ) → strategy_id 取得
[6] レスポンス: { strategy_id, normalized_json, validator_warnings }
```

**ガード**:
- ファイルサイズ ≤ 20MB（nginx `client_max_body_size=100M` の範囲内）
- MIMEは `application/pdf` のみ
- 1ユーザーあたり同時アップロードは1件（transient ロック `gcrev_lock_strategy_pdf_{user_id}`、TTL 5分）

#### 3.2.2 月次自動生成（Cron）

```
[Cron] gcrev_strategy_report_generate_event @ 毎月5日 04:30
  ↓
on_strategy_report_generate_event():
    $year_month = 前月の YYYY-MM 形式
    $job_id = Cron_Logger::start('strategy_report', $year_month)
    $users = WP_User_Query で 'role__in' => ['subscriber', ...] 全件
    foreach ($users as $u):
        $strategy = Strategy_Repository::get_active_for_month($u->ID, $year_month)
        if ($strategy === null):
            $strategy_reports に status='skipped', error='no_active_strategy' で1行 INSERT
            continue
        # キュー投入
        Report_Queue::enqueue($job_id, $u->ID, $year_month,
            job_type='strategy_report',
            client_info_snapshot={ strategy_id: $strategy['id'] })
    # チャンクワーカー起動（既存パターン踏襲）
    wp_schedule_single_event(time()+30, 'gcrev_strategy_report_chunk_event',
                              [$job_id, 5])
  ↓
[Cron] gcrev_strategy_report_chunk_event($job_id, $limit=5)
  ↓
on_strategy_report_chunk_event($job_id, $limit):
    $items = Report_Queue::claim_next($job_id, $limit) WHERE job_type='strategy_report'
    foreach ($items as $q):
        try:
            Strategy_Report_Service::generate($q->user_id, $q->year_month,
                                              source='cron')
            Report_Queue::mark_success($q->id)
        catch (Exception $e):
            Report_Queue::mark_failed($q->id, $e->getMessage())
    # 残あれば自己チェーン
    if (Report_Queue::has_pending($job_id, 'strategy_report')):
        wp_schedule_single_event(time()+30, 'gcrev_strategy_report_chunk_event',
                                  [$job_id, $limit])
    else:
        Cron_Logger::finish($job_id)
```

#### 3.2.3 戦略レポート生成本体 `Strategy_Report_Service::generate()`

```
function generate($user_id, $year_month, $source='cron'):
    # 排他ロック（同月二重生成防止）
    $lock_key = "gcrev_lock_strategy_report_{$user_id}_{$year_month}"
    if (!set_transient($lock_key, 1, 30*MINUTE_IN_SECONDS, ...not exists)):
        throw "already running"
    try:
        # 1. 戦略取得
        $strategy = Strategy_Repository::get_active_for_month($user_id, $year_month)
        if (!$strategy) -> mark skipped + return
        
        # 2. 月次データ統合（既存 fetcher を組み合わせ）
        $data = Strategy_Data_Aggregator::collect($user_id, $year_month)
        # → ga4_summary, gsc_summary, meo_summary, page_insights, competitors_meta
        
        # 3. レポート行 upsert (status=running, started_at=now)
        $report_id = Strategy_Report_Repository::start_generation(
            $user_id, $year_month, $strategy['id'], $source)
        
        # 4. プロンプト組み立て（§4.1）
        $prompt = Strategy_Prompt_Builder::build($strategy['strategy_json'], $data)
        $prompt_hash = sha1($prompt)
        
        # 5. AI 呼び出し（既存 Gcrev_AI_Client）
        $ai_client = new Gcrev_AI_Client()
        $start = microtime(true)
        $raw = $ai_client->call_gemini_api($prompt, [
            'model' => 'gemini-2.0-flash-001',
            'temperature' => 0.3,
            'max_output_tokens' => 8192,
            'response_mime_type' => 'application/json'
        ])
        $duration_ms = (microtime(true) - $start) * 1000
        
        # 6. JSON パース + バリデーション
        $parsed = Gcrev_AI_Json_Parser::parse_or_throw($raw)
        Strategy_Report_Schema_Validator::validate_or_throw($parsed)
        
        # 7. HTML レンダ
        $html = Strategy_Report_Renderer::render($parsed, $strategy, $data)
        
        # 8. 保存
        Strategy_Report_Repository::complete($report_id, [
            report_json: $parsed,
            rendered_html: $html,
            alignment_score: $parsed['alignment_score'] ?? null,
            ai_meta: { model, input_tokens, output_tokens, duration_ms },
            prompt_hash: $prompt_hash,
        ])
    catch (\Throwable $e):
        Strategy_Report_Repository::fail($report_id ?? null, $e->getMessage())
        throw
    finally:
        delete_transient($lock_key)
```

**ファイル分割**（PR2 で新規作成）：

| ファイル | 役割 |
|---|---|
| `inc/gcrev-api/modules/class-strategy-report-service.php` | 上記 generate() の本体 |
| `inc/gcrev-api/modules/class-strategy-data-aggregator.php` | GA4/GSC/MEO 横断のデータ集約 |
| `inc/gcrev-api/modules/class-strategy-prompt-builder.php` | プロンプト組み立て |
| `inc/gcrev-api/modules/class-strategy-report-renderer.php` | JSON → HTML |
| `inc/gcrev-api/modules/class-strategy-report-repository.php` | `gcrev_strategy_reports` への CRUD |
| `inc/gcrev-api/utils/class-strategy-report-schema-validator.php` | AI出力 JSON の検証 |

### 3.3 データ集約レイヤ（`Strategy_Data_Aggregator`）

既存の以下メソッドを呼び合わせるだけで、**新しい外部APIは追加しない**：

```
collect($user_id, $year_month) returns array {
    period:      { from: 'YYYY-MM-01', to: 'YYYY-MM-末日', prev_from, prev_to },
    ga4_summary: Gcrev_GA4_Fetcher の月次集計（sessions/users/goals/devices/mediums/regions）,
    gsc_summary: Gcrev_GSC_Fetcher のクエリ別クリック・表示（top 30）,
    meo_summary: 既存 rest_get_meo_dashboard 内部処理を抽出した内部メソッド,
    page_insights: ページ別 PV / 滞在時間 / 入口・出口（既存 dashboard_service）,
    keyword_changes: 順位トラッキングの当月差分,
    competitors_snapshot: 戦略JSON の competitors[] に対する最新メタ（できる範囲で）,
    target_area: クライアント設定のターゲットエリア
}
```

集約結果は `Strategy_Prompt_Builder` で **要約済みの数値・文字列** に圧縮してから AI に渡す。生レスポンスは渡さない（CLAUDE.md §7 NG項）。

### 3.4 既存 AI Client への小修正

`Gcrev_AI_Client::call_gemini_api()` は現状テキスト返却のみ。本機能では **JSON mode + メタ情報（token数等）** が欲しい。以下を追加：

```php
public function call_gemini_api_structured($prompt, $options = []): array {
    // 既存呼び出しを内部で利用しつつ
    // generationConfig.response_mime_type = 'application/json' を強制
    // 戻り値: ['text' => ..., 'input_tokens' => ..., 'output_tokens' => ..., 'finish_reason' => ...]
}
```

既存メソッドは無改修。新メソッドを追加することで本機能は安全に乗る。

---

## 4. プロンプト

### 4.1 戦略レポート生成プロンプト

`Strategy_Prompt_Builder::build()` の出力。**System プロンプトと User プロンプトを分けず、Gemini の単一プロンプトに連結**（既存 AI Client の互換性のため）。

```
あなたはWebマーケティングコンサルタントです。中小企業の経営者に対し、
戦略と現状データのズレを指摘し、具体的なアクションを提示します。

# 制約
- 出力は必ず JSON のみ。前後の説明文・コードフェンスは禁止。
- スキーマに無いキーは出さない。
- 抽象的な表現（「強化する」「最適化する」等の単独使用）は禁止。
  必ず「何を、どこで、何のKPIを動かすために」を含める。
- 経営者が読むので専門用語は最小限に。必要な場合は1行で言い換えを添える。
- issues は最大3件。原因は各 issue に1対1で対応。
- alignment_score は 0-100 の整数。戦略と実態の整合度。
  100=完全一致、50=半分実行、0=戦略と逆方向に動いている。

# 戦略
{{strategy_json}}

# 対象期間
{{year_month}} （{{period.from}} 〜 {{period.to}}）
前月比較: {{period.prev_from}} 〜 {{period.prev_to}}

# 集約データ（GA4 / GSC / MEO / ページ分析）
{{aggregated_data_summary_text}}

# 競合・キーワード変化
{{competitors_and_keywords_summary}}

# 出力スキーマ（必須）
{
  "schema_version": "1.0",
  "alignment_score": <0-100 の整数>,
  "sections": {
    "conclusion": "<3〜5文の経営者向け結論>",
    "alignment": [
      {
        "topic": "<戦略上の論点>",
        "expected": "<戦略から期待される状態>",
        "actual":   "<データ上の実態>",
        "gap":      "<ギャップの言語化>"
      }
    ],
    "issues": [
      {
        "title":    "<60字以内>",
        "evidence": "<数字付きの根拠1〜2文>",
        "severity": "high" | "mid" | "low"
      }
    ],
    "causes": [
      {
        "issue_ref": <issues配列のインデックス>,
        "cause":     "<原因の言語化、1〜3文>"
      }
    ],
    "actions": [
      {
        "title":   "<60字以内>",
        "owner":   "<担当 例: 経営者 / 制作担当 / 広告担当>",
        "horizon": "this_month" | "next_month" | "quarter",
        "kpi":     "<このアクションで動かす指標と目標値>"
      }
    ],
    "this_month_todos": [
      {
        "title":    "<実行可能な単位の作業>",
        "due_date": "YYYY-MM-DD",
        "kpi":      "<完了判定のKPI>"
      }
    ]
  }
}

# 注意
- issues 配列が空はNG（1〜3件）。データ不足の場合でも「データ不足」を1件目の issue にせよ。
- this_month_todos は3〜5件を目安。
```

### 4.2 PDF抽出プロンプト

```
あなたは企画書からマーケティング戦略を構造化するアシスタントです。
以下のPDFテキストを読み、定義スキーマに従って JSON を返してください。

# 制約
- JSON のみ出力。説明文禁止。
- 抜けている項目は空配列 [] または空文字 "" を入れること。捏造禁止。
- meta.client_name は本文中の社名を抽出。見つからない場合は "" 。
- meta.effective_from は 本日（{{today}}）を入れる。

# PDF テキスト
{{pdf_text}}

# 出力スキーマ
{
  "meta": {
    "client_name": "",
    "effective_from": "YYYY-MM-DD",
    "schema_version": "1.0"
  },
  "target":           "<ターゲット顧客像>",
  "issues":           ["<課題1>", "<課題2>"],
  "strategy":         "<戦略方針 1〜3文>",
  "value_proposition":["<差別化要素1>", "..."],
  "conversion_path":  "<想定する CV 導線>",
  "competitors":      [{"name":"","url":"","type":"peer|rival_major|rival_local","notes":""}],
  "company_strengths": {
    "design_function":  ["..."],
    "support_trust":    ["..."],
    "economy_eco":      ["..."]
  },
  "differentiation_axes": ["..."],
  "customer_segments": {
    "potential":   {"label":"","channel":"","kpi":""},
    "semi_active": {"label":"","channel":"","kpi":""},
    "active":      {"label":"","channel":"","kpi":""}
  },
  "customer_journey": [
    {"stage":"認知","pains":["..."],"messaging":"..."}
  ],
  "site_map_priorities": [
    {"path":"/","role":"","kpi_focus":""}
  ]
}
```

PDF抽出後の応答を `Gcrev_Strategy_Schema_Validator::validate()` に通すので、AI が嘘をついても **必須5項目が欠けると 422** で弾く。

---

## 5. 管理画面UI構成

### 5.1 メニュー追加

**親メニュー**: `gcrev-insight`（既存）
**追加サブメニュー**: 2つ

| スラッグ | ラベル | 権限 | 役割 |
|---|---|---|---|
| `gcrev-strategy-management` | 🧭 戦略管理 | `manage_options` | 全クライアントの戦略一覧・代理編集・PDF取込 |
| 既存 `gcrev-report-queue` を流用 | （変更なし） | `manage_options` | キューモニターに `job_type` フィルタを追加 |

「クライアント単位の戦略編集 UI」は新規ページ `gcrev-strategy-management` の中で **クライアント選択 → タブ（基本 / 競合 / 顧客 / 導線 / バージョン履歴）** の構成にする。
クライアント本人向けの編集 UI は **クライアント側の固定ページ** 経由（§5.4）で提供する。

### 5.2 管理者向け「戦略管理」ページ

**ファイル**: `inc/gcrev-api/admin/class-strategy-management-page.php`（新規）

```
┌─────────────────────────────────────────────────┐
│ 🧭 戦略管理                                    │
├─────────────────────────────────────────────────┤
│ クライアント: [▼ 山田工務店         ] [絞込: ●●]│
├─────────────────────────────────────────────────┤
│ 現在の active 戦略: v3  (2026-04-01〜)         │
│ [📄 PDFから取込] [✏️ 手動で新版作成] [📋 履歴]   │
├─────────────────────────────────────────────────┤
│ タブ: [基本] [3C/差別化] [顧客] [導線] [履歴]    │
├─────────────────────────────────────────────────┤
│ ┌─ 基本 タブ ─────────────────────────────┐ │
│ │ ターゲット      : [textarea]              │ │
│ │ 課題（複数）    : [+ 追加]                │ │
│ │ 戦略方針        : [textarea]              │ │
│ │ 差別化要素      : [+ 追加]                │ │
│ │ コンバージョン導線: [textarea]            │ │
│ │ effective_from : [date picker]           │ │
│ │                                           │ │
│ │ [💾 下書き保存] [🚀 この内容を有効化]      │ │
│ └───────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

**保存挙動**:
- 「下書き保存」→ `POST /strategy/draft`（新規）or `PUT /strategy/draft/{id}`（既存draft）
- 「有効化」→ Validator で必須5項目チェック → 不足あれば確認モーダル → `POST /strategy/draft/{id}/activate`

**PDF取込モーダル**:
```
[PDFファイルを選択]
[アップロード] → ローディング
  ↓
AI抽出結果のプレビュー（JSON を整形表示・編集可）
  ↓
[このまま下書き保存] [破棄]
```

### 5.3 既存「レポートキュー」ページの拡張

**ファイル**: `inc/gcrev-api/admin/class-report-queue-page.php`（既存・修正）

- 上部にタブ追加：`[ 全て | 月次レポート | 戦略レポート ]`
- 各行に `job_type` バッジ表示
- 「戦略レポート」タブで failed 行を選んで一括リトライボタン

### 5.4 クライアント側 UI

#### 戦略設定ページ
- **URL**: `/strategy-settings/`
- **テンプレート**: `page-strategy-settings.php`（新規）
- **アクセス**: ログイン必須（自分の戦略のみ）
- **構成**: §5.2 と同じレイアウトを「自分専用」モードで描画。クライアント選択ドロップダウンは出さない。

#### 戦略レポート閲覧ページ
- **URL**: `/strategy-report/`
- **テンプレート**: `page-strategy-report.php`（新規）
- **アクセス**: ログイン必須

```
┌──────────────────────────────────────────────┐
│ 🧠 戦略レポート — 2026年3月                  │
│ 整合度スコア: ████████░░ 64 / 100            │
├──────────────────────────────────────────────┤
│ 📌 今月の結論                                │
│ ……経営者向けサマリー……                       │
├──────────────────────────────────────────────┤
│ ⚖️ 戦略とのズレ                              │
│  ・トピック：……                              │
│   期待：…… / 実態：…… / ギャップ：……         │
├──────────────────────────────────────────────┤
│ 🚨 問題点（最大3件）                          │
├──────────────────────────────────────────────┤
│ 🔍 原因                                       │
├──────────────────────────────────────────────┤
│ 💡 改善アクション                             │
├──────────────────────────────────────────────┤
│ ✅ 今月やるべきこと                           │
│  □ 〜（期日 4/15）                           │
├──────────────────────────────────────────────┤
│ [📥 PDFでダウンロード] [🔄 やり直し生成]      │
└──────────────────────────────────────────────┘
```

**「やり直し生成」**:
- 同月3回まで（`gcrev_lock_strategy_report_retry_{user_id}_{year_month}` カウンタ transient）
- 押下 → 確認モーダル → `POST /strategy-report/generate` → 進捗ポーリング（status?year_month=...）→ 完了したら自動再描画

**生成中表示**:
```
┌──────────────────────────────────────────────┐
│ ⏳ 戦略レポートを生成中です…（推定 30秒〜2分） │
│ [████████░░░░░░] 60%                          │
└──────────────────────────────────────────────┘
```
進捗バーは演出（実際は status=running を polling）。

### 5.5 ダッシュボードへの導線

`page-dashboard.php` 上部、既存の「月次レポート」カードの隣に **「戦略レポート」カード** を追加：

```
┌─ 月次レポート ──┐  ┌─ 戦略レポート ──┐
│ 2026年3月       │  │ 2026年3月       │
│ [📊 表示]       │  │ 整合度: 64      │
│                 │  │ [🧠 表示]       │
└─────────────────┘  └─────────────────┘
```

戦略未設定ユーザーには「まずは戦略を設定しましょう [設定する →]」CTA を出す。

---

## 6. 実装手順（ステップ形式）

PR1 = 完了 (`97167ae`)。以降を **6つの PR** に分割し、各 PR は単独で main にマージしても既存機能を壊さない設計。

### PR2 — 戦略の REST API + クライアント側設定UI（手動入力のみ）
**目的**: 戦略を「手動で」設定・更新できる土台。PDF抽出・レポート生成はまだ無し。

| ステップ | 作業 | 影響ファイル |
|---|---|---|
| 2.1 | REST ルート追加（CRUD系）`/strategy`, `/strategy/draft*`, `/strategy/versions`, `/strategy/active-for-month` | `inc/class-gcrev-api.php` |
| 2.2 | コールバック実装（`Strategy_Repository` を呼ぶだけ） | 同上 |
| 2.3 | クライアント側 `page-strategy-settings.php` テンプレ + JS | `page-strategy-settings.php`, `assets/js/strategy-settings.js`, `sass/strategy-settings.scss` |
| 2.4 | 固定ページを WP に作成（管理画面手動 or wp-cli）してテンプレ割当 | （手順書化） |
| 2.5 | ダッシュボードからの導線 | `page-dashboard.php` |

**完了条件**: ログイン → `/strategy-settings/` → 入力 → 「下書き保存」「有効化」が動く。`gcrev_client_strategy` に行が増える。

### PR3 — PDFアップロード + AI抽出
**目的**: 企画書PDFを投げると draft が生成される。

| ステップ | 作業 | 影響ファイル |
|---|---|---|
| 3.1 | PDFテキスト抽出ユーティリティ（vendor 確認 → 無ければ `smalot/pdfparser` を composer.json 追加） | `composer.json`, `inc/gcrev-api/utils/class-pdf-text-extractor.php` |
| 3.2 | PDF抽出プロンプト（§4.2）と Builder | `inc/gcrev-api/modules/class-strategy-pdf-extractor.php` |
| 3.3 | REST `POST /strategy/extract-pdf` | `inc/class-gcrev-api.php` |
| 3.4 | UI: アップロードモーダル + プレビュー | `assets/js/strategy-settings.js` |

**完了条件**: PDFアップロード → 数十秒後にプレビューが表示され、保存すると draft に入る。失敗時は Validator のエラーが UI に出る。

### PR4 — 戦略レポート生成サービス（同期実行版）
**目的**: コア生成ロジックを「単発実行」で動かす。Cron はまだ繋がない。**WP-CLI コマンドで叩いて検証** できる状態にする。

| ステップ | 作業 | 影響ファイル |
|---|---|---|
| 4.1 | データ集約レイヤ | `class-strategy-data-aggregator.php` |
| 4.2 | プロンプトビルダ | `class-strategy-prompt-builder.php` |
| 4.3 | AIクライアント拡張（`call_gemini_api_structured`） | `class-ai-client.php` |
| 4.4 | AI出力スキーマ Validator | `class-strategy-report-schema-validator.php` |
| 4.5 | レポート Repository（`gcrev_strategy_reports` への CRUD） | `class-strategy-report-repository.php` |
| 4.6 | HTML レンダラ | `class-strategy-report-renderer.php`, `template-parts/strategy-report-body.php` |
| 4.7 | サービス本体 `Strategy_Report_Service::generate()` | `class-strategy-report-service.php` |
| 4.8 | WP-CLI コマンド `wp mimamori strategy-report generate --user_id=... --year_month=...` | `inc/cli/class-mimamori-strategy-cli.php` |

**完了条件**: WP-CLI で1ユーザー × 1月分を生成 → DB に completed 行が入り、HTML が `rendered_html` に格納される。

### PR5 — REST + クライアント側 閲覧UI
**目的**: 生成済みレポートを画面で見られる。手動「やり直し生成」もここで。

| ステップ | 作業 | 影響ファイル |
|---|---|---|
| 5.1 | REST `/strategy-report/*` 群（GET系 + 手動 generate） | `inc/class-gcrev-api.php` |
| 5.2 | 手動 generate は **キュー投入のみ**（同期処理しない）。生成は次PRのワーカーが処理 | 同上 |
| 5.3 | テンプレート `page-strategy-report.php` + JS | `page-strategy-report.php`, `assets/js/strategy-report.js`, `sass/strategy-report.scss` |
| 5.4 | ダッシュボードカード | `page-dashboard.php` |
| 5.5 | 進捗ポーリングUI | `assets/js/strategy-report.js` |

**完了条件**: PR4 で WP-CLI 生成済みのレポートが `/strategy-report/` で表示される。「やり直し生成」ボタン押下でキューに `pending` 行が入る（処理されるのは次PR後）。

### PR6 — キューワーカー + Cron 連動
**目的**: バックグラウンド自動化を完結させる。

| ステップ | 作業 | 影響ファイル |
|---|---|---|
| 6.1 | Cronイベント登録 `gcrev_strategy_report_generate_event`, `gcrev_strategy_report_chunk_event` | `class-gcrev-bootstrap.php` |
| 6.2 | チャンクワーカー（`Report_Queue::claim_next` を `job_type='strategy_report'` で絞る対応） | `class-report-queue.php`（既存修正・後方互換維持） |
| 6.3 | サービス起動シム（chunk → `Strategy_Report_Service::generate()`） | `class-gcrev-bootstrap.php` |
| 6.4 | 既存「レポートキュー」管理画面に job_type タブ追加 | `class-report-queue-page.php` |
| 6.5 | 通知（失敗時メール）を既存 `Gcrev_Error_Notifier` 経由で | `class-strategy-report-service.php` |

**完了条件**: Dev では WP-CLI `wp cron event run gcrev_strategy_report_generate_event` で全フローが流れる。Prod では毎月5日 04:30 に自動実行（次回実行を `wp cron event list` で確認）。

### PR7 — 管理者向け「戦略管理」ページ + 微調整
**目的**: 運用担当が代理編集・障害対応できるように。

| ステップ | 作業 | 影響ファイル |
|---|---|---|
| 7.1 | 管理者向けページ追加 | `inc/gcrev-api/admin/class-strategy-management-page.php` |
| 7.2 | Bootstrap で読込 | `class-gcrev-bootstrap.php` |
| 7.3 | 管理者用 REST `/admin/strategy-report/generate` | `inc/class-gcrev-api.php` |
| 7.4 | レート制限・ロック調整、ログ整備 | `class-strategy-report-service.php` |

**完了条件**: 管理者がメニューから任意クライアントの戦略を確認・編集・代理生成できる。

---

## 7. 横断ルール（CLAUDE.md 遵守）

| 項目 | ルール |
|---|---|
| デバッグログ | `file_put_contents('/tmp/gcrev_strategy_debug.log', date('Y-m-d H:i:s')." ...\n", FILE_APPEND)`。`error_log()` 禁止 |
| 外部API呼び出し | エラーパスに **必ず** ログを入れる（プロンプトの先頭500字とレスポンス先頭500字） |
| JSON出力 | `wp_json_encode($data, JSON_UNESCAPED_UNICODE)` |
| SQL | `$wpdb->prepare()` 必須 |
| 出力エスケープ | `esc_html()` / `esc_attr()` / `esc_url()` |
| REST 認可 | `permission_callback` 必須、`__return_true` 禁止 |
| 日付 | `wp_timezone()` + `DateTimeImmutable` |
| キャッシュ無効化 | レポート完了時に `gcrev_dash_{user_id}_*` を必要に応じて削除 |
| Cron | `MIMAMORI_ENV === 'production'` のみ登録 |
| デプロイ | main push → GitHub Actions 自動。本番は管理画面のデプロイボタン |

---

## 8. リスクと対策

| リスク | 影響 | 対策 |
|---|---|---|
| AI 出力 JSON が壊れる | レポート生成失敗 | `Gcrev_AI_Json_Parser` でロバストパース → Validator で必須項目チェック → 1回だけ「JSON形式を厳密に守って」を付けて再試行 → それでも失敗なら failed |
| AI 応答が長くて max_output_tokens 到達 | 末尾切れ | プロンプト側で「issues 最大3件」「actions 最大5件」と明示。max_output_tokens=8192 |
| 戦略未設定ユーザーが多数 | 大量の skipped 行で DB 肥大 | skipped は3ヶ月以上経過したら DELETE する Cron（cron_log_cleanup_event に相乗り） |
| PDF抽出の精度不足 | 戦略が空になる | UI 側で「AI抽出結果は必ず人がチェックする」前提に。プレビュー編集を必須動線に |
| AI コスト増加 | 月次費用 | `ai_input_tokens` / `output_tokens` を DB に記録。管理画面に月次合計表示。手動生成のレート制限（同月3回） |
| 同月二重生成 | DB ロック・コスト浪費 | `gcrev_lock_strategy_report_{user_id}_{year_month}` transient + `uniq_user_month` UNIQUE 制約 |
| Cron が落ちる | 月初に全ユーザー未生成 | 既存 `Cron_Logger` で監視。失敗時は手動 `wp mimamori strategy-report generate-all --year_month=...` で巻き返せる WP-CLI を用意 |

---

## 9. 完成像（このシステムが回ったとき）

毎月5日朝、サーバーは前月分の **戦略レポート** を全ユーザー分自動生成する。
クライアントは `/strategy-report/` を開くと、自分の企画書（戦略）と先月の実績データを突き合わせて

> **今月の結論：自然検索流入は伸びたが、CV経路の入口にしている「ショールーム来店予約ページ」への到達率が戦略想定の半分以下。広告ではなくサイト内導線の設計が原因。**

…という形で「次に何をすべきか」が並んでいる。経営者は5分で読み、3つのアクションを社内に落とす。
これが **ツールではなくコンサル価値** であり、継続課金の理由になる。

— 以上 —
