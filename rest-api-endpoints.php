<?php
/**
 * GCREV v2ダッシュボード用 REST API 追加登録
 * 既存のregister_routes()メソッド内に以下を追加してください
 */

// class Gcrev_Insight_API の register_routes() メソッド内に追加:

// ===== v2ダッシュボード用KPI取得 =====
register_rest_route('gcrev/v1', '/dashboard/kpi', [
    'methods'             => 'GET',
    'callback'            => [ $this, 'rest_get_dashboard_kpi' ],
    'permission_callback' => [ $this, 'check_permission' ],
    'args'                => [
        'period' => [
            'required'          => false,
            'default'           => 'prev-month',
            'validate_callback' => function($param) {
                return in_array($param, ['last30', 'prev-month', 'prev-prev-month']);
            }
        ]
    ]
]);

// ===== v2ダッシュボード用レポート生成 =====
register_rest_route('gcrev/v1', '/report/generate-manual', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'rest_generate_report_manual' ],
    'permission_callback' => [ $this, 'check_permission' ],
    'args'                => [
        'year' => [
            'required'          => false,
            'validate_callback' => function($param) {
                return is_numeric($param) && $param >= 2020 && $param <= 2100;
            }
        ],
        'month' => [
            'required'          => false,
            'validate_callback' => function($param) {
                return is_numeric($param) && $param >= 1 && $param <= 12;
            }
        ]
    ]
]);

/**
 * 以下のメソッドをclass Gcrev_Insight_APIに追加してください
 */

/**
 * REST: v2ダッシュボード用KPI取得
 */
public function rest_get_dashboard_kpi(WP_REST_Request $request): WP_REST_Response {
    $period = $request->get_param('period') ?? 'prev-month';
    $user_id = get_current_user_id();

    error_log("[GCREV] REST get_dashboard_kpi: user_id={$user_id}, period={$period}");

    try {
        $kpi_data = $this->get_dashboard_kpi($period, $user_id);

        return new WP_REST_Response([
            'success' => true,
            'data'    => $kpi_data,
        ], 200);

    } catch (\Exception $e) {
        error_log("[GCREV] REST get_dashboard_kpi ERROR: " . $e->getMessage());
        return new WP_REST_Response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * REST: v2ダッシュボード用レポート手動生成
 */
public function rest_generate_report_manual(WP_REST_Request $request): WP_REST_Response {
    $year = $request->get_param('year');
    $month = $request->get_param('month');
    $user_id = get_current_user_id();

    error_log("[GCREV] REST generate_report_manual: user_id={$user_id}, year={$year}, month={$month}");

    try {
        $result = $this->generate_monthly_report_manual($user_id, $year, $month);

        if ($result['success']) {
            return new WP_REST_Response($result, 200);
        } else {
            return new WP_REST_Response($result, 400);
        }

    } catch (\Exception $e) {
        error_log("[GCREV] REST generate_report_manual ERROR: " . $e->getMessage());
        return new WP_REST_Response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}
