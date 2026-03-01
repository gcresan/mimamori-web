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
      <link href="https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@400;500;700&family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">
      <link rel="stylesheet" type="text/css" href="<?php echo get_template_directory_uri(); ?>/css/import.css?v=1.4.1" media="all">
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
            $company = $u->last_name ?: $u->display_name;
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
      // --- アコーディオン初期状態: 子ページがアクティブな親を開く / なければ analysis ---
      $report_pages   = array('report-latest','report-archive');
      $analysis_pages = array('analysis-device','analysis-age','analysis-source','analysis-region','analysis-pages','analysis-keywords','analysis-cv');
      $settings_pages = array('report-settings','cv-review','cv-settings');
      $support_pages  = array('faq','tutorials','inquiry');
      $option_pages   = array('service','improvement-request','training','ad-consulting','meeting-reservation');
      $account_pages  = array('account-info','client-settings','ga-gsc-connection','meo-connection','notifications');

      $report_child_active = false;
      foreach ($report_pages as $_p) { if (is_page($_p)) { $report_child_active = true; break; } }
      $analysis_child_active = false;
      foreach ($analysis_pages as $_p) { if (is_page($_p)) { $analysis_child_active = true; break; } }
      $settings_child_active = false;
      foreach ($settings_pages as $_p) { if (is_page($_p)) { $settings_child_active = true; break; } }
      $support_child_active = false;
      foreach ($support_pages as $_p) { if (is_page($_p)) { $support_child_active = true; break; } }
      $option_child_active = false;
      foreach ($option_pages as $_p) { if (is_page($_p)) { $option_child_active = true; break; } }
      $account_child_active = false;
      foreach ($account_pages as $_p) { if (is_page($_p)) { $account_child_active = true; break; } }

      // 子がアクティブなグループを開く。なければデフォルト 'analysis'
      $sidebar_active_group = 'analysis';
      if ($report_child_active)   $sidebar_active_group = 'report';
      if ($settings_child_active) $sidebar_active_group = 'settings';
      if ($support_child_active)  $sidebar_active_group = 'support';
      if ($option_child_active)   $sidebar_active_group = 'option';
      if ($account_child_active)  $sidebar_active_group = 'account';
      ?>
      <nav class="sidebar-nav">
         <div class="nav-section">
            <ul class="nav-menu">
               <!-- 全体のようす（単独） -->
               <li class="nav-item">
                  <a href="<?php echo home_url('/dashboard/'); ?>" class="nav-link <?php echo is_page('dashboard') ? 'active' : ''; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3C6 3 2.7 5.9 1.5 10c1.2 4.1 4.5 7 8.5 7s7.3-2.9 8.5-7C17.3 5.9 14 3 10 3Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="3" stroke="currentColor" stroke-width="1.5"/></svg></span>
                  <span>全体のようす</span>
                  </a>
               </li>

               <!-- 月次レポート（折りたたみ親） -->
               <li class="nav-item nav-item-collapsible<?php echo $report_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'report' ? ' collapsed' : ''; ?>" data-menu-key="report">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleReport" aria-expanded="<?php echo $sidebar_active_group === 'report' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 2.5h8.5L16 6v11.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.5 2.5V6H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.5 11h5M7.5 14h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                  <span>月次レポート</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuReport">
                     <li class="nav-item">
                        <a href="<?php echo esc_url(home_url('/report/report-latest/')); ?>" class="nav-link <?php echo is_page('report-latest') ? 'active' : ''; ?>">
                        <span>最新月次レポート</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo esc_url(home_url('/report/report-archive/')); ?>" class="nav-link <?php echo is_page('report-archive') ? 'active' : ''; ?>">
                        <span>過去の月次レポート一覧</span>
                        </a>
                     </li>
                  </ul>
               </li>

               <!-- 集客のようす（折りたたみ親） -->
               <li class="nav-item nav-item-collapsible<?php echo $analysis_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'analysis' ? ' collapsed' : ''; ?>" data-menu-key="analysis">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleAnalysis" aria-expanded="<?php echo $sidebar_active_group === 'analysis' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 10h14M10 3v14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="5" cy="5" r="1.5" stroke="currentColor" stroke-width="1.2"/><circle cx="15" cy="5" r="1.5" stroke="currentColor" stroke-width="1.2"/><circle cx="5" cy="15" r="1.5" stroke="currentColor" stroke-width="1.2"/><circle cx="15" cy="15" r="1.5" stroke="currentColor" stroke-width="1.2"/></svg></span>
                  <span>集客のようす</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuAnalysis">
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-device/'); ?>" class="nav-link <?php echo is_page('analysis-device') ? 'active' : ''; ?>">
                        <span>スマホとパソコンの割合</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-age/'); ?>" class="nav-link <?php echo is_page('analysis-age') ? 'active' : ''; ?>">
                        <span>見ている人の年代</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-source/'); ?>" class="nav-link <?php echo is_page('analysis-source') ? 'active' : ''; ?>">
                        <span>見つけたきっかけ</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-region/'); ?>" class="nav-link <?php echo is_page('analysis-region') ? 'active' : ''; ?>">
                        <span>見ている人の場所</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-pages/'); ?>" class="nav-link <?php echo is_page('analysis-pages') ? 'active' : ''; ?>">
                        <span>よく見られているページ</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-keywords/'); ?>" class="nav-link <?php echo is_page('analysis-keywords') ? 'active' : ''; ?>">
                        <span>どんな言葉で探された？</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/analysis-cv/'); ?>" class="nav-link <?php echo is_page('analysis-cv') ? 'active' : ''; ?>">
                        <span>ゴール分析</span>
                        </a>
                     </li>
                  </ul>
               </li>

               <!-- 各種設定（折りたたみ親） -->
               <li class="nav-item nav-item-collapsible<?php echo $settings_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'settings' ? ' collapsed' : ''; ?>" data-menu-key="settings">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleSettings" aria-expanded="<?php echo $sidebar_active_group === 'settings' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.5"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                  <span>各種設定</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuSettings">
                     <li class="nav-item">
                        <a href="<?php echo home_url('/report/report-settings/'); ?>" class="nav-link <?php echo is_page('report-settings') ? 'active' : ''; ?>">
                        <span>月次レポート設定</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/cv-review/'); ?>" class="nav-link <?php echo is_page('cv-review') ? 'active' : ''; ?>">
                        <span>ゴールの確認（手動調整）</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/analysis/cv-settings/'); ?>" class="nav-link <?php echo is_page('cv-settings') ? 'active' : ''; ?>">
                        <span>ゴールの数え方設定</span>
                        </a>
                     </li>
                  </ul>
               </li>
         <!-- C. MEO（Googleビジネス） -->
