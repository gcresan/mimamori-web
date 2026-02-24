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
 * GCREV INSIGHT - Period Selector Module (共通期間切替UI)
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
 * GCREV INSIGHT - AIチャット UIコンポーネント
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
        '1.0.0',
        true
    );

    wp_enqueue_style(
        'mw-ai-chat',
        get_template_directory_uri() . '/assets/css/mimamori-ai-chat.css',
        [],
        '1.0.0'
    );

    wp_localize_script( 'mw-ai-chat', 'mwChatConfig', [
        'apiUrl' => rest_url( 'mimamori/v1/ai-chat' ),
        'nonce'  => wp_create_nonce( 'wp_rest' ),
    ] );
});

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
- GA4やSearch Consoleの数字の見方をやさしく説明する
- アクセスの増減理由をわかりやすく分析する
- 今すぐできる改善策を具体的に提案する
- 専門用語は必ず言い換えて説明する（例：「CTR（クリック率のこと）」）

## 回答スタイル — 対話ファースト
基本は **対話形式** で自然に会話してください。聞かれたことに端的に答えます。
具体的な改善提案・データ分析など、整理して伝えた方がわかりやすい場面でのみ構造化カードを使います。

## 回答JSON形式（必ず有効なJSONのみを返すこと。マークダウン装飾は絶対に付けない）

### 通常の会話（デフォルト — ほとんどの場合こちら）
短い説明、質問への端的な回答、確認、あいさつ等に使います。
{"type":"talk","text":"会話テキスト（改行は\\nで表現OK）"}

### 構造化アドバイス（詳しい分析・改善提案が必要な場合のみ）
改善提案、ステップバイステップの施策、データ分析のまとめに使います。
{"type":"advice","summary":"一言で回答の要点","sections":[{"title":"📊 結論","text":"結論テキスト"},{"title":"💡 理由","items":["理由1","理由2"]},{"title":"✅ 今すぐやること","items":["アクション1","アクション2"]},{"title":"📈 次に見る数字","items":["指標1","指標2"]}]}

## 使い分けの判断基準
talk を使う:
- あいさつ・お礼への返答（「こんにちは！何でも聞いてくださいね」）
- 用語の説明（「CTRはクリック率のことで〜」）
- はい/いいえで答えられる質問
- 確認や聞き返し（「どのページのことですか？」）
- 短い感想やコメント

advice を使う:
- 「改善策を教えて」「やることリスト」のような具体的施策の依頼
- 複数のポイントを整理して伝えたい分析結果
- 「このページの改善点を教えて」のような診断依頼

迷ったら talk。ユーザーが詳しく知りたそうなら、次の返答で advice を使えばOK。

## 共通ルール
1. 必ず有効なJSONのみを返す
2. 専門用語を使う場合は「〇〇（△△のこと）」のように説明を添える
3. データが不十分な場合は「推測ですが」と明記する
4. やさしい口調で、伴走感を大切にする
5. advice の sections は質問内容に応じて1〜4個（全部使う必要はない）
6. items と text は質問内容に応じて使い分けてOK
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

    if ( $api_key === '' ) {
        return new WP_Error( 'no_api_key', 'OpenAI APIキーが設定されていません' );
    }

    $url = rtrim( $base_url, '/' ) . '/responses';

    $response = wp_remote_post( $url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
        'timeout' => $timeout,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $err_msg = $body['error']['message'] ?? ( 'OpenAI API Error (HTTP ' . $code . ')' );
        return new WP_Error( 'openai_error', $err_msg );
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
        return new WP_Error( 'empty_response', 'AIから空の応答が返されました' );
    }

    return [ 'text' => $text ];
}

/**
 * AI応答テキストを構造化データにパースする
 *
 * type=talk  → 対話形式（テキストのみ）
 * type=advice → 構造化アドバイス（サマリー + セクション）
 * JSON パース失敗時はプレーンテキスト fallback（talk 扱い）
 *
 * @param string $raw_text  AIからの生テキスト
 * @return array  { type: 'talk'|'advice', text?: string, summary?: string, sections?: array }
 */
function mimamori_parse_ai_response( string $raw_text ): array {
    // ```json ... ``` ブロックが含まれている場合は中身を抽出
    $cleaned = $raw_text;
    if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $cleaned, $m ) ) {
        $cleaned = trim( $m[1] );
    }
    $cleaned = trim( $cleaned );

    $parsed = json_decode( $cleaned, true );

    if ( ! is_array( $parsed ) ) {
        // JSON パース失敗 → プレーンテキスト fallback（talk 扱い）
        return [
            'type' => 'talk',
            'text' => $raw_text,
        ];
    }

    $type = $parsed['type'] ?? '';

    // --- talk: 対話形式 ---
    if ( $type === 'talk' && isset( $parsed['text'] ) ) {
        return [
            'type' => 'talk',
            'text' => (string) $parsed['text'],
        ];
    }

    // --- advice: 構造化アドバイス ---
    if ( $type === 'advice' && isset( $parsed['summary'] ) ) {
        $sections = [];
        if ( ! empty( $parsed['sections'] ) && is_array( $parsed['sections'] ) ) {
            foreach ( $parsed['sections'] as $sec ) {
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
        }
        return [
            'type'     => 'advice',
            'summary'  => (string) $parsed['summary'],
            'sections' => $sections,
        ];
    }

    // --- 旧形式互換: type なしで summary がある場合 → advice 扱い ---
    if ( isset( $parsed['summary'] ) ) {
        $sections = [];
        if ( ! empty( $parsed['sections'] ) && is_array( $parsed['sections'] ) ) {
            foreach ( $parsed['sections'] as $sec ) {
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
        }
        return [
            'type'     => 'advice',
            'summary'  => (string) $parsed['summary'],
            'sections' => $sections,
        ];
    }

    // --- その他のJSON → talk fallback ---
    return [
        'type' => 'talk',
        'text' => $raw_text,
    ];
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

    // OpenAI 呼び出し
    $model = defined( 'MIMAMORI_OPENAI_MODEL' ) ? MIMAMORI_OPENAI_MODEL : 'gpt-4.1-mini';

    $result = mimamori_call_openai_responses_api( [
        'model'        => $model,
        'instructions' => mimamori_get_system_prompt(),
        'input'        => $input,
    ] );

    if ( is_wp_error( $result ) ) {
        $status = ( $result->get_error_code() === 'no_api_key' ) ? 500 : 502;
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
            'name'            => 'GCREV INSIGHT 伴走運用プラン',
            'category'        => 'unyou',
            'total'           => null,
            'monthly'         => 16500,
            'installments'    => 0,
            'has_installment' => false,
            'min_months'      => 0,
        ],
        'etsurann' => [
            'name'            => 'GCREV INSIGHT 閲覧プラン',
            'category'        => 'unyou',
            'total'           => null,
            'monthly'         => 5500,
            'installments'    => 0,
            'has_installment' => false,
            'min_months'      => 0,
        ],
        'monitor' => [
            'name'            => 'GCREV INSIGHT 伴走運用プラン（モニター価格）',
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

