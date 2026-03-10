<?php
// FILE: inc/gcrev-api/utils/class-opportunity-scorer.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Opportunity_Scorer') ) { return; }

/**
 * Gcrev_Opportunity_Scorer
 *
 * SERP 上位結果をもとにキーワードの「上がりやすさ目安」を
 * 0〜100 のスコアで算出する独自指標。
 *
 * 評価要素（5項目）:
 *  1. 強いドメイン比率（0〜30点）
 *  2. 地域小規模サイト比率（-20〜0点 ※ 難易度を下げる要素）
 *  3. タイトル最適化の強さ（0〜20点）
 *  4. 専門特化ページ比率（0〜20点）
 *  5. ローカルパック強度（0〜10点）
 *
 * @package Mimamori_Web
 * @since   2.6.0
 */
class Gcrev_Opportunity_Scorer {

    // =========================================================
    // 強いドメインの判定リスト
    // =========================================================

    /** 強い TLD / サフィックスパターン */
    private const STRONG_TLDS = [
        '.go.jp',   // 政府
        '.lg.jp',   // 地方自治体
        '.ac.jp',   // 大学・学術
        '.ed.jp',   // 教育
        '.gov',     // 英語圏政府
        '.edu',     // 英語圏大学
    ];

    /** 強いドメインリスト（完全一致・サフィックス一致） */
    private const STRONG_DOMAINS = [
        // 大手ポータル・比較サイト
        'hotpepper.jp', 'beauty.hotpepper.jp', 'suumo.jp', 'ekiten.jp',
        'epark.jp', 'minimo.jp', 'kakaku.com', 'my-best.com',
        'ferret-plus.com', 'liskul.com', 'web-幹事.jp',
        // 大手プラットフォーム
        'yahoo.co.jp', 'rakuten.co.jp', 'amazon.co.jp', 'amazon.com',
        'google.com', 'google.co.jp',
        // 百科事典・知識系
        'wikipedia.org',
        // メディア・ニュース
        'nikkei.com', 'mainichi.jp', 'asahi.com', 'yomiuri.co.jp',
        'nhk.or.jp', 'itmedia.co.jp', 'impress.co.jp', 'mynavi.jp',
        'doda.jp', 'diamond.jp', 'toyokeizai.net', 'prtimes.jp',
        // 大手企業・サービス
        'ntt.com', 'softbank.jp', 'kddi.com', 'docomo.ne.jp',
        'recruit.co.jp', 'benesse.co.jp',
        // UGC 大手
        'note.com', 'qiita.com', 'zenn.dev',
        // クラウドソーシング・ビジネスマッチング
        'lancers.jp', 'crowdworks.jp', 'coconala.com',
        // 求人・人材
        'indeed.com', 'en-japan.com', 'rikunabi.com',
        // グルメ・不動産等
        'tabelog.com', 'gnavi.co.jp', 'retty.me',
        'homes.co.jp', 'athome.co.jp', 'chintai.net',
        // Web制作系大手
        'web-tan.forum.jp', 'lig.inc', 'baigie.me',
    ];

    /** ブログ / UGC プラットフォーム（小規模ローカルとみなさない） */
    private const BLOG_PLATFORMS = [
        'ameblo.jp', 'hateblo.jp', 'hatenablog.com', 'hatenablog.jp',
        'livedoor.jp', 'fc2.com', 'blogspot.com', 'blogspot.jp',
        'wordpress.com', 'medium.com', 'tumblr.com', 'seesaa.net',
        'cocolog-nifty.com', 'exblog.jp',
    ];

    // =========================================================
    // メイン: スコア算出
    // =========================================================

