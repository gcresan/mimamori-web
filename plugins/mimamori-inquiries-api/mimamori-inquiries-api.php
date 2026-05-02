<?php
/**
 * Plugin Name: みまもりウェブ 問い合わせ集計API
 * Plugin URI:  https://mimamori-web.jp/
 * Description: Flamingo / MW WP Form の問い合わせデータを月単位で集計し、みまもりウェブに対して REST API で返却する。スパム・テスト・営業を除外した「有効問い合わせ数」も算出する。
 * Version:     1.0.0
 * Author:      みまもりウェブ
 * License:     GPL-2.0-or-later
 * Text Domain: mimamori-inquiries-api
 *
 * 設定: wp-config.php に以下の定数を追加してください
 *   define( 'MIMAMORI_INQUIRIES_API_TOKEN', '<32文字以上のランダム文字列>' );
 *
 * オプション: 任意の許可IPをカンマ区切りで指定（指定時のみフィルタ適用）
 *   define( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS', '203.0.113.10,203.0.113.11' );
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

    /**
     * フック登録（プラグインエントリ）
     */
    public static function bootstrap(): void {
        error_log( '[MIMAMORI_INQUIRIES_API] bootstrap() called' );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
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

        // (1) 定数 MIMAMORI_INQUIRIES_API_TOKEN が未定義/空ならゲート閉鎖
        if ( ! defined( 'MIMAMORI_INQUIRIES_API_TOKEN' ) || MIMAMORI_INQUIRIES_API_TOKEN === '' ) {
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
        if ( ! hash_equals( (string) MIMAMORI_INQUIRIES_API_TOKEN, $header ) ) {
            return false;
        }

        // (3) 任意の IP 許可リスト（定義時のみ適用）
        if ( defined( 'MIMAMORI_INQUIRIES_API_ALLOWED_IPS' ) && MIMAMORI_INQUIRIES_API_ALLOWED_IPS !== '' ) {
            $allowed = array_filter( array_map( 'trim', explode( ',', (string) MIMAMORI_INQUIRIES_API_ALLOWED_IPS ) ) );
            $remote  = self::get_remote_ip();
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

        // (A) MW WP Form は v3 系で CPT 'mwf_inquiry' に保存する場合がある
        if ( post_type_exists( 'mwf_inquiry' ) ) {
            $posts = get_posts( [
                'post_type'        => 'mwf_inquiry',
                'post_status'      => 'any',
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
            foreach ( $posts as $post ) {
                $meta = get_post_meta( $post->ID );
                $items[] = [
                    'source'  => 'mw_wp_form_cpt',
                    'email'   => self::find_email_in_meta( $meta ),
                    'name'    => self::find_name_in_meta( $meta ),
                    'message' => self::find_message_in_meta( $meta ),
                    'date'    => $post->post_date,
                    'spam'    => false,
                ];
            }
        }

        // (B) 旧式: 専用テーブル wp_mwf_entries に保存される場合
        global $wpdb;
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
        if ( post_type_exists( 'mwf_inquiry' ) ) {
            $sources[] = 'mw_wp_form_cpt';
        }
        $table  = $wpdb->prefix . 'mwf_entries';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            $sources[] = 'mw_wp_form_db';
        }
        return $sources;
    }
}

Mimamori_Inquiries_API::bootstrap();
