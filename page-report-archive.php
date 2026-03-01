<?php
/*
Template Name: 過去の月次レポート一覧
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル
set_query_var( 'gcrev_page_title', '過去の月次レポート一覧' );
set_query_var( 'gcrev_page_subtitle', '保存された月次レポートを月ごとに確認できます。' );

// パンくず
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '過去の月次レポート一覧', '月次レポート' ) );

// ========================================
// レポート一覧取得（CPT: gcrev_report）
// ========================================
$paged = max( 1, absint( get_query_var( 'paged', 1 ) ) );
$per_page = 20;

$args = [
    'post_type'      => 'gcrev_report',
    'posts_per_page' => $per_page,
    'paged'          => $paged,
    'post_status'    => 'publish',
    'orderby'        => 'meta_value',
    'meta_key'       => '_gcrev_year_month',
    'order'          => 'DESC',
    'meta_query'     => [
        [
            'key'   => '_gcrev_user_id',
            'value' => $user_id,
        ],
        [
            'key'   => '_gcrev_is_current',
            'value' => 1,
        ],
    ],
];

$report_query = new WP_Query( $args );
$reports      = $report_query->posts;
$total_pages  = $report_query->max_num_pages;

// レポートデータ整形
$report_list = [];
foreach ( $reports as $post ) {
    $ym         = get_post_meta( $post->ID, '_gcrev_year_month', true );
    $state      = get_post_meta( $post->ID, '_gcrev_report_state', true );
    $version    = (int) get_post_meta( $post->ID, '_gcrev_report_version', true );
    $source     = get_post_meta( $post->ID, '_gcrev_report_source', true );
    $created_at = get_post_meta( $post->ID, '_gcrev_created_at', true );
    $finalized  = get_post_meta( $post->ID, '_gcrev_finalized_at', true );

    $report_list[] = [
        'id'           => $post->ID,
        'year_month'   => $ym,
        'state'        => $state ?: 'draft',
        'version'      => $version,
        'source'       => $source ?: 'auto',
        'created_at'   => $created_at,
        'finalized_at' => $finalized,
    ];
}

get_header();
?>

<style>
/* =============================================
   page-report-archive — Page-specific styles
   ============================================= */
.rpt-archive-container {
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 32px 48px;
}
.rpt-archive-card {
    background: #fff;
    border-radius: var(--mw-radius-md, 10px);
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    padding: 28px 32px;
}
.rpt-archive-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--mw-primary-blue, #3D6B6E);
}
.rpt-archive-header h2 {
    font-size: 17px;
    font-weight: 700;
    color: var(--mw-text-primary, #2c3e50);
    margin: 0;
}
.rpt-archive-count {
    font-size: 13px;
    color: #888;
}

/* テーブル */
.rpt-archive-table {
    width: 100%;
    border-collapse: collapse;
}
.rpt-archive-table th {
    background: #f8f9fa;
    font-size: 12px;
    font-weight: 600;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #e0e0e0;
}
.rpt-archive-table td {
    padding: 14px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 14px;
    color: #333;
    vertical-align: middle;
}
.rpt-archive-table tbody tr:hover {
    background: #fafbfc;
}
.rpt-archive-table tbody tr:last-child td {
    border-bottom: none;
}

/* 年月ラベル */
.rpt-ym-label {
    font-weight: 600;
    font-size: 15px;
    color: var(--mw-primary-blue, #3D6B6E);
}

/* 状態バッジ */
.rpt-state-badge {
    display: inline-block;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 4px;
    letter-spacing: 0.03em;
}
.rpt-state-badge--finalized {
    background: #e8f5e9;
    color: #2e7d32;
}
.rpt-state-badge--draft {
    background: #fff3e0;
    color: #e65100;
}

/* ソースバッジ */
.rpt-source-badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 11px;
    border-radius: 3px;
    background: #f5f5f5;
    color: #888;
}

/* 詳細ボタン */
.rpt-view-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 500;
    color: var(--mw-primary-blue, #3D6B6E);
    background: rgba(61,107,110,0.06);
    border: 1px solid rgba(61,107,110,0.15);
    border-radius: 6px;
    text-decoration: none;
    transition: background 0.15s, border-color 0.15s;
}
.rpt-view-btn:hover {
    background: rgba(61,107,110,0.12);
    border-color: rgba(61,107,110,0.3);
    color: var(--mw-primary-blue, #3D6B6E);
    text-decoration: none;
}

/* 空状態 */
.rpt-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}
.rpt-empty-state .rpt-empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
}
.rpt-empty-state p {
    font-size: 15px;
    margin: 0;
}

