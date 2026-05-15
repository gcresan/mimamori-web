# Meta App Review 申請準備

> Meta（Facebook）App Review 申請に必要な情報・操作手順・申請文面を一括でまとめたガイド。
> アプリ名：**Mimamori Web Social Connector**
> 対象 URL：`https://mimamori-web.jp/`
>
> **本アプリの利用フロー（重要 — 申請文の核）**：
> 利用者は「Google Business Profile（GBP）に投稿する」ためにみまもりウェブを使う。
> 投稿作成画面に「Facebook ページにも投稿する」「Instagram にも投稿する」のチェックボックスがあり、
> **利用者が明示的にチェックを入れた場合のみ** 同じ内容を Facebook ページ・Instagram にも同時投稿する。
> ユーザーの明示的な選択なしに Meta プラットフォームへ投稿することは絶対にない。

---

## 1. 申請対象スコープと実装箇所の対応表

申請対象は 5 件。`business_management` は本実装では使用しないため申請しません。

| 権限 | 実装での使用箇所 | 呼び出している Graph API | 用途 |
|---|---|---|---|
| `pages_show_list` | `inc/gcrev-api/modules/social/class-meta-client.php:196` | `GET /me/accounts` | 利用者が管理する Facebook ページ一覧の取得・選択 |
| `pages_read_engagement` | `inc/gcrev-api/modules/social/class-meta-client.php:196` | `GET /me/accounts` のレスポンスでページ名・トークン取得時 | ページの基本情報（ID, 名前）を読むため。Page Access Token の発行に必要 |
| `pages_manage_posts` | `inc/gcrev-api/modules/social/class-meta-client.php:285,292` | `POST /{page_id}/feed`, `POST /{page_id}/photos` | 利用者が選択した自社 Facebook ページに投稿（テキスト・画像） |
| `instagram_basic` | `inc/gcrev-api/modules/social/class-meta-client.php:196` 内 `instagram_business_account` フィールド取得 | `GET /me/accounts?fields=...,instagram_business_account` | Facebook ページに紐付く Instagram ビジネスアカウントの ID 取得 |
| `instagram_content_publish` | `inc/gcrev-api/modules/social/class-meta-client.php:328,344` | `POST /{ig_user_id}/media`, `POST /{ig_user_id}/media_publish` | 利用者の Instagram ビジネスアカウントへ画像＋キャプションを投稿 |

### 申請しない権限

| 権限 | 理由 |
|---|---|
| `business_management` | `/me/businesses` 等のビジネス資産取得を一切行わない。投稿は Page Access Token のみで完結するため不要 |

> Threads (`threads_basic` / `threads_content_publish`) は別の権限フローのため、Threads 投稿機能を本格運用する段階で別途申請する。

---

## 2. 各権限の利用目的説明文（Meta App Review 用）

App Review の各権限「Tell us how you're using this permission」欄に貼り付ける文面。
**Meta は英語必須**なので英語版・日本語版の両方を用意。

### 2-1. `pages_show_list`

**English (submit this):**
> Mimamori Web Social Connector helps small business owners manage their own social media presence from a single dashboard. After a business owner connects their Facebook account, we use `pages_show_list` to list the Facebook Pages the user manages so they can select which Page should be linked to their Mimamori Web account. The list is shown only to the page owner inside our authenticated dashboard at `/social-connect/`. We do not display, share, or store this list outside the user's own session and account.

**日本語（社内確認用）:**
> Mimamori Web Social Connector は、中小事業者が自社の SNS 運用を 1 つのダッシュボードから管理できるようにする SaaS です。Facebook 連携後、`pages_show_list` を使って利用者が管理している Facebook ページの一覧を取得し、どのページをみまもりウェブと連携させるかを利用者自身に選択していただきます。一覧は認証済みダッシュボード（`/social-connect/`）内で利用者本人にのみ表示し、外部公開・第三者提供は行いません。

### 2-2. `pages_read_engagement`

**English:**
> We use `pages_read_engagement` together with `pages_show_list` to read the basic metadata of the Pages the user owns (page id, name, page access token, linked Instagram Business account). This is required by Meta to issue Page Access Tokens for posting on behalf of the user. We do not access engagement metrics, comments, reactions, insights, or any audience data through this permission. The information is only used to label and route posts to the correct Page in our dashboard.

