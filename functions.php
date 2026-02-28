<?php
/**
 * functions.php (cleaned & bug-fixed)
 */

// ----------------------------------------
// Basic settings
// ----------------------------------------

// ハイフンの自動変換防止（見出し/本文）
remove_filter('the_title', 'wptexturize');
remove_filter('the_content', 'wptexturize');

// 管理バーを完全非表示にする
add_filter('show_admin_bar', '__return_false');

// カスタムメニュー
register_nav_menus([
    'navigation' => 'ナビゲーションバー',
]);

// RSSフィードの情報を出力
add_theme_support('automatic-feed-links');

// エディタ・スタイルシート
add_editor_style();

// ギャラリーのスタイルシートの出力を停止
add_filter('use_default_gallery_style', '__return_false');

// アイキャッチ画像
add_theme_support('post-thumbnails');

// メディアサイズを追加
add_image_size('中2', 600, 600, false);
add_image_size('中3', 700, 700, false);

// ----------------------------------------
// Content filters
// ----------------------------------------

// pタグ消す（<!--handmade--> がある記事のみ wpautop を無効）
function rm_wpautop($content) {
    if (preg_match('|<!--handmade-->|siu', $content)) {
        remove_filter('the_content', 'wpautop');
    } else {
        add_filter('the_content', 'wpautop');
    }
    return $content;
}
add_filter('the_content', 'rm_wpautop', 9);

// jQueryの設定を出力（wp_head直は非推奨なので wp_enqueue_scripts へ）
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('jquery');
}, 1);

// ----------------------------------------
// Custom post type
// ----------------------------------------

add_action('init', function () {
    // ニュース記事の投稿タイプ
    register_post_type('news', [
        'label'        => 'ニュース',
        'hierarchical' => false,
        'public'       => true,
        'has_archive'  => true,
        'supports'     => ['title', 'editor'],
    ]);

    // アップデート履歴（release notes / 更新情報ベル）
    register_post_type('mimamori_update', [
        'label'           => 'アップデート',
        'hierarchical'    => false,
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'gcrev-insight',
        'supports'        => ['title', 'editor'],
        'capability_type' => 'post',
    ]);
});

// ----------------------------------------
// Widgets
// ----------------------------------------

add_action('widgets_init', function () {
    $widget_config_base = [
        'before_widget' => '<div class="widget" id="%1$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3>',
        'after_title'   => '</h3>',
    ];

    register_sidebar([
        'name' => __('Sidebar') . ' 1',
        'id'   => 'widget1',
    ] + $widget_config_base);

    register_sidebar([
        'name' => __('Sidebar') . ' 2',
        'id'   => 'widget2',
    ] + $widget_config_base);
});

// ----------------------------------------
// Archives for taxonomy (monthly)
// ----------------------------------------

add_filter('getarchives_join', function ($join, $r) {
    global $wpdb;
    if (!empty($r['taxonomy'])) {
        $join .= " LEFT JOIN {$wpdb->term_relationships} ON ({$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id) ";
        $join .= " LEFT JOIN {$wpdb->term_taxonomy} ON ({$wpdb->term_relationships}.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id) ";
    }
    return $join;
}, 10, 2);

add_filter('getarchives_where', function ($where, $r) {
    global $wpdb;
    if (!empty($r['taxonomy'])) {
        $where .= $wpdb->prepare(" AND {$wpdb->term_taxonomy}.taxonomy = %s ", $r['taxonomy']);
    }
    return $where;
}, 10, 2);

// ----------------------------------------
// Helpers
// ----------------------------------------

// 投稿のカテゴリーリンクを表示（echoする関数：元仕様を維持）
function get_post_category_link($post_id) {
    $my_cats = get_the_category($post_id);
    if (!$my_cats) return;

    $cats_cnt = 0;
    foreach ($my_cats as $my_cat) {
        if ($cats_cnt > 0) echo ', ';
        $cat_link = get_category_link($my_cat->term_id);
        echo '<a href="' . esc_url($cat_link) . '">' . esc_html($my_cat->name) . '</a>';
        $cats_cnt++;
    }
}

// ユーザーが検索したワードをハイライト（正規表現安全化）
function wps_highlight_results($text) {
    if (!is_search()) return $text;

    $sr = (string) get_query_var('s');
    $sr = trim($sr);
    if ($sr === '') return $text;

    // スペース区切り（全角スペースも考慮）
    $keys = preg_split('/[\s　]+/u', $sr, -1, PREG_SPLIT_NO_EMPTY);
    if (!$keys) return $text;

    $escaped = array_map(function ($k) {
        return preg_quote($k, '/');
    }, $keys);

    $pattern = '/(' . implode('|', $escaped) . ')/iu';
    return preg_replace($pattern, '<span class="searchhighlight">$1</span>', $text);
}
add_filter('the_title', 'wps_highlight_results');
add_filter('the_content', 'wps_highlight_results');

// 投稿記事だけ検索する（SQL直書きではなく pre_get_posts で安全に）
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query() || !$query->is_search()) return;
    $query->set('post_type', 'post');
});

// 空白の検索時にトップページにリダイレクト
add_action('parse_query', function ($wp_query) {
    if ($wp_query->is_main_query() && $wp_query->is_search && !$wp_query->is_admin) {
        $s = trim((string) $wp_query->get('s'));
        if ($s === '') {
            wp_safe_redirect(home_url('/blog/'));
            exit;
        }
    }
});

// ----------------------------------------
// Admin: taxonomy filter for CPT
// ----------------------------------------

add_action('restrict_manage_posts', function () {
    global $post_type;

    if ($post_type !== 'publications') return;

    $selected = isset($_GET['publications_cat']) ? (string) $_GET['publications_cat'] : '';

    echo '<select name="publications_cat">';
    echo '<option value="">' . esc_html__('カテゴリー一覧') . '</option>';

    $terms = get_terms([
        'taxonomy'   => 'publications_cat',
        'hide_empty' => false,
    ]);

    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($term->slug),
                selected($selected, $term->slug, false),
                esc_html($term->name)
            );
        }
    }

    echo '</select>';
});

// 管理画面のカスタム投稿タイプ一覧にカテゴリーを表示する
add_filter('manage_publications_posts_columns', function ($columns) {
    $columns['publications_cat'] = 'カテゴリー';
    return $columns;
}, 15);

add_action('manage_publications_posts_custom_column', function ($column_name, $post_id) {
    if ($column_name !== 'publications_cat') return;

    $terms = get_the_terms($post_id, 'publications_cat');
    if (!$terms || is_wp_error($terms)) return;

    $out = [];
    foreach ($terms as $term) {
        $url = add_query_arg(
            ['publications_cat' => $term->slug, 'post_type' => 'publications'],
            admin_url('edit.php')
        );
        $out[] = '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
    }
    echo implode(', ', $out);
}, 15, 2);

// ----------------------------------------
// Shortcodes
// ----------------------------------------

// サイトのURLを出力
add_shortcode('url', function () {
    return get_bloginfo('url');
});

// テンプレート・ディレクトリへのパス
add_shortcode('template', function () {
    return get_template_directory_uri();
});

// アップロード・ディレクトリへのパス
add_shortcode('uploads', function () {
    $upload_dir = wp_upload_dir();
    return $upload_dir['baseurl'];
});

add_shortcode('include_subnavi_about', function ($atts) {
    ob_start();
    get_template_part('subnavi_about');
    return ob_get_clean();
});

// srcset内のショートコードがそのまま表示されてしまう現象を解決（未定義配列対策）
add_filter('wp_kses_allowed_html', function ($tags, $context) {
    if (!isset($tags['img']))   $tags['img'] = [];
    if (!isset($tags['link']))  $tags['link'] = [];
    if (!isset($tags['script'])) $tags['script'] = [];

    $tags['img']['srcset']   = true;
    $tags['link']['href']    = true;
    $tags['script']['src']   = true;

    return $tags;
}, 10, 2);

// 記事の一番最初の画像を取得する
function catch_that_image() {
    global $post;
    $first_img = '';

    if ($post && !empty($post->post_content)) {
        preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post->post_content, $matches);
        if (!empty($matches[1][0])) {
            $first_img = $matches[1][0];
        }
    }

    if ($first_img === '') {
        $first_img = get_stylesheet_directory_uri() . '/images/common/noimg.jpg';
    }

    return $first_img;
}

// ----------------------------------------
// Jetpack
// ----------------------------------------

// JetpackのデフォルトのOG画像
add_filter('jetpack_open_graph_image_default', function ($image) {
    return get_stylesheet_directory_uri() . '/images/ogp.jpg';
});

// Jetpack SSOの「標準ログインフォーム表示」は、GETを勝手に書き換えない。
// もし常に有効にしたいなら、ログインURLを作る側で ?jetpack-sso-show-default-form=1 を付与してください。

// ----------------------------------------
// OGP
// ----------------------------------------

function my_meta_ogp() {
    if (!(is_front_page() || is_home() || is_singular())) return;

    $ogp_image = get_template_directory_uri() . '/images/ogp.png';

    $ogp_title       = '';
    $ogp_description = '';
    $ogp_url         = '';

    if (is_front_page() || is_home()) {
        $ogp_title       = get_bloginfo('name');
        $ogp_description = get_bloginfo('description');
        $ogp_url         = home_url();
    } elseif (is_singular()) {
        $post = get_queried_object();
        if ($post) {
            $ogp_title       = get_the_title($post);
            $ogp_description = mb_substr(wp_strip_all_tags(get_the_excerpt($post)), 0, 100);
            $ogp_url         = get_permalink($post);
        }
    }

    $ogp_type = (is_front_page() || is_home()) ? 'website' : 'article';

    if (is_singular() && has_post_thumbnail()) {
        $ps_thumb = wp_get_attachment_image_src(get_post_thumbnail_id(), 'full');
        if ($ps_thumb && !empty($ps_thumb[0])) {
            $ogp_image = $ps_thumb[0];
        }
    }

    // 未定義でも落ちないように（必要ならここに値を入れる）
    $twitter_card    = 'summary_large_image';
    $twitter_site    = '';
    $facebook_app_id = '';

    echo "\n";
    echo '<meta property="og:title" content="' . esc_attr($ogp_title) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($ogp_description) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($ogp_type) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($ogp_url) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url($ogp_image) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";

    if ($twitter_site !== '') {
        echo '<meta name="twitter:site" content="' . esc_attr($twitter_site) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
    echo '<meta property="og:locale" content="ja_JP">' . "\n";

    if ($facebook_app_id !== '') {
        echo '<meta property="fb:app_id" content="' . esc_attr($facebook_app_id) . '">' . "\n";
    }
}
add_action('wp_head', 'my_meta_ogp');

// ----------------------------------------
// Admin menu
// ----------------------------------------

add_action('admin_menu', function () {
    $current_user = wp_get_current_user();

    if ($current_user && $current_user->user_login === 'ibuki') {
        remove_menu_page('upload.php'); // メディア
        remove_menu_page('edit-comments.php'); // コメント
        remove_menu_page('tools.php'); // ツール
        remove_menu_page('edit.php?post_type=news'); // ニュース
        remove_menu_page('profile.php'); // プロフィール

        remove_menu_page('edit.php?post_type=works');
        remove_menu_page('edit.php?post_type=event');
        remove_menu_page('edit.php?post_type=staff');
        remove_menu_page('edit.php?post_type=recruit');
    }
});

// 固定ページでビジュアルエディタ無効
add_filter('user_can_richedit', function ($wp_rich_edit) {
    $posttype = get_post_type();
    if ($posttype === 'page') return false;
    return $wp_rich_edit;
});

// ----------------------------------------
// ユーザー編集画面カスタマイズ（一般ユーザー向け）
// ----------------------------------------

// カラースキーム選択を非表示（admin_init で実行しないと効かない）
add_action( 'admin_init', function () {
    remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
} );

// アプリケーションパスワードを非表示
add_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );

// ツールバーのデフォルトを OFF に設定（新規ユーザー登録時）
add_filter( 'get_user_option_show_admin_bar_front', function ( $value, $option, $user ) {
    // まだ明示的に設定されていない場合は false（非表示）
    $raw = get_user_meta( $user->ID, 'show_admin_bar_front', true );
    if ( '' === $raw ) {
        return 'false';
    }
    return $value;
}, 10, 3 );

// CSS で不要セクションを非表示
add_action( 'admin_head-profile.php', 'gcrev_hide_profile_sections_css' );
add_action( 'admin_head-user-edit.php', 'gcrev_hide_profile_sections_css' );
function gcrev_hide_profile_sections_css() {
    ?>
    <style>
    .user-comment-shortcuts-wrap,  /* キーボードショートカット */
    .user-admin-bar-front-wrap,    /* ツールバー */
    .user-language-wrap,           /* 言語 */
    .user-url-wrap,                /* サイト */
    .user-description-wrap,        /* プロフィール情報 */
    .user-profile-picture,         /* プロフィール写真 */
    #application-passwords-section /* アプリケーションパスワード */
    { display: none !important; }
    </style>
    <?php
}

// ----------------------------------------
// Mobile detection
// ----------------------------------------

// is_mobile()でスマホとタブレットを分ける（UA未定義対策）
function is_mobile() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua === '') return false;

    $useragents = [
        'iPhone',
        'iPod',
        '^(?=.*Android)(?=.*Mobile)',
        'dream',
        'CUPCAKE',
        'blackberry9500',
        'blackberry9530',
        'blackberry9520',
        'blackberry9550',
        'blackberry9800',
        'webOS',
        'incognito',
        'webmate',
    ];

    $pattern = '/' . implode('|', $useragents) . '/i';
    return (bool) preg_match($pattern, $ua);
}

// ----------------------------------------
// WP-Members
// ----------------------------------------

// ログイン後、決済ステータスに応じてリダイレクト先を切替
add_filter('wpmem_login_redirect', function ($redirect_to, $user_id) {
    if ( gcrev_is_payment_active( $user_id ) ) {
        return home_url('/mypage/dashboard/');
    }
    return home_url('/payment-status/');
}, 10, 2);

// ----------------------------------------
// wp-login.php カスタマイズ
// ----------------------------------------

/**
 * wp-login.php でのログインを禁止し、トップページへリダイレクト。
 * ただし以下は許可:
 *   - パスワードリセット系アクション（lostpassword / rp / resetpass 等）
 *   - /wp-admin/ 経由のリダイレクト（管理者ログイン）
 *   - 既にログイン済みのユーザー
 */
add_action( 'login_init', function () {
    // ログイン済みなら許可（wp-admin へのアクセス等）
    if ( is_user_logged_in() ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';

    // パスワードリセット・ログアウト等は許可
    $allowed_actions = [ 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'postpass', 'logout' ];
    if ( in_array( $action, $allowed_actions, true ) ) {
        return;
    }

    // redirect_to に wp-admin が含まれていれば管理者ログインとみなし許可
    $redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';
    if ( strpos( $redirect_to, 'wp-admin' ) !== false ) {
        return;
    }

    // 上記以外（一般ユーザーが直接 wp-login.php を開いた場合）→ トップへリダイレクト
    wp_safe_redirect( home_url( '/' ) );
    exit;
} );

/**
 * wp-login.php の外観カスタマイズ（パスワードリセット画面用）
 * - ロゴをみまもりウェブに変更
 * - テーマカラーに合わせた配色
 * - 「ログイン」リンクを非表示
 */
add_action( 'login_enqueue_scripts', function () {
    $logo_url = esc_url( get_template_directory_uri() . '/images/common/logo.png' );
    ?>
    <style>
    /* 背景 */
    body.login {
        background: #F2F1EC !important;
        font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Yu Gothic', sans-serif !important;
    }
    /* ロゴ → みまもりウェブ */
    #login h1 a {
        background-image: url('<?php echo $logo_url; ?>') !important;
        background-size: contain !important;
        background-position: center !important;
        background-repeat: no-repeat !important;
        width: 225px !important;
        height: 80px !important;
        margin: 0 auto 20px !important;
        display: block !important;
    }
    /* フォームカード */
    .login form {
        background: #FAF9F6 !important;
        border: none !important;
        border-radius: 8px !important;
        box-shadow: 0 2px 8px rgba(43,43,43,0.06) !important;
        padding: 26px 24px !important;
    }
    /* 入力フィールド */
    .login input[type="text"],
    .login input[type="email"],
    .login input[type="password"] {
        border: 1px solid #E5E3DC !important;
        border-radius: 4px !important;
        box-shadow: none !important;
    }
    .login input[type="text"]:focus,
    .login input[type="email"]:focus,
    .login input[type="password"]:focus {
        border-color: #3D6B6E !important;
        box-shadow: 0 0 0 1px #3D6B6E !important;
    }
    /* ボタン（テーマカラー） */
    .login .button-primary,
    .wp-core-ui .button-primary {
        background: #2F3A4A !important;
        border: none !important;
        border-radius: 4px !important;
        box-shadow: none !important;
        text-shadow: none !important;
        font-weight: 600 !important;
        padding: 6px 14px !important;
    }
    .login .button-primary:hover,
    .wp-core-ui .button-primary:hover,
    .login .button-primary:focus,
    .wp-core-ui .button-primary:focus {
        background: #3D4B5C !important;
        box-shadow: none !important;
    }
    /* 「ログイン」リンクを非表示、「← サイトへ移動」は残す */
    #nav { display: none !important; }
    /* 言語切替セレクタを非表示 */
    .language-switcher,
    #language-switcher { display: none !important; }
    /* サイトリンクの色 */
    #backtoblog a {
        color: #8C8A85 !important;
    }
    #backtoblog a:hover {
        color: #3D6B6E !important;
    }
    /* メッセージボックスのアクセントカラー */
    .login .message,
    .login .success {
        border-left-color: #3D6B6E !important;
    }
    .login a {
        color: #3D6B6E !important;
    }
    .login a:hover {
        color: #346062 !important;
    }
    </style>
    <?php
} );

// ロゴのリンク先をサイトトップに変更
add_filter( 'login_headerurl', function () {
    return home_url( '/' );
} );

// ロゴの alt テキスト
add_filter( 'login_headertext', function () {
    return 'みまもりウェブ';
} );



// ----------------------------------------
// incフォルダ内のAPIクラスファイルを読み込み
// ----------------------------------------
$gcrev_inc_path = get_template_directory() . '/inc/';

// ========================================
// Step1: utils を先に読み込む（Config / DateHelper / AreaDetector / HtmlExtractor）
// ========================================
$gcrev_utils_path = $gcrev_inc_path . 'gcrev-api/utils/';

$gcrev_utils_config        = $gcrev_utils_path . 'class-config.php';
$gcrev_utils_dates         = $gcrev_utils_path . 'class-date-helper.php';
// Step4.5 追加（utils）
$gcrev_utils_area_detector = $gcrev_utils_path . 'class-area-detector.php';
$gcrev_utils_html_extractor= $gcrev_utils_path . 'class-html-extractor.php';
$gcrev_utils_ai_json_parser= $gcrev_utils_path . 'class-ai-json-parser.php';

if ( file_exists( $gcrev_utils_config ) ) {
    require_once $gcrev_utils_config;
}
if ( file_exists( $gcrev_utils_dates ) ) {
    require_once $gcrev_utils_dates;
}
if ( file_exists( $gcrev_utils_area_detector ) ) {
    require_once $gcrev_utils_area_detector;
}
if ( file_exists( $gcrev_utils_html_extractor ) ) {
    require_once $gcrev_utils_html_extractor;
}
if ( file_exists( $gcrev_utils_ai_json_parser ) ) {
    require_once $gcrev_utils_ai_json_parser;
}
$gcrev_utils_crypto = $gcrev_utils_path . 'class-crypto.php';
if ( file_exists( $gcrev_utils_crypto ) ) {
    require_once $gcrev_utils_crypto;
}
$gcrev_utils_rate_limiter = $gcrev_utils_path . 'class-rate-limiter.php';
if ( file_exists( $gcrev_utils_rate_limiter ) ) {
    require_once $gcrev_utils_rate_limiter;
}
$gcrev_utils_cron_logger = $gcrev_utils_path . 'class-cron-logger.php';
if ( file_exists( $gcrev_utils_cron_logger ) ) {
    require_once $gcrev_utils_cron_logger;
}
$gcrev_utils_db_optimizer = $gcrev_utils_path . 'class-db-optimizer.php';
if ( file_exists( $gcrev_utils_db_optimizer ) ) {
    require_once $gcrev_utils_db_optimizer;
}
$gcrev_utils_prefetch_scheduler = $gcrev_utils_path . 'class-prefetch-scheduler.php';
if ( file_exists( $gcrev_utils_prefetch_scheduler ) ) {
    require_once $gcrev_utils_prefetch_scheduler;
}
$gcrev_utils_error_notifier = $gcrev_utils_path . 'class-error-notifier.php';
if ( file_exists( $gcrev_utils_error_notifier ) ) {
    require_once $gcrev_utils_error_notifier;
}

// ========================================
// Step2: modules を読み込む（入口クラスより先）
// ========================================
$gcrev_modules_path = $gcrev_inc_path . 'gcrev-api/modules/';

$gcrev_ai_client = $gcrev_modules_path . 'class-ai-client.php';
$gcrev_ga4       = $gcrev_modules_path . 'class-ga4-fetcher.php';
$gcrev_gsc       = $gcrev_modules_path . 'class-gsc-fetcher.php';

if ( file_exists( $gcrev_ai_client ) ) {
    require_once $gcrev_ai_client;
}
if ( file_exists( $gcrev_ga4 ) ) {
    require_once $gcrev_ga4;
}
if ( file_exists( $gcrev_gsc ) ) {
    require_once $gcrev_gsc;
}

// ========================================
// Step3: modules を読み込む（Repository / Generator）
// ========================================
$gcrev_repo = $gcrev_modules_path . 'class-report-repository.php';
$gcrev_gen  = $gcrev_modules_path . 'class-report-generator.php';

if ( file_exists( $gcrev_repo ) ) {
    require_once $gcrev_repo;
}
if ( file_exists( $gcrev_gen ) ) {
    require_once $gcrev_gen;
}

// ========================================
// Step4: modules を読み込む（Highlights / MonthlyReportService）
// ※依存: Highlights → Config + (AreaDetector, HtmlExtractor)
// ※依存: MonthlyReportService → Highlights + HtmlExtractor
// ========================================
$gcrev_highlights     = $gcrev_modules_path . 'class-highlights.php';
$gcrev_report_service = $gcrev_modules_path . 'class-monthly-report-service.php';

if ( file_exists( $gcrev_highlights ) ) {
    require_once $gcrev_highlights;
}
if ( file_exists( $gcrev_report_service ) ) {
    require_once $gcrev_report_service;
}

// ========================================
// Step5: Dashboard Service
// ========================================
$gcrev_dashboard_service = $gcrev_modules_path . 'class-dashboard-service.php';

if ( file_exists( $gcrev_highlights ) ) {
    require_once $gcrev_highlights;
}
if ( file_exists( $gcrev_report_service ) ) {
    require_once $gcrev_report_service;
}
if ( file_exists( $gcrev_dashboard_service ) ) {
    require_once $gcrev_dashboard_service;
}

// ========================================
// Updates API（更新情報ベル通知）
// ========================================
$mimamori_updates_api = $gcrev_modules_path . 'class-updates-api.php';
if ( file_exists( $mimamori_updates_api ) ) {
    require_once $mimamori_updates_api;
    if ( class_exists( 'Mimamori_Updates_API' ) ) {
        ( new Mimamori_Updates_API() )->register();
    }
}