/* ページネーション */
.rpt-pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 24px;
}
.rpt-pagination a,
.rpt-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    font-size: 13px;
    border-radius: 6px;
    text-decoration: none;
    border: 1px solid #e0e0e0;
    color: #555;
    background: #fff;
    transition: background 0.15s;
}
.rpt-pagination a:hover {
    background: #f5f5f5;
    text-decoration: none;
}
.rpt-pagination .current {
    background: var(--mw-primary-blue, #3D6B6E);
    color: #fff;
    border-color: var(--mw-primary-blue, #3D6B6E);
}

/* レスポンシブ */
@media (max-width: 768px) {
    .rpt-archive-container {
        padding: 16px;
    }
    .rpt-archive-card {
        padding: 16px;
    }
    .rpt-archive-table th,
    .rpt-archive-table td {
        padding: 10px 8px;
        font-size: 13px;
    }
    /* モバイルではソース列を非表示 */
    .rpt-archive-table th:nth-child(4),
    .rpt-archive-table td:nth-child(4) {
        display: none;
    }
}
</style>

<div class="content-area">
    <div class="rpt-archive-container">
        <div class="rpt-archive-card">
            <div class="rpt-archive-header">
                <h2>月次レポート一覧</h2>
                <?php if ( $report_query->found_posts > 0 ) : ?>
                <span class="rpt-archive-count"><?php echo esc_html( $report_query->found_posts ); ?>件</span>
                <?php endif; ?>
            </div>

            <?php if ( empty( $report_list ) ) : ?>
            <!-- 空状態 -->
            <div class="rpt-empty-state">
                <div class="rpt-empty-icon">📄</div>
                <p>まだ月次レポートが保存されていません。</p>
                <p style="margin-top: 8px; font-size: 13px;">レポートが自動生成されると、こちらに一覧で表示されます。</p>
            </div>

            <?php else : ?>
            <!-- レポート一覧テーブル -->
            <table class="rpt-archive-table">
                <thead>
                    <tr>
                        <th>対象月</th>
                        <th>生成日時</th>
                        <th>状態</th>
                        <th>生成方法</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $report_list as $rpt ) :
                        // 年月の表示フォーマット（例: 2026年2月）
                        $ym_parts = explode( '-', $rpt['year_month'] );
                        $disp_ym  = count( $ym_parts ) === 2
                            ? intval( $ym_parts[0] ) . '年' . intval( $ym_parts[1] ) . '月'
                            : esc_html( $rpt['year_month'] );

                        // 状態ラベル
                        $is_finalized = ! empty( $rpt['finalized_at'] );
                        $state_label  = $is_finalized ? '確定' : '下書き';
                        $state_class  = $is_finalized ? 'finalized' : 'draft';

                        // 生成日時
                        $created_disp = '';
                        if ( $rpt['created_at'] ) {
                            $dt = new DateTimeImmutable( $rpt['created_at'], wp_timezone() );
                            $created_disp = $dt->format( 'Y/n/j H:i' );
                        }

                        // ソースラベル
                        $source_label = $rpt['source'] === 'manual' ? '手動' : '自動';

                        // リンク先: 最新月次レポートページに ym パラメータ付き
                        $view_url = add_query_arg( 'ym', $rpt['year_month'], home_url( '/report/report-latest/' ) );
                    ?>
                    <tr>
                        <td><span class="rpt-ym-label"><?php echo esc_html( $disp_ym ); ?></span></td>
                        <td><?php echo esc_html( $created_disp ); ?></td>
                        <td><span class="rpt-state-badge rpt-state-badge--<?php echo esc_attr( $state_class ); ?>"><?php echo esc_html( $state_label ); ?></span></td>
                        <td><span class="rpt-source-badge"><?php echo esc_html( $source_label ); ?></span></td>
                        <td style="text-align: right;">
                            <a href="<?php echo esc_url( $view_url ); ?>" class="rpt-view-btn">
                                詳細を見る →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // ページネーション
            if ( $total_pages > 1 ) :
            ?>
            <div class="rpt-pagination">
                <?php
                echo paginate_links( [
                    'total'     => $total_pages,
                    'current'   => $paged,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type'      => 'plain',
                ] );
                ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php get_footer(); ?>
