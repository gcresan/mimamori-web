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

	public const MAX_FILE_BYTES       = 2 * 1024 * 1024; // 2MB (text/pdf/csv/html)
	public const MAX_IMAGE_FILE_BYTES = 4 * 1024 * 1024; // 4MB (image)
	public const MAX_TEXT_CHARS       = 500_000;         // 抽出後の最大文字数 (テナント保護)

	private const ALLOWED_EXT = [
		'txt', 'md', 'markdown', 'csv', 'html', 'htm',
		'pdf',
		'jpg', 'jpeg', 'png', 'webp',
	];

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

		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
			throw new RuntimeException( '未対応の拡張子です: .' . $ext );
		}
		$is_image = in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true );
		$max_size = $is_image ? self::MAX_IMAGE_FILE_BYTES : self::MAX_FILE_BYTES;
		if ( $size > $max_size ) {
			throw new RuntimeException( 'ファイルサイズが上限(' . round( $max_size / 1024 / 1024 ) . 'MB)を超えています' );
		}

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
			case 'pdf':
				$text = self::extract_pdf( $body );
				$mime = 'application/pdf';
				break;
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'webp':
				$mime = self::detect_image_mime( $body, $ext );
				$text = self::extract_image( $body, $mime );
				break;
			default:
				throw new RuntimeException( '未対応の拡張子です: .' . $ext );
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

	/**
	 * PDF からテキスト抽出。smalot/pdfparser がサーバーに導入されていれば使用する。
	 * 未導入の場合は明確なエラーで案内。
	 */
	private static function extract_pdf( string $body ): string {
		// smalot/pdfparser を多段ロード:
		//   (a) すでに autoload 済み
		//   (b) テーマ vendor (GCREV_VENDOR_PATH)
		//   (c) プラグイン同梱 vendor (将来用)
		if ( ! class_exists( 'Smalot\\PdfParser\\Parser' ) ) {
			if ( defined( 'GCREV_VENDOR_PATH' ) && file_exists( GCREV_VENDOR_PATH . 'autoload.php' ) ) {
				require_once GCREV_VENDOR_PATH . 'autoload.php';
			}
		}
		if ( ! class_exists( 'Smalot\\PdfParser\\Parser' ) ) {
			$plugin_vendor = MIMAMORI_BOT_PATH . 'vendor/autoload.php';
			if ( file_exists( $plugin_vendor ) ) {
				require_once $plugin_vendor;
			}
		}
		if ( ! class_exists( 'Smalot\\PdfParser\\Parser' ) ) {
			throw new RuntimeException(
				'PDF取込にはサーバーへの smalot/pdfparser 導入が必要です。'
				. 'テーマvendor (' . ( defined( 'GCREV_VENDOR_PATH' ) ? (string) GCREV_VENDOR_PATH : '/vendor' ) . ') で '
				. '"composer require smalot/pdfparser" を実行してください。'
			);
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$doc    = $parser->parseContent( $body );
			$text   = $doc->getText();
		} catch ( \Throwable $e ) {
			Mimamori_Bot_Logger::error( 'extractor: pdf parse failed', [ 'msg' => $e->getMessage() ] );
			throw new RuntimeException( 'PDFの解析に失敗しました: ' . $e->getMessage() );
		}

		// 連続空白整理
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', (string) $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text );
		$text = trim( (string) $text );

		if ( $text === '' ) {
			throw new RuntimeException( 'PDFからテキストを抽出できませんでした (画像ベースのPDFかもしれません。OCR対応はjpg/png/webp取込で行えます)' );
		}
		return $text;
	}

	/**
	 * 画像から OCR 経由でテキスト抽出。OpenAI Vision を使用。
	 */
	private static function extract_image( string $body, string $mime ): string {
		// magic-bytes 検証 (拡張子偽装防止)
		$detected = self::detect_image_mime( $body, '' );
		if ( $detected !== $mime ) {
			// 厳密一致しない場合でも、許可された画像 mime 内ならOKとする
			if ( ! in_array( $detected, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
				throw new RuntimeException( '画像形式を判定できませんでした (拡張子偽装の可能性)' );
			}
			$mime = $detected;
		}
		$vision = new Mimamori_Bot_Vision_Bridge();
		$result = $vision->extract_text( $body, $mime );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		return (string) $result['text'];
	}

	/**
	 * 画像の MIME を magic-bytes 先頭から判定する。
	 * fallback: 拡張子から推測。
	 */
	private static function detect_image_mime( string $body, string $fallback_ext ): string {
		if ( strlen( $body ) >= 12 ) {
			$head = substr( $body, 0, 12 );
			// JPEG: FF D8 FF
			if ( substr( $head, 0, 3 ) === "\xFF\xD8\xFF" ) return 'image/jpeg';
			// PNG: 89 50 4E 47 0D 0A 1A 0A
			if ( substr( $head, 0, 8 ) === "\x89PNG\r\n\x1A\n" ) return 'image/png';
			// WebP: RIFF....WEBP
			if ( substr( $head, 0, 4 ) === 'RIFF' && substr( $head, 8, 4 ) === 'WEBP' ) return 'image/webp';
		}
		switch ( strtolower( $fallback_ext ) ) {
			case 'jpg':
			case 'jpeg': return 'image/jpeg';
			case 'png':  return 'image/png';
			case 'webp': return 'image/webp';
		}
		return 'application/octet-stream';
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