<!--          <div class="nav-section">
            <div class="nav-section-title">MEO（Googleビジネス）</div>
            <ul class="nav-menu">
               <li class="nav-item">
                  <a href="<?php echo home_url('meo/meo-dashboard/'); ?>" class="nav-link">
                  <span class="nav-icon">📍</span>
                  <span>ダッシュボード</span>
                  </a>
               </li>
            </ul>
            <ul class="nav-submenu">
               <li class="nav-item">
                  <a href="<?php echo home_url('/meo-report/'); ?>" class="nav-link">
                  <span>診断レポート</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/meo-ranking/'); ?>" class="nav-link">
                  <span>順位チェック</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/meo-reviews/'); ?>" class="nav-link">
                  <span>クチコミ分析</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/meo-posts/'); ?>" class="nav-link">
                  <span>投稿チェック</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/meo-competitors/'); ?>" class="nav-link">
                  <span>競合比較</span>
                  </a>
               </li>
            </ul>
         </div> -->
         <!-- D. SNS（Instagram） -->
<!--          <div class="nav-section">
            <div class="nav-section-title">SNS（Instagram）</div>
            <ul class="nav-menu">
               <li class="nav-item">
                  <a href="<?php echo home_url('/instagram-dashboard/'); ?>" class="nav-link">
                  <span class="nav-icon">📱</span>
                  <span>ダッシュボード</span>
                  </a>
               </li>
            </ul>
            <ul class="nav-submenu">
               <li class="nav-item">
                  <a href="<?php echo home_url('/instagram-posts/'); ?>" class="nav-link">
                  <span>投稿別分析</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/instagram-followers/'); ?>" class="nav-link">
                  <span>フォロワー推移</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/instagram-hashtags/'); ?>" class="nav-link">
                  <span>ハッシュタグ分析</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/instagram-ai-suggestions/'); ?>" class="nav-link">
                  <span>改善提案（AI）</span>
                  </a>
               </li>
            </ul>
         </div> -->
         <!-- E. AI支援 / 改善提案 -->
