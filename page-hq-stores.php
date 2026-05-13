<?php
/*
Template Name: 本部 — 店舗一覧
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$hq_user_id = get_current_user_id();

// 本部ユーザーでない場合はダッシュボードへ
if ( ! mimamori_is_hq_user( $hq_user_id ) ) {
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}

$managed_ids = mimamori_get_hq_managed_user_ids( $hq_user_id );

// MEO サービスクラスを読み込む (順位データなどの集約用)
if ( ! class_exists( 'Gcrev_Meo_Report_Service' ) ) {
    $svc = get_template_directory() . '/inc/gcrev-api/modules/class-meo-report-service.php';
    if ( file_exists( $svc ) ) require_once $svc;
}

/**
 * 1店舗分のサマリーを取得 (直近30日)。
 * 失敗時は 0 埋めの配列を返す。
 */
$summarize_store = static function ( int $client_user_id ): array {
    $default = [
        'business_name'  => '',
        'impressions'    => 0,
        'website_clicks' => 0,
        'call_clicks'    => 0,
        'direction_clicks'=> 0,
        'reviews_count'  => 0,
        'rating'         => 0.0,
        'maps_rank_avg'  => null,
    ];

    $u = get_userdata( $client_user_id );
    if ( ! $u ) return $default;
    $default['business_name'] = function_exists( 'gcrev_get_business_name' )
        ? (string) gcrev_get_business_name( $client_user_id )
        : (string) $u->display_name;
    if ( $default['business_name'] === '' ) $default['business_name'] = (string) $u->display_name;

    // MEO metrics (last30) — 既存の安全な公開ラッパーを使う
    if ( class_exists( 'Gcrev_Insight_API' ) ) {
        try {
            $api = new Gcrev_Insight_API( false );
            if ( method_exists( $api, 'fetch_meo_metrics_safe_public' ) ) {
                $tz    = wp_timezone();
                $end   = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
                $start = ( new DateTimeImmutable( '-30 days', $tz ) )->format( 'Y-m-d' );
                $m = $api->fetch_meo_metrics_safe_public( $client_user_id, $start, $end );
                if ( is_array( $m ) ) {
                    $default['impressions']      = (int) ( ( $m['mobile_impressions'] ?? 0 ) + ( $m['desktop_impressions'] ?? 0 ) );
                    $default['website_clicks']   = (int) ( $m['website_clicks']    ?? 0 );
                    $default['call_clicks']      = (int) ( $m['call_clicks']       ?? 0 );
                    $default['direction_clicks'] = (int) ( $m['direction_clicks']  ?? 0 );
                }
            }
        } catch ( \Throwable $e ) {
            // 失敗時はデフォルトのまま
        }
    }

    // クチコミ件数・評価 + マップ順位平均: gcrev_meo_results から直接
    global $wpdb;
    $meo_table = $wpdb->prefix . 'gcrev_meo_results';
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT rating, reviews_count
           FROM {$meo_table}
          WHERE user_id = %d
            AND rating IS NOT NULL
            AND reviews_count IS NOT NULL
          ORDER BY fetch_date DESC, id DESC LIMIT 1",
        $client_user_id
    ), ARRAY_A );
    if ( is_array( $row ) ) {
        $default['reviews_count'] = (int) ( $row['reviews_count'] ?? 0 );
        $default['rating']        = (float) ( $row['rating']        ?? 0 );
    }

    // マップ順位の直近30日平均 (mobile/business base のみ。圏外/NULL は除外)
    $avg = $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(maps_rank) FROM {$meo_table}
          WHERE user_id = %d AND device = 'mobile' AND base_mode = 'business'
            AND maps_rank IS NOT NULL
            AND fetch_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
        $client_user_id
    ) );
    if ( $avg !== null && $avg !== '' ) {
        $default['maps_rank_avg'] = round( (float) $avg, 1 );
    }

    return $default;
};

$stores = [];
foreach ( $managed_ids as $cid ) {
    $stores[] = [ 'id' => $cid ] + $summarize_store( (int) $cid );
}

set_query_var( 'gcrev_page_title', '本部ビュー — 店舗一覧' );
set_query_var( 'gcrev_page_subtitle', '管理対象店舗の概況をひと目で確認できます。カードをクリックすると個別店舗のダッシュボードに遷移します。' );

get_header();
?>

