<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Faq_Repository' ) ) { return; }

/**
 * Mimamori_Bot_Faq_Repository
 *
 * FAQ (高優先度Q&A) の CRUD。
 *   - is_starter=1 の項目は初期表示の質問例として使われる
 *   - priority 降順で並び替え
 *   - retriever で利用された際に hit_count を増やして「よく当たるFAQ」を抽出可能に
 */
class Mimamori_Bot_Faq_Repository {

	public static function create( int $tenant_id, array $fields ): int {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$wpdb->insert( $table, self::normalize( $tenant_id, $fields ), self::formats() );
		$id = (int) $wpdb->insert_id;
		// 埋め込み生成 (question 単体で意味的類似検索する想定)
		self::regenerate_embedding( $tenant_id, $id, (string) $fields['question'] );
		Mimamori_Bot_Logger::info( 'faq: created', [ 'tenant_id' => $tenant_id, 'id' => $id ] );
		return $id;
	}

	public static function update( int $tenant_id, int $id, array $fields ): bool {
		global $wpdb;
		$table  = Mimamori_Bot_Installer::table_faq();
		$data   = self::normalize( $tenant_id, $fields );
		unset( $data['tenant_id'] ); // tenant_id は WHERE 側でしか使わない
		$result = $wpdb->update(
			$table, $data,
			[ 'id' => $id, 'tenant_id' => $tenant_id ],
			self::formats( false ),
			[ '%d', '%d' ]
		);
		// 質問が変わった可能性があるので埋め込みも再生成
		self::regenerate_embedding( $tenant_id, $id, (string) $fields['question'] );
		return $result !== false;
	}

	/**
	 * FAQ の埋め込み (question ベース) を生成・保存する。
	 */
	public static function regenerate_embedding( int $tenant_id, int $id, string $question ): void {
		$embedder = new Mimamori_Bot_Embedder();
		$blob = $embedder->embed_one( $question );
		if ( $blob === null ) return;
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$wpdb->update( $table, [ 'embedding' => $blob ],
			[ 'id' => $id, 'tenant_id' => $tenant_id ],
			[ '%s' ], [ '%d', '%d' ]
		);
	}

	/**
	 * テナント全体の FAQ 埋め込み再生成 (Phase 2a データの後付け)。
	 *
	 * @return array{processed:int, saved:int}
	 */
	public static function reindex_tenant( int $tenant_id ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question FROM {$table}
			  WHERE tenant_id = %d AND embedding IS NULL AND status = 'active'",
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [ 'processed' => 0, 'saved' => 0 ];

		$texts = array_map( static fn( $r ) => (string) $r['question'], $rows );
		$embedder = new Mimamori_Bot_Embedder();
		$blobs    = $embedder->embed_batch( $texts );

		$saved = 0;
		foreach ( $rows as $i => $r ) {
			$blob = $blobs[ $i ] ?? null;
			if ( $blob === null ) continue;
			$wpdb->update( $table, [ 'embedding' => $blob ],
				[ 'id' => (int) $r['id'], 'tenant_id' => $tenant_id ],
				[ '%s' ], [ '%d', '%d' ]
			);
			$saved++;
		}
		Mimamori_Bot_Logger::info( 'faq: reindex done', [
			'tenant_id' => $tenant_id, 'processed' => count( $rows ), 'saved' => $saved,
		] );
		return [ 'processed' => count( $rows ), 'saved' => $saved ];
	}

	public static function delete( int $tenant_id, int $id ): bool {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$result = $wpdb->delete( $table, [ 'id' => $id, 'tenant_id' => $tenant_id ], [ '%d', '%d' ] );
		Mimamori_Bot_Logger::info( 'faq: deleted', [ 'tenant_id' => $tenant_id, 'id' => $id ] );
		return (bool) $result;
	}

