<?php
/**
 * 公開ポリシーページ（プライバシーポリシー / 利用規約 / データ削除）共通スタイル
 *
 * page-login.php と同系のトーン（背景 #F2F1EC、カード #FAF9F6、アクセント #568184）。
 */
?>
<style>
body.policy-page {
    font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Yu Gothic', 'Noto Sans JP', sans-serif;
    background: #F2F1EC;
    color: #2B2B2B;
    line-height: 1.8;
    margin: 0;
    padding: 0;
    -webkit-font-smoothing: antialiased;
}
body.policy-page * { box-sizing: border-box; }

.policy-wrapper {
    width: 92%;
    max-width: 820px;
    margin: 40px auto;
}

.policy-header {
    text-align: center;
    margin-bottom: 28px;
}
.policy-logo-link {
    display: inline-block;
    text-decoration: none;
}
.policy-logo-link img {
    max-width: 200px;
    width: auto;
    height: auto;
}

.policy-main {
    background: #FAF9F6;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(43, 43, 43, 0.06);
    padding: 40px 44px;
}

.policy-title {
    font-size: 26px;
    font-weight: 700;
    color: #2B2B2B;
    margin: 0 0 8px;
    padding-bottom: 16px;
    border-bottom: 2px solid #E5E3DC;
}
.policy-updated {
    margin: 0 0 24px;
    font-size: 13px;
    color: #8C8A85;
}
.policy-lead {
    font-size: 15px;
    margin: 0 0 28px;
    color: #444;
}

.policy-section {
    margin-bottom: 28px;
}
.policy-section h2 {
    font-size: 17px;
    font-weight: 700;
    color: #3D4B5C;
    margin: 0 0 12px;
    padding-left: 10px;
    border-left: 4px solid #568184;
}
.policy-section h3 {
    font-size: 15px;
    font-weight: 700;
    color: #3D4B5C;
    margin: 18px 0 8px;
}
.policy-section p {
    font-size: 14.5px;
    margin: 0 0 12px;
    color: #2B2B2B;
}
.policy-section ul,
.policy-section ol {
    font-size: 14.5px;
    margin: 8px 0 14px;
    padding-left: 1.4em;
    color: #2B2B2B;
}
.policy-section li {
    margin-bottom: 6px;
}
.policy-section a {
    color: #568184;
    text-decoration: underline;
}
.policy-section a:hover {
    color: #3D4B5C;
}
.policy-section strong {
    font-weight: 700;
}

.policy-callout {
    background: #EEEDEA;
    border-left: 4px solid #568184;
    padding: 14px 18px;
    margin: 14px 0;
    font-size: 14px;
    color: #444;
    border-radius: 4px;
}

.policy-contact {
    background: #EEEDEA;
    padding: 14px 18px;
    border-radius: 4px;
    font-size: 14px;
    margin: 8px 0 0;
}

.policy-page-footer {
    margin-top: 32px;
    padding: 20px 16px;
    text-align: center;
}
.policy-footer-links {
    font-size: 13px;
    color: #8C8A85;
    margin-bottom: 8px;
}
.policy-footer-links a {
    color: #568184;
    text-decoration: none;
    margin: 0 4px;
}
.policy-footer-links a:hover {
    color: #3D4B5C;
    text-decoration: underline;
}
.policy-footer-links .sep {
    color: #C8C6BF;
    margin: 0 2px;
}
.policy-copyright {
    font-size: 12px;
    color: #B0AEA8;
    margin: 8px 0 0;
}

@media (max-width: 600px) {
    .policy-wrapper { width: 94%; margin: 24px auto; }
    .policy-main { padding: 28px 20px; }
    .policy-title { font-size: 22px; }
    .policy-section h2 { font-size: 16px; }
    .policy-section p,
    .policy-section ul,
    .policy-section ol { font-size: 14px; }
}
</style>
