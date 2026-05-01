=== みまもりウェブ 問い合わせ集計API ===
Contributors: gcrev
Tags: mimamori, contact-form-7, flamingo, mw-wp-form, rest-api
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flamingo / MW WP Form の問い合わせデータを月単位で集計し、みまもりウェブから取得できる REST API を提供します。

== 説明 ==

みまもりウェブ（https://mimamori-web.jp/）から、契約サイトの問い合わせ件数を「確定値」として月初に取得するためのプラグインです。

* Flamingo（カスタム投稿 `flamingo_inbound`）に対応
* MW WP Form（CPT `mwf_inquiry` または旧式 `wp_mwf_entries` テーブル）に対応
* スパム / テスト / 営業を自動除外し「有効問い合わせ数」を返却
* トークン認証（任意で IP 制限）で保護

== 設定 ==

1. プラグインを有効化
2. `wp-config.php` に以下を追加:

   `define( 'MIMAMORI_INQUIRIES_API_TOKEN', '<32文字以上のランダム文字列>' );`

3. 任意で許可 IP を指定:

   `define( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS', '203.0.113.10,203.0.113.11' );`

4. みまもりウェブ管理画面の「問い合わせ取得設定」に、サイト URL とトークンを登録

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