// ========================================
// 既存のAPIクラス（入口）は最後に読み込む
// ========================================
$gcrev_entry = $gcrev_inc_path . 'class-gcrev-api.php';
if ( file_exists( $gcrev_entry ) ) {
    require_once $gcrev_entry;
}
// ========================================
// Bootstrap（Cron/Hook登録）は入口の後でOK
// ========================================
$gcrev_bootstrap = $gcrev_inc_path . 'gcrev-api/class-gcrev-bootstrap.php';
if ( file_exists( $gcrev_bootstrap ) ) {
    require_once $gcrev_bootstrap;

    if ( class_exists('Gcrev_Bootstrap') ) {
        Gcrev_Bootstrap::register();
    }
}




// ----------------------------------------
// ブロック用CSSを止める
// ----------------------------------------
add_action('wp_enqueue_scripts', function () {

    // ブロック基本CSS
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');

    // クラシックテーマ用CSS
    wp_dequeue_style('classic-theme-styles');

    // グローバルスタイル
    wp_dequeue_style('global-styles');

}, 100);



/**
 * ============================================================
 * みまもりウェブ - Period Selector Module (共通期間切替UI)
 * 各テンプレートで使える期間セレクター用のJS/CSSを読み込む
 *
 * assets/js/period-selector.js
 * assets/css/period-selector.css
 * ============================================================
 */
add_action('wp_enqueue_scripts', function() {

    wp_enqueue_script(
        'gcrev-period-selector',
        get_template_directory_uri() . '/assets/js/period-selector.js',
        [],
        '1.0.0',
        true
    );

    wp_enqueue_style(
        'gcrev-period-selector',
        get_template_directory_uri() . '/assets/css/period-selector.css',
        [],
        '1.0.0'
    );

});

/**
 * ============================================================
 * みまもりウェブ - AIチャット UIコンポーネント
 *
 * assets/js/mimamori-ai-chat.js
 * assets/css/mimamori-ai-chat.css
 * ============================================================
 */
add_action('wp_enqueue_scripts', function() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    wp_enqueue_script(
        'mw-ai-chat',
        get_template_directory_uri() . '/assets/js/mimamori-ai-chat.js',
        [],
        '1.3.0',
        true
    );

    wp_enqueue_style(
        'mw-ai-chat',
        get_template_directory_uri() . '/assets/css/mimamori-ai-chat.css',
        [],
        '1.3.0'
    );

    // --- クイックプロンプト（2モード×3カテゴリ） ---
    $quick_prompts = [
        'beginner' => [
            'status'  => [
                '今月、いちばん大事なことを1つだけ教えて',
                '今の数字は良い？悪い？かんたんに教えて',
                '良いところと気をつけたいところを1つずつ教えて',
                '先月と比べて、何が変わった？（短く）',
                '今月の結果を、子どもでも分かるくらい簡単に説明して',
            ],
            'action'  => [
                '今日30分でできる改善を1つ教えて',
                'まず何からやればいい？（1つだけ）',
                '問い合わせを増やすために、いま一番やるべきことは？',
                'サイトで直すなら、どのページを直すのが効果的？',
                '次の一歩を3つ教えて（かんたんに）',
            ],
            'trouble' => [
                'やってみたけど成果が出ない。原因をやさしく教えて',
                'アクセスが増えない理由を、むずかしい言葉なしで教えて',
                '問い合わせが増えないのは、何が原因か考えて',
                'どこがボトルネック？（集客／ページ内容／導線のどれ？）',
                '次は何を変えたらいい？（1つだけ）',
            ],
        ],
        'standard' => [
            'status'  => [
                '今月の良い兆しと注意点を3つずつ教えて',
                '先月からの変化を重要度順に3つまとめて',
                '数字の変化の原因を仮説で整理して',
                '流入（検索/マップ/参照）別に状況を要約して',
                '成果と課題の"本質"を1つずつ挙げて',
            ],
            'action'  => [
                '今月やるべきことを優先順位つきで3つ提案して',
                '即効性/中期/やらない を分けて改善案を出して',
                '問い合わせを増やすための改善を3つ、効果順に',
                'SEOで伸ばすなら、どのページをどう直すべき？',
                '最小工数で最大効果が出そうな一手は？',
            ],
            'trouble' => [
                '施策をやったが伸びない。原因を切り分けて（集客/導線/内容）',
                '仮説→検証の形で次に試すことを提案して',
                '数字が悪化した要因を時系列で推定して',
                '成果が出ない原因として"よくある罠"をチェックして',
                '改善が効いたか判断する指標と観察期間を提案して',
            ],
        ],
    ];
    $quick_prompts = apply_filters( 'mw_quick_prompts', $quick_prompts );

    // 初期モード: report_output_mode が 'easy' なら beginner、それ以外は standard
    $user_output_mode   = get_user_meta( get_current_user_id(), 'report_output_mode', true ) ?: 'normal';
    $initial_prompt_mode = ( $user_output_mode === 'easy' ) ? 'beginner' : 'standard';

    wp_localize_script( 'mw-ai-chat', 'mwChatConfig', [
        'apiUrl'            => rest_url( 'mimamori/v1/ai-chat' ),
        'voiceUrl'          => rest_url( 'mimamori/v1/voice-transcribe' ),
        'nonce'             => wp_create_nonce( 'wp_rest' ),
        'paymentActive'     => gcrev_is_payment_active(),
        'paymentStatusUrl'  => home_url( '/payment-status/' ),
        'quickPrompts'      => $quick_prompts,
        'initialPromptMode' => $initial_prompt_mode,
    ] );
});

/**
 * ============================================================
 * みまもりウェブ - アップデート通知ベル
 *
 * assets/js/mimamori-updates-bell.js
 * assets/css/mimamori-updates-bell.css
 * ============================================================
 */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_user_logged_in() ) {
        return;
    }

    wp_enqueue_script(
        'mw-updates-bell',
        get_template_directory_uri() . '/assets/js/mimamori-updates-bell.js',
        [],
        '1.0.0',
        true
    );

    wp_enqueue_style(
        'mw-updates-bell',
        get_template_directory_uri() . '/assets/css/mimamori-updates-bell.css',
        [],
        '1.0.0'
    );

    wp_localize_script( 'mw-updates-bell', 'mwUpdatesConfig', [
        'apiUrl'         => rest_url( 'mimamori/v1/updates' ),
        'markReadUrl'    => rest_url( 'mimamori/v1/updates/mark-read' ),
        'unreadCountUrl' => rest_url( 'mimamori/v1/updates/unread-count' ),
        'nonce'          => wp_create_nonce( 'wp_rest' ),
    ] );
} );

// ============================================================
// みまもりAI チャット — REST API + OpenAI 連携 (Phase 2)
// ※ 後でクラスファイルに切り出し可能な構造にしている
// ============================================================

/**
 * REST API ルート登録
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'mimamori/v1', '/ai-chat', [
        'methods'             => 'POST',
        'callback'            => 'mimamori_handle_ai_chat_request',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ] );

    register_rest_route( 'mimamori/v1', '/voice-transcribe', [
        'methods'             => 'POST',
        'callback'            => 'mimamori_handle_voice_transcribe',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ] );
} );

/**
 * みまもりAI システムプロンプト
 *
 * @return string
 */
function mimamori_get_system_prompt(): string {
    return <<<'PROMPT'
あなたは「みまもりAI」です。中小企業のホームページ担当者（初心者）を支援するアシスタントです。

## あなたの役割
- ホームページのアクセス数字の見方をやさしく説明する
- アクセスの増減理由をわかりやすく分析する
- 今すぐできる改善策を具体的に提案する
- 専門用語は一切使わず、日常の言葉だけで説明する
  絶対に使ってはいけない言葉と言い換え例：
  × CTR → ○「検索結果でクリックされた割合」
  × セッション → ○「サイトへの訪問回数」
  × コンバージョン/CV → ○「お問い合わせや申込みなどの成果」
  × 直帰率 → ○「1ページだけ見てすぐ帰った人の割合」
  × エンゲージメント → ○「しっかり見てくれた人の割合」
  × インプレッション → ○「検索結果に表示された回数」
  × PV/ページビュー → ○「ページが見られた回数」
  × GA4 → ○「アクセス解析のデータ」
  × Search Console/GSC → ○「Google検索のデータ」
  × KPI → ○ 使わない
  × SEO → ○「検索で上位に出るための対策」

## 回答スタイル — 対話ファースト
基本は **対話形式** で自然に会話してください。聞かれたことに端的に答えます。
具体的な改善提案・データ分析など、整理して伝えた方がわかりやすい場面でのみ構造化カードを使います。

## 回答JSON形式（最重要ルール）
必ず有効なJSONのみを返すこと。JSON以外のテキストを前後に付けない。マークダウン装飾（```等）も絶対に付けない。
JSON全体を必ず完結させること（途中で切れてはいけない）。

### 通常の会話（デフォルト — ほとんどの場合こちら）
短い説明、質問への端的な回答、確認、あいさつ等に使います。
{"type":"talk","text":"会話テキスト（改行は\\nで表現OK）"}

### 構造化アドバイス（詳しい分析・改善提案が必要な場合のみ）
改善提案やデータ分析のまとめに使います。必ず短く・具体的に。
{"type":"advice","summary":"一言要約（30文字以内）","sections":[{"title":"📊 わかったこと","text":"結論を1〜2文で"},{"title":"💡 その理由","items":["理由1","理由2"]},{"title":"✅ 今すぐやること","items":["具体的アクション1","具体的アクション2"]}]}

構造化アドバイスの厳守ルール：
- sections は最大3つまで（多すぎると読まれない）
- 各 items は最大3つまで
- 各 item は1〜2文で簡潔に書く
- summary は30文字以内
- 必ずJSON全体を完結させる（途中で切れるぐらいなら sections を減らす）

## 使い分けの判断基準
talk を使う:
- あいさつ・お礼への返答
- 用語の説明
- はい/いいえで答えられる質問
- 確認や聞き返し
- 短い感想やコメント

advice を使う:
- 「改善策を教えて」「やることリスト」のような具体的施策の依頼
- 複数のポイントを整理して伝えたい分析結果

迷ったら talk。ユーザーが詳しく知りたそうなら、次の返答で advice を使えばOK。

## 改修作業の案内（support_notice）— 最重要ルール
このサービスのお客様は「ホームページの制作・管理を弊社に委託している」方です。
お客様はWordPressの管理画面にはアクセスできますが、できることは限られています。
ホームページの改善を提案する際、以下の基準で必ず判定してください。

お客様自身でできる作業は「投稿系コンテンツの更新だけ」です（support_notice 不要）:
- ブログ記事・お知らせの新規投稿や編集
- 施工事例・実績紹介など「投稿タイプ」で管理されているコンテンツの追加・編集

上記以外のホームページに関する改善・変更はすべて弊社の専門スタッフが対応します。
以下の場合は必ず "support_notice": true を付けてください:
- ページの新規作成（新しいページを作りたい等）
- ナビゲーション・メニュー構造の変更（メニュー項目の追加・並び替え等）
- トップページや固定ページの内容・レイアウト変更
- HTML・CSS・デザインの修正
- フォームの変更・機能追加
- サイト構造に関わるあらゆる変更
- 「どうすればいい？」と聞かれた改善内容が投稿の追加・編集だけでは実現できない場合

重要: 迷ったら必ず support_notice: true を付けること。付けすぎて問題はないが、付け忘れは問題。
重要: support_notice: true の場合、お客様自身での操作手順を案内してはいけない。「専門のスタッフにお任せください」という方向で回答すること。

JSON例: {"type":"talk","text":"ナビゲーションへのページ追加ですね！これは専門のスタッフがきれいに仕上げますので、お気軽にご相談ください。","support_notice":true}
JSON例: {"type":"advice","summary":"...","sections":[...],"support_notice":true}

## 共通ルール
1. 必ず有効なJSONのみを返す（JSON以外の文字を前後に絶対付けない）
2. 専門用語は一切使わない（上の言い換え表に従う）
3. データが不十分な場合は「推測ですが」と明記する
4. やさしい口調で、初心者に寄り添う伴走感を大切にする
5. advice の sections は最大3つ、各 items も最大3つ（短くまとめる）
6. 回答は必ず完結させる（途中で切れるぐらいなら短くする）

## 回答対象のルール（最重要）
- あなたが改善を提案する対象は、常に「クライアントのWebサイト（ホームページ）」です
- この管理画面（みまもりウェブ）自体のUI・デザイン・使い勝手の改善は絶対に提案しません
- レポートや分析画面の数字は「判断材料」であり、改善すべき対象ではありません
- ユーザーが「改善点」「問題点」と聞いた場合、それは管理画面の批評を求めているのではなく、クライアントのWebサイト集客・問い合わせ増加のための改善を求めています

## 追加質問のルール（重要）
あなたにはGA4やSearch Consoleのデータが自動的に提供されます。以下の情報は既に手元にあるか、自動取得されるため、ユーザーに聞いてはいけません:
- アクセス数・PV・セッション・ユーザー数
- 検索キーワード・表示回数・クリック数・検索順位
- 流入チャネル（検索・SNS・直接流入など）の内訳
- ページ別のアクセス数
- デバイス別（PC/スマホ）の比率
- 地域別のアクセス
- 前期比の増減

ユーザーに追加で質問してよいのは、APIでは取得できないビジネス情報だけです:
- 事業の目標（例: 「月の問い合わせ目標は何件ですか？」）
- ターゲット顧客層（例: 「主なお客様はどんな方ですか？」）
- 商圏・対象エリア（例: 「サービス対象地域はどのあたりですか？」）
- 競合他社や差別化ポイント
- 最近の施策やキャンペーン（例: 「最近チラシ配布やSNS投稿をされましたか？」）
- 季節性やイベントの影響

追加質問は回答の最後に1つだけ、かつ分析の結論を先に述べてから聞くこと。データで答えられる質問を投げ返すのは禁止。

## 分析回答のテンプレート（データ分析・改善提案が必要な場合）
データに基づく分析を求められた場合、以下の順序で回答すること:
1. まず結論（何が起きているか・何をすべきか）
2. データの根拠（「直近28日のデータを見ると〜」のように具体的数値を引用）
3. 仮説（なぜそうなっているかの推測、「推測ですが」と前置き）
4. おすすめのアクション（具体的に1〜3つ）
5. 確認質問（必要な場合のみ。APIで取得できないビジネス情報について1つだけ）

この順序は「結論→根拠→仮説→アクション→確認」と覚えてください。
データが提供されている場合は必ずデータを引用して結論を述べ、先にユーザーに聞き返さないこと。

## 参照データについて（推測禁止ルール）
質問内容に応じて、GA4やSearch Consoleの実データがこのプロンプトの末尾に付与されます。

最重要ルール: GA4で取得可能な質問（国/地域/参照元/ページ/日別/チャネル等）には、必ずGA4の実数値だけで回答すること。
- データが提供されている場合: 数字を引用して断定的に回答する。「〜と思われます」は使わず「〜です」「〜でした」と事実を述べる
  良い例：「9月5日のアクセスはUnited Statesからが245セッション（全体の78.2%）で最も多いです」
  良い例：「直帰率が98.5%と極端に高く、スパムの可能性があります」
- データが提供されていない / 取得に失敗した場合: 推測回答は一切せず「GA4データを確認できませんでした（理由: ○○）」と伝える
- 「〜かもしれません」「〜と考えられます」「〜でしょう」などの推測表現は、GA4で確認可能な数値について使用禁止
- 推測が許されるのは、GA4では分からないビジネス上の仮説（例: 「季節要因かもしれません」）のみ

データの出典やシステム内部の判断ロジックには絶対に言及しない:
  悪い例：「チェックボックスがONだったので〜」
  悪い例：「自動判断によりGA4データを参照しました」
  悪い例：「フレキシブルクエリで取得した結果によると〜」

## 異常値・スパムに関する回答ルール
データに「異常値の可能性」の注記が付いている場合:
- まず事実（国名・セッション数・構成比）を提示する
- 次に異常値のシグナル（低エンゲージメント率、高直帰率、短い滞在時間等）を具体的数値で示す
- 「スパムまたはbotアクセスの可能性があります」と注記する（断定ではなく可能性として）
- 最後に、次に確認すべきGA4レポート（参照元/メディア、ランディングページ等）を提案する

## ページへの言及ルール
ページについて回答するとき、必ず**ページタイトル**で言及すること。
順位番号やURLパスだけで伝えるのは禁止。
  良い例：「一番見られているのは『施工事例 | ○○工務店』で、月に95回見られています」
  良い例：「『お問い合わせ』ページへのアクセスが先月より増えています」
  悪い例：「1位が95回の見られ方です」
  悪い例：「/works/ が最もアクセスが多いです」
データにタイトルとURLの両方がある場合は、タイトルを主体にし、必要に応じてURLを補足する程度にする。
PROMPT;
}

/**
 * 会話コンテキスト構築（history + 現メッセージ → OpenAI input 配列）
 *
 * @param array $data  REST リクエストのJSONパラメータ
 * @return array       OpenAI Responses API の input 配列
 */
function mimamori_build_chat_context( array $data ): array {
    $input = [];

    // 過去の会話履歴（最大50メッセージ = 25往復まで）
    if ( ! empty( $data['history'] ) && is_array( $data['history'] ) ) {
        $history = array_slice( $data['history'], -50 );
        foreach ( $history as $msg ) {
            if ( ! is_array( $msg ) || empty( $msg['content'] ) ) {
                continue;
            }
            $role    = ( isset( $msg['role'] ) && $msg['role'] === 'assistant' ) ? 'assistant' : 'user';
            $content = sanitize_textarea_field( $msg['content'] );
            if ( $content !== '' ) {
                $input[] = [ 'role' => $role, 'content' => $content ];
            }
        }
    }

    // 現在のメッセージ
    $message = sanitize_textarea_field( $data['message'] ?? '' );
    if ( $message !== '' ) {
        $input[] = [ 'role' => 'user', 'content' => $message ];
    }

    return $input;
}

/**
 * OpenAI Responses API 呼び出し
 *
 * @param array $payload  ['model'=>..., 'instructions'=>..., 'input'=>[...]]
 * @return array|WP_Error  成功時: ['text'=>string], 失敗時: WP_Error
 */
function mimamori_call_openai_responses_api( array $payload ) {
    $api_key  = defined( 'MIMAMORI_OPENAI_API_KEY' )  ? MIMAMORI_OPENAI_API_KEY  : '';
    $base_url = defined( 'MIMAMORI_OPENAI_BASE_URL' ) ? MIMAMORI_OPENAI_BASE_URL : 'https://api.openai.com/v1';
    $timeout  = defined( 'MIMAMORI_OPENAI_TIMEOUT' )  ? (int) MIMAMORI_OPENAI_TIMEOUT : 60;

    $model = $payload['model'] ?? '(none)';
    $url   = rtrim( $base_url, '/' ) . '/responses';

    if ( $api_key === '' ) {
        error_log( '[みまもりAI] ERROR: MIMAMORI_OPENAI_API_KEY is empty' );
        return new WP_Error( 'no_api_key', 'OpenAI APIキーが設定されていません' );
    }

    $response = wp_remote_post( $url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
        'timeout' => $timeout,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[みまもりAI] HTTP Error: ' . $response->get_error_message() );
        return new WP_Error( 'openai_http_error', 'AI サービスに接続できませんでした。しばらくしてからお試しください。' );
    }

    $code     = wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );
    $body     = json_decode( $raw_body, true );

    if ( $code !== 200 ) {
        // --- 詳細エラーをログに記録（フロントには出さない） ---
        $err_type = $body['error']['type']    ?? 'unknown';
        $err_code = $body['error']['code']    ?? 'unknown';
        $err_msg  = $body['error']['message'] ?? 'No message';
        error_log( sprintf(
            '[みまもりAI] OpenAI API Error — HTTP %d, type=%s, code=%s, message=%s',
            $code,
            $err_type,
            $err_code,
            mb_substr( $err_msg, 0, 500 )
        ) );

        // フロント向けメッセージ（管理者のみ詳細、一般ユーザーは汎用文言）
        if ( current_user_can( 'manage_options' ) ) {
            $front_msg = sprintf( 'AI設定エラー (HTTP %d / %s): %s', $code, $err_code, mb_substr( $err_msg, 0, 200 ) );
        } else {
            $front_msg = 'AI機能に一時的な問題が発生しています。管理者にお問い合わせください。';
        }

        return new WP_Error( 'openai_error', $front_msg );
    }

    // Responses API: output[0].content[0].text
    $text = '';
    if ( ! empty( $body['output'] ) && is_array( $body['output'] ) ) {
        foreach ( $body['output'] as $item ) {
            if ( ( $item['type'] ?? '' ) === 'message' && ! empty( $item['content'] ) ) {
                foreach ( $item['content'] as $part ) {
                    if ( ( $part['type'] ?? '' ) === 'output_text' ) {
                        $text = $part['text'];
                        break 2;
                    }
                }
            }
        }
    }

    if ( $text === '' ) {
        error_log( '[みまもりAI] Empty response body: ' . mb_substr( $raw_body, 0, 500 ) );
        return new WP_Error( 'empty_response', 'AIから空の応答が返されました' );
    }

    return [ 'text' => $text ];
}

/**
 * 音声ファイルを OpenAI Whisper API で文字起こしする
 *
 * @param string $file_path  音声ファイルの絶対パス
 * @param string $language   言語コード（デフォルト: ja）
 * @return array|WP_Error    成功時: ['text'=>string], 失敗時: WP_Error
 */
function mimamori_call_whisper_api( string $file_path, string $language = 'ja', string $mime_type = '', string $filename = '' ) {
    $api_key  = defined( 'MIMAMORI_OPENAI_API_KEY' )  ? MIMAMORI_OPENAI_API_KEY  : '';
    $base_url = defined( 'MIMAMORI_OPENAI_BASE_URL' ) ? MIMAMORI_OPENAI_BASE_URL : 'https://api.openai.com/v1';
    $timeout  = defined( 'MIMAMORI_OPENAI_TIMEOUT' )  ? (int) MIMAMORI_OPENAI_TIMEOUT : 60;

    if ( $api_key === '' ) {
        return new WP_Error( 'no_api_key', 'OpenAI APIキーが設定されていません' );
    }

    if ( ! file_exists( $file_path ) ) {
        return new WP_Error( 'file_not_found', '音声ファイルが見つかりません' );
    }

    // MIME タイプとファイル名をデフォルト設定（Whisper はファイル拡張子で形式を判定する）
    if ( $mime_type === '' ) {
        $finfo     = new finfo( FILEINFO_MIME_TYPE );
        $mime_type = $finfo->file( $file_path ) ?: 'audio/mp4';
    }
    if ( $filename === '' ) {
        // MIME からファイル名を推定
        $ext_map = [
            'audio/webm' => 'webm', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/m4a'  => 'm4a',  'audio/ogg' => 'ogg', 'audio/mpeg'  => 'mp3',
            'audio/mpga' => 'mp3',  'audio/mp3' => 'mp3', 'audio/wav'   => 'wav',
            'audio/flac' => 'flac', 'video/webm' => 'webm',
            'audio/aac'  => 'm4a',  'audio/x-caf' => 'm4a',
            'application/octet-stream' => 'm4a',
        ];
        $base_mime = preg_replace( '/;.*$/', '', $mime_type ); // codecs 除去
        $ext       = $ext_map[ $base_mime ] ?? 'm4a';
        $filename  = 'audio.' . $ext;
    }

    $url = rtrim( $base_url, '/' ) . '/audio/transcriptions';

    // cURL を使用（wp_remote_post はマルチパートファイルアップロードが煩雑なため）
    $ch = curl_init();
    curl_setopt_array( $ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => [
            'file'            => new CURLFile( $file_path, $mime_type, $filename ),
            'model'           => 'whisper-1',
            'language'        => $language,
            'response_format' => 'json',
        ],
    ] );

    $response = curl_exec( $ch );
    $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $curl_err  = curl_error( $ch );
    curl_close( $ch );

    if ( $response === false || $curl_err !== '' ) {
        return new WP_Error( 'curl_error', '音声認識サーバーとの通信に失敗しました: ' . $curl_err );
    }

    $body = json_decode( $response, true );

    if ( $http_code !== 200 ) {
        $err_msg = $body['error']['message'] ?? ( 'Whisper API Error (HTTP ' . $http_code . ')' );
        return new WP_Error( 'whisper_error', $err_msg );
    }

    $text = isset( $body['text'] ) ? trim( $body['text'] ) : '';

    if ( $text === '' ) {
        return new WP_Error( 'empty_transcription', '音声を認識できませんでした。もう一度お試しください。' );
    }

    return [ 'text' => $text ];
}

/**
 * 音声文字起こし REST ハンドラ
 *
 * POST /wp-json/mimamori/v1/voice-transcribe
 * Content-Type: multipart/form-data
 * Body: audio=<file>
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function mimamori_handle_voice_transcribe( WP_REST_Request $request ): WP_REST_Response {
    $files = $request->get_file_params();

    if ( empty( $files['audio'] ) || empty( $files['audio']['tmp_name'] ) ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => '音声ファイルが送信されていません',
        ], 400 );
    }

    $file = $files['audio'];

    // エラーチェック
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => 'ファイルアップロードに失敗しました（コード: ' . $file['error'] . '）',
        ], 400 );
    }

    // サイズチェック（25MB = Whisper API 上限）
    $max_size = 25 * 1024 * 1024;
    if ( $file['size'] > $max_size ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => '音声ファイルが大きすぎます（上限: 25MB）',
        ], 400 );
    }

    // MIMEタイプチェック
    $allowed_mimes = [
        'audio/webm', 'audio/mp4', 'audio/mpeg', 'audio/mpga',
        'audio/ogg', 'audio/wav', 'audio/x-m4a', 'audio/m4a',
        'audio/mp3', 'audio/aac', 'audio/x-caf', 'audio/flac',
        'video/webm', 'video/mp4',  // Chrome は video/webm、iOS は video/mp4 で送ることがある
        'application/octet-stream',  // finfo が音声ファイルを正しく識別できない場合
    ];
    $finfo = new finfo( FILEINFO_MIME_TYPE );
    $detected_mime = $finfo->file( $file['tmp_name'] );
    $browser_mime  = sanitize_text_field( $file['type'] ?? '' );

    // finfo 判定 or ブラウザ送信MIMEのいずれかがOKなら通す
    $mime_ok = in_array( $detected_mime, $allowed_mimes, true )
            || in_array( $browser_mime, $allowed_mimes, true );

    if ( ! $mime_ok ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => 'サポートされていない音声形式です（' . $detected_mime . '）',
        ], 400 );
    }

    // Whisper API に渡すMIMEは、ブラウザ送信のほうが正確な場合が多い（finfo は音声を誤判定しやすい）
    if ( $detected_mime === 'application/octet-stream' && $browser_mime !== '' ) {
        $detected_mime = $browser_mime;
    }

    // Whisper API 呼び出し（ファイル拡張子で形式判定されるため元のファイル名を渡す）
    $original_name = sanitize_file_name( $file['name'] ?? 'audio.m4a' );
    $result = mimamori_call_whisper_api( $file['tmp_name'], 'ja', $detected_mime, $original_name );

    if ( is_wp_error( $result ) ) {
        $status = ( $result->get_error_code() === 'no_api_key' ) ? 500 : 502;
        return new WP_REST_Response( [
            'success' => false,
            'message' => $result->get_error_message(),
        ], $status );
    }

    return new WP_REST_Response( [
        'success' => true,
        'data'    => [
            'text' => $result['text'],
        ],
    ], 200 );
}

/**
 * AI応答テキストを構造化データにパースする（堅牢版）
 *
 * type=talk  → 対話形式（テキストのみ）
 * type=advice → 構造化アドバイス（サマリー + セクション）
 *
 * パース戦略:
 * 1. ```json ブロック抽出
 * 2. 先頭テキスト付き JSON（{ を探す）
 * 3. 途中切れ JSON の自動修復
 * 4. JSONから読めるテキストを抽出して talk fallback
 * ※ 生の JSON を chat に表示しない
 *
 * @param string $raw_text  AIからの生テキスト
 * @return array  { type: 'talk'|'advice', text?: string, summary?: string, sections?: array }
 */
function mimamori_parse_ai_response( string $raw_text ): array {
    $cleaned = trim( $raw_text );

    // --- Step 1: ```json ... ``` ブロック抽出 ---
    if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $cleaned, $m ) ) {
        $cleaned = trim( $m[1] );
    }

    // --- Step 2: 先頭に余分テキストがある場合 → 最初の { を見つける ---
    $brace_pos = strpos( $cleaned, '{' );
    if ( $brace_pos === false ) {
        // JSON が一切含まれていない → プレーンテキストとして返す
        return [
            'type' => 'talk',
            'text' => $raw_text,
        ];
    }
    $json_candidate = substr( $cleaned, $brace_pos );

    // --- Step 3: まずそのままパースを試みる ---
    $parsed = json_decode( $json_candidate, true );

    // --- Step 4: 失敗時 → 途中切れ JSON の修復を試みる ---
    if ( ! is_array( $parsed ) ) {
        $repaired = mimamori_repair_truncated_json( $json_candidate );
        $parsed   = json_decode( $repaired, true );
    }

    // --- Step 5: それでも失敗 → JSON 内のテキスト値を抽出して talk fallback ---
    if ( ! is_array( $parsed ) ) {
        $extracted = mimamori_extract_text_from_broken_json( $json_candidate );
        return [
            'type' => 'talk',
            'text' => $extracted !== '' ? $extracted : $raw_text,
        ];
    }

    // --- パース成功: type に応じて返す ---
    return mimamori_build_parsed_result( $parsed, $raw_text );
}

