<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Settings_Page' ) ) { return; }

/**
 * Mimamori_Bot_Settings_Page
 *
 * テナント設定・キー発行・埋め込みコード表示ページ。
 *
 * フォーム送信は admin-post.php 経由。CSRF は wp_nonce_field で保護。
 */
class Mimamori_Bot_Settings_Page {

	const NONCE_ACTION_CREATE     = 'mimamori_bot_create_tenant';
	const NONCE_ACTION_UPDATE     = 'mimamori_bot_update_tenant';
	const NONCE_ACTION_REGENERATE = 'mimamori_bot_regenerate_keys';

	public static function init_hooks(): void {
		Mimamori_Bot_Logger::info( 'settings_page: init_hooks() called' );
		add_action( 'admin_post_mimamori_bot_create_tenant',     [ __CLASS__, 'handle_create' ] );
		add_action( 'admin_post_mimamori_bot_update_tenant',     [ __CLASS__, 'handle_update' ] );
		add_action( 'admin_post_mimamori_bot_regenerate_keys',   [ __CLASS__, 'handle_regenerate' ] );
		// すべての admin_post_* を傍受してログ出力 (診断用)
		add_action( 'admin_init', static function () {
			$action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
			$is_post = ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST';
			$is_admin_post = false !== strpos( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ), 'admin-post.php' );
			if ( $is_post && $is_admin_post ) {
				Mimamori_Bot_Logger::info( 'admin_init on admin-post.php', [
					'action'   => $action,
					'post_keys' => array_keys( $_POST ),
					'user_id'  => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				] );
			}
		}, 1 );
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( 'forbidden' );
		}
		$user_id = get_current_user_id();
		$tenant  = Mimamori_Bot_Tenant_Repository::find_for_user( $user_id );

		echo '<div class="wrap">';
		echo '<h1>みまもりチャットボット</h1>';

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
		}
		if ( isset( $_GET['created'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>テナントを作成しました。</p></div>';
		}
		if ( isset( $_GET['regenerated'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>キーを再発行しました。既存の埋め込みコードを更新してください。</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
		}

		if ( ! $tenant ) {
			self::render_create_form();
		} else {
			self::render_settings_form( $tenant );
			self::render_embed_code( $tenant );
			self::render_regenerate_form( $tenant );
			self::render_test_console( $tenant );
		}
		echo '</div>';
	}

	private static function render_create_form(): void {
		echo '<h2>テナント発行</h2>';
		echo '<p>現在のユーザーにはまだチャットボットテナントが割り当てられていません。下記から作成してください。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION_CREATE );
		echo '<input type="hidden" name="action" value="mimamori_bot_create_tenant">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="slug">スラッグ</label></th><td><input type="text" id="slug" name="slug" class="regular-text" pattern="[a-z0-9\-]{3,32}" required placeholder="例: ekc-001"><p class="description">英小文字・数字・ハイフン 3〜32字。あとから変更できません。</p></td></tr>';
		echo '<tr><th><label for="name">表示名</label></th><td><input type="text" id="name" name="name" class="regular-text" required placeholder="例: EKC ポスティング"></td></tr>';
		echo '</tbody></table>';
		submit_button( 'テナントを作成' );
		echo '</form>';
	}

	private static function render_settings_form( array $tenant ): void {
		echo '<h2>基本設定</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION_UPDATE );
		echo '<input type="hidden" name="action" value="mimamori_bot_update_tenant">';
		echo '<input type="hidden" name="tenant_id" value="' . esc_attr( (string) $tenant['id'] ) . '">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th>スラッグ</th><td><code>' . esc_html( $tenant['slug'] ) . '</code></td></tr>';

		echo '<tr><th><label for="name">表示名</label></th><td>';
		echo '<input type="text" id="name" name="name" class="regular-text" value="' . esc_attr( $tenant['name'] ) . '">';
		echo '</td></tr>';

		echo '<tr><th><label for="persona">ペルソナ</label></th><td>';
		echo '<input type="text" id="persona" name="persona" class="regular-text" value="' . esc_attr( (string) ( $tenant['persona'] ?? '' ) ) . '" placeholder="例: ポスティング会社の見積アシスタント">';
		echo '</td></tr>';

		echo '<tr><th><label for="cta_url_quote">見積もりCTA URL</label></th><td>';
		echo '<input type="url" id="cta_url_quote" name="cta_url_quote" class="regular-text" value="' . esc_attr( (string) ( $tenant['cta_url_quote'] ?? '' ) ) . '" placeholder="https://example.com/quote">';
		echo '</td></tr>';

		echo '<tr><th><label for="cta_url_contact">問い合わせCTA URL</label></th><td>';
		echo '<input type="url" id="cta_url_contact" name="cta_url_contact" class="regular-text" value="' . esc_attr( (string) ( $tenant['cta_url_contact'] ?? '' ) ) . '" placeholder="https://example.com/contact">';
		echo '</td></tr>';

		echo '<tr><th><label for="allowed_origins">許可Origin</label></th><td>';
		$ao = is_array( $tenant['allowed_origins'] ) ? implode( "\n", $tenant['allowed_origins'] ) : '';
		echo '<textarea id="allowed_origins" name="allowed_origins" rows="4" cols="60" placeholder="https://example.com\nhttps://www.example.com">' . esc_textarea( $ao ) . '</textarea>';
		echo '<p class="description">1行1Origin。Widgetを設置するサイトを記載。プロトコル(https://)必須。</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="rate_limit_rpm">レート制限 (req/分)</label></th><td>';
		echo '<input type="number" id="rate_limit_rpm" name="rate_limit_rpm" min="10" max="600" value="' . esc_attr( (string) $tenant['rate_limit_rpm'] ) . '"> req/分';
		echo '</td></tr>';

		echo '<tr><th><label for="monthly_budget_jpy">月次バジェット (円)</label></th><td>';
		echo '<input type="number" id="monthly_budget_jpy" name="monthly_budget_jpy" min="0" value="' . esc_attr( (string) ( $tenant['monthly_budget_jpy'] ?? '' ) ) . '"> 円<p class="description">0または空欄で無制限。超過すると公開Widgetが一時停止します。</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="system_prompt">システム指示 (プロンプト)</label></th><td>';
		$sp = (string) ( $tenant['system_prompt'] ?? '' );
		echo '<textarea id="system_prompt" name="system_prompt" rows="8" cols="80" style="font-family:monospace">' . esc_textarea( $sp ) . '</textarea>';
		echo '<p class="description">AIに与える役割・口調・禁則事項などを記述。</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="status">ステータス</label></th><td>';
		$status = (string) ( $tenant['status'] ?? 'active' );
		echo '<select id="status" name="status">';
		echo '<option value="active"' . selected( $status, 'active', false ) . '>稼働中</option>';
		echo '<option value="suspended"' . selected( $status, 'suspended', false ) . '>停止</option>';
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( '設定を保存' );
		echo '</form>';
	}

	private static function render_embed_code( array $tenant ): void {
		echo '<h2>埋め込みコード</h2>';
		echo '<p>下記コードをクライアントサイトの <code>&lt;/body&gt;</code> 直前にコピー＆ペーストしてください。</p>';
		$widget_url = esc_url( MIMAMORI_BOT_URL . 'public/widget/widget.js' );
		$pk         = esc_attr( $tenant['public_key'] );
		$slug       = esc_attr( $tenant['slug'] );

		$snippet = sprintf(
			"<script>\n  (function(){\n    var s=document.createElement('script');\n    s.src='%s';\n    s.async=true;\n    s.dataset.tenant='%s';\n    s.dataset.key='%s';\n    document.head.appendChild(s);\n  })();\n</script>",
			$widget_url, $slug, $pk
		);
		echo '<textarea rows="9" cols="80" readonly onclick="this.select()" style="font-family:monospace;background:#f6f7f7">' . esc_textarea( $snippet ) . '</textarea>';
		echo '<p><strong>公開キー:</strong> <code>' . esc_html( $tenant['public_key'] ) . '</code></p>';
	}

	private static function render_regenerate_form( array $tenant ): void {
		echo '<h2>キー再発行</h2>';
		echo '<p>キーを再発行すると既存の埋め込みコードは無効になります。差し替えが必要です。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'キーを再発行します。既存埋め込みは無効になります。続行しますか？\');">';
		wp_nonce_field( self::NONCE_ACTION_REGENERATE );
		echo '<input type="hidden" name="action" value="mimamori_bot_regenerate_keys">';
		echo '<input type="hidden" name="tenant_id" value="' . esc_attr( (string) $tenant['id'] ) . '">';
		submit_button( '🔁 キーを再発行', 'delete' );
		echo '</form>';
	}

	private static function render_test_console( array $tenant ): void {
		echo '<h2>動作確認</h2>';
		$embed = esc_url( rest_url( MIMAMORI_BOT_REST_NS . '/embed?tenant=' . rawurlencode( $tenant['slug'] ) ) );
		echo '<p>iframe テスト URL (許可Originに <code>' . esc_html( home_url() ) . '</code> を入れた状態で確認):</p>';
		echo '<p><a href="' . $embed . '" target="_blank" rel="noopener">' . $embed . '</a></p>';
	}

	public static function handle_create(): void {
		Mimamori_Bot_Logger::info( 'handle_create: entry', [
			'user_id' => get_current_user_id(),
			'post_keys' => array_keys( $_POST ),
		] );
		check_admin_referer( self::NONCE_ACTION_CREATE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );

		$user_id = get_current_user_id();
		$slug    = isset( $_POST['slug'] ) ? sanitize_title( (string) $_POST['slug'] ) : '';
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( (string) $_POST['name'] ) : '';
		Mimamori_Bot_Logger::info( 'handle_create: parsed', [ 'slug' => $slug, 'name' => $name ] );

		if ( ! preg_match( '/^[a-z0-9\-]{3,32}$/', $slug ) || $name === '' ) {
			self::redirect_with_error( '入力に不備があります。' );
		}
		if ( Mimamori_Bot_Tenant_Repository::find_for_user( $user_id ) ) {
			self::redirect_with_error( 'このユーザーには既にテナントが存在します。' );
		}
		if ( Mimamori_Bot_Tenant_Repository::find_by_slug( $slug ) ) {
			self::redirect_with_error( 'そのスラッグは既に使用されています。' );
		}

		try {
			Mimamori_Bot_Tenant_Repository::create( $user_id, $slug, $name );
		} catch ( \Throwable $e ) {
			Mimamori_Bot_Logger::error( 'create_tenant failed', [ 'msg' => $e->getMessage() ] );
			self::redirect_with_error( '作成に失敗しました: ' . $e->getMessage() );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG, 'created' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_update(): void {
		check_admin_referer( self::NONCE_ACTION_UPDATE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );

		$id     = isset( $_POST['tenant_id'] ) ? absint( $_POST['tenant_id'] ) : 0;
		$user_id = get_current_user_id();
		$tenant = $id ? Mimamori_Bot_Tenant_Repository::find( $id ) : null;
		if ( ! $tenant || (int) $tenant['user_id'] !== $user_id ) {
			self::redirect_with_error( 'テナントが見つかりません。' );
		}

		$origins_raw = isset( $_POST['allowed_origins'] ) ? (string) wp_unslash( $_POST['allowed_origins'] ) : '';
		$origins     = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $origins_raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) continue;
			$url = esc_url_raw( $line );
			$parts = wp_parse_url( $url );
			if ( ! $url || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}
			$normalized = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			$origins[] = $normalized;
		}
		$origins = array_values( array_unique( $origins ) );

		$status = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'active';
		if ( ! in_array( $status, [ 'active', 'suspended' ], true ) ) {
			$status = 'active';
		}

		$budget_raw = isset( $_POST['monthly_budget_jpy'] ) ? trim( (string) $_POST['monthly_budget_jpy'] ) : '';
		$budget     = $budget_raw === '' ? null : max( 0, absint( $budget_raw ) );

		$fields = [
			'name'               => sanitize_text_field( (string) ( $_POST['name'] ?? '' ) ),
			'persona'            => sanitize_text_field( (string) ( $_POST['persona'] ?? '' ) ),
			'cta_url_quote'      => esc_url_raw( (string) ( $_POST['cta_url_quote'] ?? '' ) ),
			'cta_url_contact'    => esc_url_raw( (string) ( $_POST['cta_url_contact'] ?? '' ) ),
			'allowed_origins'    => wp_json_encode( $origins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'rate_limit_rpm'     => max( 10, min( 600, absint( $_POST['rate_limit_rpm'] ?? 60 ) ) ),
			'system_prompt'      => isset( $_POST['system_prompt'] ) ? (string) wp_unslash( $_POST['system_prompt'] ) : '',
			'status'             => $status,
		];
		if ( $budget !== null ) {
			$fields['monthly_budget_jpy'] = $budget;
		}

		Mimamori_Bot_Tenant_Repository::update( (int) $tenant['id'], $fields );

		wp_safe_redirect( add_query_arg( [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_regenerate(): void {
		check_admin_referer( self::NONCE_ACTION_REGENERATE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );

		$id      = isset( $_POST['tenant_id'] ) ? absint( $_POST['tenant_id'] ) : 0;
		$user_id = get_current_user_id();
		$tenant  = $id ? Mimamori_Bot_Tenant_Repository::find( $id ) : null;
		if ( ! $tenant || (int) $tenant['user_id'] !== $user_id ) {
			self::redirect_with_error( 'テナントが見つかりません。' );
		}

		Mimamori_Bot_Tenant_Repository::regenerate_keys( (int) $tenant['id'] );
		wp_safe_redirect( add_query_arg( [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG, 'regenerated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function redirect_with_error( string $message ): void {
		wp_safe_redirect( add_query_arg( [
			'page'  => Mimamori_Bot_Admin_Menu::PAGE_SLUG,
			'error' => rawurlencode( $message ),
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}

// admin-post.php フックを早めに登録
Mimamori_Bot_Settings_Page::init_hooks();
