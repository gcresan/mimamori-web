<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_Keyword_Research_Service') ) { return; }

/**
 * キーワード調査サービス
 *
 * クライアント情報・GSCデータ・シードキーワードを元に
 * Gemini AIでSEOキーワード候補を調査・提案する。
 */
class Gcrev_Keyword_Research_Service {

    private Gcrev_AI_Client $ai;
    private Gcrev_Config    $config;

    private const DEBUG_LOG = '/tmp/gcrev_seo_debug.log';

    /**
     * キーワードグループ定義
     */
    private const GROUPS = [
        'immediate'    => '今すぐ狙うべきキーワード',
        'local_seo'    => '地域SEO向けキーワード',
        'comparison'   => '比較・検討流入向けキーワード',
        'column'       => 'コラム記事向きキーワード',
        'service_page' => 'サービスページ向きキーワード',
    ];

    public function __construct( Gcrev_AI_Client $ai, Gcrev_Config $config ) {
        $this->ai     = $ai;
        $this->config = $config;
    }

    /**
     * キーワード調査を実行
     *
     * @param int   $user_id       対象クライアントのユーザーID
     * @param array $seed_keywords 追加シードキーワード（オプション）
     * @return array { success: bool, summary: array, groups: array, meta: array }
     */
    public function research( int $user_id, array $seed_keywords = [] ): array {
        $this->log( "research start: user_id={$user_id}, seeds=" . count( $seed_keywords ) );

        // 1. クライアント情報取得
        $settings = gcrev_get_client_settings( $user_id );
        $site_url = $settings['site_url'] ?? '';

        if ( empty( $site_url ) ) {
            return [
                'success' => false,
                'error'   => 'クライアントのサイトURLが設定されていません。先にクライアント設定でサイトURLを登録してください。',
            ];
        }

        $area_label     = function_exists( 'gcrev_get_client_area_label' )
            ? gcrev_get_client_area_label( $settings )
            : '';
        $biz_type_label = function_exists( 'gcrev_get_business_type_label' )
            ? gcrev_get_business_type_label( $settings['business_type'] ?? [] )
            : '';

        // 業種ラベルの取得
        $industry_label = $settings['industry'] ?? '';
        $industry_detail = $settings['industry_detail'] ?? '';

        // ペルソナ情報
        $persona_one_liner  = $settings['persona_one_liner'] ?? '';
        $persona_detail     = $settings['persona_detail_text'] ?? '';
        $persona_attributes = $settings['persona_attributes'] ?? [];
        $persona_decision   = $settings['persona_decision_factors'] ?? [];

        // 2. GSCキーワード取得（オプション、失敗時は空）
        $gsc_keywords = [];
        try {
            $gsc = new Gcrev_GSC_Fetcher( $this->config );
            $tz  = wp_timezone();
            $end   = new \DateTimeImmutable( 'now', $tz );
            $start = $end->modify( '-90 days' );
            $gsc_data = $gsc->fetch_gsc_data(
                $site_url,
                $start->format( 'Y-m-d' ),
                $end->format( 'Y-m-d' )
            );
            if ( ! empty( $gsc_data['keywords'] ) ) {
                $gsc_keywords = array_slice( $gsc_data['keywords'], 0, 50 );
            }
            $this->log( "GSC keywords fetched: " . count( $gsc_keywords ) );
        } catch ( \Throwable $e ) {
            $this->log( "GSC fetch error: " . $e->getMessage() );
        }

        // 3. プロンプト構築
        $prompt = $this->build_prompt(
            $site_url,
            $area_label,
            $industry_label,
            $industry_detail,
            $biz_type_label,
            $persona_one_liner,
            $persona_detail,
            $persona_attributes,
            $persona_decision,
            $settings,
            $gsc_keywords,
            $seed_keywords
        );

        // 4. Gemini呼び出し
        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'      => 0.7,
                'max_output_tokens' => 8192,
            ] );
            $this->log( "Gemini response length: " . strlen( $raw ) );
        } catch ( \Throwable $e ) {
            $this->log( "Gemini API error: " . $e->getMessage() );
            return [
                'success' => false,
                'error'   => 'AI分析中にエラーが発生しました: ' . $e->getMessage(),
            ];
        }

        // 5. レスポンスパース
        $parsed = $this->parse_response( $raw );
        if ( $parsed === null ) {
            $this->log( "Parse error. Raw (first 1000): " . substr( $raw, 0, 1000 ) );
            return [
                'success' => false,
                'error'   => 'AI応答の解析に失敗しました。再度お試しください。',
                'raw'     => substr( $raw, 0, 500 ),
            ];
        }

        return [
            'success' => true,
            'summary' => $parsed['summary'] ?? [],
            'groups'  => $parsed['groups'] ?? [],
            'meta'    => [
                'user_id'        => $user_id,
                'site_url'       => $site_url,
                'area'           => $area_label,
                'industry'       => $industry_label,
                'gsc_count'      => count( $gsc_keywords ),
                'seed_count'     => count( $seed_keywords ),
                'generated_at'   => ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' ),
            ],
        ];
    }

    /**
     * Geminiプロンプト構築
     */
    private function build_prompt(
        string $site_url,
        string $area_label,
        string $industry_label,
        string $industry_detail,
        string $biz_type_label,
        string $persona_one_liner,
        string $persona_detail,
        array  $persona_attributes,
        array  $persona_decision,
        array  $settings,
        array  $gsc_keywords,
        array  $seed_keywords
    ): string {
        $parts = [];

        // 役割定義
        $parts[] = <<<'ROLE'
あなたは日本のローカルビジネスSEOキーワード戦略の専門家です。
クライアントの業種・業態・地域・ターゲット層・既存の検索流入状況を分析し、
SEOで優先的に狙うべきキーワード候補を調査・提案してください。
ROLE;

        // クライアントプロファイル
        $profile = "## クライアントプロファイル\n";
        $profile .= "- サイトURL: {$site_url}\n";
        if ( $area_label )      $profile .= "- 対応エリア: {$area_label}\n";
        if ( $industry_label )  $profile .= "- 業種: {$industry_label}\n";
        if ( $industry_detail ) $profile .= "- 業種詳細: {$industry_detail}\n";
        if ( $biz_type_label )  $profile .= "- ビジネス形態: {$biz_type_label}\n";

        // エリア詳細
        $area_pref = $settings['area_pref'] ?? '';
        $area_city = $settings['area_city'] ?? '';
        $area_custom = $settings['area_custom'] ?? '';
        if ( $area_pref ) $profile .= "- 都道府県: {$area_pref}\n";
        if ( $area_city ) $profile .= "- 市区町村: {$area_city}\n";
        if ( $area_custom ) $profile .= "- カスタムエリア: {$area_custom}\n";

        // ステージ・コンバージョン
        $stage = $settings['stage'] ?? '';
        $conversions = $settings['main_conversions'] ?? '';
        if ( $stage )       $profile .= "- 事業ステージ: {$stage}\n";
        if ( $conversions ) $profile .= "- 主要コンバージョン: {$conversions}\n";

        $parts[] = $profile;

        // ペルソナ情報
        if ( $persona_one_liner || $persona_detail || ! empty( $persona_attributes ) ) {
            $persona = "## ターゲットペルソナ\n";
            if ( $persona_one_liner ) $persona .= "- 概要: {$persona_one_liner}\n";
            if ( ! empty( $persona_attributes ) ) {
                $persona .= "- 属性: " . implode( ', ', $persona_attributes ) . "\n";
            }
            if ( ! empty( $persona_decision ) ) {
                $persona .= "- 意思決定要因: " . implode( ', ', $persona_decision ) . "\n";
            }
            if ( $persona_detail ) $persona .= "- 詳細:\n{$persona_detail}\n";
            $parts[] = $persona;
        }

        // GSCデータ
        if ( ! empty( $gsc_keywords ) ) {
            $gsc_section = "## 現在の検索流入キーワード（Google Search Console 直近90日）\n";
            $gsc_section .= "| キーワード | クリック | 表示回数 | CTR | 平均順位 |\n";
            $gsc_section .= "|---|---|---|---|---|\n";
            foreach ( $gsc_keywords as $kw ) {
                $query = $kw['query'] ?? '';
                $clicks = $kw['clicks'] ?? '0';
                $impressions = $kw['impressions'] ?? '0';
                $ctr = $kw['ctr'] ?? '0%';
                $position = $kw['position'] ?? '-';
                $gsc_section .= "| {$query} | {$clicks} | {$impressions} | {$ctr} | {$position} |\n";
            }
            $parts[] = $gsc_section;
        }

        // シードキーワード
        if ( ! empty( $seed_keywords ) ) {
            $parts[] = "## 追加で調査したいキーワード（シード）\n" . implode( ', ', $seed_keywords );
        }

        // 指示
        $area_names = [];
        if ( $area_pref ) $area_names[] = $area_pref;
        if ( $area_city ) $area_names[] = $area_city;
        if ( $area_custom ) {
            $custom_parts = preg_split( '/[、,\s]+/', $area_custom );
            if ( $custom_parts ) $area_names = array_merge( $area_names, $custom_parts );
        }
        $area_csv = ! empty( $area_names ) ? implode( '、', array_unique( $area_names ) ) : '（エリア情報なし）';

        $parts[] = <<<INSTRUCTION
## 調査指示

### キーワード分類基準
以下の5グループに分類してキーワードを提案してください:

1. **immediate（今すぐ狙うべきキーワード）**: コンバージョンに直結する本命キーワード。競合が少なく、すぐに順位を狙えるもの
2. **local_seo（地域SEO向けキーワード）**: 地域名との掛け合わせキーワード。以下の地域名を使って組み合わせを生成: {$area_csv}
3. **comparison（比較・検討流入向けキーワード）**: 「○○ vs △△」「○○ おすすめ」「○○ 比較」「○○ 口コミ」など比較検討段階のユーザーが検索するキーワード
4. **column（コラム記事向きキーワード）**: 「○○とは」「○○ やり方」「○○ メリット」など情報収集段階のロングテールキーワード
5. **service_page（サービスページ向きキーワード）**: 具体的なサービス名や料金に関するキーワード。サービスページや料金ページで対策すべきもの

### 地域掛け合わせの自動生成
地域名（{$area_csv}）とサービス関連語を掛け合わせたキーワードを積極的に提案してください。
例: 「{$area_csv} ○○」「{$area_csv} ○○ おすすめ」

### 各キーワードに付与する情報
- keyword: キーワード文字列
- type: 本命 / 補助 / 比較流入 / ローカルSEO / コラム向け のいずれか
- priority: 高 / 中 / 低
- page_type: トップページ / サービスページ / 料金ページ / よくある質問 / コラム記事 / 事例紹介 のいずれか
- reason: このキーワードを狙うべき理由（30〜60文字）
- action: 既存ページ改善 / 新規ページ追加 / タイトル改善 / 見出し追加 / 内部リンク強化 のいずれか

### 各グループの目安件数
- immediate: 5〜10件
- local_seo: 10〜20件
- comparison: 5〜10件
- column: 5〜10件
- service_page: 5〜10件

### 戦略サマリー
groups とは別に、以下の5項目の戦略サマリーも提供してください:
- direction: このクライアントが優先して狙うべきキーワードの方向性（100〜200文字）
- priority_pages: まず改善すべき既存ページの提案（100〜200文字）
- new_pages: 新規作成すべきページ案（100〜200文字）
- title_tips: タイトルや見出しに含めるべき語句（100〜200文字）
- local_tips: ローカルSEOで有効な地域掛け合わせ案（100〜200文字）

INSTRUCTION;

        // 出力フォーマット指定
        $parts[] = <<<'FORMAT'
## 出力フォーマット

以下のJSON形式のみを出力してください。前後に説明文やマークダウンのコードブロック記号を入れないでください。

{
  "summary": {
    "direction": "...",
    "priority_pages": "...",
    "new_pages": "...",
    "title_tips": "...",
    "local_tips": "..."
  },
  "groups": {
    "immediate": [
      { "keyword": "...", "type": "本命", "priority": "高", "page_type": "サービスページ", "reason": "...", "action": "既存ページ改善" }
    ],
    "local_seo": [...],
    "comparison": [...],
    "column": [...],
    "service_page": [...]
  }
}
FORMAT;

        return implode( "\n\n", $parts );
    }

    /**
     * GeminiレスポンスからJSON抽出・パース
     */
    private function parse_response( string $raw ): ?array {
        // マークダウンコードブロック除去
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

        // 最初の { から最後の } までを抽出
        $first = strpos( $cleaned, '{' );
        $last  = strrpos( $cleaned, '}' );
        if ( $first === false || $last === false || $last <= $first ) {
            return null;
        }

        $json_str = substr( $cleaned, $first, $last - $first + 1 );
        $data = json_decode( $json_str, true );

        if ( ! is_array( $data ) ) {
            return null;
        }

        // 正規化: summary
        $summary_defaults = [
            'direction'      => '',
            'priority_pages' => '',
            'new_pages'      => '',
            'title_tips'     => '',
            'local_tips'     => '',
        ];
        $data['summary'] = array_merge( $summary_defaults, $data['summary'] ?? [] );

        // 正規化: groups
        $kw_defaults = [
            'keyword'   => '',
            'type'      => '補助',
            'priority'  => '中',
            'page_type' => '',
            'reason'    => '',
            'action'    => '',
            'volume'    => null,
            'competition' => null,
            'difficulty'  => null,
            'current_rank' => null,
        ];

        foreach ( array_keys( self::GROUPS ) as $group_key ) {
            $items = $data['groups'][ $group_key ] ?? [];
            if ( ! is_array( $items ) ) {
                $items = [];
            }
            $normalized = [];
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item['keyword'] ) ) {
                    continue;
                }
                $normalized[] = array_merge( $kw_defaults, $item );
            }
            $data['groups'][ $group_key ] = $normalized;
        }

        return $data;
    }

    /**
     * デバッグログ出力
     */
    private function log( string $message ): void {
        file_put_contents(
            self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . " [KeywordResearch] {$message}\n",
            FILE_APPEND
        );
    }
}