/**
 * パース済み配列から構造化結果を構築する（共通ロジック）
 *
 * @param array  $parsed    json_decode 成功後の配列
 * @param string $raw_text  元の生テキスト（最終 fallback 用）
 * @return array
 */
function mimamori_build_parsed_result( array $parsed, string $raw_text ): array {
    $type           = $parsed['type'] ?? '';
    $support_notice = ! empty( $parsed['support_notice'] );

    // --- talk: 対話形式 ---
    if ( $type === 'talk' && isset( $parsed['text'] ) ) {
        $result = [
            'type' => 'talk',
            'text' => (string) $parsed['text'],
        ];
        if ( $support_notice ) {
            $result['support_notice'] = true;
        }
        return $result;
    }

    // --- advice: 構造化アドバイス ---
    if ( ( $type === 'advice' || $type === '' ) && isset( $parsed['summary'] ) ) {
        $sections = mimamori_normalize_sections( $parsed['sections'] ?? [] );
        $result = [
            'type'     => 'advice',
            'summary'  => (string) $parsed['summary'],
            'sections' => $sections,
        ];
        if ( $support_notice ) {
            $result['support_notice'] = true;
        }
        return $result;
    }

    // --- その他のJSON → 中のテキスト値を探して talk fallback ---
    $text = $parsed['text'] ?? $parsed['summary'] ?? $parsed['message'] ?? '';
    if ( $text === '' ) {
        $text = $raw_text;
    }
    $result = [
        'type' => 'talk',
        'text' => (string) $text,
    ];
    if ( $support_notice ) {
        $result['support_notice'] = true;
    }
    return $result;
}

/**
 * sections 配列を安全に正規化する
 *
 * @param mixed $raw_sections
 * @return array
 */
function mimamori_normalize_sections( $raw_sections ): array {
    if ( ! is_array( $raw_sections ) ) {
        return [];
    }
    $sections = [];
    foreach ( $raw_sections as $sec ) {
        if ( ! is_array( $sec ) || empty( $sec['title'] ) ) {
            continue;
        }
        $s = [ 'title' => (string) $sec['title'] ];
        if ( ! empty( $sec['items'] ) && is_array( $sec['items'] ) ) {
            $s['items'] = array_map( 'strval', $sec['items'] );
        } elseif ( ! empty( $sec['text'] ) ) {
            $s['text'] = (string) $sec['text'];
        }
        $sections[] = $s;
    }
    return $sections;
}

/**
 * 途中切れ JSON を閉じ括弧で修復する
 *
 * トークン制限で応答が途中で切れた場合、開き括弧に対応する
 * 閉じ括弧/引用符を補って json_decode 可能にする。
 *
 * @param string $json  途中切れの可能性がある JSON 文字列
 * @return string       修復済み JSON 文字列
 */
function mimamori_repair_truncated_json( string $json ): string {
    // 文字列の途中で切れている場合 → 閉じ引用符を補う
    $in_string = false;
    $escape    = false;
    $len       = strlen( $json );
    for ( $i = 0; $i < $len; $i++ ) {
        $ch = $json[ $i ];
        if ( $escape ) {
            $escape = false;
            continue;
        }
        if ( $ch === '\\' && $in_string ) {
            $escape = true;
            continue;
        }
        if ( $ch === '"' ) {
            $in_string = ! $in_string;
        }
    }
    if ( $in_string ) {
        $json .= '"';
    }

    // 最後の不完全な key: value ペアを除去（例: ,"items":[ の途中）
    // 末尾カンマやコロンの後が不完全な場合に備える
    $json = preg_replace( '/,\s*"[^"]*"\s*:\s*$/', '', $json );
    $json = preg_replace( '/,\s*$/', '', $json );

    // 開き括弧と閉じ括弧のバランスを修復
    $stack = [];
    $in_str = false;
    $esc    = false;
    $len    = strlen( $json );
    for ( $i = 0; $i < $len; $i++ ) {
        $ch = $json[ $i ];
        if ( $esc ) {
            $esc = false;
            continue;
        }
        if ( $ch === '\\' && $in_str ) {
            $esc = true;
            continue;
        }
        if ( $ch === '"' ) {
            $in_str = ! $in_str;
            continue;
        }
        if ( $in_str ) {
            continue;
        }
        if ( $ch === '{' || $ch === '[' ) {
            $stack[] = $ch;
        } elseif ( $ch === '}' ) {
            if ( end( $stack ) === '{' ) {
                array_pop( $stack );
            }
        } elseif ( $ch === ']' ) {
            if ( end( $stack ) === '[' ) {
                array_pop( $stack );
            }
        }
    }

    // 逆順に閉じ括弧を補う
    while ( ! empty( $stack ) ) {
        $open = array_pop( $stack );
        $json .= ( $open === '{' ) ? '}' : ']';
    }

    return $json;
}

/**
 * 壊れた JSON 文字列から読めるテキスト値を抽出する
 *
 * パースに完全に失敗した場合でも、"text": "..." や "summary": "..." の
 * 値を正規表現で拾い、ユーザーに意味のあるテキストを返す。
 * 生の JSON をそのまま表示しない。
 *
 * @param string $broken_json
 * @return string  抽出テキスト（見つからなければ空文字）
 */
