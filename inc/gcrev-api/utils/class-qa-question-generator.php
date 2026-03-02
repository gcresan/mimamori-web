<?php
// FILE: inc/gcrev-api/utils/class-qa-question-generator.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Question_Generator
 *
 * QAバッチ用の質問を「偏り制御ランダム」で生成する。
 * カテゴリ比率を固定し、seed で再現可能。
 *
 * カテゴリ比率:
 *   kpi        30%  数字確認（PV/ユーザー/問い合わせ/流入元）
 *   trend      20%  期間比較・トレンド・原因分析
 *   comparison 20%  前月比/前年同月比
 *   page       20%  ページ系（人気ページ/LP/設定）
 *   general    10%  初心者質問（用語、見方、改善提案）
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Mimamori_QA_Question_Generator {

    /** カテゴリ別ウェイト */
    private const CATEGORY_WEIGHTS = [
        'kpi'        => 30,
        'trend'      => 20,
        'comparison' => 20,
        'page'       => 20,
        'general'    => 10,
    ];

    /**
     * 質問テンプレート — カテゴリごとに message + page_type を定義
     *
     * page_type: report_dashboard | analysis_detail | unknown
     */
    private const TEMPLATES = [
        'kpi' => [
            [ 'message' => '今月のアクセス数を教えて',                 'page_type' => 'report_dashboard' ],
            [ 'message' => 'セッション数はどのくらい？',               'page_type' => 'report_dashboard' ],
            [ 'message' => '今月のゴール数を教えて',                   'page_type' => 'report_dashboard' ],
            [ 'message' => '検索キーワードのトップ5は？',              'page_type' => 'analysis_detail' ],
            [ 'message' => '直帰率はどう？',                           'page_type' => 'analysis_detail' ],
            [ 'message' => 'スマホからのアクセスは？',                 'page_type' => 'analysis_detail' ],
            [ 'message' => 'パソコンとスマホの比率を教えて',           'page_type' => 'analysis_detail' ],
            [ 'message' => 'どこの国からアクセスがある？',             'page_type' => 'analysis_detail' ],
            [ 'message' => '検索での表示回数は？',                     'page_type' => 'analysis_detail' ],
            [ 'message' => '今月のユーザー数を教えて',                 'page_type' => 'report_dashboard' ],
            [ 'message' => '問い合わせ数はいくつ？',                   'page_type' => 'report_dashboard' ],
            [ 'message' => '流入元の内訳を教えて',                     'page_type' => 'analysis_detail' ],
            [ 'message' => 'SNSからの流入はある？',                    'page_type' => 'analysis_detail' ],
            [ 'message' => '検索からの訪問は何件？',                   'page_type' => 'analysis_detail' ],
            [ 'message' => '滞在時間の平均は？',                       'page_type' => 'analysis_detail' ],
            [ 'message' => 'クリック数はどのくらい？',                 'page_type' => 'analysis_detail' ],
            [ 'message' => '今の数字を簡単に教えて',                   'page_type' => 'report_dashboard' ],
            [ 'message' => 'Googleで何回表示されてる？',               'page_type' => 'analysis_detail' ],
            [ 'message' => '地域別のアクセスを教えて',                 'page_type' => 'analysis_detail' ],
            [ 'message' => '都市別で見るとどこからが多い？',           'page_type' => 'analysis_detail' ],
        ],
        'trend' => [
            [ 'message' => 'アクセスが下がった理由は？',               'page_type' => 'report_dashboard' ],
            [ 'message' => 'なぜセッションが減った？',                 'page_type' => 'report_dashboard' ],
            [ 'message' => '最近の変化について教えて',                 'page_type' => 'report_dashboard' ],
            [ 'message' => 'アクセスが急に増えたのはなぜ？',           'page_type' => 'report_dashboard' ],
            [ 'message' => 'Direct流入が多いのはなぜ？',               'page_type' => 'analysis_detail' ],
            [ 'message' => '検索順位が下がった原因は？',               'page_type' => 'analysis_detail' ],
            [ 'message' => 'ゴール数が減った理由を教えて',             'page_type' => 'report_dashboard' ],
            [ 'message' => '直帰率が高くなった原因は？',               'page_type' => 'analysis_detail' ],
            [ 'message' => '完了ページがLPになってるのはおかしい？',    'page_type' => 'analysis_detail' ],
            [ 'message' => 'スマホのアクセスが減ったのはなぜ？',       'page_type' => 'analysis_detail' ],
            [ 'message' => '海外からのアクセスが急増してるけど大丈夫？', 'page_type' => 'analysis_detail' ],
            [ 'message' => '検索キーワードの順位変動を教えて',         'page_type' => 'analysis_detail' ],
            [ 'message' => '先週からアクセスが減り続けてるけどなぜ？', 'page_type' => 'report_dashboard' ],
            [ 'message' => 'ユーザー数は増えてる？減ってる？',         'page_type' => 'report_dashboard' ],
            [ 'message' => '最近の傾向を教えて',                       'page_type' => 'report_dashboard' ],
        ],
        'comparison' => [
            [ 'message' => '先月と比べてどう？',                       'page_type' => 'report_dashboard' ],
            [ 'message' => '前の期間と比較して',                       'page_type' => 'report_dashboard' ],
            [ 'message' => '前年同月と比べてどう変わった？',           'page_type' => 'report_dashboard' ],
            [ 'message' => '先月比でアクセスは増えた？',               'page_type' => 'report_dashboard' ],
            [ 'message' => '前月のゴール数と比較して',                 'page_type' => 'report_dashboard' ],
            [ 'message' => '去年の今頃と比べてどう？',                 'page_type' => 'report_dashboard' ],
            [ 'message' => '検索キーワードの順位は先月と比べてどう？', 'page_type' => 'analysis_detail' ],
            [ 'message' => '先月より良くなったところはある？',         'page_type' => 'report_dashboard' ],
            [ 'message' => '前月比で悪くなった指標はある？',           'page_type' => 'report_dashboard' ],
            [ 'message' => 'スマホのアクセスは先月と比べて増えた？',   'page_type' => 'analysis_detail' ],
            [ 'message' => '流入元の構成は先月と変わった？',           'page_type' => 'analysis_detail' ],
            [ 'message' => '前月との差を教えて',                       'page_type' => 'report_dashboard' ],
            [ 'message' => '1月と2月を比較して',                       'page_type' => 'report_dashboard' ],
            [ 'message' => '直近30日と前の30日を比べて',               'page_type' => 'report_dashboard' ],
            [ 'message' => '先月はどんな状況だった？',                 'page_type' => 'report_dashboard' ],
        ],
        'page' => [
            [ 'message' => '一番見られているページは？',               'page_type' => 'analysis_detail' ],
            [ 'message' => '人気ページのURLを教えて',                  'page_type' => 'analysis_detail' ],
            [ 'message' => 'トップページのアクセスはどのくらい？',     'page_type' => 'analysis_detail' ],
            [ 'message' => 'お問い合わせページは見られてる？',         'page_type' => 'analysis_detail' ],
            [ 'message' => 'どのページが一番長く見られてる？',         'page_type' => 'analysis_detail' ],
            [ 'message' => 'ランディングページのトップ5を教えて',      'page_type' => 'analysis_detail' ],
            [ 'message' => 'ページ別のアクセス数を教えて',             'page_type' => 'analysis_detail' ],
            [ 'message' => '直帰率が高いページはどこ？',               'page_type' => 'analysis_detail' ],
            [ 'message' => '検索から入ってくるページはどこ？',         'page_type' => 'analysis_detail' ],
            [ 'message' => 'ブログ記事のアクセスはどう？',             'page_type' => 'analysis_detail' ],
            [ 'message' => '料金ページは見られてる？',                 'page_type' => 'analysis_detail' ],
            [ 'message' => '新しく作ったページのアクセスは？',         'page_type' => 'analysis_detail' ],
            [ 'message' => 'どのページから問い合わせにつながってる？', 'page_type' => 'analysis_detail' ],
            [ 'message' => 'GTMの設定方法を教えて',                    'page_type' => 'unknown' ],
            [ 'message' => 'GA4の見方がわからない',                    'page_type' => 'unknown' ],
        ],
        'general' => [
            [ 'message' => 'サイトの改善点を教えて',                   'page_type' => 'report_dashboard' ],
            [ 'message' => '何を優先すべき？',                         'page_type' => 'report_dashboard' ],
            [ 'message' => '今日30分でできる改善を教えて',             'page_type' => 'report_dashboard' ],
            [ 'message' => 'こんにちは',                               'page_type' => 'unknown' ],
            [ 'message' => 'ありがとう',                               'page_type' => 'unknown' ],
            [ 'message' => '直帰率ってなに？',                         'page_type' => 'unknown' ],
            [ 'message' => 'セッションとユーザーの違いは？',           'page_type' => 'unknown' ],
            [ 'message' => '検索順位を上げるにはどうしたらいい？',     'page_type' => 'unknown' ],
            [ 'message' => 'ホームページを見てもらうコツを教えて',     'page_type' => 'unknown' ],
            [ 'message' => 'このレポートの見方を教えて',               'page_type' => 'report_dashboard' ],
            [ 'message' => '成果が出ない原因をやさしく教えて',         'page_type' => 'report_dashboard' ],
            [ 'message' => 'Clarityってなに？',                        'page_type' => 'unknown' ],
            [ 'message' => 'MEO対策って何をすればいい？',               'page_type' => 'unknown' ],
            [ 'message' => 'Googleビジネスプロフィールの活用法は？',    'page_type' => 'unknown' ],
            [ 'message' => 'アクセス解析の基本を教えて',               'page_type' => 'unknown' ],
        ],
    ];

    /** ページタイプ別の仮想 currentPage URL */
    private const PAGE_URLS = [
        'report_dashboard' => [
            'url'   => 'https://dev.mimamori-web.jp/mypage/dashboard/',
            'title' => 'ダッシュボード | みまもりウェブ',
        ],
        'analysis_detail' => [
            'url'   => 'https://dev.mimamori-web.jp/mypage/analysis/',
            'title' => 'アクセス解析 | みまもりウェブ',
        ],
        'unknown' => [
            'url'   => 'https://dev.mimamori-web.jp/mypage/',
            'title' => 'マイページ | みまもりウェブ',
        ],
    ];

    /** @var int 乱数シード */
    private int $seed;

    public function __construct( int $seed ) {
        $this->seed = $seed;
    }

    /**
     * N問の質問を生成する。
     *
     * @param  int         $n        質問数
     * @param  string|null $category 特定カテゴリのみ（null = ウェイト付きミックス）
     * @return array 質問配列
     */
    public function generate( int $n, ?string $category = null ): array {
        mt_srand( $this->seed );

        $categories = ( $category !== null )
            ? array_fill( 0, $n, $category )
            : $this->pick_categories( $n );

        $questions = [];
        // カテゴリごとの使用済みインデックス管理
        $used = [];

        foreach ( $categories as $i => $cat ) {
            $templates = self::TEMPLATES[ $cat ] ?? self::TEMPLATES['general'];
            $count     = count( $templates );

            if ( ! isset( $used[ $cat ] ) ) {
                $used[ $cat ] = [];
            }

            // 全テンプレ使い切ったらリセット
            if ( count( $used[ $cat ] ) >= $count ) {
                $used[ $cat ] = [];
            }

            // 未使用テンプレからランダム選択
            $available = array_diff( range( 0, $count - 1 ), $used[ $cat ] );
            $pick      = array_values( $available )[ mt_rand( 0, count( $available ) - 1 ) ];
            $used[ $cat ][] = $pick;

            $tpl       = $templates[ $pick ];
            $page_type = $tpl['page_type'];

            $questions[] = [
                'id'              => sprintf( 'qa_%03d', $i + 1 ),
                'category'        => $cat,
                'message'         => $tpl['message'],
                'page_type'       => $page_type,
                'current_page'    => self::PAGE_URLS[ $page_type ] ?? self::PAGE_URLS['unknown'],
                'history'         => [],
                'section_context' => null,
            ];
        }

        return $questions;
    }

    /**
     * ウェイト付きランダムでカテゴリ配分を決定。
     *
     * @param  int   $n 質問数
     * @return array カテゴリ名の配列（長さ $n）
     */
    private function pick_categories( int $n ): array {
        $categories = [];
        $weights    = self::CATEGORY_WEIGHTS;
        $total_w    = array_sum( $weights );

        // まず比率に基づく固定枠を配分
        $remaining = $n;
        $counts    = [];
        foreach ( $weights as $cat => $w ) {
            $counts[ $cat ] = (int) floor( $n * $w / $total_w );
            $remaining -= $counts[ $cat ];
        }

        // 端数を最大剰余法で配分
        $remainders = [];
        foreach ( $weights as $cat => $w ) {
            $exact = $n * $w / $total_w;
            $remainders[ $cat ] = $exact - floor( $exact );
        }
        arsort( $remainders );
        foreach ( $remainders as $cat => $_ ) {
            if ( $remaining <= 0 ) break;
            $counts[ $cat ]++;
            $remaining--;
        }

        // カテゴリ配列を構築してシャッフル
        foreach ( $counts as $cat => $cnt ) {
            for ( $i = 0; $i < $cnt; $i++ ) {
                $categories[] = $cat;
            }
        }

        // mt_rand ベースの Fisher-Yates シャッフル（seed 再現可能）
        for ( $i = count( $categories ) - 1; $i > 0; $i-- ) {
            $j = mt_rand( 0, $i );
            [ $categories[ $i ], $categories[ $j ] ] = [ $categories[ $j ], $categories[ $i ] ];
        }

        return $categories;
    }

    /**
     * 利用可能なカテゴリ一覧を返す。
     *
     * @return array
     */
    public static function get_categories(): array {
        return array_keys( self::CATEGORY_WEIGHTS );
    }
}
