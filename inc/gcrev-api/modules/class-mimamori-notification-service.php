<?php
// FILE: inc/gcrev-api/modules/class-mimamori-notification-service.php
// =====================================================================
// みまもり通知サービス
//
//  A. みまもりアラート（日次）  — 異常検知時のみメール通知（全プラン）
//     ・アクセス急減 / 急増 / CV停滞 / サイトダウン / SSL期限
//     ・見える化プラン  : 事実のみ + アップグレード案内
//     ・AI改善提案プラン以上: AIによる原因分析と推奨アクション付き
//  B. みまもり週次便（週次）    — 異常がなくても毎週必ず届く簡易サマリー
//  C. AI改善提案通知            — 優先度「高」の改善アクションをプッシュ通知
//                                 （AI改善提案プラン以上・月2回上限）
//
// 閾値・上限は option 'mimamori_alert_settings' で変更可能（管理画面「通知設定」）。
// =====================================================================

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Mimamori_Notification_Service' ) ) { return; }

class Mimamori_Notification_Service {

    public const OPTION_KEY = 'mimamori_alert_settings';

    private const LOG = '/tmp/gcrev_notify_debug.log';

    // ユーザー側設定（user_meta、'1' で受信停止）
    public const META_OPTOUT_ALERT  = 'mimamori_alert_optout';
    public const META_OPTOUT_DIGEST = 'mimamori_digest_optout';

    // 送信履歴（user_meta）
    private const META_ALERT_LOG   = 'mimamori_alert_log';   // [ ['ts'=>int, 'type'=>string], ... ]
    private const META_SUGGEST_LOG = 'mimamori_suggest_log'; // [ ['ts'=>int, 'hash'=>string], ... ]

    private Gcrev_Insight_API $api;

    public function __construct() {
        $this->api = new Gcrev_Insight_API( false );
    }

    /**
     * 閾値設定（デフォルト + option 上書き）。
     */
    public static function get_settings(): array {
        $defaults = [
            'drop_threshold_pct'  => -40,  // アクセス急減: 前週比この値以下で発火
            'surge_threshold_pct' => 100,  // アクセス急増: 前週比この値以上で発火
            'min_weekly_sessions' => 50,   // 母数条件: 前週訪問数がこの値未満なら対象外
            'cv_stall_days'       => 30,   // CV停滞: この日数ゴール0件で発火
            'cv_lookback_days'    => 90,   // CV停滞: 過去この日数に1件以上の実績があるサイトのみ
            'ssl_warn_days'       => 30,   // SSL: 期限まで残りこの日数以下で発火
            'cooldown_days'       => 14,   // 同一種類アラートの再送禁止期間
            'weekly_alert_limit'  => 2,    // 1サイトあたり週の通知上限（サイトダウン/SSLは例外）
            'suggest_monthly_max' => 2,    // AI改善提案通知の月間上限
            'suggest_dedup_days'  => 60,   // 同一内容の提案の再送禁止期間
        ];
        $saved = get_option( self::OPTION_KEY, [] );
        return is_array( $saved ) ? array_merge( $defaults, array_intersect_key( $saved, $defaults ) ) : $defaults;
    }

    private static function log( string $msg ): void {
        @file_put_contents( self::LOG, date( 'Y-m-d H:i:s' ) . " {$msg}\n", FILE_APPEND );
    }

    // =================================================================
    // 対象ユーザー
    // =================================================================

    /**
     * 通知対象ユーザーIDを返す。
     * 通常階層プランのみ（本部・MEO特化・口コミ特化・管理者は対象外）。
     */
    private function get_target_user_ids(): array {
        $users = get_users( [ 'fields' => [ 'ID' ] ] );
        $ids   = [];
        foreach ( $users as $u ) {
            $uid = (int) $u->ID;
            if ( $uid <= 0 ) continue;
            if ( user_can( $uid, 'manage_options' ) ) continue;
            if ( function_exists( 'gcrev_user_api_enabled' ) && ! gcrev_user_api_enabled( $uid ) ) continue;
            $tier = gcrev_get_service_tier( $uid );
            if ( in_array( $tier, [ 'headquarters', 'meo_only', 'review_survey' ], true ) ) continue;
            $user = get_userdata( $uid );
            if ( ! $user || ! is_email( $user->user_email ) ) continue;
            $ids[] = $uid;
        }
        return $ids;
    }

    /**
     * 分析付き通知の対象か（AI改善提案プラン以上か）。
     */
    private function has_analysis_plan( int $user_id ): bool {
        return function_exists( 'mimamori_can' ) && mimamori_can( 'improvement_actions', $user_id );
    }

    // =================================================================
    // 共通: トレンド集計
    // =================================================================

    /**
     * 直近7日と前7日の合計を返す。データ不足時は null。
     *
     * @return array{recent:int, prev:int}|null
     */
    private function weekly_pair( int $user_id, string $metric ): ?array {
        try {
            $trend  = $this->api->get_daily_metric_trend_for_user( $user_id, $metric, 30 );
            $values = array_values( array_map( 'intval', $trend['values'] ?? [] ) );
        } catch ( \Throwable $e ) {
            self::log( "weekly_pair ERROR user={$user_id} metric={$metric}: " . $e->getMessage() );
            return null;
        }
        if ( count( $values ) < 14 ) { return null; }
        $last14 = array_slice( $values, -14 );
        return [
            'prev'   => array_sum( array_slice( $last14, 0, 7 ) ),
            'recent' => array_sum( array_slice( $last14, 7, 7 ) ),
        ];
    }