function mimamori_extract_text_from_broken_json( string $broken_json ): string {
    $parts = [];

    // "text": "..." or "summary": "..." を抽出
    if ( preg_match( '/"summary"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $broken_json, $m ) ) {
        $parts[] = stripcslashes( $m[1] );
    }
    if ( preg_match( '/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $broken_json, $m ) ) {
        $parts[] = stripcslashes( $m[1] );
    }

    // "items": ["...", "..."] からテキスト抽出
    if ( preg_match_all( '/"items"\s*:\s*\[(.*?)\]/s', $broken_json, $matches ) ) {
        foreach ( $matches[1] as $items_str ) {
            if ( preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/s', $items_str, $item_m ) ) {
                foreach ( $item_m[1] as $item ) {
                    $parts[] = '・' . stripcslashes( $item );
                }
            }
        }
    }

    // "title": "..." も拾って見出し風に
    if ( preg_match_all( '/"title"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $broken_json, $title_m ) ) {
        // title は parts の先頭に挿入するより、既に items が拾えていれば十分
        // parts が空の場合のみ title を使う
        if ( empty( $parts ) ) {
            foreach ( $title_m[1] as $t ) {
                $parts[] = stripcslashes( $t );
            }
        }
    }

    return implode( "\n", $parts );
}

/**
 * ============================================================
 * 自動判断ロジック — 質問分類・データソース解決・コンテキスト構築
 * ============================================================
 */

/**
 * 質問内容からデータソースの必要性を自動判定する（キーワードベース）
 *
 * @param string $message  ユーザーの質問テキスト
 * @return array  ['needs_page' => bool, 'needs_analytics' => bool]
 */
function mimamori_classify_question( string $message ): array {
    $needs_page      = false;
    $needs_analytics = false;

    // ページ文脈系キーワード
    $page_keywords = [
        'このページ', 'この画面', '今見ている', '見ている画面',
        '表示中', 'ここの', 'このサイトの',
        'ページの改善', '改善点を', '見にくい', '分かりにくい',
        'レイアウト', 'デザイン', '構成', '導線',
    ];

    // 数値・推移・比較系キーワード
    $analytics_keywords = [
        // 指標名
        'アクセス数', 'アクセスデータ', 'PV', 'ページビュー',
        'セッション', 'ユーザー数', '訪問者', '訪問数',
        'CTR', 'クリック率', '表示回数', 'インプレッション',
        '検索順位', '順位', '掲載順位',
        'コンバージョン', 'CV数', '成果', '問い合わせ数',
        '直帰率', '離脱率', '滞在時間', 'エンゲージメント',
        // 分析
        '流入', '検索キーワード', 'クエリ', '検索ワード',
        'デバイス別', 'モバイル', 'スマホ比率',
        '地域別', 'エリア別', '都道府県',
        '年齢層', '年齢別', 'デモグラ',
        // 時系列
        '先月', '前月', '前年', '推移', '増えた', '減った',
        '下がった', '上がった', '比較', '変化', '伸び',
        // ツール名
        'GA4', 'GSC', 'サーチコンソール', 'アナリティクス',
        // 数値・データ参照
        '数字', '数値', 'データを見', '実際の数', '何件', '何人',
        'うちのサイト', '自社サイト', 'うちの',
    ];

    $msg = mb_strtolower( $message );

    foreach ( $page_keywords as $kw ) {
        if ( mb_strpos( $msg, mb_strtolower( $kw ) ) !== false ) {
            $needs_page = true;
            break;
        }
    }

    foreach ( $analytics_keywords as $kw ) {
        if ( mb_strpos( $msg, mb_strtolower( $kw ) ) !== false ) {
            $needs_analytics = true;
            break;
        }
    }

    return [
        'needs_page'      => $needs_page,
        'needs_analytics' => $needs_analytics,
    ];
}

/**
 * データソースの使用可否を決定する（AI自動判断）
 *
 * 質問内容・意図から自動的にデータソースの要否を判定する。
 * force_* 引数は後方互換のため残すが、通常は false を渡す。
 *
 * @param array $classification  mimamori_rewrite_intent() の返り値（needs_page / needs_analytics 含む）
 * @param bool  $force_page      ページ文脈の強制指定（将来用・通常 false）
 * @param bool  $force_analytics  GA4/GSCの強制指定（将来用・通常 false）
 * @return array ['use_page_context' => bool, 'use_analytics' => bool]
 */
function mimamori_resolve_data_sources( array $classification, bool $force_page, bool $force_analytics ): array {
    return [
        'use_page_context' => $force_page      || $classification['needs_page'],
        'use_analytics'    => $force_analytics  || $classification['needs_analytics'],
    ];
}

/**
 * 表示中ページの文脈情報を取得する
 *
 * @param array $current_page  JS から送信される { url, title }
 * @return string  コンテキストテキスト（空文字 = 取得不可）
 */
function mimamori_get_page_context( array $current_page ): string {
    $url   = isset( $current_page['url'] )   ? esc_url_raw( $current_page['url'] )            : '';
    $title = isset( $current_page['title'] ) ? sanitize_text_field( $current_page['title'] )  : '';

    if ( $url === '' ) {
        return '';
    }

    $path = wp_parse_url( $url, PHP_URL_PATH );
    if ( ! $path ) {
        return '';
    }
    $path = rtrim( $path, '/' ) . '/';

    // 既知のダッシュボード / 分析ページへのマッピング
    $contexts = [
        '/mypage/dashboard/'         => 'ダッシュボード（月次KPIサマリー）',
        '/mypage/analysis/source/'   => '流入元分析（チャネル別・参照元別のアクセス内訳）',
        '/mypage/analysis/page/'     => 'ページ別分析（各ページのPV・滞在時間・CVR）',
        '/mypage/analysis/keywords/' => 'キーワード分析（検索クエリ・表示回数・CTR・順位）',
        '/mypage/analysis/region/'   => '地域別分析（都道府県ごとのアクセス・CV）',
        '/mypage/analysis/device/'   => 'デバイス別分析（PC / スマホ / タブレットの比率）',
        '/mypage/analysis/age/'      => '年齢層分析（訪問者の年齢層分布）',
        '/mypage/report/'            => '月次レポート（AI生成の詳細分析レポート）',
        '/mypage/actual-cv/'         => '実績CV入力（問い合わせ・電話等の実績登録）',
    ];

    foreach ( $contexts as $slug => $desc ) {
        if ( strpos( $path, $slug ) !== false ) {
            return 'ユーザーが現在表示中のページ: ' . $desc . "\nURL: " . $url;
        }
    }

    // WordPress 固定ページからタイトルを取得（フォールバック）
    $post_id = url_to_postid( $url );
    if ( $post_id > 0 ) {
        $post_title = get_the_title( $post_id );
        if ( $post_title ) {
            return 'ユーザーが現在表示中のページ: ' . $post_title . "\nURL: " . $url;
        }
    }

    if ( $title !== '' ) {
        return 'ユーザーが現在表示中のページ: ' . $title . "\nURL: " . $url;
    }

    return '';
}

/**
 * GA4 / GSC の要約ダイジェストを生成する
 *
 * 直近28日間の主要KPIと前期比、Search Console 上位キーワードを取得し、
 * AI に渡すテキスト形式に整形する。結果は Transient で 4 時間キャッシュ。
 *
 * @param int $user_id  WordPress ユーザーID
 * @return string  ダイジェストテキスト（空文字 = データなし or 未設定）
 */
function mimamori_get_analytics_digest( int $user_id ): string {
    // 必要クラスの存在チェック
    if ( ! class_exists( 'Gcrev_Config' ) ) {
        return '';
    }

    // キャッシュ確認（4時間TTL）
    $cache_key = 'mw_ai_digest_' . $user_id;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    try {
        $config      = new Gcrev_Config();
        $user_config = $config->get_user_config( $user_id );

        $ga4_id  = $user_config['ga4_id']  ?? '';
        $gsc_url = $user_config['gsc_url'] ?? '';

        if ( $ga4_id === '' && $gsc_url === '' ) {
            // 設定なし → 空キャッシュ（短め: 1時間）
            set_transient( $cache_key, '', HOUR_IN_SECONDS );
            return '';
        }

        // 日付範囲: 直近28日間 + 前28日間（比較用）
        $tz         = wp_timezone();
        $end        = new DateTime( 'now', $tz );
        $start      = ( clone $end )->modify( '-27 days' );
        $prev_end   = ( clone $start )->modify( '-1 day' );
        $prev_start = ( clone $prev_end )->modify( '-27 days' );

        $start_str      = $start->format( 'Y-m-d' );
        $end_str        = $end->format( 'Y-m-d' );
        $prev_start_str = $prev_start->format( 'Y-m-d' );
        $prev_end_str   = $prev_end->format( 'Y-m-d' );

        $lines = [];

        // --- GA4 サマリー ---
        if ( $ga4_id !== '' && class_exists( 'Gcrev_GA4_Fetcher' ) ) {
            $ga4     = new Gcrev_GA4_Fetcher( $config );
            $current  = $ga4->fetch_ga4_summary( $ga4_id, $start_str, $end_str );
            $previous = $ga4->fetch_ga4_summary( $ga4_id, $prev_start_str, $prev_end_str );

            if ( is_array( $current ) && ! is_wp_error( $current ) && ! empty( $current ) ) {
                $lines[] = '【GA4 アクセスデータ（直近28日間: ' . $start_str . ' 〜 ' . $end_str . '）】';

                $metrics = [
                    [ 'label' => 'ページビュー',     'key' => 'pageViews',   'raw' => '_pageViews' ],
                    [ 'label' => 'セッション',       'key' => 'sessions',    'raw' => '_sessions' ],
                    [ 'label' => 'ユーザー',         'key' => 'users',       'raw' => '_users' ],
                    [ 'label' => '新規ユーザー',     'key' => 'newUsers',    'raw' => '_newUsers' ],
                    [ 'label' => 'コンバージョン',   'key' => 'conversions', 'raw' => '_conversions' ],
                ];

                $prev_arr = ( is_array( $previous ) && ! is_wp_error( $previous ) ) ? $previous : [];

                foreach ( $metrics as $m ) {
                    $val  = $current[ $m['key'] ] ?? '0';
                    $line = '・' . $m['label'] . ': ' . $val;

                    // 前期比
                    $cur_raw  = (float) ( $current[ $m['raw'] ]  ?? 0 );
                    $prev_raw = (float) ( $prev_arr[ $m['raw'] ] ?? 0 );
                    if ( $prev_raw > 0 ) {
                        $change = ( ( $cur_raw - $prev_raw ) / $prev_raw ) * 100;
                        $sign   = $change >= 0 ? '+' : '';
                        $line  .= '（前期比 ' . $sign . number_format( $change, 1 ) . '%）';
                    }
                    $lines[] = $line;
                }

                // 追加指標
                if ( isset( $current['avgDuration'] ) ) {
                    $lines[] = '・平均滞在時間: ' . $current['avgDuration'] . '秒';
                }
                if ( isset( $current['engagementRate'] ) ) {
                    $lines[] = '・エンゲージメント率: ' . $current['engagementRate'];
                }
            }
        }

        // --- GSC キーワード ---
        if ( $gsc_url !== '' && class_exists( 'Gcrev_GSC_Fetcher' ) ) {
            $gsc      = new Gcrev_GSC_Fetcher( $config );
            $gsc_data = $gsc->fetch_gsc_data( $gsc_url, $start_str, $end_str );

            if ( is_array( $gsc_data ) && ! is_wp_error( $gsc_data ) && ! empty( $gsc_data ) ) {
                $lines[] = '';
                $lines[] = '【Search Console 検索データ（直近28日間）】';

                if ( ! empty( $gsc_data['total'] ) ) {
                    $lines[] = '・合計表示回数: ' . ( $gsc_data['total']['impressions'] ?? '0' );
                    $lines[] = '・合計クリック: ' . ( $gsc_data['total']['clicks'] ?? '0' );
                    $lines[] = '・平均CTR: '      . ( $gsc_data['total']['ctr'] ?? '0%' );
                }

                if ( ! empty( $gsc_data['keywords'] ) && is_array( $gsc_data['keywords'] ) ) {
                    $lines[] = '・主要キーワード（上位5件）:';
                    $top  = array_slice( $gsc_data['keywords'], 0, 5 );
                    $rank = 1;
                    foreach ( $top as $kw ) {
                        $lines[] = '  ' . $rank . '. ' . ( $kw['query'] ?? '?' )
                            . '（表示: ' . ( $kw['impressions'] ?? 0 )
                            . ' / クリック: ' . ( $kw['clicks'] ?? 0 )
                            . ' / 順位: ' . ( $kw['position'] ?? '-' ) . '）';
                        $rank++;
                    }
                }
            }
        }

        $digest = implode( "\n", $lines );

        // キャッシュ保存（4時間）
        if ( $digest !== '' ) {
            set_transient( $cache_key, $digest, 4 * HOUR_IN_SECONDS );
        } else {
            // データ取得できなかった場合も短めにキャッシュ
            set_transient( $cache_key, '', HOUR_IN_SECONDS );
        }

        return $digest;

    } catch ( \Exception $e ) {
        error_log( '[みまもりAI] Analytics digest error: ' . $e->getMessage() );
        return '';
    }
}

/**
 * 動的コンテキスト（ページ情報・GA4/GSCデータ）をテキストブロックとして組み立てる
 *
 * @param array  $sources       mimamori_resolve_data_sources() の返り値
 * @param array  $current_page  JS から送信される { url, title }
 * @param int    $user_id       WordPress ユーザーID
 * @return string  コンテキストブロック（空文字 = データなし）
 */
function mimamori_build_data_context( array $sources, array $current_page, int $user_id ): string {
    $blocks  = [];
    $ref_list = [];

    // ページ文脈
    if ( ! empty( $sources['use_page_context'] ) ) {
        $page_ctx = mimamori_get_page_context( $current_page );
        if ( $page_ctx !== '' ) {
            $blocks[]   = "【表示中ページ情報】\n" . $page_ctx;
            $ref_list[] = '表示中ページの情報';
        }
    }

    // GA4 / GSC ダイジェスト
    if ( ! empty( $sources['use_analytics'] ) ) {
        $digest = mimamori_get_analytics_digest( $user_id );
        if ( $digest !== '' ) {
            $blocks[]   = $digest;
            $ref_list[] = 'GA4 / Search Console のデータ（直近28日間）';
        }
    }

    if ( empty( $blocks ) ) {
        return '';
    }

    // データ宣言 + データ本体
    $declaration = "【今回の回答で参照できる情報】\n- " . implode( "\n- ", $ref_list );

    return "---\n"
        . "以下は今回の回答で参照可能なデータです。必要に応じて活用してください。\n\n"
        . $declaration . "\n\n"
        . implode( "\n\n", $blocks );
}

/**
 * ============================================================
 * 意図補正（Intent Rewriter）— ページ種別判定・意図自動補正・コンテキスト統合
 * ============================================================
 */

/**
 * 表示中ページの種別を判定する
 *
 * @param array $current_page  JS から送信される { url, title }
 * @return string  'report_dashboard' | 'analysis_detail' | 'settings' | 'unknown'
 */
function mimamori_detect_page_type( array $current_page ): string {
    $url = $current_page['url'] ?? '';
    if ( $url === '' ) {
        return 'unknown';
    }

    $path = wp_parse_url( $url, PHP_URL_PATH );
    if ( ! $path ) {
        return 'unknown';
    }
    $path = rtrim( $path, '/' ) . '/';

    // 順序重要: 具体的なパターンを先に判定（/report-setting を /report/ より先に）
    $rules = [
        // settings（/report/ との衝突回避のため先に判定）
        '/report-setting'  => 'settings',
        '/account/'        => 'settings',
        '/gbp-oauth'       => 'settings',
        // report_dashboard
        '/dashboard/'      => 'report_dashboard',
        '/report/'         => 'report_dashboard',
        '/report-latest/'  => 'report_dashboard',
        '/meo-dashboard/'  => 'report_dashboard',
        '/meo/'            => 'report_dashboard',
        // analysis_detail
        '/analysis/'       => 'analysis_detail',
        '/analysis-'       => 'analysis_detail',
        '/actual-cv/'      => 'analysis_detail',
    ];

    foreach ( $rules as $pattern => $type ) {
        if ( strpos( $path, $pattern ) !== false ) {
            return $type;
        }
    }

    return 'unknown';
}

/**
 * ユーザーの質問とページ種別から意図を補正する（Intent Rewriter）
 *
 * 返り値:
 *   intent          — 'site_improvement' | 'report_interpretation' | 'reason_analysis' | 'how_to' | 'general'
 *   target          — 'client_site' | 'settings' | 'general'
 *   needs_page      — ページ文脈が必要か
 *   needs_analytics — GA4/GSCデータが必要か
 *
 * 強制ルール:
 *   report_dashboard / analysis_detail で「改善点」等の曖昧ワード
 *   → 管理画面UI改善ではなく、クライアントWebサイト改善として解釈
 *
 * @param string $message    ユーザーの質問テキスト
 * @param string $page_type  mimamori_detect_page_type() の返り値
 * @return array
 */
function mimamori_rewrite_intent( string $message, string $page_type ): array {
    $msg = mb_strtolower( $message );

    // --- キーワード群 ---

    // 改善・施策系（曖昧ワード → 意図補正の対象）
    $improvement_kw = [
        '改善', '何をすべき', 'どうしたらいい', '問題', '対策', '施策',
        '打ち手', '優先度', '伸ばす', '増やす', '上げる',
        'アドバイス', '提案', 'おすすめ', 'やること', 'TODO',
    ];

    // ネガティブ変動（下落・悪化）
    $negative_kw = [
        '下がった', '落ちた', '悪い', '減った', '低い', '少ない', '悪化',
    ];

    // 数値確認・解釈
    $eval_kw = [
        '良い？', '良いですか', 'どう？', 'どうですか', '大丈夫',
        'どのくらい', '平均', '目安', '基準', '高い？', '低い？',
    ];

    // 原因・理由
    $reason_kw = [
        'なぜ', '理由', '原因', 'どうして', 'なんで', '要因',
    ];

    // 操作方法・概念
    $howto_kw = [
        'やり方', '方法', 'どうやって', 'どこで見', '見方', '使い方',
        'とは', 'って何', 'って何ですか', '意味', '説明',
    ];

    // GA4/GSCデータが必要な質問
    $analytics_kw = [
        'アクセス', 'PV', 'ページビュー', 'セッション', 'ユーザー数', '訪問',
        'CTR', 'クリック率', '表示回数', 'インプレッション', '検索順位', '順位',
        'コンバージョン', 'CV', '成果', '問い合わせ数',
        '直帰率', '離脱率', '滞在時間', 'エンゲージメント',
        '流入', '検索キーワード', 'クエリ', '検索ワード',
        'デバイス', 'モバイル', 'スマホ',
        '地域', 'エリア', '都道府県',
        '国', '海外', '都市', 'どこから',
        '参照元', 'ソース', 'ランディング',
        'スパム', 'bot', 'ボット',
        '年齢', 'デモグラ',
        '先月', '前月', '前年', '推移', '比較', '変化',
        'GA4', 'GSC', 'サーチコンソール', 'アナリティクス',
        '数字', '数値', 'データ', '何件', '何人',
        'うちのサイト', '自社サイト', 'うちの',
    ];

    // ページ文脈系
    $page_kw = [
        'このページ', 'この画面', '今見ている', '見ている画面',
        '表示中', 'ここの', 'このサイトの',
    ];

    // --- キーワード判定 ---
    $has_improvement = mimamori_has_keyword( $msg, $improvement_kw );
    $has_negative    = mimamori_has_keyword( $msg, $negative_kw );
    $has_eval        = mimamori_has_keyword( $msg, $eval_kw );
    $has_reason      = mimamori_has_keyword( $msg, $reason_kw );
    $has_howto       = mimamori_has_keyword( $msg, $howto_kw );
    $has_analytics   = mimamori_has_keyword( $msg, $analytics_kw );
    $has_page        = mimamori_has_keyword( $msg, $page_kw );

    // --- 意図判定 ---
    $intent          = 'general';
    $target          = 'general';
    $needs_page      = false;
    $needs_analytics = false;

    // 1) 操作方法・概念説明（最優先: 「とは」「って何」がある場合）
    if ( $has_howto && ! $has_improvement && ! $has_negative && ! $has_reason ) {
        $intent = 'how_to';
        $target = 'general';
        // how_to は基本データ不要。ただし analytics キーワードがあれば参考程度に
    }
    // 2) 原因・理由分析
    elseif ( $has_reason || ( $has_negative && $has_analytics ) ) {
        $intent          = 'reason_analysis';
        $target          = 'client_site';
        $needs_analytics = true;
        $needs_page      = ( $page_type === 'report_dashboard' || $page_type === 'analysis_detail' );
    }
    // 3) 数値の評価・解釈（「この数字は良い？」系）
    elseif ( $has_eval && $has_analytics && ! $has_improvement ) {
        $intent          = 'report_interpretation';
        $target          = 'client_site';
        $needs_analytics = true;
        $needs_page      = ( $page_type === 'report_dashboard' || $page_type === 'analysis_detail' );
    }
    // 4) 改善・施策系（★ 意図補正の核心）
    elseif ( $has_improvement || $has_negative ) {
        $intent          = 'site_improvement';
        $needs_analytics = true;  // 改善提案にはデータが必要

        if ( $page_type === 'report_dashboard' || $page_type === 'analysis_detail' ) {
            // ★ 強制ルール: レポート/分析画面では管理画面UIではなくクライアントサイト改善
            $target     = 'client_site';
            $needs_page = true;
        } elseif ( $page_type === 'settings' ) {
            $target          = 'settings';
            $needs_page      = true;
            $needs_analytics = false;  // 設定画面ではGA4不要
        } else {
            $target = 'client_site';
        }
    }
    // 5) データ関連の質問（明確な分析キーワードあり）
    elseif ( $has_analytics ) {
        $intent          = 'report_interpretation';
        $target          = 'client_site';
        $needs_analytics = true;
        $needs_page      = $has_page;
    }
    // 6) ページ文脈のみ（「このページの〜」）
    elseif ( $has_page ) {
        // ページ系だがレポート/分析画面の場合はサイト改善として解釈
        if ( $page_type === 'report_dashboard' || $page_type === 'analysis_detail' ) {
            $intent          = 'site_improvement';
            $target          = 'client_site';
            $needs_page      = true;
            $needs_analytics = true;
        } else {
            $intent     = 'general';
            $target     = 'general';
            $needs_page = true;
        }
    }

    return [
        'intent'          => $intent,
        'target'          => $target,
        'needs_page'      => $needs_page,
        'needs_analytics' => $needs_analytics,
    ];
}

/**
 * メッセージ内にキーワード群のいずれかが含まれるか判定する
 *
 * @param string $message_lower  mb_strtolower 済みのメッセージ
 * @param array  $keywords       検索キーワード配列
 * @return bool
 */
function mimamori_has_keyword( string $message_lower, array $keywords ): bool {
    foreach ( $keywords as $kw ) {
        if ( mb_strpos( $message_lower, mb_strtolower( $kw ) ) !== false ) {
            return true;
        }
    }
    return false;
}

/**
 * ページ種別・意図補正・データソースを統合したコンテキストブロックを生成する
 *
 * mimamori_build_data_context() の上位互換。意図補正メッセージ（ガードレール）を含む。
 *
 * @param string $page_type      mimamori_detect_page_type() の返り値
 * @param array  $intent_result  mimamori_rewrite_intent() の返り値
 * @param array  $sources        mimamori_resolve_data_sources() の返り値
 * @param array  $current_page   JS から送信される { url, title }
 * @param int    $user_id        WordPress ユーザーID
 * @return string  instructions に追記するテキスト（空文字 = 追記なし）
 */
function mimamori_build_context_blocks(
    string $page_type,
    array  $intent_result,
    array  $sources,
    array  $current_page,
    int    $user_id,
    ?array $section_context = null
): string {
    $blocks   = [];
    $ref_list = [];

    // --- Block 1: ページ種別の宣言 ---
    $page_type_labels = [
        'report_dashboard' => 'レポート / ダッシュボード画面（月次サマリー・KPI・ふりかえり）',
        'analysis_detail'  => '詳細分析画面（流入元・キーワード・ページ別・地域・デバイス・CV等）',
        'settings'         => '設定画面（GA4/GSC連携・レポート設定など）',
        'unknown'          => 'その他のページ',
    ];
    $blocks[] = '【現在のページ種別】' . ( $page_type_labels[ $page_type ] ?? 'その他のページ' );

    // --- Block 2: 意図補正の宣言（★ ズレ回答防止の核心） ---
    $intent = $intent_result['intent'] ?? 'general';
    $target = $intent_result['target'] ?? 'general';

    if ( $target === 'client_site' && in_array( $page_type, [ 'report_dashboard', 'analysis_detail' ], true ) ) {
        $blocks[] = "【重要：回答の対象について】\n"
            . "ユーザーが見ている画面はレポート・分析ツール（みまもりウェブ管理画面）です。\n"
            . "この画面自体のUI/デザイン/使い勝手の改善は絶対に提案しないでください。\n"
            . "ユーザーの質問は「このレポート/分析結果を踏まえ、クライアントのWebサイト（ホームページ）をどう改善すべきか」として解釈してください。\n"
            . "改善対象 = クライアントのWebサイト（集客・問い合わせ増加のためのホームページ改善）です。";
    } elseif ( $target === 'settings' ) {
        $blocks[] = "【回答の対象について】\n"
            . "ユーザーは設定画面を見ています。\n"
            . "質問が改善に関するものであれば、設定の見直し（GA4/GSC連携・計測設定・目標設定など）についてアドバイスしてください。\n"
            . "管理画面のUIデザインの批評はしないでください。";
    }

    // 意図カテゴリの補足（AIが質問を正しく解釈するためのヒント）
    $intent_hints = [
        'site_improvement'       => 'ユーザーはクライアントWebサイトの改善策を求めています。手元のデータから現状を分析し、結論→根拠→仮説→アクションの順序で具体的に提案してください。データで答えられることをユーザーに聞き返してはいけません。',
        'report_interpretation'  => 'ユーザーは数値の意味・良し悪しを知りたがっています。業界平均との比較や解釈を交えて説明してください。',
        'reason_analysis'        => 'ユーザーは数値の変動理由を知りたがっています。提供されたデータから考えられる要因を自ら分析し、結論→根拠→仮説→アクションの順序で回答してください。データで確認できることをユーザーに聞き返してはいけません。',
    ];
    if ( isset( $intent_hints[ $intent ] ) ) {
        $blocks[] = '【質問の意図】' . $intent_hints[ $intent ];
    }

    // --- Block 2.5: セクションコンテキスト（「AIに聞く」ボタンから渡されたレポートセクション内容） ---
    if ( $section_context !== null && $section_context['sectionBody'] !== '' ) {
        $section_label_map = [
            'summary'          => '結論サマリー',
            'highlight_good'   => '今月うまくいっていること（ハイライト）',
            'highlight_issue'  => '今いちばん気をつけたい点（ハイライト）',
            'highlight_action' => '次にやるとよいこと（ハイライト）',
            'report_summary'   => '総評',
            'report_good'      => '良かった点（成果）',
            'report_issue'     => '改善が必要な点（課題）',
        ];
        $section_label = $section_label_map[ $section_context['sectionType'] ]
                       ?? ( $section_context['sectionTitle'] ?: 'レポートセクション' );

        $section_block  = "【ユーザーが確認中のレポートセクション】\n";
        $section_block .= "セクション名: {$section_label}\n";
        if ( $section_context['sectionTitle'] !== '' && $section_context['sectionTitle'] !== $section_label ) {
            $section_block .= "見出し: {$section_context['sectionTitle']}\n";
        }
        $section_block .= "表示内容:\n{$section_context['sectionBody']}";

        $blocks[]   = $section_block;
        $ref_list[] = 'ユーザーが閲覧中のレポートセクション内容';
    }

    // --- Block 3: ページ文脈情報 ---
    if ( ! empty( $sources['use_page_context'] ) ) {
        $page_ctx = mimamori_get_page_context( $current_page );
        if ( $page_ctx !== '' ) {
            $blocks[]   = "【表示中ページ情報】\n" . $page_ctx;
            $ref_list[] = '表示中ページの情報';
        }
    }

    // --- Block 4: GA4/GSC ダイジェスト ---
    if ( ! empty( $sources['use_analytics'] ) ) {
        $digest = mimamori_get_analytics_digest( $user_id );
        if ( $digest !== '' ) {
            $blocks[]   = $digest;
            $ref_list[] = 'GA4 / Search Console のデータ（直近28日間）';
        }
    }

    if ( empty( $blocks ) ) {
        return '';
    }

    // --- ヘッダー ---
    $header = "---\n以下は今回の回答に使用するコンテキストです。";
    if ( ! empty( $ref_list ) ) {
        $header .= "\n\n【参照データ】\n- " . implode( "\n- ", $ref_list );
    }

    return "\n\n" . $header . "\n\n" . implode( "\n\n", $blocks );
}

/**
 * ============================================================
 * 2パス動的データ取得 — プランナー・バリデーション・データ取得・整形
 * ============================================================
 */

/**
 * プランナーパス用のシステムプロンプトを生成する
 *
 * @return string
 */
function mimamori_get_planner_prompt( string $intent = 'general' ): string {
    $today = wp_date( 'Y-m-d' );

    // 意図に応じた追加指示
    $intent_instruction = '';
    switch ( $intent ) {
        case 'reason_analysis':
            $intent_instruction = "\n\n"
                . "重要: ユーザーは数値の変動理由を分析したいと考えています。\n"
                . "原因分析には多角的なデータが必要です。以下を参考にクエリを選んでください:\n"
                . "- daily_traffic（いつ変動が起きたか特定）\n"
                . "- channel_breakdown（どのチャネルが増減したか）\n"
                . "- page_breakdown（どのページに影響があったか）\n"
                . "- gsc_keywords（検索キーワードの変動）\n"
                . "最低2件のクエリを出力してください。空配列は禁止です。";
            break;
        case 'site_improvement':
            $intent_instruction = "\n\n"
                . "重要: ユーザーはサイトの改善策を求めています。\n"
                . "改善提案にはサイトの現状把握が必要です。以下を参考にクエリを選んでください:\n"
                . "- page_breakdown（どのページが効果的か/課題か）\n"
                . "- channel_breakdown（集客チャネルのバランス）\n"
                . "- gsc_keywords（検索流入の質と量）\n"
                . "- device_breakdown（デバイス別のUX差）\n"
                . "最低2件のクエリを出力してください。空配列は禁止です。";
            break;
        case 'report_interpretation':
            $intent_instruction = "\n\n"
                . "重要: ユーザーは数値の意味・良し悪しを知りたがっています。\n"
                . "評価に必要なデータを取得してください。最低1件のクエリを出力してください。";
            break;
    }

    return <<<PROMPT
あなたはデータクエリプランナーです。ユーザーの質問に正確に回答するために、追加で取得すべきGA4/GSCデータを判断します。

今日の日付: {$today}

利用可能なクエリタイプ:
- daily_traffic: 日別アクセス推移（PV/セッション/ユーザー/CV の日別データ）
- page_breakdown: ページ別PVランキング（どのページが何回見られたか）
- device_breakdown: デバイス別統計（PC/スマホ/タブレットの内訳）
- device_daily: デバイス×日別のセッション推移
- channel_breakdown: 流入チャネル/参照元別（検索/直接/SNS等）のセッション・CV
- region_breakdown: 地域（都道府県）別のセッション・CV
- gsc_keywords: Google検索キーワード詳細（どんな言葉で検索されたか・表示回数・クリック数・順位、上位200件）

ルール:
1. 質問に答えるために本当に必要なクエリだけを選ぶ（最大5件）
2. 28日間のサマリー（総PV/セッション/ユーザー/上位5キーワード）は既に持っている。それで十分なら空配列を返す
3. 各クエリに日付範囲（start/end）を指定する。日付指定がない一般的な質問なら直近28日間を使う
4. 特定の日付に関する質問の場合、その前後を含む適切な範囲を設定する（例: 「1月19日」→ 1月10日〜1月25日程度）
5. 必ず有効なJSONのみを返す。JSON以外のテキストを前後に付けない
6. 比較データが必要な場合、compare フラグを true にする。システムが同じ長さの前期間データを自動取得する
{$intent_instruction}
出力形式（厳守）:
{"queries":[{"type":"クエリタイプ","start":"YYYY-MM-DD","end":"YYYY-MM-DD","compare":true}]}

compare は省略可能（省略時は false 扱い）。原因分析や改善提案の場合は true を推奨。

追加データ不要の場合:
{"queries":[]}
PROMPT;
}

/**
 * プランナーのレスポンスをバリデーション・正規化する
 *
 * @param array $raw_queries  プランナーが返した queries 配列
 * @return array  バリデーション済みクエリ配列（最大3件）
 */
function mimamori_validate_planner_queries( array $raw_queries ): array {
    $allowed_types = [
        'daily_traffic',
        'page_breakdown',
        'device_breakdown',
        'device_daily',
        'channel_breakdown',
        'region_breakdown',
        'gsc_keywords',
    ];

    $tz    = wp_timezone();
    $today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
    $d28   = ( new DateTime( 'now', $tz ) )->modify( '-27 days' )->format( 'Y-m-d' );

    $validated = [];
    $seen      = [];

    foreach ( $raw_queries as $q ) {
        if ( ! is_array( $q ) ) {
            continue;
        }

        $type = $q['type'] ?? '';
        if ( ! in_array( $type, $allowed_types, true ) ) {
            continue;
        }

        // 重複排除
        if ( isset( $seen[ $type ] ) ) {
            continue;
        }
        $seen[ $type ] = true;

        // 日付バリデーション
        $start = ( isset( $q['start'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $q['start'] ) )
            ? $q['start'] : $d28;
        $end   = ( isset( $q['end'] )   && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $q['end'] ) )
            ? $q['end']   : $today;

        // start > end なら入れ替え
        if ( $start > $end ) {
            [ $start, $end ] = [ $end, $start ];
        }

        // 未来日は今日に補正
        if ( $end > $today ) {
            $end = $today;
        }

        // 最大92日間に制限
        $start_dt = new DateTime( $start, $tz );
        $end_dt   = new DateTime( $end, $tz );
        $diff     = (int) $start_dt->diff( $end_dt )->days;
        if ( $diff > 92 ) {
            $start = $end_dt->modify( '-92 days' )->format( 'Y-m-d' );
        }

        // 2年以上前は拒否
        $two_years_ago = ( new DateTime( 'now', $tz ) )->modify( '-2 years' )->format( 'Y-m-d' );
        if ( $start < $two_years_ago ) {
            $start = $two_years_ago;
        }

        $validated[] = [
            'type'    => $type,
            'start'   => $start,
            'end'     => $end,
            'compare' => ! empty( $q['compare'] ),
        ];

        // 最大5件
        if ( count( $validated ) >= 5 ) {
            break;
        }
    }

    return $validated;
}

/**
 * プランナーパス: 追加データクエリの判定をAIに依頼する
 *
 * @param string $message  ユーザーの質問テキスト
 * @param string $digest   既存の28日サマリー
 * @param string $intent   意図タイプ（'reason_analysis' | 'site_improvement' | 'report_interpretation' | 'general'）
 * @return array  バリデーション済みクエリ配列
 */
function mimamori_call_planner_pass( string $message, string $digest, string $intent = 'general' ): array {
    $planner_model = defined( 'MIMAMORI_OPENAI_PLANNER_MODEL' )
        ? MIMAMORI_OPENAI_PLANNER_MODEL
        : ( defined( 'MIMAMORI_OPENAI_MODEL' ) ? MIMAMORI_OPENAI_MODEL : 'gpt-4.1-mini' );

    // プランナーへの入力: 質問 + 意図ラベル + 既存データの概要
    $digest_summary = '';
    if ( $digest !== '' ) {
        $digest_summary = "\n\n既に持っているデータ:\n" . mb_substr( $digest, 0, 300 ) . '...（省略）';
    } else {
        $digest_summary = "\n\n既に持っているデータ: 28日サマリーなし（GA4/GSC未設定の可能性）";
    }

    $intent_label = '';
    if ( $intent !== 'general' ) {
        $intent_labels = [
            'reason_analysis'       => '原因分析',
            'site_improvement'      => '改善提案',
            'report_interpretation' => '数値解釈',
        ];
        $intent_label = "\n質問の意図: " . ( $intent_labels[ $intent ] ?? $intent );
    }

    $input = [
        [
            'role'    => 'user',
            'content' => '質問: ' . $message . $intent_label . $digest_summary,
        ],
    ];

    $result = mimamori_call_openai_responses_api( [
        'model'             => $planner_model,
        'instructions'      => mimamori_get_planner_prompt( $intent ),
        'input'             => $input,
        'max_output_tokens' => 256,
    ] );

    if ( is_wp_error( $result ) ) {
        error_log( '[みまもりAI] Planner pass failed: ' . $result->get_error_message() );
        return [];
    }

    $text = $result['text'] ?? '';

    // JSON パース（{ を探す → json_decode）
    $brace = strpos( $text, '{' );
    if ( $brace === false ) {
        return [];
    }
    $json_str = substr( $text, $brace );
    $parsed   = json_decode( $json_str, true );

    if ( ! is_array( $parsed ) || ! isset( $parsed['queries'] ) || ! is_array( $parsed['queries'] ) ) {
        return [];
    }

    return mimamori_validate_planner_queries( $parsed['queries'] );
}

/**
 * 単一クエリのデータ取得ヘルパー
 *
 * switch/case を共通化し、当期・前期の両方で使い回す。
 *
 * @param string $type     クエリタイプ
 * @param string $start    開始日（YYYY-MM-DD）
 * @param string $end      終了日（YYYY-MM-DD）
 * @param mixed  $ga4      Gcrev_GA4_Fetcher インスタンス（null 可）
 * @param string $ga4_id   GA4 プロパティID
 * @param string $gsc_url  GSC サイトURL
 * @param mixed  $config   Gcrev_Config インスタンス
 * @return array|null  取得データ（空/エラー時は null）
 */
function mimamori_fetch_single_query( string $type, string $start, string $end, $ga4, string $ga4_id, string $gsc_url, $config ) {
    if ( ! $ga4 && $type !== 'gsc_keywords' ) {
        return null;
    }

    $data = null;

    switch ( $type ) {
        case 'daily_traffic':
            $data = $ga4->fetch_ga4_daily_series( $ga4_id, $start, $end );
            break;

        case 'page_breakdown':
            $data = $ga4->fetch_page_details( $ga4_id, $start, $end, $gsc_url );
            break;

        case 'device_breakdown':
            $data = $ga4->fetch_device_details( $ga4_id, $start, $end );
            break;

        case 'device_daily':
            $data = $ga4->fetch_device_daily_series( $ga4_id, $start, $end );
            break;

        case 'channel_breakdown':
            $data = $ga4->fetch_source_data_from_ga4( $ga4_id, $start, $end );
            break;

        case 'region_breakdown':
            $data = $ga4->fetch_region_details( $ga4_id, $start, $end );
            break;

        case 'gsc_keywords':
            if ( $gsc_url !== '' && class_exists( 'Gcrev_GSC_Fetcher' ) ) {
                $gsc  = new Gcrev_GSC_Fetcher( $config );
                $data = $gsc->fetch_gsc_data( $gsc_url, $start, $end );
            }
            break;
    }

    if ( is_array( $data ) && ! is_wp_error( $data ) && ! empty( $data ) ) {
        return $data;
    }

    return null;
}

/**
 * プランナーが指定したクエリを実行し、追加データを取得する
 *
 * compare フラグが true のクエリは、同じ長さの前期間データも自動取得する。
 *
 * @param array $queries   バリデーション済みクエリ配列
 * @param int   $user_id   WordPress ユーザーID
 * @return array  [['type'=>..., 'start'=>..., 'end'=>..., 'data'=>..., 'prev_data'=>...?], ...]
 */
function mimamori_fetch_enrichment_data( array $queries, int $user_id ): array {
    if ( ! class_exists( 'Gcrev_Config' ) ) {
        return [];
    }

    try {
        $config      = new Gcrev_Config();
        $user_config = $config->get_user_config( $user_id );
        $ga4_id      = $user_config['ga4_id']  ?? '';
        $gsc_url     = $user_config['gsc_url'] ?? '';

        if ( $ga4_id === '' ) {
            return [];
        }
    } catch ( \Exception $e ) {
        error_log( '[みまもりAI] Enrichment config error: ' . $e->getMessage() );
        return [];
    }

    $ga4 = class_exists( 'Gcrev_GA4_Fetcher' ) ? new Gcrev_GA4_Fetcher( $config ) : null;

    $results = [];

    foreach ( $queries as $q ) {
        $type    = $q['type'];
        $start   = $q['start'];
        $end     = $q['end'];
        $compare = ! empty( $q['compare'] );

        // --- 当期データ取得 ---
        $cache_key = 'mw_ai_extra_' . $user_id . '_' . $type . '_' . $start . '_' . $end;
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            $data = $cached;
        } else {
            try {
                $data = mimamori_fetch_single_query( $type, $start, $end, $ga4, $ga4_id, $gsc_url, $config );
                if ( $data !== null ) {
                    set_transient( $cache_key, $data, 2 * HOUR_IN_SECONDS );
                }
            } catch ( \Exception $e ) {
                error_log( '[みまもりAI] Enrichment fetch error (' . $type . '): ' . $e->getMessage() );
                $data = null;
            }
        }

        if ( $data === null ) {
            continue;
        }

        $result_item = [
            'type'  => $type,
            'start' => $start,
            'end'   => $end,
            'data'  => $data,
        ];

        // --- 前期データの自動取得（compare = true の場合） ---
        if ( $compare ) {
            try {
                $tz       = wp_timezone();
                $start_dt = new DateTime( $start, $tz );
                $end_dt   = new DateTime( $end, $tz );
                $days     = (int) $start_dt->diff( $end_dt )->days;

                $prev_end_dt   = ( clone $start_dt )->modify( '-1 day' );
                $prev_start_dt = ( clone $prev_end_dt )->modify( '-' . $days . ' days' );
                $prev_start    = $prev_start_dt->format( 'Y-m-d' );
                $prev_end      = $prev_end_dt->format( 'Y-m-d' );

                // 前期キャッシュチェック
                $prev_cache_key = 'mw_ai_extra_' . $user_id . '_' . $type . '_' . $prev_start . '_' . $prev_end;
                $prev_cached    = get_transient( $prev_cache_key );

                if ( $prev_cached !== false ) {
                    $prev_data = $prev_cached;
                } else {
                    $prev_data = mimamori_fetch_single_query( $type, $prev_start, $prev_end, $ga4, $ga4_id, $gsc_url, $config );
                    if ( $prev_data !== null ) {
                        set_transient( $prev_cache_key, $prev_data, 2 * HOUR_IN_SECONDS );
                    }
                }

                if ( $prev_data !== null ) {
                    $result_item['prev_start'] = $prev_start;
                    $result_item['prev_end']   = $prev_end;
                    $result_item['prev_data']  = $prev_data;
                }
            } catch ( \Exception $e ) {
                error_log( '[みまもりAI] Enrichment prev-period error (' . $type . '): ' . $e->getMessage() );
                // 前期取得失敗でも当期データは返す
            }
        }

        $results[] = $result_item;
    }

    return $results;
}

/**
 * 取得した追加データをAIに渡すテキスト形式に整形する
 *
 * @param array $enrichment_results  mimamori_fetch_enrichment_data() の返り値
 * @return string  追加データのテキストブロック（空文字 = データなし）
 */
function mimamori_format_enrichment_for_ai( array $enrichment_results ): string {
    $blocks = [];

    foreach ( $enrichment_results as $r ) {
        $type      = $r['type'];
        $start     = $r['start'];
        $end       = $r['end'];
        $data      = $r['data'];
        $range     = $start . ' 〜 ' . $end;
        $prev_data  = $r['prev_data']  ?? null;
        $prev_range = '';
        if ( $prev_data && isset( $r['prev_start'], $r['prev_end'] ) ) {
            $prev_range = $r['prev_start'] . ' 〜 ' . $r['prev_end'];
        }

        switch ( $type ) {
            case 'daily_traffic':
                $blocks[] = mimamori_format_daily_traffic( $data, $range );
                break;
            case 'page_breakdown':
                $blocks[] = mimamori_format_page_breakdown( $data, $range, $prev_data, $prev_range );
                break;
            case 'device_breakdown':
                $blocks[] = mimamori_format_device_breakdown( $data, $range, $prev_data, $prev_range );
                break;
            case 'device_daily':
                $blocks[] = mimamori_format_device_daily( $data, $range );
                break;
            case 'channel_breakdown':
                $blocks[] = mimamori_format_channel_breakdown( $data, $range, $prev_data, $prev_range );
                break;
            case 'region_breakdown':
                $blocks[] = mimamori_format_region_breakdown( $data, $range, $prev_data, $prev_range );
                break;
            case 'gsc_keywords':
                $blocks[] = mimamori_format_gsc_keywords( $data, $range, $prev_data, $prev_range );
                break;
        }
    }

    $blocks = array_filter( $blocks );
    if ( empty( $blocks ) ) {
        return '';
    }

    return "【追加で取得した詳細データ】\n\n" . implode( "\n\n", $blocks );
}

/**
 * 日別アクセス推移のフォーマット
 */
function mimamori_format_daily_traffic( array $data, string $range ): string {
    if ( empty( $data['labels'] ) ) {
        return '';
    }

    $lines   = [ '▼ 日別アクセス推移（' . $range . '）' ];
    $lines[] = '日付 | PV | セッション | ユーザー';

    $labels = $data['labels'] ?? [];
    $pvs    = $data['pageViews'] ?? $data['values']['pageViews'] ?? [];
    $sess   = $data['sessions']  ?? $data['values']['sessions']  ?? [];
    $users  = $data['users']     ?? $data['values']['users']     ?? [];

    $count = min( count( $labels ), 30 );
    for ( $i = 0; $i < $count; $i++ ) {
        $label = $labels[ $i ] ?? '';
        // YYYYMMDD → YYYY-MM-DD
        if ( strlen( $label ) === 8 && ctype_digit( $label ) ) {
            $label = substr( $label, 0, 4 ) . '-' . substr( $label, 4, 2 ) . '-' . substr( $label, 6, 2 );
        }
        $pv = $pvs[ $i ]   ?? 0;
        $se = $sess[ $i ]  ?? 0;
        $us = $users[ $i ] ?? 0;
        $lines[] = $label . ' | ' . $pv . ' | ' . $se . ' | ' . $us;
    }

    return implode( "\n", $lines );
}

/**
 * ページ別アクセスのフォーマット（上位20件）
 */
function mimamori_format_page_breakdown( array $data, string $range, ?array $prev_data = null, string $prev_range = '' ): string {
    if ( empty( $data ) ) {
        return '';
    }

    // fetch_page_details の返り値は配列の配列
    $pages = is_array( $data ) ? array_values( $data ) : [];
    if ( empty( $pages ) || ! is_array( $pages[0] ?? null ) ) {
        return '';
    }

    $lines   = [ '▼ ページ別アクセス（' . $range . '・上位20件）' ];
    $lines[] = '順位 | ページタイトル | URL | PV | セッション | 直帰率';

    $count = min( count( $pages ), 20 );
    for ( $i = 0; $i < $count; $i++ ) {
        $p     = $pages[ $i ];
        $title = $p['title']     ?? '';
        $path  = $p['page']      ?? $p['pagePath'] ?? $p['path'] ?? '?';
        $pv    = $p['pageViews'] ?? $p['screenPageViews'] ?? $p['pv'] ?? 0;
        $se    = $p['sessions']  ?? 0;
        $br    = $p['bounceRate'] ?? '-';
        // タイトルが空ならパスをタイトル代わりに
        $display_title = ( $title !== '' && $title !== '(not set)' ) ? $title : $path;
        $lines[] = ( $i + 1 ) . ' | ' . $display_title . ' | ' . $path . ' | ' . $pv . ' | ' . $se . ' | ' . $br;
    }

    // 前期比サマリー
    if ( $prev_data && ! empty( $prev_data ) ) {
        $prev_pages  = is_array( $prev_data ) ? array_values( $prev_data ) : [];
        $prev_lookup = [];
        foreach ( $prev_pages as $pp ) {
            $ppath = $pp['page'] ?? $pp['pagePath'] ?? $pp['path'] ?? '';
            if ( $ppath !== '' ) {
                $prev_lookup[ $ppath ] = $pp;
            }
        }

        $lines[] = '';
        $lines[] = '▼ 前期比（' . $prev_range . ' との比較・上位10件）';
        $compare_count = min( $count, 10 );
        for ( $i = 0; $i < $compare_count; $i++ ) {
            $p       = $pages[ $i ];
            $path    = $p['page'] ?? $p['pagePath'] ?? $p['path'] ?? '';
            $title   = $p['title'] ?? $path;
            $display = ( $title !== '' && $title !== '(not set)' ) ? $title : $path;
            $cur_pv  = (int) ( $p['pageViews'] ?? $p['screenPageViews'] ?? $p['pv'] ?? 0 );

            if ( isset( $prev_lookup[ $path ] ) ) {
                $prev_pv = (int) ( $prev_lookup[ $path ]['pageViews'] ?? $prev_lookup[ $path ]['screenPageViews'] ?? $prev_lookup[ $path ]['pv'] ?? 0 );
                if ( $prev_pv > 0 ) {
                    $change = ( ( $cur_pv - $prev_pv ) / $prev_pv ) * 100;
                    $sign   = $change >= 0 ? '+' : '';
                    $lines[] = $display . ': ' . $cur_pv . 'PV（前期比 ' . $sign . number_format( $change, 1 ) . '%）';
                } else {
                    $lines[] = $display . ': ' . $cur_pv . 'PV（前期: 0PV）';
                }
            } else {
                $lines[] = $display . ': ' . $cur_pv . 'PV（新規ページ）';
            }
        }
    }

    return implode( "\n", $lines );
}

/**
 * デバイス別のフォーマット
 */
function mimamori_format_device_breakdown( array $data, string $range, ?array $prev_data = null, string $prev_range = '' ): string {
    if ( empty( $data ) ) {
        return '';
    }

    // 前期データの lookup
    $prev_lookup = [];
    if ( $prev_data ) {
        foreach ( $prev_data as $pd ) {
            if ( is_array( $pd ) ) {
                $pname = $pd['device'] ?? $pd['deviceCategory'] ?? $pd['name'] ?? '';
                if ( $pname !== '' ) {
                    $prev_lookup[ mb_strtolower( $pname ) ] = $pd;
                }
            }
        }
    }

    $lines   = [ '▼ デバイス別アクセス（' . $range . '）' ];
    $lines[] = 'デバイス | セッション | シェア | PV | 直帰率 | CV' . ( $prev_data ? ' | 前期比(セッション)' : '' );

    foreach ( $data as $d ) {
        if ( ! is_array( $d ) ) {
            continue;
        }
        $name  = $d['device'] ?? $d['deviceCategory'] ?? $d['name'] ?? '?';
        $sess  = $d['sessions']  ?? 0;
        $share = $d['share']     ?? $d['percentage'] ?? '-';
        $pv    = $d['pageViews'] ?? $d['screenPageViews'] ?? 0;
        $br    = $d['bounceRate'] ?? '-';
        $cv    = $d['conversions'] ?? $d['cv'] ?? 0;
        $line  = $name . ' | ' . $sess . ' | ' . $share . ' | ' . $pv . ' | ' . $br . ' | ' . $cv;

        if ( $prev_data ) {
            $key = mb_strtolower( $name );
            if ( isset( $prev_lookup[ $key ] ) ) {
                $prev_sess = (int) ( $prev_lookup[ $key ]['sessions'] ?? 0 );
                if ( $prev_sess > 0 ) {
                    $change = ( ( (int) $sess - $prev_sess ) / $prev_sess ) * 100;
                    $sign   = $change >= 0 ? '+' : '';
                    $line  .= ' | ' . $sign . number_format( $change, 1 ) . '%';
                } else {
                    $line .= ' | -';
                }
            } else {
                $line .= ' | 新規';
            }
        }

        $lines[] = $line;
    }

    return implode( "\n", $lines );
}

/**
 * デバイス×日別推移のフォーマット（最大20行）
 */
function mimamori_format_device_daily( array $data, string $range ): string {
    if ( empty( $data['labels'] ) ) {
        return '';
    }

    $lines   = [ '▼ デバイス別×日別セッション推移（' . $range . '）' ];
    $lines[] = '日付 | mobile | desktop | tablet';

    $labels  = $data['labels']  ?? [];
    $mobile  = $data['mobile']  ?? [];
    $desktop = $data['desktop'] ?? [];
    $tablet  = $data['tablet']  ?? [];

    $count = min( count( $labels ), 20 );
    for ( $i = 0; $i < $count; $i++ ) {
        $label = $labels[ $i ] ?? '';
        $m     = $mobile[ $i ]  ?? 0;
        $d     = $desktop[ $i ] ?? 0;
        $t     = $tablet[ $i ]  ?? 0;
        $lines[] = $label . ' | ' . $m . ' | ' . $d . ' | ' . $t;
    }

    return implode( "\n", $lines );
}

/**
 * 流入チャネルのフォーマット
 */
function mimamori_format_channel_breakdown( array $data, string $range, ?array $prev_data = null, string $prev_range = '' ): string {
    $parts = [];

    // 前期チャネル lookup
    $prev_ch_lookup = [];
    if ( $prev_data && ! empty( $prev_data['channels'] ) ) {
        foreach ( $prev_data['channels'] as $pch ) {
            $pname = $pch['channel'] ?? $pch['name'] ?? '';
            if ( $pname !== '' ) {
                $prev_ch_lookup[ mb_strtolower( $pname ) ] = $pch;
            }
        }
    }

    // チャネル別
    if ( ! empty( $data['channels'] ) && is_array( $data['channels'] ) ) {
        $lines   = [ '▼ 流入チャネル別（' . $range . '）' ];
        $lines[] = 'チャネル | セッション | PV | CV' . ( $prev_data ? ' | 前期比(セッション)' : '' );

        $count = min( count( $data['channels'] ), 10 );
        for ( $i = 0; $i < $count; $i++ ) {
            $ch   = $data['channels'][ $i ];
            $name = $ch['channel'] ?? $ch['name'] ?? '?';
            $sess = $ch['sessions'] ?? 0;
            $pv   = $ch['pageViews'] ?? $ch['screenPageViews'] ?? 0;
            $cv   = $ch['conversions'] ?? $ch['cv'] ?? 0;
            $line = $name . ' | ' . $sess . ' | ' . $pv . ' | ' . $cv;

            if ( $prev_data ) {
                $key = mb_strtolower( $name );
                if ( isset( $prev_ch_lookup[ $key ] ) ) {
                    $prev_sess = (int) ( $prev_ch_lookup[ $key ]['sessions'] ?? 0 );
                    if ( $prev_sess > 0 ) {
                        $change = ( ( (int) $sess - $prev_sess ) / $prev_sess ) * 100;
                        $sign   = $change >= 0 ? '+' : '';
                        $line  .= ' | ' . $sign . number_format( $change, 1 ) . '%';
                    } else {
                        $line .= ' | -';
                    }
                } else {
                    $line .= ' | 新規';
                }
            }

            $lines[] = $line;
        }
        $parts[] = implode( "\n", $lines );
    }

    // 参照元 TOP10
    if ( ! empty( $data['sources'] ) && is_array( $data['sources'] ) ) {
        $lines   = [ '▼ 参照元 TOP10（' . $range . '）' ];
        $lines[] = '参照元 | セッション | PV';

        $count = min( count( $data['sources'] ), 10 );
        for ( $i = 0; $i < $count; $i++ ) {
            $src  = $data['sources'][ $i ];
            $name = $src['source'] ?? $src['name'] ?? '?';
            $sess = $src['sessions'] ?? 0;
            $pv   = $src['pageViews'] ?? $src['screenPageViews'] ?? 0;
            $lines[] = $name . ' | ' . $sess . ' | ' . $pv;
        }
        $parts[] = implode( "\n", $lines );
    }

    return implode( "\n\n", $parts );
}

/**
 * 地域別のフォーマット（上位15件）
 */
function mimamori_format_region_breakdown( array $data, string $range, ?array $prev_data = null, string $prev_range = '' ): string {
    if ( empty( $data ) ) {
        return '';
    }

    // 前期データ lookup
    $prev_lookup = [];
    if ( $prev_data ) {
        $prev_items = is_array( $prev_data ) ? array_values( $prev_data ) : [];
        foreach ( $prev_items as $pr ) {
            if ( is_array( $pr ) ) {
                $pname = $pr['region'] ?? $pr['name'] ?? '';
                if ( $pname !== '' ) {
                    $prev_lookup[ $pname ] = $pr;
                }
            }
        }
    }

    $lines   = [ '▼ 地域別アクセス（' . $range . '・上位15件）' ];
    $lines[] = '地域 | セッション | ユーザー | PV | CV' . ( $prev_data ? ' | 前期比(セッション)' : '' );

    $items = is_array( $data ) ? array_values( $data ) : [];
    $count = min( count( $items ), 15 );

    for ( $i = 0; $i < $count; $i++ ) {
        $r    = $items[ $i ];
        if ( ! is_array( $r ) ) {
            continue;
        }
        $name  = $r['region'] ?? $r['name'] ?? '?';
        $sess  = $r['sessions']  ?? 0;
        $users = $r['users']     ?? $r['totalUsers'] ?? 0;
        $pv    = $r['pageViews'] ?? $r['screenPageViews'] ?? 0;
        $cv    = $r['conversions'] ?? $r['cv'] ?? 0;
        $line  = $name . ' | ' . $sess . ' | ' . $users . ' | ' . $pv . ' | ' . $cv;

        if ( $prev_data && isset( $prev_lookup[ $name ] ) ) {
            $prev_sess = (int) ( $prev_lookup[ $name ]['sessions'] ?? 0 );
            if ( $prev_sess > 0 ) {
                $change = ( ( (int) $sess - $prev_sess ) / $prev_sess ) * 100;
                $sign   = $change >= 0 ? '+' : '';
                $line  .= ' | ' . $sign . number_format( $change, 1 ) . '%';
            } else {
                $line .= ' | -';
            }
        } elseif ( $prev_data ) {
            $line .= ' | 新規';
        }

        $lines[] = $line;
    }

    return implode( "\n", $lines );
}

/**
 * GSC 検索キーワード詳細のフォーマット（上位30件）
 */
function mimamori_format_gsc_keywords( array $data, string $range, ?array $prev_data = null, string $prev_range = '' ): string {
    $lines = [];

    // 合計
    if ( ! empty( $data['total'] ) && is_array( $data['total'] ) ) {
        $lines[] = '▼ Google検索キーワード（' . $range . '）';
        $total_line = '合計表示回数: ' . ( $data['total']['impressions'] ?? 0 )
            . ' / 合計クリック: ' . ( $data['total']['clicks'] ?? 0 )
            . ' / 平均CTR: ' . ( $data['total']['ctr'] ?? '0%' );

        // 前期比（合計）
        if ( $prev_data && ! empty( $prev_data['total'] ) ) {
            $cur_clicks  = (int) ( $data['total']['clicks'] ?? 0 );
            $prev_clicks = (int) ( $prev_data['total']['clicks'] ?? 0 );
            $cur_imp     = (int) ( $data['total']['impressions'] ?? 0 );
            $prev_imp    = (int) ( $prev_data['total']['impressions'] ?? 0 );

            $parts = [];
            if ( $prev_clicks > 0 ) {
                $ch = ( ( $cur_clicks - $prev_clicks ) / $prev_clicks ) * 100;
                $parts[] = 'クリック前期比: ' . ( $ch >= 0 ? '+' : '' ) . number_format( $ch, 1 ) . '%';
            }
            if ( $prev_imp > 0 ) {
                $ch = ( ( $cur_imp - $prev_imp ) / $prev_imp ) * 100;
                $parts[] = '表示回数前期比: ' . ( $ch >= 0 ? '+' : '' ) . number_format( $ch, 1 ) . '%';
            }
            if ( ! empty( $parts ) ) {
                $total_line .= "\n" . implode( ' / ', $parts );
            }
        }

        $lines[] = $total_line;
        $lines[] = '';
    }

    // 前期キーワード lookup
    $prev_kw_lookup = [];
    if ( $prev_data && ! empty( $prev_data['keywords'] ) ) {
        foreach ( $prev_data['keywords'] as $pkw ) {
            $pq = $pkw['query'] ?? '';
            if ( $pq !== '' ) {
                $prev_kw_lookup[ $pq ] = $pkw;
            }
        }
    }

    // キーワード一覧
    if ( ! empty( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
        $lines[] = '順位 | 検索キーワード | 表示回数 | クリック | CTR | 掲載順位' . ( $prev_data ? ' | 前期比(クリック)' : '' );

        $count = min( count( $data['keywords'] ), 30 );
        for ( $i = 0; $i < $count; $i++ ) {
            $kw = $data['keywords'][ $i ];
            if ( ! is_array( $kw ) ) {
                continue;
            }
            $query = $kw['query']       ?? '?';
            $imp   = $kw['impressions'] ?? 0;
            $click = $kw['clicks']      ?? 0;
            $ctr   = $kw['ctr']         ?? '-';
            $pos   = $kw['position']    ?? '-';

            $line = ( $i + 1 ) . ' | ' . $query . ' | ' . $imp . ' | ' . $click . ' | ' . $ctr . ' | ' . $pos;

            if ( $prev_data ) {
                if ( isset( $prev_kw_lookup[ $query ] ) ) {
                    $prev_click = (int) ( $prev_kw_lookup[ $query ]['clicks'] ?? 0 );
                    if ( $prev_click > 0 ) {
                        $change = ( ( (int) $click - $prev_click ) / $prev_click ) * 100;
                        $sign   = $change >= 0 ? '+' : '';
                        $line  .= ' | ' . $sign . number_format( $change, 1 ) . '%';
                    } else {
                        $line .= ' | -';
                    }
                } else {
                    $line .= ' | 新規';
                }
            }

            $lines[] = $line;
        }
    }

    return ! empty( $lines ) ? implode( "\n", $lines ) : '';
}

/**
 * ============================================================
 * 確定的データプランナー（Deterministic Data Planner）
 *
 * ユーザーの質問からキーワードで必要なGA4クエリを確定的に判定する。
 * AI プランナーの補助として動作し、推測回答を排除する。
 * ============================================================
 */

/**
 * 確定的プランナー: 質問からGA4フレキシブルクエリを生成する
 *
 * キーワードマッチングで「国/都市/source/medium/ランディングページ」等の
 * 具体的ディメンションが必要か判定し、fetch_flexible_report() 用のクエリを返す。
 *
 * @param string $message    ユーザーの質問テキスト
 * @param string $intent_type  意図タイプ
 * @return array  フレキシブルクエリ配列 [['dimensions'=>[...], 'metrics'=>[...], 'start'=>..., 'end'=>..., 'label'=>...], ...]
 */
function mimamori_build_deterministic_queries( string $message, string $intent_type ): array {
    $msg = mb_strtolower( $message );

    $queries = [];

    // --- 日付の抽出 ---
    $date_range = mimamori_extract_date_range( $message );

    // --- キーワードマッチ → クエリ生成 ---

    // 国/海外系
    $country_kw = [ '国', 'どこの国', '海外', 'アメリカ', '米国', 'usa', 'united states', 'china', '中国',
                    'country', '外国', '国別', '国から', '国内', '国外', '海外アクセス', 'スパム' ];
    if ( mimamori_has_keyword( $msg, $country_kw ) ) {
        $queries[] = [
            'label'      => 'country_breakdown',
            'dimensions' => [ 'country' ],
            'metrics'    => [ 'sessions', 'totalUsers', 'engagementRate', 'averageSessionDuration', 'bounceRate', 'screenPageViews' ],
            'start'      => $date_range['start'],
            'end'        => $date_range['end'],
            'limit'      => 20,
        ];
    }

    // 都市/市区町村
    $city_kw = [ '都市', '市', '市区町村', 'city', 'どこから', 'エリア', '地域', '地方' ];
    if ( mimamori_has_keyword( $msg, $city_kw ) ) {
        $queries[] = [
            'label'      => 'city_breakdown',
            'dimensions' => [ 'city' ],
            'metrics'    => [ 'sessions', 'totalUsers', 'engagementRate', 'averageSessionDuration' ],
            'start'      => $date_range['start'],
            'end'        => $date_range['end'],
            'limit'      => 20,
        ];
    }

    // 参照元/ソース/メディア
    $source_kw = [ '参照元', 'ソース', 'メディア', 'source', 'medium', 'リファラー', 'referral',
                   'どこから来', 'どこ経由', '流入元', '流入経路', 'sns', 'twitter', 'instagram', 'facebook' ];
    if ( mimamori_has_keyword( $msg, $source_kw ) ) {
        $queries[] = [
            'label'      => 'source_medium',
            'dimensions' => [ 'sessionSource', 'sessionMedium' ],
            'metrics'    => [ 'sessions', 'totalUsers', 'engagementRate', 'bounceRate', 'screenPageViews' ],
            'start'      => $date_range['start'],
            'end'        => $date_range['end'],
            'limit'      => 20,
        ];
    }

    // ランディングページ
    $landing_kw = [ 'ランディングページ', '着地ページ', '最初のページ', 'ランディング', 'landing', 'lp',
                    'どのページに来', 'どのページから入' ];
    if ( mimamori_has_keyword( $msg, $landing_kw ) ) {
        $queries[] = [
            'label'      => 'landing_page',
            'dimensions' => [ 'landingPagePlusQueryString' ],
            'metrics'    => [ 'sessions', 'totalUsers', 'engagementRate', 'bounceRate' ],
            'start'      => $date_range['start'],
            'end'        => $date_range['end'],
            'limit'      => 20,
        ];
    }

    // 「どこから」「訪問」系の包括パターン（国もソースも含みうる → 両方取得）
    $where_from_kw = [ 'どこからの訪問', 'どこからのアクセス', 'どこからの流入',
                       'どこから来て', 'アクセス元', '訪問元' ];
    if ( mimamori_has_keyword( $msg, $where_from_kw ) && empty( $queries ) ) {
        // 国別 + ソース別の両方を取得
        $queries[] = [
            'label'      => 'country_breakdown',
            'dimensions' => [ 'country' ],
            'metrics'    => [ 'sessions', 'totalUsers', 'engagementRate', 'averageSessionDuration', 'bounceRate', 'screenPageViews' ],
            'start'      => $date_range['start'],
            'end'        => $date_range['end'],
            'limit'      => 20,
        ];
        $queries[] = [
            'label'      => 'source_medium',
            'dimensions' => [ 'sessionSource', 'sessionMedium' ],
            'metrics'    => [ 'sessions', 'totalUsers', 'engagementRate', 'bounceRate' ],
            'start'      => $date_range['start'],
            'end'        => $date_range['end'],
            'limit'      => 15,
        ];
    }

    return $queries;
}

/**
 * メッセージから日付範囲を抽出する
 *
 * 「2025年9月5日」「9/5」「9月」「先月」「今月」等のパターンを検出。
 * 見つからなければ直近28日間を返す。
 *
 * @param string $message  ユーザーの質問テキスト
 * @return array ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
 */
function mimamori_extract_date_range( string $message ): array {
    $tz    = wp_timezone();
    $today = new DateTime( 'now', $tz );

    // パターン1: YYYY年M月D日 or YYYY/M/D or YYYY-M-D
    if ( preg_match( '/(\d{4})\s*[年\/\-]\s*(\d{1,2})\s*[月\/\-]\s*(\d{1,2})\s*日?/', $message, $m ) ) {
        $date_str = sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
        return [ 'start' => $date_str, 'end' => $date_str ];
    }

    // パターン2: M月D日（年なし → 直近の該当日を推測）
    if ( preg_match( '/(\d{1,2})\s*月\s*(\d{1,2})\s*日/', $message, $m ) ) {
        $month = (int) $m[1];
        $day   = (int) $m[2];
        $year  = (int) $today->format( 'Y' );
        $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
        if ( $candidate > $today->format( 'Y-m-d' ) ) {
            $year--;
            $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
        }
        return [ 'start' => $candidate, 'end' => $candidate ];
    }

    // パターン3: M/D（年なし）
    if ( preg_match( '/(\d{1,2})\/(\d{1,2})/', $message, $m ) ) {
        $month = (int) $m[1];
        $day   = (int) $m[2];
        if ( $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 ) {
            $year  = (int) $today->format( 'Y' );
            $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
            if ( $candidate > $today->format( 'Y-m-d' ) ) {
                $year--;
                $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
            }
            return [ 'start' => $candidate, 'end' => $candidate ];
        }
    }

    // パターン4: YYYY年M月 or YYYY/M（月単位）
    if ( preg_match( '/(\d{4})\s*[年\/\-]\s*(\d{1,2})\s*月?(?!\s*\d)/', $message, $m ) ) {
        $year  = (int) $m[1];
        $month = (int) $m[2];
        $start = sprintf( '%04d-%02d-01', $year, $month );
        $end_dt = ( new DateTime( $start, $tz ) )->modify( 'last day of this month' );
        $end = $end_dt->format( 'Y-m-d' );
        if ( $end > $today->format( 'Y-m-d' ) ) {
            $end = $today->format( 'Y-m-d' );
        }
        return [ 'start' => $start, 'end' => $end ];
    }

    // パターン5: M月（年なし・月単位）
    if ( preg_match( '/(\d{1,2})\s*月(?!\s*\d)/', $message, $m ) ) {
        $month = (int) $m[1];
        $year  = (int) $today->format( 'Y' );
        $start = sprintf( '%04d-%02d-01', $year, $month );
        $end_dt = ( new DateTime( $start, $tz ) )->modify( 'last day of this month' );
        $end = $end_dt->format( 'Y-m-d' );
        if ( $end > $today->format( 'Y-m-d' ) ) {
            // 未来月なら前年
            $year--;
            $start = sprintf( '%04d-%02d-01', $year, $month );
            $end_dt = ( new DateTime( $start, $tz ) )->modify( 'last day of this month' );
            $end = $end_dt->format( 'Y-m-d' );
        }
        return [ 'start' => $start, 'end' => $end ];
    }

    // パターン6: 「先月」「前月」
    if ( preg_match( '/先月|前月/', $message ) ) {
        $first = ( clone $today )->modify( 'first day of last month' );
        $last  = ( clone $today )->modify( 'last day of last month' );
        return [ 'start' => $first->format( 'Y-m-d' ), 'end' => $last->format( 'Y-m-d' ) ];
    }

    // パターン7: 「今月」
    if ( preg_match( '/今月/', $message ) ) {
        $first = ( clone $today )->modify( 'first day of this month' );
        return [ 'start' => $first->format( 'Y-m-d' ), 'end' => $today->format( 'Y-m-d' ) ];
    }

    // パターン8: 「昨日」
    if ( preg_match( '/昨日/', $message ) ) {
        $yesterday = ( clone $today )->modify( '-1 day' )->format( 'Y-m-d' );
        return [ 'start' => $yesterday, 'end' => $yesterday ];
    }

    // デフォルト: 直近28日
    $d28 = ( clone $today )->modify( '-27 days' )->format( 'Y-m-d' );
    return [ 'start' => $d28, 'end' => $today->format( 'Y-m-d' ) ];
}

/**
 * フレキシブルクエリを実行する
 *
 * @param array $flex_queries  mimamori_build_deterministic_queries() の返り値
 * @param int   $user_id       WordPress ユーザーID
 * @return array  [['label'=>..., 'query_meta'=>..., 'rows'=>[...], 'totals'=>[...], 'row_count'=>int, 'error'=>string?], ...]
 */
function mimamori_execute_flexible_queries( array $flex_queries, int $user_id ): array {
    if ( empty( $flex_queries ) || ! class_exists( 'Gcrev_Config' ) ) {
        return [];
    }

    try {
        $config      = new Gcrev_Config();
        $user_config = $config->get_user_config( $user_id );
        $ga4_id      = $user_config['ga4_id'] ?? '';

        if ( $ga4_id === '' ) {
            error_log( '[みまもりAI] Flex query: GA4 property ID not configured for user ' . $user_id );
            return [ [ 'label' => 'error', 'error' => 'GA4プロパティIDが設定されていません。レポート設定画面でGA4連携を完了してください。' ] ];
        }
    } catch ( \Exception $e ) {
        error_log( '[みまもりAI] Flex query config error: ' . $e->getMessage() );
        return [ [ 'label' => 'error', 'error' => 'GA4設定の読み込みに失敗しました。' ] ];
    }

    $ga4     = class_exists( 'Gcrev_GA4_Fetcher' ) ? new Gcrev_GA4_Fetcher( $config ) : null;
    if ( ! $ga4 ) {
        return [ [ 'label' => 'error', 'error' => 'GA4フェッチャーが利用できません。' ] ];
    }

    $results = [];

    foreach ( $flex_queries as $q ) {
        $label      = $q['label'] ?? 'unknown';
        $dimensions = $q['dimensions'] ?? [];
        $metrics    = $q['metrics'] ?? [ 'sessions' ];
        $start      = $q['start'] ?? '';
        $end        = $q['end'] ?? '';
        $limit      = $q['limit'] ?? 20;

        // キャッシュ
        $cache_key = 'mw_ai_flex_' . $user_id . '_' . md5( $label . $start . $end . implode( ',', $dimensions ) );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            $results[] = array_merge( [ 'label' => $label ], $cached );

            error_log( sprintf(
                '[みまもりAI] Flex query [%s] cache hit | dims=%s | %s〜%s',
                $label, implode( ',', $dimensions ), $start, $end
            ) );
            continue;
        }

        try {
            $report = $ga4->fetch_flexible_report( $ga4_id, $start, $end, $dimensions, $metrics, $limit );

            // ログ出力
            error_log( sprintf(
                '[みまもりAI] Flex query [%s] | dims=%s | metrics=%s | %s〜%s | rows=%d',
                $label,
                implode( ',', $dimensions ),
                implode( ',', $metrics ),
                $start,
                $end,
                $report['row_count'] ?? 0
            ) );

            set_transient( $cache_key, $report, 2 * HOUR_IN_SECONDS );
            $results[] = array_merge( [ 'label' => $label ], $report );

        } catch ( \Exception $e ) {
            $error_msg = $e->getMessage();
            error_log( '[みまもりAI] Flex query error [' . $label . ']: ' . $error_msg );

            $user_error = 'GA4データの取得に失敗しました。';
            if ( strpos( $error_msg, 'PERMISSION_DENIED' ) !== false ) {
                $user_error = 'GA4への権限がありません。サービスアカウントの設定を確認してください。';
            } elseif ( strpos( $error_msg, 'NOT_FOUND' ) !== false ) {
                $user_error = 'GA4プロパティが見つかりません。プロパティIDを確認してください。';
            }

            $results[] = [ 'label' => $label, 'error' => $user_error, 'row_count' => 0 ];
        }
    }

    return $results;
}

/**
 * フレキシブルクエリ結果の異常値/スパム検知
 *
 * 国別データに対して、エンゲージメント率が極端に低い + セッション数が突出している
 * パターンを検出し、注記テキストを返す。
 *
 * @param array $flex_results  mimamori_execute_flexible_queries() の結果
 * @return array  異常検知結果 [['label'=>..., 'anomalies'=>[...], 'summary'=>string], ...]
 */
function mimamori_detect_anomalies( array $flex_results ): array {
    $detections = [];

    foreach ( $flex_results as $result ) {
        if ( ! empty( $result['error'] ) || empty( $result['rows'] ) ) {
            continue;
        }

        $label = $result['label'] ?? '';
        $rows  = $result['rows'];

        // 国別・都市別データに対してのみ異常検知
        if ( ! in_array( $label, [ 'country_breakdown', 'city_breakdown', 'source_medium' ], true ) ) {
            continue;
        }

        // 全体の平均値を計算
        $total_sessions = 0;
        $row_count      = count( $rows );
        foreach ( $rows as $r ) {
            $total_sessions += (int) ( $r['sessions'] ?? 0 );
        }
        $avg_sessions = $row_count > 0 ? $total_sessions / $row_count : 0;

        $anomalies = [];

        foreach ( $rows as $r ) {
            $sessions        = (int) ( $r['sessions'] ?? 0 );
            $engagement_rate = (float) ( $r['engagementRate'] ?? 1.0 );
            $avg_duration    = (float) ( $r['averageSessionDuration'] ?? 0 );
            $bounce_rate     = (float) ( $r['bounceRate'] ?? 0 );

            // 識別キー
            $name = $r['country'] ?? $r['city'] ?? ( ( $r['sessionSource'] ?? '' ) . '/' . ( $r['sessionMedium'] ?? '' ) ) ?? '?';

            $signals = [];

            // シグナル1: セッション数が平均の5倍以上
            if ( $avg_sessions > 0 && $sessions >= $avg_sessions * 5 && $sessions >= 10 ) {
                $signals[] = '他と比べてセッション数が突出（平均の' . number_format( $sessions / $avg_sessions, 1 ) . '倍）';
            }

            // シグナル2: セッション構成比が50%超 + 低エンゲージメント
            $share = $total_sessions > 0 ? $sessions / $total_sessions : 0;
            if ( $share > 0.5 && $engagement_rate < 0.1 ) {
                $signals[] = '構成比' . number_format( $share * 100, 1 ) . '%かつエンゲージメント率が極端に低い(' . number_format( $engagement_rate * 100, 1 ) . '%)';
            }

            // シグナル3: エンゲージメント率 < 5% かつ 平均滞在時間 < 3秒 かつ セッション10以上
            if ( $engagement_rate < 0.05 && $avg_duration < 3.0 && $sessions >= 10 ) {
                $signals[] = 'エンゲージメント率' . number_format( $engagement_rate * 100, 1 ) . '%・平均滞在' . number_format( $avg_duration, 1 ) . '秒（botまたはスパムの可能性）';
            }

            // シグナル4: 直帰率 > 95% かつ セッション多い
            if ( $bounce_rate > 0.95 && $sessions >= 20 ) {
                $signals[] = '直帰率' . number_format( $bounce_rate * 100, 1 ) . '%';
            }

            if ( ! empty( $signals ) ) {
                $anomalies[] = [
                    'name'     => $name,
                    'sessions' => $sessions,
                    'share'    => number_format( $share * 100, 1 ) . '%',
                    'signals'  => $signals,
                ];

                // ログ
                error_log( sprintf(
                    '[みまもりAI] Anomaly detected [%s]: %s | sessions=%d | signals=%s',
                    $label, $name, $sessions, implode( '; ', $signals )
                ) );
            }
        }

        if ( ! empty( $anomalies ) ) {
            $summary_parts = [];
            foreach ( $anomalies as $a ) {
                $summary_parts[] = $a['name'] . '（' . implode( '、', $a['signals'] ) . '）';
            }
            $detections[] = [
                'label'     => $label,
                'anomalies' => $anomalies,
                'summary'   => '⚠ 異常値の可能性: ' . implode( '／', $summary_parts ),
            ];
        }
    }

    return $detections;
}

/**
 * フレキシブルクエリ結果をAI向けテキストに整形する
 *
 * @param array $flex_results    mimamori_execute_flexible_queries() の結果
 * @param array $anomaly_results mimamori_detect_anomalies() の結果
 * @return string  AIに渡すテキストブロック
 */
function mimamori_format_flexible_results_for_ai( array $flex_results, array $anomaly_results ): string {
    $blocks = [];

    // 異常検知 lookup
    $anomaly_map = [];
    foreach ( $anomaly_results as $a ) {
        $anomaly_map[ $a['label'] ] = $a;
    }

    foreach ( $flex_results as $result ) {
        $label = $result['label'] ?? '';

        // エラーの場合
        if ( ! empty( $result['error'] ) ) {
            $blocks[] = '▼ ' . $label . ': ' . $result['error'];
            continue;
        }

        if ( empty( $result['rows'] ) ) {
            continue;
        }

        $rows       = $result['rows'];
        $totals     = $result['totals'] ?? [];
        $query_meta = $result['query_meta'] ?? [];
        $range      = ( $query_meta['start'] ?? '' ) . ' 〜 ' . ( $query_meta['end'] ?? '' );
        $dimensions = $query_meta['dimensions'] ?? [];
        $metrics    = $query_meta['metrics'] ?? [];

        // ラベル → 日本語タイトル
        $title_map = [
            'country_breakdown' => '国別アクセス',
            'city_breakdown'    => '都市別アクセス',
            'source_medium'     => '参照元/メディア別アクセス',
            'landing_page'      => 'ランディングページ別アクセス',
        ];
        $title = $title_map[ $label ] ?? $label;

        $lines   = [ '▼ ' . $title . '（' . $range . '）— GA4実データ' ];

        // ヘッダー行
        $header_parts = [];
        foreach ( $dimensions as $d ) {
            $dim_labels = [
                'country' => '国', 'city' => '都市', 'region' => '地域',
                'sessionSource' => '参照元', 'sessionMedium' => 'メディア',
                'landingPagePlusQueryString' => 'ランディングページ',
            ];
            $header_parts[] = $dim_labels[ $d ] ?? $d;
        }
        $metric_labels = [
            'sessions' => 'セッション', 'totalUsers' => 'ユーザー',
            'engagementRate' => 'エンゲージメント率', 'averageSessionDuration' => '平均滞在(秒)',
            'bounceRate' => '直帰率', 'screenPageViews' => 'PV',
        ];
        foreach ( $metrics as $m ) {
            $header_parts[] = $metric_labels[ $m ] ?? $m;
        }
        $header_parts[] = '構成比';
        $lines[] = implode( ' | ', $header_parts );

        // データ行
        $total_sessions = (int) ( $totals['sessions'] ?? 0 );
        $row_limit = min( count( $rows ), 15 );

        for ( $i = 0; $i < $row_limit; $i++ ) {
            $r     = $rows[ $i ];
            $parts = [];

            foreach ( $dimensions as $d ) {
                $val = $r[ $d ] ?? '(not set)';
                $parts[] = $val;
            }

            foreach ( $metrics as $m ) {
                $val = $r[ $m ] ?? 0;
                if ( in_array( $m, [ 'engagementRate', 'bounceRate' ], true ) ) {
                    $parts[] = number_format( (float) $val * 100, 1 ) . '%';
                } elseif ( $m === 'averageSessionDuration' ) {
                    $parts[] = number_format( (float) $val, 1 );
                } else {
                    $parts[] = number_format( (int) $val );
                }
            }

            // 構成比
            $sess  = (int) ( $r['sessions'] ?? 0 );
            $share = $total_sessions > 0 ? ( $sess / $total_sessions ) * 100 : 0;
            $parts[] = number_format( $share, 1 ) . '%';

            $lines[] = ( $i + 1 ) . '. ' . implode( ' | ', $parts );
        }

        // 合計
        if ( $total_sessions > 0 ) {
            $lines[] = '合計セッション: ' . number_format( $total_sessions );
        }

        // 異常値注記
        if ( isset( $anomaly_map[ $label ] ) ) {
            $lines[] = '';
            $lines[] = $anomaly_map[ $label ]['summary'];
            $lines[] = '→ GA4の「レポート > ユーザー属性 > テクノロジー > ユーザーの環境の詳細」や「参照元/メディア」レポートで詳しく確認してください。';
        }

        $blocks[] = implode( "\n", $lines );
    }

    if ( empty( $blocks ) ) {
        return '';
    }

    return "【GA4実データ（フレキシブルクエリ取得）】\n以下のデータはGA4 Data APIから直接取得した実データです。推測ではありません。\n\n" . implode( "\n\n", $blocks );
}

/**
 * REST API ハンドラー: POST /mimamori/v1/ai-chat
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function mimamori_handle_ai_chat_request( WP_REST_Request $request ): WP_REST_Response {
    $data = $request->get_json_params();

    $message = sanitize_textarea_field( $data['message'] ?? '' );
    if ( $message === '' ) {
        return new WP_REST_Response( [
            'success' => false,
            'message' => 'メッセージが空です',
        ], 400 );
    }

    // コンテキスト構築
    $input = mimamori_build_chat_context( $data );

    // セクションコンテキスト（「AIに聞く」ボタンからのセクション抽出テキスト）
    $section_context = null;
    if ( isset( $data['sectionContext'] ) && is_array( $data['sectionContext'] ) ) {
        $section_context = [
            'sectionType'  => sanitize_text_field( $data['sectionContext']['sectionType'] ?? '' ),
            'sectionTitle' => sanitize_text_field( $data['sectionContext']['sectionTitle'] ?? '' ),
            'sectionBody'  => sanitize_textarea_field( $data['sectionContext']['sectionBody'] ?? '' ),
            'pageType'     => sanitize_text_field( $data['sectionContext']['pageType'] ?? '' ),
        ];
        // 空のコンテキストは無視
        if ( $section_context['sectionBody'] === '' ) {
            $section_context = null;
        }
    }

    // --- 意図補正 + データソースの自動解決 ---
    $current_page    = ( isset( $data['currentPage'] ) && is_array( $data['currentPage'] ) )
                       ? $data['currentPage']
                       : [];

    $page_type = mimamori_detect_page_type( $current_page );

    // セクションコンテキストがある場合、本文も意図検出に含める
    $intent_text = $message;
    if ( $section_context !== null ) {
        $intent_text .= ' ' . $section_context['sectionBody'];
    }
    $intent      = mimamori_rewrite_intent( $intent_text, $page_type );
    $intent_type = $intent['intent'] ?? 'general';
    $sources     = mimamori_resolve_data_sources( $intent, false, false );

    // 分析系意図ではアナリティクスを強制有効化
    if ( in_array( $intent_type, [ 'reason_analysis', 'site_improvement' ], true ) ) {
        $sources['use_analytics'] = true;
    }

    // セクションコンテキストがある場合もアナリティクスを強制有効化
    if ( $section_context !== null ) {
        $sources['use_analytics'] = true;
    }

    $user_id = get_current_user_id();

    // ログ: 質問分類
    error_log( sprintf(
        '[みまもりAI] Chat request | intent=%s | page_type=%s | analytics=%s | section=%s | user=%d',
        $intent_type, $page_type, $sources['use_analytics'] ? 'yes' : 'no',
        $section_context ? $section_context['sectionType'] : 'none', $user_id
    ) );

    // システムプロンプト + 意図補正コンテキスト
    $instructions    = mimamori_get_system_prompt();
    $context_blocks  = mimamori_build_context_blocks( $page_type, $intent, $sources, $current_page, $user_id, $section_context );
    if ( $context_blocks !== '' ) {
        $instructions .= $context_blocks;
    }

    // === 確定的プランナー: 国/都市/source等の特定クエリを自動実行 ===
    // セクションコンテキストがある場合、本文のキーワードもクエリ対象に含める
    $deterministic_text = $message;
    if ( $section_context !== null ) {
        $deterministic_text .= ' ' . $section_context['sectionBody'];
    }
    $flex_queries = mimamori_build_deterministic_queries( $deterministic_text, $intent_type );

    if ( ! empty( $flex_queries ) ) {
        // フレキシブルクエリが必要 → アナリティクスも強制ON
        $sources['use_analytics'] = true;

        $flex_results    = mimamori_execute_flexible_queries( $flex_queries, $user_id );
        $anomaly_results = mimamori_detect_anomalies( $flex_results );
        $flex_text       = mimamori_format_flexible_results_for_ai( $flex_results, $anomaly_results );

        if ( $flex_text !== '' ) {
            $instructions .= "\n\n" . $flex_text;
        }
    }

    // === 2パス: 追加データの動的取得（AI プランナー） ===
    if ( ! empty( $sources['use_analytics'] ) ) {
        $digest          = mimamori_get_analytics_digest( $user_id );
        $planner_queries = mimamori_call_planner_pass( $message, $digest, $intent_type );

        if ( ! empty( $planner_queries ) ) {
            $enrichment_data = mimamori_fetch_enrichment_data( $planner_queries, $user_id );

            if ( ! empty( $enrichment_data ) ) {
                $enrichment_text = mimamori_format_enrichment_for_ai( $enrichment_data );
                if ( $enrichment_text !== '' ) {
                    $instructions .= "\n\n" . $enrichment_text;
                }
            }
        }
    }

    // OpenAI 回答呼び出し
    $model = defined( 'MIMAMORI_OPENAI_MODEL' ) ? MIMAMORI_OPENAI_MODEL : 'gpt-4.1-mini';

    $result = mimamori_call_openai_responses_api( [
        'model'             => $model,
        'instructions'      => $instructions,
        'input'             => $input,
        'max_output_tokens' => 2048,
    ] );

    if ( is_wp_error( $result ) ) {
        $err_code = $result->get_error_code();
        $status   = ( $err_code === 'no_api_key' ) ? 500 : 502;

        // エラーメッセージは mimamori_call_openai_responses_api() 内で
        // 管理者向け/一般向けに分岐済み
        return new WP_REST_Response( [
            'success' => false,
            'message' => $result->get_error_message(),
        ], $status );
    }

    $raw_text   = $result['text'];
    $structured = mimamori_parse_ai_response( $raw_text );

    return new WP_REST_Response( [
        'success' => true,
        'data'    => [
            'message' => [
                'role'       => 'assistant',
                'content'    => $raw_text,
                'structured' => $structured,
            ],
        ],
    ], 200 );
}


// =========================================
// 実質CV（経路別・日別）: DB + REST API
// namespace: gcrev_insights/v1
// =========================================

add_action('after_setup_theme', function () {
    gcrev_actual_cv_create_table();
    gcrev_cv_routes_create_table();
    if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
        Gcrev_Cron_Logger::create_tables();
    }
    if ( class_exists( 'Gcrev_DB_Optimizer' ) ) {
        Gcrev_DB_Optimizer::ensure_indexes();
    }
});

function gcrev_actual_cv_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'gcrev_actual_cvs';
}

function gcrev_cv_routes_create_table(): void {
    global $wpdb;

    $table = $wpdb->prefix . 'gcrev_cv_routes';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        route_key VARCHAR(64) NOT NULL,
        label VARCHAR(100) NOT NULL DEFAULT '',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY user_route (user_id, route_key),
        KEY user_id (user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

function gcrev_actual_cv_create_table(): void {
    global $wpdb;

    $table = gcrev_actual_cv_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // ルートは固定キー（必要なら将来拡張OK）
    // dateカラム名は reserved 回避で cv_date にする
    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        cv_date DATE NOT NULL,
        route VARCHAR(64) NOT NULL,
        cv_count INT NOT NULL DEFAULT 0,
        memo VARCHAR(255) NULL,
        updated_by BIGINT(20) UNSIGNED NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_date_route (user_id, cv_date, route),
        KEY user_date (user_id, cv_date),
        KEY route (route)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ----------------------------
// 権限：管理者 or 本人
// ----------------------------
function gcrev_can_edit_actual_cv(int $target_user_id): bool {
    if ($target_user_id <= 0) return false;
    if (current_user_can('manage_options')) return true;
    return get_current_user_id() === $target_user_id;
}

// ----------------------------
// REST登録
// ----------------------------
// ※ /actual-cv の GET/POST/users は class-gcrev-api.php に一本化済み
//    重複登録による競合を防ぐため、ここでは登録しない

function gcrev_validate_month(string $month): bool {
    return (bool)preg_match('/^\d{4}-\d{2}$/', $month);
}
function gcrev_validate_date(string $date): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}
function gcrev_month_range(string $month): array {
    // month: YYYY-MM
    $start = $month . '-01';
    $dt = new DateTime($start);
    $end = clone $dt;
    $end->modify('last day of this month');
    return [$start, $end->format('Y-m-d')];
}
function gcrev_days_in_month(string $month): array {
    [$start, $end] = gcrev_month_range($month);
    $days = [];
    $dt = new DateTime($start);
    $endDt = new DateTime($end);
    while ($dt <= $endDt) {
        $days[] = $dt->format('Y-m-d');
        $dt->modify('+1 day');
    }
    return $days;
}
function gcrev_allowed_routes(int $user_id = 0): array {
    if ($user_id <= 0) $user_id = get_current_user_id();
    if ($user_id <= 0) return ['form','phone','line','other'];

    global $wpdb;
    $table = $wpdb->prefix . 'gcrev_cv_routes';

    // テーブルが存在しない場合はフォールバック
    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
        return ['form','phone','line','other'];
    }

    $keys = $wpdb->get_col($wpdb->prepare(
        "SELECT route_key FROM {$table} WHERE user_id = %d AND enabled = 1 ORDER BY sort_order ASC",
        $user_id
    ));

    // ルート未設定のユーザーはフォールバック
    if (empty($keys)) return ['form','phone','line','other'];

    return $keys;
}

// ----------------------------
// GET /actual-cv
// 返す形式：
// {
//   success:true,
//   data:{
//     user_id: 123,
//     month: "2026-02",
//     routes: [{route_key:"contact_form", label:"お問い合わせ"}, ...],
//     items: { "2026-02-01": {"contact_form":null,...}, ... },
//     totals: {"contact_form":3,...,"all":6},
//     has_any: true/false
//   }
// }
// ----------------------------
function gcrev_rest_get_actual_cv(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $table = gcrev_actual_cv_table_name();

    $month = (string)$req->get_param('month');
    if (!gcrev_validate_month($month)) {
        return new \WP_REST_Response(['success'=>false,'message'=>'Invalid month'], 400);
    }

    $user_id = (int)($req->get_param('user_id') ?: get_current_user_id());
    if ($user_id <= 0) {
        return new \WP_REST_Response(['success'=>false,'message'=>'Invalid user_id'], 400);
    }

    [$start, $end] = gcrev_month_range($month);
    $route_keys = gcrev_allowed_routes($user_id);
    $days = gcrev_days_in_month($month);

    // ルートのラベル情報を取得（オブジェクト配列用）
    $routes_table = $wpdb->prefix . 'gcrev_cv_routes';
    $route_labels = [];
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $routes_table))) {
        $label_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT route_key, label FROM {$routes_table} WHERE user_id = %d AND enabled = 1 ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A);
        foreach ($label_rows as $lr) {
            $route_labels[$lr['route_key']] = $lr['label'];
        }
    }

    // routes をオブジェクト配列として構築（JSの r.route_key / r.label に対応）
    $routes_response = [];
    foreach ($route_keys as $rk) {
        $routes_response[] = [
            'route_key' => $rk,
            'label'     => $route_labels[$rk] ?? $rk,
        ];
    }

    // 初期形（未入力=null）
    $items = [];
    foreach ($days as $d) {
        $items[$d] = [];
        foreach ($route_keys as $r) $items[$d][$r] = null;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cv_date, route, cv_count
             FROM {$table}
             WHERE user_id = %d AND cv_date BETWEEN %s AND %s",
            $user_id, $start, $end
        ),
        ARRAY_A
    );

    $totals = array_fill_keys($route_keys, 0);
    $has_any = false;

    foreach ($rows as $row) {
        $d = $row['cv_date'];
        $r = $row['route'];
        $c = (int)$row['cv_count'];

        if (isset($items[$d]) && in_array($r, $route_keys, true)) {
            $items[$d][$r] = $c; // 0も保存される＝確定0
            $totals[$r] += $c;
            $has_any = true; // レコードが1つでもあれば運用中扱い
        }
    }

    $all = 0;
    foreach ($route_keys as $r) $all += $totals[$r];
    $totals['all'] = $all;

    return new \WP_REST_Response([
        'success' => true,
        'data' => [
            'user_id' => $user_id,
            'month'   => $month,
            'routes'  => $routes_response,
            'items'   => $items,
            'totals'  => $totals,
            'has_any' => $has_any,
        ],
    ], 200);
}

// ----------------------------
// POST /actual-cv
// body:
// { user_id:int, month:"YYYY-MM", items:[{date:"YYYY-MM-DD", route:"form", count:0|1|..., memo?:""}...] }
// ルール：count が null/""/未指定 → delete（未入力扱い）
// ----------------------------
function gcrev_rest_post_actual_cv(\WP_REST_Request $req): \WP_REST_Response {
    global $wpdb;
    $table = gcrev_actual_cv_table_name();

    $body = $req->get_json_params();
    $user_id = (int)($body['user_id'] ?? get_current_user_id());
    $month   = (string)($body['month'] ?? '');

    if ($user_id <= 0) {
        return new \WP_REST_Response(['success'=>false,'message'=>'Invalid user_id'], 400);
    }
    if (!gcrev_validate_month($month)) {
        return new \WP_REST_Response(['success'=>false,'message'=>'Invalid month'], 400);
    }

    $items = $body['items'] ?? [];
    if (!is_array($items)) {
        return new \WP_REST_Response(['success'=>false,'message'=>'Invalid items'], 400);
    }

    [$start, $end] = gcrev_month_range($month);
    $allowed_routes = gcrev_allowed_routes($user_id);
    $now = current_time('mysql');
    $editor = get_current_user_id();

    $saved = 0;
    $deleted = 0;

    foreach ($items as $it) {
        if (!is_array($it)) continue;

        $date  = (string)($it['date'] ?? '');
        $route = (string)($it['route'] ?? '');
        $count_raw = $it['count'] ?? null;
        $memo  = isset($it['memo']) ? sanitize_text_field((string)$it['memo']) : null;

        if (!gcrev_validate_date($date)) continue;
        if ($date < $start || $date > $end) continue;
        if (!in_array($route, $allowed_routes, true)) continue;

        // 未入力扱い（削除）
        if ($count_raw === null || $count_raw === '') {
            $wpdb->delete($table, [
                'user_id' => $user_id,
                'cv_date' => $date,
                'route'   => $route,
            ], ['%d','%s','%s']);
            $deleted++;
            continue;
        }

        // 数値化（桁ミス防止で0〜99にクリップ：必要なら変更）
        $count = (int)$count_raw;
        if ($count < 0) $count = 0;
        if ($count > 99) $count = 99;

        // upsert
        $wpdb->replace($table, [
            'user_id'     => $user_id,
            'cv_date'     => $date,
            'route'       => $route,
            'cv_count'    => $count,
            'memo'        => $memo,
            'updated_by'  => $editor,
            'updated_at'  => $now,
        ], ['%d','%s','%s','%d','%s','%d','%s']);
        $saved++;
    }

    // ダッシュボード・レポートキャッシュを無効化（即反映保証）
    if ($saved > 0 || $deleted > 0) {
        gcrev_invalidate_user_cv_cache($user_id);
    }

    return new \WP_REST_Response([
        'success' => true,
        'data' => [
            'saved' => $saved,
            'deleted' => $deleted,
        ],
    ], 200);
}

/**
 * 指定ユーザーのダッシュボード・レポートキャッシュを削除
 */
function gcrev_invalidate_user_cv_cache(int $user_id): void {
    global $wpdb;
    foreach (['gcrev_dash_', 'gcrev_report_', 'gcrev_effcv_'] as $prefix) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . $prefix . $user_id . '_') . '%',
            $wpdb->esc_like('_transient_timeout_' . $prefix . $user_id . '_') . '%'
        ));
    }
}

