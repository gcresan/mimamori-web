<?php
// FILE: inc/gcrev-api/modules/class-strategy-report-service.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Report_Service' ) ) { return; }

/**
 * Gcrev_Strategy_Report_Service
 *
 * 戦略連動型 月次レポートの生成オーケストレーター。
 *
 *   generate(user_id, year_month, source)
 *     1. 排他ロック (transient)
 *     2. 戦略取得 (Strategy_Repository::get_active_for_month)
 *     3. データ集約 (Aggregator)
 *     4. レポート行 upsert (status=running)
 *     5. プロンプト構築 → Gemini 呼び出し
 *     6. JSON パース + Validator
 *     7. HTML レンダ
 *     8. 完了保存
 *
 * 失敗時は status=failed で error_message を残す。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Report_Service {

    private Gcrev_Config       $config;
    private Gcrev_AI_Client    $ai;
    private Gcrev_GA4_Fetcher  $ga4;
    private Gcrev_GSC_Fetcher  $gsc;
    private Gcrev_Strategy_Repository        $strategy_repo;
    private Gcrev_Strategy_Report_Repository $report_repo;

    public function __construct(
        Gcrev_Config $config,
        Gcrev_AI_Client $ai,
        Gcrev_GA4_Fetcher $ga4,
        Gcrev_GSC_Fetcher $gsc
    ) {
        $this->config        = $config;
        $this->ai            = $ai;
        $this->ga4           = $ga4;
        $this->gsc           = $gsc;
        $this->strategy_repo = new Gcrev_Strategy_Repository();
        $this->report_repo   = new Gcrev_Strategy_Report_Repository();
    }

    /**
     * 戦略レポートを生成する。
     *
     * @param int    $user_id
     * @param string $year_month YYYY-MM
     * @param string $source     cron / manual_admin / manual_user
     * @return array { status, report_id, message?, report? }
     * @throws \Throwable 致命的エラー（ロック取得失敗・I/O 等）
     */
    public function generate( int $user_id, string $year_month, string $source = 'cron' ): array {
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
            throw new \InvalidArgumentException( 'invalid year_month: ' . $year_month );
        }

        $lock_key = 'gcrev_lock_strategy_report_' . $user_id . '_' . $year_month;
        if ( get_transient( $lock_key ) ) {
            return [ 'status' => 'busy', 'message' => 'already running' ];
        }
        set_transient( $lock_key, 1, 30 * MINUTE_IN_SECONDS );

        $report_id = 0;
        try {
            // 1. 戦略取得
            $strategy = $this->strategy_repo->get_active_for_month( $user_id, $year_month );
            if ( ! $strategy ) {
                $skipped_id = $this->report_repo->mark_skipped( $user_id, $year_month, 'no_active_strategy' );
                return [ 'status' => 'skipped', 'report_id' => $skipped_id, 'message' => 'active strategy not set for this month' ];
            }

            // 2. データ集約（GA4/GSC が未設定なら例外を投げる → catch で failed 扱い）
            $aggregator = new Gcrev_Strategy_Data_Aggregator( $this->config, $this->ga4, $this->gsc );
            $aggregated = $aggregator->collect( $user_id, $year_month );

            // 3. レポート行 upsert (running)
            $report_id = $this->report_repo->start_generation(
                $user_id, $year_month, (int) $strategy['id'], $source
            );

            // 4. プロンプト構築
            $prompt_builder = new Gcrev_Strategy_Prompt_Builder();
            $prompt = $prompt_builder->build( $strategy['strategy_json'], $aggregated );

            // 5. Gemini 呼び出し（JSON 出力強制）
            $start_us = microtime( true );
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'        => 0.3,
                'maxOutputTokens'    => 8192,
                'responseMimeType'   => 'application/json',
            ] );
            $duration_ms = (int) round( ( microtime( true ) - $start_us ) * 1000 );

            // 6. JSON パース + Validator
            $parsed = $this->parse_json_lenient( $raw );
            if ( ! is_array( $parsed ) ) {
                throw new \RuntimeException( 'AI応答をJSONとして解釈できませんでした' );
            }
            $validation = Gcrev_Strategy_Report_Schema_Validator::validate( $parsed );
            if ( ! $validation['valid'] ) {
                throw new \RuntimeException(
                    'AI出力がスキーマに違反しています: ' . implode( ' / ', $validation['errors'] )
                );
            }
            $normalized = $validation['normalized'];

            // 7. HTML レンダ
            $renderer = new Gcrev_Strategy_Report_Renderer();
            $html = $renderer->render( $normalized, $strategy, $aggregated );

            // 8. 完了保存
            $this->report_repo->complete( $report_id, [
                'alignment_score'  => (int) ( $normalized['alignment_score'] ?? 0 ),
                'report_json'      => $normalized,
                'rendered_html'    => $html,
                'ai_model'         => $this->config->get_gemini_model(),
                // Vertex のレスポンスからtoken数は取れていないため null（将来 call_gemini_api_structured 化で対応）
                'ai_input_tokens'  => null,
                'ai_output_tokens' => null,
            ] );

            file_put_contents(
                '/tmp/gcrev_strategy_debug.log',
                date( 'Y-m-d H:i:s' ) . " generate completed user={$user_id} ym={$year_month} report={$report_id} dur_ms={$duration_ms}\n",
                FILE_APPEND
            );

            return [
                'status'    => 'completed',
                'report_id' => $report_id,
                'report'    => $this->report_repo->get_by_id( $report_id ),
            ];
        } catch ( \Throwable $e ) {
            file_put_contents(
                '/tmp/gcrev_strategy_debug.log',
                date( 'Y-m-d H:i:s' ) . " generate FAILED user={$user_id} ym={$year_month}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            if ( $report_id > 0 ) {
                $this->report_repo->fail( $report_id, $e->getMessage() );
            }
            throw $e;
        } finally {
            delete_transient( $lock_key );
        }
    }

    private function parse_json_lenient( string $raw ): ?array {
        $text = trim( $raw );
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```\s*$/', '', $text );
        $text = trim( (string) $text );

        $decoded = json_decode( $text, true );
        if ( is_array( $decoded ) ) { return $decoded; }

        if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
            $decoded = json_decode( $m[0], true );
            if ( is_array( $decoded ) ) { return $decoded; }
        }
        return null;
    }
}
