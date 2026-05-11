<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Knowledge_Page' ) ) { return; }

class Mimamori_Bot_Knowledge_Page {

	const NONCE_ADD    = 'mimamori_bot_knowledge_add';
	const NONCE_DELETE = 'mimamori_bot_knowledge_delete';

	public static function init_hooks(): void {
		add_action( 'admin_post_mimamori_bot_knowledge_add',    [ __CLASS__, 'handle_add' ] );
		add_action( 'admin_post_mimamori_bot_knowledge_delete', [ __CLASS__, 'handle_delete' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Repository::find_for_user( get_current_user_id() );

		echo '<div class="wrap">';
		echo '<h1>ナレッジ管理</h1>';

		if ( ! $tenant ) {
			echo '<div class="notice notice-warning"><p>先にチャットボットの<strong>設定ページでテナントを発行</strong>してください。</p></div>';
			echo '</div>';
			return;
		}
		self::flash_notices();

		echo '<p>クライアントの料金表・配布実績・エリア情報などをテキストで登録します。AI はチャット応答時にここから引用します。</p>';

		self::render_add_form();
		self::render_list( (int) $tenant['id'] );

		echo '</div>';
	}

	private static function flash_notices(): void {
		if ( isset( $_GET['added'] ) )   echo '<div class="notice notice-success is-dismissible"><p>ナレッジを追加しました。</p></div>';
		if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>ナレッジを削除しました。</p></div>';
		if ( isset( $_GET['error'] ) )   echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
	}

	private static function render_add_form(): void {
		echo '<h2>新規追加</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ADD );
		echo '<input type="hidden" name="action" value="mimamori_bot_knowledge_add">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="kn_title">タイトル</label></th><td><input type="text" id="kn_title" name="title" class="regular-text" required maxlength="200" placeholder="例: 道後エリア配布実績"></td></tr>';
		echo '<tr><th><label for="kn_category">カテゴリ (任意)</label></th><td><input type="text" id="kn_category" name="category" class="regular-text" maxlength="80" placeholder="例: エリア / 価格 / 実績"></td></tr>';
		echo '<tr><th><label for="kn_body">本文</label></th><td><textarea id="kn_body" name="raw_text" rows="10" cols="80" required style="font-family:inherit" placeholder="改行を含む長文OK。AI はこの内容を根拠に回答します。"></textarea><p class="description">段落で区切るとチャンクが綺麗になります。1チャンク最大500字目安。</p></td></tr>';
		echo '</tbody></table>';
		submit_button( '追加する' );
		echo '</form>';
	}

	private static function render_list( int $tenant_id ): void {
		$rows = Mimamori_Bot_Knowledge_Repository::list_for_tenant( $tenant_id );

		echo '<h2>登録済みナレッジ (' . count( $rows ) . '件)</h2>';
		if ( empty( $rows ) ) {
			echo '<p>まだ登録がありません。</p>';
			return;
		}

		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>タイトル</th><th>タイプ</th><th>チャンク数</th><th>更新</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$id   = (int) $r['id'];
			$del_url = wp_nonce_url(
				add_query_arg( [
					'action'    => 'mimamori_bot_knowledge_delete',
					'id'        => $id,
				], admin_url( 'admin-post.php' ) ),
				self::NONCE_DELETE
			);
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $r['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( (string) $r['source_type'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $r['chunk_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['updated_at'] ) . '</td>';
			echo '<td><a href="' . esc_url( $del_url ) . '" class="button-link-delete" onclick="return confirm(\'削除しますか？関連チャンクも全て消えます。\');">削除</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function handle_add(): void {
		check_admin_referer( self::NONCE_ADD );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Repository::find_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		$title    = sanitize_text_field( (string) ( $_POST['title'] ?? '' ) );
		$category = sanitize_text_field( (string) ( $_POST['category'] ?? '' ) );
		$raw_text = isset( $_POST['raw_text'] ) ? (string) wp_unslash( $_POST['raw_text'] ) : '';
		$raw_text = wp_check_invalid_utf8( $raw_text );

		if ( $title === '' || trim( $raw_text ) === '' ) {
			self::back( 'タイトルと本文は必須です。' );
		}
		try {
			Mimamori_Bot_Knowledge_Repository::add_text( (int) $tenant['id'], $title, $raw_text, $category !== '' ? $category : null );
		} catch ( \Throwable $e ) {
			Mimamori_Bot_Logger::error( 'knowledge add failed', [ 'msg' => $e->getMessage() ] );
			self::back( '追加に失敗しました: ' . $e->getMessage() );
		}
		self::back_ok( 'added' );
	}

	public static function handle_delete(): void {
		check_admin_referer( self::NONCE_DELETE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Repository::find_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id <= 0 ) self::back( '不正なIDです。' );

		Mimamori_Bot_Knowledge_Repository::delete( (int) $tenant['id'], $id );
		self::back_ok( 'deleted' );
	}

	private static function back_ok( string $flag ): void {
		wp_safe_redirect( add_query_arg( [
			'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_KNOWLEDGE,
			$flag  => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function back( string $error ): void {
		wp_safe_redirect( add_query_arg( [
			'page'  => Mimamori_Bot_Admin_Menu::PAGE_SLUG_KNOWLEDGE,
			'error' => rawurlencode( $error ),
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}

Mimamori_Bot_Knowledge_Page::init_hooks();