**日本語:**
> `pages_show_list` と組み合わせて、利用者が管理するページの基本メタデータ（ページ ID、ページ名、Page Access Token、紐付く Instagram ビジネスアカウント）を取得するために使用します。Meta の仕様上、Page Access Token の発行に本権限が必要です。本権限を使ってエンゲージメント指標・コメント・リアクション・Insights・オーディエンス情報等にはアクセスしません。取得した情報はダッシュボード上でページを識別し、投稿先を正しくルーティングするためだけに使います。

### 2-3. `pages_manage_posts`

**English:**
> Mimamori Web's primary workflow is creating updates for the user's Google Business Profile (GBP). On the GBP post composer at `/gbp-posts/`, the user types the body, optionally attaches an image, and sees two checkboxes: "Also post to Facebook Page" and "Also post to Instagram". When — and only when — the user ticks the Facebook checkbox, we use `pages_manage_posts` to publish the same content to the user's own connected Facebook Page (immediately or at the scheduled time the user chose). The post is sent to `POST /{page_id}/feed` (text/link) or `POST /{page_id}/photos` (image). We never publish to Facebook without that explicit checkbox selection. No third party publishes on the user's behalf — only the authenticated page owner who composed the post in our UI.

**日本語:**
> 本サービスの主たるワークフローは、利用者の Google ビジネスプロフィール（GBP）への投稿作成です。GBP 投稿コンポーザー（`/gbp-posts/`）で本文を入力し、必要に応じて画像を添付すると、「Facebook ページにも投稿する」「Instagram にも投稿する」というチェックボックスが表示されます。利用者が **Facebook のチェックを入れた場合に限り**、`pages_manage_posts` を使って同じ内容を利用者自身が連携した Facebook ページに即時または予約日時に投稿します（`POST /{page_id}/feed` または `POST /{page_id}/photos`）。チェックが入っていない場合は Facebook へは投稿しません。利用者の明示的な操作以外で Facebook に投稿することは一切ありません。

### 2-4. `instagram_basic`

**English:**
> We use `instagram_basic` to retrieve the Instagram Business Account ID that is linked to the user's Facebook Page (via the `instagram_business_account` field on `/me/accounts`). The ID is required as the target endpoint for the Instagram Content Publishing API. We do not retrieve photos, captions, comments, follower lists, or any other media or insight data through this permission.

**日本語:**
> 利用者の Facebook ページに紐付く Instagram ビジネスアカウントの ID を取得するために使用します（`/me/accounts` の `instagram_business_account` フィールド）。この ID は Instagram Content Publishing API の投稿先指定に必要です。本権限を使って既存の写真・キャプション・コメント・フォロワー一覧・Insights 等を取得することはありません。

### 2-5. `instagram_content_publish`

**English:**
> When — and only when — the user ticks the "Also post to Instagram" checkbox on the GBP post composer at `/gbp-posts/`, we use `instagram_content_publish` to publish the same caption and image to the user's own Instagram Business Account. The flow is the standard two-step Content Publishing API: (1) `POST /{ig_user_id}/media` to create a media container with `image_url` and `caption`, (2) `POST /{ig_user_id}/media_publish` to publish it. Instagram requires an image, so the checkbox is disabled (and the form is rejected server-side) when no image is attached. The image is the same one the user attached to their GBP post. We never publish to Instagram without that explicit checkbox selection.

**日本語:**
> GBP 投稿コンポーザー（`/gbp-posts/`）で「Instagram にも投稿する」のチェックを利用者が入れた場合に限り、`instagram_content_publish` を使って同じキャプション・画像を利用者自身の Instagram ビジネスアカウントへ投稿します。Meta 標準の 2 ステップ Content Publishing API を使い、(1) `POST /{ig_user_id}/media` でメディアコンテナを作成、(2) `POST /{ig_user_id}/media_publish` で公開します。Instagram は画像が必須のため、画像が添付されていない場合はチェックボックスを無効化し、サーバー側でも拒否します。投稿される画像は GBP 投稿に添付したものと同じです。チェックが入っていない場合は Instagram へは投稿しません。

---

## 3. 審査用スクリーンキャスト撮影手順

