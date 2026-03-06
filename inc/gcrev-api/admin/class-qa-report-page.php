<?php
// FILE: inc/gcrev-api/admin/class-qa-report-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_QA_Report_Page' ) ) { return; }

/**
 * Gcrev_QA_Report_Page
 *
 * 管理画面「みまもりウェブ > AI品質レポート」ページ。
 * QAバッチ（wp mimamori qa:run）の結果を可視化する。
 *
 * セクション構成:
 *   A. サマリーカード（選択中 run の概要）
 *   B. 実行履歴テーブル（直近30件）
 *   C. ケース一覧（フィルタ付き）
 *   D. ケース詳細モーダル
 *   E. Failures Top 10
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_QA_Report_Page {

    private const MENU_SLUG      = 'gcrev-qa-report';
    private const MAX_RUNS       = 30;
    private const MAX_CASES      = 200;
    private const RUN_ID_PATTERN = '/^\d{8}_\d{6}$/';
    private const OUTPUT_BASE    = 'mimamori/qa_runs';

    /** Triage カテゴリ → severity マッピング */
    private const TRIAGE_SEVERITY = [
        'TOOL_ERROR'     => 'critical',
        'TIMEOUT'        => 'critical',
        'HALLUCINATION'  => 'critical',
        'PARAM_MISS'     => 'major',
        'WRONG_PERIOD'   => 'major',
        'EMPTY_RESPONSE' => 'major',
        'JSON_BROKEN'    => 'major',
        'FOLLOWUP_FAIL'  => 'major',
        'FALSE_NEGATIVE' => 'minor',
        'JARGON'         => 'minor',
        'PARAM_GATE'     => 'info',
        'UNKNOWN'        => 'minor',
    ];

    /** Triage カテゴリ → 改善アドバイス */
    private const FAILURE_ADVICE = [
        'TOOL_ERROR'     => 'GA4/GSC API接続またはパラメータを確認。サービスアカウント権限を再チェック。',
        'TIMEOUT'        => 'APIレスポンス時間を確認。クエリ複雑度を下げるか、タイムアウト値を引き上げ。',
        'HALLUCINATION'  => 'コンテキスト注入量を増やすか、システムプロンプトの「根拠なし断言禁止」を強化。',
        'PARAM_MISS'     => 'ParamResolverのマッピングルールを確認。該当カテゴリの質問パターンを追加。',
        'WRONG_PERIOD'   => '期間解決ロジックを確認。日付ヘルパーのタイムゾーン処理を再チェック。',
        'EMPTY_RESPONSE' => 'AIモデルのトークン上限、コンテキスト長を確認。空応答のフォールバック処理を追加。',
        'JSON_BROKEN'    => 'AI応答のJSON抽出正規表現を確認。構造化パーサーのエラーハンドリングを改善。',
        'FALSE_NEGATIVE' => 'データ存在判定ロジックを確認。digest/flex_textが空でないのに「不明」応答のケースを調査。',
        'JARGON'         => 'システムプロンプトの用語変換ルールを更新。禁止用語リストを拡張。',
        'FOLLOWUP_FAIL'  => 'フォローアップ解決ロジックを確認。mimamori_resolve_followup_context()の番号解析パターンとクエリビルダのキーワードマッチを調査。',
        'PARAM_GATE'     => '情報不足により確認質問を返却。ParamResolver確認質問のしきい値を調整。',
        'UNKNOWN'        => '個別調査が必要。スコア詳細を確認して根本原因を特定。',
    ];

    /** 機密データマスクパターン */
    private const MASK_PATTERNS = [
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/' => '[EMAIL]',
        '/(?:api[_\-]?key|token|secret|password)\s*[:=]\s*\S+/i' => '[CREDENTIAL]',
        '/AIza[0-9A-Za-z\-_]{35}/'                              => '[API_KEY]',
        '/sk-[0-9A-Za-z]{20,}/'                                 => '[API_KEY]',
        '/\d{3}-\d{4}-\d{4}/'                                   => '[PHONE]',
    ];

    // =========================================================
    // 登録
    // =========================================================

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
    }

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'みまもりウェブ',
                'みまもりウェブ',
                'manage_options',
                'gcrev-insight',
                '__return_null',
                'dashicons-chart-area',
                30
            );
        }

        add_submenu_page(
            'gcrev-insight',
            'AI品質・採点レポート - みまもりウェブ',
            "\xF0\x9F\xA7\xAA AI品質レポート",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // ページ描画（メインオーケストレーター）
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $base_dir = $this->get_base_dir();

        if ( ! is_dir( $base_dir ) ) {
            $this->render_inline_styles();
            echo '<div class="wrap">';
            echo '<h1>' . esc_html( "\xF0\x9F\xA7\xAA AI品質・採点レポート" ) . '</h1>';
            echo '<p>QA実行結果がありません。<code>wp mimamori qa:run</code> を実行してください。</p>';
            echo '</div>';
            return;
        }

        $runs = $this->list_runs( self::MAX_RUNS );

        if ( empty( $runs ) ) {
            $this->render_inline_styles();
            echo '<div class="wrap">';
            echo '<h1>' . esc_html( "\xF0\x9F\xA7\xAA AI品質・採点レポート" ) . '</h1>';
            echo '<p>QA実行結果がありません。<code>wp mimamori qa:run</code> を実行してください。</p>';
            echo '</div>';
            return;
        }

        // 選択中の run を決定
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selected = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : '';
        if ( $selected === '' || ! $this->validate_run_id( $selected ) ) {
            $selected = $runs[0]['run_id'];
        }

        $meta  = $this->load_meta( $selected );
        $cases = $this->load_cases( $selected );

        $this->render_inline_styles();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( "\xF0\x9F\xA7\xAA AI品質・採点レポート" ); ?></h1>

            <?php $this->render_latest_summary( $meta ?: [], $cases ); ?>

            <hr />

            <?php $this->render_run_history( $runs, $selected ); ?>

            <hr />

            <?php $this->render_case_list( $selected, $cases ); ?>

            <hr />

            <?php $this->render_failures_section( $selected ); ?>

            <?php $this->render_improve_section( $selected ); ?>

            <?php $this->render_case_detail_modal(); ?>
        </div>
        <?php

        $this->render_inline_scripts( $selected );
    }

    // =========================================================
    // データ読み込み
    // =========================================================

    private function get_base_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . self::OUTPUT_BASE;
    }

    private function get_run_dir( string $run_id ): string {
        return $this->get_base_dir() . '/' . $run_id;
    }

    /**
     * run_id を検証する（正規表現 + ディレクトリ存在 + パス前方一致）。
     */
    private function validate_run_id( string $run_id ): bool {
        if ( ! preg_match( self::RUN_ID_PATTERN, $run_id ) ) {
            return false;
        }

        $dir       = $this->get_run_dir( $run_id );
        $real      = realpath( $dir );
        $base_real = realpath( $this->get_base_dir() );

        return $real !== false && $base_real !== false && strpos( $real, $base_real ) === 0;
    }

    /**
     * 直近 $limit 件の run を返す（meta.json 読み込み付き）。
     *
     * @return array [ ['run_id'=>..., 'avg_score'=>..., ...], ... ]
     */
    private function list_runs( int $limit = 30 ): array {
        $base = $this->get_base_dir();
        $dirs = glob( $base . '/*', GLOB_ONLYDIR );
        if ( empty( $dirs ) ) {
            return [];
        }

        $runs = [];
        foreach ( $dirs as $dir ) {
            $run_id = basename( $dir );
            if ( ! preg_match( self::RUN_ID_PATTERN, $run_id ) ) {
                continue;
            }
            $meta = $this->load_meta( $run_id );
            if ( $meta === null ) {
                // meta.json がない run はスキップ
                continue;
            }
            $runs[] = array_merge( [ 'run_id' => $run_id ], $meta );
        }

        // 新しい順
        usort( $runs, static function ( $a, $b ) {
            return strcmp( $b['run_id'], $a['run_id'] );
        } );

        return array_slice( $runs, 0, $limit );
    }

    /**
     * meta.json を読み込む。
     */
    private function load_meta( string $run_id ): ?array {
        $path = $this->get_run_dir( $run_id ) . '/meta.json';
        if ( ! is_readable( $path ) ) {
            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $path );
        if ( $raw === false ) {
            return null;
        }

        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * cases.jsonl を読み込む（最大 MAX_CASES 行）。
     *
     * @return array ケース配列
     */
    private function load_cases( string $run_id ): array {
        $path = $this->get_run_dir( $run_id ) . '/cases.jsonl';
        if ( ! is_readable( $path ) ) {
            return [];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $fp = fopen( $path, 'r' );
        if ( ! $fp ) {
            return [];
        }

        $cases = [];
        $count = 0;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
        while ( ( $line = fgets( $fp ) ) !== false && $count < self::MAX_CASES ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $case = json_decode( $line, true );
            if ( is_array( $case ) ) {
                $cases[] = $case;
                $count++;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $fp );

        return $cases;
    }

    /**
     * Markdown ファイルを読み込む。
     */
    private function load_markdown( string $run_id, string $filename ): string {
        // ファイル名をホワイトリスト
        $allowed = [ 'summary.md', 'failures_top10.md' ];
        if ( ! in_array( $filename, $allowed, true ) ) {
            return '';
        }

        $path = $this->get_run_dir( $run_id ) . '/' . $filename;
        if ( ! is_readable( $path ) ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $content = file_get_contents( $path );
        return $content !== false ? $content : '';
    }

    // =========================================================
    // A. サマリーカード
    // =========================================================

    /**
     * @param array $meta  meta.json データ
     * @param array $cases cases.jsonl データ
     */
    private function render_latest_summary( array $meta, array $cases ): void {
        $run_id    = $meta['run_id'] ?? '';
        $avg_score = $meta['avg_score'] ?? 0;
        $n         = $meta['n'] ?? count( $cases );
        $success   = $meta['success_count'] ?? 0;
        $fail      = $meta['fail_count'] ?? 0;
        $mode      = $meta['mode'] ?? '';
        $model     = $meta['model'] ?? 'unknown';
        $started   = $meta['started_at'] ?? '';

        // Triage 集計
        $triage_counts = $this->compute_triage_counts( $cases );
        $hallucination = $triage_counts['HALLUCINATION'] ?? 0;
        $tool_error    = ( $triage_counts['TOOL_ERROR'] ?? 0 ) + ( $triage_counts['TIMEOUT'] ?? 0 );
        $low_score     = 0;
        foreach ( $cases as $c ) {
            if ( ( $c['score']['total'] ?? 0 ) < 70 ) {
                $low_score++;
            }
        }

        // 日時フォーマット
        $date_display = $started;
        if ( $run_id !== '' && preg_match( '/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/', $run_id, $m ) ) {
            $date_display = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }

        echo '<div class="gcrev-qa-cards">';

        // Card 1: Run 情報
        echo '<div class="gcrev-qa-card">';
        echo '<h3>実行情報</h3>';
        echo '<div class="value" style="font-size:16px;">' . esc_html( $date_display ) . '</div>';
        echo '<p style="margin:4px 0 0;color:#64748b;font-size:12px;">';
        echo esc_html( "Model: {$model}" );
        if ( $mode !== '' ) {
            echo ' / ' . esc_html( "Mode: {$mode}" );
        }
        echo '<br />N=' . esc_html( (string) $n );
        echo '</p>';
        echo '</div>';

        // Card 2: 平均スコア
        $score_color = $this->get_score_color( (int) round( $avg_score ) );
        echo '<div class="gcrev-qa-card">';
        echo '<h3>平均スコア</h3>';
        echo '<div class="value" style="color:' . esc_attr( $score_color ) . ';">' . esc_html( number_format( $avg_score, 1 ) ) . '</div>';
        echo '<p style="margin:4px 0 0;color:#64748b;font-size:12px;">' . esc_html( "{$n}件中" ) . '</p>';
        echo '</div>';

        // Card 3: 成功 / 失敗
        echo '<div class="gcrev-qa-card">';
        echo '<h3>成功 / 失敗</h3>';
        echo '<div class="value" style="font-size:20px;">';
        echo '<span style="color:#059669;">' . esc_html( (string) $success ) . '</span>';
        echo ' / ';
        echo '<span style="color:' . ( $fail > 0 ? '#dc2626' : '#9ca3af' ) . ';">' . esc_html( (string) $fail ) . '</span>';
        echo '</div>';
        echo '<p style="margin:4px 0 0;color:#64748b;font-size:12px;">低スコア(&lt;70): <strong>' . esc_html( (string) $low_score ) . '件</strong></p>';
        echo '</div>';

        // Card 4: Triage 内訳
        $severity_groups = [ 'critical' => 0, 'major' => 0, 'minor' => 0, 'info' => 0 ];
        foreach ( $triage_counts as $cat => $cnt ) {
            $sev = self::TRIAGE_SEVERITY[ $cat ] ?? 'minor';
            $severity_groups[ $sev ] += $cnt;
        }
        echo '<div class="gcrev-qa-card">';
        echo '<h3>Triage 内訳</h3>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        $sev_labels = [
            'critical' => [ 'CRITICAL', '#dc2626' ],
            'major'    => [ 'MAJOR', '#d97706' ],
            'minor'    => [ 'MINOR', '#6b7280' ],
            'info'     => [ 'INFO', '#3b82f6' ],
        ];
        foreach ( $sev_labels as $sev => $lc ) {
            $cnt = $severity_groups[ $sev ];
            echo '<span style="display:inline-block;padding:4px 8px;font-size:12px;font-weight:600;color:#fff;background:' . esc_attr( $lc[1] ) . ';border-radius:4px;">';
            echo esc_html( $lc[0] . ': ' . $cnt );
            echo '</span>';
        }
        echo '</div>';
        echo '</div>';

        // Card 5: HALLUCINATION（強調）
        $alert_class = $hallucination > 0 ? ' gcrev-qa-card-alert' : '';
        echo '<div class="gcrev-qa-card' . $alert_class . '">';
        echo '<h3>HALLUCINATION</h3>';
        echo '<div class="value" style="color:' . ( $hallucination > 0 ? '#dc2626' : '#059669' ) . ';">' . esc_html( (string) $hallucination ) . '件</div>';
        if ( $tool_error > 0 ) {
            echo '<p style="margin:4px 0 0;color:#64748b;font-size:12px;">TOOL_ERROR/TIMEOUT: ' . esc_html( (string) $tool_error ) . '件</p>';
        }
        echo '</div>';

        echo '</div>'; // .gcrev-qa-cards
    }

    // =========================================================
    // B. 実行履歴テーブル
    // =========================================================

    /**
     * @param array  $runs        run メタ配列
     * @param string $selected_id 選択中の run_id
     */
    private function render_run_history( array $runs, string $selected_id ): void {
        echo '<h2>実行履歴（直近' . count( $runs ) . '件）</h2>';

        echo '<table class="widefat striped" id="qa-run-table">';
        echo '<thead><tr>';
        echo '<th>Run ID</th><th>日時</th><th>User</th><th>N</th><th>平均点</th><th>HALLU</th><th>ERROR率</th><th>Mode</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $page_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );

        foreach ( $runs as $run ) {
            $rid = $run['run_id'];
            $is_selected = ( $rid === $selected_id );
            $row_class   = $is_selected ? ' class="gcrev-qa-selected-row"' : '';

            // 日時
            $date = $rid;
            if ( preg_match( '/^(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})$/', $rid, $m ) ) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}";
            }

            $avg   = isset( $run['avg_score'] ) ? number_format( (float) $run['avg_score'], 1 ) : '-';
            $n     = $run['n'] ?? 0;
            $uid   = $run['user_id'] ?? '-';
            $mode  = $run['mode'] ?? '-';

            // HALLUCINATION / ERROR 率は cases を読まないと正確には出せないが、
            // list_runs() では meta のみ読み込むため、meta 由来で簡易表示
            // 正確な集計は選択中の run のみ行う
            $hallu_display = '-';
            $error_display = '-';

            // 選択中の run は後の render_case_list で詳しく出すので、ここでは '-'
            $link = esc_url( add_query_arg( 'run_id', $rid, $page_url ) );

            $score_color = $avg !== '-' ? $this->get_score_color( (int) round( (float) $avg ) ) : '#9ca3af';

            echo '<tr' . $row_class . '>';
            echo '<td><a href="' . $link . '" style="text-decoration:none;font-weight:600;">' . esc_html( $rid ) . '</a></td>';
            echo '<td>' . esc_html( $date ) . '</td>';
            echo '<td>' . esc_html( (string) $uid ) . '</td>';
            echo '<td>' . esc_html( (string) $n ) . '</td>';
            echo '<td style="color:' . esc_attr( $score_color ) . ';font-weight:600;">' . esc_html( $avg ) . '</td>';
            echo '<td>' . esc_html( $hallu_display ) . '</td>';
            echo '<td>' . esc_html( $error_display ) . '</td>';
            echo '<td>' . esc_html( $mode ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================
    // C. ケース一覧（フィルタ付き）
    // =========================================================

    private function render_case_list( string $run_id, array $cases ): void {
        echo '<h2>ケース一覧 — ' . esc_html( $run_id ) . ' (' . count( $cases ) . '件)</h2>';

        if ( empty( $cases ) ) {
            echo '<p style="color:#999;">ケースデータがありません。</p>';
            return;
        }

        // フィルタバー
        echo '<div class="gcrev-qa-filters">';

        // Triage フィルタ
        echo '<select id="qa-filter-triage" onchange="applyQaFilters()">';
        echo '<option value="">Triage: すべて</option>';
        foreach ( array_keys( self::TRIAGE_SEVERITY ) as $cat ) {
            echo '<option value="' . esc_attr( $cat ) . '">' . esc_html( $cat ) . '</option>';
        }
        echo '</select>';

        // Score レンジ
        echo '<select id="qa-filter-score" onchange="applyQaFilters()">';
        echo '<option value="">Score: すべて</option>';
        echo '<option value="0-29">0-29</option>';
        echo '<option value="30-59">30-59</option>';
        echo '<option value="60-79">60-79</option>';
        echo '<option value="80-100">80-100</option>';
        echo '</select>';

        // Category
        echo '<select id="qa-filter-category" onchange="applyQaFilters()">';
        echo '<option value="">Category: すべて</option>';
        $cats = [ 'kpi', 'trend', 'comparison', 'page', 'general' ];
        foreach ( $cats as $cat ) {
            echo '<option value="' . esc_attr( $cat ) . '">' . esc_html( $cat ) . '</option>';
        }
        echo '</select>';

        // Errors only
        echo '<label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;">';
        echo '<input type="checkbox" id="qa-filter-errors" onchange="applyQaFilters()">';
        echo 'エラーのみ';
        echo '</label>';

        echo '<span id="qa-filter-count" style="color:#64748b;font-size:13px;">' . count( $cases ) . '件表示中</span>';

        echo '</div>';

        // テーブル
        echo '<table class="widefat striped" id="qa-case-table">';
        echo '<thead><tr>';
        echo '<th style="width:60px;">Score</th>';
        echo '<th>Triage</th>';
        echo '<th>質問</th>';
        echo '<th>カテゴリ</th>';
        echo '<th style="width:70px;">時間</th>';
        echo '<th style="width:50px;">成否</th>';
        echo '<th style="width:50px;">詳細</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $cases as $idx => $case ) {
            $score_total = $case['score']['total'] ?? 0;
            $question    = $case['message'] ?? '';
            $category    = $case['category'] ?? '';
            $duration    = $case['duration_ms'] ?? 0;
            $success     = $case['success'] ?? false;

            // Triage カテゴリ一覧
            $triage_cats = [];
            foreach ( ( $case['triage'] ?? [] ) as $t ) {
                $triage_cats[] = $t['category'] ?? '';
            }
            $triage_str = implode( ',', $triage_cats );

            $has_error = in_array( 'TOOL_ERROR', $triage_cats, true ) || in_array( 'TIMEOUT', $triage_cats, true ) || ! $success;

            // モーダル用データ（機密マスク済み）
            $modal_data = [
                'id'       => $case['id'] ?? '',
                'message'  => $question,
                'category' => $category,
                'raw_text' => $this->mask_sensitive( mb_substr( $case['raw_text'] ?? '', 0, 1500 ) ),
                'score'    => $case['score'] ?? [],
                'triage'   => $case['triage'] ?? [],
                'success'  => $success,
                'error'    => $case['error'] ?? null,
                'duration' => $duration,
            ];

            $score_color = $this->get_score_color( $score_total );

            echo '<tr data-triage="' . esc_attr( $triage_str ) . '"';
            echo ' data-score="' . esc_attr( (string) $score_total ) . '"';
            echo ' data-category="' . esc_attr( $category ) . '"';
            echo ' data-has-error="' . ( $has_error ? '1' : '0' ) . '">';

            // Score
            echo '<td style="color:' . esc_attr( $score_color ) . ';font-weight:700;font-size:15px;text-align:center;">' . esc_html( (string) $score_total ) . '</td>';

            // Triage バッジ
            echo '<td>';
            if ( empty( $triage_cats ) || ( count( $triage_cats ) === 1 && $triage_cats[0] === '' ) ) {
                echo '<span style="color:#9ca3af;font-size:12px;">—</span>';
            } else {
                foreach ( $triage_cats as $tc ) {
                    if ( $tc !== '' ) {
                        echo $this->get_triage_badge( $tc ); // Already escaped inside
                    }
                }
            }
            echo '</td>';

            // 質問（マルチターンの場合は 2-turn バッジ表示）
            $is_followup   = ! empty( $case['is_followup'] );
            $followup_badge = $is_followup
                ? '<span style="font-size:10px;padding:1px 4px;background:#dbeafe;color:#1d4ed8;border-radius:3px;margin-right:4px;white-space:nowrap;">2-turn</span>'
                : '';
            echo '<td style="font-size:13px;">' . $followup_badge . esc_html( $this->truncate( $question, 60 ) ) . '</td>';

            // カテゴリ
            echo '<td><span style="font-size:12px;padding:2px 6px;background:#f1f5f9;border-radius:3px;">' . esc_html( $category ) . '</span></td>';

            // 時間
            echo '<td style="font-size:12px;text-align:right;">' . esc_html( $this->format_duration( $duration ) ) . '</td>';

            // 成否
            echo '<td style="text-align:center;">';
            echo $success
                ? '<span style="color:#059669;">&#10003;</span>'
                : '<span style="color:#dc2626;">&#10007;</span>';
            echo '</td>';

            // 詳細ボタン
            echo '<td>';
            echo '<button type="button" class="button button-small" onclick="openQaCaseModal(this)" data-case="' . esc_attr( wp_json_encode( $modal_data, JSON_UNESCAPED_UNICODE ) ) . '">詳細</button>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================
    // D. ケース詳細モーダル
    // =========================================================

    private function render_case_detail_modal(): void {
        ?>
        <div id="qa-case-modal" style="display:none;">
            <div class="qa-modal-overlay" onclick="closeQaCaseModal()"></div>
            <div class="qa-modal-content">
                <button type="button" class="qa-modal-close" onclick="closeQaCaseModal()">&times;</button>
                <div id="qa-modal-body"></div>
            </div>
        </div>
        <?php
    }

    // =========================================================
    // E. Failures Top 10
    // =========================================================

    private function render_failures_section( string $run_id ): void {
        $content = $this->load_markdown( $run_id, 'failures_top10.md' );

        echo '<h2>Failures Top 10</h2>';

        if ( $content === '' ) {
            echo '<p style="color:#999;">failures_top10.md が見つかりません。</p>';
            return;
        }

        echo '<div class="qa-failures-md">';
        echo $this->simple_markdown_to_html( $content ); // Content is escaped inside the converter
        echo '</div>';
    }

    // =========================================================
    // F. 改善ループ結果
    // =========================================================

    private function render_improve_section( string $run_id ): void {
        $improve_dir = $this->get_run_dir( $run_id ) . '/improvements';
        $summary_path = $improve_dir . '/improve_summary.json';

        if ( ! file_exists( $summary_path ) ) {
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $summary_path );
        if ( $raw === false ) return;
        $summary = json_decode( $raw, true );
        if ( ! is_array( $summary ) ) return;

        $pass_score = $summary['config']['pass_score'] ?? 100;
        $pass_mode  = $summary['config']['pass_mode'] ?? 'total_score';

        echo '<h2>自動改善ループ結果</h2>';
        echo '<div class="gcrev-qa-cards">';

        // Card: 合格率
        $below   = $summary['below_pass'] ?? 0;
        $passed  = $summary['passed_count'] ?? 0;
        $rate    = $below > 0 ? round( ( $passed / $below ) * 100, 1 ) : 0;
        $color   = $rate >= 80 ? '#059669' : ( $rate >= 50 ? '#d97706' : '#dc2626' );

        echo '<div class="gcrev-qa-card">';
        echo '<h3>合格率</h3>';
        echo '<div class="value" style="color:' . esc_attr( $color ) . ';">' . esc_html( $rate . '%' ) . '</div>';
        echo '<div style="font-size:12px;color:#64748b;">' . esc_html( "{$passed} / {$below} ケース合格" ) . '</div>';
        echo '</div>';

        // Card: 平均スコア推移
        $avg_init  = $summary['avg_initial_score'] ?? 0;
        $avg_final = $summary['avg_final_score'] ?? 0;
        echo '<div class="gcrev-qa-card">';
        echo '<h3>平均スコア推移</h3>';
        echo '<div class="value">' . esc_html( number_format( $avg_init, 1 ) . ' → ' . number_format( $avg_final, 1 ) ) . '</div>';
        echo '<div style="font-size:12px;color:#64748b;">+' . esc_html( number_format( $avg_final - $avg_init, 1 ) ) . '点改善</div>';
        echo '</div>';

        // Card: 平均試行回数
        $avg_rev = $summary['avg_revisions'] ?? 0;
        echo '<div class="gcrev-qa-card">';
        echo '<h3>平均試行回数</h3>';
        echo '<div class="value">' . esc_html( number_format( $avg_rev, 1 ) ) . '</div>';
        echo '<div style="font-size:12px;color:#64748b;">最大' . esc_html( $summary['config']['max_revisions'] ?? 5 ) . '回</div>';
        echo '</div>';

        // Card: 合格基準
        echo '<div class="gcrev-qa-card">';
        echo '<h3>合格基準</h3>';
        echo '<div class="value" style="font-size:22px;">' . esc_html( $pass_score . '点' ) . '</div>';
        echo '<div style="font-size:12px;color:#64748b;">' . esc_html( $pass_mode ) . '</div>';
        echo '</div>';

        echo '</div>'; // cards

        // 停止理由テーブル
        $reasons = $summary['stop_reasons'] ?? [];
        if ( ! empty( $reasons ) ) {
            echo '<table class="widefat striped" style="max-width:400px;margin:12px 0;">';
            echo '<thead><tr><th>停止理由</th><th>件数</th></tr></thead><tbody>';
            foreach ( $reasons as $reason => $cnt ) {
                echo '<tr><td>' . esc_html( $reason ) . '</td><td>' . esc_html( (string) $cnt ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // 改善レポート MD
        $report_path = $improve_dir . '/improve_report.md';
        if ( file_exists( $report_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $report_md = file_get_contents( $report_path );
            if ( $report_md !== false && $report_md !== '' ) {
                echo '<details style="margin:16px 0;">';
                echo '<summary style="cursor:pointer;font-weight:600;color:#334155;">改善レポート詳細を表示</summary>';
                echo '<div class="qa-failures-md" style="margin-top:8px;">';
                echo $this->simple_markdown_to_html( $report_md ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- simple_markdown_to_html uses esc_html internally
                echo '</div>';
                echo '</details>';
            }
        }

        echo '<hr />';
    }

    /**
     * 改善履歴データを読み込む。
     *
     * @param  string $run_id  実行ID
     * @param  string $case_id ケースID
     * @return array|null
     */
    private function load_improvement( string $run_id, string $case_id ): ?array {
        $path = $this->get_run_dir( $run_id ) . '/improvements/' . sanitize_file_name( $case_id ) . '.json';

        if ( ! file_exists( $path ) ) return null;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $path );
        if ( $raw === false ) return null;

        return json_decode( $raw, true );
    }

    // =========================================================
    // インラインスタイル
    // =========================================================

    private function render_inline_styles(): void {
        ?>
        <style>
        .gcrev-qa-cards { display:flex; gap:16px; flex-wrap:wrap; margin:16px 0; }
        .gcrev-qa-card {
            background:#fff; border:1px solid #e2e8f0; border-radius:8px;
            padding:16px 20px; min-width:180px; flex:1;
        }
        .gcrev-qa-card-alert { background:#fef2f2; border-color:#fca5a5; }
        .gcrev-qa-card h3 {
            margin:0 0 8px; font-size:11px; color:#64748b;
            text-transform:uppercase; letter-spacing:0.5px; font-weight:600;
        }
        .gcrev-qa-card .value { font-size:28px; font-weight:700; line-height:1.2; }

        .gcrev-qa-filters {
            display:flex; gap:12px; align-items:center;
            margin:12px 0; flex-wrap:wrap; padding:10px 12px;
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;
        }
        .gcrev-qa-filters select {
            padding:4px 8px; font-size:13px; border:1px solid #cbd5e1;
            border-radius:4px; background:#fff;
        }

        .gcrev-qa-selected-row { background:#eff6ff !important; }

        #qa-case-table td { vertical-align:middle; }

        /* Modal */
        #qa-case-modal .qa-modal-overlay {
            position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(0,0,0,0.5); z-index:100000;
        }
        #qa-case-modal .qa-modal-content {
            position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
            background:#fff; border-radius:12px; max-width:900px; width:90%;
            max-height:85vh; overflow-y:auto; z-index:100001;
            padding:28px 32px; box-shadow:0 20px 60px rgba(0,0,0,0.3);
        }
        .qa-modal-close {
            position:absolute; top:12px; right:16px; font-size:28px;
            background:none; border:none; cursor:pointer; color:#94a3b8;
            line-height:1;
        }
        .qa-modal-close:hover { color:#1e293b; }

        .qa-modal-section { margin-bottom:20px; }
        .qa-modal-section h3 {
            font-size:14px; font-weight:700; color:#334155;
            margin:0 0 8px; padding-bottom:6px; border-bottom:1px solid #e2e8f0;
        }

        .qa-score-grid {
            display:grid; grid-template-columns:130px 1fr 60px;
            gap:6px 12px; align-items:center; font-size:13px;
        }
        .qa-score-bar-bg {
            background:#e5e7eb; border-radius:4px; height:10px; overflow:hidden;
        }
        .qa-score-bar-fill { height:10px; border-radius:4px; transition:width 0.3s; }

        .qa-answer-box {
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;
            padding:12px 16px; font-size:13px; line-height:1.7;
            white-space:pre-wrap; word-break:break-word; max-height:300px; overflow-y:auto;
        }

        .qa-triage-item {
            display:flex; align-items:flex-start; gap:8px;
            margin-bottom:6px; font-size:13px;
        }

        .qa-advice-box {
            background:#fffbeb; border:1px solid #fde68a; border-radius:6px;
            padding:12px 16px; font-size:13px;
        }
        .qa-advice-box li { margin-bottom:4px; }

        /* Failures MD */
        .qa-failures-md {
            background:#fff; border:1px solid #e2e8f0; border-radius:8px;
            padding:20px 24px; max-width:960px; line-height:1.7;
        }
        .qa-failures-md h2 { font-size:18px; margin:24px 0 8px; color:#1e293b; }
        .qa-failures-md h3 { font-size:15px; margin:20px 0 6px; color:#334155; }
        .qa-failures-md h4 { font-size:13px; margin:16px 0 4px; color:#475569; }
        .qa-failures-md pre {
            background:#1e293b; color:#e2e8f0; padding:12px 16px;
            border-radius:6px; overflow-x:auto; font-size:12px; line-height:1.5;
        }
        .qa-failures-md code {
            background:#f1f5f9; padding:1px 5px; border-radius:3px; font-size:12px;
        }
        .qa-failures-md pre code { background:none; padding:0; }
        .qa-failures-md hr { border:none; border-top:1px solid #e2e8f0; margin:16px 0; }
        .qa-failures-md ul { margin:4px 0 8px 20px; }
        .qa-failures-md li { margin-bottom:2px; font-size:13px; }
        .qa-failures-md table {
            border-collapse:collapse; width:100%; margin:8px 0;
        }
        .qa-failures-md table th,
        .qa-failures-md table td {
            border:1px solid #e2e8f0; padding:6px 10px; font-size:13px; text-align:left;
        }
        .qa-failures-md table th { background:#f8fafc; font-weight:600; }
        </style>
        <?php
    }

    // =========================================================
    // インライン JavaScript
    // =========================================================

    private function render_inline_scripts( string $selected_run = '' ): void {
        // FAILURE_ADVICE を JS に渡す
        $advice_json = wp_json_encode( self::FAILURE_ADVICE, JSON_UNESCAPED_UNICODE );
        $severity_json = wp_json_encode( self::TRIAGE_SEVERITY, JSON_UNESCAPED_UNICODE );

        // 改善データをロード（存在すれば）
        $improve_data = [];
        if ( $selected_run !== '' ) {
            $improve_dir = $this->get_run_dir( $selected_run ) . '/improvements';
            if ( is_dir( $improve_dir ) ) {
                $files = glob( $improve_dir . '/qa_*.json' );
                if ( is_array( $files ) ) {
                    foreach ( $files as $f ) {
                        $case_id = pathinfo( $f, PATHINFO_FILENAME );
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                        $raw = file_get_contents( $f );
                        if ( $raw !== false ) {
                            $data = json_decode( $raw, true );
                            if ( is_array( $data ) ) {
                                $improve_data[ $case_id ] = $data;
                            }
                        }
                    }
                }
            }
        }
        $improve_json = wp_json_encode( $improve_data, JSON_UNESCAPED_UNICODE );
        ?>
        <script>
        (function() {
            var FAILURE_ADVICE  = <?php echo $advice_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
            var TRIAGE_SEVERITY = <?php echo $severity_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
            var IMPROVE_DATA    = <?php echo $improve_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

            var SEVERITY_COLORS = {
                critical: '#dc2626',
                major:    '#d97706',
                minor:    '#6b7280',
                info:     '#3b82f6'
            };

            // --- フィルタ ---
            window.applyQaFilters = function() {
                var triage     = document.getElementById('qa-filter-triage').value;
                var scoreRange = document.getElementById('qa-filter-score').value;
                var category   = document.getElementById('qa-filter-category').value;
                var errorsOnly = document.getElementById('qa-filter-errors').checked;

                var rows  = document.querySelectorAll('#qa-case-table tbody tr');
                var shown = 0;

                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var rowTriage   = row.getAttribute('data-triage') || '';
                    var rowScore    = parseInt(row.getAttribute('data-score') || '0', 10);
                    var rowCategory = row.getAttribute('data-category') || '';
                    var rowError    = row.getAttribute('data-has-error') === '1';

                    var visible = true;

                    if (triage !== '' && rowTriage.indexOf(triage) === -1) visible = false;

                    if (scoreRange !== '') {
                        var parts = scoreRange.split('-');
                        var lo = parseInt(parts[0], 10);
                        var hi = parseInt(parts[1], 10);
                        if (rowScore < lo || rowScore > hi) visible = false;
                    }

                    if (category !== '' && rowCategory !== category) visible = false;
                    if (errorsOnly && !rowError) visible = false;

                    row.style.display = visible ? '' : 'none';
                    if (visible) shown++;
                }

                document.getElementById('qa-filter-count').textContent = shown + '件表示中';
            };

            // --- モーダル ---
            window.openQaCaseModal = function(btn) {
                var caseData = JSON.parse(btn.getAttribute('data-case'));
                var body = document.getElementById('qa-modal-body');
                body.innerHTML = buildCaseDetailHTML(caseData);
                document.getElementById('qa-case-modal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            };

            window.closeQaCaseModal = function() {
                document.getElementById('qa-case-modal').style.display = 'none';
                document.body.style.overflow = '';
            };

            // ESC キーで閉じる
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeQaCaseModal();
                }
            });

            function buildCaseDetailHTML(c) {
                var html = '';

                // ID & Category
                html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">';
                html += '<span style="font-weight:700;font-size:16px;">' + escHtml(c.id || '') + '</span>';
                html += '<span style="padding:2px 8px;background:#f1f5f9;border-radius:4px;font-size:12px;">' + escHtml(c.category || '') + '</span>';
                if (!c.success) {
                    html += '<span style="padding:2px 8px;background:#fef2f2;color:#dc2626;border-radius:4px;font-size:12px;font-weight:600;">FAILED</span>';
                }
                html += '<span style="margin-left:auto;color:#64748b;font-size:12px;">' + formatDuration(c.duration || 0) + '</span>';
                html += '</div>';

                // 質問
                html += '<div class="qa-modal-section">';
                html += '<h3>質問</h3>';
                html += '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;font-size:14px;">' + escHtml(c.message || '') + '</div>';
                html += '</div>';

                // 回答
                html += '<div class="qa-modal-section">';
                html += '<h3>回答（マスク済み）</h3>';
                var rawText = c.raw_text || '';
                var truncated = rawText.length > 500;
                html += '<div class="qa-answer-box" id="qa-answer-content">' + escHtml(truncated ? rawText.substring(0, 500) + '...' : rawText) + '</div>';
                if (truncated) {
                    html += '<button type="button" class="button button-small" style="margin-top:6px;" onclick="toggleQaAnswer()" id="qa-answer-toggle" data-full="' + escAttr(rawText) + '">全文表示</button>';
                }
                html += '</div>';

                // エラー
                if (c.error) {
                    html += '<div class="qa-modal-section">';
                    html += '<h3>エラー</h3>';
                    html += '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;color:#dc2626;font-size:13px;">' + escHtml(c.error) + '</div>';
                    html += '</div>';
                }

                // スコア内訳
                html += '<div class="qa-modal-section">';
                html += '<h3>スコア内訳</h3>';
                var score = c.score || {};
                html += '<div class="qa-score-grid">';
                html += scoreRow('Total', score.total || 0, 100);
                html += scoreRow('Data Integrity', score.data_integrity || 0, 40);
                html += scoreRow('Period Accuracy', score.period_accuracy || 0, 20);
                html += scoreRow('Honesty', score.honesty || 0, 20);
                html += scoreRow('Structure', score.structure || 0, 20);
                html += '</div>';
                html += '</div>';

                // Triage
                var triage = c.triage || [];
                if (triage.length > 0) {
                    html += '<div class="qa-modal-section">';
                    html += '<h3>分類・原因</h3>';
                    for (var i = 0; i < triage.length; i++) {
                        var t = triage[i];
                        var sev = TRIAGE_SEVERITY[t.category] || 'minor';
                        var col = SEVERITY_COLORS[sev] || '#9ca3af';
                        html += '<div class="qa-triage-item">';
                        html += '<span style="display:inline-block;padding:2px 6px;font-size:11px;font-weight:600;color:#fff;background:' + col + ';border-radius:3px;white-space:nowrap;">' + escHtml(t.category || '') + '</span>';
                        html += '<span style="color:#475569;">' + escHtml(t.reason || '') + '</span>';
                        html += '</div>';
                    }
                    html += '</div>';
                }

                // 改善アドバイス
                if (triage.length > 0) {
                    html += '<div class="qa-modal-section">';
                    html += '<h3>改善アドバイス</h3>';
                    html += '<div class="qa-advice-box"><ul style="margin:0;padding-left:20px;">';
                    var seen = {};
                    for (var j = 0; j < triage.length; j++) {
                        var tc = triage[j].category || '';
                        if (tc && !seen[tc] && FAILURE_ADVICE[tc]) {
                            html += '<li><strong>' + escHtml(tc) + ':</strong> ' + escHtml(FAILURE_ADVICE[tc]) + '</li>';
                            seen[tc] = true;
                        }
                    }
                    html += '</ul></div>';
                    html += '</div>';
                }

                // 改善履歴
                var caseId = c.id || '';
                var improveInfo = IMPROVE_DATA[caseId];
                if (improveInfo && improveInfo.revisions && improveInfo.revisions.length > 0) {
                    html += '<div class="qa-modal-section">';
                    html += '<h3>改善ループ履歴</h3>';

                    // ステータスバッジ
                    var stopReason = improveInfo.stop_reason || '';
                    var isPassed = (stopReason === 'passed' || stopReason === 'passed_no_critical' || stopReason === 'already_passed');
                    var badgeColor = isPassed ? '#059669' : '#d97706';
                    var badgeText = isPassed ? 'PASSED' : stopReason;
                    html += '<div style="margin-bottom:10px;">';
                    html += '<span style="display:inline-block;padding:3px 10px;font-size:12px;font-weight:600;color:#fff;background:' + badgeColor + ';border-radius:4px;">' + escHtml(badgeText) + '</span>';
                    html += '<span style="margin-left:8px;font-size:13px;color:#64748b;">' + escHtml(improveInfo.initial_score + ' → ' + improveInfo.final_score) + ' (' + improveInfo.revisions.length + ' revisions)</span>';
                    html += '</div>';

                    // リビジョンテーブル
                    html += '<table style="border-collapse:collapse;width:100%;font-size:12px;margin:8px 0;">';
                    html += '<thead><tr style="background:#f8fafc;">';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">Rev</th>';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">Total</th>';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">Data</th>';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">Period</th>';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">Honesty</th>';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">Structure</th>';
                    html += '<th style="border:1px solid #e2e8f0;padding:5px 8px;">変更</th>';
                    html += '</tr></thead><tbody>';

                    for (var ri = 0; ri < improveInfo.revisions.length; ri++) {
                        var rev = improveInfo.revisions[ri];
                        var totalColor = getScoreColor(rev.score_total || 0);
                        var changes = (rev.changes || []).join(', ') || '—';
                        if (changes.length > 50) changes = changes.substring(0, 47) + '...';
                        html += '<tr>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;text-align:center;">' + rev.revision_no + '</td>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;text-align:center;font-weight:600;color:' + totalColor + ';">' + (rev.score_total || 0) + '</td>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;text-align:center;">' + (rev.data_integrity || 0) + '/40</td>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;text-align:center;">' + (rev.period_accuracy || 0) + '/20</td>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;text-align:center;">' + (rev.honesty || 0) + '/20</td>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;text-align:center;">' + (rev.structure || 0) + '/20</td>';
                        html += '<td style="border:1px solid #e2e8f0;padding:4px 8px;font-size:11px;">' + escHtml(changes) + '</td>';
                        html += '</tr>';
                    }

                    html += '</tbody></table>';
                    html += '</div>';
                }

                return html;
            }

            function scoreRow(label, value, max) {
                var pct = max > 0 ? Math.round((value / max) * 100) : 0;
                var color = getScoreColor(pct);
                return '<div style="font-weight:500;">' + escHtml(label) + '</div>' +
                    '<div class="qa-score-bar-bg"><div class="qa-score-bar-fill" style="width:' + pct + '%;background:' + color + ';"></div></div>' +
                    '<div style="text-align:right;font-weight:600;color:' + color + ';">' + value + '/' + max + '</div>';
            }

            function getScoreColor(score) {
                if (score >= 80) return '#059669';
                if (score >= 60) return '#d97706';
                return '#dc2626';
            }

            function formatDuration(ms) {
                if (ms < 1000) return ms + 'ms';
                return (ms / 1000).toFixed(1) + 's';
            }

            // --- 回答展開トグル ---
            window.toggleQaAnswer = function() {
                var content = document.getElementById('qa-answer-content');
                var btn = document.getElementById('qa-answer-toggle');
                var full = btn.getAttribute('data-full');
                var isTruncated = btn.textContent === '全文表示';
                if (isTruncated) {
                    content.textContent = full;
                    btn.textContent = '折りたたむ';
                } else {
                    content.textContent = full.substring(0, 500) + '...';
                    btn.textContent = '全文表示';
                }
            };

            // --- HTML エスケープ ---
            function escHtml(str) {
                if (typeof str !== 'string') str = String(str);
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

            function escAttr(str) {
                return escHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
        })();
        </script>
        <?php
    }

    // =========================================================
    // ユーティリティ
    // =========================================================

    /**
     * 機密データをマスクする。
     */
    private function mask_sensitive( string $text ): string {
        foreach ( self::MASK_PATTERNS as $pattern => $replacement ) {
            $text = preg_replace( $pattern, $replacement, $text ) ?? $text;
        }
        return $text;
    }

    /**
     * 簡易 Markdown → HTML 変換。
     *
     * failures_top10.md / summary.md のような機械生成 MD 向け。
     * まず全体を esc_html() してから構造変換する（XSS 安全）。
     */
    private function simple_markdown_to_html( string $md ): string {
        // エスケープ（XSS 防止）
        $md = esc_html( $md );

        // コードブロック (```...```)
        $md = preg_replace_callback( '/```\n(.*?)\n```/s', static function ( $m ) {
            return '<pre><code>' . $m[1] . '</code></pre>';
        }, $md );

        // テーブル変換
        $md = preg_replace_callback( '/^(\|.+\|)\n(\|[\-\|: ]+\|)\n((?:\|.+\|\n?)+)/m', static function ( $m ) {
            $header_row = $m[1];
            $body_rows  = trim( $m[3] );

            // ヘッダー
            $cols = array_map( 'trim', explode( '|', trim( $header_row, '|' ) ) );
            $html = '<table><thead><tr>';
            foreach ( $cols as $col ) {
                $html .= '<th>' . $col . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            // ボディ
            foreach ( explode( "\n", $body_rows ) as $row ) {
                $row = trim( $row );
                if ( $row === '' ) {
                    continue;
                }
                $cells = array_map( 'trim', explode( '|', trim( $row, '|' ) ) );
                $html .= '<tr>';
                foreach ( $cells as $cell ) {
                    $html .= '<td>' . $cell . '</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            return $html;
        }, $md );

        // 見出し
        $md = preg_replace( '/^#### (.+)$/m', '<h4>$1</h4>', $md );
        $md = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $md );
        $md = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $md );
        $md = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $md );

        // 水平線
        $md = preg_replace( '/^---+$/m', '<hr />', $md );

        // 太字
        $md = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md );

        // インラインコード
        $md = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $md );

        // リスト
        $md = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $md );
        $md = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $md );
        // 重複 <ul> の修正
        $md = str_replace( "</ul>\n<ul>", "\n", $md );

        // 段落（空行区切り）
        $md = preg_replace( '/\n{2,}/', "\n\n", $md );

        return $md;
    }

    /**
     * Triage バッジ HTML を返す。
     */
    private function get_triage_badge( string $category ): string {
        $severity = self::TRIAGE_SEVERITY[ $category ] ?? 'minor';
        $color    = $this->get_severity_color( $severity );
        return sprintf(
            '<span style="display:inline-block;padding:2px 6px;font-size:11px;font-weight:600;color:#fff;background:%s;border-radius:3px;margin:1px;">%s</span>',
            esc_attr( $color ),
            esc_html( $category )
        );
    }

    /**
     * severity → 色。
     */
    private function get_severity_color( string $severity ): string {
        $map = [
            'critical' => '#dc2626',
            'major'    => '#d97706',
            'minor'    => '#6b7280',
            'info'     => '#3b82f6',
        ];
        return $map[ $severity ] ?? '#9ca3af';
    }

    /**
     * スコア → 色（80+→緑, 60+→黄, <60→赤）。
     */
    private function get_score_color( int $score ): string {
        if ( $score >= 80 ) {
            return '#059669';
        }
        if ( $score >= 60 ) {
            return '#d97706';
        }
        return '#dc2626';
    }

    /**
     * ミリ秒 → 表示用文字列。
     */
    private function format_duration( int $ms ): string {
        if ( $ms < 1000 ) {
            return $ms . 'ms';
        }
        return number_format( $ms / 1000, 1 ) . 's';
    }

    /**
     * 文字列を短縮する。
     */
    private function truncate( string $text, int $length = 60 ): string {
        return mb_strimwidth( $text, 0, $length, '...' );
    }

    /**
     * ケース配列から triage カテゴリ別の件数を計算する。
     *
     * @return array [ 'HALLUCINATION' => 3, 'TOOL_ERROR' => 1, ... ]
     */
    private function compute_triage_counts( array $cases ): array {
        $counts = [];
        foreach ( $cases as $case ) {
            foreach ( ( $case['triage'] ?? [] ) as $t ) {
                $cat = $t['category'] ?? '';
                if ( $cat !== '' ) {
                    $counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;
                }
            }
        }
        return $counts;
    }
}
