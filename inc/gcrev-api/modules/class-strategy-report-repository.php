<?php
// FILE: inc/gcrev-api/modules/class-strategy-report-repository.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Report_Repository' ) ) { return; }

/**
 * Gcrev_Strategy_Report_Repository
 *
 * gcrev_strategy_reports テーブルへの CRUD。
 * UNIQUE (user_id, year_month) なので「同一ユーザー × 同月」は常に1行。
 * 再生成は同じ行を UPDATE する。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Report_Repository {

    private function table(): string {
        return Gcrev_Strategy_Tables::strategy_reports_table();
    }

    /**
     * 生成開始: 既存行があれば running に上書き、無ければ INSERT する。
     * 戻り値: report_id
     */
    public function start_generation(
        int $user_id,
        string $year_month,
        int $strategy_id,
        string $generation_source = 'cron'
    ): int {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE user_id = %d AND year_month = %s LIMIT 1",
            $user_id,
            $year_month
        ) );

        if ( $existing_id > 0 ) {
            $wpdb->update(
                $this->table(),
                [
                    'strategy_id'        => $strategy_id,
                    'status'             => 'running',
                    'attempts'           => 'attempts + 1', // ※ プレースホルダ無効。下で raw SQL で更新
                    'generation_source'  => $generation_source,
                    'started_at'         => $now,
                    'finished_at'        => null,
                    'error_message'      => null,
                    'updated_at'         => $now,
                ],
                [ 'id' => $existing_id ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            // attempts のインクリメントだけは別に raw SQL
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->table()} SET attempts = attempts + 1 WHERE id = %d",
                $existing_id
            ) );
            return $existing_id;
        }

        $wpdb->insert(
            $this->table(),
            [
                'user_id'           => $user_id,
                'year_month'        => $year_month,
                'strategy_id'       => $strategy_id,
                'status'            => 'running',
                'attempts'          => 1,
                'generation_source' => $generation_source,
                'started_at'        => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [ '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * 生成完了: report_json / rendered_html / AIメタ情報を保存。
     */
    public function complete( int $report_id, array $payload ): bool {
        global $wpdb;
        $now = current_time( 'mysql', false );
        $data = [
            'status'           => 'completed',
            'alignment_score'  => isset( $payload['alignment_score'] ) ? (int) $payload['alignment_score'] : null,
            'report_json'      => isset( $payload['report_json'] ) ? wp_json_encode( $payload['report_json'], JSON_UNESCAPED_UNICODE ) : null,
            'rendered_html'    => isset( $payload['rendered_html'] ) ? (string) $payload['rendered_html'] : null,
            'ai_model'         => isset( $payload['ai_model'] ) ? (string) $payload['ai_model'] : null,
            'ai_input_tokens'  => isset( $payload['ai_input_tokens'] ) ? (int) $payload['ai_input_tokens'] : null,
            'ai_output_tokens' => isset( $payload['ai_output_tokens'] ) ? (int) $payload['ai_output_tokens'] : null,
            'error_message'    => null,
            'finished_at'      => $now,
            'updated_at'       => $now,
        ];
        $formats = [ '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ];

        $updated = $wpdb->update(
            $this->table(),
            $data,
            [ 'id' => $report_id ],
            $formats,
            [ '%d' ]
        );
        return $updated !== false;
    }

    /** 失敗を記録（attempts は start_generation 側でインクリメント済み） */
    public function fail( int $report_id, string $error_message ): bool {
        global $wpdb;
        $now = current_time( 'mysql', false );
        $updated = $wpdb->update(
            $this->table(),
            [
                'status'        => 'failed',
                'error_message' => mb_substr( $error_message, 0, 1000 ),
                'finished_at'   => $now,
                'updated_at'    => $now,
            ],
            [ 'id' => $report_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        return $updated !== false;
    }

    /**
     * 戦略未設定など、生成しない理由がはっきりしている場合の skipped 行を upsert する。
     */
    public function mark_skipped( int $user_id, string $year_month, string $reason ): int {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE user_id = %d AND year_month = %s LIMIT 1",
            $user_id,
            $year_month
        ) );

        if ( $existing_id > 0 ) {
            $wpdb->update(
                $this->table(),
                [
                    'status'        => 'skipped',
                    'error_message' => mb_substr( $reason, 0, 1000 ),
                    'finished_at'   => $now,
                    'updated_at'    => $now,
                ],
                [ 'id' => $existing_id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return $existing_id;
        }

        $wpdb->insert(
            $this->table(),
            [
                'user_id'           => $user_id,
                'year_month'        => $year_month,
                'strategy_id'       => 0,
                'status'            => 'skipped',
                'error_message'     => mb_substr( $reason, 0, 1000 ),
                'generation_source' => 'cron',
                'finished_at'       => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public function get_by_id( int $report_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
            $report_id
        ), ARRAY_A );
        return $row ? $this->hydrate( $row ) : null;
    }

    public function get_by_user_month( int $user_id, string $year_month ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND year_month = %s LIMIT 1",
            $user_id,
            $year_month
        ), ARRAY_A );
        return $row ? $this->hydrate( $row ) : null;
    }

    /**
     * ユーザーの最新 completed レポート（ダッシュボード表示用）
     */
    public function get_latest_completed( int $user_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE user_id = %d AND status = 'completed'
             ORDER BY year_month DESC, id DESC
             LIMIT 1",
            $user_id
        ), ARRAY_A );
        return $row ? $this->hydrate( $row ) : null;
    }

    /**
     * 履歴一覧（user 限定、新しい順）
     *
     * @return array<int,array> hydrate されたが report_json/rendered_html は除外（軽量）
     */
    public function get_history( int $user_id, int $limit = 24 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, year_month, strategy_id, status, alignment_score,
                    ai_model, generation_source, started_at, finished_at, created_at
             FROM {$this->table()}
             WHERE user_id = %d
             ORDER BY year_month DESC, id DESC
             LIMIT %d",
            $user_id,
            $limit
        ), ARRAY_A );
        return $rows ?: [];
    }

    private function hydrate( array $row ): array {
        $row['id']               = (int) $row['id'];
        $row['user_id']          = (int) $row['user_id'];
        $row['strategy_id']      = (int) $row['strategy_id'];
        $row['attempts']         = isset( $row['attempts'] ) ? (int) $row['attempts'] : 0;
        $row['alignment_score']  = isset( $row['alignment_score'] ) ? (int) $row['alignment_score'] : null;
        $row['ai_input_tokens']  = isset( $row['ai_input_tokens'] ) ? (int) $row['ai_input_tokens'] : null;
        $row['ai_output_tokens'] = isset( $row['ai_output_tokens'] ) ? (int) $row['ai_output_tokens'] : null;

        if ( isset( $row['report_json'] ) && is_string( $row['report_json'] ) && $row['report_json'] !== '' ) {
            $decoded = json_decode( $row['report_json'], true );
            $row['report_json'] = is_array( $decoded ) ? $decoded : null;
        }
        return $row;
    }
}