Meta App Review では権限ごとに動作証明動画（または 1 本まとめ）が要求されます。**画面録画（音声・字幕は任意）**で以下を撮ってください。長さは合計 3〜5 分目安。

### 撮影前の準備
- ブラウザ：Chrome（拡張機能 OFF / シークレットウィンドウ推奨）
- 解像度：1280×720 以上
- テスト用 Facebook ユーザー：審査用に追加した Tester（後述§4）
- テスト用 Facebook ページ＋連携済み Instagram ビジネスアカウント
- 録画ツール：QuickTime / OBS / Loom 等

### 撮影シナリオ（GBP 投稿起点の同時投稿フロー）

| # | 操作 | 画面で見せる内容 | カバーされる権限 |
|---|---|---|---|
| 1 | `https://mimamori-web.jp/login/` を開きログイン | ログインフォーム → ダッシュボードへ遷移 | （前提） |
| 2 | URLバーで `/social-connect/` へ移動 | SNS 連携ページが開く | （前提） |
| 3 | 「Metaと接続」ボタンをクリック | Facebook ログインダイアログが開く | OAuth 開始 |
| 4 | テスト Facebook アカウントでログイン → 権限同意 | 権限要求一覧（pages_show_list, pages_read_engagement, pages_manage_posts, instagram_basic, instagram_content_publish）が表示される | 申請対象 5 権限が要求されている証跡 |
| 5 | 「次へ」→ 投稿先ページ選択（複数ある場合） | 「あなたが管理するページ」一覧 | `pages_show_list` |
| 6 | 同意完了 → コールバック → SNS 連携ページに「Meta と接続しました」表示 | 接続済み状態（Facebook ページ名、Instagram ビジネスアカウント名） | `pages_read_engagement`, `instagram_basic` |
| 7 | サイドメニュー「MEO」→「投稿管理」→ `/gbp-posts/` を開く | GBP 投稿管理ページが開く（既存投稿リスト + 「新規投稿」ボタン） | （前提） |
| 8 | 「新規投稿」をクリック → モーダル表示 | 投稿作成モーダル | （前提） |
| 9 | 本文を入力 + 画像をアップロード | 入力中のフォーム | （前提） |
| 10 | **「同時投稿先」セクションで両方のチェックボックスを ON にする** | チェックボックスがオンになる UI を明示的に映す | **本アプリの肝**：明示的なユーザー選択 |
| 11 | 「今すぐ投稿」を選択 → 「保存」 | 投稿実行 | GBP 投稿 + `pages_manage_posts` + `instagram_content_publish` |
| 12 | 投稿結果トースト表示：「✅ Googleビジネスプロフィール：投稿しました / ✅ Facebookページ：投稿しました / ✅ Instagram：投稿しました」 | 結果サマリー UI | 結果が利用者に見える証跡 |
| 13 | 別タブで Google ビジネスプロフィール → 投稿反映を確認 | GBP 上の実際の投稿 | （GBP 連携は本申請外） |
| 14 | 別タブで Facebook ページ → 投稿反映を確認 | Facebook ページ上の実際の投稿 | `pages_manage_posts` の結果 |
| 15 | 別タブで Instagram アプリ/Web → 投稿反映を確認 | Instagram 上の実際の投稿 | `instagram_content_publish` の結果 |
| 16 | （任意）GBP 投稿一覧で同投稿カードを確認 | 「同時投稿: ✅ Facebook ✅ Instagram」バッジが表示される | 履歴 UI |
| 17 | （任意）「新規投稿」をもう一度開き、**チェックボックスを OFF のまま** 投稿 → Facebook / Instagram には投稿されないことを確認 | チェックなしの場合は GBP のみ投稿 | **明示選択なしに無断投稿しない証跡** |
| 18 | SNS 連携ページに戻り「接続を解除」をクリック → 解除完了 | 解除済みの表示 | Data Deletion 動作の証跡（後段で再利用可） |

### 重要：撮影中に必ず映すこと
- ステップ **10**（チェックボックス操作）と **17**（チェック無しでは投稿されないこと）は審査担当者が「ユーザーの明示的同意なしに投稿しない」ことを確認する核心。録画では言葉でも説明する（字幕/音声）と良い。
- ステップ **12** の結果トーストは、投稿結果が利用者に明示されることを示す。

