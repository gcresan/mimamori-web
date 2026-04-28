<?php
// FILE: inc/gcrev-api/utils/class-strategy-tables.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Tables' ) ) { return; }

/**
 * Gcrev_Strategy_Tables
 *
 * 戦略連動型 月次レポート機能で使う2テーブルの dbDelta 定義を集約。
 *
 *   - {prefix}gcrev_client_strategy   … クライアントごとの戦略マスター（バージョン管理）
 *   - {prefix}gcrev_strategy_reports  … 月次レポート（pending/running/completed/failed/skipped）
 *
 * 既存 gcrev_report_queue は流用するが、戦略レポートと数値レポートを区別するため
 * job_type カラムを後付けする。
 *
 * テーブル作成は functions.php の after_setup_theme フックから一括で呼び出される。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Tables {

	public static function client_strategy_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gcrev_client_strategy';
	}

	public static function strategy_reports_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gcrev_strategy_reports';
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$strategy_table = self::client_strategy_table();
		$sql_strategy = "CREATE TABLE {$strategy_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			source_type VARCHAR(16) NOT NULL DEFAULT 'manual',
			source_file_id BIGINT(20) UNSIGNED NULL,
			strategy_json LONGTEXT NOT NULL,
			effective_from DATE NOT NULL,
			effective_until DATE NULL,
			created_by BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_active (user_id, status, effective_from),
			KEY idx_user_version (user_id, version)
		) {$charset};";
		dbDelta( $sql_strategy );

		// 注意: `year_month` は MariaDB の INTERVAL 用の非予約語と衝突するため
		// 必ずバッククォートで囲む（CREATE / KEY 両方）。
		$reports_table = self::strategy_reports_table();
		$sql_reports = "CREATE TABLE {$reports_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			`year_month` VARCHAR(7) NOT NULL,
			strategy_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			alignment_score TINYINT UNSIGNED NULL,
			report_json LONGTEXT NULL,
			rendered_html LONGTEXT NULL,
			ai_model VARCHAR(64) NULL,
			ai_input_tokens INT UNSIGNED NULL,
			ai_output_tokens INT UNSIGNED NULL,
			error_message TEXT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			generation_source VARCHAR(16) NOT NULL DEFAULT 'cron',
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_user_month (user_id, `year_month`),
			KEY idx_status (status),
			KEY idx_strategy (strategy_id)
		) {$charset};";
		dbDelta( $sql_reports );

		// 既存 gcrev_report_queue に job_type カラムを後付け
		// （数値月次レポートと戦略レポートを区別するため。dbDelta は ALTER に弱いので直接 ALTER する）
		self::ensure_report_queue_job_type_column();
	}

	/**
	 * 既存 gcrev_report_queue に job_type カラムが無ければ追加する。
	 * 既存レコードはすべて 'monthly_report' として扱う（後方互換）。
	 */
	private static function ensure_report_queue_job_type_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'gcrev_report_queue';

		// テーブル自体が無ければ何もしない（Report_Queue::create_table 側で作られる）
		$exists = (string) $wpdb->get_var( $wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$table
		) );
		if ( $exists !== $table ) return;

		$col = $wpdb->get_results( $wpdb->prepare(
			"SHOW COLUMNS FROM {$table} LIKE %s",
			'job_type'
		) );
		if ( ! empty( $col ) ) return;

		// suppress_errors はデフォルト false。ALTER は冪等にするため LIKE 後に判断済み
		$wpdb->query(
			"ALTER TABLE {$table} ADD COLUMN job_type VARCHAR(32) NOT NULL DEFAULT 'monthly_report' AFTER year_month, ADD KEY idx_job_type (job_type)"
		);
	}
}