	public static function find( int $tenant_id, int $id ): ?array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE tenant_id = %d AND id = %d LIMIT 1",
			$tenant_id, $id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function list_for_tenant( int $tenant_id, int $limit = 200 ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question, answer, category, priority, is_starter, hit_count, status, updated_at
			   FROM {$table}
			  WHERE tenant_id = %d
			  ORDER BY is_starter DESC, priority DESC, id ASC
			  LIMIT %d",
			$tenant_id, $limit
		), ARRAY_A );
		return $rows ?: [];
	}

	/**
	 * 初期表示用スターター質問を取得。
	 *   - is_starter=1 かつ status='active'
	 *   - priority 降順 → id 昇順
	 *
	 * @return array list of question strings
	 */
	public static function list_starters( int $tenant_id, int $limit = 4 ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT question FROM {$table}
			  WHERE tenant_id = %d AND is_starter = 1 AND status = 'active'
			  ORDER BY priority DESC, id ASC
			  LIMIT %d",
			$tenant_id, $limit
		) );
		return $rows ?: [];
	}

	/* =============================================================
	 *  AI スターター提案 — ナレッジ + 履歴を AI に渡して自動生成
	 * ============================================================= */

	/** 提案キャッシュの transient キー */
	private static function suggestion_transient_key( int $tenant_id ): string {
		return 'mb_starter_suggest_' . $tenant_id;
	}

	/**
	 * キャッシュされた提案を返す (なければ null)。
	 * 形式: ['suggestions'=>['q1','q2','q3','q4'], 'generated_at'=>ts, 'message_count'=>N]
	 */
	public static function get_cached_suggestions( int $tenant_id ): ?array {
		$cached = get_transient( self::suggestion_transient_key( $tenant_id ) );
		return is_array( $cached ) && ! empty( $cached['suggestions'] ) ? $cached : null;
	}

	/**
	 * ナレッジ全文 + 直近のユーザー質問を AI に渡し、最大4つの代表質問を生成する。
	 * 結果は 7日キャッシュ。AIキー未設定や履歴不足時は空配列を返す。
	 *
	 * @return array{suggestions:array<int,string>, generated_at:int, message_count:int, knowledge_count:int}
	 */
	public static function compute_starter_suggestions( int $tenant_id ): array {
		global $wpdb;

		// 1) ナレッジ抽出 (raw_text の先頭6000字分まで・複数記事連結)
		$kt = Mimamori_Bot_Installer::table_knowledge();
		$knowledge_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT title, raw_text FROM {$kt}
			  WHERE tenant_id = %d AND status = 'ready'
			  ORDER BY updated_at DESC
			  LIMIT 30",
			$tenant_id
		), ARRAY_A );
		$knowledge_count = count( $knowledge_rows );
		$knowledge_text  = '';
		foreach ( $knowledge_rows as $row ) {
			$knowledge_text .= "■ " . ( $row['title'] ?? '' ) . "\n";
			$knowledge_text .= mb_substr( (string) ( $row['raw_text'] ?? '' ), 0, 2000 ) . "\n\n";
			if ( mb_strlen( $knowledge_text ) > 6000 ) break;
		}
		$knowledge_text = trim( mb_substr( $knowledge_text, 0, 6000 ) );

		// 2) 直近90日の user 発話を最大200件まで
		$mt = Mimamori_Bot_Installer::table_messages();
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$user_msgs = $wpdb->get_col( $wpdb->prepare(
			"SELECT content FROM {$mt}
			  WHERE tenant_id = %d AND role = 'user' AND created_at >= %s
			  ORDER BY created_at DESC
			  LIMIT 200",
			$tenant_id, $since
		) );
		$message_count = is_array( $user_msgs ) ? count( $user_msgs ) : 0;
		$history_text  = '';
		if ( $message_count > 0 ) {
			$snippets = array_map( static fn( $m ) => '・' . mb_substr( (string) $m, 0, 60 ), $user_msgs );
			$history_text = implode( "\n", array_slice( $snippets, 0, 80 ) );
		}

		// ナレッジも履歴も無い → 何も提案できない
		if ( $knowledge_text === '' && $history_text === '' ) {
			Mimamori_Bot_Logger::info( 'starter: skip — no data', [ 'tenant_id' => $tenant_id ] );
			return [ 'suggestions' => [], 'generated_at' => time(), 'message_count' => 0, 'knowledge_count' => 0 ];
		}

		// 3) プロンプト組み立て
		$prompt  = "あなたはチャットボット運用の専門家です。以下の情報を基に、訪問者がもっとも聞きそうな質問を4つ作成してください。\n\n";
		if ( $knowledge_text !== '' ) {
			$prompt .= "【サービスナレッジ】\n" . $knowledge_text . "\n\n";
		}
		if ( $history_text !== '' ) {
			$prompt .= "【実際にあった質問 (直近90日)】\n" . $history_text . "\n\n";
		}
		$prompt .= "制約:\n";
		$prompt .= "- 各質問は20字以内、自然な日本語\n";
		$prompt .= "- 4つすべて異なるトピック (料金/エリア/効果/品質/手続き等)\n";
		$prompt .= "- 訪問者が思わずクリックしたくなる具体性\n";
		$prompt .= "- 履歴がある場合はそのパターンを優先的に反映\n\n";
		$prompt .= "出力形式は次の JSON のみ (前置きや説明は不要):\n";
		$prompt .= '{"starters":["質問1","質問2","質問3","質問4"]}';

		// 4) OpenAI 呼び出し
		$bridge = new Mimamori_Bot_OpenAI_Bridge();
		$resp = $bridge->call( [
			'model' => 'gpt-4o-mini',
			'input' => $prompt,
		] );
		if ( is_wp_error( $resp ) ) {
			Mimamori_Bot_Logger::warn( 'starter: openai error', [ 'tenant_id' => $tenant_id, 'err' => $resp->get_error_message() ] );
			return [ 'suggestions' => [], 'generated_at' => time(), 'message_count' => $message_count, 'knowledge_count' => $knowledge_count ];
		}

		$text = (string) ( $resp['text'] ?? '' );
		$suggestions = self::parse_starter_response( $text );

		$result = [
			'suggestions'     => $suggestions,
			'generated_at'    => time(),
			'message_count'   => $message_count,
			'knowledge_count' => $knowledge_count,
		];
		if ( ! empty( $suggestions ) ) {
			set_transient( self::suggestion_transient_key( $tenant_id ), $result, 7 * DAY_IN_SECONDS );
			Mimamori_Bot_Logger::info( 'starter: regenerated', [
				'tenant_id'    => $tenant_id,
				'count'        => count( $suggestions ),
				'msg_count'    => $message_count,
				'kn_count'     => $knowledge_count,
				'tokens_in'    => $resp['usage']['input_tokens']  ?? 0,
				'tokens_out'   => $resp['usage']['output_tokens'] ?? 0,
			] );
		}
		return $result;
	}

	/** OpenAI 応答テキストから starters 配列を抽出 (JSON or 箇条書きフォールバック) */
	private static function parse_starter_response( string $text ): array {
		$text = trim( $text );
		if ( $text === '' ) return [];

		// JSON 抽出 (前後にmarkdown ```json``` が付くケースを処理)
		if ( preg_match( '/\{[^{}]*"starters"\s*:\s*\[[^\]]+\][^{}]*\}/su', $text, $m ) ) {
			$json = json_decode( $m[0], true );
			if ( is_array( $json ) && isset( $json['starters'] ) && is_array( $json['starters'] ) ) {
				return self::clean_starter_list( $json['starters'] );
			}
		}

		// フォールバック: 改行ごとに番号や記号を除去
		$lines = preg_split( '/\R/u', $text ) ?: [];
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			$line = preg_replace( '/^[0-9０-９]+[\.\)、）]\s*/u', '', $line );
			$line = preg_replace( '/^[・\-\*]\s*/u', '', $line );
			$line = trim( (string) $line, "「」\"' \t" );
			if ( $line !== '' && mb_strlen( $line ) <= 50 ) {
				$out[] = $line;
			}
			if ( count( $out ) >= 4 ) break;
		}
		return self::clean_starter_list( $out );
	}

	private static function clean_starter_list( array $arr ): array {
		$out = [];
		foreach ( $arr as $item ) {
			$s = trim( (string) $item );
			$s = preg_replace( '/\s+/u', ' ', $s );
			if ( $s === '' ) continue;
			$s = mb_substr( $s, 0, 50 );
			$out[] = $s;
			if ( count( $out ) >= 4 ) break;
		}
		return $out;
	}

	public static function bump_hit_count( int $tenant_id, int $id ): void {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET hit_count = hit_count + 1
			  WHERE tenant_id = %d AND id = %d",
			$tenant_id, $id
		) );
	}

	private static function normalize( int $tenant_id, array $fields ): array {
		$status = isset( $fields['status'] ) ? sanitize_key( (string) $fields['status'] ) : 'active';
		if ( ! in_array( $status, [ 'active', 'draft', 'disabled' ], true ) ) {
			$status = 'active';
		}
		return [
			'tenant_id'  => $tenant_id,
			'question'   => mb_substr( (string) ( $fields['question'] ?? '' ), 0, 500 ),
			'answer'     => (string) ( $fields['answer'] ?? '' ),
			'category'   => isset( $fields['category'] ) ? mb_substr( (string) $fields['category'], 0, 80 ) : null,
			'priority'   => (int) ( $fields['priority'] ?? 0 ),
			'is_starter' => ! empty( $fields['is_starter'] ) ? 1 : 0,
			'status'     => $status,
		];
	}

	/**
	 * insert/update 用フォーマット。update 時は tenant_id を含めないので
	 * $include_tenant=false で1要素少ない配列を返す。
	 */
	private static function formats( bool $include_tenant = true ): array {
		$fmt = $include_tenant ? [ '%d' ] : [];
		return array_merge( $fmt, [ '%s', '%s', '%s', '%d', '%d', '%s' ] );
	}
}
