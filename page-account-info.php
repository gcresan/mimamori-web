<?php
/*
Template Name: アカウント情報
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル
set_query_var( 'gcrev_page_title', 'アカウント情報' );

// パンくず
$breadcrumb  = '<a href="' . esc_url( home_url() ) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="' . esc_url( home_url( '/account/' ) ) . '">アカウント</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>アカウント情報</strong>';
set_query_var( 'gcrev_breadcrumb', $breadcrumb );

// --- データ取得 ---
$steps      = gcrev_get_payment_steps( $user_id );
$dates      = gcrev_get_contract_dates( $user_id );
$plan_defs  = gcrev_get_plan_definitions();

// プランタイプ（表示用）
$contract_type = $steps['contract_type'];
$type_label    = ( $contract_type === 'with_site' ) ? '制作込みプラン' : '伴走運用プラン';

// プラン名・月額
$user_plan = get_user_meta( $user_id, 'gcrev_user_plan', true );
$plan_info = isset( $plan_defs[ $user_plan ] ) ? $plan_defs[ $user_plan ] : null;
$plan_name = $plan_info ? $plan_info['name'] : '未選択';
$monthly   = $plan_info ? number_format( $plan_info['monthly'] ) : '—';

// 契約ステータス
$c_status     = $dates['status'];
$has_contract = ! empty( $dates['start_at'] );

get_header();
?>

<style>
/* page-account-info — Page-specific styles */

.account-info-section {
    max-width: 800px;
    margin: 0 auto;
}

.account-info-section h2 {
    font-size: 18px;
    font-weight: 700;
    color: var(--mw-text-primary, #2c3e50);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--mw-primary-blue, #3D6B6E);
}

.account-info-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.account-info-table th,
.account-info-table td {
    padding: 14px 18px;
    font-size: 14px;
    border-bottom: 1px solid var(--mw-border-light, #e8ecee);
    text-align: left;
    vertical-align: middle;
}

.account-info-table th {
    width: 180px;
    color: var(--mw-text-secondary, #666);
    font-weight: 600;
    background: #fafbfc;
}

.account-info-table td {
    color: var(--mw-text-primary, #2c3e50);
}

.account-info-table tr:last-child th,
.account-info-table tr:last-child td {
    border-bottom: none;
}

/* ステータスバッジ */
.contract-badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
}
.contract-badge--active {
    color: #3D8B6E;
    background: rgba(61,139,110,0.08);
}
.contract-badge--canceled {
    color: #C0392B;
    background: #FDF0EE;
}
.contract-badge--none {
    color: #888;
    background: #f0f0f0;
}

/* 未開始メッセージ */
.account-info-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    background: #fafbfc;
    border: 1px solid var(--mw-border-light, #e8ecee);
    border-radius: 8px;
    font-size: 14px;
    color: var(--mw-text-secondary, #666);
    margin-top: 16px;
}
.account-info-notice .notice-icon {
    font-size: 20px;
    flex-shrink: 0;
}

/* レスポンシブ */
@media (max-width: 600px) {
    .account-info-table th,
    .account-info-table td {
        display: block;
        width: 100%;
        box-sizing: border-box;
    }
    .account-info-table th {
        padding-bottom: 4px;
        border-bottom: none;
        font-size: 12px;
    }
    .account-info-table td {
        padding-top: 0;
        padding-bottom: 14px;
    }
}
</style>

<div class="content-wrapper">
    <div class="account-info-section">
        <h2>契約中プラン</h2>

        <table class="account-info-table">
            <tbody>
                <tr>
                    <th>プランタイプ</th>
                    <td><?php echo esc_html( $type_label ); ?></td>
                </tr>
                <tr>
                    <th>プラン名</th>
                    <td><?php echo esc_html( $plan_name ); ?></td>
                </tr>
                <tr>
                    <th>月額料金</th>
                    <td><?php echo $plan_info ? '&yen;' . esc_html( $monthly ) : '—'; ?></td>
                </tr>
                <tr>
                    <th>契約ステータス</th>
                    <td>
                        <?php if ( $c_status === 'active' ) : ?>
                            <span class="contract-badge contract-badge--active">利用中</span>
                        <?php elseif ( $c_status === 'canceled' ) : ?>
                            <span class="contract-badge contract-badge--canceled">解約済み</span>
                        <?php else : ?>
                            <span class="contract-badge contract-badge--none">未開始</span>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php if ( $has_contract ) : ?>
                <tr>
                    <th>契約開始日</th>
                    <td><?php echo esc_html( wp_date( 'Y年n月j日', strtotime( $dates['start_at'] ) ) ); ?></td>
                </tr>
                <tr>
                    <th>最終更新日</th>
                    <td><?php echo $dates['last_renewed_at'] ? esc_html( wp_date( 'Y年n月j日', strtotime( $dates['last_renewed_at'] ) ) ) : '—'; ?></td>
                </tr>
                <tr>
                    <th>次回更新日</th>
                    <td><?php echo $dates['next_renewal_at'] ? esc_html( wp_date( 'Y年n月j日', strtotime( $dates['next_renewal_at'] ) ) ) : '—'; ?></td>
                </tr>
                <tr>
                    <th>解約可能日</th>
                    <td><?php echo $dates['cancellable_at'] ? esc_html( wp_date( 'Y年n月j日', strtotime( $dates['cancellable_at'] ) ) ) : '—'; ?></td>
                </tr>
                <?php else : ?>
                <tr>
                    <th>契約開始日</th>
                    <td>—</td>
                </tr>
                <tr>
                    <th>最終更新日</th>
                    <td>—</td>
                </tr>
                <tr>
                    <th>次回更新日</th>
                    <td>—</td>
                </tr>
                <tr>
                    <th>解約可能日</th>
                    <td>—</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( ! $has_contract ) : ?>
        <div class="account-info-notice">
            <span class="notice-icon">&#9432;</span>
            <span>決済手続きが完了すると、契約情報が表示されます。</span>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php get_footer(); ?>