// ----------------------------
// GET /actual-cv/users （管理者用）
// 入力対象ユーザーを選ぶための簡易リスト
// weisite_url または report_site_url を持つユーザーを優先
// ----------------------------
function gcrev_rest_get_actual_cv_users(\WP_REST_Request $req): \WP_REST_Response {
    $args = [
        'number' => 200,
        'fields' => ['ID','display_name','user_login'],
        'orderby' => 'ID',
        'order' => 'DESC',
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => 'weisite_url',
                'compare' => 'EXISTS',
            ],
            [
                'key' => 'report_site_url',
                'compare' => 'EXISTS',
            ],
        ],
    ];

    $q = new WP_User_Query($args);
    $users = [];
    foreach ($q->get_results() as $u) {
        $users[] = [
            'id' => (int)$u->ID,
            'name' => $u->display_name ?: $u->user_login,
        ];
    }

    return new \WP_REST_Response(['success'=>true,'data'=>$users], 200);
}

// ========================================
// 申込フォーム（MW WP Form 連携）
// ========================================

/**
 * MW WP Form 送信後フック — お申込みフォーム専用
 *
 * plan / 会社名 / 氏名 / メール等を transient に保存し、
 * サンクスページ（決済ボタン表示）へリダイレクトする。
 *
 * フォームキー（数値）は MW WP Form 管理画面で作成後に書き換える。
 * 複数のフォームキーに対応するため、フィルターフック名を動的に生成。
 *
 * ★ MW WP Form のフォームキーが確定したら下の定数を書き換えること ★
 */
