<?php
/**
 * Plugin Name: みまもりウェブ 問い合わせ集計API
 * Plugin URI:  https://mimamori-web.jp/
 * Description: Flamingo / MW WP Form の問い合わせデータを月単位で集計し、みまもりウェブに対して REST API で返却する。スパム・テスト・営業を除外した「有効問い合わせ数」も算出する。
 * Version:     1.1.2
 * Author:      みまもりウェブ
 * License:     GPL-2.0-or-later
 * Text Domain: mimamori-inquiries-api
 *
 * 設定: 有効化後 wp-admin → 設定 → みまもり問い合わせAPI でトークンを確認 (自動生成)
 *
 * 上級者向け (DBではなく wp-config.php で管理したい場合):
 *   define( 'MIMAMORI_INQUIRIES_API_TOKEN', '<32文字以上のランダム文字列>' );
 *   define( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS', '203.0.113.10,203.0.113.11' );
 *   ※ 定数が定義されていればそちらを優先し、DB の値はマスク表示する。
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 読み込みマーカー（このログが出ない場合、プラグインファイル自体がロードされていない）
error_log( '[MIMAMORI_INQUIRIES_API] plugin file loaded at ' . date( 'Y-m-d H:i:s' ) );

// REST ルート登録は二重ロードでも必ず実行されるようファイル先頭で登録
// （クラス定義前に登録しても、コールバックは REST 初期化時に呼び出されるので OK）
if ( ! has_action( 'rest_api_init', 'mimamori_inquiries_api_register_routes_bridge' ) ) {
    add_action( 'rest_api_init', 'mimamori_inquiries_api_register_routes_bridge' );
    error_log( '[MIMAMORI_INQUIRIES_API] add_action(rest_api_init) registered' );
}

if ( ! function_exists( 'mimamori_inquiries_api_register_routes_bridge' ) ) {
    function mimamori_inquiries_api_register_routes_bridge() {
        error_log( '[MIMAMORI_INQUIRIES_API] bridge: register_routes triggered' );
        if ( class_exists( 'Mimamori_Inquiries_API' ) ) {
            Mimamori_Inquiries_API::register_routes();
        } else {
            error_log( '[MIMAMORI_INQUIRIES_API] bridge: class not loaded yet!' );
        }
    }
}

if ( class_exists( 'Mimamori_Inquiries_API' ) ) {
    error_log( '[MIMAMORI_INQUIRIES_API] class already exists, skipping class definition' );
    return;
}

class Mimamori_Inquiries_API {

    private const ROUTE_NAMESPACE = 'mimamori/v1';
    private const ROUTE_PATH      = '/inquiries';

    /** 営業判定用 NG ワード（B2B 勧誘・サービス紹介・代行業） */
    private const NG_WORDS_SALES = [
        '営業', '売り込み', '広告', '無料掲載', 'SEO対策',
        // マーケ・代行系
        'マーケティング', 'リスティング', 'meo対策', '集客代行', '集客のご提案',
        'アクセス向上', '相互リンク', 'アポイント', 'アサイン',
        'ランキング掲載', '掲載のご案内', '掲載のご提案', '掲載のお願い',
        'サービスのご紹介', 'サービスをご案内', 'サービスのご案内',
        'ご提案させて', 'ご紹介させて', 'ご検討いただけ',
        '貴社のサイト', '貴社のホームページ', '貴社のwebサイト', '貴社のwebページ',
        '弊社では', '弊社サービス', '弊社の', '当社では',
        'お打ち合わせ', 'お時間頂戴', 'お時間いただ',
        // 業界特有
        'eラーニング', '人材育成', '人材形成', '採用代行',
        'd-marketing', 'd marketing',
        'グーグルマップで上位', 'グーグルマップ上位',
        'mapで上位', 'マップで上位',
        '口コミの管理', '口コミ管理',
        '無料相談', '無料診断',
        'お得な情報', '特別なご案内',
        'パートナーシップ', 'コラボレーション',

        /* === v1.1.2 追加: 実運用で漏れたパターン === */
        // 「貴社」単独 — 個人客は「そちら/御社」を使う。「貴社」は B2B 商習慣語
        '貴社', '貴店', '貴サイト',
        // 法人名乗り口上 — 個人客はフォームの名前欄を使うので本文に「と申します」は書かない
        'と申します。', 'と申します　', 'と申します ', 'と申し上げ',
        // 営業特有の語彙
        '成果報酬', '新規アポ', 'アポを', '商談', 'クロージング',
        '業務効率', '業務改善', '業務代行', '一次対応', '受電代行',
        'コスト削減', '経費削減', '利益を残', '利益向上',
        '事務の二重手間', '二重手間', '紙書類', 'ペーパーレス',
        // 営業によくある書き出し
        '突然のご連絡', '突然の連絡',
        // 営業によくある自社紹介後の動詞
        'ご紹介させていただ', 'ご案内させていただ', 'ご提案させていただ',
        'お役に立て', 'お力になれ',
        // 異業種 (建築・住宅と無関係)
        'toeic', '英会話', 'eコマース', 'ec構築', 'shopify',
        // 業界向けマーケ素材タイトル (【〜向け】)
        '【建築', '【工務店', '【住宅会社', '【リフォーム',
        '建築工事向け', '工務店向け', '住宅会社向け',
        // 集客系の追加
        'web集客', 'ウェブ集客', 'web広告', 'web運用',
        '広告運用', '広告代行', 'リスティング広告',
        // 採用代行系
        '採用支援', '人材紹介', '人材派遣', '応募数を', 'スカウト送信',
        // 「お時間を頂戴」の助詞ありパターン (既存「お時間頂戴」と別表記対応)
        'お時間を頂戴', 'お時間を頂きた', 'お時間ください',
        '打合せのお時間', '打合わせのお時間',
        // 「ニーズに応じた」「高度な専門知識」等のテンプレ営業文句
        'ニーズに応じた', '高度な専門知識', '専門知識を持',
        // よくある「〇〇な貴社へ」的な呼びかけ
        '貴社が', '貴社へ', '貴社に', '貴社で', '貴社を',
    ];

    /** 配信停止・購読解除系 NG ワード（reason: unsubscribe → 集計時は sales に統合） */
    private const NG_WORDS_UNSUBSCRIBE = [
        '配信停止', '配信を停止', '配信の停止',
        '購読解除', '購読の解除', '購読を解除',
        'メルマガ登録解除', 'メルマガ解除', 'メルマガ登録の解除',
        'メールマガジン解除', 'メールマガジン登録解除', 'メールマガジン登録の解除',
        '解除を依頼', '解除のお願い',
        '送信停止', '送信を停止',
        'unsubscribe', 'opt-out',
    ];

    /** テスト判定用キーワード */
    private const NG_WORDS_TEST = [ 'テスト', 'test', 'てすと' ];

    /** 有効と認める最低本文文字数 */
    private const MIN_MESSAGE_LENGTH = 10;

    /** wp_options に保存するキー */
    private const OPT_TOKEN       = 'mimamori_inquiries_api_token';
    private const OPT_ALLOWED_IPS = 'mimamori_inquiries_api_allowed_ips';

    /** Settings ページ slug */
    private const SETTINGS_SLUG = 'mimamori-inquiries-api';

    /**
     * フック登録（プラグインエントリ）
     */
    public static function bootstrap(): void {
        error_log( '[MIMAMORI_INQUIRIES_API] bootstrap() called' );
        add_action( 'rest_api_init',  [ __CLASS__, 'register_routes' ] );

        // 管理画面: 設定ページ + 再発行ハンドラ
        add_action( 'admin_menu',                                              [ __CLASS__, 'add_settings_menu' ] );
        add_action( 'admin_post_mimamori_inquiries_api_regenerate_token',      [ __CLASS__, 'handle_regenerate_token' ] );
        add_action( 'admin_post_mimamori_inquiries_api_save_ips',              [ __CLASS__, 'handle_save_allowed_ips' ] );
    }

    /* ================================================================
     * トークン解決ヘルパ
     * ================================================================ */

    /**
     * 認証に使う実トークンを返す。
     *   優先順位:
     *     1) wp-config.php の MIMAMORI_INQUIRIES_API_TOKEN 定数 (上書き)
     *     2) wp_options に保存した値 (無ければ自動生成)
     */
    public static function get_token(): string {
        if ( defined( 'MIMAMORI_INQUIRIES_API_TOKEN' ) && (string) MIMAMORI_INQUIRIES_API_TOKEN !== '' ) {
            return (string) MIMAMORI_INQUIRIES_API_TOKEN;
        }
        $token = get_option( self::OPT_TOKEN, '' );
        if ( ! is_string( $token ) || $token === '' ) {
            $token = self::generate_new_token();
            // autoload=false: 機密値は要求時のみロード
            update_option( self::OPT_TOKEN, $token, false );
        }
        return (string) $token;
    }

    /** 64文字 (256bit) の hex トークンを生成 */
    public static function generate_new_token(): string {
        try {
            return bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable $e ) {
            // random_bytes が使えない極稀なケース (PHP < 7) の保険
            return wp_generate_password( 64, false, false );
        }
    }

    /** 定数で固定されているか (= プラグイン画面では編集不可) */
    public static function is_token_locked_by_constant(): bool {
        return ( defined( 'MIMAMORI_INQUIRIES_API_TOKEN' ) && (string) MIMAMORI_INQUIRIES_API_TOKEN !== '' );
    }

    /** 許可IPリスト (定数 > option) */
    public static function get_allowed_ips_list(): array {
        if ( defined( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS' ) && (string) MIMAMORI_INQUIRIES_API_ALLOWED_IPS !== '' ) {
            $raw = (string) MIMAMORI_INQUIRIES_API_ALLOWED_IPS;
        } else {
            $raw = (string) get_option( self::OPT_ALLOWED_IPS, '' );
        }
        if ( $raw === '' ) return [];
        $list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        return array_values( $list );
    }

    /**
     * REST ルート登録
     */
    public static function register_routes(): void {
        error_log( '[MIMAMORI_INQUIRIES_API] register_routes() called' );

        $year_arg = [
            'required'          => true,
            'validate_callback' => static function ( $v ) {
                return is_numeric( $v ) && (int) $v >= 2000 && (int) $v <= 2100;
            },
            'sanitize_callback' => 'absint',
        ];
        $month_arg = [
            'required'          => true,
            'validate_callback' => static function ( $v ) {
                return is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 12;
            },
            'sanitize_callback' => 'absint',
        ];

        register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_get_inquiries' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
            'args'                => [
                'year'  => $year_arg,
                'month' => $month_arg,
            ],
        ] );

        // 個別問い合わせ一覧（明細）— 内容を含むので閲覧時のみ取得する想定
        register_rest_route( self::ROUTE_NAMESPACE, '/inquiries/list', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_get_inquiries_list' ],
            'permission_callback' => [ __CLASS__, 'check_token' ],
            'args'                => [
                'year'             => $year_arg,
                'month'            => $month_arg,
                'include_excluded' => [
                    'required'          => false,
                    'default'           => 1,
                    'validate_callback' => static function ( $v ) {
                        return in_array( (int) $v, [ 0, 1 ], true );
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    /* ================================================================
     * 認証
     * ================================================================ */

    /**
     * トークン認証 + 任意のIP制限
     */
    public static function check_token( \WP_REST_Request $request ): bool {

        // (1) 認証トークン取得 (定数 > option > 自動生成)
        $expected = self::get_token();
        if ( $expected === '' ) {
            return false;
        }

        // (2) ヘッダ X-Mimamori-Token を優先、フォールバックで Authorization: Bearer
        $header = '';
        if ( isset( $_SERVER['HTTP_X_MIMAMORI_TOKEN'] ) ) {
            $header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MIMAMORI_TOKEN'] ) );
        } elseif ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
            if ( stripos( $auth, 'Bearer ' ) === 0 ) {
                $header = trim( substr( $auth, 7 ) );
            }
        }

        if ( $header === '' ) {
            return false;
        }
        if ( ! hash_equals( $expected, $header ) ) {
            return false;
        }

        // (3) 任意の IP 許可リスト (定数 > option、空なら制限なし)
        $allowed = self::get_allowed_ips_list();
        if ( ! empty( $allowed ) ) {
            $remote = self::get_remote_ip();
            if ( ! in_array( $remote, $allowed, true ) ) {
                return false;
            }
        }

        return true;
    }

    private static function get_remote_ip(): string {
        // プロキシ越しの場合は X-Forwarded-For の先頭を採用
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $first = trim( (string) ( $list[0] ?? '' ) );
            if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
                return $first;
            }
        }
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
    }

    /* ================================================================
     * ハンドラ: GET /inquiries
     * ================================================================ */

    public static function handle_get_inquiries( \WP_REST_Request $request ): \WP_REST_Response {
        $year  = (int) $request->get_param( 'year' );
        $month = (int) $request->get_param( 'month' );

        $tz = wp_timezone();
        try {
            $start_dt = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => 'invalid date' ], 400 );
        }
        $end_dt = $start_dt->modify( 'last day of this month' )->setTime( 23, 59, 59 );

        $start = $start_dt->format( 'Y-m-d H:i:s' );
        $end   = $end_dt->format( 'Y-m-d H:i:s' );

        $items = array_merge(
            self::collect_flamingo( $start, $end ),
            self::collect_mw_wp_form( $start, $end )
        );

        $reasons = [
            'spam'  => 0,
            'test'  => 0,
            'sales' => 0,
        ];
        $valid    = 0;
        $excluded = 0;

        foreach ( $items as $item ) {
            $check = self::evaluate_inquiry( $item );
            if ( $check['valid'] ) {
                $valid++;
            } else {
                $excluded++;
                $reason = $check['reason'];
                // unsubscribe は sales カウンタに統合（DB スキーマ維持）
                if ( $reason === 'unsubscribe' ) {
                    $reason = 'sales';
                }
                if ( ! isset( $reasons[ $reason ] ) ) {
                    $reasons[ $reason ] = 0;
                }
                $reasons[ $reason ]++;
            }
        }

        $response = [
            'period'           => sprintf( '%04d-%02d', $year, $month ),
            'total'            => count( $items ),
            'valid'            => $valid,
            'excluded'         => $excluded,
            'excluded_reasons' => $reasons,
            'sources'          => self::detect_sources(),
            'generated_at'     => ( new \DateTimeImmutable( 'now', $tz ) )->format( DATE_ATOM ),
        ];

        return new \WP_REST_Response( $response, 200 );
    }

    /* ================================================================
     * ハンドラ: GET /inquiries/list — 個別の問い合わせ一覧
     * ================================================================ */

    public static function handle_get_inquiries_list( \WP_REST_Request $request ): \WP_REST_Response {
        $year             = (int) $request->get_param( 'year' );
        $month            = (int) $request->get_param( 'month' );
        $include_excluded = (int) $request->get_param( 'include_excluded' );

        $tz = wp_timezone();
        try {
            $start_dt = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [ 'error' => 'invalid date' ], 400 );
        }
        $end_dt = $start_dt->modify( 'last day of this month' )->setTime( 23, 59, 59 );

        $items = array_merge(
            self::collect_flamingo( $start_dt->format( 'Y-m-d H:i:s' ), $end_dt->format( 'Y-m-d H:i:s' ) ),
            self::collect_mw_wp_form( $start_dt->format( 'Y-m-d H:i:s' ), $end_dt->format( 'Y-m-d H:i:s' ) )
        );

        $list = [];
        foreach ( $items as $item ) {
            $check    = self::evaluate_inquiry( $item );
            $is_valid = (bool) $check['valid'];
            if ( ! $is_valid && ! $include_excluded ) {
                continue;
            }
            $list[] = [
                'date'    => (string) ( $item['date'] ?? '' ),
                'name'    => (string) ( $item['name'] ?? '' ),
                'email'   => (string) ( $item['email'] ?? '' ),
                'message' => (string) ( $item['message'] ?? '' ),
                'source'  => (string) ( $item['source'] ?? '' ),
                'spam'    => ! empty( $item['spam'] ),
                'valid'   => $is_valid,
                'reason'  => $is_valid ? '' : (string) ( $check['reason'] ?? '' ),
            ];
        }

        // 日付降順
        usort( $list, static function ( $a, $b ) {
            return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) );
        } );

        return new \WP_REST_Response( [
            'period'       => sprintf( '%04d-%02d', $year, $month ),
            'count'        => count( $list ),
            'items'        => $list,
            'generated_at' => ( new \DateTimeImmutable( 'now', $tz ) )->format( DATE_ATOM ),
        ], 200 );
    }

    /* ================================================================
     * Flamingo データ取得
     * ================================================================ */

    private static function collect_flamingo( string $start, string $end ): array {
        if ( ! post_type_exists( 'flamingo_inbound' ) ) {
            return [];
        }

        $posts = get_posts( [
            'post_type'        => 'flamingo_inbound',
            'post_status'      => [ 'publish', 'flamingo-spam', 'private' ],
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'date_query'       => [
                [
                    'after'     => $start,
                    'before'    => $end,
                    'inclusive' => true,
                ],
            ],
        ] );

        $items = [];
        foreach ( $posts as $post ) {
            $email   = (string) get_post_meta( $post->ID, '_from_email', true );
            $name    = (string) get_post_meta( $post->ID, '_from_name', true );
            $is_spam = ( $post->post_status === 'flamingo-spam' );

            // Flamingo は本文を post_content または '_field_*' に持つ
            $message = (string) $post->post_content;
            if ( $message === '' ) {
                $fields = get_post_meta( $post->ID, '_fields', true );
                if ( is_array( $fields ) ) {
                    $message = self::join_field_values( $fields );
                }
            }

            $items[] = [
                'source'  => 'flamingo',
                'email'   => $email,
                'name'    => $name,
                'message' => $message,
                'date'    => $post->post_date,
                'spam'    => $is_spam,
            ];
        }

        return $items;
    }

    /**
     * Flamingo の _fields 配列値を本文向けに連結
     */
    private static function join_field_values( array $fields ): string {
        $parts = [];
        foreach ( $fields as $value ) {
            if ( is_string( $value ) ) {
                $parts[] = $value;
            } elseif ( is_array( $value ) ) {
                $parts[] = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
            }
        }
        return trim( implode( ' / ', array_filter( $parts ) ) );
    }

    /* ================================================================
     * MW WP Form データ取得（CPT/DB 両対応）
     * ================================================================ */

    private static function collect_mw_wp_form( string $start, string $end ): array {
        $items = [];
        global $wpdb;

        // (A) MW WP Form v3+ は フォームごとに個別 CPT (mwf_<form_id>) を作る方式。
        //     post_type が 'mwf_' で始まるものを wp_posts から直接 SQL で拾う。
        //     post_type_exists() に頼らないため、未登録 CPT (REST 初期化時点で register されていない) も拾える。
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_date, post_type
               FROM {$wpdb->posts}
              WHERE post_type LIKE %s ESCAPE '\\\\'
                AND post_status NOT IN ('trash','auto-draft','inherit')
                AND post_date BETWEEN %s AND %s",
            'mwf\_%',
            $start,
            $end
        ), ARRAY_A );
        if ( is_array( $rows ) && ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $post_id = (int) $row['ID'];
                $meta    = get_post_meta( $post_id );
                $items[] = [
                    'source'  => 'mw_wp_form_cpt',
                    'email'   => self::find_email_in_meta( $meta ),
                    'name'    => self::find_name_in_meta( $meta ),
                    'message' => self::find_message_in_meta( $meta ),
                    'date'    => (string) ( $row['post_date'] ?? '' ),
                    'spam'    => false,
                ];
            }
        }

        // (B) 旧式: 専用テーブル wp_mwf_entries に保存される場合
        $table = $wpdb->prefix . 'mwf_entries';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {

            // 列名が環境差で揺れるため SHOW COLUMNS で実在する列だけ拾う
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
            $columns = is_array( $columns ) ? array_map( 'strval', $columns ) : [];
            $date_col = self::pick_existing_column( $columns, [ 'created_at', 'created', 'date', 'post_date' ] );

            if ( $date_col !== '' ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM `{$table}` WHERE `{$date_col}` BETWEEN %s AND %s",
                        $start,
                        $end
                    ),
                    ARRAY_A
                );
                if ( is_array( $rows ) ) {
                    foreach ( $rows as $row ) {
                        $items[] = [
                            'source'  => 'mw_wp_form_db',
                            'email'   => self::find_email_in_row( $row ),
                            'name'    => self::find_name_in_row( $row ),
                            'message' => self::find_message_in_row( $row ),
                            'date'    => (string) ( $row[ $date_col ] ?? '' ),
                            'spam'    => false,
                        ];
                    }
                }
            }
        }

        return $items;
    }

    private static function pick_existing_column( array $columns, array $candidates ): string {
        $lower = array_map( 'strtolower', $columns );
        foreach ( $candidates as $cand ) {
            $idx = array_search( strtolower( $cand ), $lower, true );
            if ( $idx !== false ) {
                return $columns[ $idx ];
            }
        }
        return '';
    }

    private static function find_email_in_meta( array $meta ): string {
        foreach ( [ '_from_email', 'email', 'mail', 'your-email' ] as $key ) {
            if ( ! empty( $meta[ $key ][0] ) && is_email( (string) $meta[ $key ][0] ) ) {
                return (string) $meta[ $key ][0];
            }
        }
        // 最後の手段: 全 meta から最初に見つかったメールアドレスを返す
        foreach ( $meta as $values ) {
            if ( is_array( $values ) ) {
                foreach ( $values as $v ) {
                    if ( is_string( $v ) && is_email( $v ) ) {
                        return $v;
                    }
                }
            }
        }
        return '';
    }

    private static function find_name_in_meta( array $meta ): string {
        foreach ( [ '_from_name', 'name', 'your-name' ] as $key ) {
            if ( ! empty( $meta[ $key ][0] ) ) {
                return (string) $meta[ $key ][0];
            }
        }
        return '';
    }

    private static function find_message_in_meta( array $meta ): string {
        foreach ( [ '_message', 'message', 'body', 'your-message', 'inquiry' ] as $key ) {
            if ( ! empty( $meta[ $key ][0] ) ) {
                return (string) $meta[ $key ][0];
            }
        }
        // 最後の手段: 全 meta 値を連結（_ で始まる private meta は除外）
        $parts = [];
        foreach ( $meta as $key => $values ) {
            if ( is_string( $key ) && strpos( $key, '_' ) === 0 ) {
                continue;
            }
            if ( is_array( $values ) ) {
                foreach ( $values as $v ) {
                    if ( is_string( $v ) && $v !== '' ) {
                        $parts[] = $v;
                    }
                }
            }
        }
        return trim( implode( ' / ', array_unique( $parts ) ) );
    }

    private static function find_email_in_row( array $row ): string {
        foreach ( [ 'email', 'mail', 'your_email', 'your-email' ] as $key ) {
            if ( ! empty( $row[ $key ] ) && is_email( (string) $row[ $key ] ) ) {
                return (string) $row[ $key ];
            }
        }
        foreach ( $row as $v ) {
            if ( is_string( $v ) && is_email( $v ) ) {
                return $v;
            }
        }
        return '';
    }

    private static function find_name_in_row( array $row ): string {
        foreach ( [ 'name', 'your_name', 'your-name' ] as $key ) {
            if ( ! empty( $row[ $key ] ) ) {
                return (string) $row[ $key ];
            }
        }
        return '';
    }

    private static function find_message_in_row( array $row ): string {
        foreach ( [ 'message', 'body', 'your_message', 'your-message', 'inquiry', 'comment' ] as $key ) {
            if ( ! empty( $row[ $key ] ) ) {
                return (string) $row[ $key ];
            }
        }
        $parts = [];
        foreach ( $row as $key => $v ) {
            if ( in_array( $key, [ 'id', 'created_at', 'updated_at', 'date' ], true ) ) {
                continue;
            }
            if ( is_string( $v ) && $v !== '' ) {
                $parts[] = $v;
            }
        }
        return trim( implode( ' / ', $parts ) );
    }

    /* ================================================================
     * 有効問い合わせ判定
     * ================================================================ */

    /**
     * @return array{valid:bool, reason?:string}
     */
    public static function evaluate_inquiry( array $item ): array {
        $message = (string) ( $item['message'] ?? '' );
        $email   = (string) ( $item['email'] ?? '' );
        $is_spam = (bool) ( $item['spam'] ?? false );

        if ( $is_spam ) {
            return [ 'valid' => false, 'reason' => 'spam' ];
        }

        $msg_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $message ) : strtolower( $message );
        $msg_norm  = function_exists( 'mb_convert_kana' ) ? mb_convert_kana( $msg_lower, 's' ) : $msg_lower;
        // 名前・メールドメインも判定対象に含める（送信元情報からも検知）
        $name_lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $item['name'] ?? '' ) ) : strtolower( (string) ( $item['name'] ?? '' ) );
        $haystack   = $msg_norm . "\n" . $name_lower . "\n" . $email;

        $needle_match = static function ( string $needle ) use ( $haystack ) : bool {
            $n = function_exists( 'mb_strtolower' ) ? mb_strtolower( $needle ) : strtolower( $needle );
            return ( function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $n ) : strpos( $haystack, $n ) ) !== false;
        };

        // 配信停止 / 購読解除（B2B営業の派生として扱うが reason は別管理）
        foreach ( self::NG_WORDS_UNSUBSCRIBE as $word ) {
            if ( $needle_match( $word ) ) {
                return [ 'valid' => false, 'reason' => 'unsubscribe' ];
            }
        }

        // 営業 / 売り込み判定（NGワード）
        foreach ( self::NG_WORDS_SALES as $word ) {
            if ( $needle_match( $word ) ) {
                return [ 'valid' => false, 'reason' => 'sales' ];
            }
        }

        // テスト判定
        foreach ( self::NG_WORDS_TEST as $word ) {
            if ( $needle_match( $word ) ) {
                return [ 'valid' => false, 'reason' => 'test' ];
            }
        }

        // 文字数下限
        $msg_len = function_exists( 'mb_strlen' ) ? mb_strlen( trim( $message ) ) : strlen( trim( $message ) );
        if ( $msg_len < self::MIN_MESSAGE_LENGTH ) {
            return [ 'valid' => false, 'reason' => 'spam' ];
        }

        // メール形式
        if ( $email !== '' && ! is_email( $email ) ) {
            return [ 'valid' => false, 'reason' => 'spam' ];
        }

        return [ 'valid' => true ];
    }

    /* ================================================================
     * 補助: 利用可能なソース一覧
     * ================================================================ */

    private static function detect_sources(): array {
        global $wpdb;

        $sources = [];
        if ( post_type_exists( 'flamingo_inbound' ) ) {
            $sources[] = 'flamingo';
        }

        // MW WP Form: フォームごとの個別CPT (mwf_<form_id>) が wp_posts に存在するかで判定。
        // post_type_exists() ではなく実データ存在で判定するため、CPT 未登録でも検出可能。
        $mwf_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type LIKE %s ESCAPE '\\\\'
                AND post_status NOT IN ('trash','auto-draft','inherit')
              LIMIT 1",
            'mwf\_%'
        ) );
        if ( $mwf_count > 0 ) {
            $sources[] = 'mw_wp_form_cpt';
        }

        $table  = $wpdb->prefix . 'mwf_entries';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            $sources[] = 'mw_wp_form_db';
        }
        return $sources;
    }

    /* ================================================================
     * 管理画面: 設定 → みまもり問い合わせAPI
     * ================================================================ */

    public static function add_settings_menu(): void {
        add_options_page(
            'みまもり問い合わせAPI',
            'みまもり問い合わせAPI',
            'manage_options',
            self::SETTINGS_SLUG,
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。', 403 );
        }

        $token             = self::get_token();
        $is_locked         = self::is_token_locked_by_constant();
        $endpoint_url      = rest_url( self::ROUTE_NAMESPACE . self::ROUTE_PATH );
        $endpoint_list_url = rest_url( self::ROUTE_NAMESPACE . self::ROUTE_PATH . '/list' );
        $allowed_ips       = self::get_allowed_ips_list();
        $ips_locked        = ( defined( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS' ) && (string) MIMAMORI_INQUIRIES_API_ALLOWED_IPS !== '' );
        $sources           = self::detect_sources();

        // 通知
        $msg = '';
        if ( isset( $_GET['regenerated'] ) ) {
            $msg = '✅ トークンを再発行しました。みまもりウェブの管理画面に新しいトークンを再登録してください。';
        } elseif ( isset( $_GET['ips_saved'] ) ) {
            $msg = '✅ 許可IPを保存しました。';
        }
        ?>
        <div class="wrap">
            <h1>みまもり問い合わせAPI 設定</h1>

            <?php if ( $msg ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
            <?php endif; ?>

            <p>このプラグインは「みまもりウェブ」と連携して、月初に問い合わせ件数を集計・送信するための受け口を提供します。<br>下記の <strong>エンドポイント URL</strong> と <strong>トークン</strong> を、みまもりウェブの管理画面で登録してください。</p>

            <?php if ( empty( $sources ) ) : ?>
                <div class="notice notice-warning">
                    <p>⚠️ Flamingo / MW WP Form のいずれもインストールされていません。問い合わせフォームの保存先プラグインを有効化してください。</p>
                </div>
            <?php else : ?>
                <p style="color:#3c3;">✅ 検出された問い合わせソース: <code><?php echo esc_html( implode( ', ', $sources ) ); ?></code></p>
            <?php endif; ?>

            <h2 style="margin-top:32px">📡 エンドポイント URL</h2>
            <table class="form-table">
                <tr>
                    <th>月次集計（合計）</th>
                    <td>
                        <input type="text" readonly value="<?php echo esc_attr( $endpoint_url ); ?>" class="regular-text" style="width:520px;font-family:monospace" onclick="this.select();">
                        <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint_url ); ?>');this.textContent='✅ コピー済み';setTimeout(()=>this.textContent='コピー',1800);">コピー</button>
                    </td>
                </tr>
                <tr>
                    <th>個別明細</th>
                    <td>
                        <input type="text" readonly value="<?php echo esc_attr( $endpoint_list_url ); ?>" class="regular-text" style="width:520px;font-family:monospace" onclick="this.select();">
                        <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $endpoint_list_url ); ?>');this.textContent='✅ コピー済み';setTimeout(()=>this.textContent='コピー',1800);">コピー</button>
                    </td>
                </tr>
            </table>

            <h2 style="margin-top:32px">🔑 認証トークン</h2>
            <table class="form-table">
                <tr>
                    <th>現在のトークン</th>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <input type="password" id="mw_inq_token" readonly value="<?php echo esc_attr( $token ); ?>" class="regular-text" style="width:520px;font-family:monospace" onclick="this.select();">
                            <button type="button" class="button" id="mw_inq_reveal" onclick="(function(){var i=document.getElementById('mw_inq_token');i.type=(i.type==='password'?'text':'password');this.textContent=(i.type==='text'?'🙈 隠す':'👁 表示');}).call(this);">👁 表示</button>
                            <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $token ); ?>');this.textContent='✅ コピー済み';setTimeout(()=>this.textContent='コピー',1800);">コピー</button>
                        </div>
                        <?php if ( $is_locked ) : ?>
                            <p class="description">🔒 このトークンは <code>wp-config.php</code> の <code>MIMAMORI_INQUIRIES_API_TOKEN</code> 定数で固定されています。再発行するには定数を削除してください。</p>
                        <?php else : ?>
                            <p class="description">DB に保存されています。プラグインを削除しても <code>wp_options</code> に残ります。</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( ! $is_locked ) : ?>
                <tr>
                    <th>トークンの再発行</th>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('トークンを再発行します。\n古いトークンは即座に無効になり、みまもりウェブ側にも新しいトークンを登録し直す必要があります。\n続行しますか？');">
                            <?php wp_nonce_field( 'mimamori_inquiries_api_regenerate_token' ); ?>
                            <input type="hidden" name="action" value="mimamori_inquiries_api_regenerate_token">
                            <button type="submit" class="button button-secondary">🔁 トークンを再発行する</button>
                        </form>
                        <p class="description">漏洩等が疑われる場合のみご利用ください。再発行後は <strong>みまもりウェブ管理画面</strong> でトークンを更新する必要があります。</p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <h2 style="margin-top:32px">🛡️ 許可IP制限 (任意)</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'mimamori_inquiries_api_save_ips' ); ?>
                <input type="hidden" name="action" value="mimamori_inquiries_api_save_ips">
                <table class="form-table">
                    <tr>
                        <th>許可IP (カンマ区切り)</th>
                        <td>
                            <?php if ( $ips_locked ) : ?>
                                <code><?php echo esc_html( implode( ', ', $allowed_ips ) ); ?></code>
                                <p class="description">🔒 wp-config.php の <code>MIMAMORI_INQUIRIES_API_ALLOWED_IPS</code> 定数で固定されています。</p>
                            <?php else : ?>
                                <input type="text" name="allowed_ips" value="<?php echo esc_attr( implode( ', ', $allowed_ips ) ); ?>" class="regular-text" style="width:520px;font-family:monospace" placeholder="例: 203.0.113.10, 203.0.113.11">
                                <p class="description">空欄なら制限なし。みまもりウェブ側のサーバーIPを指定すると、それ以外からのアクセスを遮断できます。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php if ( ! $ips_locked ) : ?>
                    <?php submit_button( '許可IPを保存' ); ?>
                <?php endif; ?>
            </form>

            <h2 style="margin-top:32px">🧪 動作確認</h2>
            <p>ターミナルで以下を実行すると、認証込みで疎通確認できます:</p>
            <pre style="background:#f6f7f7;padding:12px;border-radius:4px;overflow:auto;font-size:12px;border:1px solid #ddd"><?php
                $cur_y = (int) wp_date( 'Y' );
                $cur_m = (int) wp_date( 'n' );
                echo "curl -H 'X-Mimamori-Token: " . esc_html( $token ) . "' \\\n";
                echo "  '" . esc_html( $endpoint_url ) . '?year=' . $cur_y . '&month=' . $cur_m . "'";
            ?></pre>
        </div>
        <?php
    }

    public static function handle_regenerate_token(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '権限がありません。', 403 );
        check_admin_referer( 'mimamori_inquiries_api_regenerate_token' );
        if ( self::is_token_locked_by_constant() ) {
            wp_die( 'トークンは wp-config.php 定数で固定されているため再発行できません。', 400 );
        }
        $new_token = self::generate_new_token();
        update_option( self::OPT_TOKEN, $new_token, false );
        wp_safe_redirect( add_query_arg( [
            'page'        => self::SETTINGS_SLUG,
            'regenerated' => 1,
        ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    public static function handle_save_allowed_ips(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '権限がありません。', 403 );
        check_admin_referer( 'mimamori_inquiries_api_save_ips' );
        if ( defined( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS' ) && (string) MIMAMORI_INQUIRIES_API_ALLOWED_IPS !== '' ) {
            wp_die( '許可IPは wp-config.php 定数で固定されているため変更できません。', 400 );
        }
        $raw = isset( $_POST['allowed_ips'] ) ? (string) wp_unslash( $_POST['allowed_ips'] ) : '';
        $list = array_filter( array_map( 'trim', explode( ',', $raw ) ), static function ( $ip ) {
            return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
        } );
        $clean = implode( ',', array_values( $list ) );
        update_option( self::OPT_ALLOWED_IPS, $clean, false );
        wp_safe_redirect( add_query_arg( [
            'page'      => self::SETTINGS_SLUG,
            'ips_saved' => 1,
        ], admin_url( 'options-general.php' ) ) );
        exit;
    }
}

Mimamori_Inquiries_API::bootstrap();