    // =================================================================
    // 通知 → AIチャット連携（ワンタップ導線）
    // =================================================================

    /**
     * 通知コンテキストトークンを発行し、チャット起動URLを返す。
     *
     * トークンは transient（30日TTL）に user_id 付きで保存し、チャット側の
     * 参照時に「ログイン中ユーザー == トークンの user_id」を検証する
     * （他テナントの通知内容は参照不可。期限切れは transient の失効で担保）。
     *
     * URL は /login/?redirect_to= を経由する:
     *   - ログイン済み  → 即座に /dashboard/?mw_chat_notify={token} へ
     *   - 未ログイン    → ログイン後に同URLへ復帰（既存の認証フロー）
     */
    private function create_chat_link( int $uid, string $type, string $summary ): string {
        try {
            $token = bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $e ) {
            $token = md5( uniqid( (string) $uid, true ) . wp_salt( 'auth' ) );
        }
        set_transient( 'mimamori_notify_ctx_' . $token, [
            'user_id' => $uid,
            'type'    => $type,
            'summary' => mb_substr( $summary, 0, 1500 ),
            'created' => time(),
        ], 30 * DAY_IN_SECONDS );

        $target = home_url( '/dashboard/' ) . '?mw_chat_notify=' . $token;
        return home_url( '/login/' ) . '?redirect_to=' . rawurlencode( $target );
    }

    // =================================================================
    // A. みまもりアラート（日次）
    // =================================================================

    public function run_daily_alert_scan(): void {
        $s   = self::get_settings();
        $ids = $this->get_target_user_ids();
        self::log( 'alert scan START users=' . count( $ids ) );

        foreach ( $ids as $uid ) {
            if ( get_user_meta( $uid, self::META_OPTOUT_ALERT, true ) === '1' ) { continue; }
            try {
                $alerts = array_merge(
                    $this->detect_traffic_alerts( $uid, $s ),
                    $this->detect_cv_stall( $uid, $s ),
                    $this->detect_site_health( $uid, $s )
                );
                foreach ( $alerts as $alert ) {
                    $urgent = ! empty( $alert['urgent'] );
                    if ( ! $this->can_send( $uid, $alert['type'], $s, $urgent ) ) { continue; }
                    $this->send_alert_mail( $uid, $alert );
                    $this->record_sent( $uid, $alert['type'] );
                }
            } catch ( \Throwable $e ) {
                self::log( "alert scan ERROR user={$uid}: " . $e->getMessage() );
            }
        }
        self::log( 'alert scan END' );
    }

    /**
     * アクセス急減 / 急増の検知。
     */
    private function detect_traffic_alerts( int $uid, array $s ): array {
        $pair = $this->weekly_pair( $uid, 'sessions' );
        if ( $pair === null ) { return []; }
        // 母数条件: 前週訪問数が小さいサイトは誤報になりやすいため対象外
        if ( $pair['prev'] < (int) $s['min_weekly_sessions'] ) { return []; }

        $change_pct = ( ( $pair['recent'] - $pair['prev'] ) / max( 1, $pair['prev'] ) ) * 100;
        $alerts     = [];

        if ( $change_pct <= (float) $s['drop_threshold_pct'] ) {
            $alerts[] = [
                'type'    => 'access_drop',
                'subject' => 'アクセスが急減しています',
                'facts'   => sprintf(
                    "直近7日間の訪問数が %s件 となり、前の7日間（%s件）から %.1f%% 減少しています。",
                    number_format( $pair['recent'] ), number_format( $pair['prev'] ), $change_pct
                ),
            ];
        } elseif ( $change_pct >= (float) $s['surge_threshold_pct'] ) {
            $alerts[] = [
                'type'    => 'access_surge',
                'subject' => 'アクセスが急増しています',
                'facts'   => sprintf(
                    "直近7日間の訪問数が %s件 となり、前の7日間（%s件）から %.1f%% 増加しています。",
                    number_format( $pair['recent'] ), number_format( $pair['prev'] ), $change_pct
                ),
            ];
        }
        return $alerts;
    }

