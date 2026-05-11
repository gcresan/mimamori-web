<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Crypto' ) ) { return; }

/**
 * Mimamori_Bot_Crypto
 *
 * 暗号化ブリッジ。Gcrev_Crypto（テーマ側）が利用可能ならそれに委譲、
 * そうでなければ libsodium で同等処理を行う。
 *
 * 鍵: GCREV_ENCRYPTION_KEY 定数 (base64 32bytes)
 */
class Mimamori_Bot_Crypto {

	public static function is_available(): bool {
		if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
			return true;
		}
		return defined( 'GCREV_ENCRYPTION_KEY' )
			&& constant( 'GCREV_ENCRYPTION_KEY' ) !== ''
			&& function_exists( 'sodium_crypto_secretbox' );
	}

	public static function encrypt( string $plaintext ): string {
		if ( $plaintext === '' ) {
			return '';
		}
		if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
			return Gcrev_Crypto::encrypt( $plaintext );
		}
		if ( ! self::is_available() ) {
			Mimamori_Bot_Logger::warn( 'crypto: GCREV_ENCRYPTION_KEY not set, storing plaintext (NOT RECOMMENDED)' );
			return $plaintext;
		}
		$key   = self::get_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		sodium_memzero( $key );
		return base64_encode( $nonce . $cipher );
	}

	public static function decrypt( string $ciphertext ): string {
		if ( $ciphertext === '' ) {
			return '';
		}
		if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
			return Gcrev_Crypto::decrypt( $ciphertext );
		}
		if ( ! self::is_available() ) {
			return $ciphertext;
		}
		$decoded = base64_decode( $ciphertext, true );
		if ( $decoded === false || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 ) {
			Mimamori_Bot_Logger::error( 'crypto: decrypt failed (invalid format)' );
			return '';
		}
		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = self::get_key();
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		sodium_memzero( $key );
		if ( $plain === false ) {
			Mimamori_Bot_Logger::error( 'crypto: decrypt failed (auth tag mismatch)' );
			return '';
		}
		return $plain;
	}

	/**
	 * 公開キー(pk_live_...) を生成
	 */
	public static function generate_public_key(): string {
		return 'pk_live_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * 秘密キー(sk_live_...) を生成。返却値はそのまま、保存時に暗号化する。
	 */
	public static function generate_secret_key(): string {
		return 'sk_live_' . bin2hex( random_bytes( 24 ) );
	}

	private static function get_key(): string {
		$encoded = defined( 'GCREV_ENCRYPTION_KEY' ) ? (string) constant( 'GCREV_ENCRYPTION_KEY' ) : '';
		if ( $encoded === '' ) {
			throw new RuntimeException( 'GCREV_ENCRYPTION_KEY is not defined' );
		}
		$key = base64_decode( $encoded, true );
		if ( $key === false || strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			throw new RuntimeException( 'GCREV_ENCRYPTION_KEY is invalid (must be base64-encoded 32 bytes)' );
		}
		return $key;
	}
}