<!--          <div class="nav-section">
            <div class="nav-section-title">AI支援 / 改善提案</div>
            <ul class="nav-menu">
               <li class="nav-item">
                  <a href="<?php echo home_url('/ai-improvements/'); ?>" class="nav-link">
                  <span class="nav-icon">🤖</span>
                  <span>今月の改善ポイント</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/ai-priority-actions/'); ?>" class="nav-link">
                  <span class="nav-icon">⭐</span>
                  <span>優先施策ランキング</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/ai-content-ideas/'); ?>" class="nav-link">
                  <span class="nav-icon">✏️</span>
                  <span>コンテンツ企画AI</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/ai-ad-suggestions/'); ?>" class="nav-link">
                  <span class="nav-icon">📢</span>
                  <span>広告改善案</span>
                  </a>
               </li>
            </ul>
         </div> -->
         <!-- F. お知らせ・トピックス -->
<!--          <div class="nav-section">
            <div class="nav-section-title">お知らせ・トピックス</div>
            <ul class="nav-menu">
               <li class="nav-item">
                  <a href="<?php echo home_url('/topics/'); ?>" class="nav-link">
                  <span class="nav-icon">📰</span>
                  <span>最新トピックス</span>
                  </a>
               </li>
               <li class="nav-item">
                  <a href="<?php echo home_url('/updates/'); ?>" class="nav-link">
                  <span class="nav-icon">🔔</span>
                  <span>機能アップデート</span>
                  </a>
               </li>

            </ul>
         </div> -->
         <!-- G. サポート・問い合わせ（折りたたみ親） -->
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

               <!-- H. オプションサービス（折りたたみ親） -->
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

               <!-- I. アカウント（折りたたみ親） -->
               <li class="nav-item nav-item-collapsible<?php echo $account_child_active ? ' child-active' : ''; ?><?php echo $sidebar_active_group !== 'account' ? ' collapsed' : ''; ?>" data-menu-key="account">
                  <button type="button" class="nav-link nav-link-toggle" id="navToggleAccount" aria-expanded="<?php echo $sidebar_active_group === 'account' ? 'true' : 'false'; ?>">
                  <span class="nav-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="1.5"/></svg></span>
                  <span>アカウント</span>
                  <span class="nav-toggle-arrow" aria-hidden="true">&#9662;</span>
                  </button>
                  <ul class="nav-submenu" id="navSubmenuAccount">
                     <li class="nav-item">
                        <a href="<?php echo home_url('/account/account-info/'); ?>" class="nav-link <?php echo is_page('account-info') ? 'active' : ''; ?>">
                        <span>アカウント情報</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/account/client-settings/'); ?>" class="nav-link <?php echo is_page('client-settings') ? 'active' : ''; ?>">
                        <span>クライアント設定</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/account/ga-gsc-connection/'); ?>" class="nav-link <?php echo is_page('ga-gsc-connection') ? 'active' : ''; ?>">
                        <span>GA / GSC連携</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/account/meo-connection/'); ?>" class="nav-link <?php echo is_page('meo-connection') ? 'active' : ''; ?>">
                        <span>MEO連携</span>
                        </a>
                     </li>
                     <li class="nav-item">
                        <a href="<?php echo home_url('/account/notifications/'); ?>" class="nav-link <?php echo is_page('notifications') ? 'active' : ''; ?>">
                        <span>通知設定</span>
                        </a>
                     </li>
                  </ul>
               </li>
            </ul>
         </div>
      </nav>
   </aside>
   <!-- メインコンテンツ -->
   <main class="main-content">
   <!-- トップバー -->
   <div class="topbar">
      <div class="topbar-logo">
         <a href="<?php echo esc_url( home_url() ); ?>">
            <img src="<?php echo esc_url( get_template_directory_uri() . '/images/common/logo.png' ); ?>" alt="<?php echo esc_attr( 'みまもりウェブ' ); ?>">
         </a>
      </div>
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
            $company = $u->last_name ?: $u->display_name;
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

         <div class="logout">
            <a href="<?php echo wp_logout_url( home_url('/login/') ); ?>" class="logout-btn">
            ログアウト
            </a>
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