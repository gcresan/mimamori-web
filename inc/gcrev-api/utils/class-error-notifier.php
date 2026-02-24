<?php
// FILE: inc/gcrev-api/utils/class-error-notifier.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Error_Notifier' ) ) { return; }

/**
 * Gcrev_Error_Notifier
 *
 * Cron ジョブの失敗時にメールで管理者に通知する。
 * Gcrev_Cron_Logger::finish() から自動呼び出しされる。
 *
 * 設定はオプション（wp_options）で管理:
 *   gcrev_notify_enabled        … '1' or '0'
 *   gcrev_notify_recipient      … メールアドレス
 *   gcrev_notify_error_threshold … 通知を送るエラー数の閾値
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Gcrev_Error_Notifier {

    /** オプションキー */
    private const OPT_ENABLED   = 'gcrev_notify_enabled';
    private const OPT_RECIPIENT = 'gcrev_notify_recipient';
    private const OPT_THRESHOLD = 'gcrev_notify_error_threshold';

    /** スロットル: 同一ジョブへの通知は1時間に1回まで */
    private const THROTTLE_TTL = 3600;

    // =========================================================
    // 設定取得
    // =========================================================

    public static function is_enabled(): bool {
        return get_option( self::OPT_ENABLED, '0' ) === '1';
    }

    public static function get_recipient(): string {
        $email = get_option( self::OPT_RECIPIENT, '' );
        return $email !== '' ? $email : get_option( 'admin_email', '' );
    }

    public static function get_threshold(): int {
        return max( 1, (int) get_option( self::OPT_THRESHOLD, 1 ) );
    }

    // =========================================================
    // 通知判定
    // =========================================================

    /**
     * 完了したジョブをチェックし、必要なら通知を送信する。
     *
     * @param int $log_id Gcrev_Cron_Logger のログ ID
     */
    public static function maybe_notify( int $log_id ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        if ( ! class_exists( 'Gcrev_Cron_Logger' ) ) {
            return;
        }

        $log = Gcrev_Cron_Logger::get_log( $log_id );
        if ( ! $log ) {
            return;
        }

        // 成功・ロック（スキップ）は通知不要
        if ( in_array( $log->status, [ 'success', 'locked' ], true ) ) {
            return;
        }

        // エラー数が閾値未満なら通知しない
        if ( (int) $log->users_error < self::get_threshold() ) {
            return;
        }

        // スロットルチェック
        if ( self::is_throttled( $log->job_name ) ) {
            return;
        }

        $details = Gcrev_Cron_Logger::get_user_logs( $log_id );
        self::send_notification( $log, $details );

        // スロットル設定
        set_transient( 'gcrev_notify_throttle_' . sanitize_key( $log->job_name ), 1, self::THROTTLE_TTL );
    }

    // =========================================================
    // テスト送信
    // =========================================================

    /**
     * テスト通知を送信する。
     *
     * @return bool 送信成功
     */
    public static function send_test(): bool {
        $to      = self::get_recipient();
        $site    = get_bloginfo( 'name' );
        $subject = "[{$site}] GCREV 通知テスト";
        $body    = "このメールは みまもりウェブ のエラー通知機能のテストです。\n\n";
        $body   .= "通知が正常に設定されています。\n";
        $body   .= '送信先: ' . $to . "\n";
        $body   .= '閾値: ' . self::get_threshold() . " エラー以上で通知\n\n";
        $body   .= '管理画面: ' . admin_url( 'admin.php?page=gcrev-notification-settings' );

        return wp_mail( $to, $subject, $body );
    }

    // =========================================================
    // Private
    // =========================================================

    /**
     * スロットルチェック。
     *
     * @param  string $job_name
     * @return bool true = スロットル中（送信しない）
     */
    private static function is_throttled( string $job_name ): bool {
        return (bool) get_transient( 'gcrev_notify_throttle_' . sanitize_key( $job_name ) );
    }

    /**
     * 通知メールを送信する。
     *
     * @param object $log     ログオブジェクト
     * @param array  $details ユーザー詳細の配列
     */
    private static function send_notification( object $log, array $details ): void {
        $to      = self::get_recipient();
        $site    = get_bloginfo( 'name' );
        $subject = "[{$site}] Cronジョブエラー: {$log->job_name}";

        $body  = "Cronジョブ「{$log->job_name}」でエラーが発生しました。\n\n";
        $body .= "開始時刻: {$log->started_at}\n";
        $body .= "終了時刻: " . ( $log->finished_at ?: '(未完了)' ) . "\n";
        $body .= "ステータス: {$log->status}\n";
        $body .= "処理ユーザー数: {$log->users_total}\n";
        $body .= "  成功: {$log->users_success}\n";
        $body .= "  スキップ: {$log->users_skipped}\n";
        $body .= "  エラー: {$log->users_error}\n\n";

        if ( $log->error_message ) {
            $body .= "エラー概要: {$log->error_message}\n\n";
        }

        // エラーのあったユーザー詳細
        $error_details = array_filter( $details, static function ( $d ) {
            return $d->status === 'error';
        } );

        if ( ! empty( $error_details ) ) {
            $body .= "--- エラー詳細 ---\n";
            foreach ( $error_details as $d ) {
                $body .= "  User ID {$d->user_id}: " . ( $d->detail ?: '(詳細なし)' ) . "\n";
            }
            $body .= "\n";
        }

        $body .= '管理画面で確認: ' . admin_url( 'admin.php?page=gcrev-cron-monitor' ) . "\n";

        $sent = wp_mail( $to, $subject, $body );

        if ( $sent ) {
            error_log( "[GCREV][Notify] Sent error notification to {$to} for job {$log->job_name}" );
        } else {
            error_log( "[GCREV][Notify] FAILED to send notification to {$to} for job {$log->job_name}" );
        }
    }
}