撮影後、Loom や YouTube（限定公開）にアップしてリンクを App Review に貼り付け。

---

## 4. 審査用テストアカウント準備

Meta は審査担当者用にログイン情報を要求します。以下を用意してください。

### 4-1. みまもりウェブ側のテストアカウント

| 項目 | 内容 |
|---|---|
| ログイン URL | `https://mimamori-web.jp/login/` |
| ユーザー名 | `meta_review_tester`（仮 — 審査時に発行） |
| パスワード | 強力なランダム文字列（24 文字以上） |
| アカウント種別 | 通常クライアント（管理者ではない一般ユーザー） |
| 必要な権限 | お試し or 決済済み状態にして全機能アクセス可にする |

審査担当者がログインしたら以下が見えるようにする:
- サイドメニューから「SNS連携」（`/social-connect/`）に到達できる
- サイドメニュー「MEO」→「投稿管理」（`/gbp-posts/`）に到達できる
- GBP 投稿モーダルで「同時投稿先」セクション（Facebook / Instagram チェックボックス）が表示される
- Meta 連携前はチェックボックスが無効化され「先に SNS 連携してください」案内が出る
- 接続後はチェックボックスが有効になり、選択して投稿すると Facebook / Instagram にも反映される

### 4-2. Facebook 側のテストアカウント／ページ準備

審査担当者は Meta 側 Tester ロールを持っているため、こちらでテストアカウントを作る必要はありませんが、以下は満たしておく:

- 開発モードで以下が認可されていること:
  - **アプリの役割**：審査担当者用の追加は不要（Meta が自動で reviewer をテストモードで動かす）
  - 自社のテスト Facebook ページが少なくとも 1 つ存在
  - そのページに Instagram ビジネスアカウントが連携済み

> Meta ダッシュボード → 「役割」タブで Tester / Developer を見て不足が無いか確認。

### 4-3. App Review フォームに記入する Test Credentials 例

```
Test User Login URL: https://mimamori-web.jp/login/
Username: meta_review_tester
Password: <16文字以上のランダムパスワード>

The primary workflow tested by this app:
1. After login, navigate to /social-connect/ and click "Metaと接続" to authenticate via Facebook Login.
2. Navigate to /gbp-posts/ (Google Business Profile post composer).
3. Click "新規投稿" (New Post). In the modal, type a body and attach an image.
4. In the "同時投稿先" (Cross-post targets) section, tick "Facebookページにも投稿する" and/or
   "Instagramにも投稿する". The app NEVER posts to Facebook or Instagram without these
   checkboxes being explicitly ticked by the user.
5. Choose "今すぐ投稿" (Post now) and click 保存 (Save). The post is published to GBP, and to
   Facebook Page / Instagram if the corresponding checkbox was ticked.
6. The result toast displays per-platform success/failure. The post card in the list shows
   ✅ Facebook / ✅ Instagram badges to confirm each cross-post.
```

---

## 5. 申請前チェックリスト

### 5-1. アプリ設定（Meta App Dashboard）

- [ ] アプリ名 `Mimamori Web Social Connector` が表示用に正しく設定されている
- [ ] アプリアイコン（1024×1024 PNG）がアップロードされている
- [ ] **Privacy Policy URL** = `https://mimamori-web.jp/privacy-policy/` が設定されている
- [ ] **Terms of Service URL** = `https://mimamori-web.jp/terms-of-service/` が設定されている
- [ ] **Data Deletion Instructions URL** または **Data Deletion Callback URL** = `https://mimamori-web.jp/meta-data-deletion-callback/` が設定されている
- [ ] **App Domain** に `mimamori-web.jp` が登録されている
- [ ] **Site URL** に `https://mimamori-web.jp/` が登録されている
- [ ] **Business Use Case** が選択されている（"Other" or "Business Tools"）

### 5-2. Facebook Login 設定

- [ ] **Valid OAuth Redirect URIs** に `https://mimamori-web.jp/social/meta-oauth-callback/` のみが登録されている
- [ ] **Client OAuth Login** = ON
- [ ] **Web OAuth Login** = ON
- [ ] **Use Strict Mode for Redirect URIs** = ON
- [ ] **Enforce HTTPS** = ON

