<?php
/*
Template Name: 戦略レポート履歴
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;

set_query_var( 'gcrev_page_title', '戦略レポート履歴' );
set_query_var( 'gcrev_page_subtitle', 'これまでにアップロードされた戦略レポートを一覧で閲覧できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '戦略レポート履歴', '戦略レポート' ) );

$versions = [];
if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
    try {
        $versions = Gcrev_Manual_Strategy_Report_Page::get_versions( $user_id );
    } catch ( \Throwable $e ) {
        if ( function_exists( 'file_put_contents' ) ) {
            file_put_contents(
                '/tmp/gcrev_strategy_report_debug.log',
                date( 'Y-m-d H:i:s' ) . ' [history] ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n",
                FILE_APPEND
            );
        }
        $versions = [];
    }
}

get_header();
?>
<div class="content-area" style="max-width:960px;margin:0 auto;padding:32px 24px 64px;">

    <header style="margin-bottom:28px;">
        <h2 style="font-size:22px;margin:0 0 6px;">📚 戦略レポート履歴</h2>
        <p style="color:#666;margin:0;line-height:1.7;">
            これまでに発行された戦略レポートの一覧です。新しいバージョンほど上に表示されます。<br>
            「簡易版を見る」「詳細版を見る」ボタンで、それぞれの版を別タブで開けます。
        </p>
    </header>

    <?php if ( empty( $versions ) ) : ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:48px 24px;text-align:center;color:#666;">
            <div style="font-size:36px;margin-bottom:14px;">📭</div>
            <p style="margin:0 0 8px;font-size:16px;font-weight:600;color:#333;">まだレポートが発行されていません</p>
            <p style="margin:0;font-size:13px;">担当者がレポートをアップロードすると、ここに表示されます。</p>
            <p style="margin-top:24px;">
                <a class="ss-btn ss-btn--primary" href="<?php echo esc_url( home_url( '/strategy-report/' ) ); ?>">← 戦略レポートに戻る</a>
            </p>
        </div>
    <?php else : ?>

        <div style="display:flex;flex-direction:column;gap:14px;">
            <?php foreach ( $versions as $idx => $v ) :
                $is_latest = ( $idx === 0 );
                $period    = (string) ( $v['period'] ?? '' );
                $label     = (string) ( $v['label'] ?? '' );
                $created   = (string) ( $v['created_at'] ?? '' );
                $simple_id = (int) ( $v['simple_id'] ?? 0 );
                $detail_id = (int) ( $v['detail_id'] ?? 0 );
                $ver_param = ! empty( $v['id'] ) ? '?ver=' . rawurlencode( $v['id'] ) : '';

                $period_label = '';
                if ( $period !== '' && preg_match( '/^(\d{4})-(\d{2})$/', $period, $m ) ) {
                    $period_label = $m[1] . '年' . (int) $m[2] . '月版';
                }
            ?>
                <div style="background:#fff;border:1px solid <?php echo $is_latest ? '#27ae60' : '#e2e8f0'; ?>;border-radius:10px;padding:20px 24px;display:flex;flex-wrap:wrap;align-items:center;gap:14px 24px;<?php echo $is_latest ? 'box-shadow:0 2px 6px rgba(39,174,96,0.08);' : ''; ?>">

                    <div style="flex:1;min-width:240px;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
                            <?php if ( $is_latest ) : ?>
                                <span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#27ae60;color:#fff;font-size:11px;font-weight:600;">最新</span>
                            <?php endif; ?>
                            <?php if ( $period_label !== '' ) : ?>
                                <strong style="font-size:16px;"><?php echo esc_html( $period_label ); ?></strong>
                            <?php endif; ?>
                            <?php if ( $label !== '' ) : ?>
                                <span style="color:#444;font-size:14px;"><?php echo esc_html( $label ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:12px;color:#888;">
                            アップロード日: <?php echo esc_html( $created ); ?>
                        </div>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if ( $simple_id ) : ?>
                            <a class="ss-btn ss-btn--primary" target="_blank" rel="noopener"
                               href="<?php echo esc_url( home_url( '/strategy-report/' . $ver_param ) ); ?>">📋 簡易版を見る</a>
                        <?php endif; ?>
                        <?php if ( $detail_id ) : ?>
                            <a class="ss-btn" target="_blank" rel="noopener"
                               style="background:#fff;color:#333;border:1px solid #ccc;"
                               href="<?php echo esc_url( home_url( '/strategy-report-detail/' . $ver_param ) ); ?>">📊 詳細版を見る</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <p style="margin-top:28px;text-align:center;">
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report/' ) ); ?>" style="background:#fff;color:#333;border:1px solid #ccc;">← 最新の戦略レポートに戻る</a>
        </p>

    <?php endif; ?>
</div>
<?php get_footer(); ?>
