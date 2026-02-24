<?php
/**
 * Gcrev_Html_Extractor
 *
 * レポートHTMLからセクション別テキスト/リスト/innerHTMLを抽出する静的ユーティリティ。
 * Step4.5 で Gcrev_Highlights から昇格。Highlights / Monthly_Report_Service 等が共有利用する。
 *
 * @package GCREV_INSIGHT
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Html_Extractor') ) { return; }

class Gcrev_Html_Extractor {

    /**
     * HTMLからテキスト抽出
     *
     * @param string $html       レポートHTML
     * @param string $class_name 対象divのクラス名
     * @return string 抽出テキスト
     */
    public static function extract_text(string $html, string $class_name): string {
        $pattern = '/<div[^>]*class="[^"]*' . preg_quote($class_name, '/') . '[^"]*"[^>]*>(.*?)<\/div>/is';
        if (preg_match($pattern, $html, $matches)) {
            $text = strip_tags($matches[1]);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/', ' ', $text));
            $text = str_replace('**', '', $text);
            $text = preg_replace('/\d+;"?>/', '', $text);
            return $text;
        }
        return '';
    }

    /**
     * HTMLからリスト抽出
     *
     * @param string $html       レポートHTML
     * @param string $class_name 対象divのクラス名
     * @return array 抽出リスト（文字列配列 or 構造化配列）
     */
    public static function extract_list(string $html, string $class_name): array {
        $items = [];

        $inner = self::extract_div_inner_html($html, $class_name);

        if ($inner === '') {
            if ($class_name === 'actions') {
                error_log("[GCREV] extract_list_from_html: Extracting actions section");
                error_log("[GCREV] ❌ ERROR: Actions section not found in HTML (DOM)");
            }
            return [];
        }

        // actions専用：DOMで action-item を抽出し、構造化して返す
        if ($class_name === 'actions') {
            error_log("[GCREV] extract_list_from_html: Extracting actions section");
            error_log("[GCREV] actions section HTML length: " . strlen($inner));
            error_log("[GCREV] actions section HTML preview: " . mb_substr($inner, 0, 300));

            if (stripos($inner, 'AI生成に失敗') !== false) {
                error_log("[GCREV] ERROR: Actions contains failure message");
                return [];
            }
            if (strlen($inner) < 200) {
                error_log("[GCREV] ERROR: Actions HTML too short (< 200 chars)");
                return [];
            }

            libxml_use_internal_errors(true);
            $doc = new \DOMDocument();

            $wrapped = '<!doctype html><html><meta charset="utf-8"><body><div class="actions">' . $inner . '</div></body></html>';
            $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $xpath = new \DOMXPath($doc);
            $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' action-item ')]");

            if (!$nodes || $nodes->length === 0) {
                error_log("[GCREV] Pattern action-item failed (DOM): 0 nodes");
                error_log("[GCREV] Total actions extracted: 0");
                return [];
            }

            foreach ($nodes as $node) {
                /** @var \DOMElement $node */
                $prioNode  = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' action-priority ')]", $node)->item(0);
                $titleNode = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' action-title ')]", $node)->item(0);
                $descNode  = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' action-description ')]", $node)->item(0);

                $prio  = $prioNode  ? trim(preg_replace('/\s+/u', ' ', $prioNode->textContent))  : '';
                $title = $titleNode ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent)) : '';
                $desc  = $descNode  ? trim(preg_replace('/\s+/u', ' ', $descNode->textContent))  : '';

                if ($title !== '' && $desc !== '') {
                    $items[] = [
                        'priority'    => str_replace('**', '', $prio),
                        'title'       => str_replace('**', '', $title),
                        'description' => str_replace('**', '', $desc),
                    ];
                }
            }

            error_log("[GCREV] Total actions extracted: " . count($items));
            return $items;
        }

        // good-points / improvement-points：JSON構造化を優先、フォールバックで<li>抽出
        // --- JSON抽出を試行 ---
        if ( class_exists('GCREV_AI_JSON_Parser')
             && in_array($class_name, ['good-points', 'improvement-points'], true) ) {
            // innerHTMLからテキストを取り出してJSONパースを試みる
            $raw_text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $json_items = GCREV_AI_JSON_Parser::parse_items($raw_text);
            if (is_array($json_items) && !empty($json_items)) {
                return $json_items;  // 構造化配列 [{type, title, body, action}, ...]
            }
        }

        // --- フォールバック：<li>からHTML構造を活用して title/body/action を分離 ---
        if ( in_array($class_name, ['good-points', 'improvement-points'], true) ) {
            $items = self::extract_structured_li_items($inner, $class_name);
            if ( ! empty($items) ) {
                return $items;
            }
        }

        // --- 最終フォールバック：プレーンテキスト配列（構造化不可のとき） ---
        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $inner, $li_matches)) {
            foreach ($li_matches[1] as $li) {
                $text = html_entity_decode(strip_tags($li), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim(preg_replace('/\s+/u', ' ', $text));
                $text = str_replace('**', '', $text);
                if ($text !== '') $items[] = $text;
            }
        }

        return $items;
    }

    /**
     * <li> 内のHTML構造（<strong>, <br>）を活用して title/body/action を分離する。
     *
     * AI出力パターン:
     *   good-points:        <li><strong>見出し</strong>説明文...</li>
     *   improvement-points: <li><strong>見出し</strong><br>説明文<br><strong>対策：</strong>具体策</li>
     *
     * @param  string $inner_html   セクションの innerHTML
     * @param  string $class_name   'good-points' | 'improvement-points'
     * @return array  構造化配列 [{type, title, body, action}, ...]
     */
    private static function extract_structured_li_items(string $inner_html, string $class_name): array {
        if ( ! preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $inner_html, $li_matches) ) {
            return [];
        }

        $default_type = ($class_name === 'good-points') ? 'good' : 'issue';
        $items = [];

        foreach ($li_matches[1] as $li_html) {
            $title  = '';
            $body   = '';
            $action = '';

            // --- 対策ブロック抽出 ---
            // パターン: <strong>対策：</strong>具体策  or  <strong>対策：具体策</strong>  or  対策：具体策
            $li_work = $li_html;
            if ( preg_match('/<strong[^>]*>\s*対策[：:]\s*<\/strong>\s*(.+)$/uis', $li_work, $am) ) {
                // <strong>対策：</strong>の後ろ全部が action
                $action  = trim(self::clean_text(strip_tags($am[1])));
                $li_work = trim(preg_replace('/<strong[^>]*>\s*対策[：:]\s*<\/strong>.*$/uis', '', $li_work));
            } elseif ( preg_match('/<strong[^>]*>\s*対策[：:]\s*(.+?)<\/strong>/uis', $li_work, $am) ) {
                // <strong>対策：具体策</strong> 形式
                $action  = trim(self::clean_text($am[1]));
                $li_work = trim(preg_replace('/<strong[^>]*>\s*対策[：:].*?<\/strong>/uis', '', $li_work));
            } elseif ( preg_match('/(?:<br\s*\/?>)+\s*対策[：:]\s*(.+)$/uis', $li_work, $am) ) {
                // <br>対策：具体策（strongなし）
                $action  = trim(self::clean_text(strip_tags($am[1])));
                $li_work = trim(preg_replace('/(?:<br\s*\/?>)+\s*対策[：:].*$/uis', '', $li_work));
            }

            // --- 見出し抽出（先頭の<strong>） ---
            if ( preg_match('/^\s*<strong[^>]*>(.+?)<\/strong>/uis', $li_work, $hm) ) {
                $title = trim(self::clean_text($hm[1]));
                // <strong>以降を本文として取り出す
                $remainder = trim(preg_replace('/^\s*<strong[^>]*>.+?<\/strong>\s*/uis', '', $li_work, 1));
                // 先頭の <br> を除去
                $remainder = preg_replace('/^\s*(?:<br\s*\/?>)+\s*/uis', '', $remainder);
                $body = trim(self::clean_text(strip_tags($remainder)));
            } else {
                // <strong> がない場合：全文をテキスト化して先頭句を title にする
                $plain = trim(self::clean_text(strip_tags($li_work)));
                if ( preg_match('/^(.{2,80}?[！!。：:])\s*(.*)$/us', $plain, $pm) ) {
                    $title = trim($pm[1]);
                    $body  = trim($pm[2]);
                } else {
                    $title = $plain;
                }
            }

            if ($title === '') continue;

            $items[] = [
                'type'   => $default_type,
                'title'  => $title,
                'body'   => $body,
                'action' => $action,
            ];
        }

        return $items;
    }

    /**
     * テキストのクリーンアップ（エンティティデコード、余分空白除去、アスタリスク除去）
     *
     * @param  string $text
     * @return string
     */
    private static function clean_text(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = str_replace(['**', '*'], '', $text);
        $text = preg_replace('/\d+;"?>/', '', $text);
        return trim($text);
    }

    /**
     * DOMで <div class="... $class_name ..."> の innerHTML を取得する（入れ子divに強い）
     *
     * @param string $html       レポートHTML
     * @param string $class_name 対象divのクラス名
     * @return string innerHTML
     */
    public static function extract_div_inner_html(string $html, string $class_name): string {
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $wrapped = '<!doctype html><html><meta charset="utf-8"><body>' . $html . '</body></html>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new \DOMXPath($doc);
        $query = "//div[contains(concat(' ', normalize-space(@class), ' '), ' {$class_name} ')]";
        $nodes = $xpath->query($query);

        if (!$nodes || $nodes->length === 0) return '';

        /** @var \DOMElement $node */
        $node = $nodes->item(0);

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }
        return trim($inner);
    }
}
