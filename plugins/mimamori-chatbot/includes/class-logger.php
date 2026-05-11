<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Logger' ) ) { return; }

/**
 * Mimamori_Bot_Logger
 *
 * KUSANAGI環境では error_log() が出力されない問題があるため、
 * CLAUDE.md §7.1 に従い file_put_contents による /tmp 直書きパターンを採用。
 */
class Mimamori_Bot_Logger {

	public static function info( string $message, array $context = [] ): void {
		self::write( 'INFO', $message, $context );
	}

	public static function warn( string $message, array $context = [] ): void {
		self::write( 'WARN', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::write( 'ERROR', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		$line = sprintf( '%s [%s] %s', date( 'Y-m-d H:i:s' ), $level, $message );
		if ( ! empty( $context ) ) {
			$json  = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$line .= ' ' . ( is_string( $json ) ? $json : '[unserializable context]' );
		}
		@file_put_contents( MIMAMORI_BOT_LOG_FILE, $line . "\n", FILE_APPEND );
	}

	/**
	 * 機密文字列をマスクして返す（先頭4 + ... + 末尾4）。
	 */
	public static function mask( string $secret ): string {
		$len = strlen( $secret );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $secret, 0, 4 ) . '...' . substr( $secret, -4 );
	}
}
