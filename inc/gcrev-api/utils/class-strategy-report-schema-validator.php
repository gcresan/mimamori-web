<?php
// FILE: inc/gcrev-api/utils/class-strategy-report-schema-validator.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Report_Schema_Validator' ) ) { return; }

/**
 * Gcrev_Strategy_Report_Schema_Validator
 *
 * AI（Gemini）が返す戦略レポートJSONのスキーマ検証 + 正規化。
 * Gcrev_Strategy_Report_Service::generate() 内で必ず通す。
 *
 * 期待スキーマ (schema_version "1.0"):
 *   {
 *     "alignment_score": int,           // 0-100
 *     "sections": {
 *        "conclusion":      string,
 *        "alignment":       [ {topic, expected, actual, gap}, ... ],
 *        "issues":          [ {title, evidence, severity: high|mid|low}, ... ] (1〜3件),
 *        "causes":          [ {issue_ref: int, cause: string}, ... ],
 *        "actions":         [ {title, owner, horizon: this_month|next_month|quarter, kpi}, ... ],
 *        "this_month_todos":[ {title, due_date, kpi}, ... ]
 *     }
 *   }
 *
 * 設計書: docs/strategy-report-design.md §4.1
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Report_Schema_Validator {

    public const SCHEMA_VERSION = '1.0';

    /**
     * @return array { valid:bool, errors:string[], normalized:array }
     */
    public static function validate( array $raw ): array {
        $errors     = [];
        $normalized = self::normalize( $raw );

        // alignment_score
        $score = $normalized['alignment_score'];
        if ( $score < 0 || $score > 100 ) {
            $errors[] = 'alignment_score は 0-100 の整数である必要があります。';
        }

        $sec = $normalized['sections'];
        if ( $sec['conclusion'] === '' ) {
            $errors[] = 'sections.conclusion は必須です。';
        }
        if ( empty( $sec['issues'] ) ) {
            $errors[] = 'sections.issues は最低1件必要です。';
        } elseif ( count( $sec['issues'] ) > 3 ) {
            $errors[] = 'sections.issues は3件以下にしてください。';
        }
        if ( empty( $sec['actions'] ) ) {
            $errors[] = 'sections.actions は最低1件必要です。';
        }

        foreach ( $sec['issues'] as $i => $issue ) {
            if ( $issue['title'] === '' ) {
                $errors[] = "sections.issues[{$i}].title が空です。";
            }
            if ( ! in_array( $issue['severity'], [ 'high', 'mid', 'low' ], true ) ) {
                $errors[] = "sections.issues[{$i}].severity は high/mid/low のいずれか。";
            }
        }
        foreach ( $sec['causes'] as $i => $cause ) {
            $ref = $cause['issue_ref'];
            if ( $ref < 0 || $ref >= count( $sec['issues'] ) ) {
                $errors[] = "sections.causes[{$i}].issue_ref が範囲外です（参照先 issues なし）。";
            }
        }
        foreach ( $sec['actions'] as $i => $a ) {
            if ( $a['title'] === '' ) {
                $errors[] = "sections.actions[{$i}].title が空です。";
            }
            if ( ! in_array( $a['horizon'], [ 'this_month', 'next_month', 'quarter' ], true ) ) {
                $errors[] = "sections.actions[{$i}].horizon は this_month/next_month/quarter のいずれか。";
            }
        }

        return [
            'valid'      => empty( $errors ),
            'errors'     => $errors,
            'normalized' => $normalized,
        ];
    }

    public static function normalize( array $raw ): array {
        $sections_raw = is_array( $raw['sections'] ?? null ) ? $raw['sections'] : [];

        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'alignment_score' => isset( $raw['alignment_score'] ) ? (int) $raw['alignment_score'] : 0,
            'sections' => [
                'conclusion'       => self::str( $sections_raw['conclusion'] ?? '' ),
                'alignment'        => self::normalize_alignment( $sections_raw['alignment'] ?? [] ),
                'issues'           => self::normalize_issues( $sections_raw['issues'] ?? [] ),
                'causes'           => self::normalize_causes( $sections_raw['causes'] ?? [] ),
                'actions'          => self::normalize_actions( $sections_raw['actions'] ?? [] ),
                'this_month_todos' => self::normalize_todos( $sections_raw['this_month_todos'] ?? [] ),
            ],
        ];
    }

    private static function str( $v ): string {
        return is_string( $v ) ? trim( $v ) : '';
    }

    private static function normalize_alignment( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $r ) {
            if ( ! is_array( $r ) ) continue;
            $out[] = [
                'topic'    => self::str( $r['topic']    ?? '' ),
                'expected' => self::str( $r['expected'] ?? '' ),
                'actual'   => self::str( $r['actual']   ?? '' ),
                'gap'      => self::str( $r['gap']      ?? '' ),
            ];
        }
        return $out;
    }

    private static function normalize_issues( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $r ) {
            if ( ! is_array( $r ) ) continue;
            $sev = is_string( $r['severity'] ?? null ) ? strtolower( trim( $r['severity'] ) ) : 'mid';
            if ( ! in_array( $sev, [ 'high', 'mid', 'low' ], true ) ) { $sev = 'mid'; }
            $out[] = [
                'title'    => self::str( $r['title']    ?? '' ),
                'evidence' => self::str( $r['evidence'] ?? '' ),
                'severity' => $sev,
            ];
        }
        return $out;
    }

    private static function normalize_causes( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $r ) {
            if ( ! is_array( $r ) ) continue;
            $out[] = [
                'issue_ref' => isset( $r['issue_ref'] ) ? (int) $r['issue_ref'] : 0,
                'cause'     => self::str( $r['cause'] ?? '' ),
            ];
        }
        return $out;
    }

    private static function normalize_actions( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $r ) {
            if ( ! is_array( $r ) ) continue;
            $h = is_string( $r['horizon'] ?? null ) ? strtolower( trim( $r['horizon'] ) ) : 'this_month';
            if ( ! in_array( $h, [ 'this_month', 'next_month', 'quarter' ], true ) ) { $h = 'this_month'; }
            $out[] = [
                'title'   => self::str( $r['title']   ?? '' ),
                'owner'   => self::str( $r['owner']   ?? '' ),
                'horizon' => $h,
                'kpi'     => self::str( $r['kpi']     ?? '' ),
            ];
        }
        return $out;
    }

    private static function normalize_todos( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $r ) {
            if ( ! is_array( $r ) ) continue;
            $due = self::str( $r['due_date'] ?? '' );
            if ( $due !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) {
                $due = '';
            }
            $out[] = [
                'title'    => self::str( $r['title'] ?? '' ),
                'due_date' => $due,
                'kpi'      => self::str( $r['kpi']   ?? '' ),
            ];
        }
        return $out;
    }
}
