<?php
// FILE: inc/gcrev-api/modules/class-writing-service.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_Writing_Service') ) { return; }

/**
 * ライティング（記事生成）サービス — Phase 1
 *
 * 情報ストック CRUD、記事 CRUD、構成案生成を提供する。
 */
class Gcrev_Writing_Service {

    private Gcrev_AI_Client $ai;
    private Gcrev_Config    $config;

    private const DEBUG_LOG = '/tmp/gcrev_writing_debug.log';

    /** 情報ストック種別 */
    public const KNOWLEDGE_CATEGORIES = [
        'basic_info'  => '基本情報',
        'service'     => 'サービス情報',
        'faq'         => 'FAQ',
        'case_study'  => '実績・事例',
        'interview'   => 'インタビュー',
        'rules'       => '表記ルール・禁止表現',
        'notes'       => 'その他メモ',
    ];

    /** 記事タイプ */
    public const ARTICLE_TYPES = [
        'explanation' => '解説記事',
        'comparison'  => '比較記事',
        'faq'         => 'FAQ記事',
        'case_study'  => '事例記事',
        'local'       => '地域訴求記事',
    ];

    /** 記事の目的 */
    public const ARTICLE_PURPOSES = [
        'traffic'    => 'アクセス獲得',
        'inquiry'    => '問い合わせ獲得',
        'local_seo'  => '地域SEO',
        'comparison' => '比較検討対策',
        'brand'      => '指名検索補強',
    ];

    /** 文体 */
    public const TONES = [
        'friendly'     => 'やさしく',
        'professional' => '専門的に',
        'trustworthy'  => '信頼感重視',
        'casual'       => '親しみやすく',
    ];

    public function __construct( Gcrev_AI_Client $ai, Gcrev_Config $config ) {
        $this->ai     = $ai;
        $this->config = $config;
    }

    // =========================================================
    // 情報ストック CRUD
    // =========================================================

