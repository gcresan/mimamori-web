<?php
// FILE: inc/gcrev-api/modules/class-auto-article-service.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Auto_Article_Service' ) ) { return; }

/**
 * Gcrev_Auto_Article_Service
 *
 * キーワード調査結果をもとに、AIが毎日優先度の高いキーワードから順番に
 * 記事を自動で下書き生成し、既存記事と競合する場合は別の切り口へ自動でずらす。
 *
 * 既存 Gcrev_Writing_Service のパイプラインを再利用し、
 * その上にキーワード選定→重複チェック→自動実行→品質チェック→保存を追加する。
 *
 * @package Mimamori_Web
 */
class Gcrev_Auto_Article_Service {

    private Gcrev_Writing_Service          $writing;
    private Gcrev_AI_Client                $ai;
    private Gcrev_Config                   $config;
    private Gcrev_Keyword_Research_Service  $kw_service;

    private const DEBUG_LOG       = '/tmp/gcrev_autoarticle_debug.log';
    private const LOCK_KEY        = 'gcrev_lock_auto_article';
    private const LOCK_TTL        = 7200;
    private const CHUNK_DELAY_SEC = 30;
    private const LOG_ID_TRANSIENT = 'gcrev_auto_article_current_log_id';

    /** スコアリング: グループ優先度 */
    private const GROUP_PRIORITY = [
        'immediate'           => 15,
        'local_seo'           => 12,
        'competitor_gap'      => 10,
        'comparison'          => 10,
        'column'              => 8,
        'service_page'        => 7,
        'competitor_core'     => 6,
        'competitor_longterm' => 5,
        'competitor_compare'  => 5,
    ];

    /** スコアリング: 記事タイプ適合 */
    private const TYPE_FIT_SCORE = [
        'explanation' => 15,
        'faq'         => 15,
        'local'       => 14,
        'comparison'  => 10,
        'case_study'  => 8,
    ];

    public function __construct(
        Gcrev_Writing_Service          $writing,
        Gcrev_AI_Client                $ai,
        Gcrev_Config                   $config,
        Gcrev_Keyword_Research_Service $kw_service
    ) {
        $this->writing    = $writing;
        $this->ai         = $ai;
        $this->config     = $config;
        $this->kw_service = $kw_service;
    }

    // =========================================================
    // ログヘルパー
    // =========================================================

    private function log( string $msg ): void {
        file_put_contents( self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . " {$msg}\n",
            FILE_APPEND
        );
    }

    // =========================================================
    // 1. キーワード優先順位スコアリング
    // =========================================================

