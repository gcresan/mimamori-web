<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Settings_Page' ) ) { return; }

/**
 * Mimamori_Bot_Settings_Page
 *
 * テナント設定・キー発行・埋め込みコード表示ページ。
 *
 * 表示モード:
 *   - 運営者 (manage_options) かつ ?tenant_id 未指定 → テナント一覧
 *   - 運営者 ?tenant_id 指定 → そのテナントの編集画面
 *   - 一般ユーザー → 自分のテナント編集画面 (無ければ作成フォーム)
 *
 * フォーム送信は admin-post.php 経由。CSRF は wp_nonce_field で保護。
 */
class Mimamori_Bot_Settings_Page {

	const NONCE_ACTION_CREATE     = 'mimamori_bot_create_tenant';
	const NONCE_ACTION_UPDATE     = 'mimamori_bot_update_tenant';
	const NONCE_ACTION_REGENERATE = 'mimamori_bot_regenerate_keys';

	public static function init_hooks(): void {
		add_action( 'admin_post_mimamori_bot_create_tenant',     [ __CLASS__, 'handle_create' ] );
		add_action( 'admin_post_mimamori_bot_update_tenant',     [ __CLASS__, 'handle_update' ] );
		add_action( 'admin_post_mimamori_bot_regenerate_keys',   [ __CLASS__, 'handle_regenerate' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( 'forbidden' );
		}
		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		echo '<div class="wrap">';
		echo '<h1>みまもりチャットボット</h1>';

		self::render_notices();

		if ( $is_admin ) {
			// 運営者: ?tenant_id 指定で編集モード、なければ一覧
			$requested = isset( $_GET['tenant_id'] ) ? absint( $_GET['tenant_id'] ) : 0;
			if ( $requested > 0 ) {
				$tenant = Mimamori_Bot_Tenant_Repository::find( $requested );
				if ( ! $tenant ) {
					echo '<div class="notice notice-error"><p>指定されたテナントは存在しません。</p></div>';
					self::render_tenant_list_for_admin();
				} else {
					// 切替UI (この画面用)
					Mimamori_Bot_Tenant_Context::render_switcher( $user_id, Mimamori_Bot_Admin_Menu::PAGE_SLUG, $tenant );
					self::render_settings_form( $tenant, $is_admin );
					self::render_embed_code( $tenant );
					self::render_regenerate_form( $tenant );
					self::render_test_console( $tenant );
				}
			} else {
				self::render_tenant_list_for_admin();
			}
		} else {
			// 一般ユーザー: 自分のテナント編集
			$tenant = Mimamori_Bot_Tenant_Repository::find_for_user( $user_id );
			if ( ! $tenant ) {
				echo '<div class="notice notice-warning"><p>あなたのアカウントにはチャットボットテナントが割り当てられていません。運営者にテナント発行を依頼してください。</p></div>';
			} else {
				self::render_settings_form( $tenant, false );
				self::render_embed_code( $tenant );
				self::render_regenerate_form( $tenant );
				self::render_test_console( $tenant );
			}
		}

		echo '</div>';
	}

	private static function render_notices(): void {
		$map = [
			'updated'     => [ 'success', '設定を保存しました。' ],
			'created'     => [ 'success', 'テナントを作成しました。' ],
			'regenerated' => [ 'warning', 'キーを再発行しました。既存の埋め込みコードを更新してください。' ],
		];
		foreach ( $map as $k => $info ) {
			if ( isset( $_GET[ $k ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $info[0] ) . ' is-dismissible"><p>' . esc_html( $info[1] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
		}
	}

	private static function render_tenant_list_for_admin(): void {
		$tenants = Mimamori_Bot_Tenant_Repository::list_all();

		echo '<h2>テナント一覧 (運営者ビュー)</h2>';
		echo '<p>各クライアントのチャットボットテナントを一括管理できます。「編集」でそのテナントの設定・ナレッジ・FAQ・履歴・分析にアクセスできます。</p>';

		if ( empty( $tenants ) ) {
			echo '<p><strong>テナントがまだありません。</strong>下記から最初のテナントを作成してください。</p>';
		} else {
			echo '<table class="wp-list-table widefat striped"><thead><tr>';
			echo '<th>ID</th><th>表示名</th><th>スラッグ</th><th>オーナー</th><th>状態</th><th>公開キー</th><th>操作</th>';
			echo '</tr></thead><tbody>';
			foreach ( $tenants as $t ) {
				$owner = get_userdata( (int) $t['user_id'] );
				$owner_label = $owner ? esc_html( $owner->user_login . ' (' . $owner->display_name . ')' ) : '<em style="color:#9ca3af">未割当</em>';
				$edit_url = add_query_arg( [
					'page'      => Mimamori_Bot_Admin_Menu::PAGE_SLUG,
					'tenant_id' => (int) $t['id'],
				], admin_url( 'admin.php' ) );
				echo '<tr>';
				echo '<td>' . esc_html( (string) (int) $t['id'] ) . '</td>';
				echo '<td><strong>' . esc_html( $t['name'] ) . '</strong></td>';
				echo '<td><code>' . esc_html( $t['slug'] ) . '</code></td>';
				echo '<td>' . $owner_label . '</td>';
				echo '<td>' . esc_html( $t['status'] ) . '</td>';
				echo '<td><code style="font-size:11px">' . esc_html( Mimamori_Bot_Logger::mask( $t['public_key'] ) ) . '</code></td>';
				echo '<td><a href="' . esc_url( $edit_url ) . '" class="button button-small">編集</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::render_create_form_for_admin();
	}

	private static function render_create_form_for_admin(): void {
		echo '<h2 style="margin-top:32px">新規テナント作成</h2>';
		echo '<p>クライアントごとに新しいテナントを発行します。オーナーユーザーを指定すると、そのユーザーが管理画面に自分のテナントとして見えるようになります。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION_CREATE );
		echo '<input type="hidden" name="action" value="mimamori_bot_create_tenant">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="slug">スラッグ</label></th><td><input type="text" id="slug" name="slug" class="regular-text" pattern="[a-z0-9\-]{3,32}" required placeholder="例: client-001"><p class="description">英小文字・数字・ハイフン 3〜32字。あとから変更できません。</p></td></tr>';

		echo '<tr><th><label for="name">表示名</label></th><td><input type="text" id="name" name="name" class="regular-text" required placeholder="例: 株式会社サンプル"></td></tr>';

		echo '<tr><th><label for="owner_user_id">オーナーユーザー</label></th><td>';
		wp_dropdown_users( [
			'name'             => 'owner_user_id',
			'show_option_none' => '— 自分 (運営者) を所有者にする —',
			'selected'         => 0,
			'role__in'         => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
		] );
		echo '<p class="description">クライアント側ユーザーを指定すると、そのユーザーがログイン時に自分のテナントとして編集可能になります。</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( 'テナントを作成' );
		echo '</form>';
	}

	private static function render_settings_form( array $tenant, bool $is_admin ): void {
		echo '<h2>基本設定 — ' . esc_html( $tenant['name'] ) . ' <code style="font-size:13px;color:#6b7280">' . esc_html( $tenant['slug'] ) . '</code></h2>';

		if ( $is_admin ) {
			$back_url = add_query_arg( [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG ], admin_url( 'admin.php' ) );
			echo '<p><a href="' . esc_url( $back_url ) . '">← テナント一覧に戻る</a></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION_UPDATE );
		echo '<input type="hidden" name="action" value="mimamori_bot_update_tenant">';
		echo '<input type="hidden" name="tenant_id" value="' . esc_attr( (string) $tenant['id'] ) . '">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>スラッグ</th><td><code>' . esc_html( $tenant['slug'] ) . '</code></td></tr>';

		// 運営者のみオーナー変更可能
		if ( $is_admin ) {
			echo '<tr><th><label for="owner_user_id_edit">オーナーユーザー</label></th><td>';
			wp_dropdown_users( [
				'name'             => 'owner_user_id',
				'id'               => 'owner_user_id_edit',
				'selected'         => (int) $tenant['user_id'],
				'show_option_none' => '— 未割当 —',
				'role__in'         => [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ],
			] );
			echo '<p class="description">オーナーユーザーが管理画面でこのテナントを編集できます。</p>';
			echo '</td></tr>';
		}

		echo '<tr><th><label for="name">表示名</label></th><td>';
		echo '<input type="text" id="name" name="name" class="regular-text" value="' . esc_attr( $tenant['name'] ) . '">';
		echo '</td></tr>';

		echo '<tr><th><label for="persona">ペルソナ</label></th><td>';
		echo '<input type="text" id="persona" name="persona" class="regular-text" value="' . esc_attr( (string) ( $tenant['persona'] ?? '' ) ) . '" placeholder="例: お問い合わせアシスタント / 商品案内スタッフ">';
		echo '</td></tr>';

		echo '<tr><th><label for="cta_url_quote">見積もりCTA URL</label></th><td>';
		echo '<input type="url" id="cta_url_quote" name="cta_url_quote" class="regular-text" value="' . esc_attr( (string) ( $tenant['cta_url_quote'] ?? '' ) ) . '" placeholder="https://example.com/quote">';
		echo '</td></tr>';

		echo '<tr><th><label for="cta_url_contact">問い合わせCTA URL</label></th><td>';
		echo '<input type="url" id="cta_url_contact" name="cta_url_contact" class="regular-text" value="' . esc_attr( (string) ( $tenant['cta_url_contact'] ?? '' ) ) . '" placeholder="https://example.com/contact">';
		echo '</td></tr>';

		echo '<tr><th><label for="allowed_origins">許可Origin</label></th><td>';
		$ao = is_array( $tenant['allowed_origins'] ) ? implode( "\n", $tenant['allowed_origins'] ) : '';
		echo '<textarea id="allowed_origins" name="allowed_origins" rows="4" cols="60" placeholder="https://example.com">' . esc_textarea( $ao ) . '</textarea>';
		echo '<p class="description">1行1Origin。Widgetを設置するサイトを記載。プロトコル(https://)必須。</p>';
		echo '</td></tr>';

		// レート制限・月次バジェットは運営者専用（クライアントには非表示）
		if ( $is_admin ) {
			echo '<tr><th><label for="rate_limit_rpm">レート制限 (req/分) <span style="font-size:11px;color:#9ca3af">[運営者のみ]</span></label></th><td>';
			echo '<input type="number" id="rate_limit_rpm" name="rate_limit_rpm" min="10" max="600" value="' . esc_attr( (string) $tenant['rate_limit_rpm'] ) . '"> req/分';
			echo '</td></tr>';

			echo '<tr><th><label for="monthly_budget_jpy">月次バジェット (円) <span style="font-size:11px;color:#9ca3af">[運営者のみ]</span></label></th><td>';
			echo '<input type="number" id="monthly_budget_jpy" name="monthly_budget_jpy" min="0" value="' . esc_attr( (string) ( $tenant['monthly_budget_jpy'] ?? '' ) ) . '"> 円<p class="description">0または空欄で無制限。超過すると公開Widgetが一時停止します。</p>';
			echo '</td></tr>';
		}

		echo '<tr><th><label for="welcome_message">初期メッセージ</label></th><td>';
		$welcome_default = "こんにちは！👋\nサービス内容・料金・対応エリアなど、お気軽にお尋ねください。\n担当者への直接のご相談もご案内できます。";
		$welcome_val     = (string) ( $tenant['welcome_message'] ?? '' );
		echo '<textarea id="welcome_message" name="welcome_message" rows="4" cols="80" maxlength="500" placeholder="' . esc_attr( $welcome_default ) . '">' . esc_textarea( $welcome_val ) . '</textarea>';
		echo '<p class="description">チャットを開いた最初に表示する案内文。改行可、200字程度推奨、絵文字1個まで。空欄でデフォルト文 (プレースホルダ参照)。</p>';
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
		check_admin_referer( self::NONCE_ACTION_CREATE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );

		$current_user_id = get_current_user_id();
		$is_admin        = current_user_can( 'manage_options' );

		$slug = isset( $_POST['slug'] ) ? sanitize_title( (string) $_POST['slug'] ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( (string) $_POST['name'] ) : '';

		// オーナーユーザー: 運営者なら指定可、一般ユーザーは強制的に自分
		$owner_id = $current_user_id;
		if ( $is_admin && isset( $_POST['owner_user_id'] ) ) {
			$picked = absint( $_POST['owner_user_id'] );
			if ( $picked > 0 ) {
				$owner_id = $picked;
			}
		}

		if ( ! preg_match( '/^[a-z0-9\-]{3,32}$/', $slug ) || $name === '' ) {
			self::redirect_with_error( '入力に不備があります。' );
		}
		if ( ! $is_admin && Mimamori_Bot_Tenant_Repository::find_for_user( $current_user_id ) ) {
			// 一般ユーザーは1テナント上限
			self::redirect_with_error( 'このユーザーには既にテナントが存在します。' );
		}
		if ( Mimamori_Bot_Tenant_Repository::find_by_slug( $slug ) ) {
			self::redirect_with_error( 'そのスラッグは既に使用されています。' );
		}

		try {
			$created = Mimamori_Bot_Tenant_Repository::create( $owner_id, $slug, $name );
		} catch ( \Throwable $e ) {
			Mimamori_Bot_Logger::error( 'create_tenant failed', [ 'msg' => $e->getMessage() ] );
			self::redirect_with_error( '作成に失敗しました: ' . $e->getMessage() );
		}

		// フロントエンドからの送信ならそちらへ
		$ret = self::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ 'created' => 1 ], $ret ) );
			exit;
		}
		// 運営者は作成したテナントを編集画面で開く
		$redirect_args = [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG, 'created' => 1 ];
		if ( $is_admin && $created ) {
			$redirect_args['tenant_id'] = (int) $created['id'];
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_update(): void {
		check_admin_referer( self::NONCE_ACTION_UPDATE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );

		$id              = isset( $_POST['tenant_id'] ) ? absint( $_POST['tenant_id'] ) : 0;
		$current_user_id = get_current_user_id();
		$is_admin        = current_user_can( 'manage_options' );
		$tenant          = $id ? Mimamori_Bot_Tenant_Repository::find( $id ) : null;

		if ( ! $tenant ) {
			self::redirect_with_error( 'テナントが見つかりません。' );
		}
		// 運営者は全テナント編集可、一般ユーザーは自分のテナントのみ
		if ( ! $is_admin && (int) $tenant['user_id'] !== $current_user_id ) {
			self::redirect_with_error( 'このテナントを編集する権限がありません。' );
		}

		// origins 正規化
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

		$fields = [
			'name'               => sanitize_text_field( (string) ( $_POST['name'] ?? '' ) ),
			'persona'            => sanitize_text_field( (string) ( $_POST['persona'] ?? '' ) ),
			'cta_url_quote'      => esc_url_raw( (string) ( $_POST['cta_url_quote'] ?? '' ) ),
			'cta_url_contact'    => esc_url_raw( (string) ( $_POST['cta_url_contact'] ?? '' ) ),
			'allowed_origins'    => wp_json_encode( $origins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'system_prompt'      => isset( $_POST['system_prompt'] ) ? (string) wp_unslash( $_POST['system_prompt'] ) : '',
			'status'             => $status,
		];

		// 初期メッセージ (改行は保持、500字で切り詰め)
		if ( isset( $_POST['welcome_message'] ) ) {
			$welcome = (string) wp_unslash( $_POST['welcome_message'] );
			$welcome = wp_check_invalid_utf8( $welcome );
			// HTMLは禁止（タグ除去）
			$welcome = wp_strip_all_tags( $welcome );
			$fields['welcome_message'] = mb_substr( trim( $welcome ), 0, 500 );
		}

		// 見た目カスタマイズ — タイトル / カラー / FAB
		if ( isset( $_POST['title_text'] ) ) {
			$fields['title_text'] = mb_substr( sanitize_text_field( (string) $_POST['title_text'] ), 0, 80 );
		}
		foreach ( [ 'theme_primary', 'theme_on_primary', 'fab_bg_color' ] as $ck ) {
			if ( isset( $_POST[ $ck ] ) ) {
				$cv = trim( (string) $_POST[ $ck ] );
				if ( $cv === '' || Mimamori_Bot_Tenant_Repository::is_valid_color( $cv ) ) {
					$fields[ $ck ] = $cv; // 空文字 = デフォルト復帰
				}
			}
		}
		if ( isset( $_POST['fab_icon_url'] ) ) {
			$icon = esc_url_raw( (string) $_POST['fab_icon_url'] );
			$fields['fab_icon_url'] = mb_substr( $icon, 0, 500 );
		}
		if ( isset( $_POST['fab_offset_x'] ) ) {
			$fields['fab_offset_x'] = max( 0, min( 200, (int) $_POST['fab_offset_x'] ) );
		}
		if ( isset( $_POST['fab_offset_y'] ) ) {
			$fields['fab_offset_y'] = max( 0, min( 200, (int) $_POST['fab_offset_y'] ) );
		}
		// スマホ用 X/Y: 値があれば fields に積み、空欄なら後で NULL 直接更新 (wpdb->update は NULL を %d に変換できないため)
		$sp_nulls = [];
		foreach ( [ 'fab_offset_x_sp', 'fab_offset_y_sp' ] as $sp_key ) {
			if ( ! isset( $_POST[ $sp_key ] ) ) continue;
			$raw = trim( (string) $_POST[ $sp_key ] );
			if ( $raw === '' ) {
				$sp_nulls[] = $sp_key;
			} else {
				$fields[ $sp_key ] = max( 0, min( 200, (int) $raw ) );
			}
		}

		// レート制限・月次バジェットは運営者専用。クライアントからのPOSTは無視する。
		if ( $is_admin ) {
			if ( isset( $_POST['rate_limit_rpm'] ) ) {
				$fields['rate_limit_rpm'] = max( 10, min( 600, absint( $_POST['rate_limit_rpm'] ) ) );
			}
			$budget_raw = isset( $_POST['monthly_budget_jpy'] ) ? trim( (string) $_POST['monthly_budget_jpy'] ) : null;
			if ( $budget_raw !== null && $budget_raw !== '' ) {
				$fields['monthly_budget_jpy'] = max( 0, absint( $budget_raw ) );
			}
		}

		Mimamori_Bot_Tenant_Repository::update( (int) $tenant['id'], $fields );

		// SP 用 X/Y を NULL に戻すケース (空欄送信)
		if ( ! empty( $sp_nulls ) ) {
			global $wpdb;
			$table = Mimamori_Bot_Installer::table_tenants();
			$set   = implode( ', ', array_map( static function ( $c ) { return "{$c} = NULL"; }, $sp_nulls ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$set} WHERE id = %d", (int) $tenant['id'] ) );
		}

		// 運営者のみオーナー変更可
		if ( $is_admin && isset( $_POST['owner_user_id'] ) ) {
			$new_owner = absint( $_POST['owner_user_id'] );
			if ( $new_owner > 0 && $new_owner !== (int) $tenant['user_id'] ) {
				global $wpdb;
				$wpdb->update(
					Mimamori_Bot_Installer::table_tenants(),
					[ 'user_id' => $new_owner ],
					[ 'id' => (int) $tenant['id'] ],
					[ '%d' ],
					[ '%d' ]
				);
				Mimamori_Bot_Logger::info( 'tenant: owner changed', [
					'id'        => (int) $tenant['id'],
					'old_owner' => (int) $tenant['user_id'],
					'new_owner' => $new_owner,
				] );
			}
		}

		// フロントエンドからの送信ならそちらへ
		$ret = self::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ 'updated' => 1 ], $ret ) );
			exit;
		}
		// リダイレクト: 編集画面に戻す (運営者の場合は tenant_id を維持)
		$redirect_args = [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG, 'updated' => 1 ];
		if ( $is_admin ) {
			$redirect_args['tenant_id'] = (int) $tenant['id'];
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_regenerate(): void {
		check_admin_referer( self::NONCE_ACTION_REGENERATE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );

		$id              = isset( $_POST['tenant_id'] ) ? absint( $_POST['tenant_id'] ) : 0;
		$current_user_id = get_current_user_id();
		$is_admin        = current_user_can( 'manage_options' );
		$tenant          = $id ? Mimamori_Bot_Tenant_Repository::find( $id ) : null;

		if ( ! $tenant ) {
			self::redirect_with_error( 'テナントが見つかりません。' );
		}
		if ( ! $is_admin && (int) $tenant['user_id'] !== $current_user_id ) {
			self::redirect_with_error( 'このテナントを操作する権限がありません。' );
		}

		Mimamori_Bot_Tenant_Repository::regenerate_keys( (int) $tenant['id'] );

		$ret = self::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ 'regenerated' => 1 ], $ret ) );
			exit;
		}
		$redirect_args = [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG, 'regenerated' => 1 ];
		if ( $is_admin ) {
			$redirect_args['tenant_id'] = (int) $tenant['id'];
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function redirect_with_error( string $message ): void {
		$args = [
			'page'  => Mimamori_Bot_Admin_Menu::PAGE_SLUG,
			'error' => rawurlencode( $message ),
		];
		// tenant_id 指定があれば編集画面に戻す
		if ( isset( $_POST['tenant_id'] ) ) {
			$args['tenant_id'] = absint( $_POST['tenant_id'] );
		} elseif ( isset( $_GET['tenant_id'] ) ) {
			$args['tenant_id'] = absint( $_GET['tenant_id'] );
		}
		// フロントエンド (_return_url) からのリクエストなら、そちらに戻す
		$ret = self::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ 'error' => rawurlencode( $message ) ], $ret ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * フロントエンド (page-chatbot.php 等) からの送信なら、そのページに戻すための URL を返す。
	 * 同一ホストの安全な URL のみ受け付ける。
	 */
	public static function resolve_return_url(): string {
		$raw = isset( $_REQUEST['_return_url'] ) ? (string) wp_unslash( $_REQUEST['_return_url'] ) : '';
		if ( $raw === '' ) return '';
		$parsed = wp_parse_url( $raw );
		if ( empty( $parsed['host'] ) ) return '';
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $home_host && strtolower( $parsed['host'] ) !== strtolower( (string) $home_host ) ) {
			return '';
		}
		return esc_url_raw( $raw );
	}
}