if ( ! defined( 'GCREV_SIGNUP_FORM_KEY' ) ) {
    // MW WP Form 管理画面の投稿IDに書き換える（例: 123）
    define( 'GCREV_SIGNUP_FORM_KEY', 196 );
}

// --------------------------------------------------
// プラン定義（全ファイルから参照する一元管理）
// --------------------------------------------------

/**
 * 全プラン情報を返す。
 *
 * @return array<string, array{name:string, category:string, total:int|null, monthly:int, installments:int, has_installment:bool}>
 */
function gcrev_get_plan_definitions(): array {
    return [
        'seisaku_1y' => [
            'name'            => '制作込み1年プラン',
            'category'        => 'seisaku',
            'total'           => 264000,
            'monthly'         => 22000,
            'installments'    => 12,
            'has_installment' => true,
            'min_months'      => 0,
        ],
        'seisaku_2y' => [
            'name'            => '制作込み2年プラン',
            'category'        => 'seisaku',
            'total'           => 528000,
            'monthly'         => 22000,
            'installments'    => 24,
            'has_installment' => true,
            'min_months'      => 0,
        ],
        'unyou' => [
            'name'            => 'みまもりウェブ 伴走運用プラン',
            'category'        => 'unyou',
            'total'           => null,
            'monthly'         => 16500,
            'installments'    => 0,
            'has_installment' => false,
            'min_months'      => 0,
        ],
        'etsurann' => [
            'name'            => 'みまもりウェブ 閲覧プラン',
            'category'        => 'unyou',
            'total'           => null,
            'monthly'         => 5500,
            'installments'    => 0,
            'has_installment' => false,
            'min_months'      => 0,
        ],
        'monitor' => [
            'name'            => 'みまもりウェブ 伴走運用プラン（モニター価格）',
            'category'        => 'unyou',
            'total'           => null,
            'monthly'         => 11000,
            'installments'    => 0,
            'has_installment' => false,
            'min_months'      => 3,
        ],
    ];
}

