<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Analytics_Page' ) ) { return; }

/**
 * Mimamori_Bot_Analytics_Page
 *
 * チャットボット運用KPIダッシュボード（読み取り専用）。
 *
 *   - KPI: セッション数 / CV率 / 平均発話数 / トークン消費 / 推定コスト
 *   - 離脱分析: 「発話数=N で終わったセッション」の分布
 *   - 人気質問: FAQ.hit_count 降順
 *   - 直近セッション: 簡易タイムライン
 *
 * 全クエリ tenant_id WHERE 必須。
 */
class Mimamori_Bot_Analytics_Page {

	private const PERIODS = [
		'7'   => '直近 7日',
		'30'  => '直近 30日',
		'90'  => '直近 90日',
		'365' => '直近 1年',
	];

	public static function render(): void {
		if ( ! current_user_can( 'read' ) ) wp_die( 'forbidden' );
		$tenant = Mimamori_Bot_Tenant_Repository::find_for_user( get_current_user_id() );

		echo '<div class="wrap">';
		echo '<h1>分析ダッシュボード</h1>';

		if ( ! $tenant ) {
			echo '<div class="notice notice-warning"><p>先にチャットボットの<strong>設定ページでテナントを発行</strong>してください。</p></div>';
			echo '</div>';
			return;
		}

		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
		if ( ! isset( self::PERIODS[ (string) $days ] ) ) $days = 30;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

		self::render_period_switch( $days );

		$kpi = self::compute_kpi( (int) $tenant['id'], $since );
		self::render_kpi_cards( $kpi );

		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px">';
		self::render_dropoff( (int) $tenant['id'], $since );
		self::render_top_faqs( (int) $tenant['id'] );
		echo '</div>';

		self::render_recent_sessions( (int) $tenant['id'], $since );

		echo '</div>';
	}

	private static function render_period_switch( int $current_days ): void {
		echo '<p style="margin-top:12px">';
		foreach ( self::PERIODS as $d => $label ) {
			$url = add_query_arg( [
				'page' => Mimamori_Bot_Admin_Menu::PAGE_SLUG_ANALYTICS,
				'days' => $d,
			], admin_url( 'admin.php' ) );
			$is_current = ( (int) $d === $current_days );
			if ( $is_current ) {
				echo '<strong style="display:inline-block;margin:0 8px;padding:4px 10px;background:#2563eb;color:#fff;border-radius:6px">' . esc_html( $label ) . '</strong>';
			} else {
				echo '<a href="' . esc_url( $url ) . '" style="display:inline-block;margin:0 8px;padding:4px 10px;border:1px solid #d1d5db;border-radius:6px;text-decoration:none">' . esc_html( $label ) . '</a>';
			}
		}
		echo '</p>';
	}

