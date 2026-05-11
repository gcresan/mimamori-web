<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Rate_Limiter' ) ) { return; }

/**
 * Mimamori_Bot_Rate_Limiter
 *
 * Transient ベースの簡易レートリミッター（1分窓）。
 * 公開Widget API のテナント別 / IP別流量制御に使用。
 *
 * 設計:
 *   - キーは tenant_id + ip_hash + 分(YmdHi) のハッシュ
 *   - インクリメントして上限超過なら false を返す
 *   - 上限はテナント設定 (chatbot_tenants.rate_limit_rpm) を採用
 */
class Mimamori_Bot_Rate_Limiter {

	/**
	 * @return true|WP_Error  true=通過, WP_Error=429
	 */
	public static function check( int $tenant_id, string $ip_hash, int $max_per_minute ) {
		if ( $max_per_minute <= 0 ) {
			$max_per_minute = 60;
		}
		$minute = (int) date( 'YmdHi' );
		$key    = sprintf( 'mb_rl_%d_%s_%d', $tenant_id, substr( $ip_hash, 0, 16 ), $minute );

		$count = (int) get_transient( $key );
		if ( $count >= $max_per_minute ) {
			Mimamori_Bot_Logger::warn( 'rate_limit: exceeded', [
				'tenant_id' => $tenant_id,
				'minute'    => $minute,
				'count'     => $count,
				'max'       => $max_per_minute,
			] );
			return new WP_Error(
				'rate_limited',
				'リクエストが多すぎます。しばらくしてから再度お試しください。',
				[ 'status' => 429, 'retry_after' => 60 ]
			);
		}
		// TTL は 70秒（境界またぎを許容）
		set_transient( $key, $count + 1, 70 );
		return true;
	}

	/**
	 * テナント月次バジェット (円) を超えていないか
	 *
	 * @return true|WP_Error
	 */
	public static function check_budget( int $tenant_id, ?int $monthly_budget_jpy ) {
		if ( $monthly_budget_jpy === null || $monthly_budget_jpy <= 0 ) {
			return true;
		}
		global $wpdb;
		$table  = Mimamori_Bot_Installer::table_messages();
		$start  = date( 'Y-m-01 00:00:00' );
		$sum    = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(cost_microjpy),0) FROM {$table}
			 WHERE tenant_id = %d AND created_at >= %s",
			$tenant_id, $start
		) );
		$spent_jpy = (int) floor( $sum / 1000000 );
		if ( $spent_jpy >= $monthly_budget_jpy ) {
			Mimamori_Bot_Logger::warn( 'budget: exceeded', [
				'tenant_id' => $tenant_id,
				'spent'     => $spent_jpy,
				'budget'    => $monthly_budget_jpy,
			] );
			return new WP_Error(
				'budget_exceeded',
				'今月のご利用上限に達しました。フォームからお問い合わせください。',
				[ 'status' => 503 ]
			);
		}
		return true;
	}
}