    /**
     * キーワード調査結果から優先順位スコアを算出し、降順で返す。
     *
     * @return array [ ['keyword' => '', 'group' => '', 'score' => float, 'reasons' => [], 'volume' => int, 'difficulty' => int, 'suggested_type' => ''], ... ]
     */
    public function score_keywords( int $user_id ): array {
        $research = $this->kw_service->get_latest_result( $user_id );
        if ( ! $research || empty( $research['groups'] ) ) {
            return [];
        }

        // クライアント設定
        $excluded_kws    = json_decode( get_user_meta( $user_id, 'gcrev_auto_article_excluded_keywords', true ) ?: '[]', true ) ?: [];
        $preferred_groups = json_decode( get_user_meta( $user_id, 'gcrev_auto_article_preferred_groups', true ) ?: '["immediate","local_seo","column"]', true ) ?: [];
        $min_score       = (int) ( get_user_meta( $user_id, 'gcrev_auto_article_min_score', true ) ?: 40 );

        // 既存記事キーワード取得
        $existing_keywords = $this->get_existing_article_keywords( $user_id );

        // 全グループからキーワードをフラット化
        $candidates = [];
        foreach ( $research['groups'] as $group_key => $group_data ) {
            if ( ! empty( $preferred_groups ) && ! in_array( $group_key, $preferred_groups, true ) ) {
                continue;
            }
            $keywords = $group_data['keywords'] ?? [];
            foreach ( $keywords as $kw_data ) {
                $keyword = $kw_data['keyword'] ?? '';
                if ( $keyword === '' ) continue;

                // 除外チェック
                $skip = false;
                foreach ( $excluded_kws as $ex ) {
                    if ( mb_stripos( $keyword, $ex ) !== false ) {
                        $skip = true;
                        break;
                    }
                }
                if ( $skip ) continue;

                $candidates[] = [
                    'keyword'        => $keyword,
                    'group'          => $group_key,
                    'volume'         => (int) ( $kw_data['volume'] ?? 0 ),
                    'difficulty'     => (int) ( $kw_data['difficulty'] ?? 50 ),
                    'suggested_type' => $kw_data['suggested_article_type'] ?? 'explanation',
                    'intent'         => $kw_data['intent'] ?? '',
                    'analysis'       => $kw_data['analysis'] ?? '',
                ];
            }
        }

        // スコアリング
        $scored = [];
        foreach ( $candidates as $c ) {
            $reasons = [];
            $score   = 0;

            // (1) 検索ボリューム: 0-20
            $vol = $c['volume'];
            if ( $vol >= 1000 )     { $s = 20; }
            elseif ( $vol >= 500 )  { $s = 15; }
            elseif ( $vol >= 100 )  { $s = 10; }
            elseif ( $vol >= 10 )   { $s = 5; }
            else                    { $s = 2; }
            $score += $s;
            $reasons[] = "ボリューム({$vol}): +{$s}";

            // (2) 難易度（低いほど高得点）: 0-20
            $diff = min( 100, max( 0, $c['difficulty'] ) );
            $s = (int) round( 20 * ( 1 - $diff / 100 ) );
            $score += $s;
            $reasons[] = "難易度({$diff}): +{$s}";

            // (3) グループ優先度: 0-15
            $s = self::GROUP_PRIORITY[ $c['group'] ] ?? 5;
            $score += $s;
            $reasons[] = "グループ({$c['group']}): +{$s}";

            // (4) 記事タイプ適合: 0-15
            $s = self::TYPE_FIT_SCORE[ $c['suggested_type'] ] ?? 5;
            $score += $s;
            $reasons[] = "タイプ({$c['suggested_type']}): +{$s}";

            // (5) 既存記事なし: 0-15
            $kw_lower = mb_strtolower( $c['keyword'] );
            $has_exact = false;
            $has_partial = false;
            foreach ( $existing_keywords as $ek ) {
                if ( mb_strtolower( $ek ) === $kw_lower ) {
                    $has_exact = true;
                    break;
                }
                if ( mb_stripos( $ek, $kw_lower ) !== false || mb_stripos( $kw_lower, $ek ) !== false ) {
                    $has_partial = true;
                }
            }
            if ( $has_exact )         { $s = 0; }
            elseif ( $has_partial )   { $s = 5; }
            else                      { $s = 15; }
            $score += $s;
            $reasons[] = "既存記事チェック: +{$s}";

            // (6) 最近未生成: 0-15
            $recently_queued = Gcrev_Auto_Article_Queue::keyword_already_queued( $user_id, $c['keyword'] );
            $s = $recently_queued ? 0 : 15;
            $score += $s;
            $reasons[] = "未生成: +{$s}";

            if ( $score < $min_score ) continue;

            $scored[] = [
                'keyword'        => $c['keyword'],
                'group'          => $c['group'],
                'score'          => (float) $score,
                'reasons'        => $reasons,
                'volume'         => $c['volume'],
                'difficulty'     => $c['difficulty'],
                'suggested_type' => $c['suggested_type'],
                'intent'         => $c['intent'],
                'analysis'       => $c['analysis'],
            ];
        }

        // スコア降順ソート
        usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

        return $scored;
    }

