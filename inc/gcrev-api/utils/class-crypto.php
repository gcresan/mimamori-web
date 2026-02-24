<?php
// FILE: inc/gcrev-api/utils/class-crypto.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Crypto') ) { return; }

/**
 * Gcrev_Crypto
 *
 * libsodium を使った暗号化/復号ユーティリティ。
 * GBP OAuth トークン等の秘密情報を DB 保存前に暗号化する。
 *
 * 鍵: wp-config.php の GCREV_ENCRYPTION_KEY 定数（Base64エンコード済み 32バイト鍵）
 * 生成: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
 *
 * @package GCREV_INSIGHT
 * @since   2.1.0
 */
class Gcrev_Crypto {

    /**
     * 暗号化が利用可能かどうかを返す
     *
     * @return bool
     */
    public static function is_available(): bool {
        return defined( 'GCREV_ENCRYPTION_KEY' )
            && GCREV_ENCRYPTION_KEY !== ''
            && function_exists( 'sodium_crypto_secretbox' );
    }

    /**
     * 暗号鍵を取得（Base64デコード → 32バイト）
     *
     * @return string 32バイトの暗号鍵
     * @throws \RuntimeException 鍵が未定義・不正な場合
     */
    private static function get_key(): string {
        if ( ! defined( 'GCREV_ENCRYPTION_KEY' ) || GCREV_ENCRYPTION_KEY === '' ) {
            throw new \RuntimeException( 'GCREV_ENCRYPTION_KEY is not defined in wp-config.php' );
        }
        $key = base64_decode( GCREV_ENCRYPTION_KEY, true );
        if ( $key === false || strlen( $key ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
            throw new \RuntimeException( 'GCREV_ENCRYPTION_KEY is invalid (must be base64-encoded 32 bytes)' );
        }
        return $key;
    }

    /**
     * 平文を暗号化して Base64 文字列を返す
     *
     * フォーマット: base64( nonce(24bytes) + ciphertext )
     *
     * @param  string $plaintext 暗号化する平文
     * @return string Base64エンコードされた暗号文
     */
    public static function encrypt( string $plaintext ): string {
        if ( $plaintext === '' ) {
            return '';
        }
        if ( ! self::is_available() ) {
            // 鍵未設定時はそのまま返す（後方互換）
            error_log( '[GCREV][Crypto] WARNING: GCREV_ENCRYPTION_KEY not set, storing plaintext' );
            return $plaintext;
        }

        $key   = self::get_key();
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

        // メモリ上の鍵を安全に消去
        sodium_memzero( $key );

        return base64_encode( $nonce . $cipher );
    }

    /**
     * 暗号文（Base64）を復号して平文を返す
     *
     * 復号失敗（平文がそのまま保存されているケース）は元の値をそのまま返す。
     * これにより、暗号化導入前の既存データも正常に読める。
     *
     * @param  string $encoded Base64エンコードされた暗号文（or レガシー平文）
     * @return string 復号された平文
     */
    public static function decrypt( string $encoded ): string {
        if ( $encoded === '' ) {
            return '';
        }
        if ( ! self::is_available() ) {
            return $encoded;
        }

        // Base64デコード
        $decoded = base64_decode( $encoded, true );
        if ( $decoded === false ) {
            // Base64でなければ平文とみなす（レガシー互換）
            return $encoded;
        }

        // 最小長チェック（nonce + 最短の暗号文）
        $min_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ( strlen( $decoded ) < $min_len ) {
            // 短すぎる → 平文とみなす
            return $encoded;
        }

        $nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

        try {
            $key   = self::get_key();
            $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
            sodium_memzero( $key );

            if ( $plain === false ) {
                // 復号失敗 → 旧鍵 or 平文
                return $encoded;
            }
            return $plain;
        } catch ( \Exception $e ) {
            // 鍵エラー等 → 平文としてフォールバック
            return $encoded;
        }
    }

    /**
     * 全ユーザーの GBP トークンを平文→暗号化に一括移行する
     * （WP-CLI から呼び出す想定）
     *
     * @return array{migrated: int, skipped: int, errors: int}
     */
    public static function migrate_all_tokens(): array {
        if ( ! self::is_available() ) {
            return ['migrated' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];
        $meta_keys = ['_gcrev_gbp_access_token', '_gcrev_gbp_refresh_token'];

        $users = get_users( ['fields' => ['ID']] );

        foreach ( $users as $u ) {
            $user_id = (int) $u->ID;
            foreach ( $meta_keys as $key ) {
                $raw = get_user_meta( $user_id, $key, true );
                if ( $raw === '' || $raw === false ) {
                    continue;
                }

                // 既に暗号化済みかチェック（復号して同じ値に戻るか）
                $test_decrypt = self::decrypt( $raw );
                if ( $test_decrypt !== $raw ) {
                    // 復号成功 → 既に暗号化済み
                    $result['skipped']++;
                    continue;
                }

                // 平文 → 暗号化して保存
                try {
                    $encrypted = self::encrypt( $raw );
                    update_user_meta( $user_id, $key, $encrypted );
                    $result['migrated']++;
                } catch ( \Exception $e ) {
                    error_log( "[GCREV][Crypto] Migration error user_id={$user_id}, key={$key}: " . $e->getMessage() );
                    $result['errors']++;
                }
            }
        }

        return $result;
    }
}
