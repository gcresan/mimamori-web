<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Faq_Page' ) ) { return; }

class Mimamori_Bot_Faq_Page {

	const NONCE_SAVE   = 'mimamori_bot_faq_save';
	const NONCE_DELETE = 'mimamori_bot_faq_delete';

	public static function init_hooks(): void {
		add_action( 'admin_post_mimamori_bot_faq_save',   [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_mimamori_bot_faq_delete', [ __CLASS__, 'handle_delete' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$user_id = get_current_user_id();
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( $user_id );

		echo '<div class="wrap">';
		echo '<h1>FAQ管理</h1>';

		Mimamori_Bot_Tenant_Context::render_switcher( $user_id, Mimamori_Bot_Admin_Menu::PAGE_SLUG_FAQ, $tenant );

		if ( ! $tenant ) {
			echo '<div class="notice notice-warning"><p>先にチャットボットの<strong>設定ページでテナントを発行</strong>してください。</p></div>';
			echo '</div>';
			return;
		}
		self::flash_notices();

		echo '<p><code>初期表示</code> にチェックしたものはチャット起動時の質問例ボタンに使われます (priority 降順, 最大4件)。</p>';

		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? Mimamori_Bot_Faq_Repository::find( (int) $tenant['id'], $edit_id ) : null;

		self::render_form( $editing );
		self::render_list( (int) $tenant['id'] );

		echo '</div>';
	}

	private static function flash_notices(): void {
		if ( isset( $_GET['saved'] ) )   echo '<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>';
		if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>削除しました。</p></div>';
		if ( isset( $_GET['error'] ) )   echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $_GET['error'] ) . '</p></div>';
	}

	private static function render_form( ?array $editing ): void {
		$id         = $editing ? (int) $editing['id'] : 0;
		$question   = $editing ? (string) $editing['question'] : '';
		$answer     = $editing ? (string) $editing['answer']   : '';
		$category   = $editing ? (string) ( $editing['category'] ?? '' ) : '';
		$priority   = $editing ? (int) $editing['priority']   : 0;
		$is_starter = $editing ? (int) $editing['is_starter'] : 0;
		$status     = $editing ? (string) $editing['status']  : 'active';

		echo '<h2>' . ( $editing ? '編集 (#' . esc_html( (string) $id ) . ')' : '新規追加' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="mimamori_bot_faq_save">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="f_q">質問</label></th><td><input type="text" id="f_q" name="question" class="large-text" required maxlength="500" value="' . esc_attr( $question ) . '"></td></tr>';
		echo '<tr><th><label for="f_a">回答</label></th><td><textarea id="f_a" name="answer" rows="6" cols="80" required>' . esc_textarea( $answer ) . '</textarea></td></tr>';
		echo '<tr><th><label for="f_cat">カテゴリ</label></th><td><input type="text" id="f_cat" name="category" class="regular-text" maxlength="80" value="' . esc_attr( $category ) . '"></td></tr>';
		echo '<tr><th><label for="f_p">優先度</label></th><td><input type="number" id="f_p" name="priority" value="' . esc_attr( (string) $priority ) . '" step="1"> <span class="description">数値が大きいほど優先 (RAGスコアと初期表示順に影響)</span></td></tr>';
		echo '<tr><th>初期表示</th><td><label><input type="checkbox" name="is_starter" value="1"' . checked( $is_starter, 1, false ) . '> チャット起動時の質問例として表示</label></td></tr>';
		echo '<tr><th><label for="f_st">ステータス</label></th><td><select id="f_st" name="status">';
		foreach ( [ 'active' => '公開中', 'draft' => '下書き', 'disabled' => '無効' ] as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';
		submit_button( $editing ? '更新する' : '追加する' );
		if ( $editing ) {
			echo '<a href="' . esc_url( add_query_arg( [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_FAQ ], admin_url( 'admin.php' ) ) ) . '" class="button">キャンセル</a>';
		}
		echo '</form>';
	}

	private static function render_list( int $tenant_id ): void {
		$rows = Mimamori_Bot_Faq_Repository::list_for_tenant( $tenant_id );
		echo '<h2>登録済みFAQ (' . count( $rows ) . '件)</h2>';
		if ( empty( $rows ) ) {
			echo '<p>まだ登録がありません。</p>';
			return;
		}
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>★</th><th>質問</th><th>カテゴリ</th><th>優先</th><th>状態</th><th>ヒット数</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$id      = (int) $r['id'];
			$edit_url = add_query_arg( [
				'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_FAQ,
				'edit' => $id,
			], admin_url( 'admin.php' ) );
			$del_url = wp_nonce_url(
				add_query_arg( [
					'action' => 'mimamori_bot_faq_delete',
					'id'     => $id,
				], admin_url( 'admin-post.php' ) ),
				self::NONCE_DELETE
			);
			echo '<tr>';
			echo '<td>' . ( (int) $r['is_starter'] === 1 ? '★' : '' ) . '</td>';
			echo '<td><strong>' . esc_html( mb_substr( (string) $r['question'], 0, 80 ) ) . '</strong></td>';
			echo '<td>' . esc_html( (string) ( $r['category'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $r['priority'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['status'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $r['hit_count'] ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">編集</a> | '
				. '<a href="' . esc_url( $del_url ) . '" class="button-link-delete" onclick="return confirm(\'削除しますか？\');">削除</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function handle_save(): void {
		check_admin_referer( self::NONCE_SAVE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$fields = [
			'question'   => sanitize_text_field( (string) ( $_POST['question'] ?? '' ) ),
			'answer'     => isset( $_POST['answer'] ) ? (string) wp_unslash( $_POST['answer'] ) : '',
			'category'   => sanitize_text_field( (string) ( $_POST['category'] ?? '' ) ),
			'priority'   => (int) ( $_POST['priority'] ?? 0 ),
			'is_starter' => ! empty( $_POST['is_starter'] ) ? 1 : 0,
			'status'     => sanitize_key( (string) ( $_POST['status'] ?? 'active' ) ),
		];
		if ( $fields['question'] === '' || trim( $fields['answer'] ) === '' ) {
			self::back( '質問と回答は必須です。' );
		}

		if ( $id > 0 ) {
			Mimamori_Bot_Faq_Repository::update( (int) $tenant['id'], $id, $fields );
		} else {
			Mimamori_Bot_Faq_Repository::create( (int) $tenant['id'], $fields );
		}
		self::back_ok( 'saved' );
	}

	public static function handle_delete(): void {
		check_admin_referer( self::NONCE_DELETE );
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( get_current_user_id() );
		if ( ! $tenant ) self::back( 'テナントが見つかりません。' );

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( $id <= 0 ) self::back( '不正なIDです。' );

		Mimamori_Bot_Faq_Repository::delete( (int) $tenant['id'], $id );
		self::back_ok( 'deleted' );
	}

	private static function back_ok( string $flag ): void {
		$ret = Mimamori_Bot_Settings_Page::resolve_return_url();
		if ( $ret !== '' ) {
			wp_safe_redirect( add_query_arg( [ $flag => 1 ], $ret ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( [
			'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_FAQ,
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
			'page'  => Mimamori_Bot_Admin_Menu::PAGE_SLUG_FAQ,
			'error' => rawurlencode( $error ),
		], admin_url( 'admin.php' ) ) );
		exit;
	}
}

Mimamori_Bot_Faq_Page::init_hooks();
