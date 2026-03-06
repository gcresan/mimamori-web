<?php
// FILE: inc/gcrev-api/utils/class-qa-revision-store.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Revision_Store
 *
 * 改善ループの試行履歴を永続化する。
 * 保存先: wp-content/uploads/mimamori/qa_runs/{run_id}/improvements/
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Mimamori_QA_Revision_Store {

    /** @var string 改善データ保存ディレクトリ */
    private string $improve_dir;

    /**
     * @param string $out_dir QA実行出力ディレクトリ
     */
    public function __construct( string $out_dir ) {
        $this->improve_dir = trailingslashit( $out_dir ) . 'improvements';

        if ( ! is_dir( $this->improve_dir ) ) {
            wp_mkdir_p( $this->improve_dir );
        }
    }

    /**
     * 1ケースの改善履歴を保存する。
     *
     * @param string $case_id    テストケースID（例: qa_001）
     * @param array  $revisions  リビジョン配列
     * @param string $stop_reason 停止理由
     */
    public function save_case( string $case_id, array $revisions, string $stop_reason ): void {
        $data = [
            'case_id'     => $case_id,
            'revisions'   => $revisions,
            'stop_reason' => $stop_reason,
            'total_revisions' => count( $revisions ),
            'initial_score'   => $revisions[0]['score_total'] ?? 0,
            'final_score'     => end( $revisions )['score_total'] ?? 0,
            'saved_at'        => wp_date( 'Y-m-d H:i:s' ),
        ];

        $path = $this->improve_dir . '/' . sanitize_file_name( $case_id ) . '.json';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
    }

    /**
     * 全体サマリーを保存する。
     *
     * @param array $summary サマリーデータ
     */
    public function save_summary( array $summary ): void {
        $path = $this->improve_dir . '/improve_summary.json';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
    }

    /**
     * 改善レポート（Markdown）を保存する。
     *
     * @param string $markdown レポート内容
     */
    public function save_report( string $markdown ): void {
        $path = $this->improve_dir . '/improve_report.md';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, $markdown );
    }

    /**
     * 1ケースの改善履歴を読み込む。
     *
     * @param  string $case_id テストケースID
     * @return array|null
     */
    public function load_case( string $case_id ): ?array {
        $path = $this->improve_dir . '/' . sanitize_file_name( $case_id ) . '.json';

        if ( ! file_exists( $path ) ) return null;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $path );
        if ( $raw === false ) return null;

        return json_decode( $raw, true );
    }

    /**
     * 全体サマリーを読み込む。
     *
     * @return array|null
     */
    public function load_summary(): ?array {
        $path = $this->improve_dir . '/improve_summary.json';

        if ( ! file_exists( $path ) ) return null;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $path );
        if ( $raw === false ) return null;

        return json_decode( $raw, true );
    }

    /**
     * 改善レポート（Markdown）を読み込む。
     *
     * @return string|null
     */
    public function load_report(): ?string {
        $path = $this->improve_dir . '/improve_report.md';

        if ( ! file_exists( $path ) ) return null;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return file_get_contents( $path ) ?: null;
    }

    /**
     * 改善ディレクトリが存在するか。
     *
     * @return bool
     */
    public function exists(): bool {
        return is_dir( $this->improve_dir );
    }

    /**
     * 改善済みケースID一覧を返す。
     *
     * @return array
     */
    public function list_cases(): array {
        if ( ! $this->exists() ) return [];

        $files = glob( $this->improve_dir . '/qa_*.json' );
        if ( $files === false ) return [];

        return array_map( static fn( $f ) => pathinfo( $f, PATHINFO_FILENAME ), $files );
    }

    /**
     * 保存ディレクトリのパスを返す。
     *
     * @return string
     */
    public function get_dir(): string {
        return $this->improve_dir;
    }
}
