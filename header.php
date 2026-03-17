<!DOCTYPE html>
<html <?php language_attributes(); ?>>
   <head>
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <title><?php wp_title(' | ', true, 'right'); bloginfo('name'); ?></title>
      <link rel="icon" type="image/x-icon" href="<?php echo get_template_directory_uri(); ?>/images/favicon.ico">
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@400;500;700&family=Noto+Sans+JP:wght@400;500;600;700;900&display=swap" rel="stylesheet">
      <link rel="stylesheet" type="text/css" href="<?php echo get_template_directory_uri(); ?>/css/import.css?v=1.6.0" media="all">
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
            $u = wp_get_current_user();

            // 表示名優先 → 姓
            $company = gcrev_get_business_name( $u->ID );
            ?>
         <div class="sidebar-user-info">
            <div class="sidebar-user-name">
               <?php echo esc_html( $company ); ?> 様
            </div>
            <?php
            $site_url = get_user_meta( $u->ID, 'report_site_url', true )
                      ?: get_user_meta( $u->ID, 'weisite_url', true );
            if ( $site_url ) :
               $display_url = preg_replace( '#^https?://#', '', untrailingslashit( $site_url ) );
            ?>
            <div class="sidebar-user-plan">
               <a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer"
                  style="color: inherit; text-decoration: none;"><?php echo esc_html( $display_url ); ?></a>
            </div>
            <?php endif; ?>
            <?php if ( get_user_meta( $u->ID, 'gcrev_test_operation', true ) === '1' ) : ?>
            <div class="sidebar-test-badge" style="margin-top: 6px;">
               <span style="display: inline-block; padding: 2px 10px; font-size: 11px; font-weight: 600; color: #c0392b; background: #fdf0ee; border: 1px solid #e8c4bf; border-radius: 4px; letter-spacing: 0.05em;">テスト運用</span>
            </div>
            <?php endif; ?>
         </div>
         <?php endif; ?>
      </div>
      <?php
      // --- アコーディオン初期状態: 子ページがアクティブな親を開く / なければ website ---
      $diagnosis_pages = array('seo-check','ai-report','meo-diagnosis');
      $website_pages   = array('site-dashboard','analysis-device','analysis-age','analysis-source','analysis-region','analysis-pages','analysis-keywords','analysis-cv');
      $ranking_pages   = array('rank-tracker','map-rank');
      $meo_pages       = array('meo-dashboard','meo-search-terms','review-survey','survey-responses','survey-analytics','survey-analysis','survey-ai-history','review-management','gbp-posts');
      $settings_pages  = array('client-settings','report-settings','cv-review','notifications','account-info');
      $support_pages   = array('faq','tutorials','inquiry');
      $option_pages    = array('service','improvement-request','training','ad-consulting','meeting-reservation');

      $diagnosis_child_active = false;
      foreach ($diagnosis_pages as $_p) { if (is_page($_p)) { $diagnosis_child_active = true; break; } }
      $website_child_active = false;
      foreach ($website_pages as $_p) { if (is_page($_p)) { $website_child_active = true; break; } }
      $ranking_child_active = false;
      foreach ($ranking_pages as $_p) { if (is_page($_p)) { $ranking_child_active = true; break; } }
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
      if ($diagnosis_child_active) $sidebar_active_group = 'diagnosis';
      if ($ranking_child_active)   $sidebar_active_group = 'ranking';
      if ($meo_child_active)       $sidebar_active_group = 'meo';
      if ($settings_child_active)  $sidebar_active_group = 'settings';
      if ($support_child_active)   $sidebar_active_group = 'support';
      if ($option_child_active)    $sidebar_active_group = 'option';
      ?>
      <nav class="sidebar-nav">
         <div class="nav-section">
            <ul class="nav-menu">

               <!-- ========== 全体 ========== -->

               <!-- 全体ダッシュボード（単独） -->
               <li class="nav-item">
                  <a href="<?php echo esc_url( home_url('/dashboard/') ); ?>" class="nav-link <?php echo is_page('dashboard') ? 'active' : ''; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3C6 3 2.7 5.9 1.5 10c1.2 4.1 4.5 7 8.5 7s7.3-2.9 8.5-7C17.3 5.9 14 3 10 3Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/></svg></span>
                  <span>全体ダッシュボード</span>
                  </a>
               </li>

               <!-- 月次レポート（単独） -->
               <li class="nav-item">
                  <a href="<?php echo esc_url( home_url('/report/report-latest/') ); ?>" class="nav-link <?php echo ( is_page('report-latest') || is_page('report-archive') ) ? 'active' : ''; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 2.5h8.5L16 6v11.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 2.5V6H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 11h5M7.5 14h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                  <span>月次レポート</span>
                  </a>
               </li>

               <!-- ========== 診断レポート ========== -->
               <li class="nav-item nav-item-collapsible<?php echo $diagnosis_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'diagnosis' ? ' collapsed' : ''; ?>" data-menu-key="diagnosis">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleDiagnosis" aria-expanded="<?php echo $sidebar_active_group === 'diagnosis' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 11l3 3L22 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <span>診断レポート</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuDiagnosis">
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/seo-check/') ); ?>" class="nav-link <?php echo is_page('seo-check') ? 'active' : ''; ?>">
                        <span>SEO診断</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/ai-report/') ); ?>" class="nav-link <?php echo is_page('ai-report') ? 'active' : ''; ?>">
                        <span>AI検索診断</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/meo-diagnosis/') ); ?>" class="nav-link <?php echo is_page('meo-diagnosis') ? 'active' : ''; ?>">
                        <span>MEO診断</span>
                        </a>
                     </li>
                  </ul>
               </li>

               <!-- ========== ホームページ ========== -->
               <li class="nav-item nav-item-collapsible<?php echo $website_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'website' ? ' collapsed' : ''; ?>" data-menu-key="website">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleWebsite" aria-expanded="<?php echo $sidebar_active_group === 'website' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M8 21h8M12 17v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                  <span>ホームページ</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuWebsite">
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
                  </ul>
               </li>

               <!-- ========== 検索順位チェック ========== -->
               <li class="nav-item nav-item-collapsible<?php echo $ranking_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'ranking' ? ' collapsed' : ''; ?>" data-menu-key="ranking">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleRanking" aria-expanded="<?php echo $sidebar_active_group === 'ranking' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3v18h18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 16l4-5 4 3 5-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <span>検索順位チェック</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuRanking">
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/rank-tracker/') ); ?>" class="nav-link <?php echo is_page('rank-tracker') ? 'active' : ''; ?>">
                        <span>自然検索順位</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url( home_url('/map-rank/') ); ?>" class="nav-link <?php echo is_page('map-rank') ? 'active' : ''; ?>">
                        <span>マップ順位</span>
                        </a>
                     </li>
                  </ul>
               </li>

               <!-- ========== MEO ========== -->
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
                        <a href="<?php echo esc_url( home_url('/meo/meo-search-terms/') ); ?>" class="nav-link <?php echo is_page('meo-search-terms') ? 'active' : ''; ?>">
                        <span>検索語句分析</span>
                        </a>
                     </li>
                     <li class="nav-item nav-subgroup-label"><span>口コミアンケート</span></li>
                     <li class="nav-item nav-subgroup-item">
                        <a href="<?php echo esc_url( home_url('/tools/review-survey/') ); ?>" class="nav-link <?php echo is_page('review-survey') ? 'active' : ''; ?>">
                        <span>アンケート管理</span>
                        </a>
                     </li>
                     <li class="nav-item nav-subgroup-item">
                        <a href="<?php echo esc_url( home_url('/survey-responses/') ); ?>" class="nav-link <?php echo is_page('survey-responses') ? 'active' : ''; ?>">
                        <span>回答履歴</span>
                        </a>
                     </li>
                     <li class="nav-item nav-subgroup-item">
                        <a href="<?php echo esc_url( home_url('/survey-analytics/') ); ?>" class="nav-link <?php echo is_page('survey-analytics') ? 'active' : ''; ?>">
                        <span>集計</span>
                        </a>
                     </li>
                     <li class="nav-item nav-subgroup-item">
                        <a href="<?php echo esc_url( home_url('/survey-analysis/') ); ?>" class="nav-link <?php echo is_page('survey-analysis') ? 'active' : ''; ?>">
                        <span>分析</span>
                        </a>
                     </li>
                     <li class="nav-item nav-subgroup-item">
                        <a href="<?php echo esc_url( home_url('/survey-ai-history/') ); ?>" class="nav-link <?php echo is_page('survey-ai-history') ? 'active' : ''; ?>">
                        <span>AI生成履歴</span>
                        </a>
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
            $u = wp_get_current_user();
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