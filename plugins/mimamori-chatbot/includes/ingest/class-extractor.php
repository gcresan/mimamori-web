<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Extractor' ) ) { return; }

/**
 * Mimamori_Bot_Extractor
 *
 * アップロードファイル → 平文テキスト変換のディスパッチャ。
 * Phase 2b 対応:
 *   - text/plain  (.txt)
 *   - text/markdown (.md)
 *   - text/html (.html, .htm)
 *   - text/csv (.csv)
 *
 * Phase 2c 予定:
 *   - application/pdf            (要 smalot/pdfparser)
 *   - application/vnd.openxmlformats-officedocument.* (要 phpoffice)
 *   - image/* (要 OpenAI Vision)
 */
class Mimamori_Bot_Extractor {

	public const MAX_FILE_BYTES = 2 * 1024 * 1024; // 2MB
	public const MAX_TEXT_CHARS = 500_000;          // 抽出後の最大文字数 (テナント保護)

	/**
	 * @return array{title:string, raw_text:string, mime:string}
	 * @throws RuntimeException
	 */
	public static function extract_from_upload( array $file_info ): array {
		if ( ! is_array( $file_info ) || ( $file_info['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			throw new RuntimeException( 'ファイルアップロードに失敗しました (code=' . ( $file_info['error'] ?? '?' ) . ')' );
		}
		$tmp = (string) $file_info['tmp_name'];
		$name = (string) ( $file_info['name'] ?? 'upload' );
		$size = (int) ( $file_info['size'] ?? 0 );

		if ( $size <= 0 || ! is_uploaded_file( $tmp ) ) {
			throw new RuntimeException( 'アップロードファイルが不正です' );
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			throw new RuntimeException( 'ファイルサイズが上限(2MB)を超えています' );
		}

		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$body = (string) file_get_contents( $tmp );
		if ( $body === '' ) {
			throw new RuntimeException( 'ファイルが空です' );
		}

		switch ( $ext ) {
			case 'txt':
			case 'md':
			case 'markdown':
				$text = self::extract_text( $body );
				$mime = $ext === 'txt' ? 'text/plain' : 'text/markdown';
				break;
			case 'csv':
				$text = self::extract_csv( $body );
				$mime = 'text/csv';
				break;
			case 'html':
			case 'htm':
				$text = self::extract_html( $body );
				$mime = 'text/html';
				break;
			default:
				throw new RuntimeException( '未対応の拡張子です: .' . $ext . ' (Phase 2bは txt/md/csv/html のみ)' );
		}

		$text = trim( $text );
		if ( $text === '' ) {
			throw new RuntimeException( 'ファイルからテキストを抽出できませんでした' );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT_CHARS ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_CHARS );
		}
		// タイトル候補: ファイル名から拡張子を除去
		$title = pathinfo( $name, PATHINFO_FILENAME );
		if ( $title === '' ) $title = 'upload';

		return [ 'title' => $title, 'raw_text' => $text, 'mime' => $mime ];
	}

	private static function extract_text( string $body ): string {
		// 文字コード自動判定 (BOM除去含む)
		$body = self::ensure_utf8( $body );
		// 行末コードを LF に統一、連続空行を維持 (チャンカが段落として認識する)
		$body = preg_replace( "/\r\n?/", "\n", $body );
		return $body;
	}

	private static function extract_csv( string $body ): string {
		$body = self::ensure_utf8( $body );
		$lines = [];
		$fh = fopen( 'php://temp', 'r+' );
		if ( $fh === false ) return $body; // 最終手段
		fwrite( $fh, $body );
		rewind( $fh );

		$header = null;
		$row_no = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$row_no++;
			$row = array_map( static fn( $v ) => (string) $v, $row );
			if ( $header === null ) {
				$header = $row;
				$lines[] = implode( ' | ', $header );
				$lines[] = str_repeat( '-', 3 );
				continue;
			}
			// "ヘッダ: 値" 形式で組み立てる (LLMが解釈しやすい)
			$pairs = [];
			foreach ( $row as $i => $cell ) {
				$col = $header[ $i ] ?? ( 'col' . $i );
				$pairs[] = $col . ': ' . $cell;
			}
			$lines[] = '行' . $row_no . ' — ' . implode( ' / ', $pairs );
		}
		fclose( $fh );
		return implode( "\n", $lines );
	}

	private static function extract_html( string $body ): string {
		$body = self::ensure_utf8( $body );
		// DOMDocument で構造解析 + 不要タグ除去
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// メタタグで文字コード固定（DOMDocument の charset 推定誤動作対策）
		$wrapped = '<?xml encoding="UTF-8"?>' . $body;
		@$dom->loadHTML( $wrapped, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		// ノイズタグを除去
		foreach ( [ 'script', 'style', 'noscript', 'iframe', 'svg', 'nav', 'header', 'footer', 'aside' ] as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			// 後ろから消す (NodeList は live)
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$n = $nodes->item( $i );
				if ( $n && $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
		}
		// ブロック要素を改行扱いにするため textContent前に改行注入
		$blocks = [ 'p', 'div', 'br', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr' ];
		foreach ( $blocks as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$n = $nodes->item( $i );
				if ( $n ) {
					$n->appendChild( $dom->createTextNode( "\n" ) );
				}
			}
		}
		$text = $dom->textContent;
		// 連続空白を整理しつつ段落を保つ
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\n[ \t]*/u", "\n", $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text );
		return trim( (string) $text );
	}

	private static function ensure_utf8( string $body ): string {
		// BOM 除去
		if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$body = substr( $body, 3 );
		}
		// UTF-8 でなければ変換
		if ( ! mb_check_encoding( $body, 'UTF-8' ) ) {
			$detected = mb_detect_encoding( $body, [ 'UTF-8', 'SJIS-win', 'CP932', 'SJIS', 'EUC-JP', 'JIS', 'ASCII' ], true );
			if ( $detected && $detected !== 'UTF-8' ) {
				$converted = mb_convert_encoding( $body, 'UTF-8', $detected );
				if ( is_string( $converted ) ) $body = $converted;
			}
		}
		return $body;
	}
}
