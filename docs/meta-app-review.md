# Meta App Review 申請準備

> Meta（Facebook）App Review 申請に必要な情報・操作手順・申請文面を一括でまとめたガイド。
> アプリ名：**Mimamori Web Social Connector**
> 対象 URL：`https://mimamori-web.jp/`

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
> Our core feature is letting the page owner publish a post (text and/or image) to their own Facebook Page from inside Mimamori Web. The post can be sent immediately or scheduled for a later time. We use `pages_manage_posts` only to publish content explicitly composed by the page owner via our composer at `/social-posts/`. We never auto-generate, modify, or publish content without an explicit user action. No third party can publish on the user's behalf — only the authenticated page owner who composed the post in our UI.

**日本語:**
> 本サービスの中核機能として、利用者がみまもりウェブの管理画面（`/social-posts/`）からテキスト・画像投稿を作成し、自社 Facebook ページに即時または予約投稿します。`pages_manage_posts` はこの投稿実行のためだけに使用します。利用者が UI 上で明示的に作成・送信操作したコンテンツのみを投稿し、自動生成・改変・無断投稿は一切行いません。投稿可能なのは認証済みのページオーナー本人だけで、第三者は介在しません。

### 2-4. `instagram_basic`

**English:**
> We use `instagram_basic` to retrieve the Instagram Business Account ID that is linked to the user's Facebook Page (via the `instagram_business_account` field on `/me/accounts`). The ID is required as the target endpoint for the Instagram Content Publishing API. We do not retrieve photos, captions, comments, follower lists, or any other media or insight data through this permission.

**日本語:**
> 利用者の Facebook ページに紐付く Instagram ビジネスアカウントの ID を取得するために使用します（`/me/accounts` の `instagram_business_account` フィールド）。この ID は Instagram Content Publishing API の投稿先指定に必要です。本権限を使って既存の写真・キャプション・コメント・フォロワー一覧・Insights 等を取得することはありません。

### 2-5. `instagram_content_publish`

**English:**
> We use `instagram_content_publish` to publish a photo with a caption to the user's own Instagram Business Account. The flow is the standard two-step Content Publishing API: (1) `POST /{ig_user_id}/media` to create a media container with `image_url` and `caption` typed by the page owner in our composer, (2) `POST /{ig_user_id}/media_publish` to publish it. The image must be hosted at a public URL the user has uploaded to Mimamori Web. We never publish without explicit user action.

**日本語:**
> 利用者自身の Instagram ビジネスアカウントへ画像＋キャプションを投稿するために使用します。Meta 標準の 2 ステップ Content Publishing API を使い、(1) `POST /{ig_user_id}/media` で利用者がコンポーザーで入力したキャプションと画像 URL からメディアコンテナを作成、(2) `POST /{ig_user_id}/media_publish` で公開します。画像は利用者がみまもりウェブにアップロードした公開 URL を指定します。利用者の明示的な操作無しに投稿することはありません。

---

## 3. 審査用スクリーンキャスト撮影手順

Meta App Review では権限ごとに動作証明動画（または 1 本まとめ）が要求されます。**画面録画（音声・字幕は任意）**で以下を撮ってください。長さは合計 3〜5 分目安。

### 撮影前の準備
- ブラウザ：Chrome（拡張機能 OFF / シークレットウィンドウ推奨）
- 解像度：1280×720 以上
- テスト用 Facebook ユーザー：審査用に追加した Tester（後述§4）
- テスト用 Facebook ページ＋連携済み Instagram ビジネスアカウント
- 録画ツール：QuickTime / OBS / Loom 等

### 撮影シナリオ

| # | 操作 | 画面で見せる内容 | カバーされる権限 |
|---|---|---|---|
| 1 | `https://mimamori-web.jp/login/` を開きログイン | ログインフォーム → ダッシュボードへ遷移 | （前提） |
| 2 | サイドメニュー「ホームページ」→ 該当はないので、URLバーで `/social-connect/` へ移動 | SNS 連携ページが開く | （前提） |
| 3 | 「Metaと接続」ボタンをクリック | Facebook ログインダイアログが開く | OAuth 開始 |
| 4 | テスト Facebook アカウントでログイン → 権限同意 | 権限要求一覧（pages_show_list, pages_read_engagement, pages_manage_posts, instagram_basic, instagram_content_publish）が表示される | 申請対象 5 権限が要求されている証跡 |
| 5 | 「次へ」→ 投稿先ページ選択（複数ある場合） | 「あなたが管理するページ」一覧 | `pages_show_list` |
| 6 | 同意完了 → コールバック → SNS 連携ページに「Meta と接続しました」表示 | 接続済み状態（Facebook ページ名、Instagram ビジネスアカウント名が表示） | `pages_read_engagement`, `instagram_basic` |
| 7 | 「SNS投稿管理ページへ」をクリック → `/social-posts/` | 投稿コンポーザーが開く | （前提） |
| 8 | テキストを入力 + 画像をアップロード + 投稿先「Facebook ページ」「Instagram」両方にチェック → 「今すぐ投稿」 | 投稿実行ボタン押下 | `pages_manage_posts`, `instagram_content_publish` |
| 9 | 投稿成功表示 → 別タブで Facebook ページを開いて投稿が表示されていることを確認 | Facebook ページ上の実際の投稿 | `pages_manage_posts` の結果 |
| 10 | 別タブで Instagram アプリ・Web を開き投稿が表示されていることを確認 | Instagram 上の実際の投稿 | `instagram_content_publish` の結果 |
| 11 | SNS 連携ページに戻り「接続を解除」をクリック → 確認 → 解除完了 | 解除済みの表示。後述する Data Deletion 動作の証跡にもなる | （後段の Data Deletion 申請にも流用可） |

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
- サイドメニューに「**SNS連携**」が出ている
- サイドメニューに「**SNS投稿管理**」が出ている
- 「Metaと接続」ボタンが押せる
- 接続後、投稿コンポーザーで実際に投稿できる

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

After login, the user will see "SNS連携" and "SNS投稿管理" in the left sidebar.
Click "SNS連携" → click "Metaと接続" to start the OAuth flow.
After connecting, click "SNS投稿管理ページへ" and use the composer to publish to Facebook Page and Instagram.
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
- [ ] `https://mimamori-web.jp/social-posts/` で投稿コンポーザーが開く
- [ ] テスト投稿が Facebook ページ・Instagram にそれぞれ反映される
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