    /**
     * ユーザーの既存記事キーワードを取得。
     */
    private function get_existing_article_keywords( int $user_id ): array {
        $query = new \WP_Query( [
            'post_type'      => 'gcrev_article',
            'posts_per_page' => 200,
            'meta_key'       => '_gcrev_article_user_id',
            'meta_value'     => $user_id,
            'meta_type'      => 'NUMERIC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        $keywords = [];
        foreach ( $query->posts as $post_id ) {
            $kw = get_post_meta( $post_id, '_gcrev_article_keyword', true );
            if ( $kw ) {
                $keywords[] = $kw;
            }
        }
        return $keywords;
    }

    // =========================================================
    // 2. 日次キュー構築
    // =========================================================

    /**
     * 日次キュー構築（Cron daily event から呼ばれる）。
     */
    public function build_daily_queue(): void {
        $this->log( '[DAILY] build_daily_queue started' );

        // ロック取得
        $existing = get_transient( self::LOCK_KEY );
        if ( $existing && ( time() - (int) $existing ) < self::LOCK_TTL ) {
            $this->log( '[DAILY] LOCKED — skipping duplicate run' );
            return;
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        // Cron ログ開始
        $log_id = 0;
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = Gcrev_Cron_Logger::start( 'auto_article_generate', [
                'started_at' => current_time( 'mysql', false ),
            ] );
        }
        set_transient( self::LOG_ID_TRANSIENT, $log_id, self::LOCK_TTL );

        // 対象ユーザー取得（管理者除外）
        $users = get_users( [ 'fields' => [ 'ID' ] ] );
        $total_queued = 0;

        foreach ( $users as $u ) {
            $user_id = (int) $u->ID;

            if ( user_can( $user_id, 'manage_options' ) ) {
                continue;
            }

            // 自動記事生成が有効か
            if ( get_user_meta( $user_id, 'gcrev_auto_article_enabled', true ) !== '1' ) {
                continue;
            }

            $daily_limit = max( 1, min( 5, (int) ( get_user_meta( $user_id, 'gcrev_auto_article_daily_limit', true ) ?: 1 ) ) );
            $today_count = Gcrev_Auto_Article_Queue::get_today_count( $user_id );

            if ( $today_count >= $daily_limit ) {
                $this->log( "[DAILY] user={$user_id} already at daily limit ({$today_count}/{$daily_limit})" );
                if ( $log_id ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', "daily limit reached ({$today_count}/{$daily_limit})" );
                }
                continue;
            }

            // スコアリング
            $scored = $this->score_keywords( $user_id );
            if ( empty( $scored ) ) {
                $this->log( "[DAILY] user={$user_id} no scored keywords" );
                if ( $log_id ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'no scored keywords' );
                }
                continue;
            }

            $slots = $daily_limit - $today_count;
            $queued_count = 0;

            foreach ( $scored as $kw ) {
                if ( $queued_count >= $slots ) break;

                // 重複キュー防止
                if ( Gcrev_Auto_Article_Queue::keyword_already_queued( $user_id, $kw['keyword'] ) ) {
                    continue;
                }

                $result = Gcrev_Auto_Article_Queue::enqueue(
                    $log_id,
                    $user_id,
                    $kw['keyword'],
                    $kw['group'],
                    $kw['score']
                );

                if ( $result ) {
                    $queued_count++;
                    $total_queued++;
                }
            }

            $this->log( "[DAILY] user={$user_id} queued={$queued_count}" );
            if ( $log_id ) {
                Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'success', "queued {$queued_count} keywords" );
            }
        }

        $this->log( "[DAILY] total queued={$total_queued}" );

        if ( $total_queued > 0 ) {
            // 最初のチャンクをスケジュール
            wp_schedule_single_event( time() + 10, 'gcrev_auto_article_chunk_event', [ $log_id ] );
            $this->log( "[DAILY] scheduled first chunk for job_id={$log_id}" );
        } else {
            // キューなし → ロック解放
            delete_transient( self::LOCK_KEY );
            if ( $log_id && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
            }
        }
    }

    // =========================================================
    // 3. チャンクプロセッサ
    // =========================================================

    /**
     * 1件ずつ記事を自動生成するチャンクプロセッサ。
     */
    public function process_chunk( int $job_id ): void {
        $this->log( "[CHUNK] process_chunk started: job_id={$job_id}" );

        // ストール復旧
        $recovered = Gcrev_Auto_Article_Queue::recover_stale_items( $job_id );
        if ( $recovered > 0 ) {
            $this->log( "[CHUNK] recovered {$recovered} stale items" );
        }

        // 次の1件を取得
        $items = Gcrev_Auto_Article_Queue::claim_next( $job_id, 1 );
        if ( empty( $items ) ) {
            $this->log( '[CHUNK] no pending items — finishing job' );
            $this->finish_job( $job_id );
            return;
        }

        $item     = $items[0];
        $queue_id = (int) $item->id;
        $user_id  = (int) $item->user_id;
        $keyword  = $item->keyword;

        $this->log( "[CHUNK] processing queue_id={$queue_id} user={$user_id} keyword={$keyword}" );

        // コンテキスト切り替え
        $original_user = get_current_user_id();
        wp_set_current_user( $user_id );

        try {
            $this->process_single_item( $queue_id, $user_id, $keyword, $item );
        } catch ( \Throwable $e ) {
            $this->log( "[CHUNK] FATAL: queue_id={$queue_id} error=" . $e->getMessage() );
            Gcrev_Auto_Article_Queue::mark_failed( $queue_id, mb_substr( $e->getMessage(), 0, 500 ) );
            if ( $job_id && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::log_user( $job_id, $user_id, 'error', $e->getMessage() );
            }
        } finally {
            wp_set_current_user( $original_user );
        }

        // 次のチャンクをスケジュール or 完了
        $counts = Gcrev_Auto_Article_Queue::get_counts_by_status( $job_id );
        if ( ( $counts['pending'] ?? 0 ) > 0 ) {
            wp_schedule_single_event( time() + self::CHUNK_DELAY_SEC, 'gcrev_auto_article_chunk_event', [ $job_id ] );
            $this->log( "[CHUNK] scheduled next chunk, pending={$counts['pending']}" );
        } else {
            $this->log( '[CHUNK] all items processed — finishing job' );
            $this->finish_job( $job_id );
        }
    }

