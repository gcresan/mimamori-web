<?php
/**
 * template-parts/report-modern-ai.php
 *
 * Claude (Anthropic) が生成した構造化 JSON を「月次Web改善提案書」として
 * 描画する部分テンプレート。
 *
 * 期待する変数（呼び出し側で set_query_var 経由で渡す）:
 *   $ai_data          ... mimamori_generate_monthly_report_with_claude() の出力 JSON
 *   $monthly_report   ... Gcrev_Monthly_Report_Service::get_monthly_ai_report() の結果
 *   $report_user_id   ... 対象ユーザー ID
 *   $report_year      ... 年
 *   $report_month     ... 月
 *
 * 設計方針:
 * - AI 本文は esc_html / nl2br(esc_html()) でエスケープ
 * - overall_summary が無ければ全体を出さない (呼び出し側で判定済の想定)
 * - 各セクションは空配列なら表示しない
 *
 * @package Mimamori_Web
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ---------------------------------------------------------
// 入力の取り出し
// ---------------------------------------------------------
$ai_data        = isset( $ai_data )        && is_array( $ai_data )        ? $ai_data        : ( get_query_var( 'gcrev_modern_ai_data' )        ?: [] );
$monthly_report = isset( $monthly_report ) && is_array( $monthly_report ) ? $monthly_report : ( get_query_var( 'gcrev_modern_monthly_report' ) ?: [] );
$report_user_id = isset( $report_user_id ) ? (int) $report_user_id : (int) ( get_query_var( 'gcrev_modern_user_id' ) ?: get_current_user_id() );
$report_year    = isset( $report_year )    ? (int) $report_year    : (int) ( get_query_var( 'gcrev_modern_year' )    ?: 0 );
$report_month   = isset( $report_month )   ? (int) $report_month   : (int) ( get_query_var( 'gcrev_modern_month' )   ?: 0 );

if ( empty( $ai_data['overall_summary'] ) ) {
    return; // 必須セクションが無ければ何も描画しない
}

// ---------------------------------------------------------
// ヘッダー用の補助情報
// ---------------------------------------------------------
$client_settings = function_exists( 'gcrev_get_client_settings' ) ? gcrev_get_client_settings( $report_user_id ) : [];
$site_url        = (string) ( $client_settings['site_url'] ?? '' );
$site_host       = '';
if ( $site_url !== '' ) {
    $parsed    = wp_parse_url( $site_url );
    $site_host = isset( $parsed['host'] ) ? preg_replace( '/^www\./', '', strtolower( $parsed['host'] ) ) : '';
}

// 事業者名 (gcrev_business_name → fallback: display_name)
if ( function_exists( 'gcrev_get_business_name' ) ) {
    $client_name = (string) gcrev_get_business_name( $report_user_id );
} else {
    $user_obj    = get_userdata( $report_user_id );
    $client_name = $user_obj ? ( $user_obj->display_name ?: $user_obj->user_login ) : '';
}

$ai_provider   = (string) ( $monthly_report['ai_provider'] ?? '' );
$ai_model      = (string) ( $monthly_report['ai_model']    ?? '' );
$created_at    = (string) ( $monthly_report['created_at']  ?? '' );

$period_label  = sprintf( '%d年%d月', $report_year, $report_month );
$compare_label = '';
if ( $report_year > 0 && $report_month > 0 ) {
    $base = strtotime( sprintf( '%04d-%02d-01', $report_year, $report_month ) );
    if ( $base ) {
        $prev_ts       = strtotime( '-1 month', $base );
        $compare_label = sprintf( '%d年%d月', (int) date( 'Y', $prev_ts ), (int) date( 'n', $prev_ts ) );
    }
}

// KPI: スナップショットがあれば数値カードに使う
$kpi_snapshot = null;
$post_id_for_snapshot = (int) ( $monthly_report['id'] ?? 0 );
if ( $post_id_for_snapshot > 0 ) {
    $snap_raw = get_post_meta( $post_id_for_snapshot, '_gcrev_kpi_snapshot_json', true );
    if ( $snap_raw ) {
        $decoded = json_decode( $snap_raw, true );
        if ( is_array( $decoded ) ) {
            $kpi_snapshot = $decoded;
        }
    }
}

// ---------------------------------------------------------
// 局所ヘルパー (テンプレ内のみ)
// ---------------------------------------------------------
$render_text = static function ( $text ): string {
    if ( is_array( $text ) ) { $text = wp_json_encode( $text, JSON_UNESCAPED_UNICODE ); }
    return nl2br( esc_html( (string) $text ) );
};

$status_class = static function ( string $status ): string {
    $s = strtolower( trim( $status ) );
    if ( in_array( $s, [ 'good', 'positive' ], true ) ) { return 'is-good'; }
    if ( in_array( $s, [ 'attention' ], true ) )         { return 'is-attention'; }
    if ( in_array( $s, [ 'warning', 'negative' ], true ) ) { return 'is-warning'; }
    if ( in_array( $s, [ 'shift', 'change' ], true ) )   { return 'is-shift'; }
    return 'is-watch';
};

$priority_class = static function ( string $priority ): string {
    $p = strtolower( trim( $priority ) );
    if ( $p === 'high' )   { return 'priority-high'; }
    if ( $p === 'medium' ) { return 'priority-medium'; }
    if ( $p === 'low' )    { return 'priority-low'; }
    return 'priority-medium';
};

$priority_label_map = [
    'high'   => '最優先',
    'medium' => '優先',
    'low'    => '余裕',
];

$status_label_map = [
    'good'      => 'GOOD',
    'attention' => 'ATTENTION',
    'shift'     => 'SHIFT',
    'warning'   => 'WARNING',
];

$confidence_label_map = [
    'high'   => '確度: 高',
    'medium' => '仮説',
    'low'    => '参考',
];

// セクション本体
$overall      = $ai_data['overall_summary'] ?? [];
$key_findings = is_array( $ai_data['key_findings']   ?? null ) ? $ai_data['key_findings']   : [];
$good_points  = is_array( $ai_data['good_points']    ?? null ) ? $ai_data['good_points']    : [];
$warn_points  = is_array( $ai_data['warning_points'] ?? null ) ? $ai_data['warning_points'] : [];
$cross_ins    = is_array( $ai_data['cross_insights'] ?? null ) ? $ai_data['cross_insights'] : [];
$next_actions = is_array( $ai_data['next_actions']   ?? null ) ? $ai_data['next_actions']   : [];
$data_notes   = is_array( $ai_data['data_notes']     ?? null ) ? $ai_data['data_notes']     : [];

$report_title = (string) ( $ai_data['report_title'] ?? sprintf( '%s 月次Web改善レポート', $period_label ) );
$overall_status_cls = $status_class( (string) ( $overall['status'] ?? '' ) );
$hero_variant_map = [
    'is-good'      => 'is-good',
    'is-attention' => 'is-attention',
    'is-warning'   => 'is-warning',
];
$hero_variant_cls = $hero_variant_map[ $overall_status_cls ] ?? '';

// KPI カード生成データ（スナップショットがある場合のみ）
$kpi_cards = [];
if ( is_array( $kpi_snapshot ) ) {
    $build_kpi = static function ( string $label, string $key, string $unit = '' ) use ( $kpi_snapshot ): ?array {
        $v = $kpi_snapshot[ $key ] ?? null;
        if ( $v === null || $v === '' ) { return null; }
        $num = null;
        if ( is_numeric( $v ) ) {
            $num = (float) $v;
        } elseif ( is_string( $v ) ) {
            $stripped = preg_replace( '/[,\s]/u', '', $v );
            if ( is_numeric( $stripped ) ) {
                $num = (float) $stripped;
            } elseif ( preg_match( '/-?\d+(?:\.\d+)?/', $stripped, $m ) ) {
                $num = (float) $m[0];
            }
        }
        if ( $num === null ) { return null; }

        // 前月比 (snapshot.trends から取得)
        $trend_obj = ( isset( $kpi_snapshot['trends'] ) && is_array( $kpi_snapshot['trends'] ) )
            ? ( $kpi_snapshot['trends'][ $key ] ?? null )
            : null;
        $change_text = '';
        $change_dir  = 'flat'; // up / down / flat
        if ( is_array( $trend_obj ) ) {
            $tv = $trend_obj['value'] ?? null;
            $tt = (string) ( $trend_obj['text'] ?? '' );
            if ( is_numeric( $tv ) ) {
                $tvf = (float) $tv;
                $change_text = $tt !== '' ? $tt : ( sprintf( '%+.1f%%', $tvf ) );
                if ( $tvf > 0.05 )      { $change_dir = 'up'; }
                elseif ( $tvf < -0.05 ) { $change_dir = 'down'; }
                else                    { $change_dir = 'flat'; }
            }
        }

        // 増加が良い指標 / 減少が良い指標
        $invert_polarity = false; // 今は全指標「増加=良し」として扱う (滞在時間も増えた方が良い)
        $variant = 'neutral';
        if ( $change_dir === 'up' )   { $variant = $invert_polarity ? 'neg' : 'pos'; }
        if ( $change_dir === 'down' ) { $variant = $invert_polarity ? 'pos' : 'neg'; }

        return [
            'label'       => $label,
            'value'       => number_format( $num, ( $num == (int) $num ) ? 0 : 1 ),
            'unit'        => $unit,
            'variant'     => $variant,
            'change_text' => $change_text,
            'change_dir'  => $change_dir,
        ];
    };
    foreach ( [
        [ 'ユーザー数',         'users',        '人' ],
        [ 'セッション数',       'sessions',     '回' ],
        [ 'ページビュー',       'pageViews',    '回' ],
        [ 'コンバージョン',     'conversions',  '件' ],
        [ '新規ユーザー',       'newUsers',     '人' ],
        [ '平均滞在時間 (秒)',  'avgDuration',  '秒' ],
    ] as $row ) {
        $card = $build_kpi( $row[0], $row[1], $row[2] );
        if ( $card !== null ) { $kpi_cards[] = $card; }
    }
}
?>
<div class="gcrev-ai-report-modern" data-ai-provider="<?php echo esc_attr( $ai_provider ); ?>">

    <!-- ============ ヘッダー ============ -->
    <header class="m-header">
        <div class="m-wrap">
            <div class="m-client-line">
                <div class="m-client-name">
                    <?php if ( $client_name !== '' ): ?>
                        <?php echo esc_html( $client_name ); ?> 様
                    <?php endif; ?>
                    <?php if ( $site_host !== '' ): ?>
                        <span style="color:var(--m-ink-mute); margin-left:8px; font-size:13px;"><?php echo esc_html( $site_host ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="m-report-meta">
                    <?php if ( $created_at !== '' ): ?>
                        Report Date: <?php echo esc_html( $created_at ); ?><br>
                    <?php endif; ?>
                    対象期間: <?php echo esc_html( $period_label ); ?>
                    <?php if ( $compare_label !== '' ): ?>
                        ／ 比較: <?php echo esc_html( $compare_label ); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="m-eyebrow">MONTHLY ACCESS REPORT　／　月次Web改善レポート</div>
            <h1 class="m-h1"><?php echo esc_html( $report_title ); ?></h1>

            <div class="m-source-bar">
                <span class="lbl">DATA SOURCE</span>
                <span>
                    GA4 ／ Search Console
                </span>
            </div>
        </div>
    </header>

    <!-- ============ レポート設定で入力された内容 (入力ありの項目のみ) ============ -->
    <?php
    $setting_inputs = [
        '先月の課題'           => trim( (string) get_user_meta( $report_user_id, 'report_issue',            true ) ),
        '先月の目標'           => trim( (string) get_user_meta( $report_user_id, 'report_goal_monthly',     true ) ),
        '重視している数字'     => trim( (string) get_user_meta( $report_user_id, 'report_focus_numbers',    true ) ),
        '現在のサイトの状況'   => trim( (string) get_user_meta( $report_user_id, 'report_current_state',    true ) ),
        '大きな目標'           => trim( (string) get_user_meta( $report_user_id, 'report_goal_main',        true ) ),
        '追加で伝えたい情報'   => trim( (string) get_user_meta( $report_user_id, 'report_additional_notes', true ) ),
    ];
    $setting_inputs_filled = array_filter( $setting_inputs, static fn( $v ) => $v !== '' );
    ?>
    <?php if ( ! empty( $setting_inputs_filled ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 00　今月のご相談・目標</div>
            <h2 class="m-sec-title">ご記入いただいた内容</h2>
            <p class="m-sec-desc">以下にご記入いただいた内容を踏まえて、本レポートの分析・提案を行なっています。</p>

            <dl class="m-settings-dl">
                <?php foreach ( $setting_inputs_filled as $label => $value ): ?>
                <dt><?php echo esc_html( $label ); ?></dt>
                <dd><?php echo nl2br( esc_html( $value ) ); ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション1: 結論 ============ -->
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 01　ひとことで言うと</div>
            <h2 class="m-sec-title"><?php echo esc_html( (string) ( $overall['title'] ?? '今月の結論' ) ); ?></h2>

            <div class="m-hero <?php echo esc_attr( $hero_variant_cls ); ?>">
                <div class="m-hero-label">CONCLUSION ／ 結論</div>
                <h3><?php echo esc_html( (string) ( $overall['title'] ?? '' ) ); ?></h3>
                <p><?php echo $render_text( $overall['body'] ?? '' ); ?></p>
            </div>

            <?php if ( ! empty( $kpi_cards ) ): ?>
            <div class="m-kpi-grid">
                <?php foreach ( $kpi_cards as $card ): ?>
                <?php
                    $card_dir   = (string) ( $card['change_dir']  ?? 'flat' );
                    $card_chtxt = (string) ( $card['change_text'] ?? '' );
                ?>
                <div class="m-kpi-card kpi-<?php echo esc_attr( $card['variant'] ); ?>">
                    <div class="m-kpi-label"><?php echo esc_html( $card['label'] ); ?></div>
                    <div class="m-kpi-num"><?php echo esc_html( $card['value'] ); ?><?php if ( $card['unit'] !== '' ): ?><small><?php echo esc_html( $card['unit'] ); ?></small><?php endif; ?></div>
                    <?php if ( $card_chtxt !== '' ): ?>
                    <div class="m-kpi-change m-kpi-change--<?php echo esc_attr( $card_dir ); ?>">
                        <?php if ( $card_dir === 'up' ): ?>
                            <span class="m-kpi-arrow" aria-hidden="true">▲</span>
                        <?php elseif ( $card_dir === 'down' ): ?>
                            <span class="m-kpi-arrow" aria-hidden="true">▼</span>
                        <?php else: ?>
                            <span class="m-kpi-arrow" aria-hidden="true">―</span>
                        <?php endif; ?>
                        <span class="m-kpi-change-text"><?php echo esc_html( $card_chtxt ); ?></span>
                        <span class="m-kpi-change-label">前月比</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ セクション2: 重要な変化 (key_findings) ============ -->
    <?php if ( ! empty( $key_findings ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 02　何が起きているのか</div>
            <h2 class="m-sec-title">今月起きている重要な変化</h2>
            <p class="m-sec-desc">データから読み取れる、注目すべき変化を順番に整理しています。</p>

            <div class="m-scene-list">
                <?php foreach ( $key_findings as $i => $kf ): ?>
                <?php
                    $kf_status = (string) ( $kf['status']  ?? '' );
                    $kf_label  = $status_label_map[ strtolower( $kf_status ) ] ?? strtoupper( $kf_status );
                    $kf_title  = (string) ( $kf['title']   ?? '' );
                    $kf_sum    = (string) ( $kf['summary'] ?? '' );
                    $kf_body   = (string) ( $kf['body']    ?? '' );
                    $kf_evid   = (string) ( $kf['evidence']?? '' );
                    // 問い合わせ関連キーワードが含まれていればアンカーリンクを出す
                    $kf_blob   = $kf_title . ' ' . $kf_sum . ' ' . $kf_body . ' ' . $kf_evid;
                    $kf_has_inquiry_mention = ! empty( $inquiry_list_items )
                        && preg_match( '/問い?合わせ|見込み客|リード|資料請求|フォーム送信|お問合せ/u', $kf_blob );
                ?>
                <div class="m-scene-card">
                    <div class="m-scene-no"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></div>
                    <div class="m-scene-body">
                        <?php if ( $kf_label !== '' ): ?>
                            <span class="m-status-tag <?php echo esc_attr( $status_class( $kf_status ) ); ?>"><?php echo esc_html( $kf_label ); ?></span>
                        <?php endif; ?>
                        <?php if ( $kf_title !== '' ): ?>
                            <h4><?php echo esc_html( $kf_title ); ?></h4>
                        <?php endif; ?>
                        <?php if ( $kf_sum !== '' ): ?>
                            <p class="m-answer"><?php echo esc_html( $kf_sum ); ?></p>
                        <?php endif; ?>
                        <?php if ( $kf_body !== '' ): ?>
                            <p><?php echo $render_text( $kf_body ); ?></p>
                        <?php endif; ?>
                        <?php if ( $kf_evid !== '' ): ?>
                            <div class="m-evidence"><strong>根拠:</strong> <?php echo $render_text( $kf_evid ); ?></div>
                        <?php endif; ?>
                        <?php if ( $kf_has_inquiry_mention ): ?>
                            <a class="m-inq-link" href="#m-inquiry-list">📋 実際の問い合わせ内容を見る →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション3: 良かった点 ============ -->
    <?php if ( ! empty( $good_points ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 03　良かった点</div>
            <h2 class="m-sec-title">続けるべき、伸ばすべきポイント</h2>

            <div class="m-point-grid">
                <?php foreach ( $good_points as $gp ): ?>
                <?php
                    $gp_title = (string) ( $gp['title']    ?? '' );
                    $gp_body  = (string) ( $gp['body']     ?? '' );
                    $gp_evid  = (string) ( $gp['evidence'] ?? '' );
                ?>
                <div class="m-point-card is-good">
                    <?php if ( $gp_title !== '' ): ?>
                        <h4><?php echo esc_html( $gp_title ); ?></h4>
                    <?php endif; ?>
                    <?php if ( $gp_body !== '' ): ?>
                        <p><?php echo $render_text( $gp_body ); ?></p>
                    <?php endif; ?>
                    <?php if ( $gp_evid !== '' ): ?>
                        <div class="m-evidence"><span class="m-evidence-label">根拠:</span><?php echo $render_text( $gp_evid ); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション4: 注意が必要な点 ============ -->
    <?php if ( ! empty( $warn_points ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 04　注意が必要な点</div>
            <h2 class="m-sec-title">放置せず手を打つべき課題</h2>

            <div class="m-point-grid">
                <?php foreach ( $warn_points as $wp ): ?>
                <?php
                    $wp_title = (string) ( $wp['title']    ?? '' );
                    $wp_body  = (string) ( $wp['body']     ?? '' );
                    $wp_evid  = (string) ( $wp['evidence'] ?? '' );
                    $wp_risk  = (string) ( $wp['risk']     ?? '' );
                ?>
                <div class="m-point-card is-warning">
                    <?php if ( $wp_title !== '' ): ?>
                        <h4><span class="m-status-tag is-warning" style="margin-right:8px;">注意</span><?php echo esc_html( $wp_title ); ?></h4>
                    <?php endif; ?>
                    <?php if ( $wp_body !== '' ): ?>
                        <p><?php echo $render_text( $wp_body ); ?></p>
                    <?php endif; ?>
                    <?php if ( $wp_risk !== '' ): ?>
                        <div class="m-risk"><span class="m-risk-label">放置リスク:</span><?php echo $render_text( $wp_risk ); ?></div>
                    <?php endif; ?>
                    <?php if ( $wp_evid !== '' ): ?>
                        <div class="m-evidence"><span class="m-evidence-label">根拠:</span><?php echo $render_text( $wp_evid ); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション5: 横断考察 ============ -->
    <?php if ( ! empty( $cross_ins ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 05　GA4 × Search Console 横断考察</div>
            <h2 class="m-sec-title">数字の裏にあるストーリー</h2>
            <p class="m-sec-desc">GA4 と Search Console を横断して読み解いた、今月のインサイトです。</p>

            <div class="m-insights">
                <?php foreach ( $cross_ins as $ci ): ?>
                <?php
                    $ci_conf  = strtolower( (string) ( $ci['confidence'] ?? '' ) );
                    $ci_label = $confidence_label_map[ $ci_conf ] ?? ( $ci_conf !== '' ? '確度: ' . $ci_conf : '' );
                    $ci_title = (string) ( $ci['title']    ?? '' );
                    $ci_body  = (string) ( $ci['body']     ?? '' );
                    $ci_evid  = (string) ( $ci['evidence'] ?? '' );
                    $conf_cls = 'is-' . ( in_array( $ci_conf, [ 'high', 'medium', 'low' ], true ) ? $ci_conf : 'high' );
                ?>
                <div class="m-insight-card">
                    <?php if ( $ci_label !== '' ): ?>
                        <span class="m-conf <?php echo esc_attr( $conf_cls ); ?>"><?php echo esc_html( $ci_label ); ?></span>
                    <?php endif; ?>
                    <?php if ( $ci_title !== '' ): ?>
                        <h4><?php echo esc_html( $ci_title ); ?></h4>
                    <?php endif; ?>
                    <?php if ( $ci_body !== '' ): ?>
                        <p><?php echo $render_text( $ci_body ); ?></p>
                    <?php endif; ?>
                    <?php if ( $ci_evid !== '' ): ?>
                        <div class="m-evidence"><strong>根拠:</strong> <?php echo $render_text( $ci_evid ); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション6: 次のアクション ============ -->
    <?php if ( ! empty( $next_actions ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 06　次にやるべきこと</div>
            <h2 class="m-sec-title">優先度付きアクションプラン</h2>
            <p class="m-sec-desc">今月のデータから導いた、具体的に着手すべき改善案を優先度別に並べました。</p>

            <div class="m-action-list">
                <?php foreach ( $next_actions as $i => $act ): ?>
                <?php
                    $pri        = strtolower( (string) ( $act['priority'] ?? '' ) );
                    $pri_cls    = $priority_class( $pri );
                    $pri_label  = $priority_label_map[ $pri ] ?? ( $pri !== '' ? strtoupper( $pri ) : '' );
                    $act_title  = (string) ( $act['title']            ?? '' );
                    $act_target = (string) ( $act['target']           ?? '' );
                    $act_reason = (string) ( $act['reason']           ?? '' );
                    $act_body   = (string) ( $act['action']           ?? '' );
                    $act_effect = (string) ( $act['expected_effect']  ?? '' );
                    $act_time   = (string) ( $act['estimated_timing'] ?? '' );
                ?>
                <div class="m-action-row <?php echo esc_attr( $pri_cls ); ?>">
                    <div class="m-priority-mark">
                        <span class="m-num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
                        <?php echo esc_html( $pri_label ); ?>
                    </div>
                    <div class="m-action-body">
                        <?php if ( $act_title !== '' ): ?>
                            <h4><?php echo esc_html( $act_title ); ?></h4>
                        <?php endif; ?>
                        <?php if ( $act_target !== '' ): ?>
                            <div class="m-tags"><span class="m-tag">対象: <?php echo esc_html( $act_target ); ?></span></div>
                        <?php endif; ?>
                        <dl>
                            <?php if ( $act_body !== '' ): ?>
                                <dt>実施内容</dt><dd><?php echo $render_text( $act_body ); ?></dd>
                            <?php endif; ?>
                            <?php if ( $act_reason !== '' ): ?>
                                <dt>理由</dt><dd><?php echo $render_text( $act_reason ); ?></dd>
                            <?php endif; ?>
                            <?php if ( $act_effect !== '' ): ?>
                                <dt>期待効果</dt><dd><?php echo $render_text( $act_effect ); ?></dd>
                            <?php endif; ?>
                            <?php if ( $act_time !== '' ): ?>
                                <dt>目安期間</dt><dd><?php echo esc_html( $act_time ); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション7: 実際の問い合わせ内容 ============ -->
    <?php
    // 問い合わせ連携プラグインが有効なユーザーに限り、実際の問い合わせを一覧表示
    $inquiry_list_items = [];
    if ( class_exists( 'Mimamori_Inquiries_Fetcher' )
        && \Mimamori_Inquiries_Fetcher::is_enabled( $report_user_id )
        && $report_year > 0 && $report_month > 0
    ) {
        $iq_ym = sprintf( '%04d-%02d', $report_year, $report_month );
        $raw_items = \Mimamori_Inquiries_Fetcher::get_items_json( $report_user_id, $iq_ym );
        if ( is_array( $raw_items ) ) {
            $raw_items = \Mimamori_Inquiries_Fetcher::apply_overrides_to_items( $report_user_id, $iq_ym, $raw_items );
            // 日付降順
            usort( $raw_items, static function ( $a, $b ) {
                return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) );
            } );
            // 有効問い合わせのみ最大 30 件
            $cnt = 0;
            foreach ( $raw_items as $it ) {
                if ( empty( $it['effective_valid'] ) ) { continue; }
                $inquiry_list_items[] = $it;
                if ( ++$cnt >= 30 ) { break; }
            }
        }
    }

    // name フィールドが空の場合に message 本文から抽出するヘルパー
    $extract_name_from_message = static function ( string $message ): string {
        if ( $message === '' ) { return ''; }
        // よくある日本語フォームのパターン
        $patterns = [
            '/(?:お名前|氏名|お名前\s*\(\s*必須\s*\)|お問い合わせ者名|お問合せ者名|名前)\s*[：:＝=]\s*([^\r\n　]+?)(?:[\r\n　]|$)/u',
            '/(?:Name|name|NAME)\s*[：:＝=]\s*([^\r\n　]+?)(?:[\r\n　]|$)/u',
            // 「姓 名」が並んでいる場合 (姓: XXX 名: YYY → 姓+名で連結)
        ];
        foreach ( $patterns as $p ) {
            if ( preg_match( $p, $message, $m ) ) {
                $candidate = trim( $m[1] );
                // ノイズ除外
                if ( $candidate !== '' && mb_strlen( $candidate ) <= 40 && ! preg_match( '/^[\d\-\s]+$/u', $candidate ) ) {
                    return $candidate;
                }
            }
        }
        // 「姓: X / 名: Y」を結合するパターン
        if ( preg_match( '/(?:お?姓|苗字)\s*[：:＝=]\s*([^\r\n　]+)/u', $message, $sei )
            && preg_match( '/(?:お?名前?|お名)\s*[：:＝=]\s*([^\r\n　]+)/u', $message, $mei ) ) {
            $combined = trim( $sei[1] ) . ' ' . trim( $mei[1] );
            if ( mb_strlen( $combined ) <= 40 ) { return $combined; }
        }
        return '';
    };

    // スラッシュ等で区切られたサマリ文字列から日本語名らしきトークンを抽出するヘルパー
    // 例: "プラン・間取りのご相談 / 兵頭亜美 / ヒョウドウアミ / 090-xxx / ..." → "兵頭亜美"
    $extract_name_from_summary = static function ( string $summary ): string {
        if ( $summary === '' ) { return ''; }
        $tokens = preg_split( '/[\/／,、]/u', $summary );
        if ( ! is_array( $tokens ) ) { return ''; }
        $kanji_candidates = [];
        $kana_candidates  = [];
        foreach ( $tokens as $token ) {
            $t = preg_replace( '/^[\s　]+|[\s　]+$/u', '', (string) $token ); // 前後の半角/全角空白を除去
            if ( $t === '' ) { continue; }
            // 数字・@・ハイフン・括弧・中黒・ひらがな等を含むトークンは除外
            // ひらがなを除外することで「同意する」「ご相談」等の動詞・名詞句を弾く
            if ( preg_match( '/[\d@\-\(\)（）・〜~:：\s　\x{3040}-\x{309F}]/u', $t ) ) { continue; }
            // 漢字のみ 2〜6文字 — 最優先（例: 兵頭亜美, 井上博貴）
            if ( preg_match( '/^[\x{4E00}-\x{9FFF}々]{2,6}$/u', $t ) ) {
                $kanji_candidates[] = $t;
                continue;
            }
            // カタカナのみ 3〜12文字 — フリガナや外国人名（例: ヒョウドウアミ）
            // ただし「メール」「お電話」等の短い名詞を弾くため4文字以上を要件にする
            if ( preg_match( '/^[\x{30A0}-\x{30FF}ー]{4,12}$/u', $t ) ) {
                $kana_candidates[] = $t;
                continue;
            }
        }
        if ( ! empty( $kanji_candidates ) ) { return $kanji_candidates[0]; }
        if ( ! empty( $kana_candidates ) )  { return $kana_candidates[0];  }
        return '';
    };
    ?>
    <?php if ( ! empty( $inquiry_list_items ) ): ?>
    <section class="m-section" id="m-inquiry-list">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 07　実際の問い合わせ内容</div>
            <h2 class="m-sec-title">見込み客から届いた問い合わせ一覧</h2>
            <p class="m-sec-desc">営業メール・SPAM 等を AI 分類で除外した、事業上意味のある問い合わせの内訳です。実際のお客様の声を踏まえた施策判断にお使いください。</p>

            <table class="m-inq-table">
                <thead>
                    <tr>
                        <th class="m-inq-th-date">日付</th>
                        <th class="m-inq-th-name">問い合わせ者</th>
                        <th class="m-inq-th-cat">分類</th>
                        <th class="m-inq-th-summary">内容サマリ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $inquiry_list_items as $it ): ?>
                <?php
                    $iq_date = (string) ( $it['date'] ?? '' );
                    $iq_date_disp = '';
                    if ( $iq_date !== '' ) {
                        try {
                            $dt = new \DateTimeImmutable( $iq_date, wp_timezone() );
                            $iq_date_disp = $dt->format( 'n/j H:i' );
                        } catch ( \Throwable $e ) {
                            $iq_date_disp = mb_substr( $iq_date, 0, 16 );
                        }
                    }
                    $iq_name = trim( (string) ( $it['name'] ?? '' ) );
                    if ( $iq_name === '' ) {
                        // 本文から抽出を試みる (「お名前: 田中太郎」等)
                        $iq_name = $extract_name_from_message( (string) ( $it['message'] ?? '' ) );
                    }
                    if ( $iq_name === '' ) {
                        // AI サマリ（スラッシュ区切り）から日本語名らしきトークンを抽出
                        $iq_name = $extract_name_from_summary( (string) ( $it['ai_summary'] ?? '' ) );
                    }
                    if ( $iq_name === '' ) {
                        // 本文側にも同じパターンで存在する可能性がある
                        $iq_name = $extract_name_from_summary( (string) ( $it['message'] ?? '' ) );
                    }
                    if ( $iq_name === '' ) { $iq_name = '(名前なし)'; }
                    $iq_cat  = (string) ( $it['ai_category'] ?? '' );
                    $iq_sum  = (string) ( $it['ai_summary'] ?? mb_substr( (string) ( $it['message'] ?? '' ), 0, 140 ) );
                ?>
                <tr>
                    <td class="m-inq-td-date"><?php echo esc_html( $iq_date_disp ); ?></td>
                    <td class="m-inq-td-name"><?php echo esc_html( $iq_name ); ?></td>
                    <td class="m-inq-td-cat"><span class="m-inq-cat"><?php echo esc_html( $iq_cat ); ?></span></td>
                    <td class="m-inq-td-summary"><?php echo $render_text( $iq_sum ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============ セクション8: データ・計測上の注意 ============ -->
    <?php if ( ! empty( $data_notes ) ): ?>
    <section class="m-section">
        <div class="m-wrap">
            <div class="m-sec-tag">SECTION 08　データ・計測上の注意</div>
            <h2 class="m-sec-title">読み解きの前提条件</h2>

            <?php foreach ( $data_notes as $dn ): ?>
            <?php
                $dn_title = (string) ( $dn['title'] ?? '' );
                $dn_body  = (string) ( $dn['body']  ?? '' );
                if ( $dn_title === '' && $dn_body === '' ) { continue; }
            ?>
            <div class="m-footnote">
                <?php if ( $dn_title !== '' ): ?>
                    <strong><?php echo esc_html( $dn_title ); ?></strong>
                <?php endif; ?>
                <?php echo $render_text( $dn_body ); ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>