/**
 * 有効なプランIDの配列を返す。
 *
 * @return string[]
 */
function gcrev_get_valid_plan_ids(): array {
    return array_keys( gcrev_get_plan_definitions() );
}

/**
 * サンクスページURL（固定ページ: 親=signup / 子=thanks → /signup/thanks/）
 */
if ( ! defined( 'GCREV_SIGNUP_THANKS_URL' ) ) {
    define( 'GCREV_SIGNUP_THANKS_URL', '/apply/thanks/' );
}

/**
 * MW WP Form 送信完了後の処理
 *
 * mwform_after_send_{form_key} アクションで呼ばれる。
 * ※ フック名は mwform_after_send_（mail なし）が正しい。
 *
 * payload を transient に保存し、リダイレクト先URLを
 * グローバル変数にセット → mwform_redirect_url フィルターで返す。
 *
 * @param MW_WP_Form_Data $Data フォーム入力データ（get() メソッド対応）
 */
function gcrev_mwform_after_send( $Data ) {
    global $gcrev_signup_redirect_url;

    // MW WP Form_Data オブジェクトからの値取得（get メソッド対応）
    if ( is_object( $Data ) && method_exists( $Data, 'get' ) ) {
        $plan    = sanitize_text_field( $Data->get( 'plan' ) );
        $company = sanitize_text_field( $Data->get( 'company' ) );
        $name    = sanitize_text_field( $Data->get( 'fullname' ) );
        $email   = sanitize_email( $Data->get( 'email' ) );
        $tel1    = sanitize_text_field( $Data->get( 'tel1' ) );
        $tel2    = sanitize_text_field( $Data->get( 'tel2' ) );
        $tel3    = sanitize_text_field( $Data->get( 'tel3' ) );
        $tel_single = sanitize_text_field( $Data->get( 'tel' ) );
    } else {
        // 配列アクセス（フォールバック）
        $plan    = isset( $Data['plan'] )     ? sanitize_text_field( $Data['plan'] )     : '';
        $company = isset( $Data['company'] )  ? sanitize_text_field( $Data['company'] )  : '';
        $name    = isset( $Data['fullname'] ) ? sanitize_text_field( $Data['fullname'] ) : '';
        $email   = isset( $Data['email'] )    ? sanitize_email( $Data['email'] )         : '';
        $tel1    = isset( $Data['tel1'] )     ? sanitize_text_field( $Data['tel1'] )     : '';
        $tel2    = isset( $Data['tel2'] )     ? sanitize_text_field( $Data['tel2'] )     : '';
        $tel3    = isset( $Data['tel3'] )     ? sanitize_text_field( $Data['tel3'] )     : '';
        $tel_single = isset( $Data['tel'] )   ? sanitize_text_field( $Data['tel'] )      : '';
    }

    // 問い合わせデータのタイトルを会社名に更新
    if ( is_object( $Data ) && method_exists( $Data, 'get_saved_mail_id' ) ) {
        $saved_mail_id = $Data->get_saved_mail_id();
        if ( $saved_mail_id && $company ) {
            wp_update_post( [
                'ID'         => $saved_mail_id,
                'post_title' => $company,
            ] );
        }
    }

    // plan バリデーション
    if ( ! in_array( $plan, gcrev_get_valid_plan_ids(), true ) ) {
        error_log( '[GCREV Signup] plan validation failed. plan="' . $plan . '"' );
        return;
    }

    // 電話番号結合
    $tel = '';
    if ( $tel1 || $tel2 || $tel3 ) {
        $tel = $tel1 . '-' . $tel2 . '-' . $tel3;
    } elseif ( $tel_single ) {
        $tel = $tel_single;
    }

    // payload 組み立て
    $payload = [
        'plan'       => $plan,
        'company'    => $company,
        'name'       => $name,
        'email'      => $email,
        'tel'        => $tel,
        'created_at' => current_time( 'mysql' ),
    ];

    // app_id を生成（ランダムトークン 32文字）
    $app_id = wp_generate_password( 32, false, false );

    // transient に保存（24時間有効）
    set_transient( 'gcrev_apply_' . $app_id, $payload, DAY_IN_SECONDS );

    // ログイン中のユーザーにプラン情報を紐づけ
    if ( is_user_logged_in() ) {
        $uid = get_current_user_id();
        update_user_meta( $uid, 'gcrev_user_plan', $plan );

        // プランIDから初回契約プラン期間を自動判定して保存
        if ( strpos( $plan, '1y' ) !== false ) {
            update_user_meta( $uid, 'gcrev_initial_plan_term', '1y' );
        } elseif ( strpos( $plan, '2y' ) !== false ) {
            update_user_meta( $uid, 'gcrev_initial_plan_term', '2y' );
        }
        // 支払いステータスは管理者が手動で設定する
    }

    // リダイレクト先URLをグローバル変数に保存
    // → mwform_redirect_url フィルターで返す（MW WP Form の正規フロー）
    $thanks_url = home_url( GCREV_SIGNUP_THANKS_URL );
    $gcrev_signup_redirect_url = add_query_arg( 'app', $app_id, $thanks_url );

    error_log( '[GCREV Signup] Redirect URL set: ' . $gcrev_signup_redirect_url );
}