    /**
     * 単一キーワードの記事生成処理。
     */
    private function process_single_item( int $queue_id, int $user_id, string $keyword, object $item ): void {

        // ── ステップ1: 重複チェック ──
        $similarity = $this->writing->check_similarity( $user_id, $keyword );
        Gcrev_Auto_Article_Queue::update_similarity( $queue_id, wp_json_encode( $similarity, JSON_UNESCAPED_UNICODE ) );

        $risk_level = $similarity['risk_level'] ?? 'none';
        $this->log( "[PROCESS] queue_id={$queue_id} risk_level={$risk_level}" );

        // ── ステップ2: 切り口判定 ──
        $angle = '';
        $final_keyword = $keyword;

        if ( $risk_level === 'high' ) {
            $angle = $this->shift_angle( $user_id, $keyword, $similarity );
            if ( $angle === '' ) {
                Gcrev_Auto_Article_Queue::mark_skipped( $queue_id, '重複リスク高: 切り口変更不可' );
                $this->log( "[PROCESS] queue_id={$queue_id} SKIPPED — high risk, no angle" );
                return;
            }
            $this->log( "[PROCESS] queue_id={$queue_id} angle shifted: {$angle}" );
        } elseif ( $risk_level === 'medium' ) {
            $suggested = $similarity['suggested_angles'] ?? [];
            if ( ! empty( $suggested ) ) {
                $angle = $suggested[0];
                $this->log( "[PROCESS] queue_id={$queue_id} using suggested angle: {$angle}" );
            }
        }

        if ( $angle !== '' ) {
            Gcrev_Auto_Article_Queue::update_angle(
                $queue_id,
                wp_json_encode( [ 'angle' => $angle, 'original_risk' => $risk_level ], JSON_UNESCAPED_UNICODE ),
                $keyword
            );
        }

        // ── ステップ3: 記事作成 ──
        $create_data = [ 'keyword' => $keyword ];

        // 文体設定
        $preferred_tone = get_user_meta( $user_id, 'gcrev_auto_article_preferred_tone', true ) ?: '';
        if ( $preferred_tone ) {
            $create_data['tone'] = $preferred_tone;
        }

        $create_result = $this->writing->create_article( $user_id, $create_data );
        if ( empty( $create_result['success'] ) ) {
            Gcrev_Auto_Article_Queue::mark_failed( $queue_id, '記事作成失敗: ' . ( $create_result['error'] ?? 'unknown' ) );
            return;
        }

        $article_id = (int) ( $create_result['article']['id'] ?? 0 );
        if ( ! $article_id ) {
            Gcrev_Auto_Article_Queue::mark_failed( $queue_id, '記事ID取得失敗' );
            return;
        }

        // 自動生成メタ保存
        update_post_meta( $article_id, '_gcrev_article_auto_generated', '1' );
        update_post_meta( $article_id, '_gcrev_article_auto_queue_id', $queue_id );
        update_post_meta( $article_id, '_gcrev_article_auto_keyword_group', $item->keyword_group );
        if ( $angle !== '' ) {
            update_post_meta( $article_id, '_gcrev_article_auto_angle', $angle );
        }

        // ── ステップ4: 競合調査（失敗しても継続） ──
        try {
            $this->writing->generate_competitor_research( $user_id, $article_id, true );
            $this->log( "[PROCESS] queue_id={$queue_id} competitor research done" );
        } catch ( \Throwable $e ) {
            $this->log( "[PROCESS] queue_id={$queue_id} competitor research failed: " . $e->getMessage() );
        }

        // ── ステップ5: 構成案生成 ──
        $outline_result = $this->writing->generate_outline( $user_id, $article_id );
        if ( empty( $outline_result['success'] ) ) {
            Gcrev_Auto_Article_Queue::mark_failed( $queue_id, '構成案生成失敗: ' . ( $outline_result['error'] ?? 'unknown' ) );
            return;
        }
        $this->log( "[PROCESS] queue_id={$queue_id} outline generated" );

        // ── ステップ6: 本文生成 ──
        $draft_prompt = '';
        if ( $angle !== '' ) {
            $draft_prompt = "この記事は以下の切り口・差別化ポイントで書いてください:\n{$angle}";
        }

        $draft_result = $this->writing->generate_draft( $user_id, $article_id, $draft_prompt );
        if ( empty( $draft_result['success'] ) ) {
            Gcrev_Auto_Article_Queue::mark_failed( $queue_id, '本文生成失敗: ' . ( $draft_result['error'] ?? 'unknown' ) );
            return;
        }
        $this->log( "[PROCESS] queue_id={$queue_id} draft generated" );

        // ── ステップ7: 品質チェック ──
        $quality = $this->check_quality( $user_id, $article_id );
        update_post_meta( $article_id, '_gcrev_article_auto_quality_score', (string) $quality['score'] );

        $threshold = (int) ( get_user_meta( $user_id, 'gcrev_auto_article_quality_threshold', true ) ?: 60 );
        if ( ! $quality['passed'] && $quality['score'] < $threshold ) {
            // 品質不足でも記事自体は保持（ヒアリング追加で強化可能）
            update_post_meta( $article_id, '_gcrev_article_needs_hearing_enhancement', '1' );
            $this->log( "[PROCESS] queue_id={$queue_id} quality below threshold: {$quality['score']}/{$threshold}" );
        }

        // ── ステップ8: WP下書き保存 ──
        $wp_result = $this->writing->save_as_wp_draft( $user_id, $article_id );
        $wp_draft_id = (int) ( $wp_result['draft_id'] ?? 0 );
        $this->log( "[PROCESS] queue_id={$queue_id} wp_draft_id={$wp_draft_id}" );

        // ── ステップ9: 自動公開判定 ──
        $final_status = 'draft_created';
        if (
            $wp_draft_id
            && get_user_meta( $user_id, 'gcrev_auto_article_auto_publish', true ) === '1'
            && $quality['score'] >= $threshold
            && $risk_level !== 'high'
        ) {
            wp_update_post( [ 'ID' => $wp_draft_id, 'post_status' => 'publish' ] );
            $final_status = 'published';
            $this->log( "[PROCESS] queue_id={$queue_id} auto-published" );
        }

        // ── 完了 ──
        Gcrev_Auto_Article_Queue::mark_success(
            $queue_id,
            $article_id,
            $wp_draft_id,
            $quality['score'],
            $quality['feedback'] ?? '',
            $final_status
        );

        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = (int) get_transient( self::LOG_ID_TRANSIENT );
            if ( $log_id ) {
                Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'success',
                    "keyword={$keyword}, status={$final_status}, quality={$quality['score']}" );
            }
        }

        $this->log( "[PROCESS] queue_id={$queue_id} completed: status={$final_status}, quality={$quality['score']}" );
    }

    // =========================================================
    // 4. 切り口変更
    // =========================================================

    /**
     * 既存記事と競合する場合に別の切り口を生成する。
     *
     * @return string 切り口テキスト（空文字=切り口変更不可）
     */
    private function shift_angle( int $user_id, string $keyword, array $similarity ): string {
        // check_similarity が suggested_angles を返している場合はそれを使う
        $suggested = $similarity['suggested_angles'] ?? [];
        if ( ! empty( $suggested ) ) {
            return (string) $suggested[0];
        }

        // Gemini で切り口生成
        $existing_text = '';
        foreach ( $similarity['similar_articles'] ?? [] as $idx => $art ) {
            $num = $idx + 1;
            $existing_text .= "記事{$num}: 「{$art['keyword']}」— {$art['title']}\n";
            if ( ! empty( $art['reason'] ) ) {
                $existing_text .= "  類似理由: {$art['reason']}\n";
            }
            $existing_text .= "\n";
        }

        if ( $existing_text === '' ) {
            return '';
        }

        // クライアント情報
        $cs = function_exists( 'gcrev_get_client_settings' ) ? gcrev_get_client_settings( $user_id ) : [];
        $industry = $cs['industry'] ?? '';
        $area     = '';
        if ( function_exists( 'gcrev_get_client_area_label' ) ) {
            $area = gcrev_get_client_area_label( $cs );
        }

        $prompt = "あなたはSEOコンテンツ戦略の専門家です。\n\n"
            . "以下のキーワードで新しい記事を書きたいですが、既存記事と検索意図が被るリスクがあります。\n"
            . "既存記事と明確に差別化される切り口を3つ提案してください。\n\n"
            . "## 新しいキーワード\n{$keyword}\n\n";

        if ( $industry ) {
            $prompt .= "## クライアント業種\n{$industry}\n\n";
        }
        if ( $area ) {
            $prompt .= "## クライアント対象エリア\n{$area}\n\n";
        }

        $prompt .= "## 既存の類似記事\n{$existing_text}\n"
            . "## 条件\n"
            . "- 単に語尾や修飾語を変えるだけではなく、検索意図が本当にずれる切り口にすること\n"
            . "- 既存記事と役割が分かれる（読者が「これは違う記事だ」と認識できる）こと\n"
            . "- 実際に検索ニーズがある切り口であること\n\n"
            . "## 出力形式（JSONのみ出力）\n"
            . "[\n"
            . "  {\"angle\": \"切り口の説明（50文字以内）\", \"reason\": \"差別化の根拠（50文字以内）\", \"target_reader\": \"想定読者\"}\n"
            . "]\n\n"
            . "3つ提案してください。最も自然で価値の高い切り口を最初に配置してください。\n";

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'     => 0.7,
                'maxOutputTokens' => 2048,
            ] );
        } catch ( \Throwable $e ) {
            $this->log( "shift_angle error: " . $e->getMessage() );
            return '';
        }

        // JSON パース
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

        $first = strpos( $cleaned, '[' );
        $last  = strrpos( $cleaned, ']' );
        if ( $first === false || $last === false || $last <= $first ) {
            $this->log( "shift_angle parse error: " . substr( $raw, 0, 300 ) );
            return '';
        }

        $angles = json_decode( substr( $cleaned, $first, $last - $first + 1 ), true );
        if ( ! is_array( $angles ) || empty( $angles ) ) {
            $this->log( "shift_angle invalid JSON" );
            return '';
        }

        return (string) ( $angles[0]['angle'] ?? '' );
    }

    // =========================================================
    // 5. 品質チェック
    // =========================================================

    /**
     * 記事の品質をルールベース + AI でスコアリングする。
     *
     * @return array ['score' => float, 'passed' => bool, 'feedback' => string]
     */
    private function check_quality( int $user_id, int $article_id ): array {
        $draft = get_post_meta( $article_id, '_gcrev_article_draft_content', true ) ?: '';

        // ── ルールベース部分（0-30点） ──
        $rule_score = 0;
        $feedback_parts = [];

        // 文字数チェック
        $char_count = mb_strlen( $draft );
        if ( $char_count >= 2000 ) {
            $rule_score += 10;
        } else {
            $feedback_parts[] = "本文が短い（{$char_count}文字）";
        }

        // 見出しチェック
        $heading_count = preg_match_all( '/^#{2,4}\s/m', $draft );
        if ( $heading_count >= 3 ) {
            $rule_score += 10;
        } else {
            $feedback_parts[] = "見出しが少ない（{$heading_count}個）";
        }

        // 段落チェック
        $paragraphs = preg_split( '/\n\s*\n/', $draft );
        $para_count = count( array_filter( $paragraphs, fn( $p ) => mb_strlen( trim( $p ) ) > 20 ) );
        if ( $para_count >= 5 ) {
            $rule_score += 10;
        } else {
            $feedback_parts[] = "段落が少ない（{$para_count}段落）";
        }

        // ── AI部分（0-70点） ──
        $ai_score = 50; // デフォルト
        $ai_feedback = '';

        if ( $char_count > 200 ) {
            $sample = mb_substr( $draft, 0, 3000 );
            $keyword = get_post_meta( $article_id, '_gcrev_article_keyword', true ) ?: '';

            $prompt = "あなたはSEO記事の品質評価の専門家です。\n\n"
                . "以下のSEO記事（冒頭部分）を0〜70点で採点してください。\n\n"
                . "## 対策キーワード\n{$keyword}\n\n"
                . "## 記事内容（冒頭）\n{$sample}\n\n"
                . "## 採点基準\n"
                . "- 構成の論理性（テーマに沿って読者が自然に読み進められるか）\n"
                . "- 読者への価値（検索意図に対して具体的で有用な情報があるか）\n"
                . "- キーワードの自然な使用（詰め込みすぎず、自然に含まれているか）\n"
                . "- 読みやすさ（冗長でなく、適度な段落分けがあるか）\n"
                . "- 自然さ（AI感がなく、人が書いたように読めるか）\n\n"
                . "## 出力形式（JSONのみ出力）\n"
                . "{\"score\": 数値(0-70), \"feedback\": \"改善点の要約（100文字以内）\"}\n";

            try {
                $raw = $this->ai->call_gemini_api( $prompt, [
                    'temperature'     => 0.3,
                    'maxOutputTokens' => 1024,
                ] );

                $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
                $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
                $cleaned = trim( $cleaned );

                $first = strpos( $cleaned, '{' );
                $last  = strrpos( $cleaned, '}' );
                if ( $first !== false && $last !== false && $last > $first ) {
                    $result = json_decode( substr( $cleaned, $first, $last - $first + 1 ), true );
                    if ( is_array( $result ) && isset( $result['score'] ) ) {
                        $ai_score    = max( 0, min( 70, (int) $result['score'] ) );
                        $ai_feedback = $result['feedback'] ?? '';
                    }
                }
            } catch ( \Throwable $e ) {
                $this->log( "check_quality AI error: " . $e->getMessage() );
                // AI失敗時はデフォルトスコアを使用
            }
        }

        $total = $rule_score + $ai_score;
        $threshold = (int) ( get_user_meta( $user_id, 'gcrev_auto_article_quality_threshold', true ) ?: 60 );

        $all_feedback = array_filter( array_merge( $feedback_parts, [ $ai_feedback ] ) );

        return [
            'score'    => (float) $total,
            'passed'   => $total >= $threshold,
            'feedback' => implode( '。', $all_feedback ),
        ];
    }

    // =========================================================
    // 6. ジョブ完了
    // =========================================================

    private function finish_job( int $job_id ): void {
        delete_transient( self::LOCK_KEY );

        if ( $job_id && class_exists( 'Gcrev_Cron_Logger' ) ) {
            $counts = Gcrev_Auto_Article_Queue::get_counts_by_status( $job_id );
            $has_failures = ( $counts['failed'] ?? 0 ) > 0;
            Gcrev_Cron_Logger::finish( $job_id, $has_failures ? 'partial' : 'success' );
        }

        $this->log( "[JOB] job_id={$job_id} finished" );
    }

    // =========================================================
    // 7. 手動トリガー（REST API 用）
    // =========================================================

    /**
     * 指定キーワードまたは自動選択で即時記事生成する。
     *
     * @return array ['success' => bool, 'queue_id' => int, 'message' => string]
     */
    public function trigger_single( int $user_id, string $keyword = '' ): array {
        // キーワード未指定時は自動選択
        if ( $keyword === '' ) {
            $scored = $this->score_keywords( $user_id );
            if ( empty( $scored ) ) {
                return [ 'success' => false, 'message' => '記事化候補のキーワードが見つかりません。' ];
            }
            $keyword = $scored[0]['keyword'];
            $group   = $scored[0]['group'];
            $score   = $scored[0]['score'];
        } else {
            $group = '';
            $score = 50.0;
        }

        // キュー登録
        $job_id = time();
        $result = Gcrev_Auto_Article_Queue::enqueue( $job_id, $user_id, $keyword, $group, $score );
        if ( ! $result ) {
            return [ 'success' => false, 'message' => 'キュー登録に失敗しました。' ];
        }

        // 即時チャンクスケジュール
        wp_schedule_single_event( time() + 5, 'gcrev_auto_article_chunk_event', [ $job_id ] );

        return [
            'success' => true,
            'keyword' => $keyword,
            'message' => "「{$keyword}」の自動記事生成を開始しました。",
        ];
    }
}
