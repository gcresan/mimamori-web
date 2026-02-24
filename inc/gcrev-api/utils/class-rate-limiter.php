<?php
// FILE: inc/gcrev-api/utils/class-rate-limiter.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Rate_Limiter') ) { return; }

/**
 * Gcrev_Rate_Limiter
 *
 * Transient ベースの簡易レートリミッター。
 * GA4/GSC API のクォータ超過（429エラー）を防止する。
 *
 * 仕組み:
 *   - 1分間のスライディングウィンドウでAPI呼び出し数をカウント
 *   - 閾値に達したら sleep() で待機（ブロッキング）
 *   - Transient の TTL は60秒（自動で古いカウントが消える）
 *
 * @package GCREV_INSIGHT
 * @since   2.1.0
 */
class Gcrev_Rate_Limiter {

    /** デフォルト上限（GA4 APIは600/min/project、余裕を持って400） */
    private const DEFAULT_MAX_PER_MINUTE = 400;

    /** スリープ間隔（秒） */
    private const SLEEP_SECONDS = 2;

    /** 最大待機回数（無限ループ防止） */
    private const MAX_WAIT_LOOPS = 30;

    /**
     * API呼び出し前に呼ぶ。制限に達していたら自動でスリープする。
     *
     * @param string $api_name      API名（例: 'ga4', 'gsc'）。カウンターの名前空間に使用
     * @param int    $max_per_minute 1分あたりの最大呼び出し数
     */
    public static function check_and_wait( string $api_name, int $max_per_minute = self::DEFAULT_MAX_PER_MINUTE ): void {
        $key   = 'gcrev_rl_' . sanitize_key( $api_name );
        $loops = 0;

        while ( $loops < self::MAX_WAIT_LOOPS ) {
            $count = (int) get_transient( $key );

            if ( $count < $max_per_minute ) {
                // カウントをインクリメント
                set_transient( $key, $count + 1, 60 );
                return;
            }

            // 制限に達したのでスリープ
            if ( $loops === 0 ) {
                error_log( "[GCREV][RateLimit] {$api_name}: limit reached ({$count}/{$max_per_minute}), waiting..." );
            }
            sleep( self::SLEEP_SECONDS );
            $loops++;
        }

        // MAX_WAIT_LOOPS 回待っても空かない場合は強行（ログだけ残す）
        error_log( "[GCREV][RateLimit] {$api_name}: WARNING — max wait exceeded, proceeding anyway" );
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, 60 );
    }

    /**
     * 現在のカウントを取得（デバッグ/監視用）
     *
     * @param  string $api_name API名
     * @return int 現在の1分間カウント
     */
    public static function get_current_count( string $api_name ): int {
        $key = 'gcrev_rl_' . sanitize_key( $api_name );
        return (int) get_transient( $key );
    }

    /**
     * カウンターをリセット（テスト用）
     *
     * @param string $api_name API名
     */
    public static function reset( string $api_name ): void {
        $key = 'gcrev_rl_' . sanitize_key( $api_name );
        delete_transient( $key );
    }
}
