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
        'natural'      => '自然で読みやすい',
        'friendly'     => 'やさしく丁寧',
        'trustworthy'  => '信頼感重視',
        'professional' => '専門的に',
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

    /**
     * 情報ストックにファイルを添付
     */
    public function attach_file_to_knowledge( int $user_id, int $knowledge_id, array $file ): array {
        $owner = (int) get_post_meta( $knowledge_id, '_gcrev_knowledge_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }

        if ( empty( $file['name'] ) || empty( $file['tmp_name'] ) ) {
            return [ 'success' => false, 'error' => 'ファイルが選択されていません。' ];
        }

        // 許可するファイルタイプ
        $allowed = [
            'application/pdf',
            'text/plain', 'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        ];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
        if ( isset( $upload['error'] ) ) {
            return [ 'success' => false, 'error' => 'アップロード失敗: ' . $upload['error'] ];
        }

        // MIMEタイプチェック
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $upload['file'] );
        finfo_close( $finfo );
        if ( ! in_array( $mime, $allowed, true ) ) {
            @unlink( $upload['file'] );
            return [ 'success' => false, 'error' => 'このファイル形式はサポートされていません。' ];
        }

        // WordPress attachment 作成
        $attachment_id = wp_insert_attachment( [
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( $file['name'] ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $knowledge_id,
        ], $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            return [ 'success' => false, 'error' => '添付ファイルの保存に失敗しました。' ];
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // ファイルからテキストを自動抽出
        $extracted_text = $this->extract_text_from_file( $upload['file'], $mime );
        if ( $extracted_text !== '' ) {
            update_post_meta( $attachment_id, '_gcrev_extracted_text', $extracted_text );
            $this->log( "Text extracted from file: " . mb_strlen( $extracted_text ) . " chars, attachment_id={$attachment_id}" );
        }

        // 情報ストックの添付ファイルリストに追加
        $attachments_raw = get_post_meta( $knowledge_id, '_gcrev_knowledge_attachments', true );
        $attachments = is_array( $attachments_raw ) ? $attachments_raw
            : ( json_decode( $attachments_raw ?: '[]', true ) ?: [] );
        $attachments[] = $attachment_id;
        update_post_meta( $knowledge_id, '_gcrev_knowledge_attachments',
            wp_json_encode( $attachments, JSON_UNESCAPED_UNICODE ) );

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $knowledge_id, '_gcrev_knowledge_updated_at', $now );

        $file_info = $this->format_attachment( $attachment_id );
        if ( $extracted_text !== '' ) {
            $file_info['has_text'] = true;
            $file_info['text_length'] = mb_strlen( $extracted_text );
        }

        return [
            'success' => true,
            'file'    => $file_info,
        ];
    }

    /**
     * 情報ストックの添付ファイルを削除
     */
    public function detach_file_from_knowledge( int $user_id, int $knowledge_id, int $attachment_id ): array {
        $owner = (int) get_post_meta( $knowledge_id, '_gcrev_knowledge_user_id', true );
        if ( $owner !== $user_id ) {
            return [ 'success' => false, 'error' => '権限がありません。' ];
        }

        $attachments_raw = get_post_meta( $knowledge_id, '_gcrev_knowledge_attachments', true );
        $attachments = is_array( $attachments_raw ) ? $attachments_raw
            : ( json_decode( $attachments_raw ?: '[]', true ) ?: [] );

        $attachments = array_values( array_filter( $attachments, function( $id ) use ( $attachment_id ) {
            return (int) $id !== $attachment_id;
        } ) );

        update_post_meta( $knowledge_id, '_gcrev_knowledge_attachments',
            wp_json_encode( $attachments, JSON_UNESCAPED_UNICODE ) );

        wp_delete_attachment( $attachment_id, true );

        return [ 'success' => true ];
    }

    private function format_attachment( int $attachment_id ): ?array {
        $post = get_post( $attachment_id );
        if ( ! $post ) { return null; }
        $extracted = get_post_meta( $attachment_id, '_gcrev_extracted_text', true );
        return [
            'id'          => $attachment_id,
            'name'        => $post->post_title,
            'url'         => wp_get_attachment_url( $attachment_id ),
            'mime'        => $post->post_mime_type,
            'size'        => filesize( get_attached_file( $attachment_id ) ) ?: 0,
            'has_text'    => ( $extracted !== '' && $extracted !== false ),
            'text_length' => $extracted ? mb_strlen( $extracted ) : 0,
        ];
    }

    /**
     * ファイルからテキストを抽出
     */
    private function extract_text_from_file( string $file_path, string $mime ): string {
        $text = '';

        try {
            switch ( $mime ) {
                case 'application/pdf':
                    $text = $this->extract_pdf_text( $file_path );
                    break;

                case 'text/plain':
                case 'text/csv':
                    $text = file_get_contents( $file_path );
                    if ( $text === false ) { $text = ''; }
                    // UTF-8 でない場合は変換
                    if ( $text !== '' && ! mb_check_encoding( $text, 'UTF-8' ) ) {
                        $text = mb_convert_encoding( $text, 'UTF-8', 'SJIS-win,EUC-JP,ASCII' );
                    }
                    break;

                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    $text = $this->extract_docx_text( $file_path );
                    break;

                case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                    $text = $this->extract_xlsx_text( $file_path );
                    break;

                default:
                    // 画像、古い .doc, .xls 等はテキスト抽出対象外
                    break;
            }
        } catch ( \Throwable $e ) {
            $this->log( "Text extraction error ({$mime}): " . $e->getMessage() );
        }

        // 長すぎる場合は切り詰め（50000文字まで）
        if ( mb_strlen( $text ) > 50000 ) {
            $text = mb_substr( $text, 0, 50000 ) . "\n…（以下省略）";
        }

        return trim( $text );
    }

    /**
     * PDF からテキスト抽出（pdftotext コマンド使用）
     */
    private function extract_pdf_text( string $file_path ): string {
        $cmd = 'pdftotext -layout ' . escapeshellarg( $file_path ) . ' - 2>/dev/null';
        $output = shell_exec( $cmd );
        return is_string( $output ) ? $output : '';
    }

    /**
     * DOCX からテキスト抽出（ZIP + XML パース）
     */
    private function extract_docx_text( string $file_path ): string {
        if ( ! class_exists( 'ZipArchive' ) ) { return ''; }
        $zip = new \ZipArchive();
        if ( $zip->open( $file_path ) !== true ) { return ''; }

        $xml = $zip->getFromName( 'word/document.xml' );
        $zip->close();
        if ( $xml === false ) { return ''; }

        // XML タグを除去してテキストのみ取得
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadXML( $xml );
        libxml_clear_errors();

        $text = '';
        $paragraphs = $dom->getElementsByTagNameNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p' );
        foreach ( $paragraphs as $p ) {
            $line = '';
            $runs = $p->getElementsByTagNameNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't' );
            foreach ( $runs as $r ) {
                $line .= $r->textContent;
            }
            if ( $line !== '' ) {
                $text .= $line . "\n";
            }
        }

        return $text;
    }

    /**
     * XLSX からテキスト抽出（ZIP + shared strings）
     */
    private function extract_xlsx_text( string $file_path ): string {
        if ( ! class_exists( 'ZipArchive' ) ) { return ''; }
        $zip = new \ZipArchive();
        if ( $zip->open( $file_path ) !== true ) { return ''; }

        $xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        $zip->close();
        if ( $xml === false ) { return ''; }

        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadXML( $xml );
        libxml_clear_errors();

        $text = '';
        $items = $dom->getElementsByTagNameNS( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't' );
        foreach ( $items as $item ) {
            $val = trim( $item->textContent );
            if ( $val !== '' ) {
                $text .= $val . "\n";
            }
        }

        return $text;
    }

    /**
     * 記事に使用する情報ストックのアイテムを取得
     *
     * selected_knowledge_ids が空の場合は、全ての有効な情報ストックを自動使用。
     * 明示的に選択されている場合はその選択を尊重。
     */
    private function resolve_knowledge_items( int $user_id, array $article ): array {
        $kid_ids = $article['selected_knowledge_ids'] ?? [];

        // 選択が空の場合 → 全件自動使用
        if ( empty( $kid_ids ) ) {
            $all = $this->list_knowledge( $user_id );
            $kid_ids = array_column( $all, 'id' );
        }

        $items = [];
        foreach ( $kid_ids as $kid ) {
            $kp = get_post( absint( $kid ) );
            if ( ! $kp ) { continue; }
            $content = get_post_meta( $kp->ID, '_gcrev_knowledge_content', true ) ?: '';
            $file_texts = $this->get_knowledge_file_texts( $kp->ID );
            if ( $file_texts !== '' ) {
                $content .= "\n\n【添付資料の内容】\n" . $file_texts;
            }
            $items[] = [
                'title'    => $kp->post_title,
                'category' => get_post_meta( $kp->ID, '_gcrev_knowledge_category', true ) ?: '',
                'content'  => $content,
            ];
        }
        return $items;
    }

    /**
     * 情報ストックの添付ファイルからすべての抽出テキストを取得
     */
    public function get_knowledge_file_texts( int $knowledge_id ): string {
        $att_raw = get_post_meta( $knowledge_id, '_gcrev_knowledge_attachments', true );
        $att_ids = is_array( $att_raw ) ? $att_raw : ( json_decode( $att_raw ?: '[]', true ) ?: [] );

        $texts = [];
        foreach ( $att_ids as $aid ) {
            $extracted = get_post_meta( (int) $aid, '_gcrev_extracted_text', true );
            if ( $extracted ) {
                $name = get_the_title( (int) $aid );
                $texts[] = "--- {$name} ---\n{$extracted}";
            }
        }
        return implode( "\n\n", $texts );
    }

    private function format_knowledge( \WP_Post $post ): array {
        $tags_raw = get_post_meta( $post->ID, '_gcrev_knowledge_tags', true );
        $tags = is_array( $tags_raw ) ? $tags_raw : ( json_decode( $tags_raw ?: '[]', true ) ?: [] );

        // 添付ファイル
        $att_raw = get_post_meta( $post->ID, '_gcrev_knowledge_attachments', true );
        $att_ids = is_array( $att_raw ) ? $att_raw : ( json_decode( $att_raw ?: '[]', true ) ?: [] );
        $files = [];
        foreach ( $att_ids as $aid ) {
            $f = $this->format_attachment( (int) $aid );
            if ( $f ) { $files[] = $f; }
        }

        return [
            'id'         => $post->ID,
            'title'      => $post->post_title,
            'category'   => get_post_meta( $post->ID, '_gcrev_knowledge_category', true ) ?: 'notes',
            'content'    => get_post_meta( $post->ID, '_gcrev_knowledge_content', true ) ?: '',
            'tags'       => $tags,
            'files'      => $files,
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

        // 類似チェック（ブロックはしない）
        $similarity_result = null;
        try {
            $similarity_result = $this->check_similarity( $user_id, $keyword );
            if ( $similarity_result && isset( $similarity_result['risk_level'] ) ) {
                update_post_meta( $post_id, '_gcrev_article_similarity_result',
                    wp_json_encode( $similarity_result, JSON_UNESCAPED_UNICODE ) );
            }
        } catch ( \Throwable $e ) {
            $this->log( "create_article: similarity check failed (non-blocking): " . $e->getMessage() );
        }

        // 構成案を自動生成（デフォルト設定ベース）
        $this->log( "create_article: auto-generating outline for article_id={$post_id}" );
        $outline_result = $this->generate_outline( $user_id, $post_id );
        if ( ! $outline_result['success'] ) {
            $this->log( "create_article: outline auto-generation failed: " . ( $outline_result['error'] ?? '' ) );
            // 構成案生成失敗でも記事自体は返す
        }

        $post = get_post( $post_id );
        $result = [ 'success' => true, 'article' => $this->format_article_detail( $post ) ];
        if ( $similarity_result ) {
            $result['similarity'] = $similarity_result;
        }
        return $result;
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
        $keyword     = get_post_meta( $post->ID, '_gcrev_article_keyword', true ) ?: '';
        $outline_raw = get_post_meta( $post->ID, '_gcrev_article_outline_json', true );
        $outline     = $outline_raw ? json_decode( $outline_raw, true ) : null;

        // タイトル: outline の title_options[0] があればそれ、なければ keyword
        $title = $keyword;
        if ( ! empty( $outline['title_options'][0] ) ) {
            $title = $outline['title_options'][0];
        }

        return [
            'id'           => $post->ID,
            'title'        => $title,
            'keyword'      => $keyword,
            'type'         => get_post_meta( $post->ID, '_gcrev_article_type', true ) ?: 'explanation',
            'purpose'      => get_post_meta( $post->ID, '_gcrev_article_purpose', true ) ?: 'traffic',
            'status'       => get_post_meta( $post->ID, '_gcrev_article_status', true ) ?: 'keyword_set',
            'has_outline'  => (bool) $outline_raw,
            'has_draft'    => (bool) get_post_meta( $post->ID, '_gcrev_article_draft_content', true ),
            'wp_draft_id'  => (int) get_post_meta( $post->ID, '_gcrev_article_wp_draft_id', true ),
            'similarity_risk' => $this->get_similarity_risk_level( $post->ID ),
            'created_at'   => get_post_meta( $post->ID, '_gcrev_article_created_at', true ) ?: '',
            'updated_at'   => get_post_meta( $post->ID, '_gcrev_article_updated_at', true ) ?: '',
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
            'tone'                   => get_post_meta( $post->ID, '_gcrev_article_tone', true ) ?: 'natural',
            'target_reader'          => $target_reader,
            'selected_knowledge_ids' => $knowledge_ids,
            'outline'                => $outline,
            'notes'                  => is_array( $notes ) ? $notes : [],
            'interview'              => is_array( $interview ) ? $interview : null,
            'draft_content'          => get_post_meta( $post->ID, '_gcrev_article_draft_content', true ) ?: '',
            'wp_draft_id'            => (int) get_post_meta( $post->ID, '_gcrev_article_wp_draft_id', true ),
            'status'                 => get_post_meta( $post->ID, '_gcrev_article_status', true ) ?: 'keyword_set',
            'similarity_result'      => json_decode( get_post_meta( $post->ID, '_gcrev_article_similarity_result', true ) ?: 'null', true ),
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

        // 情報ストック（未選択なら全件自動使用）
        $knowledge_items = $this->resolve_knowledge_items( $user_id, $article );

        // 既存記事フィンガープリント取得（重複回避用）
        $existing_fps = $this->get_existing_articles_fingerprints( $user_id, $article_id );

        // プロンプト構築
        $prompt = $this->build_outline_prompt(
            $keyword,
            $article['type'],
            $article['purpose'],
            $article['tone'],
            $article['target_reader'],
            $settings,
            $knowledge_items,
            $existing_fps
        );

        // Gemini 呼び出し
        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.7,
                'maxOutputTokens' => 16384,
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

        // フィンガープリント保存（重複検知用）
        $this->save_article_fingerprint( $article_id, $keyword, $article['type'], $article['purpose'], $outline );

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
        array  $knowledge_items,
        array  $existing_fingerprints = []
    ): string {
        $parts = [];

        // 役割
        $type_label    = self::ARTICLE_TYPES[ $article_type ] ?? '解説記事';
        $purpose_label = self::ARTICLE_PURPOSES[ $purpose ] ?? 'アクセス獲得';
        $tone_label    = self::TONES[ $tone ] ?? '専門的に';

        $parts[] = "あなたは日本のローカルビジネス向けSEOコラム記事の構成案を作成する専門家です。\n"
            . "指定されたキーワードに対して、検索意図を分析し、**読み物として自然に読めるコラム構成**を作成してください。\n"
            . "FAQページや箇条書きQ&Aのような構成ではなく、「導入→読者の関心→解説→まとめ」の流れを重視してください。\n"
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
- FAQセクション（Q&A形式）は入れない。コラムとして自然な流れを優先する
- まとめの最後に軽いCTA（お問い合わせ誘導など）を含めてよいが、押し売り的にしない
- 見出しは「よくある質問」「Q:○○」のようなQ&A形式にせず、読み物として自然な見出しにする
- 読者が上から順に読み進めて理解が深まる構成にする
- 既存記事リストがある場合、それらと切り口・見出し構成が重複しないよう差別化する
- 同じキーワードの既存記事がある場合は、異なるアングル（対象読者を変える、特定の側面に焦点を当てるなど）で構成する
- 既存記事と同じ導入の流れ・結論を避ける
INSTRUCTION;

        // 既存記事コンテキスト（重複回避用）
        $existing_ctx = $this->build_existing_articles_context( $existing_fingerprints );
        if ( $existing_ctx !== '' ) {
            $parts[] = $existing_ctx;
        }

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

        // 構成案がなければ内部で自動生成（UIには表示しない）
        if ( empty( $article['outline'] ) ) {
            $this->log( "generate_interview: no outline, auto-generating for article_id={$article_id}" );
            $outline_result = $this->generate_outline( $user_id, $article_id );
            if ( ! $outline_result['success'] ) {
                return [ 'success' => false, 'error' => 'ヒアリング質問生成の準備に失敗しました: ' . ( $outline_result['error'] ?? '' ) ];
            }
            $article = $this->get_article( $user_id, $article_id ); // 再取得
        }

        $missing = $article['outline']['missing_info'] ?? [];
        $keyword = $article['keyword'];

        // 情報ストックの内容（添付ファイルのテキスト含む）を取得
        $knowledge_items = $this->resolve_knowledge_items( $user_id, $article );
        $knowledge_text  = '';
        $total           = 0;
        foreach ( $knowledge_items as $ki ) {
            $cat_label = self::KNOWLEDGE_CATEGORIES[ $ki['category'] ] ?? $ki['category'];
            $content   = $ki['content'];
            if ( $total + mb_strlen( $content ) > 10000 ) {
                $content = mb_substr( $content, 0, max( 0, 10000 - $total ) ) . '…（以下省略）';
            }
            $total += mb_strlen( $content );
            $knowledge_text .= "### {$ki['title']}（{$cat_label}）\n{$content}\n\n";
            if ( $total >= 10000 ) { break; }
        }

        $this->log( "generate_interview: article_id={$article_id}, missing=" . count( $missing ) . ", knowledge_chars={$total}" );

        $prompt  = "あなたはSEOコラム記事作成のヒアリング担当者です。\n";
        $prompt .= "対策キーワード「{$keyword}」のコラム記事を作成するにあたり、";
        $prompt .= "記事の質を高めるために追加で聞いておくべき質問を作成してください。\n\n";
        if ( ! empty( $missing ) ) {
            $prompt .= "## 構成案で不足と判定された情報\n";
            foreach ( $missing as $m ) {
                $prompt .= "- {$m}\n";
            }
            $prompt .= "\n";
        }
        if ( $knowledge_text !== '' ) {
            $prompt .= "## 現在登録されている参照情報（情報ストック・添付ファイルの内容）\n";
            $prompt .= "以下は既にクライアントから提供されている情報です。この内容を十分に把握した上で、**ここに書かれていない情報のみ**を質問してください。\n\n";
            $prompt .= $knowledge_text . "\n";
        }
        $prompt .= "## 出力指示\n以下のJSON配列のみを出力してください。\n";
        $prompt .= '[{"question": "質問文", "hint": "回答のヒント（50文字以内）", "field_type": "text"}]' . "\n";
        $prompt .= "- 質問は3〜7個程度\n";
        $prompt .= "- field_type は text（短文）または textarea（長文）\n";
        $prompt .= "- 記事に一次情報（実体験・具体的な数字・独自の強み等）を盛り込むための実用的な質問にしてください\n";
        $prompt .= "- 上記の参照情報に既に含まれている内容（料金、プラン、サービス内容等）については質問しないでください\n";
        $prompt .= "- 参照情報にない独自の強み・実績・顧客の声・差別化ポイントなど、記事に深みを出す情報を引き出す質問にしてください\n";

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.5,
                'maxOutputTokens' => 4096,
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

        // 保存（既存の回答があればキーワードマッチで引き継ぐ）
        $old_interview_json = get_post_meta( $article_id, '_gcrev_article_interview_json', true );
        $old_interview      = $old_interview_json ? json_decode( $old_interview_json, true ) : [];
        $old_questions      = $old_interview['questions'] ?? [];
        $old_answers        = $old_interview['answers'] ?? [];
        $new_answers        = [];
        if ( ! empty( $old_answers ) && ! empty( $old_questions ) ) {
            // 旧質問テキスト → 回答のマップを作成
            $qa_map = [];
            foreach ( $old_questions as $idx => $oq ) {
                if ( isset( $old_answers[ $idx ] ) && $old_answers[ $idx ] !== '' ) {
                    $qa_map[ $oq['question'] ?? '' ] = $old_answers[ $idx ];
                }
            }
            // 新しい質問に対して、類似の旧質問の回答を引き継ぐ
            foreach ( $questions as $idx => $nq ) {
                $nq_text = $nq['question'] ?? '';
                // 完全一致を優先
                if ( isset( $qa_map[ $nq_text ] ) ) {
                    $new_answers[ $idx ] = $qa_map[ $nq_text ];
                }
            }
        }
        $interview = [ 'questions' => $questions, 'answers' => $new_answers ];
        update_post_meta( $article_id, '_gcrev_article_interview_json',
            wp_json_encode( $interview, JSON_UNESCAPED_UNICODE ) );

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        $this->log( "Interview saved: " . count( $questions ) . " questions" );
        return [ 'success' => true, 'interview' => $interview ];
    }

    /**
     * 構成案 → ヒアリング → 本文をワンクリックで全再生成
     */
    public function regenerate_all( int $user_id, int $article_id ): array {
        $this->log( "regenerate_all: start article_id={$article_id}" );

        // 1. 構成案を再生成
        $outline_result = $this->generate_outline( $user_id, $article_id );
        if ( ! $outline_result['success'] ) {
            return [ 'success' => false, 'error' => '構成案の再生成に失敗しました: ' . ( $outline_result['error'] ?? '' ), 'step' => 'outline' ];
        }

        // 2. ヒアリング質問を再生成
        $interview_result = $this->generate_interview( $user_id, $article_id );
        if ( ! $interview_result['success'] ) {
            $this->log( "regenerate_all: interview failed, continuing to draft" );
            // ヒアリング失敗は致命的ではないので続行
        }

        // 3. 本文を再生成
        $draft_result = $this->generate_draft( $user_id, $article_id );
        if ( ! $draft_result['success'] ) {
            return [ 'success' => false, 'error' => '本文の再生成に失敗しました: ' . ( $draft_result['error'] ?? '' ), 'step' => 'draft' ];
        }

        // 最新の記事データを返す
        $article = $this->get_article( $user_id, $article_id );
        $this->log( "regenerate_all: complete article_id={$article_id}" );

        return [
            'success' => true,
            'article' => $article,
        ];
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
    public function generate_draft( int $user_id, int $article_id, string $additional_prompt = '' ): array {
        $article = $this->get_article( $user_id, $article_id );
        if ( ! $article ) {
            return [ 'success' => false, 'error' => '記事が見つかりません。' ];
        }
        // 構成案を常に（再）生成
        $this->log( "generate_draft: generating outline for article_id={$article_id}" );
        $outline_result = $this->generate_outline( $user_id, $article_id );
        if ( ! $outline_result['success'] ) {
            return [ 'success' => false, 'error' => '記事構成の準備に失敗しました: ' . ( $outline_result['error'] ?? '' ) ];
        }
        $article = $this->get_article( $user_id, $article_id );

        $this->log( "generate_draft: article_id={$article_id}" );

        $settings = function_exists( 'gcrev_get_client_settings' )
            ? gcrev_get_client_settings( $user_id ) : [];

        // 情報ストック（未選択なら全件自動使用）
        $knowledge_items = $this->resolve_knowledge_items( $user_id, $article );
        $knowledge_text = '';
        $total = 0;
        foreach ( $knowledge_items as $ki ) {
            $cat_label = self::KNOWLEDGE_CATEGORIES[ $ki['category'] ] ?? $ki['category'];
            $content = $ki['content'];
            if ( $total + mb_strlen( $content ) > 15000 ) {
                $content = mb_substr( $content, 0, max( 0, 15000 - $total ) ) . '…（以下省略）';
            }
            $total += mb_strlen( $content );
            $knowledge_text .= "### {$ki['title']}（{$cat_label}）\n{$content}\n\n";
            if ( $total >= 15000 ) { break; }
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

        // 既存記事フィンガープリント取得（重複回避用）
        $existing_fps = $this->get_existing_articles_fingerprints( $user_id, $article_id );

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
            $interview_text,
            $existing_fps
        );

        // 追加編集プロンプトがあればドラフトプロンプトに追記
        if ( $additional_prompt !== '' ) {
            $prompt .= "\n\n## ユーザーからの追加指示（必ず反映すること）\n{$additional_prompt}\n";
            $this->log( "generate_draft: additional_prompt applied, len=" . mb_strlen( $additional_prompt ) );
        }

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.7,
                'maxOutputTokens'   => 32768,
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
        return [ 'success' => true, 'draft_content' => $content, 'outline' => $article['outline'] ];
    }

    /**
     * 追加プロンプトによる本文リファイン
     */
    public function refine_draft( int $user_id, int $article_id, string $current_content, string $additional_prompt ): array {
        $article = $this->get_article( $user_id, $article_id );
        if ( ! $article ) {
            return [ 'success' => false, 'error' => '記事が見つかりません。' ];
        }
        if ( $current_content === '' ) {
            return [ 'success' => false, 'error' => '本文がありません。先に本文を生成してください。' ];
        }
        if ( $additional_prompt === '' ) {
            return [ 'success' => false, 'error' => '編集指示を入力してください。' ];
        }

        $this->log( "refine_draft: article_id={$article_id}, prompt_len=" . mb_strlen( $additional_prompt ) );

        $prompt = "あなたは日本のローカルビジネス向けコラム記事のライターです。\n"
            . "以下の既存の記事本文に対して、ユーザーの編集指示に従い修正・改善してください。\n\n"
            . "## 編集指示\n{$additional_prompt}\n\n"
            . "## 現在の記事本文\n{$current_content}\n\n"
            . "## ルール\n"
            . "- 編集指示に該当する部分のみ修正し、それ以外はできるだけ維持してください\n"
            . "- Markdown形式で出力してください（# タイトル、## 大見出し、### 小見出し）\n"
            . "- 説明や補足は不要です。修正後の記事本文のみを出力してください\n\n"
            . "## 文体ガイドライン（修正時も守ること）\n"
            . "- 自然で読みやすく、人が書いたような文章にする\n"
            . "- 自画自賛・営業色が強い表現を避ける\n"
            . "- 禁止表現: 期待を超える / 圧倒的 / 可能性を広げる / ビジネスを加速 / 新たな価値を創造 / 魅力を最大限に / 強力な武器 / 欠かせない存在\n"
            . "- AIっぽい語尾を避ける: 〜と言えるでしょう / 〜ではないでしょうか / 〜していきます / 〜してまいります / 〜を実現します / 〜が可能です\n"
            . "- 抽象語より具体語を優先し、読者目線で書く\n"
            . "- 落ち着いたトーンを維持し、テンションを上げすぎない\n";

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.5,
                'maxOutputTokens'   => 32768,
            ] );
        } catch ( \Throwable $e ) {
            $this->log( "Refine draft error: " . $e->getMessage() );
            return [ 'success' => false, 'error' => '本文修正中にエラーが発生しました: ' . $e->getMessage() ];
        }

        $content = preg_replace( '/^```(?:markdown|html)?\s*/m', '', $raw );
        $content = preg_replace( '/```\s*$/m', '', $content );
        $content = trim( $content );

        update_post_meta( $article_id, '_gcrev_article_draft_content', $content );
        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        update_post_meta( $article_id, '_gcrev_article_updated_at', $now );

        $this->log( "Refined draft saved for article_id={$article_id}, length=" . mb_strlen( $content ) );
        return [ 'success' => true, 'draft_content' => $content ];
    }

    private function build_draft_prompt(
        string $keyword, array $outline, string $type, string $purpose,
        string $tone, string $target_reader, array $settings,
        string $knowledge_text, string $notes_text, string $interview_text,
        array $existing_fingerprints = []
    ): string {
        $type_label    = self::ARTICLE_TYPES[ $type ] ?? '解説記事';
        $tone_label    = self::TONES[ $tone ] ?? '専門的に';
        $word_count    = $outline['target_word_count'] ?? 3000;

        $parts = [];

        $parts[] = "あなたは日本のローカルビジネス向けコラム記事のライターです。\n"
            . "以下の構成案に従い、{$word_count}文字程度のコラム記事本文をMarkdown形式で執筆してください。\n\n"
            . "### 最優先ルール（すべてに優先する）\n"
            . "1. 自然に読めること\n"
            . "2. 読者が不快にならないこと\n"
            . "3. 自画自賛に見えないこと\n"
            . "4. 内容が具体的であること\n"
            . "5. 人が書いたように見えること\n\n"
            . "### 文章トーン\n"
            . "- 親しみやすく、丁寧で、落ち着いたトーンで書く\n"
            . "- 押しつけがましくなく、わざとらしくなく、自然体で誠実に書く\n"
            . "- 営業色が強すぎる・熱量が高すぎる・自社を持ち上げすぎる文章にしない\n"
            . "- 感動を誘うような大げさな表現、不自然に整いすぎたAIっぽい言い回しを使わない\n\n"
            . "### 禁止表現（以下は原則使用禁止）\n"
            . "**自画自賛系:** 期待を超える / 圧倒的 / 強力なパートナー / 最大限に引き出す / 未来を切り拓く / 理想を形にする / 目覚ましい成果 / 驚きの結果 / 選ばれ続ける理由 / 唯一無二 / 圧倒的な強み\n"
            . "**抽象・大げさ系:** 可能性を広げる / ビジネスを加速させる / 新たな価値を創造する / 成長を後押しする / 未来につながる / 魅力を最大限に伝える / 強力な武器になる / 欠かせない存在 / 新たな出会いを創出する\n"
            . "**AIっぽい語尾:** 〜と言えるでしょう / 〜ではないでしょうか / 〜していきます / 〜してまいります / 〜を実現します / 〜が可能です / 〜に貢献します / 〜をサポートします / 〜をお手伝いします\n\n"
            . "### 文章スタイル指針\n"
            . "- **人が自然に書いたような、少しゆらぎのある読みやすい文章**を書く\n"
            . "- 「売り込み文章」ではなく「参考になる読み物」として成立させる\n"
            . "- 抽象語より具体語を優先する（「ビジネスの可能性を広げる」ではなく「問い合わせにつながりやすい導線を整理する」のように）\n"
            . "- 読者目線で書く — 「自社が何をしたいか」より「読者にとって何が助かるか」を優先\n"
            . "- 成果は控えめに、事実ベースで淡々と書く。数字を盛らない\n"
            . "- 文の長さに変化をつけ、同じ語尾・同じ構文を続けない\n"
            . "- 専門用語を多用せず、初めて読む人でもスムーズに読める表現を優先\n"
            . "- 漢字が多すぎる文は避け、ひらがなとのバランスを取る\n"
            . "- きれいすぎる定型文の連続を避け、少しやわらかい言い回しを混ぜて人間らしい流れにする\n"
            . "- 読者が「読んでよかった」と思えるノウハウ・考え方・判断ポイントを含める\n"
            . "- 一次情報（クライアント固有の情報）を最大限に活用し、事実に基づいた記事にする\n"
            . "- 一次情報にないことを断定したり、存在しない実績・数値を捏造してはいけない\n"
            . "- 迷ったら「少し地味なくらいがちょうどよい」と考える";

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

        // 既存記事コンテキスト（重複回避用）
        $existing_ctx = $this->build_existing_articles_context( $existing_fingerprints );
        if ( $existing_ctx !== '' ) {
            $parts[] = $existing_ctx;
        }

        // 執筆ルール
        $parts[] = <<<'RULES'
## 執筆ルール（必ず守ってください）

### フォーマット
- Markdown 形式で出力
- 記事タイトルは必ず最初の行に「# タイトル」（h1）として出力する
- 大見出しは「## 見出し」（h2）、小見出しは「### 見出し」（h3）を使用
- h1 は記事タイトルの1回のみ。本文中の見出しは h2 以下を使う
- 導入文は h1 の直後、最初の h2 の前に配置
- 前後の説明文やコードブロック記号は出力しないでください

### コラム記事としての文体
- FAQ（Q&A形式）は使わない。読み物として自然な文章で書く
- 各見出しの本文は100〜300文字程度。短すぎず、詰め込みすぎず
- 文の長さに変化をつける。長い文と短い文を混ぜて単調にしない
- 適度にやわらかい言い回しを入れ、全文が断定調にならないようにする
- 同じ意味の言い換えを繰り返さない
- 「上手い文章」より「嫌味のない文章」を目指す
- 読者が「自分ごと」として読めるよう、具体的な場面や気持ちに触れる
- 読者が自分で良さを判断できる余白を残す。説得しすぎない
- 事例を入れる場合は、持ち上げすぎず簡潔に書く

### 構成上の注意
- 導入で話を広げすぎない。早い段階で本題に入る
- 見出しごとに1テーマに絞る
- まとめでは過剰に感動的に締めない。落ち着いたまま終える
- テンションを上げすぎない。全体を通して誠実で落ち着いたトーンを維持
- まとめの末尾に軽いCTA（お問い合わせ・相談の誘導）を自然に入れてよいが、押し売り的にしない

### 内容の信頼性
- 一次情報を引用した箇所はなるべく具体的に書く
- 推測で事実を補わない。存在しない実績や数値を捏造しない
- 自社の強みを書く場合も、控えめで誠実に表現する
- 事実より印象操作が強くならないようにする
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
        $tone    = 'natural';

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
    // 重複検知: フィンガープリント & 類似チェック
    // =========================================================

    /**
     * 構成案データからフィンガープリントを抽出・保存（API呼び出しなし）
     */
    private function save_article_fingerprint( int $article_id, string $keyword, string $type, string $purpose, array $outline ): void {
        $h2_topics = [];
        foreach ( $outline['headings'] ?? [] as $h ) {
            if ( count( $h2_topics ) >= 6 ) break;
            $h2_topics[] = $h['text'] ?? '';
        }

        $fingerprint = [
            'keyword'     => $keyword,
            'type'        => $type,
            'purpose'     => $purpose,
            'summary'     => $outline['search_intent'] ?? '',
            'angle'       => $outline['target_reader_detail'] ?? '',
            'main_topics' => $h2_topics,
            'title'       => $outline['title_options'][0] ?? $keyword,
        ];

        update_post_meta( $article_id, '_gcrev_article_fingerprint',
            wp_json_encode( $fingerprint, JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * ユーザーの全記事フィンガープリントを取得
     */
    private function get_existing_articles_fingerprints( int $user_id, int $exclude_id = 0 ): array {
        $query = new \WP_Query( [
            'post_type'      => 'gcrev_article',
            'posts_per_page' => 100,
            'meta_key'       => '_gcrev_article_user_id',
            'meta_value'     => $user_id,
            'meta_type'      => 'NUMERIC',
            'no_found_rows'  => true,
        ] );

        $fingerprints = [];
        foreach ( $query->posts as $post ) {
            if ( $post->ID === $exclude_id ) continue;

            $fp_raw = get_post_meta( $post->ID, '_gcrev_article_fingerprint', true );
            if ( $fp_raw ) {
                $fp = json_decode( $fp_raw, true );
                if ( is_array( $fp ) ) {
                    $fp['id'] = $post->ID;
                    $fingerprints[] = $fp;
                    continue;
                }
            }
            // fallback: フィンガープリント未生成の既存記事
            $kw = get_post_meta( $post->ID, '_gcrev_article_keyword', true ) ?: '';
            if ( $kw === '' ) continue;

            $outline_raw = get_post_meta( $post->ID, '_gcrev_article_outline_json', true );
            $outline = $outline_raw ? json_decode( $outline_raw, true ) : null;

            $title = $kw;
            $summary = '';
            $topics = [];
            if ( is_array( $outline ) ) {
                $title   = $outline['title_options'][0] ?? $kw;
                $summary = $outline['search_intent'] ?? '';
                foreach ( $outline['headings'] ?? [] as $h ) {
                    if ( count( $topics ) >= 6 ) break;
                    $topics[] = $h['text'] ?? '';
                }
            }

            $fingerprints[] = [
                'id'          => $post->ID,
                'keyword'     => $kw,
                'type'        => get_post_meta( $post->ID, '_gcrev_article_type', true ) ?: '',
                'purpose'     => get_post_meta( $post->ID, '_gcrev_article_purpose', true ) ?: '',
                'summary'     => $summary,
                'angle'       => '',
                'main_topics' => $topics,
                'title'       => $title,
            ];
        }
        return $fingerprints;
    }

    /**
     * 既存記事リストをプロンプト用テキストに変換
     */
    private function build_existing_articles_context( array $fingerprints ): string {
        if ( empty( $fingerprints ) ) return '';

        $section  = "## 既存記事リスト（重複を避けてください）\n";
        $section .= "以下は同じサイトで既に作成済みの記事です。これらと切り口・構成・結論が重複しないように、差別化された記事を作成してください。\n\n";

        foreach ( $fingerprints as $idx => $fp ) {
            $num = $idx + 1;
            $section .= "記事{$num}: 「{$fp['keyword']}」— {$fp['title']}\n";
            if ( ! empty( $fp['summary'] ) ) {
                $section .= "  テーマ: {$fp['summary']}\n";
            }
            if ( ! empty( $fp['main_topics'] ) ) {
                $section .= "  トピック: " . implode( '、', $fp['main_topics'] ) . "\n";
            }
            $section .= "\n";
        }

        return $section;
    }

    /**
     * キーワードの類似度をチェック（Gemini API使用）
     */
    public function check_similarity( int $user_id, string $keyword ): array {
        $fingerprints = $this->get_existing_articles_fingerprints( $user_id );

        // 既存記事が0件
        if ( empty( $fingerprints ) ) {
            return [ 'risk_level' => 'none', 'similar_articles' => [], 'overall_suggestion' => '', 'suggested_angles' => [] ];
        }

        // 完全一致キーワードがある場合は即座に high 判定（API不要）
        $exact_matches = [];
        foreach ( $fingerprints as $fp ) {
            if ( mb_strtolower( trim( $fp['keyword'] ) ) === mb_strtolower( trim( $keyword ) ) ) {
                $exact_matches[] = [
                    'id'                          => $fp['id'],
                    'keyword'                     => $fp['keyword'],
                    'title'                       => $fp['title'] ?? '',
                    'similarity'                  => 'high',
                    'reason'                      => '同一キーワードの記事が既に存在します',
                    'differentiation_suggestion'  => '切り口・ターゲット読者・目的を変えれば別記事として成立します',
                ];
            }
        }
        if ( ! empty( $exact_matches ) ) {
            return [
                'risk_level'        => 'high',
                'similar_articles'  => $exact_matches,
                'overall_suggestion' => '同じキーワードの記事が既にあります。切り口を変えることで共存可能です。',
                'suggested_angles'  => [],
            ];
        }

        // Gemini で類似チェック
        $list_text = '';
        foreach ( $fingerprints as $idx => $fp ) {
            $num = $idx + 1;
            $list_text .= "記事{$num}（ID:{$fp['id']}）: キーワード「{$fp['keyword']}」\n";
            $list_text .= "  タイトル: {$fp['title']}\n";
            if ( ! empty( $fp['summary'] ) ) {
                $list_text .= "  テーマ: {$fp['summary']}\n";
            }
            if ( ! empty( $fp['main_topics'] ) ) {
                $list_text .= "  主要トピック: " . implode( '、', $fp['main_topics'] ) . "\n";
            }
            $list_text .= "\n";
        }

        $prompt = "あなたはSEO記事の重複分析の専門家です。\n\n"
            . "以下の「新しいキーワード」と「既存記事リスト」を比較し、テーマや切り口が重複・類似する記事がないか分析してください。\n\n"
            . "## 新しいキーワード\n{$keyword}\n\n"
            . "## 既存記事リスト\n{$list_text}\n"
            . "## 判定基準\n"
            . "- \"high\": 同じまたはほぼ同義のキーワードで、同じ切り口。読者にとって同じ記事に見える可能性が高い\n"
            . "- \"medium\": キーワードが関連しており、一部トピックが重複。切り口を明確にすれば差別化可能\n"
            . "- \"low\": 関連分野だがテーマ・切り口が異なる。共存可能\n"
            . "- \"none\": 関連性なし\n\n"
            . "## 出力形式（JSONのみ出力してください）\n"
            . "{\n"
            . "  \"risk_level\": \"high|medium|low|none\",\n"
            . "  \"similar_articles\": [\n"
            . "    {\n"
            . "      \"id\": 記事ID（数値）,\n"
            . "      \"keyword\": \"既存記事のキーワード\",\n"
            . "      \"title\": \"既存記事のタイトル\",\n"
            . "      \"similarity\": \"high|medium|low\",\n"
            . "      \"reason\": \"類似と判断した理由（50文字以内）\",\n"
            . "      \"differentiation_suggestion\": \"差別化の具体的な提案（80文字以内）\"\n"
            . "    }\n"
            . "  ],\n"
            . "  \"overall_suggestion\": \"全体的なアドバイス（100文字以内）\",\n"
            . "  \"suggested_angles\": [\"新しい記事で取れる切り口1\", \"切り口2\", \"切り口3\"]\n"
            . "}\n\n"
            . "similar_articles には similarity が medium 以上のもののみ含めてください。\n"
            . "1件も類似がなければ similar_articles は空配列にしてください。\n";

        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'     => 0.3,
                'maxOutputTokens' => 4096,
            ] );
        } catch ( \Throwable $e ) {
            $this->log( "check_similarity error: " . $e->getMessage() );
            return [ 'risk_level' => 'none', 'similar_articles' => [], 'overall_suggestion' => '', 'suggested_angles' => [] ];
        }

        // JSON パース
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

        $first = strpos( $cleaned, '{' );
        $last  = strrpos( $cleaned, '}' );
        if ( $first === false || $last === false || $last <= $first ) {
            $this->log( "check_similarity parse error. Raw: " . substr( $raw, 0, 500 ) );
            return [ 'risk_level' => 'none', 'similar_articles' => [], 'overall_suggestion' => '', 'suggested_angles' => [] ];
        }

        $result = json_decode( substr( $cleaned, $first, $last - $first + 1 ), true );
        if ( ! is_array( $result ) || ! isset( $result['risk_level'] ) ) {
            $this->log( "check_similarity invalid JSON" );
            return [ 'risk_level' => 'none', 'similar_articles' => [], 'overall_suggestion' => '', 'suggested_angles' => [] ];
        }

        return $result;
    }

    /**
     * 類似リスクレベル取得ヘルパー
     */
    private function get_similarity_risk_level( int $post_id ): string {
        $raw = get_post_meta( $post_id, '_gcrev_article_similarity_result', true );
        if ( ! $raw ) return 'none';
        $data = json_decode( $raw, true );
        return $data['risk_level'] ?? 'none';
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
