<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_History_Page' ) ) { return; }

/**
 * Mimamori_Bot_History_Page
 *
 * チャット履歴ビューア。
 *   - 一覧: 直近セッションを ページネーション付きで表示
 *   - 詳細: ?session=uuid で全メッセージ + 引用 knowledge_refs を表示
 *
 * 全クエリで tenant_id WHERE を必須にしてクロステナント参照を防ぐ。
 */
class Mimamori_Bot_History_Page {

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$user_id = get_current_user_id();
		$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( $user_id );

		echo '<div class="wrap">';
		echo '<h1>チャット履歴</h1>';

		Mimamori_Bot_Tenant_Context::render_switcher( $user_id, Mimamori_Bot_Admin_Menu::PAGE_SLUG_HISTORY, $tenant );

		if ( ! $tenant ) {
			echo '<div class="notice notice-warning"><p>先にチャットボットの<strong>設定ページでテナントを発行</strong>してください。</p></div>';
			echo '</div>';
			return;
		}

		$session_uuid = isset( $_GET['session'] ) ? sanitize_text_field( (string) $_GET['session'] ) : '';
		if ( $session_uuid !== '' ) {
			self::render_detail( (int) $tenant['id'], $session_uuid );
		} else {
			self::render_list( (int) $tenant['id'] );
		}
		echo '</div>';
	}

	private static function render_list( int $tenant_id ): void {
		global $wpdb;
		$st     = Mimamori_Bot_Installer::table_sessions();
		$per    = 30;
		$page   = max( 1, isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 1 );
		$offset = ( $page - 1 ) * $per;

		$total  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d", $tenant_id
		) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT session_uuid, started_at, last_active_at, message_count, quote_clicked, contact_clicked, category, landing_url
			   FROM {$st}
			  WHERE tenant_id = %d
			  ORDER BY started_at DESC
			  LIMIT %d OFFSET %d",
			$tenant_id, $per, $offset
		), ARRAY_A );

		echo '<p>総セッション数: <strong>' . esc_html( (string) $total ) . '</strong> 件</p>';
		if ( empty( $rows ) ) {
			echo '<p>まだセッションはありません。</p>';
			return;
		}

		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th>開始</th><th>最終アクティブ</th><th>発話数</th><th>見積CV</th><th>問合CV</th><th>ランディング</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url = add_query_arg( [
				'page'    => Mimamori_Bot_Admin_Menu::PAGE_SLUG_HISTORY,
				'session' => $r['session_uuid'],
			], admin_url( 'admin.php' ) );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['started_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['last_active_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $r['message_count'] ) . '</td>';
			echo '<td>' . ( (int) $r['quote_clicked']   ? '✅' : '' ) . '</td>';
			echo '<td>' . ( (int) $r['contact_clicked'] ? '✅' : '' ) . '</td>';
			echo '<td><code>' . esc_html( mb_substr( (string) ( $r['landing_url'] ?? '' ), 0, 60 ) ) . '</code></td>';
			echo '<td><a href="' . esc_url( $url ) . '" class="button button-small">詳細</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// ページネーション
		$total_pages = (int) ceil( $total / $per );
		if ( $total_pages > 1 ) {
			echo '<p style="margin-top:12px">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$is_current = ( $i === $page );
				$url = add_query_arg( [
					'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_HISTORY,
					'p'    => $i,
				], admin_url( 'admin.php' ) );
				if ( $is_current ) {
					echo '<strong style="margin:0 6px">' . esc_html( (string) $i ) . '</strong>';
				} else {
					echo '<a href="' . esc_url( $url ) . '" style="margin:0 6px">' . esc_html( (string) $i ) . '</a>';
				}
			}
			echo '</p>';
		}
	}

	private static function render_detail( int $tenant_id, string $session_uuid ): void {
		global $wpdb;
		$st = Mimamori_Bot_Installer::table_sessions();
		$mt = Mimamori_Bot_Installer::table_messages();
		$et = Mimamori_Bot_Installer::table_events();

		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$st} WHERE tenant_id = %d AND session_uuid = %s LIMIT 1",
			$tenant_id, $session_uuid
		), ARRAY_A );

		$back_url = add_query_arg( [ 'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_HISTORY ], admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back_url ) . '">← 一覧へ戻る</a></p>';

		if ( ! $session ) {
			echo '<div class="notice notice-error"><p>セッションが見つかりません。</p></div>';
			return;
		}

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, role, content, tokens_in, tokens_out, cost_microjpy, model, latency_ms, knowledge_refs, created_at
			   FROM {$mt}
			  WHERE tenant_id = %d AND session_id = %d
			  ORDER BY id ASC",
			$tenant_id, (int) $session['id']
		), ARRAY_A );

		$events = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, payload, created_at FROM {$et}
			  WHERE tenant_id = %d AND session_id = %d
			  ORDER BY id ASC",
			$tenant_id, (int) $session['id']
		), ARRAY_A );

		echo '<h2>セッション詳細</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>UUID</th><td><code>' . esc_html( (string) $session['session_uuid'] ) . '</code></td></tr>';
		echo '<tr><th>開始</th><td>' . esc_html( (string) $session['started_at'] ) . '</td></tr>';
		echo '<tr><th>最終アクティブ</th><td>' . esc_html( (string) $session['last_active_at'] ) . '</td></tr>';
		echo '<tr><th>UA</th><td><code>' . esc_html( mb_substr( (string) ( $session['user_agent'] ?? '' ), 0, 200 ) ) . '</code></td></tr>';
		echo '<tr><th>ランディング</th><td><code>' . esc_html( (string) ( $session['landing_url'] ?? '' ) ) . '</code></td></tr>';
		echo '<tr><th>UTM</th><td>'
			. esc_html( (string) ( $session['utm_source'] ?? '' ) ) . ' / '
			. esc_html( (string) ( $session['utm_medium'] ?? '' ) ) . ' / '
			. esc_html( (string) ( $session['utm_campaign'] ?? '' ) ) . '</td></tr>';
		echo '<tr><th>発話数</th><td>' . esc_html( (string) (int) $session['message_count'] ) . '</td></tr>';
		echo '<tr><th>CV</th><td>'
			. ( (int) $session['quote_clicked']   ? '✅ 見積CTAクリック ' : '' )
			. ( (int) $session['contact_clicked'] ? '✅ 問合CTAクリック'  : '' )
			. '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>会話</h2>';
		if ( empty( $messages ) ) {
			echo '<p>発話なし。</p>';
		} else {
			$total_cost = 0;
			$total_in   = 0;
			$total_out  = 0;
			echo '<div style="max-width:900px">';
			foreach ( $messages as $m ) {
				$role  = (string) $m['role'];
				$bg    = $role === 'user' ? '#dbeafe' : ( $role === 'assistant' ? '#fff' : '#f3f4f6' );
				$align = $role === 'user' ? 'right' : 'left';
				$total_cost += (int) ( $m['cost_microjpy'] ?? 0 );
				$total_in   += (int) ( $m['tokens_in']     ?? 0 );
				$total_out  += (int) ( $m['tokens_out']    ?? 0 );
				echo '<div style="margin:8px 0;text-align:' . $align . '">';
				echo '<div style="display:inline-block;background:' . $bg . ';padding:10px 14px;border-radius:10px;border:1px solid #e5e7eb;max-width:80%;text-align:left;white-space:pre-wrap;">';
				echo '<div style="font-size:11px;color:#6b7280">' . esc_html( $role ) . ' · ' . esc_html( (string) $m['created_at'] ) . '</div>';
				echo esc_html( (string) $m['content'] );
				if ( ! empty( $m['knowledge_refs'] ) ) {
					echo '<div style="font-size:11px;color:#6b7280;margin-top:6px">📎 引用: <code>' . esc_html( (string) $m['knowledge_refs'] ) . '</code></div>';
				}
				if ( $role === 'assistant' && ( $m['tokens_in'] || $m['tokens_out'] ) ) {
					$cost_jpy = (int) ( $m['cost_microjpy'] ?? 0 ) / 1000000;
					echo '<div style="font-size:11px;color:#6b7280;margin-top:4px">model=' . esc_html( (string) $m['model'] )
						. ' / in=' . esc_html( (string) $m['tokens_in'] )
						. ' / out=' . esc_html( (string) $m['tokens_out'] )
						. ' / ' . esc_html( (string) (int) $m['latency_ms'] ) . 'ms'
						. ' / ¥' . number_format( $cost_jpy, 4 ) . '</div>';
				}
				echo '</div></div>';
			}
			echo '</div>';
			$total_jpy = $total_cost / 1000000;
			echo '<p style="margin-top:16px"><strong>合計コスト:</strong> ¥' . esc_html( number_format( $total_jpy, 4 ) )
				. ' (in=' . esc_html( (string) $total_in ) . ', out=' . esc_html( (string) $total_out ) . ' tokens)</p>';
		}

		echo '<h2>イベント</h2>';
		if ( empty( $events ) ) {
			echo '<p>記録なし。</p>';
		} else {
			echo '<table class="wp-list-table widefat striped"><thead><tr><th>時刻</th><th>種別</th><th>payload</th></tr></thead><tbody>';
			foreach ( $events as $e ) {
				echo '<tr><td>' . esc_html( (string) $e['created_at'] ) . '</td><td>' . esc_html( (string) $e['type'] ) . '</td><td><code>' . esc_html( mb_substr( (string) ( $e['payload'] ?? '' ), 0, 200 ) ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
