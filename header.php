<!DOCTYPE html>
<html <?php language_attributes(); ?>>
   <head>
      <!-- Google Tag Manager -->
      <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
      new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
      j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
      'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
      })(window,document,'script','dataLayer','GTM-M5HWSJN3');</script>
      <!-- End Google Tag Manager -->
      <!-- Microsoft Clarity -->
      <script type="text/javascript">
          (function(c,l,a,r,i,t,y){
              c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
              t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
              y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
          })(window, document, "clarity", "script", "wh2uf03d6x");
      </script>
      <!-- End Microsoft Clarity -->
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <title><?php wp_title(' | ', true, 'right'); bloginfo('name'); ?></title>
      <link rel="icon" type="image/x-icon" href="<?php echo get_template_directory_uri(); ?>/images/favicon.ico">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@400;500;700&family=Noto+Sans+JP:wght@400;500;600;700;900&display=swap" rel="stylesheet">
      <link rel="stylesheet" type="text/css" href="<?php echo get_template_directory_uri(); ?>/css/import.css?v=1.6.1" media="all">
      <?php get_template_part( 'kaiseki' ); ?>
      <?php wp_head(); ?>
   </head>
<?php if(is_home() && !is_paged()): ?>
<body class="page blog">
<?php elseif ( is_front_page() ) : ?>
<body class="login">
<?php elseif ( is_page('8') ) : ?>
<body class="page dashboard">
<?php elseif ( is_page('13') ) : ?>
<body class="page account">
<?php elseif ( is_page('75') ) : ?>
<body class="page analysis">
<?php elseif ( is_page('21') ) : ?>
<body class="page service">
<?php else : ?>
<!-- <body class="page blog"> -->
<body <?php body_class(); ?>>
<?php endif; ?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-M5HWSJN3"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

   <!-- サイドバーオーバーレイ(モバイル用) -->
   <div class="sidebar-overlay" id="sidebarOverlay"></div>
   <div class="app-container">
   <!-- サイドバー -->
   <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
         <div class="logo-area">
            <a href="<?php echo esc_url( home_url() ); ?>">
               <img src="<?php echo esc_url( get_template_directory_uri() . '/images/common/logo.png' ); ?>" width="518" height="341" alt="みまもりウェブ">
            </a>
            <p class="logo-subcatch">みまもるから、次が見える。</p>
         </div>
         <?php if ( is_user_logged_in() ) :
            $u = mimamori_get_view_user_object();

            // 表示名優先 → 姓
            $company = gcrev_get_business_name( $u->ID );

            // 本部ログイン中なら、表示中の店舗を強調表示
            $_hdr_is_hq = function_exists( 'mimamori_is_hq_user' ) && mimamori_is_hq_user( get_current_user_id() );
            ?>
         <div class="sidebar-user-info<?php echo $_hdr_is_hq ? ' sidebar-user-info--hq' : ''; ?>"
              <?php if ( $_hdr_is_hq ) : ?>style="background:linear-gradient(135deg,#fff8ec 0%,#fefdfb 100%);border-left:4px solid #d97706;padding:14px 20px;"<?php endif; ?>>
            <?php if ( $_hdr_is_hq ) : ?>
            <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#92400e;letter-spacing:0.04em;margin-bottom:4px;">
               <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                  <circle cx="12" cy="10" r="3"></circle>
               </svg>
               <span>現在表示中の店舗</span>
            </div>
            <?php endif; ?>
            <div class="sidebar-user-name"<?php if ( $_hdr_is_hq ) : ?> style="font-size:15px;font-weight:700;color:#1a1a1a;"<?php endif; ?>>
               <?php echo esc_html( $company ); ?> 様
            </div>
            <?php
            // 解析対象サイトURL（新→旧フォールバック）
            $site_url = get_user_meta( $u->ID, 'gcrev_client_site_url', true )
                      ?: get_user_meta( $u->ID, 'report_site_url', true )
                      ?: get_user_meta( $u->ID, 'weisite_url', true );
            $maps_domain = get_user_meta( $u->ID, '_gcrev_maps_domain', true );

            // ドメイン正規化して比較
            $site_domain_norm = '';
            if ( $site_url ) {
                $parsed = wp_parse_url( $site_url );
                $site_domain_norm = isset( $parsed['host'] ) ? strtolower( preg_replace( '/^www\./', '', $parsed['host'] ) ) : '';
            }
            $maps_norm = $maps_domain ? strtolower( preg_replace( '/^www\./', '', $maps_domain ) ) : '';
            $is_separated = ( $maps_norm !== '' && $maps_norm !== $site_domain_norm );

            if ( $site_url ) :
               $display_url = preg_replace( '#^https?://#', '', untrailingslashit( $site_url ) );
            ?>
            <?php if ( $is_separated ) : ?>
            <div class="sidebar-user-plan" style="line-height: 1.5;">
               <div style="display: flex; align-items: baseline; gap: 4px; font-size: 11px;">
                  <span style="color: #888; white-space: nowrap;">解析:</span>
                  <a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer"
                     style="color: inherit; text-decoration: none;"><?php echo esc_html( $display_url ); ?></a>
               </div>
               <div style="display: flex; align-items: baseline; gap: 4px; font-size: 11px; margin-top: 2px;">
                  <span style="color: #888; white-space: nowrap;">MEO:</span>
                  <span style="color: inherit;"><?php echo esc_html( $maps_domain ); ?></span>
               </div>
            </div>
            <?php else : ?>
            <div class="sidebar-user-plan">
               <a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer"
                  style="color: inherit; text-decoration: none;"><?php echo esc_html( $display_url ); ?></a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ( function_exists( 'gcrev_is_trial_active' ) && gcrev_is_trial_active( $u->ID ) ) : ?>
            <div style="margin-top: 6px; display: flex; align-items: center; gap: 6px; flex-wrap: nowrap;">
               <span style="display: inline-block; padding: 2px 8px; font-size: 11px; font-weight: 600; color: #d97706; background: rgba(217,119,6,0.08); border: 1px solid rgba(217,119,6,0.2); border-radius: 4px; letter-spacing: 0.05em; white-space: nowrap;">お試し中</span>
               <?php
                  $trial_end_display = gcrev_get_trial_end_display( $u->ID );
                  if ( $trial_end_display ) :
               ?>
               <span style="font-size: 11px; color: #999; white-space: nowrap;"><?php echo esc_html( $trial_end_display ); ?>まで</span>
               <?php endif; ?>
            </div>
            <?php endif; ?>
         </div>
         <?php endif; ?>
      </div>
      <?php
      // --- アコーディオン初期状態: 子ページがアクティブな親を開く / なければ全部閉じ ---
      // ホームページグループ: 現状診断・チャットボット・サイト分析・SEO
      $home_pages = array(
          'page-analysis', 'chatbot',
          // サイト分析
          'site-dashboard','analysis-device','analysis-age','analysis-source','analysis-region','analysis-pages','analysis-keywords','analysis-cv','inquiries',
          // SEO
          'seo-check','ai-report','rank-tracker','keyword-research',
      );
      // レポートグループ: 深掘りレポート・月次レポート・MEOレポート（年次レポートは非表示中）
      $report_pages = array(
          'strategy-report','strategy-report-detail','strategy-report-history',
          'report-latest','report-archive','annual-report',
          'meo-report',
      );
      // MEOグループ: MEO関連 (レポート系は report グループへ移管)
      $meo_pages = array(
          'meo-dashboard','map-rank','meo-diagnosis','meo-diagnosis-detail','meo-search-terms',
          'review-survey','survey-responses','survey-analytics','survey-analysis','survey-ai-history',
          'review-management','gbp-posts',
      );
      $settings_pages  = array('client-settings','keyword-settings','report-settings','cv-review','notifications','account-info');
      $support_pages   = array('faq','tutorials','inquiry');
      $option_pages    = array('service','improvement-request','training','ad-consulting','meeting-reservation');

      $home_child_active = false;
      foreach ($home_pages as $_p) { if (is_page($_p)) { $home_child_active = true; break; } }
      $report_child_active = false;
      foreach ($report_pages as $_p) { if (is_page($_p)) { $report_child_active = true; break; } }
      $meo_child_active = false;
      foreach ($meo_pages as $_p) { if (is_page($_p)) { $meo_child_active = true; break; } }
      $settings_child_active = false;
      foreach ($settings_pages as $_p) { if (is_page($_p)) { $settings_child_active = true; break; } }
      $support_child_active = false;
      foreach ($support_pages as $_p) { if (is_page($_p)) { $support_child_active = true; break; } }
      $option_child_active = false;
      foreach ($option_pages as $_p) { if (is_page($_p)) { $option_child_active = true; break; } }

      // 子がアクティブなグループを開く。なければ全部閉じた状態
      $sidebar_active_group = '';
      if ($home_child_active)     $sidebar_active_group = 'home';
      if ($report_child_active)   $sidebar_active_group = 'report';
      if ($meo_child_active)      $sidebar_active_group = 'meo';
      if ($settings_child_active) $sidebar_active_group = 'settings';
      if ($support_child_active)  $sidebar_active_group = 'support';
      if ($option_child_active)   $sidebar_active_group = 'option';
      ?>
      <nav class="sidebar-nav">
         <div class="nav-section">
            <ul class="nav-menu">

               <?php
               // 本部アカウント: サイドバー上部に「店舗一覧へ戻る」+「別の店舗を選択」セレクタを表示
               $_hq_uid = get_current_user_id();
               $_is_hq  = function_exists( 'mimamori_is_hq_user' ) && mimamori_is_hq_user( $_hq_uid );
               if ( $_is_hq ) :
                   $_hq_managed   = mimamori_get_hq_managed_user_ids( $_hq_uid );
                   $_hq_current   = mimamori_get_hq_view_target_for_current_user();
                   $_hq_redirect  = ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/dashboard/' );
               ?>
               <li class="nav-item">
                  <a href="<?php echo esc_url( home_url('/hq/') ); ?>" class="nav-link <?php echo is_page('hq') ? 'active' : ''; ?>">
                     <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 21h18M5 21V8l7-5 7 5v13M9 9h6M9 13h6M9 17h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                     <span>店舗一覧へ戻る</span>
                  </a>
               </li>
               <?php if ( count( $_hq_managed ) > 1 ) : ?>
               <li class="nav-item nav-hq-switch" style="padding: 6px 24px 12px;">
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin:0;">
                     <?php wp_nonce_field( 'mimamori_hq_view_set' ); ?>
                     <input type="hidden" name="action" value="mimamori_hq_view_set">
                     <input type="hidden" name="redirect" value="<?php echo esc_attr( $_hq_redirect ); ?>">
                     <label for="nav-hq-target" style="display:block; font-size:11px; color:#888; margin-bottom:4px;">別の店舗を選択</label>
                     <select id="nav-hq-target" name="target_user_id" onchange="this.form.submit()"
                             style="width:100%; padding:6px 8px; border:1px solid #c3ced0; border-radius:6px; background:#fff; font-size:13px; cursor:pointer;">
                        <?php foreach ( $_hq_managed as $mid ) :
                           $mbn = function_exists( 'gcrev_get_business_name' ) ? (string) gcrev_get_business_name( $mid ) : '';
                           if ( $mbn === '' ) {
                              $mu = get_userdata( $mid );
                              $mbn = $mu ? $mu->display_name : (string) $mid;
                           }
                        ?>
                           <option value="<?php echo (int) $mid; ?>" <?php selected( $_hq_current, (int) $mid ); ?>>
                              <?php echo esc_html( $mbn ); ?>
                           </option>
                        <?php endforeach; ?>
                     </select>
                  </form>
               </li>
               <?php endif; ?>
               <?php endif; ?>

               <?php
               // MEO特化プラン判定 — 全体ダッシュボード・ホームページ・通常のレポート項目を非表示にする
               $_mb_is_meo_only = function_exists( 'mimamori_is_meo_only_user' ) ? mimamori_is_meo_only_user() : false;
               ?>

               <!-- ========== 全体ダッシュボード（単独・MEO特化プランは非表示） ========== -->
               <?php if ( ! $_mb_is_meo_only ) : ?>
               <li class="nav-item">
                  <a href="<?php echo esc_url( home_url('/dashboard/') ); ?>" class="nav-link <?php echo is_page('dashboard') ? 'active' : ''; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3C6 3 2.7 5.9 1.5 10c1.2 4.1 4.5 7 8.5 7s7.3-2.9 8.5-7C17.3 5.9 14 3 10 3Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/></svg></span>
                  <span>全体ダッシュボード</span>
                  </a>
               </li>
               <?php endif; ?>

               <!-- ========== レポート（大カテゴリ） ========== -->
               <li class="nav-item nav-item-collapsible<?php echo $report_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'report' ? ' collapsed' : ''; ?>" data-menu-key="report">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleReport" aria-expanded="<?php echo $sidebar_active_group === 'report' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M14 2v6h6M8 13h8M8 17h8M8 9h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <span>レポート</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuReport">
                     <?php if ( ! $_mb_is_meo_only ) : ?>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/strategy-report-history/') ); ?>" class="nav-link <?php echo ( is_page('strategy-report') || is_page('strategy-report-detail') || is_page('strategy-report-history') ) ? 'active' : ''; ?>">
                        <span>深掘りレポート</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/report/report-latest/') ); ?>" class="nav-link <?php echo ( is_page('report-latest') || is_page('report-archive') ) ? 'active' : ''; ?>">
                        <span>月次レポート</span>
                        </a>
                     </li>
                     <?php endif; ?>
                     <?php if ( mimamori_can_access_meo() ) : ?>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/meo-report/') ); ?>" class="nav-link <?php echo is_page('meo-report') ? 'active' : ''; ?>">
                        <span>MEOレポート</span>
                        </a>
                     </li>
                     <?php endif; ?>
                     <?php /* 年次レポートは一旦非表示
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/annual-report/') ); ?>" class="nav-link <?php echo is_page('annual-report') ? 'active' : ''; ?>">
                        <span>年次レポート</span>
                        </a>
                     </li>
                     */ ?>
                  </ul>
               </li>

               <?php
               // オプション機能の表示判定 (HPグループ内で利用)
               // 運営者(admin) は本部以外として閲覧していれば常に見える。本部ログイン中は
               // mimamori_get_view_user_id() が view 中クライアントの user_id を返すので、その権限で判定する。
               $_view_uid = function_exists( 'mimamori_get_view_user_id' ) ? mimamori_get_view_user_id() : get_current_user_id();
               $_mb_show_chatbot = current_user_can( 'manage_options' )
                   || ( function_exists( 'mimamori_bot_is_enabled_for_user' )
                        && mimamori_bot_is_enabled_for_user( $_view_uid ) );
               $_mb_show_page_analysis = current_user_can( 'manage_options' )
                   || ( function_exists( 'mimamori_page_analysis_is_enabled_for_user' )
                        && mimamori_page_analysis_is_enabled_for_user( $_view_uid ) );
               ?>

               <!-- ========== ホームページ（大カテゴリ・MEO特化プランは非表示） ========== -->
               <?php if ( ! $_mb_is_meo_only ) : ?>
               <li class="nav-item nav-item-collapsible<?php echo $home_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'home' ? ' collapsed' : ''; ?>" data-menu-key="home">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleHome" aria-expanded="<?php echo $sidebar_active_group === 'home' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M8 21h8M12 17v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                  <span>ホームページ</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuHome">
                     <!-- 直下: 現状診断 (オプション) -->
                     <?php if ( $_mb_show_page_analysis ) : ?>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/page-analysis/') ); ?>" class="nav-link <?php echo is_page('page-analysis') ? 'active' : ''; ?>">
                        <span>現状のページ診断</span>
                        </a>
                     </li>
                     <?php endif; ?>
                     <!-- 直下: チャットボット (オプション) -->
                     <?php if ( $_mb_show_chatbot ) : ?>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/chatbot/') ); ?>" class="nav-link <?php echo is_page('chatbot') ? 'active' : ''; ?>">
                        <span>チャットボット</span>
                        </a>
                     </li>
                     <?php endif; ?>

                     <!-- サブグループ: サイト分析 -->
                     <li class="nav-item nav-subgroup-wrapper">
                        <button type="button" class="nav-subgroup-label nav-subgroup-toggle" aria-expanded="true">
                           サイト分析
                           <span class="nav-subgroup-toggle-arrow" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="nav-subgroup-menu">
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/site-dashboard/') ); ?>" class="nav-link <?php echo is_page('site-dashboard') ? 'active' : ''; ?>">
                              <span>サイトダッシュボード</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-device/') ); ?>" class="nav-link <?php echo is_page('analysis-device') ? 'active' : ''; ?>">
                              <span>スマホとパソコンの割合</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-age/') ); ?>" class="nav-link <?php echo is_page('analysis-age') ? 'active' : ''; ?>">
                              <span>見ている人の年代</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-source/') ); ?>" class="nav-link <?php echo is_page('analysis-source') ? 'active' : ''; ?>">
                              <span>見つけたきっかけ</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-region/') ); ?>" class="nav-link <?php echo is_page('analysis-region') ? 'active' : ''; ?>">
                              <span>見ている人の場所</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-pages/') ); ?>" class="nav-link <?php echo is_page('analysis-pages') ? 'active' : ''; ?>">
                              <span>よく見られているページ</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-keywords/') ); ?>" class="nav-link <?php echo is_page('analysis-keywords') ? 'active' : ''; ?>">
                              <span>どんな言葉で探された？</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/analysis/analysis-cv/') ); ?>" class="nav-link <?php echo is_page('analysis-cv') ? 'active' : ''; ?>">
                              <span>ゴール分析</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/inquiries/') ); ?>" class="nav-link <?php echo is_page('inquiries') ? 'active' : ''; ?>">
                              <span>お問い合わせ一覧</span>
                              </a>
                           </li>
                        </ul>
                     </li>

                     <!-- サブグループ: SEO -->
                     <li class="nav-item nav-subgroup-wrapper">
                        <button type="button" class="nav-subgroup-label nav-subgroup-toggle" aria-expanded="true">
                           SEO
                           <span class="nav-subgroup-toggle-arrow" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="nav-subgroup-menu">
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/seo-check/') ); ?>" class="nav-link <?php echo is_page('seo-check') ? 'active' : ''; ?>">
                              <span>SEO診断</span>
                              </a>
                           </li>
                           <?php if ( mimamori_aio_enabled() ) : ?>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/ai-report/') ); ?>" class="nav-link <?php echo is_page('ai-report') ? 'active' : ''; ?>">
                              <span>AIO診断</span>
                              </a>
                           </li>
                           <?php endif; ?>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/rank-tracker/') ); ?>" class="nav-link <?php echo is_page('rank-tracker') ? 'active' : ''; ?>">
                              <span>自然検索順位</span>
                              </a>
                           </li>
                           <?php if ( mimamori_can_access_seo() ) : ?>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/keyword-research/') ); ?>" class="nav-link <?php echo is_page('keyword-research') ? 'active' : ''; ?>">
                              <span>キーワード調査</span>
                              </a>
                           </li>
                           <?php endif; ?>
                        </ul>
                     </li>

                  </ul>
               </li>
               <?php endif; // ! $_mb_is_meo_only — ホームページセクション ?>

               <!-- ========== MEO（大カテゴリ・MEO・検索集客強化プラン以上 or 管理者のみ表示） ========== -->
               <?php if ( mimamori_can_access_meo() ) : ?>
               <li class="nav-item nav-item-collapsible<?php echo $meo_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'meo' ? ' collapsed' : ''; ?>" data-menu-key="meo">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleMeo" aria-expanded="<?php echo $sidebar_active_group === 'meo' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/></svg></span>
                  <span>MEO</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuMeo">
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/meo/meo-dashboard/') ); ?>" class="nav-link <?php echo is_page('meo-dashboard') ? 'active' : ''; ?>">
                        <span>MEOダッシュボード</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/map-rank/') ); ?>" class="nav-link <?php echo is_page('map-rank') ? 'active' : ''; ?>">
                        <span>マップ順位</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/meo-diagnosis/') ); ?>" class="nav-link <?php echo is_page('meo-diagnosis') ? 'active' : ''; ?>">
                        <span>MEO診断</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/meo/meo-search-terms/') ); ?>" class="nav-link <?php echo is_page('meo-search-terms') ? 'active' : ''; ?>">
                        <span>検索語句分析</span>
                        </a>
                     </li>
                     <li class="nav-item nav-subgroup-wrapper">
                        <button type="button" class="nav-subgroup-label nav-subgroup-toggle" aria-expanded="true">
                           口コミアンケート
                           <span class="nav-subgroup-toggle-arrow" aria-hidden="true">&#9662;</span>
                        </button>
                        <ul class="nav-subgroup-menu">
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/tools/review-survey/') ); ?>" class="nav-link <?php echo is_page('review-survey') ? 'active' : ''; ?>">
                              <span>アンケート管理</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/survey-responses/') ); ?>" class="nav-link <?php echo is_page('survey-responses') ? 'active' : ''; ?>">
                              <span>回答履歴</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/survey-analytics/') ); ?>" class="nav-link <?php echo is_page('survey-analytics') ? 'active' : ''; ?>">
                              <span>集計</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/survey-analysis/') ); ?>" class="nav-link <?php echo is_page('survey-analysis') ? 'active' : ''; ?>">
                              <span>分析</span>
                              </a>
                           </li>
                           <li class="nav-item">
                              <a href="<?php echo esc_url( home_url('/survey-ai-history/') ); ?>" class="nav-link <?php echo is_page('survey-ai-history') ? 'active' : ''; ?>">
                              <span>AI生成履歴</span>
                              </a>
                           </li>
                        </ul>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/review-management/') ); ?>" class="nav-link <?php echo is_page('review-management') ? 'active' : ''; ?>">
                        <span>口コミ管理</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/gbp-posts/') ); ?>" class="nav-link <?php echo is_page('gbp-posts') ? 'active' : ''; ?>">
                        <span>投稿管理</span>
                        </a>
                     </li>
                  </ul>
               </li>
               <?php endif; ?>

               <!-- ========== 各種設定 ========== -->
               <li class="nav-item nav-item-collapsible<?php echo $settings_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'settings' ? ' collapsed' : ''; ?>" data-menu-key="settings">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleSettings" aria-expanded="<?php echo $sidebar_active_group === 'settings' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.5"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <span>各種設定</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuSettings">
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/client-settings/') ); ?>" class="nav-link <?php echo is_page('client-settings') ? 'active' : ''; ?>">
                        <span>クライアント設定</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/keyword-settings/') ); ?>" class="nav-link <?php echo is_page('keyword-settings') ? 'active' : ''; ?>">
                        <span>計測キーワード設定</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/report-settings/') ); ?>" class="nav-link <?php echo is_page('report-settings') ? 'active' : ''; ?>">
                        <span>月次レポート設定・生成</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/analysis/cv-review/') ); ?>" class="nav-link <?php echo is_page('cv-review') ? 'active' : ''; ?>">
                        <span>ゴール関連設定</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/account/notifications/') ); ?>" class="nav-link <?php echo is_page('notifications') ? 'active' : ''; ?>">
                        <span>通知設定</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/account-info/') ); ?>" class="nav-link <?php echo is_page('account-info') ? 'active' : ''; ?>">
                        <span>アカウント情報</span>
                        </a>
                     </li>
                  </ul>
               </li>

               <!-- ========== プラン紹介（単独） ========== -->
               <li class="nav-item">
                  <a href="<?php echo esc_url( home_url('/plans/') ); ?>" class="nav-link <?php echo is_page('plans') ? 'active' : ''; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 4h14M3 4v12a1 1 0 001 1h12a1 1 0 001-1V4M7 4V3a1 1 0 011-1h4a1 1 0 011 1v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 9h2M7 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                  <span>プラン紹介</span>
                  </a>
               </li>

               <!-- ========== サポート・問い合わせ ========== -->
               <li class="nav-item nav-item-collapsible<?php echo $support_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'support' ? ' collapsed' : ''; ?>" data-menu-key="support">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleSupport" aria-expanded="<?php echo $sidebar_active_group === 'support' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/></svg></span>
                  <span>サポート・問い合わせ</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuSupport">
                     <li class="nav-item">
                        <a href="<?php echo home_url('/faq/'); ?>" class="nav-link <?php echo is_page('faq') ? 'active' : ''; ?>">
                        <span>よくある質問</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/tutorials/'); ?>" class="nav-link <?php echo is_page('tutorials') ? 'active' : ''; ?>">
                        <span>使い方ガイド</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/inquiry/'); ?>" class="nav-link <?php echo is_page('inquiry') ? 'active' : ''; ?>">
                        <span>問い合わせ</span>
                        </a>
                     </li>
                  </ul>
               </li>

               <!-- オプションサービス（一旦非表示） -->
               <?php /*
               <li class="nav-item nav-item-collapsible<?php echo $option_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'option' ? ' collapsed' : ''; ?>" data-menu-key="option">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleOption" aria-expanded="<?php echo $sidebar_active_group === 'option' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 4 12 14.01l-3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <span>オプションサービス</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuOption">
                     <li class="nav-item">
                        <a href="<?php echo home_url('/service/'); ?>" class="nav-link <?php echo is_page('service') ? 'active' : ''; ?>">
                        <span>伴走サポート</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/improvement-request/'); ?>" class="nav-link <?php echo is_page('improvement-request') ? 'active' : ''; ?>">
                        <span>改善依頼</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/training/'); ?>" class="nav-link <?php echo is_page('training') ? 'active' : ''; ?>">
                        <span>研修申込み</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/ad-consulting/'); ?>" class="nav-link <?php echo is_page('ad-consulting') ? 'active' : ''; ?>">
                        <span>広告相談</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/meeting-reservation/'); ?>" class="nav-link <?php echo is_page('meeting-reservation') ? 'active' : ''; ?>">
                        <span>打ち合わせ予約</span>
                        </a>
                     </li>
                  </ul>
               </li>
               */ ?>

            </ul>
         </div>
      </nav>
   </aside>
   <!-- メインコンテンツ -->
   <main class="main-content">
   <!-- トップバー -->
   <div class="topbar">
      <div class="topbar-left">
         <button class="menu-toggle" id="menuToggle">☰</button>
         <h1 class="page-title"><?php
            $page_title = get_query_var('gcrev_page_title');
            if (!$page_title) {
                $page_title = get_the_title();
            }
            echo esc_html($page_title);
            ?></h1>
      </div>
      <div class="topbar-right">
         <?php if ( is_user_logged_in() ) :
            $u = mimamori_get_view_user_object();
            $company = gcrev_get_business_name( $u->ID );
            ?>

         <!-- クライアント名・サイトURL -->
         <div class="topbar-user-info">
            <div class="topbar-user-name"><?php echo esc_html( $company ); ?> 様</div>
            <?php
            $tb_site_url = get_user_meta( $u->ID, 'report_site_url', true )
                         ?: get_user_meta( $u->ID, 'weisite_url', true );
            if ( $tb_site_url ) :
               $tb_display_url = preg_replace( '#^https?://#', '', untrailingslashit( $tb_site_url ) );
            ?>
            <div class="topbar-user-plan">
               <a href="<?php echo esc_url( $tb_site_url ); ?>" target="_blank" rel="noopener noreferrer"
                  style="color: inherit; text-decoration: none;"><?php echo esc_html( $tb_display_url ); ?></a>
            </div>
            <?php endif; ?>
         </div>

         <!-- 初めての方へボタン -->
         <a href="<?php echo esc_url( home_url( '/tutorials/' ) ); ?>" class="topbar-guide-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none">
               <path d="M12 2C10 2 8.5 4 8.5 4L2 22h9l1-4 1 4h9l-6.5-18S14 2 12 2z" fill="#fff" stroke="#fff" stroke-width="0.5" stroke-linejoin="round"/>
               <path d="M12 2C10 2 8.5 4 8.5 4L2 22h9l1-4" fill="#f4d946" stroke="#f4d946" stroke-width="0.5" stroke-linejoin="round"/>
               <path d="M12 2C14 2 15.5 4 15.5 4L22 22h-9l-1-4" fill="#4caf50" stroke="#4caf50" stroke-width="0.5" stroke-linejoin="round"/>
            </svg>
            <span>初めての方へ</span>
         </a>

         <!-- 重要設定ドロップダウン -->
         <div class="settings-menu" id="settingsMenu">
            <button class="settings-menu-btn" type="button" aria-label="重要設定">
               <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="3"/>
                  <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>
               </svg>
               <span class="settings-menu-label">重要設定</span>
               <span class="settings-menu-arrow" aria-hidden="true">&#9662;</span>
            </button>
            <div class="settings-dropdown" id="settingsDropdown">
               <ul class="settings-dropdown-list">
                  <li>
                     <a href="<?php echo esc_url( home_url( '/account/client-settings/' ) ); ?>" class="settings-dropdown-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>クライアント設定</span>
                     </a>
                  </li>
                  <li>
                     <a href="<?php echo esc_url( home_url( '/report/report-settings/' ) ); ?>" class="settings-dropdown-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2.5h8.5L16 6v11.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2.5Z"/><path d="M12.5 2.5V6H16"/><path d="M7.5 11h5M7.5 14h3"/></svg>
                        <span>月次レポート設定・生成</span>
                     </a>
                  </li>
                  <li>
                     <a href="<?php echo esc_url( home_url( '/analysis/cv-review/' ) ); ?>" class="settings-dropdown-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>
                        <span>ゴール関連設定</span>
                     </a>
                  </li>
               </ul>
            </div>
         </div>

         <!-- アップデート通知ベル -->
         <div class="updates-bell" id="updatesBell">
            <button class="updates-bell-btn" type="button" aria-label="アップデート通知">
               <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                  <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
               </svg>
               <span class="updates-badge" id="updatesBadge" style="display:none;"></span>
            </button>
            <div class="updates-dropdown" id="updatesDropdown">
               <div class="updates-dropdown-header">
                  <span class="updates-dropdown-title">アップデート情報</span>
               </div>
               <div class="updates-dropdown-list" id="updatesDropdownList">
                  <div class="updates-loading">読み込み中...</div>
               </div>
            </div>
         </div>

         <!-- アカウントメニュー -->
         <div class="account-menu" id="accountMenu">
            <button class="account-menu-btn" type="button" aria-label="アカウントメニュー">
               <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
               </svg>
            </button>
            <div class="account-dropdown" id="accountDropdown">
               <div class="account-dropdown-header">
                  <span class="account-dropdown-title"><?php echo esc_html( $company ); ?> 様</span>
               </div>
               <ul class="account-dropdown-list">
                  <li>
                     <a href="<?php echo esc_url( home_url( '/account/account-info/' ) ); ?>" class="account-dropdown-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>アカウント情報</span>
                     </a>
                  </li>
                  <li>
                     <a href="<?php echo esc_url( wp_logout_url( home_url( '/login/' ) ) ); ?>" class="account-dropdown-item account-dropdown-logout">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>ログアウト</span>
                     </a>
                  </li>
               </ul>
            </div>
         </div>
         <?php endif; ?>
      </div>
   </div>
   <!-- パンくず（ページ側で出力する場合もあり） -->
   <?php 
      $breadcrumb = get_query_var('gcrev_breadcrumb');
      if ($breadcrumb): 
      ?>
   <div class="breadcrumb">
      <?php echo $breadcrumb; // XSS対策済みの前提 ?>
   </div>
   <?php endif; ?>
   <!-- ここからページ固有のコンテンツが始まる -->