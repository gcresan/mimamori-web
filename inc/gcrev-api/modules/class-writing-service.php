<?php
// FILE: inc/gcrev-api/modules/class-writing-service.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_Writing_Service') ) { return; }

/**
 * ライティング（記事生成）サービス
 *
 * 情報ストック CRUD、記事 CRUD、構成案生成、本文生成、WP下書き保存を提供する。
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

        // クライアント設定からペルソナ情報を取得して想定読者の初期値にする
        $default_reader = $this->build_default_reader( $user_id );

        // キーワードから最適な記事タイプ・目的・文体をルールベースで推定
        $cs = function_exists( 'gcrev_get_client_settings' ) ? gcrev_get_client_settings( $user_id ) : [];
        $auto = $this->detect_article_settings( $keyword, $cs );

        update_post_meta( $post_id, '_gcrev_article_user_id', $user_id );
        update_post_meta( $post_id, '_gcrev_article_keyword', $keyword );
        update_post_meta( $post_id, '_gcrev_article_type', $auto['type'] );
        update_post_meta( $post_id, '_gcrev_article_purpose', $auto['purpose'] );
        update_post_meta( $post_id, '_gcrev_article_tone', $auto['tone'] );
        update_post_meta( $post_id, '_gcrev_article_target_reader', $default_reader );
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

        $notes_raw = get_post_meta( $post->ID, '_gcrev_article_notes', true );
        $notes = $notes_raw ? json_decode( $notes_raw, true ) : [];

        $interview_raw = get_post_meta( $post->ID, '_gcrev_article_interview_json', true );
        $interview = $interview_raw ? json_decode( $interview_raw, true ) : null;

        // target_reader が空の場合はクライアント設定から補完
        $target_reader = get_post_meta( $post->ID, '_gcrev_article_target_reader', true ) ?: '';
        if ( $target_reader === '' && function_exists( 'gcrev_get_client_settings' ) ) {
            $user_id = (int) get_post_meta( $post->ID, '_gcrev_article_user_id', true );
            if ( $user_id > 0 ) {
                $target_reader = $this->build_default_reader( $user_id );
                // DB にも保存して次回以降は直接取得
                if ( $target_reader !== '' ) {
                    update_post_meta( $post->ID, '_gcrev_article_target_reader', $target_reader );
                }
            }
        }

        return [
            'id'                     => $post->ID,
            'keyword'                => get_post_meta( $post->ID, '_gcrev_article_keyword', true ) ?: '',
            'type'                   => get_post_meta( $post->ID, '_gcrev_article_type', true ) ?: 'explanation',
            'purpose'                => get_post_meta( $post->ID, '_gcrev_article_purpose', true ) ?: 'traffic',
            'tone'                   => get_post_meta( $post->ID, '_gcrev_article_tone', true ) ?: 'professional',
            'target_reader'          => $target_reader,
            'selected_knowledge_ids' => $knowledge_ids,
            'outline'                => $outline,
            'notes'                  => is_array( $notes ) ? $notes : [],
            'interview'              => is_array( $interview ) ? $interview : null,
            'draft_content'          => get_post_meta( $post->ID, '_gcrev_article_draft_content', true ) ?: '',
            'wp_draft_id'            => (int) get_post_meta( $post->ID, '_gcrev_article_wp_draft_id', true ),
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
    // Phase 2: 記事個別情報
    // =========================================================

    /**
     * 記事個別のテキストメモを追加
     */
    public function add_article_note( int $user_id, int $article_id, array $data ): array {
        $owner = (int) get_post_meta( $article_id, '_gcrev_article_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }
        $text = sanitize_textarea_field( $data['text'] ?? '' );
        if ( $text === '' ) {
            return [ 'success' => false, 'error' => 'テキストを入力してください。' ];
        }

        $notes_raw = get_post_meta( $article_id, '_gcrev_article_notes', true );
        $notes = $notes_raw ? json_decode( $notes_raw, true ) : [];
        if ( ! is_array( $notes ) ) { $notes = []; }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        $notes[] = [
            'id'         => uniqid( 'note_' ),
            'text'       => $text,
            'created_at' => $now,
        ];

        update_post_meta( $article_id, '_gcrev_article_notes', wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        return [ 'success' => true, 'notes' => $notes ];
    }

    /**
     * 記事個別メモを削除
     */
    public function delete_article_note( int $user_id, int $article_id, string $note_id ): array {
        $owner = (int) get_post_meta( $article_id, '_gcrev_article_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }

        $notes_raw = get_post_meta( $article_id, '_gcrev_article_notes', true );
        $notes = $notes_raw ? json_decode( $notes_raw, true ) : [];
        if ( ! is_array( $notes ) ) { $notes = []; }

        $notes = array_values( array_filter( $notes, function( $n ) use ( $note_id ) {
            return ( $n['id'] ?? '' ) !== $note_id;
        } ) );

        update_post_meta( $article_id, '_gcrev_article_notes', wp_json_encode( $notes, JSON_UNESCAPED_UNICODE ) );
        return [ 'success' => true, 'notes' => $notes ];
    }

    // =========================================================
    // Phase 2: ヒアリング質問生成
    // =========================================================

    /**
     * 構成案の不足情報をもとにヒアリング質問を生成
     */
    public function generate_interview( int $user_id, int $article_id ): array {
        $article = $this->get_article( $user_id, $article_id );
        if ( ! $article ) {
            return [ 'success' => false, 'error' => '記事が見つかりません。' ];
        }
        if ( empty( $article['outline'] ) ) {
            return [ 'success' => false, 'error' => '先に構成案を生成してください。' ];
        }

        $missing = $article['outline']['missing_info'] ?? [];
        $keyword = $article['keyword'];

        $this->log( "generate_interview: article_id={$article_id}, missing=" . count( $missing ) );

        $prompt  = "あなたはSEO記事作成のヒアリング担当者です。\n";
        $prompt .= "対策キーワード「{$keyword}」の記事を作成するにあたり、以下の不足情報を補うための質問を作成してください。\n\n";
        $prompt .= "## 不足している情報\n";
        foreach ( $missing as $m ) {
            $prompt .= "- {$m}\n";
        }
        $prompt .= "\n## 出力指示\n以下のJSON配列のみを出力してください。\n";
        $prompt .= '[{"question": "質問文", "hint": "回答のヒント（50文字以内）", "field_type": "text"}]' . "\n";
        $prompt .= "- 質問は3〜7個程度\n";
        $prompt .= "- field_type は text（短文）または textarea（長文）\n";
        $prompt .= "- 実用的で具体的な質問にしてください\n";

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.5,
                'max_output_tokens' => 4096,
            ] );
        } catch ( \Throwable $e ) {
            $this->log( "Gemini interview error: " . $e->getMessage() );
            return [ 'success' => false, 'error' => 'ヒアリング質問の生成に失敗しました。' ];
        }

        $questions = $this->parse_json_array( $raw );
        if ( $questions === null ) {
            $this->log( "Interview parse error. Raw: " . substr( $raw, 0, 500 ) );
            return [ 'success' => false, 'error' => '質問の解析に失敗しました。' ];
        }

        // 保存
        $interview = [ 'questions' => $questions, 'answers' => [] ];
        update_post_meta( $article_id, '_gcrev_article_interview_json',
            wp_json_encode( $interview, JSON_UNESCAPED_UNICODE ) );

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        $this->log( "Interview saved: " . count( $questions ) . " questions" );
        return [ 'success' => true, 'interview' => $interview ];
    }

    /**
     * ヒアリング回答を保存
     */
    public function save_interview_answers( int $user_id, int $article_id, array $answers ): array {
        $owner = (int) get_post_meta( $article_id, '_gcrev_article_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }

        $interview_raw = get_post_meta( $article_id, '_gcrev_article_interview_json', true );
        $interview = $interview_raw ? json_decode( $interview_raw, true ) : null;
        if ( ! is_array( $interview ) || empty( $interview['questions'] ) ) {
            return [ 'success' => false, 'error' => 'ヒアリングデータが見つかりません。' ];
        }

        // 回答をサニタイズして保存
        $sanitized = [];
        foreach ( $answers as $idx => $ans ) {
            $sanitized[ $idx ] = sanitize_textarea_field( $ans );
        }
        $interview['answers'] = $sanitized;

        update_post_meta( $article_id, '_gcrev_article_interview_json',
            wp_json_encode( $interview, JSON_UNESCAPED_UNICODE ) );

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        return [ 'success' => true, 'interview' => $interview ];
    }

    // =========================================================
    // Phase 2: 本文生成
    // =========================================================

    /**
     * 本文たたき台を生成
     */
    public function generate_draft( int $user_id, int $article_id ): array {
        $article = $this->get_article( $user_id, $article_id );
        if ( ! $article ) {
            return [ 'success' => false, 'error' => '記事が見つかりません。' ];
        }
        if ( empty( $article['outline'] ) ) {
            return [ 'success' => false, 'error' => '先に構成案を生成してください。' ];
        }

        $this->log( "generate_draft: article_id={$article_id}" );

        $settings = function_exists( 'gcrev_get_client_settings' )
            ? gcrev_get_client_settings( $user_id ) : [];

        // 情報ストック
        $knowledge_text = '';
        $kid_ids = $article['selected_knowledge_ids'] ?? [];
        if ( ! empty( $kid_ids ) ) {
            $total = 0;
            foreach ( $kid_ids as $kid ) {
                $kp = get_post( absint( $kid ) );
                if ( ! $kp ) { continue; }
                $cat = get_post_meta( $kp->ID, '_gcrev_knowledge_category', true ) ?: '';
                $cat_label = self::KNOWLEDGE_CATEGORIES[ $cat ] ?? $cat;
                $content = get_post_meta( $kp->ID, '_gcrev_knowledge_content', true ) ?: '';
                if ( $total + mb_strlen( $content ) > 10000 ) {
                    $content = mb_substr( $content, 0, max( 0, 10000 - $total ) ) . '…';
                }
                $total += mb_strlen( $content );
                $knowledge_text .= "### {$kp->post_title}（{$cat_label}）\n{$content}\n\n";
                if ( $total >= 10000 ) { break; }
            }
        }

        // 記事個別メモ
        $notes_text = '';
        $notes = $article['notes'] ?? [];
        if ( ! empty( $notes ) ) {
            foreach ( $notes as $n ) {
                $notes_text .= "- {$n['text']}\n";
            }
        }

        // ヒアリング回答
        $interview_text = '';
        $interview = $article['interview'] ?? null;
        if ( $interview && ! empty( $interview['questions'] ) && ! empty( $interview['answers'] ) ) {
            foreach ( $interview['questions'] as $idx => $q ) {
                $ans = $interview['answers'][ $idx ] ?? '';
                if ( $ans !== '' ) {
                    $interview_text .= "Q: {$q['question']}\nA: {$ans}\n\n";
                }
            }
        }

        // プロンプト構築
        $prompt = $this->build_draft_prompt(
            $article['keyword'],
            $article['outline'],
            $article['type'],
            $article['purpose'],
            $article['tone'],
            $article['target_reader'],
            $settings,
            $knowledge_text,
            $notes_text,
            $interview_text
        );

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.7,
                'max_output_tokens' => 32768,
            ] );
            $this->log( "Draft response length: " . strlen( $raw ) );
        } catch ( \Throwable $e ) {
            $this->log( "Draft generation error: " . $e->getMessage() );
            return [ 'success' => false, 'error' => '本文生成中にエラーが発生しました: ' . $e->getMessage() ];
        }

        // マークダウンコードブロックを除去
        $content = preg_replace( '/^```(?:markdown|html)?\s*/m', '', $raw );
        $content = preg_replace( '/```\s*$/m', '', $content );
        $content = trim( $content );

        // 保存
        update_post_meta( $article_id, '_gcrev_article_draft_content', $content );
        update_post_meta( $article_id, '_gcrev_article_status', 'draft_generated' );
        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        $this->log( "Draft saved for article_id={$article_id}, length=" . mb_strlen( $content ) );
        return [ 'success' => true, 'draft_content' => $content ];
    }

    private function build_draft_prompt(
        string $keyword, array $outline, string $type, string $purpose,
        string $tone, string $target_reader, array $settings,
        string $knowledge_text, string $notes_text, string $interview_text
    ): string {
        $type_label    = self::ARTICLE_TYPES[ $type ] ?? '解説記事';
        $tone_label    = self::TONES[ $tone ] ?? '専門的に';
        $word_count    = $outline['target_word_count'] ?? 3000;

        $parts = [];

        $parts[] = "あなたは日本のローカルビジネス向けSEOコンテンツのライターです。\n"
            . "以下の構成案に従い、{$word_count}文字程度の記事本文をMarkdown形式で執筆してください。\n"
            . "一次情報（クライアント固有の情報）を最大限に活用し、事実に基づいた記事にしてください。\n"
            . "一次情報にないことを断定したり、存在しない実績・数値を捏造してはいけません。";

        // 条件
        $parts[] = "## 記事条件\n"
            . "- 対策キーワード: {$keyword}\n"
            . "- 記事タイプ: {$type_label}\n"
            . "- 文体: {$tone_label}\n"
            . ( $target_reader ? "- 想定読者: {$target_reader}\n" : '' )
            . "- 目標文字数: {$word_count}文字\n";

        // 構成案（見出し構造）
        $headings_text = "## 構成案（この見出し構造に従って書いてください）\n";
        foreach ( $outline['headings'] ?? [] as $h ) {
            $headings_text .= "### {$h['text']}\n";
            if ( ! empty( $h['description'] ) ) { $headings_text .= "  → {$h['description']}\n"; }
            foreach ( $h['children'] ?? [] as $c ) {
                $headings_text .= "#### {$c['text']}\n";
                if ( ! empty( $c['description'] ) ) { $headings_text .= "  → {$c['description']}\n"; }
            }
        }
        $parts[] = $headings_text;

        // 一次情報
        if ( $knowledge_text !== '' ) {
            $parts[] = "## 参照情報（クライアント固有の一次情報）\n{$knowledge_text}";
        }
        if ( $notes_text !== '' ) {
            $parts[] = "## 記事専用のメモ・補足情報\n{$notes_text}";
        }
        if ( $interview_text !== '' ) {
            $parts[] = "## ヒアリング回答（不足情報の補完）\n{$interview_text}";
        }

        // クライアント情報
        $site_url = $settings['site_url'] ?? '';
        if ( $site_url !== '' ) {
            $profile  = "## クライアント情報\n";
            $profile .= "- サイトURL: {$site_url}\n";
            $area_label = function_exists( 'gcrev_get_client_area_label' )
                ? gcrev_get_client_area_label( $settings ) : '';
            if ( $area_label ) $profile .= "- エリア: {$area_label}\n";
            if ( ! empty( $settings['industry'] ) ) $profile .= "- 業種: {$settings['industry']}\n";
            $parts[] = $profile;
        }

        // 執筆ルール
        $parts[] = <<<'RULES'
## 執筆ルール
- Markdown 形式で出力（## h2、### h3 を使用）
- 導入文は h2 なしで冒頭に配置
- 各見出しの本文は100〜300文字程度
- FAQは「Q: / A:」形式
- CTAセクションでは具体的な行動を促す
- 一次情報を引用した箇所はなるべく具体的に書く
- 推測で事実を補わない
- 前後の説明文やコードブロック記号は出力しないでください
RULES;

        return implode( "\n\n", $parts );
    }

    // =========================================================
    // Phase 2: WordPress 下書き保存
    // =========================================================

    /**
     * 生成した記事を WordPress 下書きとして保存
     */
    public function save_as_wp_draft( int $user_id, int $article_id ): array {
        $article = $this->get_article( $user_id, $article_id );
        if ( ! $article ) {
            return [ 'success' => false, 'error' => '記事が見つかりません。' ];
        }

        $content = $article['draft_content'] ?? '';
        if ( $content === '' ) {
            return [ 'success' => false, 'error' => '本文が生成されていません。先に本文を生成してください。' ];
        }

        $keyword = $article['keyword'];
        $outline = $article['outline'] ?? [];
        $title   = ! empty( $outline['title_options'] ) ? $outline['title_options'][0] : $keyword;

        // Markdown → HTML 簡易変換
        $html_content = $this->markdown_to_html( $content );

        // 既存の下書きがあれば更新
        $existing_draft_id = (int) ( $article['wp_draft_id'] ?? 0 );
        if ( $existing_draft_id > 0 && get_post( $existing_draft_id ) ) {
            wp_update_post( [
                'ID'           => $existing_draft_id,
                'post_title'   => $title,
                'post_content' => $html_content,
                'post_excerpt' => $outline['search_intent'] ?? '',
            ] );
            $draft_id = $existing_draft_id;
            $this->log( "WP draft updated: post_id={$draft_id}" );
        } else {
            $draft_id = wp_insert_post( [
                'post_type'    => 'post',
                'post_title'   => $title,
                'post_content' => $html_content,
                'post_status'  => 'draft',
                'post_author'  => $user_id,
                'post_excerpt' => $outline['search_intent'] ?? '',
            ] );

            if ( is_wp_error( $draft_id ) || $draft_id === 0 ) {
                return [ 'success' => false, 'error' => 'WordPress下書きの作成に失敗しました。' ];
            }
            $this->log( "WP draft created: post_id={$draft_id}" );
        }

        // カスタムフィールドに対策キーワードを保存
        update_post_meta( $draft_id, '_gcrev_target_keyword', $keyword );

        // 記事側に下書きIDを記録
        update_post_meta( $article_id, '_gcrev_article_wp_draft_id', $draft_id );
        update_post_meta( $article_id, '_gcrev_article_status', 'wp_draft_saved' );
        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        return [
            'success'  => true,
            'draft_id' => $draft_id,
            'edit_url' => admin_url( "post.php?post={$draft_id}&action=edit" ),
        ];
    }

    /**
     * Markdown → HTML 簡易変換
     */
    private function markdown_to_html( string $md ): string {
        $lines = explode( "\n", $md );
        $html = '';
        $in_list = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            // 見出し
            if ( preg_match( '/^#### (.+)/', $trimmed, $m ) ) {
                if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
                $html .= '<h4>' . esc_html( $m[1] ) . "</h4>\n";
            } elseif ( preg_match( '/^### (.+)/', $trimmed, $m ) ) {
                if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
                $html .= '<h3>' . esc_html( $m[1] ) . "</h3>\n";
            } elseif ( preg_match( '/^## (.+)/', $trimmed, $m ) ) {
                if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
                $html .= '<h2>' . esc_html( $m[1] ) . "</h2>\n";
            } elseif ( preg_match( '/^# (.+)/', $trimmed, $m ) ) {
                if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
                $html .= '<h1>' . esc_html( $m[1] ) . "</h1>\n";
            } elseif ( preg_match( '/^[-*] (.+)/', $trimmed, $m ) ) {
                if ( ! $in_list ) { $html .= "<ul>\n"; $in_list = true; }
                $html .= '<li>' . esc_html( $m[1] ) . "</li>\n";
            } elseif ( $trimmed === '' ) {
                if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
            } else {
                if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
                // 太字変換
                $escaped = esc_html( $trimmed );
                $escaped = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped );
                $html .= "<p>{$escaped}</p>\n";
            }
        }
        if ( $in_list ) { $html .= "</ul>\n"; }

        return $html;
    }

    // =========================================================
    // クライアント設定からデフォルト想定読者を生成
    // =========================================================

    private function build_default_reader( int $user_id ): string {
        if ( ! function_exists( 'gcrev_get_client_settings' ) ) { return ''; }
        $cs = gcrev_get_client_settings( $user_id );
        $parts = [];
        if ( ! empty( $cs['persona_one_liner'] ) ) {
            $parts[] = $cs['persona_one_liner'];
        } else {
            if ( ! empty( $cs['persona_age_ranges'] ) )  { $parts[] = implode( '・', $cs['persona_age_ranges'] ); }
            if ( ! empty( $cs['persona_genders'] ) )     { $parts[] = implode( '・', $cs['persona_genders'] ); }
            if ( ! empty( $cs['persona_attributes'] ) )  { $parts[] = implode( '、', $cs['persona_attributes'] ); }
        }
        $area_label = function_exists( 'gcrev_get_client_area_label' ) ? gcrev_get_client_area_label( $cs ) : '';
        if ( $area_label && ! empty( $cs['industry'] ) ) {
            $parts[] = "{$area_label}で{$cs['industry']}を探している方";
        }
        return implode( ' / ', array_filter( $parts ) );
    }

    // =========================================================
    // 記事設定の自動推定
    // =========================================================

    /**
     * キーワードから最適な記事タイプ・目的・文体をルールベースで推定
     */
    private function detect_article_settings( string $keyword, array $client_settings = [] ): array {
        $kw = mb_strtolower( $keyword );

        // デフォルト
        $type    = 'explanation';
        $purpose = 'traffic';
        $tone    = 'professional';

        // --- 記事タイプ推定 ---
        // 比較系
        if ( preg_match( '/比較|vs|おすすめ|ランキング|選び方|違い/', $kw ) ) {
            $type = 'comparison';
        }
        // FAQ系
        elseif ( preg_match( '/とは|って何|よくある質問|faq|知りたい/', $kw ) ) {
            $type = 'faq';
        }
        // 事例系
        elseif ( preg_match( '/事例|実績|導入|成功|体験|口コミ|レビュー/', $kw ) ) {
            $type = 'case_study';
        }
        // 地域系（都道府県名・市区町村名を含む）
        elseif ( preg_match( '/県|市|区|町|村|駅|エリア/', $kw ) ) {
            $type = 'local';
        }
        // クライアントのエリア名を含む場合も地域記事
        $area_pref   = $client_settings['area_pref'] ?? '';
        $area_city   = $client_settings['area_city'] ?? '';
        $area_custom = $client_settings['area_custom'] ?? '';
        if ( $area_pref && mb_strpos( $kw, mb_strtolower( $area_pref ) ) !== false ) {
            $type = 'local';
        }
        if ( $area_city && mb_strpos( $kw, mb_strtolower( $area_city ) ) !== false ) {
            $type = 'local';
        }

        // --- 目的推定 ---
        if ( preg_match( '/料金|費用|価格|見積|相場|安い/', $kw ) ) {
            $purpose = 'inquiry'; // 問い合わせ獲得
        } elseif ( $type === 'local' ) {
            $purpose = 'local_seo';
        } elseif ( $type === 'comparison' ) {
            $purpose = 'comparison';
        } elseif ( preg_match( '/会社名|サービス名|ブランド/', $kw ) ) {
            $purpose = 'brand';
        }
        // 業種名 + エリア → 問い合わせ獲得
        $industry = $client_settings['industry'] ?? '';
        if ( $industry && mb_strpos( $kw, mb_strtolower( $industry ) ) !== false && $type === 'local' ) {
            $purpose = 'inquiry';
        }

        // --- 文体推定 ---
        if ( $type === 'faq' || preg_match( '/初心者|やさしい|わかりやすい|簡単/', $kw ) ) {
            $tone = 'friendly';
        } elseif ( $type === 'case_study' ) {
            $tone = 'trustworthy';
        } elseif ( $type === 'local' ) {
            $tone = 'casual';
        }

        return [
            'type'    => $type,
            'purpose' => $purpose,
            'tone'    => $tone,
        ];
    }

    // =========================================================
    // JSON 配列パースヘルパー
    // =========================================================

    private function parse_json_array( string $raw ): ?array {
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

        $first = strpos( $cleaned, '[' );
        $last  = strrpos( $cleaned, ']' );
        if ( $first === false || $last === false || $last <= $first ) {
            return null;
        }

        $json_str = substr( $cleaned, $first, $last - $first + 1 );
        $data = json_decode( $json_str, true );
        return is_array( $data ) ? $data : null;
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