    /**
     * 情報ストック一覧取得
     */
    public function list_knowledge( int $user_id ): array {
        $query = new \WP_Query( [
            'post_type'      => 'gcrev_knowledge',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_gcrev_knowledge_user_id', 'value' => $user_id, 'type' => 'NUMERIC' ],
                [ 'key' => '_gcrev_knowledge_is_active', 'value' => '1' ],
            ],
            'no_found_rows' => true,
        ] );

        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = $this->format_knowledge( $post );
        }
        return $items;
    }

    /**
     * 情報ストック作成/更新
     */
    public function save_knowledge( int $user_id, array $data ): array {
        $id       = absint( $data['id'] ?? 0 );
        $title    = sanitize_text_field( $data['title'] ?? '' );
        $category = sanitize_text_field( $data['category'] ?? 'notes' );
        $content  = sanitize_textarea_field( $data['content'] ?? '' );
        $tags     = isset( $data['tags'] ) && is_array( $data['tags'] )
            ? array_map( 'sanitize_text_field', $data['tags'] ) : [];
        $priority = max( 1, min( 5, absint( $data['priority'] ?? 3 ) ) );

        if ( $title === '' ) {
            return [ 'success' => false, 'error' => 'タイトルを入力してください。' ];
        }
        if ( ! isset( self::KNOWLEDGE_CATEGORIES[ $category ] ) ) {
            $category = 'notes';
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );

        if ( $id > 0 ) {
            // 更新: オーナー確認
            $owner = (int) get_post_meta( $id, '_gcrev_knowledge_user_id', true );
            if ( $owner !== $user_id ) {
                return [ 'success' => false, 'error' => '権限がありません。' ];
            }
            wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
        } else {
            // 新規作成
            $id = wp_insert_post( [
                'post_type'   => 'gcrev_knowledge',
                'post_title'  => $title,
                'post_status' => 'publish',
            ] );
            if ( is_wp_error( $id ) || $id === 0 ) {
                return [ 'success' => false, 'error' => '保存に失敗しました。' ];
            }
            update_post_meta( $id, '_gcrev_knowledge_user_id', $user_id );
            update_post_meta( $id, '_gcrev_knowledge_is_active', '1' );
            update_post_meta( $id, '_gcrev_knowledge_created_at', $now );
        }

        update_post_meta( $id, '_gcrev_knowledge_category', $category );
        update_post_meta( $id, '_gcrev_knowledge_content', $content );
        update_post_meta( $id, '_gcrev_knowledge_tags', wp_json_encode( $tags, JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $id, '_gcrev_knowledge_priority', $priority );
        update_post_meta( $id, '_gcrev_knowledge_updated_at', $now );

        $post = get_post( $id );
        return [ 'success' => true, 'item' => $this->format_knowledge( $post ) ];
    }

    /**
     * 情報ストック削除（論理削除）
     */
    public function delete_knowledge( int $user_id, int $post_id ): array {
        $owner = (int) get_post_meta( $post_id, '_gcrev_knowledge_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }
        update_post_meta( $post_id, '_gcrev_knowledge_is_active', '0' );
        return [ 'success' => true ];
    }

    private function format_knowledge( \WP_Post $post ): array {
        $tags_raw = get_post_meta( $post->ID, '_gcrev_knowledge_tags', true );
        $tags = is_array( $tags_raw ) ? $tags_raw : ( json_decode( $tags_raw ?: '[]', true ) ?: [] );
        return [
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'category'   => get_post_meta( $post->ID, '_gcrev_knowledge_category', true ) ?: 'notes',
            'content'    => get_post_meta( $post->ID, '_gcrev_knowledge_content', true ) ?: '',
            'tags'       => $tags,
            'priority'   => (int) ( get_post_meta( $post->ID, '_gcrev_knowledge_priority', true ) ?: 3 ),
            'created_at' => get_post_meta( $post->ID, '_gcrev_knowledge_created_at', true ) ?: '',
            'updated_at' => get_post_meta( $post->ID, '_gcrev_knowledge_updated_at', true ) ?: '',
        ];
    }

    // =========================================================
    // 記事 CRUD
    // =========================================================

    /**
     * 記事一覧取得
     */
    public function list_articles( int $user_id ): array {
        $query = new \WP_Query( [
            'post_type'      => 'gcrev_article',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => '_gcrev_article_user_id',
            'meta_value'     => $user_id,
            'meta_type'      => 'NUMERIC',
            'no_found_rows'  => true,
        ] );

        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = $this->format_article_summary( $post );
        }
        return $items;
    }

    /**
     * 記事作成
     */
    public function create_article( int $user_id, array $data ): array {
        $keyword = sanitize_text_field( $data['keyword'] ?? '' );
        if ( $keyword === '' ) {
            return [ 'success' => false, 'error' => 'キーワードを入力してください。' ];
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );

        $post_id = wp_insert_post( [
            'post_type'   => 'gcrev_article',
            'post_title'  => $keyword,
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) || $post_id === 0 ) {
            return [ 'success' => false, 'error' => '記事の作成に失敗しました。' ];
        }

        update_post_meta( $post_id, '_gcrev_article_user_id', $user_id );
        update_post_meta( $post_id, '_gcrev_article_keyword', $keyword );
        update_post_meta( $post_id, '_gcrev_article_type', 'explanation' );
        update_post_meta( $post_id, '_gcrev_article_purpose', 'traffic' );
        update_post_meta( $post_id, '_gcrev_article_tone', 'professional' );
        update_post_meta( $post_id, '_gcrev_article_target_reader', '' );
        update_post_meta( $post_id, '_gcrev_article_outline_json', '' );
        update_post_meta( $post_id, '_gcrev_article_selected_knowledge_ids', '[]' );
        update_post_meta( $post_id, '_gcrev_article_status', 'keyword_set' );
        update_post_meta( $post_id, '_gcrev_article_created_at', $now );
        update_post_meta( $post_id, '_gcrev_article_updated_at', $now );

        $post = get_post( $post_id );
        return [ 'success' => true, 'article' => $this->format_article_detail( $post ) ];
    }

    /**
     * 記事詳細取得
     */
    public function get_article( int $user_id, int $post_id ): ?array {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'gcrev_article' ) { return null; }
        $owner = (int) get_post_meta( $post_id, '_gcrev_article_user_id', true );
        if ( $owner !== $user_id ) { return null; }
        return $this->format_article_detail( $post );
    }

    /**
     * 記事削除
     */
    public function delete_article( int $user_id, int $post_id ): array {
        $owner = (int) get_post_meta( $post_id, '_gcrev_article_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }
        wp_delete_post( $post_id, true );
        return [ 'success' => true ];
    }

    /**
     * 記事設定更新
     */
    public function update_article_settings( int $user_id, int $article_id, array $data ): array {
        $owner = (int) get_post_meta( $article_id, '_gcrev_article_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }

        $fields = [
            'type'                  => '_gcrev_article_type',
            'purpose'               => '_gcrev_article_purpose',
            'tone'                  => '_gcrev_article_tone',
            'target_reader'         => '_gcrev_article_target_reader',
            'selected_knowledge_ids' => '_gcrev_article_selected_knowledge_ids',
        ];

        foreach ( $fields as $key => $meta_key ) {
            if ( ! isset( $data[ $key ] ) ) { continue; }
            $val = $data[ $key ];
            if ( $key === 'selected_knowledge_ids' ) {
                $val = wp_json_encode( is_array( $val ) ? array_map( 'absint', $val ) : [], JSON_UNESCAPED_UNICODE );
            } else {
                $val = sanitize_text_field( $val );
            }
            update_post_meta( $article_id, $meta_key, $val );
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        $post = get_post( $article_id );
        return [ 'success' => true, 'article' => $this->format_article_detail( $post ) ];
    }

    private function format_article_summary( \WP_Post $post ): array {
        return [
            'id'          => $post->ID,
            'keyword'     => get_post_meta( $post->ID, '_gcrev_article_keyword', true ) ?: '',
            'type'        => get_post_meta( $post->ID, '_gcrev_article_type', true ) ?: 'explanation',
            'purpose'     => get_post_meta( $post->ID, '_gcrev_article_purpose', true ) ?: 'traffic',
            'status'      => get_post_meta( $post->ID, '_gcrev_article_status', true ) ?: 'keyword_set',
            'has_outline'  => (bool) get_post_meta( $post->ID, '_gcrev_article_outline_json', true ),
            'created_at'  => get_post_meta( $post->ID, '_gcrev_article_created_at', true ) ?: '',
            'updated_at'  => get_post_meta( $post->ID, '_gcrev_article_updated_at', true ) ?: '',
        ];
    }

    private function format_article_detail( \WP_Post $post ): array {
        $outline_raw = get_post_meta( $post->ID, '_gcrev_article_outline_json', true );
        $outline = $outline_raw ? json_decode( $outline_raw, true ) : null;

        $kid_raw = get_post_meta( $post->ID, '_gcrev_article_selected_knowledge_ids', true );
        $knowledge_ids = is_array( $kid_raw ) ? $kid_raw : ( json_decode( $kid_raw ?: '[]', true ) ?: [] );

        return [
            'id'                     => $post->ID,
            'keyword'                => get_post_meta( $post->ID, '_gcrev_article_keyword', true ) ?: '',
            'type'                   => get_post_meta( $post->ID, '_gcrev_article_type', true ) ?: 'explanation',
            'purpose'                => get_post_meta( $post->ID, '_gcrev_article_purpose', true ) ?: 'traffic',
            'tone'                   => get_post_meta( $post->ID, '_gcrev_article_tone', true ) ?: 'professional',
            'target_reader'          => get_post_meta( $post->ID, '_gcrev_article_target_reader', true ) ?: '',
            'selected_knowledge_ids' => $knowledge_ids,
            'outline'                => $outline,
            'status'                 => get_post_meta( $post->ID, '_gcrev_article_status', true ) ?: 'keyword_set',
            'created_at'             => get_post_meta( $post->ID, '_gcrev_article_created_at', true ) ?: '',
            'updated_at'             => get_post_meta( $post->ID, '_gcrev_article_updated_at', true ) ?: '',
        ];
    }

    // =========================================================
    // 順位計測キーワード取得
    // =========================================================

    /**
     * ユーザーの順位計測キーワード一覧を返す
     */
    public function get_rank_keywords( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_rank_keywords';

        // テーブル存在チェック
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ) );
        if ( ! $exists ) { return []; }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword FROM {$table} WHERE user_id = %d AND enabled = 1 ORDER BY sort_order ASC, id ASC LIMIT 100",
            $user_id
        ), ARRAY_A );

        return $rows ?: [];
    }

    // =========================================================
    // 構成案生成
    // =========================================================

    /**
     * 構成案を AI で生成
     */
    public function generate_outline( int $user_id, int $article_id ): array {
        $article = $this->get_article( $user_id, $article_id );
        if ( ! $article ) {
            return [ 'success' => false, 'error' => '記事が見つかりません。' ];
        }

        $keyword = $article['keyword'];
        if ( $keyword === '' ) {
            return [ 'success' => false, 'error' => 'キーワードが設定されていません。' ];
        }

        $this->log( "generate_outline: article_id={$article_id}, keyword={$keyword}" );

        // クライアント設定
        $settings = function_exists( 'gcrev_get_client_settings' )
            ? gcrev_get_client_settings( $user_id ) : [];

        // 選択された情報ストック
        $knowledge_items = [];
        $kid_ids = $article['selected_knowledge_ids'] ?? [];
        if ( ! empty( $kid_ids ) ) {
            foreach ( $kid_ids as $kid ) {
                $kp = get_post( absint( $kid ) );
                if ( ! $kp ) { continue; }
                $knowledge_items[] = [
                    'title'    => $kp->post_title,
                    'category' => get_post_meta( $kp->ID, '_gcrev_knowledge_category', true ) ?: '',
                    'content'  => get_post_meta( $kp->ID, '_gcrev_knowledge_content', true ) ?: '',
                ];
            }
        }

        // プロンプト構築
        $prompt = $this->build_outline_prompt(
            $keyword,
            $article['type'],
            $article['purpose'],
            $article['tone'],
            $article['target_reader'],
            $settings,
            $knowledge_items
        );

        // Gemini 呼び出し
        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.7,
                'max_output_tokens' => 16384,
            ] );
            $this->log( "Gemini response length: " . strlen( $raw ) );
        } catch ( \Throwable $e ) {
            $this->log( "Gemini error: " . $e->getMessage() );
            return [ 'success' => false, 'error' => 'AI構成案生成中にエラーが発生しました: ' . $e->getMessage() ];
        }

        // JSON パース
        $outline = $this->parse_outline_response( $raw );
        if ( $outline === null ) {
            $this->log( "Parse error. Raw (first 1000): " . substr( $raw, 0, 1000 ) );
            return [ 'success' => false, 'error' => 'AI応答の解析に失敗しました。再度お試しください。' ];
        }

        // 保存
        update_post_meta( $article_id, '_gcrev_article_outline_json',
            wp_json_encode( $outline, JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $article_id, '_gcrev_article_status', 'outline_generated' );
        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        $this->log( "Outline saved for article_id={$article_id}" );

        return [
            'success' => true,
            'outline' => $outline,
        ];
    }

    // =========================================================
    // プロンプト構築
    // =========================================================

    private function build_outline_prompt(
        string $keyword,
        string $article_type,
        string $purpose,
        string $tone,
        string $target_reader,
        array  $settings,
        array  $knowledge_items
    ): string {
        $parts = [];

        // 役割
        $type_label    = self::ARTICLE_TYPES[ $article_type ] ?? '解説記事';
        $purpose_label = self::ARTICLE_PURPOSES[ $purpose ] ?? 'アクセス獲得';
        $tone_label    = self::TONES[ $tone ] ?? '専門的に';

        $parts[] = "あなたは日本のローカルビジネス向けSEOコンテンツの構成案作成の専門家です。\n"
            . "指定されたキーワードに対して、検索意図を分析し、SEOに最適化された記事の構成案を作成してください。\n"
            . "一次情報（クライアント固有の情報）がある場合は最大限に活用し、ないことは断定しないでください。";

        // 記事条件
        $conditions  = "## 記事の条件\n";
        $conditions .= "- 対策キーワード: {$keyword}\n";
        $conditions .= "- 記事タイプ: {$type_label}\n";
        $conditions .= "- 記事の目的: {$purpose_label}\n";
        $conditions .= "- 文体: {$tone_label}\n";
        if ( $target_reader !== '' ) {
            $conditions .= "- 想定読者: {$target_reader}\n";
        }
        $parts[] = $conditions;

        // クライアント情報
        $site_url = $settings['site_url'] ?? '';
        if ( $site_url !== '' ) {
            $profile  = "## クライアント情報\n";
            $profile .= "- サイトURL: {$site_url}\n";
            $area_label = function_exists( 'gcrev_get_client_area_label' )
                ? gcrev_get_client_area_label( $settings ) : '';
            if ( $area_label )                           $profile .= "- エリア: {$area_label}\n";
            if ( ! empty( $settings['industry'] ) )      $profile .= "- 業種: {$settings['industry']}\n";
            if ( ! empty( $settings['industry_detail'] ) ) $profile .= "- 業種詳細: {$settings['industry_detail']}\n";
            if ( ! empty( $settings['persona_one_liner'] ) ) $profile .= "- ターゲット: {$settings['persona_one_liner']}\n";
            $parts[] = $profile;
        }

        // 情報ストック
        if ( ! empty( $knowledge_items ) ) {
            $kb_section = "## 参照情報（クライアント固有の一次情報）\n";
            $kb_section .= "以下の情報はクライアントから提供された一次情報です。構成案に積極的に活用し、参照した情報を明示してください。\n\n";
            $total_chars = 0;
            foreach ( $knowledge_items as $ki ) {
                $cat_label = self::KNOWLEDGE_CATEGORIES[ $ki['category'] ] ?? $ki['category'];
                $content = $ki['content'];
                if ( $total_chars + mb_strlen( $content ) > 8000 ) {
                    $content = mb_substr( $content, 0, max( 0, 8000 - $total_chars ) ) . '…（以下省略）';
                }
                $total_chars += mb_strlen( $content );
                $kb_section .= "### {$ki['title']}（{$cat_label}）\n{$content}\n\n";
                if ( $total_chars >= 8000 ) { break; }
            }
            $parts[] = $kb_section;
        }

        // 出力指示
        $parts[] = <<<'INSTRUCTION'
## 出力指示

以下のJSON形式のみを出力してください。前後に説明文やマークダウンのコードブロック記号を入れないでください。

{
  "title_options": ["タイトル案1", "タイトル案2", "タイトル案3"],
  "search_intent": "このキーワードの検索意図の分析（100〜200文字）",
  "target_reader_detail": "想定読者の詳細（100〜150文字）",
  "target_word_count": 3000,
  "headings": [
    {
      "level": "h2",
      "text": "見出しテキスト",
      "description": "この見出しで書く内容の説明（50〜100文字）",
      "referenced_knowledge": ["参照した情報ストックのタイトル"],
      "children": [
        {
          "level": "h3",
          "text": "小見出しテキスト",
          "description": "この小見出しで書く内容（30〜60文字）"
        }
      ]
    }
  ],
  "missing_info": [
    "不足している情報1（例：他社との差別化ポイントが不明）",
    "不足している情報2"
  ],
  "seo_tips": [
    "SEO改善のヒント1",
    "SEO改善のヒント2"
  ]
}

### 構成案作成のルール
- h2 見出しは5〜8個程度
- 各 h2 には必要に応じて h3 の子見出しを追加
- 導入文とまとめは h2 として含める
- 一次情報を参照した見出しには referenced_knowledge を必ず入れる
- 一次情報にないことは missing_info に明記する
- FAQセクション（h2）を1つ含める（3〜5問）
- CTAセクション（h2）を末尾に1つ含める
INSTRUCTION;

        return implode( "\n\n", $parts );
    }

    // =========================================================
    // レスポンスパース
    // =========================================================

    private function parse_outline_response( string $raw ): ?array {
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

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

        // 最低限のバリデーション
        if ( empty( $data['headings'] ) || ! is_array( $data['headings'] ) ) {
            return null;
        }

        // デフォルト値の補完
        $data['title_options']       = $data['title_options'] ?? [];
        $data['search_intent']       = $data['search_intent'] ?? '';
        $data['target_reader_detail'] = $data['target_reader_detail'] ?? '';
        $data['target_word_count']   = (int) ( $data['target_word_count'] ?? 3000 );
        $data['missing_info']        = $data['missing_info'] ?? [];
        $data['seo_tips']            = $data['seo_tips'] ?? [];

        return $data;
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    private function log( string $message ): void {
        file_put_contents(
            self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . " [Writing] {$message}\n",
            FILE_APPEND
        );
    }
}