    /**
     * CV停滞の検知（直近30日0件、過去90日に実績ありのサイトのみ）。
     */
    private function detect_cv_stall( int $uid, array $s ): array {
        try {
            $trend  = $this->api->get_daily_metric_trend_for_user( $uid, 'cv', 30 );
            $recent = array_sum( array_map( 'intval', $trend['values'] ?? [] ) );
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( $recent > 0 ) { return []; }

        // 過去実績: 直近3ヶ月の月次確定CVの合計（約90日）
        $tz       = wp_timezone();
        $lookback = 0;
        foreach ( [ 'this month', 'first day of last month', 'first day of -2 months' ] as $rel ) {
            try {
                $ym  = ( new \DateTimeImmutable( $rel, $tz ) )->format( 'Y-m' );
                $eff = $this->api->get_effective_cv_monthly( $ym, $uid );
                $lookback += (int) ( $eff['total'] ?? 0 );
            } catch ( \Throwable $e ) {
                // 取得失敗月はスキップ
            }
        }
        if ( $lookback < 1 ) { return []; }

        return [ [
            'type'    => 'cv_stall',
            'subject' => 'お問い合わせが止まっています',
            'facts'   => sprintf(
                "直近%d日間、お問い合わせ・電話タップなどのゴールが0件です（過去%d日間では %d件 の実績があります）。",
                (int) $s['cv_stall_days'], (int) $s['cv_lookback_days'], $lookback
            ),
        ] ];
    }

    /**
     * サイトダウン / SSL証明書期限の検知（外形監視）。
     */
    private function detect_site_health( int $uid, array $s ): array {
        $site_url = trim( (string) get_user_meta( $uid, 'weisite_url', true ) );
        if ( $site_url === '' ) {
            $site_url = trim( (string) get_user_meta( $uid, 'report_site_url', true ) );
        }
        if ( $site_url === '' || ! preg_match( '#^https?://#', $site_url ) ) { return []; }

        $alerts = [];

        // --- 死活チェック（5xx または接続不能をダウンとみなす。4xxはBot対策誤検知があるため除外） ---
        $response = wp_remote_get( $site_url, [ 'timeout' => 15, 'redirection' => 3, 'user-agent' => 'MimamoriWeb-Monitor/1.0' ] );
        if ( is_wp_error( $response ) ) {
            self::log( "site health: user={$uid} url={$site_url} error=" . $response->get_error_message() );
            $alerts[] = [
                'type'    => 'site_down',
                'urgent'  => true,
                'subject' => 'サイトにアクセスできません',
                'facts'   => sprintf( "サイト（%s）にアクセスできない状態を検知しました（%s）。", $site_url, $response->get_error_message() ),
            ];
        } else {
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code >= 500 ) {
                $alerts[] = [
                    'type'    => 'site_down',
                    'urgent'  => true,
                    'subject' => 'サイトでエラーが発生しています',
                    'facts'   => sprintf( "サイト（%s）がエラーを返しています（HTTPステータス: %d）。", $site_url, $code ),
                ];
            }
        }

        // --- SSL証明書期限チェック ---
        $host = wp_parse_url( $site_url, PHP_URL_HOST );
        if ( $host && strpos( $site_url, 'https://' ) === 0 ) {
            try {
                $ctx    = stream_context_create( [ 'ssl' => [ 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false ] ] );
                $client = @stream_socket_client( "ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx );
                if ( $client ) {
                    $params = stream_context_get_params( $client );
                    $cert   = $params['options']['ssl']['peer_certificate'] ?? null;
                    if ( $cert && function_exists( 'openssl_x509_parse' ) ) {
                        $parsed  = openssl_x509_parse( $cert );
                        $expires = (int) ( $parsed['validTo_time_t'] ?? 0 );
                        if ( $expires > 0 ) {
                            $days_left = (int) floor( ( $expires - time() ) / DAY_IN_SECONDS );
                            if ( $days_left <= (int) $s['ssl_warn_days'] ) {
                                $alerts[] = [
                                    'type'    => 'ssl_expiry',
                                    'urgent'  => true,
                                    'subject' => 'SSL証明書の有効期限が近づいています',
                                    'facts'   => sprintf(
                                        "サイト（%s）のSSL証明書の有効期限が残り%d日です（期限: %s）。",
                                        $site_url, max( 0, $days_left ), wp_date( 'Y年n月j日', $expires )
                                    ),
                                ];
                            }
                        }
                    }
                    fclose( $client );
                }
            } catch ( \Throwable $e ) {
                // 証明書チェック自体の失敗ではアラートを出さない（誤報防止）
                self::log( "ssl check skip user={$uid}: " . $e->getMessage() );
            }
        }

        return $alerts;
    }

    /**
     * クールダウン + 週次上限の判定。
     */
    private function can_send( int $uid, string $type, array $s, bool $urgent ): bool {
        $log = get_user_meta( $uid, self::META_ALERT_LOG, true );
        $log = is_array( $log ) ? $log : [];
        $now = time();

        // 同一種類のクールダウン
        $cooldown = (int) $s['cooldown_days'] * DAY_IN_SECONDS;
        foreach ( $log as $entry ) {
            if ( ( $entry['type'] ?? '' ) === $type && ( $now - (int) ( $entry['ts'] ?? 0 ) ) < $cooldown ) {
                return false;
            }
        }

        // 週次上限（サイトダウン・SSLは例外として即時送信）
        if ( ! $urgent ) {
            $week_count = 0;
            foreach ( $log as $entry ) {
                if ( ( $now - (int) ( $entry['ts'] ?? 0 ) ) < 7 * DAY_IN_SECONDS ) { $week_count++; }
            }
            if ( $week_count >= (int) $s['weekly_alert_limit'] ) { return false; }
        }
        return true;
    }

    private function record_sent( int $uid, string $type ): void {
        $log   = get_user_meta( $uid, self::META_ALERT_LOG, true );
        $log   = is_array( $log ) ? $log : [];
        $log[] = [ 'ts' => time(), 'type' => $type ];
        // 90日より古い履歴は破棄
        $log = array_values( array_filter( $log, static function ( $e ) {
            return ( time() - (int) ( $e['ts'] ?? 0 ) ) < 90 * DAY_IN_SECONDS;
        } ) );
        update_user_meta( $uid, self::META_ALERT_LOG, $log );
    }

    /**
     * アラートメール送信（プラン別に本文を出し分け）。
     */
    private function send_alert_mail( int $uid, array $alert ): void {
        $user = get_userdata( $uid );
        if ( ! $user ) { return; }

        $with_analysis = $this->has_analysis_plan( $uid );
        // AI改善提案プラン以上のみ AI 分析（Gemini 呼び出し）を行う
        $analysis = $with_analysis ? $this->generate_analysis( $uid, $alert ) : '';
        $email    = $this->build_alert_email( $uid, ( $user->display_name ?: $user->user_login ), $alert, $with_analysis, $analysis );

        $sent = wp_mail( $user->user_email, $email['subject'], $email['body'] );
        self::log( sprintf( 'alert sent user=%d type=%s analysis=%s result=%s', $uid, $alert['type'], $with_analysis ? 'yes' : 'no', $sent ? 'OK' : 'FAIL' ) );
    }

    /**
     * アラートメールの件名・本文を組み立てる（実送信・テスト送信で共有）。
     *
     * @param int    $link_uid      チャット起動リンクのトークン所有者（クリック時に文脈解決する本人）
     * @param string $name          宛名
     * @param array  $alert         ['type','subject','facts']
     * @param bool   $with_analysis 分析付き（AI改善提案プラン以上）か
     * @param string $analysis      AI分析テキスト（空なら分析ブロックを省略）
     * @return array{subject:string, body:string}
     */
    private function build_alert_email( int $link_uid, string $name, array $alert, bool $with_analysis, string $analysis ): array {
        $subject = '【みまもりウェブ】' . $alert['subject'];

        $lines   = [];
        $lines[] = $name . ' 様';
        $lines[] = '';
        $lines[] = 'みまもりウェブのAIが、サイトの変化を検知しました。';
        $lines[] = '';
        $lines[] = '■ 検知内容';
        $lines[] = $alert['facts'];
        $lines[] = '';

        if ( $with_analysis ) {
            // AI改善提案プラン以上: AIによる原因分析と推奨アクションを付ける
            if ( $analysis !== '' ) {
                $lines[] = '■ AIによる分析と推奨アクション';
                $lines[] = $analysis;
                $lines[] = '';
            }
            $lines[] = '詳しい状況はダッシュボードでご確認いただけます。';
            $lines[] = home_url( '/dashboard/' );
            $lines[] = '';
            // ワンタップでチャットが文脈付きで開く導線
            $ctx_summary = $alert['facts'] . ( $analysis !== '' ? "\n\n【AIによる分析】\n" . $analysis : '' );
            $lines[] = '▼ この件についてAIに質問する';
            $lines[] = $this->create_chat_link( $link_uid, 'alert_' . $alert['type'], $ctx_summary );
        } else {
            // 見える化プラン: 事実のみ + 固定のアップグレード案内
            $lines[] = '現在の状況はダッシュボードでご確認いただけます。';
            $lines[] = home_url( '/dashboard/' );
            $lines[] = '';
            $lines[] = '原因の分析と改善アクションのご提案は、AI改善提案プラン以上でご利用いただけます。';
            $lines[] = 'プランのご案内: ' . home_url( '/plans/' );
        }

        $lines[] = '';
        $lines[] = '────────────────────';
        $lines[] = 'みまもりウェブ';
        $lines[] = '※このメールはみまもりアラートの自動通知です。';

        return [ 'subject' => $subject, 'body' => implode( "\n", $lines ) ];
    }

    /**
     * AIによる原因分析と推奨アクションの生成（失敗時は空文字）。
     */
    private function generate_analysis( int $uid, array $alert ): string {
        try {
            $config = new Gcrev_Config();
            $ai     = new Gcrev_AI_Client( $config );
            $prompt = "あなたはWebサイト改善の専門家です。中小企業のWebサイトで以下の事象を検知しました。\n\n"
                . "検知内容: {$alert['facts']}\n\n"
                . "この事象について、(1) 考えられる原因を2〜3点、(2) 推奨アクションを2〜3点、"
                . "それぞれ「・」始まりの簡潔な日本語の箇条書きで出力してください。"
                . "マークダウン記法（# や ** など）は使わず、プレーンテキストのみ。"
                . "専門用語は避け、Web初心者の経営者にも伝わる表現にしてください。全体で300文字以内。";
            $text = (string) $ai->call_gemini_api( $prompt, [ 'temperature' => 0.3 ] );
            return trim( wp_strip_all_tags( $text ) );
        } catch ( \Throwable $e ) {
            self::log( "analysis generate FAIL user={$uid}: " . $e->getMessage() );
            return '';
        }
    }

    // =================================================================
    // B. みまもり週次便
    // =================================================================

    public function run_weekly_digest(): void {
        $ids = $this->get_target_user_ids();
        self::log( 'weekly digest START users=' . count( $ids ) );

        foreach ( $ids as $uid ) {
            if ( get_user_meta( $uid, self::META_OPTOUT_DIGEST, true ) === '1' ) { continue; }
            try {
                $this->send_weekly_digest( $uid );
            } catch ( \Throwable $e ) {
                self::log( "weekly digest ERROR user={$uid}: " . $e->getMessage() );
            }
        }
        self::log( 'weekly digest END' );
    }

    /**
     * 週次便の本文は意図的に薄く保つ（3〜5行・分析や提案は含めない）。
     * 月次レポート総評との差別化のため、ここに情報を足さないこと。
     */
    private function send_weekly_digest( int $uid ): void {
        $user = get_userdata( $uid );
        if ( ! $user ) { return; }

        $sessions = $this->weekly_pair( $uid, 'sessions' );
        $cv       = $this->weekly_pair( $uid, 'cv' );

        // 直近7日にアラートを送ったかどうか
        $log        = get_user_meta( $uid, self::META_ALERT_LOG, true );
        $log        = is_array( $log ) ? $log : [];
        $week_alert = 0;
        foreach ( $log as $entry ) {
            if ( ( time() - (int) ( $entry['ts'] ?? 0 ) ) < 7 * DAY_IN_SECONDS ) { $week_alert++; }
        }
        $anomaly_line = $week_alert > 0
            ? sprintf( '今週はアラートを%d件お送りしました。詳細は過去のメールをご確認ください。', $week_alert )
            : '今週も異常はありませんでした。';

        $email = $this->build_digest_email(
            $uid, ( $user->display_name ?: $user->user_login ),
            $this->has_analysis_plan( $uid ), $sessions, $cv, $anomaly_line
        );

        $sent = wp_mail( $user->user_email, $email['subject'], $email['body'] );
        self::log( sprintf( 'weekly digest sent user=%d result=%s', $uid, $sent ? 'OK' : 'FAIL' ) );
    }

    /**
     * 週次便メールの件名・本文を組み立てる（実送信・テスト送信で共有）。
     * 本文は意図的に薄く保つ（3〜5行・分析や提案は含めない）。
     *
     * @param array{recent:int,prev:int}|null $sessions 訪問数の今週/前週
     * @param array{recent:int,prev:int}|null $cv       問い合わせの今週/前週
     * @return array{subject:string, body:string}
     */
    private function build_digest_email( int $link_uid, string $name, bool $with_analysis, ?array $sessions, ?array $cv, string $anomaly_line ): array {
        $fmt_pair = static function ( ?array $pair, string $unit ): string {
            if ( $pair === null ) { return 'データ取得中です'; }
            $diff = $pair['recent'] - $pair['prev'];
            $sign = $diff > 0 ? '+' : ( $diff < 0 ? '−' : '±' );
            return sprintf( '%s%s（前週比 %s%s%s）', number_format( $pair['recent'] ), $unit, $sign, number_format( abs( $diff ) ), $unit );
        };

        $subject = '【みまもりウェブ】みまもり週次便';
        $summary_lines = [
            '・今週の訪問数: ' . $fmt_pair( $sessions, '件' ),
            '・今週のお問い合わせ: ' . $fmt_pair( $cv, '件' ),
            '・異常検知: ' . $anomaly_line,
        ];

        $lines   = [ $name . ' 様', '' ];
        $lines   = array_merge( $lines, $summary_lines );
        $lines[] = '';
        $lines[] = '引き続きAIが24時間サイトを見守っています。';
        $lines[] = '詳細: ' . home_url( '/dashboard/' );

        if ( $with_analysis ) {
            $lines[] = '';
            $lines[] = '▼ 今週の数字についてAIに聞いてみる';
            $lines[] = $this->create_chat_link( $link_uid, 'digest', implode( "\n", $summary_lines ) );
        } else {
            // 見える化プラン: チャットは対象外のためアップグレード導線を表示
            $lines[] = '';
            $lines[] = '数字の見方のご相談やAIチャットは、AI改善提案プラン以上でご利用いただけます。';
            $lines[] = 'プランのご案内: ' . home_url( '/plans/' );
        }

        return [ 'subject' => $subject, 'body' => implode( "\n", $lines ) ];
    }

    // =================================================================
    // C. AI改善提案通知（AI改善提案プラン以上・月2回上限）
    // =================================================================

    public function run_improvement_suggestions(): void {
        $s   = self::get_settings();
        $ids = $this->get_target_user_ids();
        self::log( 'suggest scan START users=' . count( $ids ) );

        foreach ( $ids as $uid ) {
            if ( ! $this->has_analysis_plan( $uid ) ) { continue; }
            if ( get_user_meta( $uid, self::META_OPTOUT_ALERT, true ) === '1' ) { continue; }
            try {
                $this->maybe_send_suggestion( $uid, $s );
            } catch ( \Throwable $e ) {
                self::log( "suggest ERROR user={$uid}: " . $e->getMessage() );
            }
        }
        self::log( 'suggest scan END' );
    }

    private function maybe_send_suggestion( int $uid, array $s ): void {
        $now = time();
        $log = get_user_meta( $uid, self::META_SUGGEST_LOG, true );
        $log = is_array( $log ) ? $log : [];

        // 月間上限（上限でありノルマではない — 送れる根拠がなければ送らない）
        $month_start = strtotime( wp_date( 'Y-m-01 00:00:00' ) );
        $month_count = 0;
        foreach ( $log as $entry ) {
            if ( (int) ( $entry['ts'] ?? 0 ) >= $month_start ) { $month_count++; }
        }
        if ( $month_count >= (int) $s['suggest_monthly_max'] ) { return; }

        // AI改善アクション提示の既存ロジックを流用し、優先度「高」のみ対象
        $service = $this->api->get_execution_service();
        if ( ! $service ) { return; }
        $month   = wp_date( 'Y-m' );
        $actions = $service->get_or_generate_actions( $uid, $month );
        if ( empty( $actions ) || ! is_array( $actions ) ) { return; }

        $dedup_window = (int) $s['suggest_dedup_days'] * DAY_IN_SECONDS;
        foreach ( $actions as $action ) {
            if ( ( $action['priority'] ?? '' ) !== 'high' ) { continue; }
            $title = trim( (string) ( $action['title'] ?? '' ) );
            if ( $title === '' ) { continue; }

            // 同一内容の再送防止（60日）
            $hash      = md5( $title );
            $duplicate = false;
            foreach ( $log as $entry ) {
                if ( ( $entry['hash'] ?? '' ) === $hash && ( $now - (int) ( $entry['ts'] ?? 0 ) ) < $dedup_window ) {
                    $duplicate = true;
                    break;
                }
            }
            if ( $duplicate ) { continue; }

            $this->send_suggestion_mail( $uid, $action );

            $log[] = [ 'ts' => $now, 'hash' => $hash ];
            $log   = array_values( array_filter( $log, static function ( $e ) use ( $now, $dedup_window ) {
                return ( $now - (int) ( $e['ts'] ?? 0 ) ) < max( $dedup_window, 90 * DAY_IN_SECONDS );
            } ) );
            update_user_meta( $uid, self::META_SUGGEST_LOG, $log );
            return; // 1回の実行で送るのは1通まで
        }
    }

    private function send_suggestion_mail( int $uid, array $action ): void {
        $user = get_userdata( $uid );
        if ( ! $user ) { return; }

        $email = $this->build_suggestion_email( $uid, ( $user->display_name ?: $user->user_login ), $action );

        $sent = wp_mail( $user->user_email, $email['subject'], $email['body'] );
        self::log( sprintf( 'suggest sent user=%d result=%s', $uid, $sent ? 'OK' : 'FAIL' ) );
    }

    /**
     * AI改善提案メールの件名・本文を組み立てる（実送信・テスト送信で共有）。
     *
     * @param array $action ['title','reason','expected_effect']
     * @return array{subject:string, body:string}
     */
    private function build_suggestion_email( int $link_uid, string $name, array $action ): array {
        $lines   = [];
        $lines[] = $name . ' 様';
        $lines[] = '';
        $lines[] = 'みまもりウェブのAIが、データから改善のチャンスを見つけました。';
        $lines[] = '';
        $lines[] = '■ ご提案';
        $lines[] = trim( (string) ( $action['title'] ?? '' ) );
        if ( ! empty( $action['reason'] ) ) {
            $lines[] = '';
            $lines[] = '■ データ上の根拠';
            $lines[] = trim( (string) $action['reason'] );
        }
        if ( ! empty( $action['expected_effect'] ) ) {
            $lines[] = '';
            $lines[] = '■ 期待できる効果';
            $lines[] = trim( (string) $action['expected_effect'] );
        }
        $lines[] = '';
        $lines[] = '詳しい内容と他の提案は「改善施策提案」ページでご確認いただけます。';
        $lines[] = home_url( '/execution-dashboard/' );
        $lines[] = '';
        // ワンタップでチャットが文脈付きで開く導線（C はAI改善提案プラン以上のみが対象）
        $ctx_summary = trim( (string) ( $action['title'] ?? '' ) );
        if ( ! empty( $action['reason'] ) ) {
            $ctx_summary .= "\n根拠: " . trim( (string) $action['reason'] );
        }
        if ( ! empty( $action['expected_effect'] ) ) {
            $ctx_summary .= "\n期待効果: " . trim( (string) $action['expected_effect'] );
        }
        $lines[] = '▼ この提案の進め方をAIに相談する';
        $lines[] = $this->create_chat_link( $link_uid, 'suggest', $ctx_summary );
        $lines[] = '';
        $lines[] = '────────────────────';
        $lines[] = 'みまもりウェブ';
        $lines[] = '※このメールはAI改善提案の自動通知です（月2回まで）。';

        return [ 'subject' => '【みまもりウェブ】AIからの改善提案', 'body' => implode( "\n", $lines ) ];
    }

    // =================================================================
    // テスト送信（管理画面「通知設定」から呼ぶ）
    // =================================================================

    /**
     * 通知メールのテスト送信。ダミーデータで本文レイアウトを確認する用途。
     *
     * 閾値判定・クールダウン・送信履歴記録・実データ取得・Gemini呼び出しは
     * 一切行わず、固定のサンプル内容を組み立てて指定アドレスに送る。
     * 件名に [テスト] を付与し、冒頭に注記を入れる。
     *
     * @param string $kind      'alert' | 'digest' | 'suggest'
     * @param string $recipient 送信先メールアドレス
     * @param array  $opts      ['with_analysis'=>bool, 'link_uid'=>int]
     *                          with_analysis: alert/digest のプラン別見え方の切替
     *                          link_uid: チャット起動リンクの所有者（既定はログイン中ユーザー）
     * @return array{ok:bool, message:string}
     */
    public function send_test_email( string $kind, string $recipient, array $opts = [] ): array {
        if ( ! is_email( $recipient ) ) {
            return [ 'ok' => false, 'message' => '送信先メールアドレスが不正です。' ];
        }

        $with_analysis = ! empty( $opts['with_analysis'] );
        $link_uid      = (int) ( $opts['link_uid'] ?? get_current_user_id() );
        $name          = 'テスト';

        switch ( $kind ) {
            case 'alert':
                $alert = [
                    'type'    => 'access_drop',
                    'subject' => 'アクセスが急減しています',
                    'facts'   => '直近7日間の訪問数が 120件 となり、前の7日間（218件）から 45.0% 減少しています。',
                ];
                // テストでは Gemini を呼ばず固定の分析サンプルを使う
                $analysis = $with_analysis
                    ? "・検索からの流入が減っている可能性があります（特定ページの順位下落など）。\n"
                      . "・SNSやキャンペーンの反響が一段落したことも考えられます。\n"
                      . "・まずは流入元の内訳と、下落しているページを確認することをおすすめします。"
                    : '';
                $email = $this->build_alert_email( $link_uid, $name, $alert, $with_analysis, $analysis );
                break;

            case 'digest':
                $email = $this->build_digest_email(
                    $link_uid, $name, $with_analysis,
                    [ 'recent' => 218, 'prev' => 240 ],
                    [ 'recent' => 5, 'prev' => 3 ],
                    '今週も異常はありませんでした。'
                );
                break;

            case 'suggest':
                $action = [
                    'title'           => 'トップページのタイトルに地域名を入れて、検索結果での見え方を改善してください',
                    'reason'          => '「（地域名） （業種）」での表示回数が月1,200回ありますが、クリック率が1.2%と低い状態です。',
                    'expected_effect' => 'タイトル最適化により、同キーワードでのクリック率改善とお問い合わせ増加が期待できます。',
                ];
                $email = $this->build_suggestion_email( $link_uid, $name, $action );
                break;

            default:
                return [ 'ok' => false, 'message' => '不明な通知種別です。' ];
        }

        $subject = '[テスト] ' . $email['subject'];
        $body    = "※これは通知設定からのテスト送信です。実際の通知では、各クライアントのサイトデータやAIによる分析が反映されます。\n\n"
                 . $email['body'];

        // 失敗時の PHPMailer エラー理由を捕捉する（サーバーのメール設定診断用）
        $mail_error = '';
        $capture    = static function ( $wp_error ) use ( &$mail_error ) {
            if ( is_wp_error( $wp_error ) ) { $mail_error = $wp_error->get_error_message(); }
        };
        add_action( 'wp_mail_failed', $capture );
        $sent = wp_mail( $recipient, $subject, $body );
        remove_action( 'wp_mail_failed', $capture );

        self::log( sprintf(
            'TEST %s to=%s analysis=%s result=%s%s',
            $kind, $recipient, $with_analysis ? 'yes' : 'no', $sent ? 'OK' : 'FAIL',
            ( ! $sent && $mail_error !== '' ) ? ' err=' . $mail_error : ''
        ) );

        return [
            'ok'      => (bool) $sent,
            'message' => $sent
                ? "テストメールを {$recipient} に送信しました。"
                : 'メール送信に失敗しました。' . ( $mail_error !== ''
                    ? '（詳細: ' . $mail_error . '）'
                    : 'サーバーのメール設定をご確認ください。' ),
        ];
    }

    /**
     * テスト送信の「対象クライアント」候補一覧（管理画面のセレクト用）。
     *
     * 自動通知の対象判定（get_target_user_ids）と同じ条件で絞り込み、
     * 名前・ID・プランを付与したラベルを返す。
     *
     * @return array<int,array{id:int,label:string,email:string}>
     */
    public function get_test_target_users(): array {
        $out = [];
        foreach ( $this->get_target_user_ids() as $uid ) {
            $u = get_userdata( $uid );
            if ( ! $u ) { continue; }
            $name = $u->display_name ?: $u->user_login;
            $plan = $this->has_analysis_plan( $uid ) ? 'AI改善提案プラン以上' : '見える化プラン';
            $out[] = [
                'id'    => $uid,
                'label' => sprintf( '%s（ID:%d / %s）', $name, $uid, $plan ),
                'email' => (string) $u->user_email,
            ];
        }
        usort( $out, static function ( $a, $b ) { return strcmp( $a['label'], $b['label'] ); } );
        return $out;
    }

    /**
     * 実データでの通知テスト送信（管理画面「通知設定」から呼ぶ）。
     *
     * 指定した「対象クライアント（$target_uid）」の実データから本番と同じ本文を
     * 組み立て、送信先だけ $recipient に差し替えて送る。閾値判定・クールダウン・
     * 送信履歴・月間上限などの状態は一切更新しない（純粋なプレビュー）。
     * プラン別の見え方は対象クライアントの実プランに従う。
     *
     * @param string $kind       'alert' | 'digest' | 'suggest'
     * @param int    $target_uid データ取得元の対象ユーザー
     * @param string $recipient  送信先メールアドレス
     * @return array{ok:bool, message:string}
     */
    public function send_real_test_email( string $kind, int $target_uid, string $recipient ): array {
        if ( ! is_email( $recipient ) ) {
            return [ 'ok' => false, 'message' => '送信先メールアドレスが不正です。' ];
        }
        $user = get_userdata( $target_uid );
        if ( ! $user ) {
            return [ 'ok' => false, 'message' => '対象クライアントが見つかりません。' ];
        }

        $name          = ( $user->display_name ?: $user->user_login );
        $with_analysis = $this->has_analysis_plan( $target_uid );

        switch ( $kind ) {
            case 'digest':
                $sessions = $this->weekly_pair( $target_uid, 'sessions' );
                $cv       = $this->weekly_pair( $target_uid, 'cv' );

                $log        = get_user_meta( $target_uid, self::META_ALERT_LOG, true );
                $log        = is_array( $log ) ? $log : [];
                $week_alert = 0;
                foreach ( $log as $entry ) {
                    if ( ( time() - (int) ( $entry['ts'] ?? 0 ) ) < 7 * DAY_IN_SECONDS ) { $week_alert++; }
                }
                $anomaly_line = $week_alert > 0
                    ? sprintf( '今週はアラートを%d件お送りしました。詳細は過去のメールをご確認ください。', $week_alert )
                    : '今週も異常はありませんでした。';

                $email = $this->build_digest_email( $target_uid, $name, $with_analysis, $sessions, $cv, $anomaly_line );
                break;

            case 'alert':
                $s      = self::get_settings();
                $alerts = array_merge(
                    $this->detect_traffic_alerts( $target_uid, $s ),
                    $this->detect_cv_stall( $target_uid, $s ),
                    $this->detect_site_health( $target_uid, $s )
                );
                if ( empty( $alerts ) ) {
                    return [ 'ok' => false, 'message' => 'この顧客には現在、送信対象となるアラートがありません（実データ上、異常が検知されていません）。文面の確認はダミーデータのテスト送信をご利用ください。' ];
                }
                $alert    = $alerts[0];
                $analysis = $with_analysis ? $this->generate_analysis( $target_uid, $alert ) : '';
                $email    = $this->build_alert_email( $target_uid, $name, $alert, $with_analysis, $analysis );
                break;

            case 'suggest':
                if ( ! $with_analysis ) {
                    return [ 'ok' => false, 'message' => 'この顧客はAI改善提案プラン未満のため、AI改善提案は対象外です。' ];
                }
                $exec = $this->api->get_execution_service();
                if ( ! $exec ) {
                    return [ 'ok' => false, 'message' => '改善提案サービスが利用できません。' ];
                }
                $actions = $exec->get_or_generate_actions( $target_uid, wp_date( 'Y-m' ) );
                $action  = null;
                foreach ( (array) $actions as $a ) {
                    if ( ( $a['priority'] ?? '' ) === 'high' && trim( (string) ( $a['title'] ?? '' ) ) !== '' ) {
                        $action = $a;
                        break;
                    }
                }
                if ( ! $action ) {
                    return [ 'ok' => false, 'message' => 'この顧客には現在、「優先度: 高」の改善提案がありません。' ];
                }
                $email = $this->build_suggestion_email( $target_uid, $name, $action );
                break;

            default:
                return [ 'ok' => false, 'message' => '不明な通知種別です。' ];
        }

        $subject = '[実データテスト/' . $name . '] ' . $email['subject'];
        $body    = "※これは通知設定からの「実データ」テスト送信です（対象クライアント: {$name} / ID: {$target_uid}）。\n"
                 . "本文は実際のデータから組み立てた本番同等の内容です。送信履歴・上限カウントは更新していません。\n\n"
                 . $email['body'];

        // 失敗時の PHPMailer エラー理由を捕捉する（サーバーのメール設定診断用）
        $mail_error = '';
        $capture    = static function ( $wp_error ) use ( &$mail_error ) {
            if ( is_wp_error( $wp_error ) ) { $mail_error = $wp_error->get_error_message(); }
        };
        add_action( 'wp_mail_failed', $capture );
        $sent = wp_mail( $recipient, $subject, $body );
        remove_action( 'wp_mail_failed', $capture );

        self::log( sprintf(
            'REAL-TEST %s target=%d to=%s result=%s%s',
            $kind, $target_uid, $recipient, $sent ? 'OK' : 'FAIL',
            ( ! $sent && $mail_error !== '' ) ? ' err=' . $mail_error : ''
        ) );

        return [
            'ok'      => (bool) $sent,
            'message' => $sent
                ? "実データテストメール（{$name} のデータ）を {$recipient} に送信しました。"
                : 'メール送信に失敗しました。' . ( $mail_error !== ''
                    ? '（詳細: ' . $mail_error . '）'
                    : 'サーバーのメール設定をご確認ください。' ),
        ];
    }
}