	private static function compute_kpi( int $tenant_id, string $since ): array {
		global $wpdb;
		$st = Mimamori_Bot_Installer::table_sessions();
		$mt = Mimamori_Bot_Installer::table_messages();

		$total_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d AND started_at >= %s",
			$tenant_id, $since
		) );
		$conv_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$st}
			  WHERE tenant_id = %d AND started_at >= %s AND message_count >= 1",
			$tenant_id, $since
		) );
		$quote_clicks = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$st}
			  WHERE tenant_id = %d AND started_at >= %s AND quote_clicked = 1",
			$tenant_id, $since
		) );
		$contact_clicks = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$st}
			  WHERE tenant_id = %d AND started_at >= %s AND contact_clicked = 1",
			$tenant_id, $since
		) );
		$avg_msgs = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT IFNULL(AVG(message_count), 0) FROM {$st}
			  WHERE tenant_id = %d AND started_at >= %s",
			$tenant_id, $since
		) );

		$cost_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
			   SUM(tokens_in)     AS in_sum,
			   SUM(tokens_out)    AS out_sum,
			   SUM(cost_microjpy) AS cost_sum,
			   COUNT(*)           AS message_total
			 FROM {$mt}
			 WHERE tenant_id = %d AND created_at >= %s",
			$tenant_id, $since
		), ARRAY_A );
		$tokens_in   = (int) ( $cost_row['in_sum']        ?? 0 );
		$tokens_out  = (int) ( $cost_row['out_sum']       ?? 0 );
		$cost_micro  = (int) ( $cost_row['cost_sum']      ?? 0 );
		$msg_total   = (int) ( $cost_row['message_total'] ?? 0 );

		$cv_total_rate = $total_sessions > 0
			? round( ( $quote_clicks + $contact_clicks ) * 100 / $total_sessions, 1 )
			: 0.0;

		return [
			'total_sessions'  => $total_sessions,
			'conv_sessions'   => $conv_sessions,
			'quote_clicks'    => $quote_clicks,
			'contact_clicks'  => $contact_clicks,
			'cv_total_rate'   => $cv_total_rate,
			'avg_msgs'        => round( $avg_msgs, 1 ),
			'msg_total'       => $msg_total,
			'tokens_in'       => $tokens_in,
			'tokens_out'      => $tokens_out,
			'cost_jpy'        => $cost_micro / 1_000_000,
		];
	}

	private static function render_kpi_cards( array $k ): void {
		$cards = [
			[ 'label' => 'セッション数',       'value' => number_format( $k['total_sessions'] ),       'sub' => '対話完了 ' . number_format( $k['conv_sessions'] ) . ' 件' ],
			[ 'label' => 'CV合計率',          'value' => $k['cv_total_rate'] . ' %',                    'sub' => '見積 ' . $k['quote_clicks'] . ' / 問合 ' . $k['contact_clicks'] ],
			[ 'label' => '平均発話数',         'value' => $k['avg_msgs'],                                'sub' => '対話あたり' ],
			[ 'label' => '総メッセージ数',     'value' => number_format( $k['msg_total'] ),              'sub' => 'user + assistant' ],
			[ 'label' => 'トークン消費',       'value' => number_format( $k['tokens_in'] + $k['tokens_out'] ),
			                                'sub' => 'in ' . number_format( $k['tokens_in'] ) . ' / out ' . number_format( $k['tokens_out'] ) ],
			[ 'label' => '推定コスト',         'value' => '¥' . number_format( $k['cost_jpy'], 2 ),     'sub' => 'JPY換算 (155円/USD 想定)' ],
		];

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;margin-top:16px">';
		foreach ( $cards as $c ) {
			echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px">';
			echo '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">' . esc_html( $c['label'] ) . '</div>';
			echo '<div style="font-size:24px;font-weight:700;margin:6px 0">' . esc_html( (string) $c['value'] ) . '</div>';
			echo '<div style="font-size:11px;color:#9ca3af">' . esc_html( $c['sub'] ) . '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_dropoff( int $tenant_id, string $since ): void {
		global $wpdb;
		$st = Mimamori_Bot_Installer::table_sessions();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT message_count, COUNT(*) AS cnt
			   FROM {$st}
			  WHERE tenant_id = %d AND started_at >= %s
			  GROUP BY message_count
			  ORDER BY message_count ASC
			  LIMIT 20",
			$tenant_id, $since
		), ARRAY_A );

		echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px">';
		echo '<h3 style="margin-top:0">離脱分布 (発話数別セッション数)</h3>';
		if ( empty( $rows ) ) {
			echo '<p>データなし。</p>';
			echo '</div>';
			return;
		}
		$max = 0;
		foreach ( $rows as $r ) { $max = max( $max, (int) $r['cnt'] ); }
		if ( $max < 1 ) $max = 1;
		echo '<table style="width:100%;font-size:13px"><tbody>';
		foreach ( $rows as $r ) {
			$mc  = (int) $r['message_count'];
			$cnt = (int) $r['cnt'];
			$pct = $cnt * 100 / $max;
			$label = $mc === 0 ? '0発話 (起動のみ)' : ( $mc . ' 発話' );
			echo '<tr>';
			echo '<td style="padding:4px 8px;width:120px;color:#374151">' . esc_html( $label ) . '</td>';
			echo '<td style="padding:4px 0">';
			echo '<div style="background:#dbeafe;height:18px;border-radius:4px;width:' . esc_attr( (string) $pct ) . '%"></div>';
			echo '</td>';
			echo '<td style="padding:4px 8px;width:60px;text-align:right;font-variant-numeric:tabular-nums">' . esc_html( (string) $cnt ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="font-size:11px;color:#9ca3af;margin:8px 0 0">「0発話」は起動直後に閉じたセッション。「1発話」は質問1回で離脱した例で、FAQ充実度の指標になる。</p>';
		echo '</div>';
	}

	private static function render_top_faqs( int $tenant_id ): void {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question, hit_count, is_starter
			   FROM {$table}
			  WHERE tenant_id = %d AND status = 'active' AND hit_count > 0
			  ORDER BY hit_count DESC, priority DESC
			  LIMIT 10",
			$tenant_id
		), ARRAY_A );

		echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px">';
		echo '<h3 style="margin-top:0">人気FAQ Top10 (累計ヒット)</h3>';
		if ( empty( $rows ) ) {
			echo '<p>まだヒットしたFAQはありません。</p>';
			echo '</div>';
			return;
		}
		$max = 0;
		foreach ( $rows as $r ) { $max = max( $max, (int) $r['hit_count'] ); }
		if ( $max < 1 ) $max = 1;
		echo '<table style="width:100%;font-size:13px"><tbody>';
		foreach ( $rows as $r ) {
			$hc  = (int) $r['hit_count'];
			$pct = $hc * 100 / $max;
			echo '<tr>';
			echo '<td style="padding:4px 8px">' . ( (int) $r['is_starter'] ? '★ ' : '' ) . esc_html( mb_substr( (string) $r['question'], 0, 50 ) ) . '</td>';
			echo '<td style="padding:4px 0;width:30%">';
			echo '<div style="background:#fde68a;height:14px;border-radius:4px;width:' . esc_attr( (string) $pct ) . '%"></div>';
			echo '</td>';
			echo '<td style="padding:4px 8px;width:50px;text-align:right;font-variant-numeric:tabular-nums">' . esc_html( (string) $hc ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private static function render_recent_sessions( int $tenant_id, string $since ): void {
		global $wpdb;
		$st = Mimamori_Bot_Installer::table_sessions();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT session_uuid, started_at, message_count, quote_clicked, contact_clicked, landing_url
			   FROM {$st}
			  WHERE tenant_id = %d AND started_at >= %s
			  ORDER BY started_at DESC
			  LIMIT 10",
			$tenant_id, $since
		), ARRAY_A );

		echo '<h2 style="margin-top:24px">直近セッション</h2>';
		if ( empty( $rows ) ) {
			echo '<p>セッションなし。</p>';
			return;
		}
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr><th>開始</th><th>発話数</th><th>CV</th><th>ランディング</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$cv = ( (int) $r['quote_clicked']   ? '✅見積 ' : '' )
				. ( (int) $r['contact_clicked'] ? '✅問合 ' : '' );
			$url = add_query_arg( [
				'page'    => Mimamori_Bot_Admin_Menu::PAGE_SLUG_HISTORY,
				'session' => $r['session_uuid'],
			], admin_url( 'admin.php' ) );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['started_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $r['message_count'] ) . '</td>';
			echo '<td>' . esc_html( $cv ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( mb_substr( (string) ( $r['landing_url'] ?? '' ), 0, 60 ) ) . '</code></td>';
			echo '<td><a href="' . esc_url( $url ) . '" class="button button-small">詳細</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