### 5-3. 機能の動作確認（本番）

- [ ] `https://mimamori-web.jp/social-connect/` にログイン → 「Metaと接続」が動作する
- [ ] 認可後 `https://mimamori-web.jp/social/meta-oauth-callback/` に戻り「接続済み」表示
- [ ] `https://mimamori-web.jp/gbp-posts/` で「新規投稿」モーダルが開く
- [ ] モーダルに「同時投稿先」セクションが表示され、Meta 接続済みならチェックボックスが有効
- [ ] Instagram にチェック ON & 画像未設定で submit → 「Instagramへ同時投稿するには画像の設定が必要です」エラー
- [ ] 「今すぐ投稿」+ Facebook & Instagram チェック → 3 媒体すべてに投稿が反映される
- [ ] 投稿結果トーストで「✅ Googleビジネスプロフィール / ✅ Facebookページ / ✅ Instagram」が出る
- [ ] 投稿カードに「同時投稿: ✅ Facebook ✅ Instagram」バッジが出る
- [ ] チェック無しで投稿した場合、Facebook / Instagram には投稿されない
- [ ] 予約投稿（scheduled）でも、Cron 実行時に GBP + チェック先に同時投稿される
- [ ] 「接続を解除」で `_gcrev_meta_*` user_meta が削除される
- [ ] `https://mimamori-web.jp/meta-data-deletion-callback/` が GET で 200、空 POST で 200 + JSON を返す
- [ ] `https://mimamori-web.jp/privacy-policy/` `/terms-of-service/` `/user-data-deletion/` がログイン無しで開ける

### 5-4. 申請書類

- [ ] 5 権限分の利用目的説明文を上記 §2 から英語でコピー＆ペースト
- [ ] スクリーンキャスト動画を Loom / YouTube 限定公開にアップロードしリンク取得
- [ ] テストアカウントを発行・パスワード控え・申請フォームに記入
- [ ] 「How will the data be used?」回答用意（個人情報は本サービス内のみで使用、第三者提供無し、SNS 連携は利用者自身の SNS への投稿補助のみ）

---

## 6. 承認後の SCOPES_FULL 切替手順

App Review が承認されたら以下の手順で投稿用フルスコープに切り替えます。

### 6-1. コード変更（最小手順）

```php
// inc/gcrev-api/modules/social/class-meta-client.php

// 変更前
const SCOPES = self::SCOPES_MINIMAL;

// 変更後
const SCOPES = self::SCOPES_FULL;
```

または、コードを触らず一時的に切り替える場合は `functions.php` または mu-plugin に:

```php
add_filter( 'gcrev_meta_oauth_scopes', function() {
    return Gcrev_Meta_Client::SCOPES_FULL;
} );
```

### 6-2. 切り替え後のオペレーション

1. **Dev で動作確認** → 自分のテスト Facebook で投稿成功を確認
2. **Prod へデプロイ** → Dev 管理画面の「デプロイ」ボタンで本番反映
3. **接続済みクライアントへ通知**：
   - 既存ユーザーのアクセストークンには新スコープ（`pages_manage_posts` 等）が含まれていないため、
     **「再認証」ボタンを押して再認可してください**と案内
   - SNS連携画面（`/social-connect/`）には既に「再認証」ボタンが実装されている
4. **段階的にロールアウト**：
   - 最初は社内検証アカウントのみで投稿テスト
   - 問題が無ければ全クライアントに案内メール

### 6-3. 切り戻し方法

万一不具合が出た場合は、コードを `SCOPES_MINIMAL` に戻して即時デプロイすればロールバック完了。
既に発行済みの長期トークンには影響なし（接続自体は維持される、新規投稿のみ失敗する）。

---

## 7. 補足：SCOPES_FULL の最終構成

申請対象 5 権限のみ。`business_management` は除外済み。

```php
const SCOPES_FULL = [
    'pages_show_list',
    'pages_read_engagement',
    'pages_manage_posts',
    'instagram_basic',
    'instagram_content_publish',
    // 'business_management' は本実装で未使用のため申請不要
];
```

Threads 機能を本格運用する場合は別途以下を申請:
- `threads_basic`
- `threads_content_publish`
