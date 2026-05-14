=== みまもりウェブ 問い合わせ集計API ===
Contributors: gcrev
Tags: mimamori, contact-form-7, flamingo, mw-wp-form, rest-api
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flamingo / MW WP Form の問い合わせデータを月単位で集計し、みまもりウェブから取得できる REST API を提供します。

== 説明 ==

みまもりウェブ（https://mimamori-web.jp/）から、契約サイトの問い合わせ件数を「確定値」として月初に取得するためのプラグインです。

* Flamingo（カスタム投稿 `flamingo_inbound`）に対応
* MW WP Form（CPT `mwf_inquiry` または旧式 `wp_mwf_entries` テーブル）に対応
* スパム / テスト / 営業を自動除外し「有効問い合わせ数」を返却
* トークン認証（任意で IP 制限）で保護
* 1.1.0 から: トークンを自動生成し、wp-admin の設定画面で確認・コピーが可能 (wp-config.php の編集不要)

== 設定 (1.1.0 以降の標準手順) ==

1. プラグインをインストール → 有効化
2. wp-admin → **設定 → みまもり問い合わせAPI** を開く
3. 表示された **エンドポイント URL** と **トークン** をコピー
4. みまもりウェブ管理画面の「問い合わせ取得設定」に貼り付ける

トークンは初回アクセス時に自動生成 (64文字 hex / 256bit) され、wp_options に保存されます。

== 設定 (上級者向け - wp-config.php で管理する場合) ==

DB ではなく wp-config.php で機密値を管理したい場合は以下の定数を定義します。
定数が定義されている間は wp-admin の設定画面では編集不可 (マスク表示) になります。

   `define( 'MIMAMORI_INQUIRIES_API_TOKEN', '<32文字以上のランダム文字列>' );`

任意で許可IPを指定:

   `define( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS', '203.0.113.10,203.0.113.11' );`

== APIエンドポイント ==

`GET /wp-json/mimamori/v1/inquiries?year=2026&month=4`

ヘッダ:
* `X-Mimamori-Token: <トークン>` （または `Authorization: Bearer <トークン>`）

レスポンス例:

    {
      "period": "2026-04",
      "total": 12,
      "valid": 7,
      "excluded": 5,
      "excluded_reasons": { "spam": 2, "test": 1, "sales": 2 },
      "sources": [ "flamingo" ],
      "generated_at": "2026-05-01T03:00:00+09:00"
    }

== Changelog ==

= 1.1.2 =
* 営業判定NGワードを大幅増強（「貴社」単独、「と申します」、「成果報酬」「新規アポ」「業務効率」「コスト削減」「電話対応」「TOEIC」「Web集客」「広告運用」「人材紹介」「【建築○○向け】」「お時間を頂戴」など）
* ibuki建築様の実データで「漏れ」が出ていた典型的な B2B 営業メールパターンを反映

= 1.1.1 =
* MW WP Form v3+ の「フォームごと個別CPT (mwf_<form_id>)」方式に対応
* 既存ロジックは固定CPT名 'mwf_inquiry' しか見ていなかったため、現代のMW WP Formでは取得件数が常に0だった

= 1.1.0 =
* トークンを自動生成し、wp-admin → 設定 → みまもり問い合わせAPI で確認・コピー・再発行可能に
* 許可IPも管理画面から設定可能 (wp-config.php 定数があればそちらを優先)
* wp-config.php の編集を不要に (定数による上書きは引き続き対応)

= 1.0.0 =
* 初版 (Flamingo / MW WP Form 対応 / スパム・テスト・営業の自動除外)
