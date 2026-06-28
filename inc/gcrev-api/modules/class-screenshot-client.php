<?php
// FILE: inc/gcrev-api/modules/class-screenshot-client.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Screenshot_Client' ) ) { return; }

/**
 * Gcrev_Screenshot_Client
 *
 * 外部スクリーンショットAPIを使ってページのキャプチャ画像を取得する。
 * プロバイダ非依存: wp-config.php の URL テンプレート定数を使う。
 *
 *   define( 'GCREV_SCREENSHOT_API_PC',     'https://api.example.com/take?key=XXX&full_page=true&format=jpg&viewport_width=1280&url={URL}' );
 *   define( 'GCREV_SCREENSHOT_API_MOBILE', 'https://api.example.com/take?key=XXX&full_page=true&format=jpg&viewport_width=390&url={URL}' );
 *
 * テンプレート内の {URL} は、対象ページURL（rawurlencode 済み）に置換される。
 * API はレスポンスボディに画像バイナリ（Content-Type: image/*）を返すこと。
 * 例: ScreenshotOne / ApiFlash 等の GET で画像を直接返すエンドポイント。
 *
 * @package Mimamori_Web
 */
class Gcrev_Screenshot_Client {

    private const LOG = '/tmp/gcrev_page_analysis_debug.log';

    /**
     * 指定デバイス（'pc' / 'mobile'）または全体のテンプレートが設定済みか。
     */
    public static function is_configured( string $device = '' ): bool {
        if ( $device === 'pc' )     { return self::template( 'pc' ) !== ''; }
        if ( $device === 'mobile' ) { return self::template( 'mobile' ) !== ''; }
        return self::template( 'pc' ) !== '' || self::template( 'mobile' ) !== '';
    }

    private static function template( string $device ): string {
        if ( $device === 'mobile' ) {
            return ( defined( 'GCREV_SCREENSHOT_API_MOBILE' ) && GCREV_SCREENSHOT_API_MOBILE )
                ? (string) GCREV_SCREENSHOT_API_MOBILE : '';
        }
        return ( defined( 'GCREV_SCREENSHOT_API_PC' ) && GCREV_SCREENSHOT_API_PC )
            ? (string) GCREV_SCREENSHOT_API_PC : '';
    }

    /**
     * 指定URL・デバイスのスクリーンショットを取得する。
     *
     * @param  string $page_url 対象ページのURL（http/https）
     * @param  string $device   'pc' | 'mobile'
     * @return array{ok:bool, bytes?:string, mime?:string, error?:string}
     */
    public static function capture( string $page_url, string $device ): array {
        $tpl = self::template( $device );
        if ( $tpl === '' ) {
            return [ 'ok' => false, 'error' => "スクショAPIテンプレート未設定（{$device}）" ];
        }
        if ( ! preg_match( '#^https?://#i', $page_url ) ) {
            return [ 'ok' => false, 'error' => 'URLが不正です' ];
        }

        $api_url = str_replace( '{URL}', rawurlencode( $page_url ), $tpl );

        $resp = wp_remote_get( $api_url, [
            'timeout' => 60, // フルページ生成は時間がかかるため長めに
        ] );

        if ( is_wp_error( $resp ) ) {
            $err = $resp->get_error_message();
            self::log( "capture ERROR device={$device} url={$page_url}: {$err}" );
            return [ 'ok' => false, 'error' => $err ];
        }

        $code  = (int) wp_remote_retrieve_response_code( $resp );
        $body  = (string) wp_remote_retrieve_body( $resp );
        $ctype = (string) wp_remote_retrieve_header( $resp, 'content-type' );

        if ( $code < 200 || $code >= 300 || strpos( (string) $ctype, 'image/' ) !== 0 ) {
            self::log( "capture HTTP {$code} ctype={$ctype} device={$device} url={$page_url} body=" . substr( $body, 0, 300 ) );
            return [ 'ok' => false, 'error' => "スクショAPIエラー (HTTP {$code})" ];
        }
        if ( $body === '' ) {
            return [ 'ok' => false, 'error' => 'スクショAPIの応答が空でした' ];
        }

        $mime = trim( (string) strtok( $ctype, ';' ) ); // "image/jpeg; charset=..." → "image/jpeg"
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
            $mime = 'image/jpeg';
        }

        return [ 'ok' => true, 'bytes' => $body, 'mime' => $mime ];
    }

    private static function log( string $message ): void {
        @file_put_contents(
            self::LOG,
            date( 'Y-m-d H:i:s' ) . " [screenshot] {$message}\n",
            FILE_APPEND
        );
    }
}
