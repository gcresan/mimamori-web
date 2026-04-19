<?php
// FILE: inc/gcrev-api/utils/class-qa-prompt-registry.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Prompt_Registry
 *
 * 本番 AI チャットに適用する「承認済み補遺プロンプト（addendum）」と
 * 「許可された overrides」の SSOT（WordPress options）。
 *
 * - intent ごとに active は 1 本のみ（累積禁止）
 * - _global は固定レイヤー。Auto Promoter からは上書きできない
 * - overrides はホワイトリスト制
 * - 世代番号 (active_version) + history でロールバック可能
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Mimamori_QA_Prompt_Registry {

    /** WP options key */
    public const OPTION_KEY = 'mimamori_qa_prompt_registry';

    /** Canary ユーザー一覧の WP option key（Auto Promoter が参照） */
    public const CANARY_OPTION_KEY = 'mimamori_qa_canary_users';

    /** addendum 最大長 */
    public const ADDENDUM_MAX_LEN = 4000;

    /** history 最大保持件数 */
    public const HISTORY_LIMIT = 10;

    /** overrides 許可キー */
    public const ALLOWED_OVERRIDE_KEYS = [ 'temperature', 'context_boost', 'max_output_tokens' ];

    /** stage.mode の取りうる値 */
    public const STAGE_FULL   = 'full';
    public const STAGE_STAGED = 'staged';

    /** addendum に含まれてはいけないワード（プロンプトインジェクション／システム改ざん防止） */
    public const FORBIDDEN_ADDENDUM_WORDS = [
        'システムプロンプトを無視',
        'システムプロンプトを忘れ',
        'ignore all previous',
        'ignore previous instructions',
        'disregard the system',
        'model=',
        'gpt-',
        'claude-',
        'tool_choice',
        '</system>',
        '<system>',
        '```system',
        'api_key',
        'apikey',
        'secret',
    ];

    /** _global intent キー */
    public const GLOBAL_KEY = '_global';

    /** micro cache */
    private static ?array $cache = null;

    // =========================================================
    // Read
    // =========================================================

    /**
     * registry 全体を返す（キャッシュ利用）。
     */
    public static function get_registry(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }
        $raw = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        self::$cache = self::normalize_structure( $raw );
        return self::$cache;
    }

    /**
     * registry 全体を保存。
     */
    public static function save_registry( array $registry ): bool {
        $normalized = self::normalize_structure( $registry );
        $ok         = update_option( self::OPTION_KEY, $normalized, false );
        self::$cache = $ok ? $normalized : null;
        return (bool) $ok;
    }

    /**
     * 指定 intent に有効な addendum / overrides を返す。
     *
     * staged mode の場合、$user_id が対象外なら intent は null を返す
     * （ユーザーごとに段階的展開を実現する）。
     * $user_id = 0 は管理系 / CLI / 閲覧用途として常に full view を返す。
     *
     * 返り値:
     *   [
     *     'global'       => [ 'version' => ..., 'addendum' => ..., 'overrides' => [...] ] | null,
     *     'intent'       => [ 'version' => ..., 'addendum' => ..., 'overrides' => [...] ] | null,
     *     'intent_name'  => 'site_improvement' | '_global' | 'general' | ...
     *     'intent_stage' => 'full' | 'staged' | 'staged_excluded'
     *   ]
     *
     * @param string $intent intent 名（mimamori_rewrite_intent の返り値）
     * @param int    $user_id 現在のユーザー ID（0 = ステージング無視）
     */
    public static function get_active_intent( string $intent, int $user_id = 0 ): array {
        $reg = self::get_registry();

        $global = $reg['intents'][ self::GLOBAL_KEY ] ?? null;

        // intent 不明または空 → _global のみ
        if ( $intent === '' || $intent === self::GLOBAL_KEY ) {
            return [
                'global'       => $global,
                'intent'       => null,
                'intent_name'  => self::GLOBAL_KEY,
                'intent_stage' => 'full',
            ];
        }

        $intent_entry = $reg['intents'][ $intent ] ?? null;
        $stage        = is_array( $intent_entry['stage'] ?? null ) ? $intent_entry['stage'] : [];
        $mode         = (string) ( $stage['mode'] ?? self::STAGE_FULL );
        $user_ids     = array_map( 'intval', (array) ( $stage['user_ids'] ?? [] ) );

        $intent_stage_label = 'full';
        if ( $intent_entry !== null && $mode === self::STAGE_STAGED ) {
            // user_id = 0 は CLI / 管理系。ステージングを無視して intent を返す
            if ( $user_id > 0 && ! in_array( $user_id, $user_ids, true ) ) {
                // 対象外ユーザー → intent は非適用
                $intent_entry       = null;
                $intent_stage_label = 'staged_excluded';
            } else {
                $intent_stage_label = 'staged';
            }
        }

        return [
            'global'       => $global,
            'intent'       => $intent_entry,
            'intent_name'  => $intent,
            'intent_stage' => $intent_stage_label,
        ];
    }

    /**
     * registry 全体の active_version を返す。
     */
    public static function get_active_version(): string {
        $reg = self::get_registry();
        return (string) ( $reg['active_version'] ?? '' );
    }

    /**
     * intent ごとの active version マップを返す（run meta 用）。
     *
     * @return array{string,string}  [intent_name => version]
     */
    public static function get_intent_versions(): array {
        $reg = self::get_registry();
        $out = [];
        foreach ( (array) ( $reg['intents'] ?? [] ) as $intent => $data ) {
            if ( is_array( $data ) && isset( $data['version'] ) ) {
                $out[ (string) $intent ] = (string) $data['version'];
            }
        }
        return $out;
    }

    /**
     * history を返す（新しい順）。
     */
    public static function get_history( int $limit = self::HISTORY_LIMIT ): array {
        $reg = self::get_registry();
        $hist = $reg['history'] ?? [];
        if ( $limit > 0 ) {
            $hist = array_slice( $hist, 0, $limit );
        }
        return $hist;
    }

    // =========================================================
    // Write: promote / rollback / reject
    // =========================================================

    /**
     * intent に改訂案を昇格（active に置換）する。
     *
     * 成功時は新しい active_version を返す。
     * 失敗時は例外を投げる。
     *
     * @param string      $intent            intent 名（_global 禁止）
     * @param array       $revision          qa-improver のリビジョン配列
     * @param array       $source_info       { run_id, case_id, revision_no }
     * @param int         $user_id           承認した user_id（自動昇格時は 0）
     * @param string|null $edited_addendum   差し替える addendum（null なら revision の prompt_addendum をそのまま）
     *
     * @throws \InvalidArgumentException バリデーション失敗時
     */
    public static function promote(
        string $intent,
        array $revision,
        array $source_info,
        int $user_id = 0,
        ?string $edited_addendum = null,
        array $stage_user_ids = []
    ): string {
        if ( $intent === '' ) {
            throw new \InvalidArgumentException( 'intent must not be empty' );
        }
        if ( $intent === self::GLOBAL_KEY ) {
            throw new \InvalidArgumentException( '_global cannot be auto-promoted' );
        }

        $payload = self::sanitize_revision_payload( $revision );

        $addendum = $edited_addendum !== null
            ? self::sanitize_addendum_text( $edited_addendum )
            : (string) $payload['prompt_addendum'];

        if ( $addendum === '' ) {
            throw new \InvalidArgumentException( 'addendum is empty' );
        }
        if ( mb_strlen( $addendum ) > self::ADDENDUM_MAX_LEN ) {
            throw new \InvalidArgumentException(
                sprintf( 'addendum exceeds %d chars', self::ADDENDUM_MAX_LEN )
            );
        }
        if ( self::contains_forbidden_word( $addendum ) ) {
            throw new \InvalidArgumentException( 'addendum contains forbidden token' );
        }

        $overrides = self::filter_allowed_overrides( $payload['overrides'] ?? [] );

        $reg = self::get_registry();

        // history に現スナップショットを退避
        self::snapshot_to_history( $reg, 'promote' );

        // 新世代番号
        $new_version = self::generate_next_version( $reg );
        $now         = wp_date( 'Y-m-d H:i:s' );

        // stage 設定: user_ids 指定があれば staged、なければ full
        $stage_user_ids_clean = self::sanitize_user_id_list( $stage_user_ids );
        $stage = ! empty( $stage_user_ids_clean )
            ? [
                'mode'     => self::STAGE_STAGED,
                'user_ids' => $stage_user_ids_clean,
                'since'    => $now,
            ]
            : [
                'mode'     => self::STAGE_FULL,
                'user_ids' => [],
                'since'    => $now,
            ];

        $reg['intents'][ $intent ] = [
            'version'          => $new_version,
            'addendum'         => $addendum,
            'overrides'        => $overrides,
            'source'           => [
                'run_id'      => (string) ( $source_info['run_id'] ?? '' ),
                'case_id'     => (string) ( $source_info['case_id'] ?? '' ),
                'revision_no' => (int) ( $source_info['revision_no'] ?? 0 ),
            ],
            'auto_promoted_at' => $now,
            'approved_by'      => $user_id,
            'score_impact'     => [
                'before' => (int) ( $payload['score_before'] ?? 0 ),
                'after'  => (int) ( $payload['score_after'] ?? 0 ),
            ],
            'stage'            => $stage,
        ];

        $reg['active_version'] = $new_version;
        $reg['updated_at']     = $now;

        self::save_registry( $reg );
        self::log( 'promote', [
            'intent'       => $intent,
            'new_version'  => $new_version,
            'run_id'       => $source_info['run_id'] ?? '',
            'case_id'      => $source_info['case_id'] ?? '',
            'revision_no'  => $source_info['revision_no'] ?? 0,
            'approved_by'  => $user_id,
            'stage_mode'   => $stage['mode'],
            'stage_users'  => $stage['user_ids'],
        ] );

        return $new_version;
    }

    // =========================================================
    // Staged Rollout (Phase 4)
    // =========================================================

    /**
     * 指定 intent を「ステージング」モードに切り替える。
     *
     * @param string $intent    intent 名（_global 不可）
     * @param array  $user_ids  適用するユーザー ID 配列
     * @param int    $actor_id  操作ユーザー（ログ用）
     *
     * @throws \InvalidArgumentException
     */
    public static function stage( string $intent, array $user_ids, int $actor_id = 0 ): void {
        if ( $intent === '' || $intent === self::GLOBAL_KEY ) {
            throw new \InvalidArgumentException( 'invalid intent for staging' );
        }
        $user_ids_clean = self::sanitize_user_id_list( $user_ids );
        if ( empty( $user_ids_clean ) ) {
            throw new \InvalidArgumentException( 'user_ids must not be empty for staging' );
        }

        $reg = self::get_registry();
        if ( ! isset( $reg['intents'][ $intent ] ) || ! is_array( $reg['intents'][ $intent ] ) ) {
            throw new \InvalidArgumentException( 'intent not found in registry' );
        }

        $reg['intents'][ $intent ]['stage'] = [
            'mode'     => self::STAGE_STAGED,
            'user_ids' => $user_ids_clean,
            'since'    => wp_date( 'Y-m-d H:i:s' ),
        ];
        $reg['updated_at'] = wp_date( 'Y-m-d H:i:s' );
        self::save_registry( $reg );

        self::log( 'stage', [
            'intent'   => $intent,
            'user_ids' => $user_ids_clean,
            'actor'    => $actor_id,
        ] );
    }

    /**
     * 指定 intent のステージングを解除し、全ユーザー適用にする。
     *
     * @throws \InvalidArgumentException
     */
    public static function unstage( string $intent, int $actor_id = 0 ): void {
        if ( $intent === '' || $intent === self::GLOBAL_KEY ) {
            throw new \InvalidArgumentException( 'invalid intent for unstaging' );
        }
        $reg = self::get_registry();
        if ( ! isset( $reg['intents'][ $intent ] ) || ! is_array( $reg['intents'][ $intent ] ) ) {
            throw new \InvalidArgumentException( 'intent not found in registry' );
        }

        $reg['intents'][ $intent ]['stage'] = [
            'mode'     => self::STAGE_FULL,
            'user_ids' => [],
            'since'    => wp_date( 'Y-m-d H:i:s' ),
        ];
        $reg['updated_at'] = wp_date( 'Y-m-d H:i:s' );
        self::save_registry( $reg );

        self::log( 'unstage', [
            'intent' => $intent,
            'actor'  => $actor_id,
        ] );
    }

    /**
     * 指定 intent がステージング中か。
     */
    public static function is_staged( string $intent ): bool {
        $reg  = self::get_registry();
        $e    = $reg['intents'][ $intent ] ?? null;
        $mode = is_array( $e ) ? (string) ( $e['stage']['mode'] ?? self::STAGE_FULL ) : self::STAGE_FULL;
        return $mode === self::STAGE_STAGED;
    }

    /**
     * Canary ユーザー一覧を取得。
     *
     * Auto Promoter がこれを読み、設定されていれば auto-promote 時の
     * デフォルト stage.user_ids として使う。
     */
    public static function get_canary_users(): array {
        $raw = get_option( self::CANARY_OPTION_KEY, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        return self::sanitize_user_id_list( $raw );
    }

    /**
     * Canary ユーザー一覧を保存する。
     */
    public static function set_canary_users( array $user_ids ): bool {
        $clean = self::sanitize_user_id_list( $user_ids );
        $ok    = update_option( self::CANARY_OPTION_KEY, $clean, false );
        self::log( 'canary_set', [ 'user_ids' => $clean ] );
        return (bool) $ok;
    }

    /**
     * user_id 配列を正規化（int / 重複除去 / 0 除去）。
     */
    public static function sanitize_user_id_list( array $user_ids ): array {
        $out = [];
        foreach ( $user_ids as $u ) {
            $i = (int) $u;
            if ( $i > 0 ) {
                $out[] = $i;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * 指定 version の snapshot を active に復元する。
     *
     * 現 active はさらに history へ（reason=rollback として）退避。
     */
    public static function rollback_to( string $version, int $user_id = 0 ): bool {
        if ( $version === '' ) {
            return false;
        }
        $reg = self::get_registry();

        $target = null;
        $rest   = [];
        foreach ( ( $reg['history'] ?? [] ) as $h ) {
            if ( $target === null && ( $h['version'] ?? '' ) === $version ) {
                $target = $h;
                continue;
            }
            $rest[] = $h;
        }
        if ( $target === null ) {
            self::log( 'rollback_fail', [ 'version' => $version, 'reason' => 'not_in_history' ] );
            return false;
        }

        $from_version = $reg['active_version'] ?? '';
        // 現 active を history に退避
        self::snapshot_to_history( $reg, 'rollback' );

        // snapshot から intents を復元
        $snapshot         = $target['snapshot'] ?? [];
        $reg['intents']   = is_array( $snapshot['intents'] ?? null ) ? $snapshot['intents'] : [];
        // active_version は rollback 対象の version に戻す
        $reg['active_version'] = (string) $version;
        $reg['updated_at']     = wp_date( 'Y-m-d H:i:s' );

        // 既に復元した target は history から除く（rest に入っていないので復元後に再度 save）
        $reg['history'] = $rest;

        self::save_registry( $reg );

        self::log( 'rollback', [
            'from_version' => $from_version,
            'to_version'   => $version,
            'by_user'      => $user_id,
        ] );
        return true;
    }

    /**
     * 改訂案を明示的に却下する（registry は変更せず、ログだけ残す）。
     */
    public static function reject( string $run_id, string $case_id, int $revision_no, int $user_id = 0, string $reason = '' ): void {
        self::log( 'reject', [
            'run_id'      => $run_id,
            'case_id'     => $case_id,
            'revision_no' => $revision_no,
            'user_id'     => $user_id,
            'reason'      => $reason,
        ] );
    }

    // =========================================================
    // Sanitize / Validate
    // =========================================================

    /**
     * revision 配列から registry 用の正規化 payload を抽出。
     *
     * @return array {
     *   prompt_addendum: string,
     *   overrides: array,
     *   score_before: int,
     *   score_after: int,
     * }
     */
    public static function sanitize_revision_payload( array $revision ): array {
        $addendum = '';
        if ( isset( $revision['prompt_addendum'] ) ) {
            $addendum = self::sanitize_addendum_text( (string) $revision['prompt_addendum'] );
        } elseif ( isset( $revision['overrides']['prompt_addendum'] ) ) {
            $addendum = self::sanitize_addendum_text( (string) $revision['overrides']['prompt_addendum'] );
        }

        $overrides_src = is_array( $revision['overrides'] ?? null ) ? $revision['overrides'] : [];
        $overrides     = self::filter_allowed_overrides( $overrides_src );

        return [
            'prompt_addendum' => $addendum,
            'overrides'       => $overrides,
            'score_before'    => (int) ( $revision['score_before'] ?? 0 ),
            'score_after'     => (int) ( $revision['score_after'] ?? ( $revision['score_total'] ?? 0 ) ),
        ];
    }

    /**
     * overrides を whitelist に絞り込む。
     * 許可キー以外は黙って破棄。
     */
    public static function filter_allowed_overrides( array $overrides ): array {
        $out = [];
        foreach ( self::ALLOWED_OVERRIDE_KEYS as $key ) {
            if ( ! array_key_exists( $key, $overrides ) ) {
                continue;
            }
            $val = $overrides[ $key ];
            switch ( $key ) {
                case 'temperature':
                    $f = (float) $val;
                    if ( $f < 0.0 ) { $f = 0.0; }
                    if ( $f > 1.0 ) { $f = 1.0; }
                    $out['temperature'] = $f;
                    break;
                case 'max_output_tokens':
                    $i = (int) $val;
                    if ( $i < 256 )  { $i = 256; }
                    if ( $i > 4096 ) { $i = 4096; }
                    $out['max_output_tokens'] = $i;
                    break;
                case 'context_boost':
                    $out['context_boost'] = (bool) $val;
                    break;
            }
        }
        return $out;
    }

    /**
     * addendum 文字列のサニタイズ。
     */
    public static function sanitize_addendum_text( string $text ): string {
        $text = sanitize_textarea_field( $text );
        $text = trim( $text );
        if ( mb_strlen( $text ) > self::ADDENDUM_MAX_LEN ) {
            $text = mb_substr( $text, 0, self::ADDENDUM_MAX_LEN );
        }
        return $text;
    }

    /**
     * 禁止ワードが含まれるか（大文字小文字無視）。
     */
    public static function contains_forbidden_word( string $text ): bool {
        $lower = mb_strtolower( $text );
        foreach ( self::FORBIDDEN_ADDENDUM_WORDS as $w ) {
            if ( $w === '' ) { continue; }
            if ( mb_strpos( $lower, mb_strtolower( $w ) ) !== false ) {
                return true;
            }
        }
        return false;
    }

    // =========================================================
    // Internal helpers
    // =========================================================

    /**
     * 内部構造を常に一貫した形に正規化。
     */
    private static function normalize_structure( array $reg ): array {
        $reg['active_version'] = isset( $reg['active_version'] ) ? (string) $reg['active_version'] : '';
        $reg['intents']        = is_array( $reg['intents'] ?? null ) ? $reg['intents'] : [];
        $reg['history']        = is_array( $reg['history'] ?? null ) ? array_values( $reg['history'] ) : [];
        $reg['updated_at']     = (string) ( $reg['updated_at'] ?? '' );

        // 各 intent エントリに欠落フィールドがあれば埋める
        foreach ( $reg['intents'] as $k => $v ) {
            if ( ! is_array( $v ) ) {
                unset( $reg['intents'][ $k ] );
                continue;
            }
            $reg['intents'][ $k ] = array_merge(
                [
                    'version'          => '',
                    'addendum'         => '',
                    'overrides'        => [],
                    'source'           => [ 'run_id' => '', 'case_id' => '', 'revision_no' => 0 ],
                    'auto_promoted_at' => '',
                    'approved_by'      => 0,
                    'score_impact'     => [ 'before' => 0, 'after' => 0 ],
                    'stage'            => [ 'mode' => self::STAGE_FULL, 'user_ids' => [], 'since' => '' ],
                ],
                $v
            );
            // overrides は必ず whitelist 通過
            $reg['intents'][ $k ]['overrides'] = self::filter_allowed_overrides(
                is_array( $reg['intents'][ $k ]['overrides'] ) ? $reg['intents'][ $k ]['overrides'] : []
            );
            // stage は必ず正規化
            $stage_raw = is_array( $reg['intents'][ $k ]['stage'] ) ? $reg['intents'][ $k ]['stage'] : [];
            $stage_mode = (string) ( $stage_raw['mode'] ?? self::STAGE_FULL );
            if ( $stage_mode !== self::STAGE_STAGED ) {
                $stage_mode = self::STAGE_FULL;
            }
            $reg['intents'][ $k ]['stage'] = [
                'mode'     => $stage_mode,
                'user_ids' => self::sanitize_user_id_list( (array) ( $stage_raw['user_ids'] ?? [] ) ),
                'since'    => (string) ( $stage_raw['since'] ?? '' ),
            ];
        }

        // history 長すぎたら切る
        if ( count( $reg['history'] ) > self::HISTORY_LIMIT ) {
            $reg['history'] = array_slice( $reg['history'], 0, self::HISTORY_LIMIT );
        }
        return $reg;
    }

    /**
     * 現 intents を history に退避する（in-place）。
     */
    private static function snapshot_to_history( array &$reg, string $reason ): void {
        if ( empty( $reg['intents'] ) ) {
            return;
        }
        $entry = [
            'version'       => (string) ( $reg['active_version'] ?? '' ),
            'snapshot'      => [ 'intents' => $reg['intents'] ],
            'retired_at'    => wp_date( 'Y-m-d H:i:s' ),
            'retire_reason' => $reason,
        ];
        array_unshift( $reg['history'], $entry );
        if ( count( $reg['history'] ) > self::HISTORY_LIMIT ) {
            $reg['history'] = array_slice( $reg['history'], 0, self::HISTORY_LIMIT );
        }
    }

    /**
     * 次 active_version を生成する。
     *
     * 形式: vYYYYMMDD-a
     * 同日内に既に存在する場合は a→b→c と進める。
     */
    private static function generate_next_version( array $reg ): string {
        $date    = wp_date( 'Ymd' );
        $prefix  = 'v' . $date . '-';
        $used    = [];

        $cur = (string) ( $reg['active_version'] ?? '' );
        if ( $cur !== '' ) { $used[] = $cur; }
        foreach ( $reg['history'] ?? [] as $h ) {
            $used[] = (string) ( $h['version'] ?? '' );
        }
        foreach ( $reg['intents'] ?? [] as $i ) {
            $used[] = (string) ( $i['version'] ?? '' );
        }

        foreach ( range( 'a', 'z' ) as $suffix ) {
            $candidate = $prefix . $suffix;
            if ( ! in_array( $candidate, $used, true ) ) {
                return $candidate;
            }
        }
        // 26 超えはまず起きないが、秒精度でフォールバック
        return $prefix . 'z' . wp_date( 'His' );
    }

    /**
     * /tmp/gcrev_qa_registry_debug.log に追記する。
     * KUSANAGI では error_log() が効かないため file_put_contents パターン（CLAUDE.md §7.1）。
     */
    private static function log( string $event, array $ctx = [] ): void {
        $line = sprintf(
            "%s event=%s %s\n",
            date( 'Y-m-d H:i:s' ),
            $event,
            wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE )
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents( '/tmp/gcrev_qa_registry_debug.log', $line, FILE_APPEND );
    }

    // =========================================================
    // Test hook — tests cache flush
    // =========================================================

    public static function flush_cache(): void {
        self::$cache = null;
    }
}
