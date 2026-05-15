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
 * @package Mimamori_Web
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

        // Claude 等で構造化 JSON が保存されている場合は、それを優先して表示用フィールドにマップする
        $ai_json_raw = get_post_meta($post->ID, '_gcrev_report_ai_json', true);
        $ai_json     = $ai_json_raw ? json_decode($ai_json_raw, true) : null;
        $ai_provider = (string) get_post_meta($post->ID, '_gcrev_report_ai_provider', true);
        $ai_model    = (string) get_post_meta($post->ID, '_gcrev_report_ai_model', true);

        if (is_array($ai_json) && !empty($ai_json)) {
            $mapped = $this->map_ai_json_to_legacy_fields($ai_json);
        } else {
            // 既存パイプライン: HTML からセクション抽出
            $mapped = [
                'summary'                => Gcrev_Html_Extractor::extract_text($html, 'summary'),
                'good_points'            => Gcrev_Html_Extractor::extract_list($html, 'good-points'),
                'improvement_points'     => Gcrev_Html_Extractor::extract_list($html, 'improvement-points'),
                'consideration'          => Gcrev_Html_Extractor::extract_text($html, 'insight-box'),
                'next_actions'           => Gcrev_Html_Extractor::extract_list($html, 'actions'),
                'target_area_evaluation' => Gcrev_Html_Extractor::extract_text($html, 'area-box'),
            ];
        }

        $report = array_merge([
            'id'      => $post->ID,
            'year'    => $year,
            'month'   => $month,
            'version' => intval(get_post_meta($post->ID, '_gcrev_report_version', true)),
        ], $mapped, [
            'created_at'  => get_post_meta($post->ID, '_gcrev_created_at', true),
            'state'       => get_post_meta($post->ID, '_gcrev_report_state', true),
            // highlights: 保存済みがあればそのまま返す。なければHTMLから動的生成（旧データ互換）
            'highlights'  => $this->highlights->get_stored_or_extract_highlights($post->ID, $html),
            // AI出力の生 JSON（テンプレ側で詳細表示する場合に利用）
            'ai_json'     => is_array($ai_json) ? $ai_json : null,
            'ai_provider' => $ai_provider,
            'ai_model'    => $ai_model,
        ]);

        return $report;
    }

    /**
     * Claude 用の構造化 JSON を、テンプレートが期待するレガシーフィールドにマップする。
     *
     * @param array $json mimamori_generate_monthly_report_with_claude() の出力JSON
     * @return array {
     *   summary, good_points, improvement_points, consideration, next_actions, target_area_evaluation
     * }
     */
    private function map_ai_json_to_legacy_fields(array $json): array {

        $summary_obj  = $json['overall_summary'] ?? [];
        $summary_text = trim((string) ($summary_obj['title'] ?? ''));
        if (!empty($summary_obj['body'])) {
            $summary_text = $summary_text !== ''
                ? $summary_text . "\n\n" . (string) $summary_obj['body']
                : (string) $summary_obj['body'];
        }

        // good_points / warning_points は配列形式（easy mode で title/body/action を参照）
        $good_points = [];
        foreach (($json['good_points'] ?? []) as $gp) {
            $good_points[] = [
                'title'  => (string) ($gp['title']    ?? ''),
                'body'   => (string) ($gp['body']     ?? ''),
                'action' => (string) ($gp['evidence'] ?? ''),
            ];
        }

        $improvement_points = [];
        foreach (($json['warning_points'] ?? []) as $wp) {
            $improvement_points[] = [
                'title'  => (string) ($wp['title']    ?? ''),
                'body'   => (string) ($wp['body']     ?? ''),
                'action' => trim((string) ($wp['risk'] ?? '') . (!empty($wp['evidence']) ? "\n根拠: " . (string) $wp['evidence'] : '')),
            ];
        }

        // 考察: key_findings + cross_insights を結合
        $insight_lines = [];
        foreach (($json['key_findings'] ?? []) as $kf) {
            $t = (string) ($kf['title'] ?? '');
            $b = (string) ($kf['body']  ?? $kf['summary'] ?? '');
            if ($t !== '' || $b !== '') {
                $insight_lines[] = trim(($t !== '' ? "■ {$t}\n" : '') . $b);
            }
        }
        foreach (($json['cross_insights'] ?? []) as $ci) {
            $t = (string) ($ci['title'] ?? '');
            $b = (string) ($ci['body']  ?? '');
            if ($t !== '' || $b !== '') {
                $insight_lines[] = trim(($t !== '' ? "■ {$t}\n" : '') . $b);
            }
        }

        // next_actions: テンプレートは文字列 or ['description'=>...] を受け付ける
        $next_actions = [];
        foreach (($json['next_actions'] ?? []) as $act) {
            $title  = (string) ($act['title']            ?? '');
            $action = (string) ($act['action']           ?? '');
            $target = (string) ($act['target']           ?? '');
            $reason = (string) ($act['reason']           ?? '');
            $effect = (string) ($act['expected_effect']  ?? '');
            $timing = (string) ($act['estimated_timing'] ?? '');
            $prio   = (string) ($act['priority']         ?? '');

            $desc_parts = [];
            if ($action !== '') { $desc_parts[] = $action; }
            if ($target !== '') { $desc_parts[] = "対象: {$target}"; }
            if ($reason !== '') { $desc_parts[] = "理由: {$reason}"; }
            if ($effect !== '') { $desc_parts[] = "期待効果: {$effect}"; }
            if ($timing !== '') { $desc_parts[] = "時期: {$timing}"; }

            $next_actions[] = [
                'title'       => ($prio !== '' ? "[{$prio}] " : '') . $title,
                'description' => implode("\n", $desc_parts),
            ];
        }

        // data_notes は target_area_evaluation 枠で表示
        $area_lines = [];
        foreach (($json['data_notes'] ?? []) as $dn) {
            $t = (string) ($dn['title'] ?? '');
            $b = (string) ($dn['body']  ?? '');
            if ($t !== '' || $b !== '') {
                $area_lines[] = trim(($t !== '' ? "■ {$t}\n" : '') . $b);
            }
        }

        return [
            'summary'                => $summary_text,
            'good_points'            => $good_points,
            'improvement_points'     => $improvement_points,
            'consideration'          => implode("\n\n", $insight_lines),
            'next_actions'           => $next_actions,
            'target_area_evaluation' => implode("\n\n", $area_lines),
        ];
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
            'top_issue'      => 'ゴール改善',
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
