<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Knowledge_Page' ) ) { return; }

class Mimamori_Bot_Knowledge_Page {

	const NONCE_ADD      = 'mimamori_bot_knowledge_add';
	const NONCE_UPLOAD   = 'mimamori_bot_knowledge_upload';
	const NONCE_DELETE   = 'mimamori_bot_knowledge_delete';
	const NONCE_REINDEX  = 'mimamori_bot_knowledge_reindex';

	public static function init_hooks(): void {
		add_action( 'admin_post_mimamori_bot_knowledge_add',     [ __CLASS__, 'handle_add' ] );
		add_action( 'admin_post_mimamori_bot_knowledge_upload',  [ __CLASS__, 'handle_upload' ] );
		add_action( 'admin_post_mimamori_bot_knowledge_delete',  [ __CLASS__, 'handle_delete' ] );
		add_action( 'admin_post_mimamori_bot_knowledge_reindex', [ __CLASS__, 'handle_reindex' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$user_id = get_current_user_id();
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( $user_id );

		echo '<div class="wrap">';
		echo '<h1>ナレッジ管理</h1>';

		Mimamori_Bot_Tenant_Context::render_switcher( $user_id, Mimamori_Bot_Admin_Menu::PAGE_SLUG_KNOWLEDGE, $tenant );

		if ( ! $tenant ) {
			echo '<div class="notice notice-warning"><p>先にチャットボットの<strong>設定ページでテナントを発行</strong>してください。</p></div>';
			echo '</div>';
			return;
		}
		self::flash_notices();

		echo '<p>サービス内容・料金プラン・FAQ・会社案内・対応事例などをテキストで登録します。AI はチャット応答時にここから引用します。</p>';

		self::render_add_form();
		self::render_upload_form();
		self::render_reindex_form( (int) $tenant['id'] );
		self::render_list( (int) $tenant['id'] );

		echo '</div>';
	}

	private static function flash_notices(): void {
		if ( isset( $_GET['added'] ) )    echo '<div class="notice notice-success is-dismissible"><p>ナレッジを追加しました。</p></div>';
		if ( isset( $_GET['uploaded'] ) ) echo '<div class="notice notice-success is-dismissible"><p>ファイルを取り込みました。</p></div>';
		if ( isset( $_GET['deleted'] ) )  echo '<div class="notice notice-success is-dismissible"><p>ナレッジを削除しました。</p></div>';
		if ( isset( $_GET['reindexed'] ) ) {
			$n = absint( $_GET['reindexed'] );
			echo '<div class="notice notice-success is-dismissible"><p>埋め込みを再生成しました (' . esc_html( (string) $n ) . ' 件)。</p></div>';
		}
		if ( isset( $_GET['error'] ) )   echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
	}

	private static function render_add_form(): void {
		echo '<h2>新規追加</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ADD );
		echo '<input type="hidden" name="action" value="mimamori_bot_knowledge_add">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="kn_title">タイトル</label></th><td><input type="text" id="kn_title" name="title" class="regular-text" required maxlength="200" placeholder="例: サービス料金表 / よくある質問 / 会社概要"></td></tr>';
		echo '<tr><th><label for="kn_category">カテゴリ (任意)</label></th><td><input type="text" id="kn_category" name="category" class="regular-text" maxlength="80" placeholder="例: 料金 / サービス / FAQ / 会社情報"></td></tr>';
		echo '<tr><th><label for="kn_body">本文</label></th><td><textarea id="kn_body" name="raw_text" rows="10" cols="80" required style="font-family:inherit" placeholder="改行を含む長文OK。AI はこの内容を根拠に回答します。"></textarea><p class="description">段落で区切るとチャンクが綺麗になります。1チャンク最大500字目安。</p></td></tr>';
		echo '</tbody></table>';
		submit_button( '追加する' );
		echo '</form>';
	}

	private static function render_upload_form(): void {
		echo '<h2>ファイルから取り込み</h2>';
		echo '<p>対応: <code>.txt</code> <code>.md</code> <code>.csv</code> <code>.html</code> <code>.pdf</code> (テキスト2MB / 画像4MB)。'
			. '<code>.jpg</code> <code>.png</code> <code>.webp</code> は OpenAI Vision で日本語OCRします (APIコール発生)。</p>';
		echo '<p><em>注: PDFはサーバーに <code>smalot/pdfparser</code> が導入されている必要があります。未導入時は明確なエラーが返ります。</em></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_UPLOAD );
		echo '<input type="hidden" name="action" value="mimamori_bot_knowledge_upload">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="up_title">タイトル (任意)</label></th><td><input type="text" id="up_title" name="title" class="regular-text" maxlength="200" placeholder="空ならファイル名をそのまま使用"></td></tr>';
		echo '<tr><th><label for="up_category">カテゴリ (任意)</label></th><td><input type="text" id="up_category" name="category" class="regular-text" maxlength="80"></td></tr>';
		echo '<tr><th><label for="up_file">ファイル</label></th><td><input type="file" id="up_file" name="file" accept=".txt,.md,.markdown,.csv,.html,.htm,.pdf,.jpg,.jpeg,.png,.webp" required></td></tr>';
		echo '</tbody></table>';
		submit_button( '取り込み', 'secondary' );
		echo '</form>';
	}

	private static function render_reindex_form( int $tenant_id ): void {
		global $wpdb;
		$ct = Mimamori_Bot_Installer::table_knowledge_chunks();
		$ft = Mimamori_Bot_Installer::table_faq();
		$missing_chunks = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$ct} WHERE tenant_id = %d AND embedding IS NULL", $tenant_id
		) );
		$missing_faqs = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$ft} WHERE tenant_id = %d AND embedding IS NULL AND status = 'active'", $tenant_id
		) );

		if ( $missing_chunks === 0 && $missing_faqs === 0 ) {
			return;
		}
		echo '<h2>埋め込み再生成</h2>';
		echo '<p>埋め込みベクトル未生成のデータがあります — チャンク <strong>' . esc_html( (string) $missing_chunks ) . '</strong>件 / FAQ <strong>' . esc_html( (string) $missing_faqs ) . '</strong>件。意味的類似検索を有効にするため再インデックスを実行してください (OpenAI APIコール発生)。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'OpenAI Embeddings API を呼び出します (コスト発生)。実行しますか？\');">';
		wp_nonce_field( self::NONCE_REINDEX );
		echo '<input type="hidden" name="action" value="mimamori_bot_knowledge_reindex">';
		submit_button( '🔄 未生成分を再インデックス', 'secondary' );
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
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( get_current_user_id() );
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

	public static function handle_upload(): void {
		check_admin_referer( self::NONCE_UPLOAD );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		if ( empty( $_FILES['file'] ) ) {
			self::back( 'ファイルが選択されていません。' );
		}
		$title    = sanitize_text_field( (string) ( $_POST['title']    ?? '' ) );
		$category = sanitize_text_field( (string) ( $_POST['category'] ?? '' ) );
		try {
			Mimamori_Bot_Knowledge_Repository::add_from_upload(
				(int) $tenant['id'],
				$_FILES['file'],
				$title !== '' ? $title : null,
				$category !== '' ? $category : null
			);
		} catch ( \Throwable $e ) {
			Mimamori_Bot_Logger::error( 'knowledge upload failed', [ 'msg' => $e->getMessage() ] );
			self::back( '取り込みに失敗しました: ' . $e->getMessage() );
		}
		self::back_ok( 'uploaded' );
	}

	public static function handle_reindex(): void {
		check_admin_referer( self::NONCE_REINDEX );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		$r1 = Mimamori_Bot_Knowledge_Repository::reindex_tenant( (int) $tenant['id'] );
		$r2 = Mimamori_Bot_Faq_Repository::reindex_tenant( (int) $tenant['id'] );
		$total_saved = (int) $r1['saved'] + (int) $r2['saved'];

		$ret = Mimamori_Bot_Settings_Page::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ 'reindexed' => $total_saved ], $ret ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( [
			'page'      => Mimamori_Bot_Admin_Menu::PAGE_SLUG_KNOWLEDGE,
			'reindexed' => $total_saved,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		check_admin_referer( self::NONCE_DELETE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id <= 0 ) self::back( '不正なIDです。' );

		Mimamori_Bot_Knowledge_Repository::delete( (int) $tenant['id'], $id );
		self::back_ok( 'deleted' );
	}

	private static function back_ok( string $flag ): void {
		$ret = Mimamori_Bot_Settings_Page::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ $flag => 1 ], $ret ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( [
			'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_KNOWLEDGE,
			$flag  => 1,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function back( string $error ): void {
		$ret = Mimamori_Bot_Settings_Page::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ 'error' => rawurlencode( $error ) ], $ret ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( [
			'page'  => Mimamori_Bot_Admin_Menu::PAGE_SLUG_KNOWLEDGE,
			'error' => rawurlencode( $error ),
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}

Mimamori_Bot_Knowledge_Page::init_hooks();
