<?php
/**
 * Gcrev_Report_Repository
 *
 * レポート CPT (gcrev_report) の CRUD 操作を担当するモジュール。
 * Step3-A で class-gcrev-api.php から分離。
 *
 * @package GCREV_INSIGHT
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Report_Repository') ) { return; }

class Gcrev_Report_Repository {

    private Gcrev_Config $config;

    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    /**
     * レポートを履歴として保存
     *
     * @param int         $user_id     ユーザーID
     * @param string      $html        レポートHTML
     * @param array       $client_info クライアント情報
     * @param string      $source      'auto' or 'manual'
     * @param string|null $year_month  保存対象年月（nullの場合は現在の年月）
     * @param array       $highlights  ハイライトデータ（外部で生成済み）
     */
    public function save_report_to_history(int $user_id, string $html, array $client_info, string $source = 'auto', ?string $year_month = null, array $highlights = [], string $beginner_markdown = ''): void {

        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        // year_monthが指定されていない場合は現在の年月を使用
        if ($year_month === null) {
            $year_month = $now->format('Y-m');
        }

        // 当月の既存レポートを取得
        $existing = $this->get_reports_by_month($user_id, $year_month);

        // 最新版番号を取得
        $max_version = 0;
        foreach ($existing as $report) {
            $v = (int) get_post_meta($report->ID, '_gcrev_report_version', true);
            if ($v > $max_version) $max_version = $v;
        }
        $new_version = $max_version + 1;

        // 新規レポート作成
        $post_data = [
            'post_type'   => 'gcrev_report',
            'post_title'  => sprintf('%s %s様 月次レポート v%d', $year_month, get_userdata($user_id)->display_name, $new_version),
            'post_status' => 'publish',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id) || $post_id === 0) {
            error_log("[GCREV] Failed to save report to history for user_id={$user_id}");
            return;
        }

        // メタデータ保存
        update_post_meta($post_id, '_gcrev_user_id', $user_id);
        update_post_meta($post_id, '_gcrev_year_month', $year_month);
        update_post_meta($post_id, '_gcrev_report_state', 'draft'); // デフォルトはdraft
        update_post_meta($post_id, '_gcrev_report_version', $new_version);
        update_post_meta($post_id, '_gcrev_report_source', $source);
        update_post_meta($post_id, '_gcrev_report_html', $html);
        update_post_meta($post_id, '_gcrev_client_info_json', wp_json_encode($client_info, JSON_UNESCAPED_UNICODE));
        update_post_meta($post_id, '_gcrev_created_at', $now->format('Y-m-d H:i:s'));

        // =============================================
        // highlights 保存（呼び出し元で生成済みのものを保存）
        // =============================================
        update_post_meta($post_id, '_gcrev_highlights_json', wp_json_encode($highlights, JSON_UNESCAPED_UNICODE));
        error_log("[GCREV] Highlights saved for post_id={$post_id}: " . wp_json_encode($highlights, JSON_UNESCAPED_UNICODE));

        // =============================================
        // 初心者向けリライトMarkdown 保存
        // =============================================
        if ($beginner_markdown !== '') {
            update_post_meta($post_id, '_gcrev_beginner_markdown', $beginner_markdown);
            error_log("[GCREV] Beginner markdown saved for post_id={$post_id}, length=" . mb_strlen($beginner_markdown));
        }

        // 当月の is_current フラグを更新（新しいものを1、古いものを0に）
        foreach ($existing as $old_report) {
            update_post_meta($old_report->ID, '_gcrev_is_current', 0);
        }
        update_post_meta($post_id, '_gcrev_is_current', 1);

        error_log("[GCREV] Report saved to history: post_id={$post_id}, user_id={$user_id}, year_month={$year_month}, version={$new_version}, source={$source}");
    }

    /**
     * 指定年月のレポート一覧を取得
     */
    public function get_reports_by_month(int $user_id, string $year_month): array {

        $args = [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
                [
                    'key'   => '_gcrev_year_month',
                    'value' => $year_month,
                ],
            ],
        ];

        return get_posts($args);
    }

    /**
     * レポートデータのフォーマット
     */
    public function format_report_data(\WP_Post $post): array {

        $data = [
            'id'            => $post->ID,
            'year_month'    => get_post_meta($post->ID, '_gcrev_year_month', true),
            'state'         => get_post_meta($post->ID, '_gcrev_report_state', true),
            'version'       => (int) get_post_meta($post->ID, '_gcrev_report_version', true),
            'source'        => get_post_meta($post->ID, '_gcrev_report_source', true),
            'is_current'    => (int) get_post_meta($post->ID, '_gcrev_is_current', true),
            'report_html'   => get_post_meta($post->ID, '_gcrev_report_html', true),
            'client_info'   => json_decode(get_post_meta($post->ID, '_gcrev_client_info_json', true) ?: '{}', true),
            // highlights: 保存済みJSON → なければ空配列（既存データ互換）
            'highlights'    => json_decode(get_post_meta($post->ID, '_gcrev_highlights_json', true) ?: '{}', true),
            'created_at'    => get_post_meta($post->ID, '_gcrev_created_at', true),
            'finalized_at'  => get_post_meta($post->ID, '_gcrev_finalized_at', true),
        ];

        // 初心者向けリライトMarkdownが保存されていれば付与
        $beginner_md = get_post_meta($post->ID, '_gcrev_beginner_markdown', true);
        if (is_string($beginner_md) && $beginner_md !== '') {
            $data['beginner_markdown'] = $beginner_md;
        }

        return $data;
    }
}
