<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Installer' ) ) { return; }

/**
 * Mimamori_Bot_Installer
 * テーブル作成・アップグレード・アンインストール処理。
 *
 * テーブル一覧:
 *   {prefix}chatbot_tenants    テナント設定
 *   {prefix}chatbot_sessions   ブラウザセッション
 *   {prefix}chatbot_messages   発話ログ
 *   {prefix}chatbot_events     クリック等のイベント
 *
 * 将来追加予定:
 *   {prefix}chatbot_knowledge        ナレッジ原本
 *   {prefix}chatbot_knowledge_chunks 埋め込み付きチャンク
 *   {prefix}chatbot_faq              FAQ
 *   {prefix}chatbot_suggestions      改善提案
 */
class Mimamori_Bot_Installer {

	public static function table_tenants():          string { global $wpdb; return $wpdb->prefix . 'chatbot_tenants';          }
	public static function table_sessions():         string { global $wpdb; return $wpdb->prefix . 'chatbot_sessions';         }
	public static function table_messages():         string { global $wpdb; return $wpdb->prefix . 'chatbot_messages';         }
	public static function table_events():           string { global $wpdb; return $wpdb->prefix . 'chatbot_events';           }
	public static function table_knowledge():        string { global $wpdb; return $wpdb->prefix . 'chatbot_knowledge';        }
	public static function table_knowledge_chunks(): string { global $wpdb; return $wpdb->prefix . 'chatbot_knowledge_chunks'; }
	public static function table_faq():              string { global $wpdb; return $wpdb->prefix . 'chatbot_faq';              }

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql_tenants = "CREATE TABLE " . self::table_tenants() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(190) NOT NULL,
			public_key VARCHAR(64) NOT NULL,
			secret_key_enc TEXT NOT NULL,
			allowed_origins TEXT NOT NULL,
			system_prompt MEDIUMTEXT NULL,
			persona VARCHAR(80) NULL,
			cta_url_quote VARCHAR(500) NULL,
			cta_url_contact VARCHAR(500) NULL,
			rate_limit_rpm INT UNSIGNED NOT NULL DEFAULT 60,
			monthly_budget_jpy INT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			title_text VARCHAR(120) NULL,
			theme_primary VARCHAR(20) NULL,
			theme_on_primary VARCHAR(20) NULL,
			fab_icon_url VARCHAR(500) NULL,
			fab_bg_color VARCHAR(20) NULL,
			fab_offset_x SMALLINT NOT NULL DEFAULT 20,
			fab_offset_y SMALLINT NOT NULL DEFAULT 20,
			fab_offset_x_sp SMALLINT NULL,
			fab_offset_y_sp SMALLINT NULL,
			welcome_message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_slug (slug),
			UNIQUE KEY uk_pubkey (public_key),
			KEY idx_user (user_id)
		) $charset_collate;";

		$sql_sessions = "CREATE TABLE " . self::table_sessions() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			session_uuid CHAR(36) NOT NULL,
			visitor_hash CHAR(64) NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			user_agent VARCHAR(500) NULL,
			referer VARCHAR(500) NULL,
			landing_url VARCHAR(500) NULL,
			utm_source VARCHAR(80) NULL,
			utm_medium VARCHAR(80) NULL,
			utm_campaign VARCHAR(120) NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			closed_at DATETIME NULL,
			message_count INT UNSIGNED NOT NULL DEFAULT 0,
			quote_clicked TINYINT(1) NOT NULL DEFAULT 0,
			contact_clicked TINYINT(1) NOT NULL DEFAULT 0,
			category VARCHAR(80) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_session (session_uuid),
			KEY idx_tenant_time (tenant_id, started_at),
			KEY idx_iphash (ip_hash, started_at)
		) $charset_collate;";

		// messages はパーティション対応に備え PK に created_at を含める
		$sql_messages = "CREATE TABLE " . self::table_messages() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			session_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(16) NOT NULL,
			content MEDIUMTEXT NOT NULL,
			intent VARCHAR(40) NULL,
			knowledge_refs TEXT NULL,
			tokens_in INT UNSIGNED NULL,
			tokens_out INT UNSIGNED NULL,
			cost_microjpy BIGINT UNSIGNED NULL,
			model VARCHAR(60) NULL,
			latency_ms INT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id, created_at),
			KEY idx_session (session_id, created_at),
			KEY idx_tenant_time (tenant_id, created_at)
		) $charset_collate;";

		$sql_events = "CREATE TABLE " . self::table_events() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			session_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(40) NOT NULL,
			payload TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_session (session_id, created_at),
			KEY idx_tenant_type (tenant_id, type, created_at)
		) $charset_collate;";

		// Phase 2a: ナレッジ原本
		$sql_knowledge = "CREATE TABLE " . self::table_knowledge() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			source_type VARCHAR(20) NOT NULL DEFAULT 'text',
			mime_type VARCHAR(120) NULL,
			file_path VARCHAR(500) NULL,
			raw_text LONGTEXT NULL,
			metadata TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'ready',
			error_message TEXT NULL,
			chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_tenant_status (tenant_id, status, updated_at)
		) $charset_collate;";

		// Phase 2a: チャンク (embedding カラムは将来用に確保)
		$sql_chunks = "CREATE TABLE " . self::table_knowledge_chunks() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			knowledge_id BIGINT UNSIGNED NOT NULL,
			chunk_index INT UNSIGNED NOT NULL,
			content MEDIUMTEXT NOT NULL,
			token_count INT UNSIGNED NULL,
			embedding MEDIUMBLOB NULL,
			embedding_model VARCHAR(60) NULL,
			embedding_version INT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_tenant (tenant_id),
			KEY idx_knowledge (knowledge_id, chunk_index)
		) $charset_collate;";

		// Phase 2a: FAQ
		$sql_faq = "CREATE TABLE " . self::table_faq() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			question VARCHAR(500) NOT NULL,
			answer MEDIUMTEXT NOT NULL,
			category VARCHAR(80) NULL,
			priority INT NOT NULL DEFAULT 0,
			embedding MEDIUMBLOB NULL,
			is_starter TINYINT(1) NOT NULL DEFAULT 0,
			hit_count INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_tenant_status (tenant_id, status, priority),
			KEY idx_tenant_starter (tenant_id, is_starter, priority)
		) $charset_collate;";

		dbDelta( $sql_tenants );
		dbDelta( $sql_sessions );
		dbDelta( $sql_messages );
		dbDelta( $sql_events );
		dbDelta( $sql_knowledge );
		dbDelta( $sql_chunks );
		dbDelta( $sql_faq );

		update_option( 'mimamori_bot_db_version', MIMAMORI_BOT_VERSION, false );

		Mimamori_Bot_Logger::info( 'installer: dbDelta finished, version=' . MIMAMORI_BOT_VERSION );
	}

	public static function deactivate(): void {
		// テーブルは残す。スケジュール等の cron があれば解除をここで。
		Mimamori_Bot_Logger::info( 'installer: deactivated' );
	}

	public static function uninstall(): void {
		// ユーザーが明示的に削除した時のみ。WP標準: option('uninstall_remove_data')などで保護を推奨。
		if ( ! get_option( 'mimamori_bot_remove_data_on_uninstall', false ) ) {
			Mimamori_Bot_Logger::info( 'uninstall: data preserved (option not set)' );
			return;
		}
		global $wpdb;
		$tables = [
			self::table_faq(),
			self::table_knowledge_chunks(),
			self::table_knowledge(),
			self::table_events(),
			self::table_messages(),
			self::table_sessions(),
			self::table_tenants(),
		];
		foreach ( $tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
		}
		delete_option( 'mimamori_bot_db_version' );
		delete_option( 'mimamori_bot_remove_data_on_uninstall' );
	}
}