<style>
.hq-wrap { max-width: 1180px; margin: 0 auto; padding: 24px 20px; }
.hq-empty { background: #fff; border: 1px dashed #c3ced0; border-radius: 12px; padding: 60px 24px; text-align: center; color: #6b7c80; }
.hq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
.hq-card {
    background: #fff;
    border: 1px solid #c3ced0;
    border-radius: 12px;
    padding: 0;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
    overflow: hidden;
}
.hq-card-form { margin: 0; padding: 0; }
.hq-card-button {
    width: 100%;
    border: none;
    background: none;
    text-align: left;
    padding: 18px 20px 20px;
    cursor: pointer;
    font-family: inherit;
    color: inherit;
}
.hq-card:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,0.08); border-color: #568184; }
.hq-card-head { display:flex; align-items:baseline; justify-content:space-between; gap:8px; margin-bottom:14px; }
.hq-card-name { font-size: 16px; font-weight: 700; color: #0f172a; line-height: 1.3; word-break: break-word; }
.hq-card-id { font-size: 11px; color: #94a3b8; font-variant-numeric: tabular-nums; flex-shrink: 0; }
.hq-card-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 14px; }
.hq-stat { font-size: 12px; color: #475569; }
.hq-stat-label { color: #94a3b8; font-size: 11px; }
.hq-stat-value { font-size: 16px; font-weight: 600; color: #0f172a; line-height: 1.2; }
.hq-stat-unit { font-size: 11px; color: #94a3b8; margin-left: 2px; }
.hq-stat-rating { color: #d97706; }
.hq-card-footer { margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e5e7eb; font-size: 12px; color: #568184; text-align: right; }
.hq-card-footer::after { content: " →"; }
.hq-period-note { font-size: 12px; color: #94a3b8; margin: -4px 0 18px; }
</style>

<div class="hq-wrap">
    <p class="hq-period-note">指標は直近30日。クチコミ件数・評価は最新の取得値です。</p>

    <?php if ( empty( $stores ) ) : ?>
        <div class="hq-empty">
            <p style="font-size:15px;margin:0 0 6px;">管理対象クライアントが登録されていません。</p>
            <p style="font-size:13px;margin:0;">管理者にお問い合わせください。</p>
        </div>
    <?php else : ?>
        <div class="hq-grid">
            <?php foreach ( $stores as $s ) :
                $rating_disp = $s['rating'] > 0 ? number_format( $s['rating'], 1 ) : '—';
                $rank_disp   = $s['maps_rank_avg'] !== null ? (string) $s['maps_rank_avg'] : '—';
            ?>
                <div class="hq-card">
                    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="hq-card-form">
                        <?php wp_nonce_field( 'mimamori_hq_view_set' ); ?>
                        <input type="hidden" name="action" value="mimamori_hq_view_set">
                        <input type="hidden" name="target_user_id" value="<?php echo (int) $s['id']; ?>">
                        <input type="hidden" name="redirect" value="<?php echo esc_attr( home_url('/dashboard/') ); ?>">
                        <button type="submit" class="hq-card-button" aria-label="<?php echo esc_attr( $s['business_name'] . 'のダッシュボードを開く' ); ?>">
                            <div class="hq-card-head">
                                <div class="hq-card-name"><?php echo esc_html( $s['business_name'] ); ?></div>
                                <div class="hq-card-id">#<?php echo (int) $s['id']; ?></div>
                            </div>
                            <div class="hq-card-stats">
                                <div class="hq-stat">
                                    <div class="hq-stat-label">表示回数</div>
                                    <div class="hq-stat-value"><?php echo esc_html( number_format( $s['impressions'] ) ); ?></div>
                                </div>
                                <div class="hq-stat">
                                    <div class="hq-stat-label">マップ順位 平均</div>
                                    <div class="hq-stat-value">
                                        <?php echo esc_html( $rank_disp ); ?><?php if ( $rank_disp !== '—' ) echo '<span class="hq-stat-unit">位</span>'; ?>
                                    </div>
                                </div>
                                <div class="hq-stat">
                                    <div class="hq-stat-label">クチコミ件数</div>
                                    <div class="hq-stat-value"><?php echo esc_html( number_format( $s['reviews_count'] ) ); ?><span class="hq-stat-unit">件</span></div>
                                </div>
                                <div class="hq-stat">
                                    <div class="hq-stat-label">評価 平均</div>
                                    <div class="hq-stat-value hq-stat-rating"><?php echo esc_html( $rating_disp ); ?><?php if ( $rating_disp !== '—' ) echo ' ★'; ?></div>
                                </div>
                                <div class="hq-stat">
                                    <div class="hq-stat-label">電話タップ</div>
                                    <div class="hq-stat-value"><?php echo esc_html( number_format( $s['call_clicks'] ) ); ?></div>
                                </div>
                                <div class="hq-stat">
                                    <div class="hq-stat-label">ルート検索</div>
                                    <div class="hq-stat-value"><?php echo esc_html( number_format( $s['direction_clicks'] ) ); ?></div>
                                </div>
                            </div>
                            <div class="hq-card-footer">ダッシュボードを開く</div>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