    /**
     * SERP データからキーワードの上がりやすさスコアを算出
     *
     * @param array  $serp_items  SERP items 配列（DataForSEO fetch_serp の返り値）
     * @param string $keyword     検索キーワード
     * @return array{ score: ?int, level: ?string, icon_level: ?int, reasons: string[] }
     */
    public static function calculate( array $serp_items, string $keyword ): array {
        // organic アイテムのみ抽出（上位10件）
        $organic_items   = [];
        $has_local_pack  = false;
        $local_pack_count = 0;

        foreach ( $serp_items as $item ) {
            $type = $item['type'] ?? '';

            if ( $type === 'local_pack' ) {
                $has_local_pack = true;
                $local_pack_count++;
                continue;
            }
            if ( $type === 'maps' ) {
                $has_local_pack = true;
                continue;
            }

            if ( $type === 'organic' && count( $organic_items ) < 10 ) {
                $organic_items[] = $item;
            }
        }

        // SERP データ不足の場合はスコア算出しない
        if ( count( $organic_items ) < 3 ) {
            return [
                'score'      => null,
                'level'      => null,
                'icon_level' => null,
                'reasons'    => [ 'SERPデータが不足しています' ],
            ];
        }

        $total = count( $organic_items );
        $reasons = [];

        // --------------------------------------------------
        // 1. 強いドメイン比率 (0〜30)
        // --------------------------------------------------
        $strong_count = 0;
        foreach ( $organic_items as $item ) {
            if ( self::is_strong_domain( $item['domain'] ?? '' ) ) {
                $strong_count++;
            }
        }
        $score_strong = (int) round( ( $strong_count / $total ) * 30 );

        if ( $strong_count >= 5 ) {
            $reasons[] = '上位に大手サイトや強いドメインが多いです（' . $strong_count . '/' . $total . '件）';
        } elseif ( $strong_count <= 2 ) {
            $reasons[] = '大手サイトが少なく、比較的参入しやすい状況です';
        }

        // --------------------------------------------------
        // 2. 地域小規模サイト比率 (-20〜0)
        // --------------------------------------------------
        $small_local_count = 0;
        foreach ( $organic_items as $item ) {
            if ( self::is_small_local_site( $item['domain'] ?? '' ) ) {
                $small_local_count++;
            }
        }
        $score_small = - (int) round( ( $small_local_count / $total ) * 20 );

        if ( $small_local_count >= 4 ) {
            $reasons[] = '地域の中小サイトが多く含まれています（' . $small_local_count . '/' . $total . '件）';
        }

        // --------------------------------------------------
        // 3. タイトル最適化の強さ (0〜20)
        // --------------------------------------------------
        $keyword_parts = self::extract_keyword_parts( $keyword );
        $optimized_count = 0;
        foreach ( $organic_items as $item ) {
            if ( self::is_title_optimized( $item['title'] ?? '', $keyword_parts ) ) {
                $optimized_count++;
            }
        }
        $score_title = (int) round( ( $optimized_count / $total ) * 20 );

        if ( $optimized_count >= 6 ) {
            $reasons[] = 'タイトルが最適化されたページが多いです（' . $optimized_count . '/' . $total . '件）';
        } elseif ( $optimized_count <= 2 ) {
            $reasons[] = 'タイトル最適化が弱いページが多く、改善余地があります';
        }

        // --------------------------------------------------
        // 4. 専門特化ページ比率 (0〜20)
        // --------------------------------------------------
        $specialized_count = 0;
        foreach ( $organic_items as $item ) {
            if ( self::is_specialized_page( $item['url'] ?? '', $item['title'] ?? '', $keyword_parts ) ) {
                $specialized_count++;
            }
        }
        $score_specialized = (int) round( ( $specialized_count / $total ) * 20 );

        if ( $specialized_count >= 5 ) {
            $reasons[] = '専門特化ページ（サービスページや専門記事）が多いです';
        } elseif ( $specialized_count <= 2 ) {
            $reasons[] = '専門特化ページが少なく、専門コンテンツで差別化しやすいです';
        }

        // --------------------------------------------------
        // 5. ローカルパック強度 (0〜10)
        // --------------------------------------------------
        $score_local = 0;
        if ( $has_local_pack ) {
            $score_local = min( 10, $local_pack_count * 3 + 2 );
            $reasons[] = 'ローカルパック（Googleマップ）が表示されています';
        }

        // --------------------------------------------------
        // スコア合計 (0〜100 に丸め)
        // --------------------------------------------------
        $raw_score = $score_strong + $score_small + $score_title + $score_specialized + $score_local;
        $score = max( 0, min( 100, $raw_score ) );

        // --------------------------------------------------
        // レベル判定
        // --------------------------------------------------
        if ( $score <= 19 ) {
            $level = 'かなり狙いやすい';
            $icon_level = 1;
        } elseif ( $score <= 39 ) {
            $level = 'やや狙いやすい';
            $icon_level = 2;
        } elseif ( $score <= 59 ) {
            $level = 'ふつう';
            $icon_level = 3;
        } elseif ( $score <= 79 ) {
            $level = 'やや難しい';
            $icon_level = 4;
        } else {
            $level = '難しい';
            $icon_level = 5;
        }

        return [
            'score'      => $score,
            'level'      => $level,
            'icon_level' => $icon_level,
            'reasons'    => $reasons,
        ];
    }

    // =========================================================
    // ヘルパー: 強いドメイン判定
    // =========================================================

    /**
     * 強い（権威性の高い）ドメインかどうかを判定
     *
     * @param string $domain ドメイン名
     * @return bool
     */
    private static function is_strong_domain( string $domain ): bool {
        $domain = strtolower( preg_replace( '/^www\./i', '', trim( $domain ) ) );

        if ( $domain === '' ) {
            return false;
        }

        // TLD パターン一致
        foreach ( self::STRONG_TLDS as $tld ) {
            if ( substr( $domain, -strlen( $tld ) ) === $tld ) {
                return true;
            }
        }

        // ドメインリスト一致（完全一致 or サブドメイン一致）
        foreach ( self::STRONG_DOMAINS as $strong ) {
            if ( $domain === $strong || substr( $domain, -( strlen( $strong ) + 1 ) ) === '.' . $strong ) {
                return true;
            }
        }

        return false;
    }

