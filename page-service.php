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
         4. プラン紹介（最重要）
         ============================= -->
    <section class="sv-section" id="plans">
        <h2 class="sv-section__title">プラン紹介</h2>
        <p class="sv-section__subtitle">ご状況に合わせて、4つのプランからお選びいただけます</p>

        <style>
        .sv-plans-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .sv-plan2 {
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 12px;
            padding: 28px 20px;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .sv-plan2--recommended {
            border: 2px solid #4ECDC4;
            box-shadow: 0 4px 20px rgba(78,205,196,0.10);
        }
        .sv-plan2__badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: #4ECDC4;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 16px;
            border-radius: 20px;
            letter-spacing: 0.05em;
        }
        .sv-plan2__name {
            font-size: 17px;
            font-weight: 700;
            color: #333;
            text-align: center;
            margin-bottom: 8px;
        }
        .sv-plan2__subtitle {
            font-size: 12px;
            color: #888;
            line-height: 1.6;
            text-align: center;
            margin-bottom: 14px;
        }
        .sv-plan2__price {
            text-align: center;
            padding-bottom: 18px;
            border-bottom: 1px solid #f0f0f0;
            margin-bottom: 16px;
        }
        .sv-plan2__price-amount {
            font-size: 30px;
            font-weight: 800;
            color: #333;
        }
        .sv-plan2__price-unit {
            font-size: 13px;
            color: #888;
            margin-left: 2px;
        }
        .sv-plan2__cumulative {
            color: #4ECDC4;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .sv-plan2__cumulative::before {
            content: "+ ";
            font-weight: 800;
        }
        .sv-plan2__features {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sv-plan2__features li {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 7px 0;
            font-size: 13px;
            line-height: 1.5;
            color: #444;
            border-bottom: 1px solid #f8f8f8;
        }
        .sv-plan2__features li:last-child { border-bottom: none; }
        .sv-plan2__features li::before {
            content: "✓";
            color: #4ECDC4;
            font-weight: 700;
            flex-shrink: 0;
        }
        @media (max-width: 1200px) {
            .sv-plans-grid {
                grid-template-columns: repeat(2, 1fr);
                max-width: 720px;
                margin-left: auto;
                margin-right: auto;
            }
        }
        @media (max-width: 700px) {
            .sv-plans-grid {
                grid-template-columns: 1fr;
                max-width: 440px;
            }
            .sv-plan2--recommended { order: -1; }
        }
        </style>

        <div class="sv-plans-section">

            <div class="sv-plans-grid">
                <!-- プラン1: AI分析・レポートプラン -->
                <div class="sv-plan2">
                    <div class="sv-plan2__name">AI分析・レポートプラン</div>
                    <div class="sv-plan2__subtitle">AIがデータを分析し<br>サイトの状態をレポート化</div>
                    <div class="sv-plan2__price">
                        <span class="sv-plan2__price-amount">11,000</span><span class="sv-plan2__price-unit">円 / 月（税込）</span>
                    </div>
                    <ul class="sv-plan2__features">
                        <li>サイト健康スコア</li>
                        <li>アクセス状況ダッシュボード</li>
                        <li>基本データの可視化</li>
                        <li>月次レポート総評</li>
                        <li>基本保守・管理</li>
                        <li>AI改善アクション提示</li>
                        <li>AIチャット相談</li>
                    </ul>
                </div>

                <!-- プラン2: MEO・口コミ対策プラン（おすすめ） -->
                <div class="sv-plan2 sv-plan2--recommended">
                    <span class="sv-plan2__badge">★ おすすめ</span>
                    <div class="sv-plan2__name">MEO・口コミ対策プラン</div>
                    <div class="sv-plan2__subtitle">MEO対策や口コミ獲得で<br>地域集客を強化</div>
                    <div class="sv-plan2__price">
                        <span class="sv-plan2__price-amount">22,000</span><span class="sv-plan2__price-unit">円 / 月（税込）</span>
                    </div>
                    <div class="sv-plan2__cumulative">AI分析・レポートに加え</div>
                    <ul class="sv-plan2__features">
                        <li>MEOダッシュボード</li>
                        <li>口コミ返信機能</li>
                        <li>口コミアンケート＋AI機能</li>
                        <li>MEO運用代行</li>
                    </ul>
                </div>

                <!-- プラン3: コンテンツSEO強化プラン -->
                <div class="sv-plan2">
                    <div class="sv-plan2__name">コンテンツSEO強化プラン</div>
                    <div class="sv-plan2__subtitle">コンテンツSEOを強化し<br>検索流入を拡大</div>
                    <div class="sv-plan2__price">
                        <span class="sv-plan2__price-amount">44,000</span><span class="sv-plan2__price-unit">円 / 月（税込）</span>
                    </div>
                    <div class="sv-plan2__cumulative">MEO・口コミ対策に加え</div>
                    <ul class="sv-plan2__features">
                        <li>コンテンツSEO機能</li>
                        <li>キーワード調査・競合分析</li>
                        <li>コラム記事作成機能</li>
                        <li>一次情報ストック機能</li>
                    </ul>
                </div>

                <!-- プラン4: プロ伴走・改善実行プラン -->
                <div class="sv-plan2">
                    <div class="sv-plan2__name">プロ伴走・改善実行プラン</div>
                    <div class="sv-plan2__subtitle">プロが伴走し<br>改善施策の実行まで全面サポート</div>
                    <div class="sv-plan2__price">
                        <span class="sv-plan2__price-amount">110,000</span><span class="sv-plan2__price-unit">円 / 月（税込）</span>
                    </div>
                    <div class="sv-plan2__cumulative">コンテンツSEO強化に加え</div>
                    <ul class="sv-plan2__features">
                        <li>改善指示に基づく作業</li>
                        <li>専門スタッフ伴走支援</li>
                        <li>定例ミーティング</li>
                    </ul>
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
                    はい、大丈夫です。専門知識がなくてもわかりやすいダッシュボードとレポートをご提供しています。プロ伴走・改善実行プランなら専門スタッフが一緒に考えますので、安心してお任せいただけます。
                </div>
            </div>
            <div class="sv-faq__item">
                <div class="sv-faq__q" onclick="this.parentElement.classList.toggle('active')">
                    途中でプラン変更できますか？
                    <span>▼</span>
                </div>
                <div class="sv-faq__a">
                    はい、いつでもプラン変更が可能です。まずはAI分析・レポートプランから始めて、必要に応じてアップグレードされる方が多いです。
                </div>
            </div>
            <div class="sv-faq__item">
                <div class="sv-faq__q" onclick="this.parentElement.classList.toggle('active')">
                    どのプランから始めるのがおすすめですか？
                    <span>▼</span>
                </div>
                <div class="sv-faq__a">
                    まずはMEO・口コミ対策プランがおすすめです。AI分析に加えて地域集客の強化機能が含まれており、コストパフォーマンスに優れています。より手厚いサポートが必要な場合は、プロ伴走・改善実行プランをご検討ください。
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
