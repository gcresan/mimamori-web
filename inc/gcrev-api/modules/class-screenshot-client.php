<?php
// FILE: inc/gcrev-api/modules/class-screenshot-client.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Screenshot_Client' ) ) { return; }

/**
 * Gcrev_Screenshot_Client
 *
 * 外部スクリーンショットAPIを使ってページのキャプチャ画像を取得する。
 *
 * 設定の優先順位:
 *   1. wp-config.php の URLテンプレート定数（上級者・他社プロバイダ用の上書き）
 *      define( 'GCREV_SCREENSHOT_API_PC',     '...&viewport_width=1280&url={URL}' );
 *      define( 'GCREV_SCREENSHOT_API_MOBILE', '...&viewport_width=390&url={URL}' );
 *   2. 管理画面「スクショAPI設定」（option gcrev_screenshot_settings）
 *      ScreenshotOne のアクセスキー等から取得URLを自動生成する。
 *
 * テンプレート内の {URL} は、対象ページURL（rawurlencode 済み）に置換される。
 * API はレスポンスボディに画像バイナリ（Content-Type: image/*）を返すこと。
 *
 * @package Mimamori_Web
 */
class Gcrev_Screenshot_Client {

    private const LOG = '/tmp/gcrev_page_analysis_debug.log';

    /**
     * 指定デバイス（'pc' / 'mobile'）または全体でキャプチャURLを組み立て可能か。
     * 「有効化」フラグとは独立（キー/定数があれば true）。接続テスト等で使う。
     */
    public static function is_configured( string $device = '' ): bool {
        if ( $device === 'pc' )     { return self::template( 'pc' ) !== ''; }
        if ( $device === 'mobile' ) { return self::template( 'mobile' ) !== ''; }
        return self::template( 'pc' ) !== '' || self::template( 'mobile' ) !== '';
    }

    /**
     * 自動キャプチャ（cron・クライアントの取得ボタン）を実行してよいか。
     * wp-config 定数があれば常に有効、無ければ管理画面の「有効化」トグルに従う。
     */
    public static function is_enabled(): bool {
        if ( ( defined( 'GCREV_SCREENSHOT_API_PC' ) && GCREV_SCREENSHOT_API_PC )
            || ( defined( 'GCREV_SCREENSHOT_API_MOBILE' ) && GCREV_SCREENSHOT_API_MOBILE ) ) {
            return true;
        }
        $s = self::admin_settings();
        return $s['enabled'] && $s['access_key'] !== '';
    }

    private static function template( string $device ): string {
        // 1) wp-config 定数（上級者・他社プロバイダ用の上書き）
        $const = $device === 'mobile' ? 'GCREV_SCREENSHOT_API_MOBILE' : 'GCREV_SCREENSHOT_API_PC';
        if ( defined( $const ) && constant( $const ) ) {
            return (string) constant( $const );
        }
        // 2) 管理画面設定（ScreenshotOne）— キーがあればURLを組み立てる
        //    （有効化フラグは is_enabled() で別途判定。テストはキーだけで実行可能）
        $s = self::admin_settings();
        if ( $s['access_key'] === '' ) {
            return '';
        }
        return self::build_screenshotone_url( $device, $s );
    }

    /**
     * 管理画面（option gcrev_screenshot_settings）の設定を復号して返す。
     *
     * @return array{enabled:bool, access_key:string, pc_width:int, mobile_width:int, format:string}
     */
    private static function admin_settings(): array {
        $s = get_option( 'gcrev_screenshot_settings', [] );
        $s = is_array( $s ) ? $s : [];

        $key_enc = (string) ( $s['access_key'] ?? '' );
        $key     = '';
        if ( $key_enc !== '' ) {
            if ( ! class_exists( 'Gcrev_Crypto' ) ) {
                $f = dirname( __DIR__ ) . '/utils/class-crypto.php';
                if ( file_exists( $f ) ) { require_once $f; }
            }
            $key = class_exists( 'Gcrev_Crypto' ) ? Gcrev_Crypto::decrypt( $key_enc ) : $key_enc;
        }

        return [
            'enabled'           => ! empty( $s['enabled'] ),
            'access_key'        => $key,
            'pc_width'          => (int) ( $s['pc_width'] ?? 1280 ) ?: 1280,
            'mobile_width'      => (int) ( $s['mobile_width'] ?? 390 ) ?: 390,
            'pc_max_height'     => (int) ( $s['pc_max_height'] ?? 12000 ) ?: 12000,
            'mobile_max_height' => (int) ( $s['mobile_max_height'] ?? 20000 ) ?: 20000,
            'format'            => ( ( $s['format'] ?? 'jpg' ) === 'png' ) ? 'png' : 'jpg',
        ];
    }

    /**
     * 管理画面設定から ScreenshotOne の取得URL（{URL} プレースホルダ付き）を組み立てる。
     */
    private static function build_screenshotone_url( string $device, array $s ): string {
        $max_height = $device === 'mobile' ? $s['mobile_max_height'] : $s['pc_max_height'];
        $params = [
            'access_key'           => $s['access_key'],
            'full_page'            => 'true',
            'full_page_scroll'     => 'true',
            'full_page_max_height' => (string) $max_height, // 縦長ページの生成失敗・タイムアウト対策（device別）
            'format'               => $s['format'],
            'block_cookie_banners' => 'true',
            'block_ads'            => 'true',
            'cache'                => 'true',
        ];
        if ( $s['format'] === 'jpg' ) {
            $params['image_quality'] = '80';
        }
        if ( $device === 'mobile' ) {
            // モバイル表示を手動設定（iPhoneのUAを明示）。倍率は2倍に抑え、縦長でも
            // JPEGの寸法上限(65,535px)に達しにくくする（プリセットの3倍だと縦長で超過する）。
            $params['viewport_width']     = (string) $s['mobile_width'];
            $params['viewport_mobile']    = 'true';
            $params['viewport_has_touch'] = 'true';
            $params['device_scale_factor'] = '2';
            $params['user_agent'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        } else {
            $params['viewport_width'] = (string) $s['pc_width'];
        }
        // url={URL} は最後に固定で付与（capture() が {URL} を実URLに置換する）
        return 'https://api.screenshotone.com/take?' . http_build_query( $params ) . '&url={URL}';
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
            'timeout' => 90, // 縦長フルページの生成は時間がかかるため長めに
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
            self::log( "capture HTTP {$code} ctype={$ctype} device={$device} url={$page_url} body=" . substr( $body, 0, 500 ) );
            // ScreenshotOne 等はエラー時に JSON を返すので、その理由を抽出して表示する
            $detail = '';
            $j      = json_decode( $body, true );
            if ( is_array( $j ) ) {
                $detail = (string) ( $j['error_message'] ?? $j['message'] ?? $j['error_code'] ?? '' );
            }
            if ( $detail === '' && $body !== '' ) {
                $detail = mb_substr( wp_strip_all_tags( $body ), 0, 200 );
            }
            return [ 'ok' => false, 'error' => "スクショAPIエラー (HTTP {$code})" . ( $detail !== '' ? ': ' . $detail : '' ) ];
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
