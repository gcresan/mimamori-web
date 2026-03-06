<?php
// FILE: inc/gcrev-api/utils/class-qa-prompt-tuner.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Prompt_Tuner
 *
 * 改善アクションに基づいてプロンプト・パラメータのオーバーライドを生成する。
 * 安全な範囲内でのみ調整し、累積変更を追跡する。
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Mimamori_QA_Prompt_Tuner {

    /** temperature 最小値 */
    private const TEMP_MIN = 0.0;

    /** temperature 最大値 */
    private const TEMP_MAX = 1.0;

    /** temperature デフォルト（OpenAI Responses APIの既定値） */
    private const TEMP_DEFAULT = 1.0;

    /** max_output_tokens の最大値 */
    private const MAX_TOKENS_CAP = 4096;

    /** max_output_tokens のデフォルト */
    private const MAX_TOKENS_DEFAULT = 2048;

    /**
     * 改善アクション配列から新しいオーバーライドを生成する。
     *
     * @param  array $actions     Diagnosis::diagnose() の出力
     * @param  array $current     現在のオーバーライド（前回からの累積）
     * @return array 新しいオーバーライド + 変更ログ
     */
    public function tune( array $actions, array $current = [] ): array {
        $overrides = $current;
        $changes   = [];

        // 処理済みアクション追跡（重複防止）
        $processed = [];

        foreach ( $actions as $action ) {
            $act = $action['action'] ?? '';
            if ( isset( $processed[ $act ] ) ) continue;
            $processed[ $act ] = true;

            switch ( $act ) {
                case 'lower_temperature':
                    $result = $this->apply_lower_temperature( $overrides, $action['params'] ?? [] );
                    break;

                case 'add_honesty_guard':
                    $result = $this->apply_honesty_guard( $overrides );
                    break;

                case 'add_data_hint':
                    $result = $this->apply_data_hint( $overrides );
                    break;

                case 'add_context':
                    $result = $this->apply_context_boost( $overrides );
                    break;

                case 'enforce_period':
                    $result = $this->apply_period_guard( $overrides );
                    break;

                case 'add_format_guard':
                    $result = $this->apply_format_guard( $overrides );
                    break;

                case 'increase_max_tokens':
                    $result = $this->apply_increase_max_tokens( $overrides, $action['params'] ?? [] );
                    break;

                case 'add_jargon_guard':
                    $result = $this->apply_jargon_guard( $overrides, $action['params'] ?? [] );
                    break;

                default:
                    continue 2;
            }

            if ( $result !== null ) {
                $overrides = $result['overrides'];
                $changes[] = $result['change'];
            }
        }

        return [
            'overrides' => $overrides,
            'changes'   => $changes,
        ];
    }

    /**
     * 現在のオーバーライドの概要テキストを返す。
     *
     * @param  array $overrides オーバーライド配列
     * @return string
     */
    public function summarize( array $overrides ): string {
        $parts = [];

        if ( isset( $overrides['temperature'] ) ) {
            $parts[] = 'temp:' . $overrides['temperature'];
        }
        if ( isset( $overrides['max_output_tokens'] ) && $overrides['max_output_tokens'] !== self::MAX_TOKENS_DEFAULT ) {
            $parts[] = 'max_tokens:' . $overrides['max_output_tokens'];
        }
        if ( ! empty( $overrides['context_boost'] ) ) {
            $parts[] = 'context_boost';
        }
        if ( ! empty( $overrides['prompt_addendum'] ) ) {
            $guards = [];
            if ( str_contains( $overrides['prompt_addendum'], '数値は必ず' ) ) $guards[] = 'honesty_guard';
            if ( str_contains( $overrides['prompt_addendum'], 'データが提供されている場合' ) ) $guards[] = 'data_hint';
            if ( str_contains( $overrides['prompt_addendum'], '期間' ) ) $guards[] = 'period_guard';
            if ( str_contains( $overrides['prompt_addendum'], 'JSON' ) ) $guards[] = 'format_guard';
            if ( str_contains( $overrides['prompt_addendum'], '以下の用語は' ) ) $guards[] = 'jargon_guard';
            $parts[] = implode( '+', $guards );
        }

        return implode( ', ', $parts ) ?: '(none)';
    }

    // --- アクション適用メソッド ---

    private function apply_lower_temperature( array $overrides, array $params ): ?array {
        $step    = (float) ( $params['step'] ?? 0.1 );
        $current = (float) ( $overrides['temperature'] ?? self::TEMP_DEFAULT );
        $new_val = max( self::TEMP_MIN, $current - $step );

        if ( abs( $new_val - $current ) < 0.01 ) return null;

        $overrides['temperature'] = round( $new_val, 1 );

        return [
            'overrides' => $overrides,
            'change'    => "temperature: {$current} → {$overrides['temperature']}",
        ];
    }

    private function apply_honesty_guard( array $overrides ): ?array {
        $guard = "【重要】数値は必ず提供されたデータに基づいて回答してください。" .
                 "データがない場合は「現在のデータからは確認できません」と正直に回答し、" .
                 "推測や仮定の数値を提示しないでください。";

        return $this->append_prompt( $overrides, $guard, 'honesty_guard' );
    }

    private function apply_data_hint( array $overrides ): ?array {
        $guard = "【重要】データが提供されている場合は、必ずそのデータを使って具体的な数値とともに回答してください。" .
                 "「確認できません」「取得できません」とは回答しないでください。" .
                 "提供されたコンテキスト内のデータを積極的に活用してください。";

        return $this->append_prompt( $overrides, $guard, 'data_hint' );
    }

    private function apply_context_boost( array $overrides ): ?array {
        if ( ! empty( $overrides['context_boost'] ) ) return null;

        $overrides['context_boost'] = true;

        return [
            'overrides' => $overrides,
            'change'    => 'context_boost: OFF → ON (クエリ取得件数1.5倍)',
        ];
    }

    private function apply_period_guard( array $overrides ): ?array {
        $guard = "【重要】回答には必ず対象期間を明記してください。" .
                 "「○年○月」や「先月」「今月」など、どの期間のデータかを明確にしてください。" .
                 "比較の場合は両方の期間を明記してください。";

        return $this->append_prompt( $overrides, $guard, 'period_guard' );
    }

    private function apply_format_guard( array $overrides ): ?array {
        $guard = "【重要】回答は必ず正しいJSON形式で出力してください。" .
                 "typeは\"talk\"または\"advice\"のいずれかにしてください。" .
                 "adviceの場合、sectionsは最大3つ、各セクションのitemsは最大3つにしてください。" .
                 "JSONの途中で切れないよう、簡潔に回答してください。";

        return $this->append_prompt( $overrides, $guard, 'format_guard' );
    }

    private function apply_increase_max_tokens( array $overrides, array $params ): ?array {
        $step    = (int) ( $params['step'] ?? 1024 );
        $current = (int) ( $overrides['max_output_tokens'] ?? self::MAX_TOKENS_DEFAULT );
        $new_val = min( self::MAX_TOKENS_CAP, $current + $step );

        if ( $new_val <= $current ) return null;

        $overrides['max_output_tokens'] = $new_val;

        return [
            'overrides' => $overrides,
            'change'    => "max_output_tokens: {$current} → {$new_val}",
        ];
    }

    private function apply_jargon_guard( array $overrides, array $params ): ?array {
        $terms = $params['terms'] ?? '';
        if ( $terms === '' ) return null;

        $guard = "【重要】以下の用語は使用禁止です。必ず平易な日本語に言い換えてください: {$terms}。" .
                 "例: セッション→訪問、CV→お問い合わせ、PV→ページ閲覧数、" .
                 "インプレッション→表示回数、コンバージョン→成果、オーガニック→自然検索。";

        return $this->append_prompt( $overrides, $guard, 'jargon_guard' );
    }

    /**
     * プロンプト補遺に追記する（重複防止付き）。
     */
    private function append_prompt( array $overrides, string $text, string $label ): ?array {
        $current = $overrides['prompt_addendum'] ?? '';

        // 重複チェック: 同一ガードが既に含まれていれば追加しない
        if ( str_contains( $current, mb_substr( $text, 0, 30 ) ) ) {
            return null;
        }

        $overrides['prompt_addendum'] = trim( $current . "\n\n" . $text );

        return [
            'overrides' => $overrides,
            'change'    => "prompt_addendum: +{$label}",
        ];
    }
}
