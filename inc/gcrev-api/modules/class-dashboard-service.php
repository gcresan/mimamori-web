<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Dashboard Usecase / Service
 * - ダッシュボード表示に必要なデータをまとめて返す
 * - 表示側（page-dashboard.php）は「受け取って表示」だけにする
 * - 見た目を変えないため、戻り値は現状の変数に合わせる
 */
if ( ! class_exists('Gcrev_Dashboard_Service') ) {

class Gcrev_Dashboard_Service {

    /** @var Gcrev_Monthly_Report_Service */
    private $report_service;

    public function __construct(Gcrev_Monthly_Report_Service $report_service) {
        $this->report_service = $report_service;
    }

    /**
     * ダッシュボード用 payload を返す
     *
     * @param int        $year
     * @param int        $month
     * @param int        $user_id
     * @param array|null $infographic  インフォグラフィックデータ（ハイライト詳細生成用）
     * @return array{
     *   monthly_report: mixed,
     *   highlights: array,
     *   highlight_details: array
     * }
     */
    public function get_payload(int $year, int $month, int $user_id, ?array $infographic = null): array {

        // 既存の取得ロジックは Monthly_Report_Service に集約済み（Step4.5）
        $monthly_report = $this->report_service->get_monthly_ai_report($year, $month, $user_id);

        // highlights は配列で返る想定（most_important/top_issue/opportunity）
        $highlights = $this->report_service->get_monthly_highlights($year, $month, $user_id);

        // 安全ガード（テンプレが期待するキーを必ず持つ）
        if ( ! is_array($highlights) ) {
            $highlights = [];
        }
        $highlights += [
            'most_important' => '',
            'top_issue'      => '',
            'opportunity'    => '',
        ];

        // ハイライト詳細テキスト生成（ディープダイブ用）
        $highlight_details = $this->report_service->build_highlight_details(
            $highlights,
            $infographic,
            $monthly_report
        );

        return [
            'monthly_report'    => $monthly_report,
            'highlights'        => $highlights,
            'highlight_details' => $highlight_details,
        ];
    }
}

}