// --------------------------------------------------
// MW WP Form フック登録
// --------------------------------------------------
if ( GCREV_SIGNUP_FORM_KEY > 0 ) {

    $gcrev_form_key = 'mw-wp-form-' . GCREV_SIGNUP_FORM_KEY;

    // A) 送信完了後: payload 保存 & リダイレクトURL セット
    //    ※ 正しいフック名: mwform_after_send_{form_key}（mail なし）
    add_action(
        'mwform_after_send_' . $gcrev_form_key,
        'gcrev_mwform_after_send'
    );

    // B) リダイレクト先URLを差し替えるフィルター
    //    MW WP Form が内部で wp_redirect() する直前に呼ばれる
    add_filter(
        'mwform_redirect_url_' . $gcrev_form_key,
        function( $url, $Data ) {
            global $gcrev_signup_redirect_url;
            if ( ! empty( $gcrev_signup_redirect_url ) ) {
                error_log( '[GCREV Signup] mwform_redirect_url filter returning: ' . $gcrev_signup_redirect_url );
                return esc_url_raw( $gcrev_signup_redirect_url );
            }
            return $url;
        },
        10,
        2
    );

    // C) 確認画面を無効化
    add_filter(
        'mwform_skip_confirm_' . $gcrev_form_key,
        '__return_true'
    );

    // D) メール内の {plan} をプランID → 日本語名に変換
    add_filter(
        'mwform_custom_mail_tag_' . $gcrev_form_key,
        function ( $value, $name, $saved_mail_id ) {
            if ( 'plan' === $name && $value ) {
                $defs = gcrev_get_plan_definitions();
                if ( isset( $defs[ $value ] ) ) {
                    return $defs[ $value ]['name'];
                }
            }
            return $value;
        },
        10,
        3
    );
}

// ========================================================================
// 決済ステータス管理（手動運用）
// ========================================================================

// --------------------------------------------------
// 契約タイプ・決済ステップ定義
// --------------------------------------------------
// user_meta: gcrev_contract_type
//   'with_site'    — 制作込み（初回分割 + サブスク の2段階決済）
//   'insight_only' — 伴走運用のみ／制作なし（サブスク決済のみ）
//
// user_meta: gcrev_initial_plan_term
//   '1y' — 1年プラン / '2y' — 2年プラン
//
// user_meta: gcrev_subscription_type
//   'normal'  — 通常サブスク
//   'monitor' — モニタープラン（主に2年プラン側で使用）
//
// user_meta: gcrev_initial_payment_completed
//   '1' — 初回決済（分割払い）完了
//
// user_meta: gcrev_subscription_payment_completed
//   '1' — 月額サブスクリプション決済完了
//
// options（5パターン決済URL）:
//   gcrev_url_installment_1y       — 分割払い決済URL（1年プラン）
//   gcrev_url_installment_2y       — 分割払い決済URL（2年プラン）
//   gcrev_url_subscribe_1y         — サブスク決済URL（1年プラン）
//   gcrev_url_subscribe_2y_normal  — サブスク決済URL（2年通常）
//   gcrev_url_subscribe_monitor    — サブスク決済URL（モニター）
// --------------------------------------------------

/**
 * ユーザーの契約タイプを取得する。
 *
 * @param  int  $user_id  0 の場合はログイン中ユーザー
 * @return string 'with_site' | 'insight_only'（デフォルト: 'with_site'）
 */
function gcrev_get_contract_type( int $user_id = 0 ): string {
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return 'with_site';
    }
    $type = get_user_meta( $user_id, 'gcrev_contract_type', true );
    return ( $type === 'insight_only' ) ? 'insight_only' : 'with_site';
}

/**
 * ユーザーの初回契約プラン期間を取得する。
 *
 * @param  int  $user_id
 * @return string '1y' | '2y'（デフォルト: '2y'）
 */
function gcrev_get_initial_plan_term( int $user_id = 0 ): string {
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return '2y';
    }
    $term = get_user_meta( $user_id, 'gcrev_initial_plan_term', true );
    return ( $term === '1y' ) ? '1y' : '2y';
}

/**
 * ユーザーのサブスク種別を取得する。
 *
 * @param  int  $user_id
 * @return string 'normal' | 'monitor'（デフォルト: 'normal'）
 */
function gcrev_get_subscription_type( int $user_id = 0 ): string {
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return 'normal';
    }
    $type = get_user_meta( $user_id, 'gcrev_subscription_type', true );
    return ( $type === 'monitor' ) ? 'monitor' : 'normal';
}

/**
 * ユーザーの決済ステップ状態を取得する。
 *
 * @param  int  $user_id  0 の場合はログイン中ユーザー
 * @return array{contract_type:string, plan_term:string, subscription_type:string, initial_completed:bool, subscription_completed:bool}
 */
function gcrev_get_payment_steps( int $user_id = 0 ): array {
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    return [
        'contract_type'          => gcrev_get_contract_type( $user_id ),
        'plan_term'              => gcrev_get_initial_plan_term( $user_id ),
        'subscription_type'      => gcrev_get_subscription_type( $user_id ),
        'initial_completed'      => ( get_user_meta( $user_id, 'gcrev_initial_payment_completed', true ) === '1' ),
        'subscription_completed' => ( get_user_meta( $user_id, 'gcrev_subscription_payment_completed', true ) === '1' ),
    ];
}

/**
 * ユーザーに対応する分割払い決済URLを返す。
 *
 * @param  int  $user_id
 * @return string URL or ''
 */
function gcrev_get_installment_url( int $user_id = 0 ): string {
    $term = gcrev_get_initial_plan_term( $user_id );
    if ( $term === '1y' ) {
        return get_option( 'gcrev_url_installment_1y', '' );
    }
    return get_option( 'gcrev_url_installment_2y', '' );
}

/**
 * ユーザーに対応するサブスク決済URLを返す。
 *
 * 選択ルール:
 *   制作込み + 1年プラン                 → C) gcrev_url_subscribe_1y       ¥16,500
 *   制作込み + 2年プラン                 → D) gcrev_url_subscribe_2y_normal ¥12,100
 *   伴走運用のみ + モニター適用           → E) gcrev_url_subscribe_monitor   ¥16,500
 *   伴走運用のみ + 通常                  → C) gcrev_url_subscribe_1y       ¥16,500
 *
 * @param  int  $user_id
 * @return string URL or ''
 */
function gcrev_get_subscription_url( int $user_id = 0 ): string {
    $contract = gcrev_get_contract_type( $user_id );
    $term     = gcrev_get_initial_plan_term( $user_id );
    $sub_type = gcrev_get_subscription_type( $user_id );

    if ( $contract === 'with_site' ) {
        // 制作込み: プラン年数で URL C / D を切替
        return ( $term === '1y' )
            ? get_option( 'gcrev_url_subscribe_1y', '' )
            : get_option( 'gcrev_url_subscribe_2y_normal', '' );
    }

    // 伴走運用のみ: モニター適用なら URL E、通常なら URL C
    return ( $sub_type === 'monitor' )
        ? get_option( 'gcrev_url_subscribe_monitor', '' )
        : get_option( 'gcrev_url_subscribe_1y', '' );
}

/**
 * ユーザーの決済ステータスを取得する。
 *
 * @param  int  $user_id  0 の場合はログイン中ユーザー
 * @return string 'paid' | ''
 */
function gcrev_get_payment_status( int $user_id = 0 ): string {
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return '';
    }
    // 管理者は常に paid（ロックアウト防止）
    if ( user_can( $user_id, 'manage_options' ) ) {
        return 'paid';
    }
    // 新ロジック: 契約タイプ + 完了フラグで判定（旧 gcrev_payment_status は参照しない）
    $steps = gcrev_get_payment_steps( $user_id );
    if ( $steps['contract_type'] === 'with_site' ) {
        // 制作込み: 初回決済 + サブスク両方完了で paid
        return ( $steps['initial_completed'] && $steps['subscription_completed'] ) ? 'paid' : '';
    } else {
        // 伴走運用のみ: サブスク完了で paid（initial は無視）
        return $steps['subscription_completed'] ? 'paid' : '';
    }
}

/**
 * ユーザーが支払い済み（全機能アクセス可）かどうか。
 */
function gcrev_is_payment_active( int $user_id = 0 ): bool {
    return gcrev_get_payment_status( $user_id ) === 'paid';
}

// --------------------------------------------------
// 既存ユーザー移行 v2（一回限り）
// 旧3値（active/subscription_pending/installment_pending）→ 新1値（paid）
// 旧 active or subscription_pending → paid（どちらかのチェックがONだった）
// 旧 installment_pending → 未払い（meta 削除）
// --------------------------------------------------
add_action( 'admin_init', function () {
    if ( get_option( 'gcrev_payment_status_v2_migrated' ) ) {
        return;
    }
    $user_ids = get_users( [ 'fields' => 'ID' ] );
    foreach ( $user_ids as $uid ) {
        $uid = (int) $uid;
        if ( user_can( $uid, 'manage_options' ) ) {
            continue;
        }
        $existing = get_user_meta( $uid, 'gcrev_payment_status', true );
        if ( $existing === 'active' || $existing === 'subscription_pending' ) {
            update_user_meta( $uid, 'gcrev_payment_status', 'paid' );
        } elseif ( $existing === 'installment_pending' ) {
            delete_user_meta( $uid, 'gcrev_payment_status' );
        }
    }
    // 決済URLオプションは引き続き使用するため削除しない
    update_option( 'gcrev_payment_status_v2_migrated', '1' );
});

// --------------------------------------------------
// 既存ユーザー移行 v3（一回限り）
// 旧 gcrev_payment_status を完全削除。
// 新ロジックでは gcrev_initial_payment_completed / gcrev_subscription_payment_completed のみで判定。
// --------------------------------------------------
add_action( 'admin_init', function () {
    if ( get_option( 'gcrev_payment_status_v3_migrated' ) ) {
        return;
    }
    $user_ids = get_users( [ 'fields' => 'ID' ] );
    foreach ( $user_ids as $uid ) {
        $uid = (int) $uid;
        if ( user_can( $uid, 'manage_options' ) ) {
            continue;
        }
        delete_user_meta( $uid, 'gcrev_payment_status' );
    }
    update_option( 'gcrev_payment_status_v3_migrated', '1' );
});

// --------------------------------------------------
// 新規ユーザー登録時
// 支払いステータスは未セット（= 未払い）。管理者が手動でチェックを設定する。
// --------------------------------------------------

// --------------------------------------------------
// WP管理画面 — ユーザープロフィールに決済チェックボックスを表示
// --------------------------------------------------
add_action( 'edit_user_profile', 'gcrev_render_payment_status_fields' );
add_action( 'show_user_profile', 'gcrev_render_payment_status_fields' );

function gcrev_render_payment_status_fields( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( user_can( $user->ID, 'manage_options' ) ) {
        return;
    }

    $user_plan   = get_user_meta( $user->ID, 'gcrev_user_plan', true );
    $plan_defs   = gcrev_get_plan_definitions();
    $plan_info   = isset( $plan_defs[ $user_plan ] ) ? $plan_defs[ $user_plan ] : null;
    $plan_label  = $plan_info ? $plan_info['name'] : '未選択';

    $contract_type          = gcrev_get_contract_type( $user->ID );
    $plan_term              = gcrev_get_initial_plan_term( $user->ID );
    $subscription_type      = gcrev_get_subscription_type( $user->ID );
    $initial_completed      = ( get_user_meta( $user->ID, 'gcrev_initial_payment_completed', true ) === '1' );
    $subscription_completed = ( get_user_meta( $user->ID, 'gcrev_subscription_payment_completed', true ) === '1' );
    $is_paid                = gcrev_is_payment_active( $user->ID );

    wp_nonce_field( 'gcrev_payment_status_save', 'gcrev_payment_status_nonce' );
    ?>
    <h3>GCREV 決済ステータス</h3>
    <table class="form-table" role="presentation">
        <tr>
            <th>選択プラン（申込時）</th>
            <td><code><?php echo esc_html( $plan_label ); ?></code></td>
        </tr>
        <tr>
            <th><label for="gcrev_contract_type">契約タイプ</label></th>
            <td>
                <select id="gcrev_contract_type" name="gcrev_contract_type">
                    <option value="with_site" <?php selected( $contract_type, 'with_site' ); ?>>
                        制作込み（初回決済 + サブスク）
                    </option>
                    <option value="insight_only" <?php selected( $contract_type, 'insight_only' ); ?>>
                        伴走運用のみ（サブスクのみ）
                    </option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="gcrev_initial_plan_term">初回契約プラン</label></th>
            <td>
                <select id="gcrev_initial_plan_term" name="gcrev_initial_plan_term">
                    <option value="1y" <?php selected( $plan_term, '1y' ); ?>>1年プラン</option>
                    <option value="2y" <?php selected( $plan_term, '2y' ); ?>>2年プラン</option>
                </select>
                <p class="description">制作込み契約の場合のみ使用。分割払い決済URL（A/B）の切り替えに使います。伴走運用のみの場合は無視されます。</p>
            </td>
        </tr>
        <tr id="gcrev_subscription_type_row">
            <th>サブスク種別</th>
            <td>
                <code id="gcrev_subscription_type_label"></code>
                <input type="hidden" id="gcrev_subscription_type" name="gcrev_subscription_type"
                       value="<?php echo esc_attr( $subscription_type ); ?>">
                <p class="description">契約タイプと初回契約プランから自動で決まります。</p>
            </td>
        </tr>
        <tr id="gcrev_monitor_row">
            <th>モニター適用</th>
            <td>
                <label>
                    <input type="checkbox"
                           id="gcrev_monitor_applied"
                           name="gcrev_monitor_applied"
                           value="1"
                           <?php checked( $subscription_type, 'monitor' ); ?>>
                    モニター価格を適用する
                </label>
                <p class="description">伴走運用のみ契約の場合に、モニター専用サブスクURL（E）を使用します。</p>
            </td>
        </tr>
        <tr>
            <th>初回決済（分割払い）</th>
            <td>
                <label>
                    <input type="checkbox"
                           id="gcrev_initial_payment_completed"
                           name="gcrev_initial_payment_completed"
                           value="1"
                           <?php checked( $initial_completed ); ?>>
                    完了
                </label>
                <p class="description">制作込み契約の場合のみ使用。伴走運用のみの場合は無視されます。</p>
            </td>
        </tr>
        <tr>
            <th>月額サブスクリプション決済</th>
            <td>
                <label>
                    <input type="checkbox"
                           id="gcrev_subscription_payment_completed"
                           name="gcrev_subscription_payment_completed"
                           value="1"
                           <?php checked( $subscription_completed ); ?>>
                    完了
                </label>
            </td>
        </tr>
        <tr>
            <th>現在のステータス</th>
            <td>
                <?php if ( $is_paid ): ?>
                <span style="display:inline-block; padding:4px 12px; border-radius:12px; font-size:13px; font-weight:600; color:#3D8B6E; background:rgba(61,139,110,0.08);">
                    ✅ 利用中
                </span>
                <?php else: ?>
                <span style="display:inline-block; padding:4px 12px; border-radius:12px; font-size:13px; font-weight:600; color:#C0392B; background:#FDF0EE;">
                    ⏳ 手続き中
                </span>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <script>
    (function(){
        var contractSel     = document.getElementById('gcrev_contract_type');
        var planTermSel     = document.getElementById('gcrev_initial_plan_term');
        var planTermRow     = planTermSel.closest('tr');
        var initialPayRow   = document.getElementById('gcrev_initial_payment_completed').closest('tr');
        var monitorRow      = document.getElementById('gcrev_monitor_row');
        var monitorCheck    = document.getElementById('gcrev_monitor_applied');
        var subTypeHidden   = document.getElementById('gcrev_subscription_type');
        var subTypeLabel    = document.getElementById('gcrev_subscription_type_label');

        /** サブスク種別ラベル＋hidden値を自動更新 */
        function updateSubscriptionType() {
            var ct   = contractSel.value;
            var term = planTermSel.value;
            var label, value;

            if (ct === 'with_site') {
                if (term === '1y') {
                    label = 'C) 1年プラン — ¥16,500/月';
                    value = 'normal';
                } else {
                    label = 'D) 2年プラン（通常） — ¥12,100/月';
                    value = 'normal';
                }
            } else {
                /* insight_only */
                if (monitorCheck.checked) {
                    label = 'E) モニター — ¥11,000/月';
                    value = 'monitor';
                } else {
                    label = '伴走運用 — ¥16,500/月';
                    value = 'normal';
                }
            }
            subTypeLabel.textContent = label;
            subTypeHidden.value      = value;
        }

        /** 行の表示/非表示を制御 */
        function toggleRows() {
            var isWithSite = (contractSel.value === 'with_site');

            /* 制作込みのみ: 初回契約プラン行 + 初回決済行 */
            planTermRow.style.display   = isWithSite ? '' : 'none';
            initialPayRow.style.display = isWithSite ? '' : 'none';

            /* 伴走運用のみ: モニター適用チェック行 */
            monitorRow.style.display = isWithSite ? 'none' : '';

            updateSubscriptionType();
        }

        contractSel.addEventListener('change', toggleRows);
        planTermSel.addEventListener('change', updateSubscriptionType);
        monitorCheck.addEventListener('change', updateSubscriptionType);
        toggleRows();
    })();
    </script>
    <?php
}

// --------------------------------------------------
// WP管理画面 — テスト運用チェックボックスを表示
// --------------------------------------------------
add_action( 'edit_user_profile', 'gcrev_render_test_operation_field' );
add_action( 'show_user_profile', 'gcrev_render_test_operation_field' );

function gcrev_render_test_operation_field( $user ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( user_can( $user->ID, 'manage_options' ) ) {
        return;
    }

    $is_test = ( get_user_meta( $user->ID, 'gcrev_test_operation', true ) === '1' );
    ?>
    <h3>運用ステータス</h3>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="gcrev_test_operation">テスト運用</label></th>
            <td>
                <label>
                    <input type="checkbox"
                           id="gcrev_test_operation"
                           name="gcrev_test_operation"
                           value="1"
                           <?php checked( $is_test ); ?>>
                    テスト運用中
                </label>
                <p class="description">チェックを入れると、フロントのサイドバーに「テスト運用」バッジが表示されます。</p>
            </td>
        </tr>
    </table>
    <?php
}

// --------------------------------------------------
// WP管理画面 — テスト運用チェックボックスの保存処理
// --------------------------------------------------
add_action( 'edit_user_profile_update', 'gcrev_save_test_operation_field' );
add_action( 'personal_options_update',  'gcrev_save_test_operation_field' );

function gcrev_save_test_operation_field( int $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        return;
    }

    if ( ! empty( $_POST['gcrev_test_operation'] ) ) {
        update_user_meta( $user_id, 'gcrev_test_operation', '1' );
    } else {
        delete_user_meta( $user_id, 'gcrev_test_operation' );
    }
}

// --------------------------------------------------
// WP管理画面 — 決済チェックボックスの保存処理
// --------------------------------------------------
add_action( 'edit_user_profile_update', 'gcrev_save_payment_status_fields' );
add_action( 'personal_options_update',  'gcrev_save_payment_status_fields' );

function gcrev_save_payment_status_fields( int $user_id ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( user_can( $user_id, 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_POST['gcrev_payment_status_nonce'] ) ||
         ! wp_verify_nonce(
             sanitize_text_field( wp_unslash( $_POST['gcrev_payment_status_nonce'] ) ),
             'gcrev_payment_status_save'
         )
    ) {
        return;
    }

    // 契約タイプ保存
    $contract_type = isset( $_POST['gcrev_contract_type'] )
        ? sanitize_text_field( wp_unslash( $_POST['gcrev_contract_type'] ) )
        : 'with_site';
    if ( ! in_array( $contract_type, [ 'with_site', 'insight_only' ], true ) ) {
        $contract_type = 'with_site';
    }
    update_user_meta( $user_id, 'gcrev_contract_type', $contract_type );

    // 初回契約プラン保存
    $plan_term = isset( $_POST['gcrev_initial_plan_term'] )
        ? sanitize_text_field( wp_unslash( $_POST['gcrev_initial_plan_term'] ) )
        : '2y';
    if ( ! in_array( $plan_term, [ '1y', '2y' ], true ) ) {
        $plan_term = '2y';
    }
    update_user_meta( $user_id, 'gcrev_initial_plan_term', $plan_term );

    // サブスク種別 — 契約タイプ＋プラン＋モニターチェックから自動導出
    if ( $contract_type === 'with_site' ) {
        // 制作込み: 1年→normal, 2年→normal（URLは plan_term で切替）
        $sub_type = 'normal';
    } else {
        // 伴走運用のみ: モニターチェック有→monitor, 無→normal
        $sub_type = ! empty( $_POST['gcrev_monitor_applied'] ) ? 'monitor' : 'normal';
    }
    update_user_meta( $user_id, 'gcrev_subscription_type', $sub_type );

    // 初回決済（分割払い）完了
    if ( ! empty( $_POST['gcrev_initial_payment_completed'] ) ) {
        update_user_meta( $user_id, 'gcrev_initial_payment_completed', '1' );
    } else {
        delete_user_meta( $user_id, 'gcrev_initial_payment_completed' );
    }

    // サブスクリプション決済完了
    if ( ! empty( $_POST['gcrev_subscription_payment_completed'] ) ) {
        update_user_meta( $user_id, 'gcrev_subscription_payment_completed', '1' );
    } else {
        delete_user_meta( $user_id, 'gcrev_subscription_payment_completed' );
    }

}

// --------------------------------------------------
// アクセスゲート — 非 active ユーザーを /payment-status/ へリダイレクト
// --------------------------------------------------
add_action( 'template_redirect', function () {
    if ( ! is_user_logged_in() ) {
        return;
    }
    if ( ! is_page() ) {
        return;
    }

    // 除外スラッグ（リダイレクトループ防止 + 常時アクセス許可ページ）
    $exempt_slugs = [
        'payment-status',
        'account',
        'signup',
        'thanks',
        'register',
        'apply',
    ];

    $post = get_queried_object();
    if ( $post && is_a( $post, 'WP_Post' ) ) {
        // 現在のページ + 祖先ページのスラッグをすべてチェック
        $slugs = [ get_post_field( 'post_name', $post->ID ) ];
        foreach ( get_post_ancestors( $post ) as $ancestor_id ) {
            $slugs[] = get_post_field( 'post_name', $ancestor_id );
        }
        foreach ( $slugs as $slug ) {
            if ( in_array( $slug, $exempt_slugs, true ) ) {
                return;
            }
        }
    }

    if ( ! gcrev_is_payment_active() ) {
        wp_safe_redirect( home_url( '/payment-status/' ) );
        exit;
    }
});

// ========================================
// WP-CLI Commands
// ========================================
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    $gcrev_cli_path = get_template_directory() . '/inc/cli/class-gcrev-cli.php';
    if ( file_exists( $gcrev_cli_path ) ) {
        require_once $gcrev_cli_path;
        WP_CLI::add_command( 'gcrev', 'Gcrev_CLI' );
    }
}

