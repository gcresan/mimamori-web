<?php
// inc/gcrev-api/utils/class-ai-json-parser.php

if (!defined('ABSPATH')) exit;

class GCREV_AI_JSON_Parser {

    /**
     * Parse AI output text and return items array or null.
     *
     * @param string $text AI raw output.
     * @return array|null
     */
    public static function parse_items($text) {
        if (!is_string($text) || trim($text) === '') return null;

        $json = self::extract_first_json_array($text);
        if ($json === null) return null;

        $data = json_decode($json, true);

        if (!is_array($data)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[GCREV_AI_JSON_Parser] json_decode failed: ' . json_last_error_msg());
            }
            return null;
        }

        // Validate & normalize
        $items = [];
        foreach ($data as $item) {
            if (!is_array($item)) continue;

            $type   = isset($item['type']) ? (string)$item['type'] : '';
            $title  = isset($item['title']) ? (string)$item['title'] : '';
            $body   = isset($item['body']) ? (string)$item['body'] : '';
            $action = isset($item['action']) ? (string)$item['action'] : '';

            $type = in_array($type, ['good', 'issue'], true) ? $type : 'issue';

            // Skip empty rows safely (but keep if title/body exists)
            if (trim($title) === '' && trim($body) === '' && trim($action) === '') continue;

            $items[] = [
                'type'   => $type,
                'title'  => $title,
                'body'   => $body,
                'action' => $action,
            ];
        }

        return !empty($items) ? $items : null;
    }

    /**
     * Extract the first valid JSON array from mixed text.
     * Handles ```json fences and extra text before/after.
     *
     * @param string $text
     * @return string|null
     */
    private static function extract_first_json_array($text) {
        $t = trim($text);

        // Remove common code fences but keep content
        // e.g. ```json ... ``` or ``` ... ```
        $t = preg_replace('/```(?:json)?\s*/i', '', $t);
        $t = str_replace('```', '', $t);

        $len = strlen($t);
        $start = strpos($t, '[');
        if ($start === false) return null;

        // Scan for matching closing bracket of the first JSON array
        $depth = 0;
        $in_string = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $t[$i];

            if ($in_string) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $in_string = false;
                }
                continue;
            } else {
                if ($ch === '"') {
                    $in_string = true;
                    continue;
                }
                if ($ch === '[') {
                    $depth++;
                    continue;
                }
                if ($ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $json = substr($t, $start, $i - $start + 1);
                        $json = trim($json);

                        // Quick sanity: must start with [ and end with ]
                        if (strlen($json) >= 2 && $json[0] === '[' && substr($json, -1) === ']') {
                            return $json;
                        }
                        return null;
                    }
                }
            }
        }

        return null;
    }
}