    // =========================================================
    // ヘルパー: 地域小規模サイト判定
    // =========================================================

    /**
     * 地域の中小企業・小規模サイトかどうかを判定
     *
     * 「強いドメインでもなく、ブログプラットフォームでもない」
     * サイトを地域の小規模サイトとみなす。
     *
     * @param string $domain ドメイン名
     * @return bool
     */
    private static function is_small_local_site( string $domain ): bool {
        if ( self::is_strong_domain( $domain ) ) {
            return false;
        }

        $domain = strtolower( preg_replace( '/^www\./i', '', trim( $domain ) ) );

        // ブログ・UGC プラットフォームは除外
        foreach ( self::BLOG_PLATFORMS as $blog ) {
            if ( $domain === $blog || substr( $domain, -( strlen( $blog ) + 1 ) ) === '.' . $blog ) {
                return false;
            }
        }

        // 大手プラットフォームのサブドメインも除外
        if ( strpos( $domain, 'google.' ) !== false || strpos( $domain, 'yahoo.' ) !== false ) {
            return false;
        }

        // 上記いずれでもなければ小規模ローカルサイトとみなす
        return true;
    }

    // =========================================================
    // ヘルパー: キーワード分割
    // =========================================================

    /**
     * キーワードを構成パーツに分割する
     *
     * 全角/半角スペースで分割。
     * 例: "愛媛 ホームページ制作" → ["愛媛", "ホームページ制作"]
     *
     * @param string $keyword キーワード
     * @return string[]
     */
    private static function extract_keyword_parts( string $keyword ): array {
        $keyword = str_replace( "\xE3\x80\x80", ' ', $keyword ); // 全角スペース→半角
        $parts = preg_split( '/\s+/u', trim( $keyword ) );
        return array_values( array_filter( $parts, function ( $p ) {
            return mb_strlen( $p, 'UTF-8' ) > 0;
        } ) );
    }

    // =========================================================
    // ヘルパー: タイトル最適化判定
    // =========================================================

    /**
     * タイトルがキーワードに対して最適化されているか判定
     *
     * キーワードパーツの 2/3 以上がタイトルに含まれていれば最適化済みと判定。
     *
     * @param string   $title         ページタイトル
     * @param string[] $keyword_parts キーワードパーツ
     * @return bool
     */
    private static function is_title_optimized( string $title, array $keyword_parts ): bool {
        if ( $title === '' || empty( $keyword_parts ) ) {
            return false;
        }

        $title_lower = mb_strtolower( $title, 'UTF-8' );
        $matched = 0;

        foreach ( $keyword_parts as $part ) {
            if ( mb_strpos( $title_lower, mb_strtolower( $part, 'UTF-8' ) ) !== false ) {
                $matched++;
            }
        }

        // パーツの 2/3 以上一致で最適化済み
        $threshold = max( 1, (int) ceil( count( $keyword_parts ) * 0.67 ) );
        return $matched >= $threshold;
    }

    // =========================================================
    // ヘルパー: 専門特化ページ判定
    // =========================================================

    /**
     * 対象キーワード向けに作られた専用ページかどうかを判定
     *
     * トップページ（パスなし）は非特化。
     * サービスページ、LP、比較記事、ブログ記事などは特化とみなす。
     *
     * @param string   $url           ページURL
     * @param string   $title         ページタイトル
     * @param string[] $keyword_parts キーワードパーツ
     * @return bool
     */
    private static function is_specialized_page( string $url, string $title, array $keyword_parts ): bool {
        $path = (string) parse_url( $url, PHP_URL_PATH );
        $path = trim( $path, '/' );

        // トップページ = 非特化
        if ( $path === '' ) {
            return false;
        }

        $path_lower = strtolower( $path );
        $segments   = explode( '/', $path );
        $depth      = count( $segments );

        // 専門ページ URL パターン
        $specialized_patterns = [
            '/service', '/lp/', '/landing', '/works', '/portfolio',
            '/web-design', '/homepage', '/price', '/plan',
            '/blog/', '/article/', '/column/', '/news/', '/case/',
        ];

        foreach ( $specialized_patterns as $pattern ) {
            if ( strpos( '/' . $path_lower, $pattern ) !== false ) {
                return true;
            }
        }

        // パスが深く（2階層以上）かつタイトルが最適化されている場合は特化
        if ( $depth >= 2 && self::is_title_optimized( $title, $keyword_parts ) ) {
            return true;
        }

        return false;
    }
}
