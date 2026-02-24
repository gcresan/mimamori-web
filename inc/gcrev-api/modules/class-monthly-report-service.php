<?php
/**
 * Gcrev_Monthly_Report_Service
 *
 * 月次AIレポートの取得 → セクション抽出 → ハイライト生成までの
 * ユースケースをまとめるサービス層。
 *
 * Step4.5 で class-gcrev-api.php の get_monthly_ai_report / get_monthly_highlights を移動。
 *
 * 依存: Gcrev_Highlights（NLP抽出）
 * 注: CPT (gcrev_report) への読み取りのみ。書き込みは Gcrev_Report_Repository が担当。
 *
 * @package GCREV_INSIGHT
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Monthly_Report_Service') ) { return; }

class Gcrev_Monthly_Report_Service {

    private Gcrev_Highlights $highlights;

    public function __construct( Gcrev_Highlights $highlights ) {
        $this->highlights = $highlights;
    }

    /**
     * 月次レポート取得（v2ダッシュボード用）
     * HTMLからテキスト抽出版
     *
     * @param int      $year    年
     * @param int      $month   月
     * @param int|null $user_id ユーザーID
     * @return array|null レポートデータ
     */
    public function get_monthly_ai_report(int $year, int $month, ?int $user_id = null): ?array {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $year_month = sprintf('%04d-%02d', $year, $month);

        // 既存のCPT取得処理を利用
        $args = [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
                [
                    'key'   => '_gcrev_year_month',
                    'value' => $year_month,
                ],
                [
                    'key'   => '_gcrev_is_current',
                    'value' => 1,
                ],
            ],
        ];

        $posts = get_posts($args);
        if (empty($posts)) {
            error_log("[GCREV] get_monthly_ai_report: No report found for user_id={$user_id}, year={$year}, month={$month}");
            return null;
        }

        $post = $posts[0];
        $html = get_post_meta($post->ID, '_gcrev_report_html', true);

        if (empty($html)) {
            return null;
        }

        // HTMLからセクション抽出（静的ユーティリティを直接使用）
        $report = [
            'id' => $post->ID,
            'year' => $year,
            'month' => $month,
            'version' => intval(get_post_meta($post->ID, '_gcrev_report_version', true)),
            'summary' => Gcrev_Html_Extractor::extract_text($html, 'summary'),
            'good_points' => Gcrev_Html_Extractor::extract_list($html, 'good-points'),
            'improvement_points' => Gcrev_Html_Extractor::extract_list($html, 'improvement-points'),
            'consideration' => Gcrev_Html_Extractor::extract_text($html, 'insight-box'),
            'next_actions' => Gcrev_Html_Extractor::extract_list($html, 'actions'),
            'target_area_evaluation' => Gcrev_Html_Extractor::extract_text($html, 'area-box'),
            'created_at' => get_post_meta($post->ID, '_gcrev_created_at', true),
            'state' => get_post_meta($post->ID, '_gcrev_report_state', true),
            // highlights: 保存済みがあればそのまま返す。なければHTMLから動的生成（旧データ互換）
            'highlights' => $this->highlights->get_stored_or_extract_highlights($post->ID, $html),
        ];

        return $report;
    }

    /**
     * ダッシュボード用：月次ハイライト3項目を取得
     * テンプレート（page-dashboard.php）から呼ばれる公開メソッド
     *
     * @param int      $year    対象年
     * @param int      $month   対象月
     * @param int|null $user_id ユーザーID
     * @return array ['most_important'=>string, 'top_issue'=>string, 'opportunity'=>string]
     */
    public function get_monthly_highlights(int $year, int $month, ?int $user_id = null): array {

        $defaults = [
            'most_important' => '新規ユーザー獲得',
            'top_issue'      => 'コンバージョン改善',
            'opportunity'    => '地域施策見直し',
        ];

        // 保存済みAIレポートを取得（新たにAI生成はしない）
        $report = $this->get_monthly_ai_report($year, $month, $user_id);
        if (!$report) {
            return $defaults;
        }

        // highlights が保存済みならそのまま返す
        if (!empty($report['highlights']['most_important'])) {
            return $report['highlights'];
        }

        return $defaults;
    }

    /**
     * ハイライト3項目のディープダイブ詳細テキスト生成（パススルー）
     *
     * @param array      $highlights      ハイライト3項目
     * @param array|null $infographic     インフォグラフィックデータ
     * @param array|null $monthly_report  月次レポートデータ
     * @return array
     */
    public function build_highlight_details(
        array $highlights,
        ?array $infographic,
        ?array $monthly_report
    ): array {
        return $this->highlights->build_highlight_details($highlights, $infographic, $monthly_report);
    }
}
