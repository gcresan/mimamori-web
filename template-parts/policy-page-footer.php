<?php
/**
 * 公開ポリシーページ共通フッター（3ページ間の相互リンク + コピーライト）
 */
?>
<footer class="policy-page-footer">
    <nav class="policy-footer-links">
        <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">プライバシーポリシー</a>
        <span class="sep">｜</span>
        <a href="<?php echo esc_url( home_url( '/terms-of-service/' ) ); ?>">利用規約</a>
        <span class="sep">｜</span>
        <a href="<?php echo esc_url( home_url( '/user-data-deletion/' ) ); ?>">データ削除について</a>
    </nav>
    <p class="policy-copyright">&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
</footer>
