<?php
// FILE: inc/gcrev-api/modules/class-strategy-prompt-builder.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Prompt_Builder' ) ) { return; }

/**
 * Gcrev_Strategy_Prompt_Builder
 *
 * 戦略JSON + 集約データ → Gemini 用プロンプト文字列を組み立てる。
 * 出力は「Gemini に直接渡せる単一プロンプト」。System/User の分離はしない。
 *
 * 設計書: docs/strategy-report-design.md §4.1
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Prompt_Builder {

    /**
     * @param array $strategy_json Schema_Validator 正規化済みの戦略
     * @param array $aggregated    Aggregator::collect() の戻り値
     */
    public function build( array $strategy_json, array $aggregated ): string {
        $strategy_text = $this->format_strategy_for_prompt( $strategy_json );
        $data_text     = $this->format_aggregated_for_prompt( $aggregated );
        $period        = $aggregated['period'] ?? [];
        $year_month    = (string) ( $period['year_month'] ?? '' );

        $output_schema = $this->output_schema_text();

        return <<<PROMPT
あなたはWebマーケティングコンサルタントです。中小企業の経営者に対し、
戦略と現状データのズレを指摘し、具体的なアクションを提示します。

# 制約
- 出力は必ず JSON のみ。前後の説明文・コードフェンス（```）は禁止。
- スキーマに無いキーは出さない。
- 抽象的な表現（「強化する」「最適化する」等の単独使用）は禁止。
  必ず「何を、どこで、何のKPIを動かすために」を含める。
- 経営者が読むので専門用語は最小限に。必要な場合は1行で言い換えを添える。
- issues は最大3件。原因（causes）は各 issue に1対1で対応（issue_ref = issues配列のインデックス）。
- alignment_score は 0-100 の整数。戦略と実態の整合度。
  100=完全一致、50=半分実行、0=戦略と逆方向に動いている。
- this_month_todos は3〜5件。due_date は YYYY-MM-DD。
- データ不足の場合でも「データ不足」を1件目の issue にして必ず1件以上返す。

# 戦略
{$strategy_text}

# 対象期間
{$year_month}

# 集約データ（GA4 / GSC）
{$data_text}

# 出力スキーマ（必ずこの形）
{$output_schema}
PROMPT;
    }

    private function format_strategy_for_prompt( array $s ): string {
        $meta = $s['meta'] ?? [];
        $client_name = $meta['client_name'] ?? '';
        $effective_from = $meta['effective_from'] ?? '';

        $lines = [];
        $lines[] = "- クライアント: {$client_name}";
        $lines[] = "- 有効開始: {$effective_from}";
        $lines[] = '- ターゲット: ' . self::clean( $s['target'] ?? '' );
        $lines[] = '- 戦略方針: ' . self::clean( $s['strategy'] ?? '' );
        $lines[] = '- コンバージョン導線: ' . self::clean( $s['conversion_path'] ?? '' );

        $issues = is_array( $s['issues'] ?? null ) ? $s['issues'] : [];
        if ( $issues ) {
            $lines[] = '- 課題:';
            foreach ( $issues as $i ) {
                $lines[] = '  - ' . self::clean( (string) $i );
            }
        }

        $vp = is_array( $s['value_proposition'] ?? null ) ? $s['value_proposition'] : [];
        if ( $vp ) {
            $lines[] = '- 差別化要素:';
            foreach ( $vp as $v ) {
                $lines[] = '  - ' . self::clean( (string) $v );
            }
        }

        // 拡張: competitors
        $competitors = is_array( $s['competitors'] ?? null ) ? $s['competitors'] : [];
        if ( $competitors ) {
            $lines[] = '- 競合:';
            foreach ( $competitors as $c ) {
                $name  = (string) ( $c['name'] ?? '' );
                $type  = (string) ( $c['type'] ?? '' );
                $notes = (string) ( $c['notes'] ?? '' );
                $line  = '  - ' . self::clean( $name );
                if ( $type !== '' )  $line .= " (type: {$type})";
                if ( $notes !== '' ) $line .= ' / ' . self::clean( $notes );
                $lines[] = $line;
            }
        }

        return implode( "\n", $lines );
    }

    private function format_aggregated_for_prompt( array $agg ): string {
        $cur  = $agg['ga4']['current']  ?? [];
        $prev = $agg['ga4']['previous'] ?? [];
        $deltas = $agg['ga4']['deltas'] ?? [];
        $gsc_total = $agg['gsc']['total'] ?? [];
        $gsc_kw    = $agg['gsc']['top_keywords'] ?? [];
        $period = $agg['period'] ?? [];

        $lines = [];
        $lines[] = '## 期間';
        $lines[] = '- 当月: ' . ( $period['from']      ?? '' ) . ' 〜 ' . ( $period['to']      ?? '' );
        $lines[] = '- 前月: ' . ( $period['prev_from'] ?? '' ) . ' 〜 ' . ( $period['prev_to'] ?? '' );

        $lines[] = '';
        $lines[] = '## GA4 主要指標（当月）';
        $lines[] = '- セッション: ' . ( $cur['sessions']    ?? '0' ) . ' / ユーザー: ' . ( $cur['users']  ?? '0' ) . ' (新規 ' . ( $cur['newUsers'] ?? '0' ) . ' / 再訪 ' . ( $cur['returningUsers'] ?? '0' ) . ')';
        $lines[] = '- ページビュー: ' . ( $cur['pageViews'] ?? '0' ) . ' / 平均滞在(秒): ' . ( $cur['avgDuration'] ?? '0' );
        $lines[] = '- エンゲージ率: ' . ( $cur['engagementRate'] ?? '0%' ) . ' / エンゲージセッション: ' . ( $cur['engagedSessions'] ?? '0' );
        $lines[] = '- コンバージョン(GA4 keyEvents): ' . ( $cur['conversions'] ?? '0' );

        $lines[] = '';
        $lines[] = '## 前月比（生値）';
        foreach ( $deltas as $k => $d ) {
            $pct = $d['pct'] === null ? 'N/A' : ( ( $d['pct'] >= 0 ? '+' : '' ) . $d['pct'] . '%' );
            $diff = ( $d['diff'] >= 0 ? '+' : '' ) . $d['diff'];
            $lines[] = "- {$k}: {$d['previous']} → {$d['current']} ({$diff}, {$pct})";
        }

        $lines[] = '';
        $lines[] = '## GSC 合計（当月）';
        $lines[] = '- 表示回数: ' . ( $gsc_total['impressions'] ?? '0' ) . ' / クリック: ' . ( $gsc_total['clicks'] ?? '0' ) . ' / CTR: ' . ( $gsc_total['ctr'] ?? '0%' );

        if ( $gsc_kw ) {
            $lines[] = '';
            $lines[] = '## GSC 上位キーワード（最大20）';
            foreach ( $gsc_kw as $k ) {
                $line = '- ' . self::clean( (string) ( $k['query'] ?? '' ) )
                    . ' (imp: ' . (int) ( $k['impressions'] ?? 0 )
                    . ' / clk: ' . (int) ( $k['clicks'] ?? 0 )
                    . ' / pos: ' . self::clean( (string) ( $k['position'] ?? '' ) ) . ')';
                $lines[] = $line;
            }
        }

        $warnings = $agg['warnings'] ?? [];
        if ( $warnings ) {
            $lines[] = '';
            $lines[] = '## データ取得警告';
            foreach ( $warnings as $w ) {
                $lines[] = '- ' . self::clean( (string) $w );
            }
        }

        return implode( "\n", $lines );
    }

    private function output_schema_text(): string {
        return <<<JSON
{
  "schema_version": "1.0",
  "alignment_score": 0,
  "sections": {
    "conclusion": "",
    "alignment": [
      { "topic": "", "expected": "", "actual": "", "gap": "" }
    ],
    "issues": [
      { "title": "", "evidence": "", "severity": "high|mid|low" }
    ],
    "causes": [
      { "issue_ref": 0, "cause": "" }
    ],
    "actions": [
      { "title": "", "owner": "", "horizon": "this_month|next_month|quarter", "kpi": "" }
    ],
    "this_month_todos": [
      { "title": "", "due_date": "YYYY-MM-DD", "kpi": "" }
    ]
  }
}
JSON;
    }

    private static function clean( string $s ): string {
        $s = trim( $s );
        // 改行は単スペースに圧縮（プロンプト中の構造を壊さないため）
        $s = preg_replace( '/\s+/u', ' ', $s ) ?? $s;
        return $s;
    }
}
