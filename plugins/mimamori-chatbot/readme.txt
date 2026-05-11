=== みまもりチャットボット ===
Contributors: mimamori-web
Tags: chatbot, ai, openai, multi-tenant
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0-or-later

クライアントサイトに <script> 1行で埋め込める公開AIチャットボット。OpenAI連携・マルチテナント対応・ナレッジ管理。

== Description ==

みまもりウェブのオプション機能として、クライアントのLP/HPに設置可能なAIチャットボットを提供します。

* マルチテナント（クライアント毎にデータ分離）
* OpenAI Responses API 連携
* ナレッジ管理 / FAQ管理（Phase 2）
* セッション履歴・分析（Phase 2）
* iframe ベースの安全な埋め込み

== Installation ==

1. プラグインを `/wp-content/plugins/mimamori-chatbot` にアップロード
2. WP管理画面でプラグインを有効化（テーブル自動作成）
3. 「みまもりChatbot」メニューから初期テナントを発行
4. 表示された `<script>` タグをクライアントサイトに埋め込み

== Configuration ==

`wp-config.php` に以下を追加（推奨）:

    define( 'GCREV_ENCRYPTION_KEY', '<base64 32bytes>' );
    define( 'MIMAMORI_BOT_OPENAI_KEY', 'sk-...' );

== Changelog ==

= 0.1.0 =
* Initial skeleton: 公開API最小セット、管理画面、Widget雛形。
