<?php /*Template Name: サービスページ */ ?>
<?php
set_query_var('gcrev_page_title', '伴走サポート');
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('伴走サポート', 'オプションサービス'));
get_header();
?>

<div class="content-area">

    <!-- =============================
         1. ファーストビュー
         ============================= -->
    <section class="sv-section">
        <div class="sv-hero">
            <div class="sv-hero__label">Web運用伴走サポートサービス</div>
            <h1 class="sv-hero__heading">ホームページを作って終わりにしない、<br>運用伴走サポート</h1>
            <div class="sv-hero__brand">みまもりウェブ</div>
            <p class="sv-hero__sub">見える化・分析・改善・伴走で、ホームページを育てる仕組みを。</p>
            <div class="sv-hero__actions">
                <a href="<?php echo esc_url( home_url('/inquiry/') ); ?>" class="sv-btn sv-btn--primary">まずは相談する</a>
                <a href="#plans" class="sv-btn sv-btn--outline">プランを見る</a>
            </div>
        </div>
    </section>

    <!-- =============================
         2. よくある悩み
         ============================= -->
    <section class="sv-section">
        <h2 class="sv-section__title">こんなお悩みありませんか？</h2>
        <div class="sv-pain">
            <div class="sv-pain__item"><span>📊</span>効果が<br>見えていない</div>
            <div class="sv-pain__item"><span>🔧</span>改善方法が<br>わからない</div>
            <div class="sv-pain__item"><span>💬</span>相談できる人が<br>いない</div>
            <div class="sv-pain__item"><span>⏸️</span>更新が<br>止まっている</div>
            <div class="sv-pain__item"><span>🌐</span>ホームページを<br>活かしきれていない</div>
        </div>
    </section>

    <!-- =============================
         3. みまもりウェブでできること
         ============================= -->
    <section class="sv-section">
        <h2 class="sv-section__title">みまもりウェブでできること</h2>
        <p class="sv-section__subtitle">4つの柱で、ホームページの成果を最大化します</p>
        <div class="sv-features">
            <div class="sv-feature">
                <div class="sv-feature__icon">👁️</div>
                <div class="sv-feature__title">見える化</div>
                <div class="sv-feature__desc">アクセス状況をダッシュボードでいつでも確認。数字の見方に迷いません。</div>
            </div>
            <div class="sv-feature">
                <div class="sv-feature__icon">🤖</div>
                <div class="sv-feature__title">AI分析</div>
                <div class="sv-feature__desc">AIが自動でデータを分析。毎月のレポートと改善ポイントを提案します。</div>
            </div>
            <div class="sv-feature">
                <div class="sv-feature__icon">💡</div>
                <div class="sv-feature__title">改善提案</div>
                <div class="sv-feature__desc">何をすればいいか具体的にアドバイス。優先順位もわかります。</div>
            </div>
            <div class="sv-feature">
                <div class="sv-feature__icon">🤝</div>
                <div class="sv-feature__title">伴走支援</div>
                <div class="sv-feature__desc">専門スタッフが一緒に考え、改善を継続的にサポートします。</div>
            </div>
        </div>
    </section>

    <!-- =============================
         4. プラン比較表（最重要）
         ============================= -->
    <section class="sv-section" id="plans">
        <h2 class="sv-section__title">プラン比較</h2>
        <p class="sv-section__subtitle">ご状況に合わせて、3つのプランからお選びいただけます</p>

        <div class="sv-plans-section">

            <!-- デスクトップ: テーブル形式 -->
            <table class="sv-plan-table">
                <thead>
                    <tr>
                        <th class="sv-plan-header">&nbsp;</th>
                        <th class="sv-plan-header">
                            <div class="sv-plan-name">ベーシックプラン</div>
                            <div class="sv-plan-price">5,500<small>円/月（税込）</small></div>
                            <div class="sv-plan-tagline">まず始めたい方に</div>
                        </th>
                        <th class="sv-plan-header sv-plan-header--recommended">
                            <div class="sv-recommended-badge">おすすめ</div>
                            <div class="sv-plan-name">AIサポートプラン</div>
                            <div class="sv-plan-price">11,000<small>円/月（税込）</small></div>
                            <div class="sv-plan-tagline">AIの力で改善を加速</div>
                        </th>
                        <th class="sv-plan-header sv-plan-header--premium">
                            <div class="sv-plan-name">伴走プラン</div>
                            <div class="sv-plan-price">33,000<small>円/月（税込）</small></div>
                            <div class="sv-plan-tagline">手厚い専門支援</div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>サイトの見える化</td>
                        <td><span class="sv-check">◯</span></td>
                        <td class="sv-col-recommended"><span class="sv-check">◯</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                    <tr>
                        <td>アクセスダッシュボード</td>
                        <td><span class="sv-check">◯</span></td>
                        <td class="sv-col-recommended"><span class="sv-check">◯</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                    <tr>
                        <td>AI分析レポート</td>
                        <td><span class="sv-cross">—</span></td>
                        <td class="sv-col-recommended"><span class="sv-check">◯</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                    <tr>
                        <td>AI改善提案</td>
                        <td><span class="sv-cross">—</span></td>
                        <td class="sv-col-recommended"><span class="sv-check">◯</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                    <tr>
                        <td>AIチャット相談</td>
                        <td><span class="sv-cross">—</span></td>
                        <td class="sv-col-recommended"><span class="sv-check">◯</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                    <tr>
                        <td>定期ミーティング</td>
                        <td><span class="sv-cross">—</span></td>
                        <td class="sv-col-recommended"><span class="sv-cross">—</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                    <tr>
                        <td>専門スタッフの伴走支援</td>
                        <td><span class="sv-cross">—</span></td>
                        <td class="sv-col-recommended"><span class="sv-cross">—</span></td>
                        <td><span class="sv-check">◯</span></td>
                    </tr>
                </tbody>
            </table>

            <!-- スマホ: カード形式 -->
            <div class="sv-plan-cards">

                <!-- ベーシック -->
                <div class="sv-plan-card">
                    <div class="sv-plan-card__header">
                        <div class="sv-plan-card__name">ベーシックプラン</div>
                        <div class="sv-plan-card__price">5,500<small>円/月（税込）</small></div>
                        <div class="sv-plan-card__tagline">まず始めたい方に</div>
                    </div>
                    <div class="sv-plan-card__body">
                        <ul class="sv-plan-card__list">
                            <li>サイトの見える化<span class="sv-check">◯</span></li>
                            <li>アクセスダッシュボード<span class="sv-check">◯</span></li>
                            <li>AI分析レポート<span class="sv-cross">—</span></li>
                            <li>AI改善提案<span class="sv-cross">—</span></li>
                            <li>AIチャット相談<span class="sv-cross">—</span></li>
                            <li>定期ミーティング<span class="sv-cross">—</span></li>
                            <li>専門スタッフの伴走支援<span class="sv-cross">—</span></li>
                        </ul>
                    </div>
                </div>

                <!-- AIサポート（おすすめ） -->
                <div class="sv-plan-card sv-plan-card--recommended">
                    <div class="sv-plan-card__header">
                        <div class="sv-plan-card__badge">おすすめ</div>
                        <div class="sv-plan-card__name">AIサポートプラン</div>
                        <div class="sv-plan-card__price">11,000<small>円/月（税込）</small></div>
                        <div class="sv-plan-card__tagline">AIの力で改善を加速</div>
                    </div>
                    <div class="sv-plan-card__body">
                        <ul class="sv-plan-card__list">
                            <li>サイトの見える化<span class="sv-check">◯</span></li>
                            <li>アクセスダッシュボード<span class="sv-check">◯</span></li>
                            <li>AI分析レポート<span class="sv-check">◯</span></li>
                            <li>AI改善提案<span class="sv-check">◯</span></li>
                            <li>AIチャット相談<span class="sv-check">◯</span></li>
                            <li>定期ミーティング<span class="sv-cross">—</span></li>
                            <li>専門スタッフの伴走支援<span class="sv-cross">—</span></li>
                        </ul>
                    </div>
                </div>

                <!-- 伴走プラン -->
                <div class="sv-plan-card sv-plan-card--premium">
                    <div class="sv-plan-card__header">
                        <div class="sv-plan-card__name">伴走プラン</div>
                        <div class="sv-plan-card__price">33,000<small>円/月（税込）</small></div>
                        <div class="sv-plan-card__tagline">手厚い専門支援</div>
                    </div>
                    <div class="sv-plan-card__body">
                        <ul class="sv-plan-card__list">
                            <li>サイトの見える化<span class="sv-check">◯</span></li>
                            <li>アクセスダッシュボード<span class="sv-check">◯</span></li>
                            <li>AI分析レポート<span class="sv-check">◯</span></li>
                            <li>AI改善提案<span class="sv-check">◯</span></li>
                            <li>AIチャット相談<span class="sv-check">◯</span></li>
                            <li>定期ミーティング<span class="sv-check">◯</span></li>
                            <li>専門スタッフの伴走支援<span class="sv-check">◯</span></li>
                        </ul>
                    </div>
                </div>

            </div>

            <!-- 補足 -->
            <ul class="sv-plan-notes">
                <li>このサービスはホームページ制作費を含みません</li>
                <li>既存サイトを活用する運用支援サービスです</li>
                <li>必要に応じてプラン変更が可能です</li>
                <li>ページ改修などの作業は別途費用になる場合があります</li>
            </ul>

        </div>
    </section>

    <!-- =============================
         5. 導入フロー
         ============================= -->
    <section class="sv-section">
        <h2 class="sv-section__title">導入の流れ</h2>
        <p class="sv-section__subtitle">5つのステップで、かんたんにスタートできます</p>

        <div class="sv-flow">
            <div class="sv-flow__step">
                <div class="sv-flow__num">1</div>
                <div class="sv-flow__line"></div>
                <div class="sv-flow__content">
                    <div class="sv-flow__title">お問い合わせ</div>
                    <div class="sv-flow__desc">まずはお気軽にご相談ください。</div>
                </div>
            </div>
            <div class="sv-flow__step">
                <div class="sv-flow__num">2</div>
                <div class="sv-flow__line"></div>
                <div class="sv-flow__content">
                    <div class="sv-flow__title">ヒアリング</div>
                    <div class="sv-flow__desc">現状のお悩みや目標をお伺いします。</div>
                </div>
            </div>
            <div class="sv-flow__step">
                <div class="sv-flow__num">3</div>
                <div class="sv-flow__line"></div>
                <div class="sv-flow__content">
                    <div class="sv-flow__title">初期設定</div>
                    <div class="sv-flow__desc">アクセス解析の接続やダッシュボードの準備を行います。</div>
                </div>
            </div>
            <div class="sv-flow__step">
                <div class="sv-flow__num">4</div>
                <div class="sv-flow__line"></div>
                <div class="sv-flow__content">
                    <div class="sv-flow__title">見える化スタート</div>
                    <div class="sv-flow__desc">データの可視化が始まり、現状が一目でわかるようになります。</div>
                </div>
            </div>
            <div class="sv-flow__step">
                <div class="sv-flow__num">5</div>
                <div class="sv-flow__content">
                    <div class="sv-flow__title">改善提案・運用開始</div>
                    <div class="sv-flow__desc">分析結果をもとに、改善サイクルがスタートします。</div>
                </div>
            </div>
        </div>
    </section>

    <!-- =============================
         6. FAQ
         ============================= -->
    <section class="sv-section">
        <h2 class="sv-section__title">よくある質問</h2>

        <div class="sv-faq">
            <div class="sv-faq__item">
                <div class="sv-faq__q" onclick="this.parentElement.classList.toggle('active')">
                    今あるホームページでも使えますか？
                    <span>▼</span>
                </div>
                <div class="sv-faq__a">
                    はい、既存のホームページをそのまま活用いただけます。Googleアナリティクス（GA4）とSearch Consoleの接続ができれば、すぐに見える化を始められます。
                </div>
            </div>
            <div class="sv-faq__item">
                <div class="sv-faq__q" onclick="this.parentElement.classList.toggle('active')">
                    Web担当者がいなくても大丈夫ですか？
                    <span>▼</span>
                </div>
                <div class="sv-faq__a">
                    はい、大丈夫です。専門知識がなくてもわかりやすいダッシュボードとレポートをご提供しています。伴走プランなら専門スタッフが一緒に考えますので、安心してお任せいただけます。
                </div>
            </div>
            <div class="sv-faq__item">
                <div class="sv-faq__q" onclick="this.parentElement.classList.toggle('active')">
                    途中でプラン変更できますか？
                    <span>▼</span>
                </div>
                <div class="sv-faq__a">
                    はい、いつでもプラン変更が可能です。まずはベーシックプランから始めて、必要に応じてアップグレードされる方が多いです。
                </div>
            </div>
            <div class="sv-faq__item">
                <div class="sv-faq__q" onclick="this.parentElement.classList.toggle('active')">
                    どのプランから始めるのがおすすめですか？
                    <span>▼</span>
                </div>
                <div class="sv-faq__a">
                    まずはAIサポートプランがおすすめです。AIによる分析レポートと改善提案が含まれており、コストパフォーマンスに優れています。より手厚いサポートが必要な場合は、伴走プランをご検討ください。
                </div>
            </div>
        </div>
    </section>

    <!-- =============================
         7. CTA
         ============================= -->
    <section class="sv-section">
        <div class="sv-cta">
            <h2 class="sv-cta__heading">まずは今のホームページの<br>状況確認から始めませんか？</h2>
            <a href="<?php echo esc_url( home_url('/inquiry/') ); ?>" class="sv-btn sv-btn--primary">お問い合わせする</a>
        </div>
    </section>

</div>

<?php get_footer(); ?>
