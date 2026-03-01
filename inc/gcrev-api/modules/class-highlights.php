<?php
/**
 * Gcrev_Highlights
 *
 * レポートHTMLから日本語要約フレーズ（ハイライト）を抽出する NLP 系モジュール。
 * Step4 で class-gcrev-api.php から分離。
 *
 * 依存: Gcrev_Config, Gcrev_Area_Detector（静的util）
 *
 * @package GCREV_INSIGHT
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Highlights') ) { return; }

class Gcrev_Highlights {

    private Gcrev_Config $config;

    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    // =========================================================
    // 公開メソッド
    // =========================================================

    /**
     * 保存済み highlights を取得。なければ HTML から動的抽出してキャッシュ保存（旧データ互換）
     *
     * @param int    $post_id  gcrev_report の投稿ID
     * @param string $html     レポートHTML本文
     * @return array ['most_important'=>string, 'top_issue'=>string, 'opportunity'=>string]
     */
    public function get_stored_or_extract_highlights(int $post_id, string $html): array {
        // ① postmeta に保存済みなら即返却
        $stored = get_post_meta($post_id, '_gcrev_highlights_json', true);
        if (!empty($stored)) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded) && !empty($decoded['most_important'])) {
                return $decoded;
            }
        }

        // ② 旧データ互換：HTML から動的抽出して保存（次回以降は①で返る）
        $owner_id = (int) get_post_meta($post_id, '_gcrev_user_id', true);
        $highlights = $this->extract_highlights_from_html($html, $owner_id ?: null);
        update_post_meta($post_id, '_gcrev_highlights_json', wp_json_encode($highlights, JSON_UNESCAPED_UNICODE));
        error_log("[GCREV] Highlights back-filled for post_id={$post_id}");

        return $highlights;
    }

    /**
     * レポートHTMLからハイライト3項目を抽出
     *
     * 【設計方針】
     * AIレポートの長文から「指標名＋変化」の名詞句パターンを直接抽出する。
     * 文を丸ごと取って後から削るのではなく、最初から短い名詞句を拾う。
     *
     * @param string   $html    生成済みレポートHTML
     * @param int|null $user_id ユーザーID
     * @return array ['most_important'=>string, 'top_issue'=>string, 'opportunity'=>string]
     */
    public function extract_highlights_from_html(string $html, ?int $user_id = null): array {

        $defaults = [
            'most_important' => '新規ユーザー獲得',
            'top_issue'      => 'ゴール改善',
            'opportunity'    => '地域施策見直し',
        ];

        if (empty($html)) {
            return $defaults;
        }

        // --- ユーザー設定からターゲットエリアと出力モードを取得 ---
        $target_area = null;
        $is_easy_mode = false;
        if ($user_id) {
            $target_text = (string) get_user_meta($user_id, 'report_target', true);
            $target_area = Gcrev_Area_Detector::detect($target_text);
            $output_mode = (string) get_user_meta($user_id, 'report_output_mode', true);
            $is_easy_mode = ($output_mode === 'easy');
        }

        // --- 各セクションのプレーンテキストを取得 ---
        $summary_text     = $this->clean_for_highlight($this->extract_text_from_html($html, 'summary'));
        $good_text        = $this->clean_for_highlight($this->extract_text_from_html($html, 'good-points'));
        $improvement_text = $this->clean_for_highlight($this->extract_text_from_html($html, 'improvement-points'));
        $actions_text     = $this->clean_for_highlight($this->extract_text_from_html($html, 'actions'));
        $insight_text     = $this->clean_for_highlight($this->extract_text_from_html($html, 'insight-box'));
        $area_text        = $this->clean_for_highlight($this->extract_text_from_html($html, 'area-box'));

        // --- 📈 最重要ポイント：ポジティブ指標名＋変化 ---
        // summaryはサイト全体の話 → 文脈ヒント: 'site_wide'
        $positive_keywords = ['増加', '上昇', '向上', '改善', '獲得', '好調', '達成', '伸び', '成長', '回復'];
        if ($is_easy_mode) {
            // やさしい日本語のポジティブ表現を追加
            $positive_keywords = array_merge($positive_keywords, [
                '増えま', '増えて', '伸びて', '伸びま', 'グンと', 'グッと', 'ドンと',
                '上がって', '上がりま', '良くなって', '嬉しい', '好調', '絶好調',
                '初めて発生', '初めて出',
            ]);
        }
        $most_important = $this->extract_noun_phrase(
            $summary_text . '。' . $good_text,
            $positive_keywords,
            $is_easy_mode,
            'site_wide',
            $target_area
        );

        // --- ⚠️ 最優先課題：ネガティブ指標名＋変化 ---
        // improvement-pointsを優先。area_textにもターゲットエリアの課題が含まれる
        $negative_keywords = ['減少', '低下', '悪化', '下落', '離脱', '不足', '停滞', '課題', '問題'];
        if ($is_easy_mode) {
            // やさしい日本語のネガティブ表現を追加
            $negative_keywords = array_merge($negative_keywords, [
                '減って', '減りま', '下がって', '下がりま', 'あまり来て', 'まだ少な',
                '来ていません', 'つながっていません', 'つながっていない', '心配',
                '発生していません', 'まだない', '0件',
            ]);
        }
        $top_issue = $this->extract_noun_phrase(
            $improvement_text . '。' . $area_text . '。' . $summary_text,
            $negative_keywords,
            $is_easy_mode,
            'issue',
            $target_area
        );

        // --- 🎯 改善機会：施策名の名詞句 ---
        $action_keywords = ['強化', '最適化', '施策', '導入', '見直し', '改善', '対策', '検討', '拡充', '推進', '活用'];
        if ($is_easy_mode) {
            $action_keywords = array_merge($action_keywords, [
                'してみませんか', 'してみましょう', 'おすすめ', '提案', '作戦',
                '貼りましょう', '載せましょう', '書きましょう', '追加しましょう',
            ]);
        }
        $opportunity = $this->extract_noun_phrase(
            $actions_text . '。' . $insight_text,
            $action_keywords,
            $is_easy_mode,
            'action',
            $target_area
        );

        // --- 重複防止: most_important と top_issue が同じ指標の言い換えにならないようにする ---
        if ($most_important !== '' && $top_issue !== '') {
            $top_issue = $this->ensure_no_overlap($most_important, $top_issue);
        }

        // --- 課題連動バリデーション: 抽象的アクションを排除し、課題に対応するアクションで補完 ---
        $resolved_issue = $top_issue !== '' ? $top_issue : $defaults['top_issue'];
        $opportunity = $this->validate_or_derive_action($opportunity, $resolved_issue, $is_easy_mode);

        return [
            'most_important' => $most_important !== '' ? $most_important : $defaults['most_important'],
            'top_issue'      => $top_issue      !== '' ? $top_issue      : $defaults['top_issue'],
            'opportunity'    => $opportunity     !== '' ? $opportunity    : $defaults['opportunity'],
        ];
    }

    // =========================================================
    // ハイライト詳細テキスト生成（ディープダイブ）
    // =========================================================

    /**
     * ハイライト3項目のディープダイブ詳細テキストを生成
     *
     * @param array      $highlights      ['most_important'=>string, 'top_issue'=>string, 'opportunity'=>string]
     * @param array|null $infographic     kpi, breakdown キーを含むインフォグラフィック
     * @param array|null $monthly_report  summary, good_points, improvement_points, next_actions を含む
     * @return array     ['most_important'=>['fact'=>string,'causes'=>string[],'actions'=>string[]], ...]
     */
    public function build_highlight_details(
        array $highlights,
        ?array $infographic = null,
        ?array $monthly_report = null
    ): array {
        if (!$infographic || !isset($infographic['breakdown'])) {
            return [];
        }

        $breakdown = $infographic['breakdown'] ?? [];
        $kpi       = $infographic['kpi'] ?? [];

        return [
            'most_important' => $this->detail_most_important($highlights['most_important'] ?? '', $breakdown, $kpi, $monthly_report),
            'top_issue'      => $this->detail_top_issue($highlights['top_issue'] ?? '', $breakdown, $kpi, $monthly_report),
            'opportunity'    => $this->detail_opportunity($highlights['opportunity'] ?? '', $breakdown, $kpi, $monthly_report),
        ];
    }

    /**
     * 最重要ポイントの詳細テキスト生成
     */
    private function detail_most_important(string $phrase, array $breakdown, array $kpi, ?array $report): array {
        $best = $this->find_extreme_kpi($breakdown, 'positive');

        // --- fact ---
        $fact = '';
        if ($best) {
            $fact = $this->format_kpi_sentence($best, 'positive');
            // 補助: 他にも正方向のKPIがあれば付記
            $others = [];
            foreach ($breakdown as $k => $bd) {
                if ($k === $best['key'] || !is_array($bd)) continue;
                if (($bd['pct'] ?? 0) > 5) {
                    $others[] = ($this->detail_kpi_labels()[$k] ?? $k) . 'も+' . number_format((float)$bd['pct'], 1) . '%増加';
                }
            }
            if ($others) {
                $fact .= ' また、' . implode('、', array_slice($others, 0, 2)) . 'しています。';
            }
        } else {
            $fact = '今月は大きな変動はなく、安定した状態が続いています。';
        }

        // --- causes ---
        $causes = [];
        if ($report && !empty($report['good_points'])) {
            foreach (array_slice($report['good_points'], 0, 3) as $item) {
                $text = $this->summarize_report_item($item);
                if ($text !== '') $causes[] = $text;
            }
        }
        if (count($causes) < 2) {
            $causes = $this->generic_causes_for_kpi($best['key'] ?? 'traffic', 'positive');
        }

        // --- actions ---
        $actions = $this->extract_report_actions($report, 0, 2);
        if (empty($actions)) {
            $actions = $this->generic_actions_for_kpi($best['key'] ?? 'traffic', 'positive');
        }

        return ['fact' => $fact, 'causes' => $causes, 'actions' => $actions];
    }

    /**
     * 最優先課題の詳細テキスト生成
     */
    private function detail_top_issue(string $phrase, array $breakdown, array $kpi, ?array $report): array {
        $worst = $this->find_extreme_kpi($breakdown, 'negative');
        $best  = $this->find_extreme_kpi($breakdown, 'positive');

        // --- fact ---
        $fact = '';
        if ($worst) {
            $fact = $this->format_kpi_sentence($worst, 'negative');
            // ギャップ表現（正のKPIと負のKPIの対比）
            if ($best && ($best['pct'] ?? 0) > 5 && ($worst['pct'] ?? 0) < -3) {
                $best_label  = $this->detail_kpi_labels()[$best['key']] ?? $best['key'];
                $worst_label = $this->detail_kpi_labels()[$worst['key']] ?? $worst['key'];
                $fact .= " {$best_label}は伸びているのに対し、{$worst_label}が追いついていない状況です。";
            }
        } else {
            // 全KPIが横ばい or 正の場合でもissueがある → 一番低い points を探す
            $lowest = $this->find_lowest_points($breakdown);
            if ($lowest) {
                $label = $this->detail_kpi_labels()[$lowest['key']] ?? $lowest['key'];
                $fact = "{$label}のスコアは{$lowest['points']}/{$lowest['max']}で、他の指標に比べて改善余地があります。";
            } else {
                $fact = '全体的には安定していますが、さらに伸ばせる余地がありそうです。';
            }
        }

        // --- causes ---
        $causes = [];
        if ($report && !empty($report['improvement_points'])) {
            foreach (array_slice($report['improvement_points'], 0, 3) as $item) {
                $text = $this->summarize_report_item($item);
                if ($text !== '') $causes[] = $text;
            }
        }
        if (count($causes) < 2) {
            $causes = $this->generic_causes_for_kpi($worst['key'] ?? 'cv', 'negative');
        }

        // --- actions ---
        $actions = $this->extract_report_actions($report, 1, 2);
        if (empty($actions)) {
            $actions = $this->generic_actions_for_kpi($worst['key'] ?? 'cv', 'negative');
        }

        return ['fact' => $fact, 'causes' => $causes, 'actions' => $actions];
    }

    /**
     * ネクストアクションの詳細テキスト生成
     */
    private function detail_opportunity(string $phrase, array $breakdown, array $kpi, ?array $report): array {
        $worst = $this->find_extreme_kpi($breakdown, 'negative');

        // --- fact ---
        $fact = '';
        if ($worst) {
            $label = $this->detail_kpi_labels()[$worst['key']] ?? $worst['key'];
            $curr  = number_format((float)($worst['curr'] ?? 0));
            $prev  = number_format((float)($worst['prev'] ?? 0));
            $fact = "現在の{$label}は{$curr}（前月{$prev}）で、ここを改善すると全体の成果が上がりやすくなります。";
        } else {
            $fact = '今の好調さをさらに伸ばすために、次の一歩を踏み出すのに良いタイミングです。';
        }

        // --- causes (なぜこの施策が有効か) ---
        $causes = [];
        if ($report && !empty($report['consideration'])) {
            $consideration = $report['consideration'];
            // consideration テキストを2-3文に分割
            $sentences = preg_split('/(?<=[。！？])\s*/u', $consideration, -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_slice($sentences, 0, 3) as $s) {
                $s = trim($s);
                if (mb_strlen($s) > 5 && mb_strlen($s) <= 100) {
                    $causes[] = $s;
                }
            }
        }
        if (count($causes) < 2) {
            // next_actions の description から理由を補完
            if ($report && !empty($report['next_actions'])) {
                foreach (array_slice($report['next_actions'], 0, 2) as $act) {
                    if (is_array($act) && !empty($act['description'])) {
                        $desc = $this->truncate_at_sentence(trim($act['description']), 120);
                        if ($desc !== '' && mb_strlen($desc) > 10) {
                            $causes[] = $desc;
                        }
                    }
                }
            }
        }
        if (count($causes) < 2) {
            $causes = ['いちばん効果が出やすいところから手をつけるのがポイントです', '小さな改善でも、続けることで数値に変化が出てきます'];
        }

        // --- actions (具体ステップ) ---
        $actions = $this->extract_report_actions($report, 0, 3);
        if (empty($actions)) {
            $actions = ['まずは今のサイトの状態をざっと確認してみる', '気になるところを1つだけ選んで手を入れてみる', '1〜2週間後にもう一度数値を見て変化を確かめる'];
        }

        return ['fact' => $fact, 'causes' => $causes, 'actions' => $actions];
    }

    // --- 詳細テキスト用 private ヘルパー ---

    /**
     * breakdown 配列のキー→日本語ラベル
     */
    private function detail_kpi_labels(): array {
        return [
            'traffic' => '訪問数',
            'cv'      => 'ゴール数',
            'gsc'     => '検索クリック数',
            'meo'     => 'Googleマップ表示数',
        ];
    }

    /**
     * breakdown配列から最大/最小変化KPIを特定
     *
     * @param array  $breakdown  $infographic['breakdown']
     * @param string $direction  'positive' | 'negative'
     * @return array|null ['key'=>string, 'curr'=>float, 'prev'=>float, 'pct'=>float, 'points'=>int, 'max'=>int]
     */
    private function find_extreme_kpi(array $breakdown, string $direction): ?array {
        $result = null;
        $extreme_pct = null;

        foreach ($breakdown as $key => $bd) {
            if (!is_array($bd)) continue;
            $pct = (float)($bd['pct'] ?? 0);

            if ($direction === 'positive') {
                if ($pct > 0 && ($extreme_pct === null || $pct > $extreme_pct)) {
                    $extreme_pct = $pct;
                    $result = array_merge($bd, ['key' => $key]);
                }
            } else {
                if ($pct < 0 && ($extreme_pct === null || $pct < $extreme_pct)) {
                    $extreme_pct = $pct;
                    $result = array_merge($bd, ['key' => $key]);
                }
            }
        }

        return $result;
    }

    /**
     * breakdown配列から最低スコア(points)のKPIを特定
     */
    private function find_lowest_points(array $breakdown): ?array {
        $result = null;
        $min_ratio = null;

        foreach ($breakdown as $key => $bd) {
            if (!is_array($bd)) continue;
            $max = (int)($bd['max'] ?? 25);
            if ($max <= 0) continue;
            $ratio = (int)($bd['points'] ?? 0) / $max;
            if ($min_ratio === null || $ratio < $min_ratio) {
                $min_ratio = $ratio;
                $result = array_merge($bd, ['key' => $key]);
            }
        }

        return $result;
    }

    /**
     * KPIデータから読みやすい事実文を生成
     *
     * 小さい数値（前月0や絶対値5以下）の場合は率を強調せず、
     * 事実ベースのやさしい表現に切り替える。
     */
    private function format_kpi_sentence(array $kpi_data, string $direction): string {
        $labels = $this->detail_kpi_labels();
        $label  = $labels[$kpi_data['key']] ?? $kpi_data['key'];
        $curr_raw = (float)($kpi_data['curr'] ?? 0);
        $prev_raw = (float)($kpi_data['prev'] ?? 0);
        $curr   = number_format($curr_raw);
        $prev   = number_format($prev_raw);
        $pct    = abs((float)($kpi_data['pct'] ?? 0));

        // --- 小さい数値の特別処理 ---
        $is_tiny = ($prev_raw <= 2 && $curr_raw <= 5) || ($curr_raw <= 2 && $prev_raw <= 5);

        if ($direction === 'positive') {
            if ($prev_raw == 0 && $curr_raw > 0) {
                // 0 → N: 「初めて確認された」表現
                if ($curr_raw <= 3) {
                    return "これまで見られなかった{$label}が初めて{$curr}件確認されました。今後の動きに注目です。";
                }
                return "{$label}が前月の{$prev}から{$curr}へと発生し始めています。";
            }
            if ($is_tiny) {
                // 1→2, 2→3 など: 率を使わず事実ベース
                return "{$label}は前月の{$prev}から{$curr}へと少しずつ出始めています。";
            }
            // 通常: 率を含む表現
            return "当月の{$label}は{$curr}で、前月の{$prev}から+" . number_format($pct, 1) . '%増加しました。';
        } else {
            if ($curr_raw == 0 && $prev_raw > 0 && $prev_raw <= 3) {
                // 少数→0: トーンダウン
                return "{$label}は前月の{$prev}から{$curr}へと減っています。もともと少ない数値のため、大きな変動とは限りません。";
            }
            if ($is_tiny) {
                return "{$label}は前月の{$prev}から{$curr}へと減少しています。まだ数が少ないため、今後の推移を見守る段階です。";
            }
            // 通常
            return "当月の{$label}は{$curr}で、前月の{$prev}から" . number_format($pct, 1) . '%減少しています。';
        }
    }

    /**
     * レポートのリスト項目を要約テキスト化
     * 文末で言い切る（途中切断…を発生させない）
     *
     * @param mixed $item   文字列 or ['title'=>string, 'description'=>string]
     * @param int   $max_len 最大文字数の目安（この範囲内で文を切る）
     * @return string
     */
    private function summarize_report_item($item, int $max_len = 120): string {
        if (is_array($item)) {
            $text = $item['title'] ?? $item['description'] ?? '';
        } else {
            $text = (string)$item;
        }
        $text = trim($text);
        if ($text === '') return '';

        return $this->truncate_at_sentence($text, $max_len);
    }

    /**
     * 文を自然な位置（句点）で切り、言い切りの形で返す
     * 途中切断（…）を一切発生させない
     *
     * @param string $text    元テキスト
     * @param int    $max_len 最大文字数の目安
     * @return string
     */
    private function truncate_at_sentence(string $text, int $max_len = 120): string {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $max_len) {
            // 文末が句点で終わっていなければ補完
            return $this->ensure_sentence_end($text);
        }

        // 句点（。！？）で分割して、max_len 以内に収まる文を連結
        $sentences = preg_split('/(?<=[。！？])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $result = '';
        foreach ($sentences as $s) {
            $candidate = $result === '' ? $s : $result . $s;
            if (mb_strlen($candidate) > $max_len && $result !== '') {
                break;
            }
            $result = $candidate;
        }

        // 1文目だけでも max_len を超える場合 → 読点（、）で切る
        if ($result === '' || mb_strlen($result) > $max_len) {
            $first = $sentences[0] ?? $text;
            if (mb_strlen($first) > $max_len) {
                // 読点で分割して収まる範囲を取る
                $clauses = preg_split('/(?<=、)/u', $first, -1, PREG_SPLIT_NO_EMPTY);
                $result = '';
                foreach ($clauses as $c) {
                    $candidate = $result . $c;
                    if (mb_strlen($candidate) > $max_len && $result !== '') {
                        break;
                    }
                    $result = $candidate;
                }
            } else {
                $result = $first;
            }
        }

        return $this->ensure_sentence_end($result);
    }

    /**
     * 文末が句点・助動詞で終わっていなければ「です」を補う
     */
    private function ensure_sentence_end(string $text): string {
        $text = rtrim($text);
        if ($text === '') return '';

        // 既に自然な文末ならそのまま
        if (preg_match('/[。！？]$/u', $text)) {
            // 末尾の「。」を除去してすっきりさせる（リスト項目向け）
            return rtrim($text, '。');
        }

        // 末尾の「、」を除去
        $text = rtrim($text, '、');

        // 既に「です」「ます」「した」等で終わっていればOK
        if (preg_match('/(です|ます|ません|でした|ました|れます|せん|している|されている|あります|なります|できます|みられます|考えられます|出ています)$/u', $text)) {
            return $text;
        }

        // 体言止め的な文末（名詞・形容動詞語幹で終わる）はそのまま
        if (preg_match('/(状態|傾向|可能性|影響|効果|変化|増加|減少|不足|改善|低下|向上)$/u', $text)) {
            return $text;
        }

        return $text;
    }

    /**
     * next_actions から指定範囲のアクションテキストを抽出
     */
    private function extract_report_actions(?array $report, int $offset, int $count): array {
        if (!$report || empty($report['next_actions'])) return [];

        $actions = [];
        $items = array_slice($report['next_actions'], $offset, $count);
        foreach ($items as $item) {
            $text = $this->summarize_report_item($item, 60);
            if ($text !== '') $actions[] = $text;
        }
        return $actions;
    }

    /**
     * KPI種別に応じた汎用原因候補
     */
    private function generic_causes_for_kpi(string $kpi_key, string $direction): array {
        if ($direction === 'positive') {
            $map = [
                'traffic' => ['検索エンジンからの流入が増えている可能性があります', '季節的な需要増や業界トレンドの影響も考えられます'],
                'cv'      => ['問い合わせ導線やCTAの改善が効果を出している可能性があります', 'ターゲットユーザーに合った訴求ができている可能性があります'],
                'gsc'     => ['サイトの検索評価が向上している可能性があります', 'コンテンツの充実が検索エンジンに評価されている可能性があります'],
                'meo'     => ['Googleビジネスプロフィールの情報充実が効果を出している可能性があります', '口コミ評価の向上が表示回数に影響している可能性があります'],
            ];
        } else {
            $map = [
                'traffic' => ['検索順位の変動や競合の増加が影響している可能性があります', 'サイトの更新頻度低下や季節要因も考えられます'],
                'cv'      => ['導線やCTAの位置が最適でない可能性があります', 'フォームの入力項目が多い、またはページ表示速度の低下も考えられます'],
                'gsc'     => ['検索アルゴリズムの変更やタイトル・説明文の訴求力不足が考えられます', '競合サイトのコンテンツ強化の影響も考えられます'],
                'meo'     => ['口コミ数の減少や情報の更新不足が影響している可能性があります', '競合店舗の増加やカテゴリの競争激化も考えられます'],
            ];
        }
        return $map[$kpi_key] ?? $map['traffic'];
    }

    /**
     * KPI種別に応じた汎用アクション
     */
    private function generic_actions_for_kpi(string $kpi_key, string $direction): array {
        if ($direction === 'positive') {
            $map = [
                'traffic' => ['増加した訪問者の行動を分析し、人気ページを特定する', '訪問者を問い合わせにつなげる導線を整備する'],
                'cv'      => ['成功している導線パターンを他ページにも展開する', '問い合わせ内容を分析し、訴求内容を最適化する'],
                'gsc'     => ['伸びているキーワードのページをさらに充実させる', '検索流入の多いページにCTAを設置する'],
                'meo'     => ['口コミへの返信を積極的に行い、信頼性を高める', '店舗情報や写真を定期的に更新する'],
            ];
        } else {
            $map = [
                'traffic' => ['コンテンツの見直しと定期的な更新を行う', 'SNSやメルマガなど別の集客チャネルを検討する'],
                'cv'      => ['問い合わせフォームの簡素化やCTA位置の調整を行う', '上位ランディングページの訴求内容を見直す'],
                'gsc'     => ['主要ページのタイトル・メタディスクリプションを改善する', '検索意図に合ったコンテンツを追加する'],
                'meo'     => ['Googleビジネスプロフィールの情報を最新に更新する', '口コミ獲得のための声かけを強化する'],
            ];
        }
        return $map[$kpi_key] ?? $map['traffic'];
    }

    // =========================================================
    // HTML抽出ヘルパー（レポートHTML→テキスト/リスト/innerHTML）
    // =========================================================

    /**
     * HTMLからテキスト抽出（Gcrev_Html_Extractor への委譲）
     */
    public function extract_text_from_html(string $html, string $class_name): string {
        return Gcrev_Html_Extractor::extract_text($html, $class_name);
    }

    /**
     * HTMLからリスト抽出（Gcrev_Html_Extractor への委譲）
     */
    public function extract_list_from_html(string $html, string $class_name): array {
        return Gcrev_Html_Extractor::extract_list($html, $class_name);
    }

    /**
     * DOMで innerHTML 取得（Gcrev_Html_Extractor への委譲）
     */
    public function extract_div_inner_html_by_class(string $html, string $class_name): string {
        return Gcrev_Html_Extractor::extract_div_inner_html($html, $class_name);
    }

    // =========================================================
    // 内部NLPメソッド群
    // =========================================================

    /**
     * テキストからハイライト用のプレーンテキストに前処理
     */
    private function clean_for_highlight(string $text): string {
        if ($text === '') return '';

        $text = strip_tags($text);
        $text = str_replace('**', '', $text);

        // やさしいモード対策：括弧内の補足説明を除去
        $text = preg_replace('/（[^）]{15,}）/u', '', $text);
        $text = preg_replace('/\([^)]{15,}\)/u', '', $text);

        // 英語混じり見出し除去
        $text = preg_replace('/Priority\s*\d+[:\-]?/i', '', $text);
        $text = preg_replace('/Action\s*\d+[:\-]?/i', '', $text);
        $text = preg_replace('/今すぐやるべき\d+つの[^\s。]+/u', '', $text);

        // 初心者モードのセクション見出し除去
        $text = preg_replace('/良かったこと/u', '', $text);
        $text = preg_replace('/課題/u', '', $text, 1); // 先頭の「課題」見出しのみ除去
        $text = preg_replace('/今のサイトの状態をひと言で言うと/u', '', $text);
        $text = preg_replace('/今後の作戦（ネクストステップ）/u', '', $text);
        $text = preg_replace('/地元のお客さんの動き/u', '', $text);
        $text = preg_replace('/私からの提案/u', '', $text);

        // 数値比較の冗長表現除去
        $text = preg_replace('/前々?月の[\d,.]+から前々?月の[\d,.]+[へにまで]*/u', '', $text);
        $text = preg_replace('/[\d,.]+から[\d,.]+[へにまで]+/u', '', $text);

        // 空白・改行正規化
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * テキストからキーワードを含む「名詞句」を直接抽出する
     */
    private function extract_noun_phrase(string $text, array $keywords, bool $is_easy_mode = false, string $section_hint = '', ?string $target_area = null): string {
        if ($text === '') return '';

        // 句点で分割
        $sentences = preg_split('/(?<=[。！\n])/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_map('trim', $sentences);
        $sentences = array_values(array_filter($sentences, fn($s) => mb_strlen($s, 'UTF-8') >= 4));

        if (empty($sentences)) return '';

        // --- パス1: キーワードを含む文から名詞句抽出 ---
        foreach ($sentences as $sentence) {
            foreach ($keywords as $kw) {
                if (mb_strpos($sentence, $kw) !== false) {
                    $phrase = $this->sentence_to_noun_phrase($sentence, $kw, $is_easy_mode, $section_hint, $target_area);
                    if ($phrase !== '' && mb_strlen($phrase, 'UTF-8') >= 4) {
                        return $phrase;
                    }
                }
            }
        }

        // --- パス2: 先頭文から名詞句抽出（キーワードなし） ---
        $phrase = $this->sentence_to_noun_phrase($sentences[0], '', $is_easy_mode, $section_hint, $target_area);
        if ($phrase !== '' && mb_strlen($phrase, 'UTF-8') >= 4) {
            return $phrase;
        }

        return '';
    }

    /**
     * 1文から「主語＋述語の名詞化」の体言止めフレーズを生成
     */
    private function sentence_to_noun_phrase(string $sentence, string $keyword, bool $is_easy_mode = false, string $section_hint = '', ?string $target_area = null): string {
        $s = $sentence;

        // 前処理：記号・句読点除去
        $s = $this->strip_highlight_symbols($s);
        $s = str_replace(['。', '、', '，', '．'], '', $s);
        $s = preg_replace('/\s+/u', '', $s);

        if ($s === '') return '';

        // --- 初心者モード：やさしい日本語パターンから直接フレーズ抽出 ---
        if ($is_easy_mode) {
            $easy_phrase = $this->extract_easy_mode_phrase($s, $keyword, $section_hint, $target_area);
            if ($easy_phrase !== '') {
                return $easy_phrase;
            }
        }

        // --- 括弧付き補足から本来の指標名を取得 ---
        $bracket_term = '';
        if (preg_match('/（([^）]{2,10})）/u', $s, $bm)) {
            $bracket_term = $bm[1];
        } elseif (preg_match('/\(([^)]{2,10})\)/u', $s, $bm)) {
            $bracket_term = $bm[1];
        }
        $s = preg_replace('/（[^）]*）/u', '', $s);
        $s = preg_replace('/\([^)]*\)/u', '', $s);

        // --- 指標名辞書マッチ ---
        $metric_map = [
            '検索クリック数'     => '検索クリック数',
            '検索エンジンからのクリック' => '検索クリック数',
            '自然検索流入'       => '自然検索流入',
            'オーガニック流入'   => '自然検索流入',
            'オーガニック検索'   => '自然検索流入',
            '検索流入'           => '検索流入',
            '平均掲載順位'       => '検索平均掲載順位',
            '検索順位'           => '検索順位',
            '平均滞在時間'       => '平均滞在時間',
            '滞在時間'           => '滞在時間',
            'ページビュー数'     => 'ページビュー数',
            'ページビュー'       => 'ページビュー数',
            'PV数'               => 'ページビュー数',
            'セッション数'       => 'セッション数',
            'セッション'         => 'セッション数',
            '新規ユーザー数'     => '新規ユーザー数',
            '新規ユーザー'       => '新規ユーザー数',
            'ユーザー数'         => 'ユーザー数',
            'リピーター'         => 'リピーター数',
            'コンバージョン率'   => 'ゴール達成率',
            'コンバージョン数'   => 'ゴール達成数',
            'コンバージョン'     => 'ゴール達成数',
            'CV率'               => 'ゴール達成率',
            'CVR'                => 'ゴール達成率',
            'エンゲージメント率' => 'エンゲージメント率',
            'エンゲージメント'   => 'エンゲージメント率',
            '直帰率'             => '直帰率',
            '離脱率'             => '離脱率',
            '回遊率'             => '回遊率',
            'クリック率'         => '検索クリック率',
            'クリック数'         => '検索クリック数',
            'CTR'                => '検索クリック率',
            '表示回数'           => '検索表示回数',
            'インプレッション数' => '検索表示回数',
            'インプレッション'   => '検索表示回数',
            '広告流入'           => '広告流入',
            'SNS流入'            => 'SNS流入',
            '参照流入'           => '参照流入',
            'ダイレクト流入'     => 'ダイレクト流入',
            'お問い合わせ数'     => 'ゴール達成数',
            'お問い合わせ'       => 'ゴール達成数',
            '資料請求数'         => '資料請求数',
            '資料請求'           => '資料請求数',
            'PV'                 => 'ページビュー数',
        ];

        $found_metric = '';
        $search_targets = $bracket_term !== '' ? [$bracket_term, $s] : [$s];
        foreach ($search_targets as $target) {
            foreach ($metric_map as $pattern => $display_name) {
                if (mb_strpos($target, $pattern) !== false) {
                    $found_metric = $display_name;
                    break 2;
                }
            }
        }

        // --- 施策名パターン（改善機会用） ---
        $action_names = [
            'コンテンツSEO', 'SEO対策', 'SEO', 'コンテンツ強化', 'コンテンツ改善',
            'ランディングページ最適化', 'LP改善', 'LP最適化',
            '広告運用', '広告費最適化', '広告改善', 'リスティング広告',
            'SNS活用', 'SNS強化', 'SNS運用',
            '地域施策', '地域SEO', 'MEO対策', 'MEO',
            'サイト改善', 'UI改善', 'UX改善', 'ページ速度改善',
            '内部リンク', '外部リンク', '被リンク',
            'CTA改善', 'フォーム改善', '導線改善', '導線見直し',
        ];

        $found_action = '';
        foreach ($action_names as $action) {
            if (mb_strpos($s, $action) !== false) {
                $found_action = $action;
                break;
            }
        }

        // --- フレーズ組み立て ---
        $phrase = '';

        $normalized_keyword = $this->normalize_change_word($keyword);

        // --- section_hint 別の組み立て戦略 ---

        if ($section_hint === 'site_wide') {
            // ====== 最重要ポイント：事実・状態のみ ======
            $display_metric = ($is_easy_mode && $found_metric !== '') ? $this->metric_to_easy_name($found_metric) : $found_metric;

            if ($found_metric !== '' && $normalized_keyword !== '') {
                $phrase = $display_metric . 'の' . $normalized_keyword;
            } elseif ($found_metric !== '') {
                $phrase = $this->metric_with_verb_stem($s, $display_metric);
            } elseif ($normalized_keyword !== '') {
                $phrase = 'アクセス数の' . $normalized_keyword;
            } else {
                $phrase = $this->force_taigen_dome($s);
            }

        } elseif ($section_hint === 'issue') {
            // ====== 最優先課題：読みやすい文章形式 ======
            if ($found_metric !== '' && $normalized_keyword !== '') {
                $phrase = $this->build_relational_bottleneck($found_metric, $normalized_keyword);
            } elseif ($found_metric !== '') {
                $phrase = $this->build_relational_bottleneck($found_metric, '');
            } else {
                // メトリクスが見つからない場合: 元文から短い文を抽出
                $phrase = $this->extract_short_issue_sentence($s);
            }

        } elseif ($section_hint === 'action') {
            // ====== 改善機会：施策名 ======
            $display_action = ($is_easy_mode && $found_action !== '') ? $this->action_to_easy_name($found_action) : $found_action;

            if ($found_action !== '') {
                // 抽象語のみのアクション名は空にして課題連動フォールバックに委ねる
                if ($this->is_abstract_standalone($display_action)) {
                    $phrase = '';
                } else {
                    $phrase = $display_action;
                }
            } elseif ($found_metric !== '') {
                // 旧: '$metric改善の取り組み' → 空にして課題連動フォールバックに委ねる
                $phrase = '';
            } else {
                $phrase = $this->force_taigen_dome($s);
            }

        } else {
            // ====== フォールバック（section_hint未指定） ======
            $context_prefix = $this->extract_context_prefix($sentence);
            $display_metric = ($is_easy_mode && $found_metric !== '') ? $this->metric_to_easy_name($found_metric) : $found_metric;

            if ($found_metric !== '' && $normalized_keyword !== '') {
                $phrase = $context_prefix . $display_metric . 'の' . $normalized_keyword;
            } elseif ($found_action !== '' && $normalized_keyword !== '') {
                $display_action = ($is_easy_mode && $found_action !== '') ? $this->action_to_easy_name($found_action) : $found_action;
                $phrase = $context_prefix . $display_action;
            } elseif ($found_metric !== '') {
                $phrase = $context_prefix . $this->metric_with_verb_stem($s, $display_metric);
            } elseif ($found_action !== '') {
                $display_action = ($is_easy_mode && $found_action !== '') ? $this->action_to_easy_name($found_action) : $found_action;
                $phrase = $context_prefix . $display_action;
            } else {
                $phrase = $this->force_taigen_dome($s);
            }
        }

        // --- 最終整形 ---
        $phrase = $this->finalize_highlight($phrase, $section_hint);

        return $phrase;
    }

    /**
     * 初心者モードのやさしい日本語から体言止めフレーズを直接生成
     *
     * section_hint による出し分け:
     *   - 'site_wide' → 事実・状態のみ（例: 「サイト訪問数の増加」）
     *   - 'issue'     → ボトルネック表現（例: 「増えた訪問を成果につなげきれていない点」）
     *   - 'action'    → 施策名（例: 「問い合わせ導線の見直し」）
     */
    private function extract_easy_mode_phrase(string $s, string $keyword, string $section_hint, ?string $target_area): string {

        // --- やさしい日本語 → 指標名マッピング ---
        $easy_metric_patterns = [
            'ホームページに来てくれた人'       => '訪問者数',
            'ホームページに来た人'             => '訪問者数',
            'ホームページへの訪問'             => '訪問数',
            '来てくれた人の数'                 => '訪問者数',
            '来てくれる人'                     => '訪問者数',
            '見られたページの数'               => 'ページ閲覧数',
            '見てもらえたページ'               => 'ページ閲覧数',
            '見られたページ'                   => 'ページ閲覧数',
            'ページの数'                       => 'ページ閲覧数',
            'じっくり見てくれた人'             => '熟読率',
            'しっかり読まれた割合'             => '熟読率',
            'じっくり読まれた割合'             => '熟読率',
            '検索結果に表示された回数'         => '検索表示回数',
            '検索結果に出た回数'               => '検索表示回数',
            '検索での見つかりやすさ'           => '検索露出',
            '検索からクリックされた'           => '検索クリック数',
            '検索からのクリック'               => '検索クリック数',
            'Google検索から来た人'             => '検索流入数',
            '検索から来た人'                   => '検索流入数',
            '検索からの訪問'                   => '検索流入数',
            'お問い合わせ'                     => 'ゴール数',
            '問い合わせ'                       => 'ゴール数',
            '1ページだけ見て帰った'             => '直帰率',
            '1ページで帰った'                   => '直帰率',
            '初めて来てくれた人'               => '新規訪問者数',
            '初めての訪問者'                   => '新規訪問者数',
            '成果'                             => '成果',
            'コンバージョン'                   => 'ゴール',
        ];

        // --- やさしい変化語 → 名詞形マッピング ---
        $easy_change_patterns = [
            'グンと増え'     => '大幅増加',
            'グッと増え'     => '大幅増加',
            'ドンと増え'     => '大幅増加',
            '大きく増え'     => '大幅増加',
            'かなり増え'     => '大幅増加',
            '一気に増え'     => '大幅増加',
            '少し増え'       => '増加',
            'ちょっと増え'   => '微増',
            '増えて'         => '増加',
            '増えま'         => '増加',
            '伸びて'         => '増加',
            '伸びま'         => '増加',
            'グンと減'       => '大幅減少',
            'ガクッと減'     => '大幅減少',
            '大きく減'       => '大幅減少',
            'かなり減'       => '大幅減少',
            '少し減'         => '減少',
            'ちょっと減'     => '微減',
            '減って'         => '減少',
            '減りま'         => '減少',
            '減ってしまい'   => '減少',
            '下がって'       => '低下',
            '下がりま'       => '低下',
            '上がって'       => '上昇',
            '上がりま'       => '上昇',
            '良くなって'     => '改善',
            '良くなりま'     => '改善',
            '好調'           => '好調',
            '絶好調'         => '好調',
            '初めて発生'     => '初発生',
            '初めて出'       => '初発生',
            'つながっていません' => '未転換',
            'つながっていない'   => '未転換',
            '発生していません'   => '未発生',
            'まだない'       => '未発生',
            '変わらず'       => '横ばい',
            '変わりません'   => '横ばい',
            'あまり来て'     => '低水準',
            'まだ少な'       => '低水準',
        ];

        // --- マッチ処理 ---
        $found_metric = '';
        foreach ($easy_metric_patterns as $pattern => $metric_name) {
            if (mb_strpos($s, $pattern) !== false) {
                $found_metric = $metric_name;
                break;
            }
        }

        $found_change = '';
        foreach ($easy_change_patterns as $pattern => $change_name) {
            if (mb_strpos($s, $pattern) !== false) {
                $found_change = $change_name;
                break;
            }
        }

        // マッチなし → 通常ロジックに戻す
        if ($found_metric === '' && $found_change === '') {
            return '';
        }

        // =============================================================
        // section_hint ごとのフレーズ組み立て
        // =============================================================

        // --- (A) site_wide: 事実・状態のみ（「指標名の変化」） ---
        if ($section_hint === 'site_wide') {
            if ($found_metric !== '' && $found_change !== '') {
                return $this->finalize_highlight($found_metric . 'の' . $found_change);
            }
            if ($found_metric !== '') {
                return $this->finalize_highlight($found_metric);
            }
            // 変化語だけの場合はアクセス数等を補完
            return $this->finalize_highlight('アクセス数の' . $found_change);
        }

        // --- (B) issue: ボトルネック表現（「Xに対するYの不足」） ---
        if ($section_hint === 'issue') {
            return $this->build_bottleneck_phrase($s, $found_metric, $found_change, $easy_metric_patterns);
        }

        // --- (C) action: 施策名（「～の見直し」「～の強化」） ---
        if ($section_hint === 'action') {
            return $this->build_action_phrase($s, $found_metric, $found_change);
        }

        // --- フォールバック ---
        if ($found_metric !== '' && $found_change !== '') {
            return $this->finalize_highlight($found_metric . 'の' . $found_change);
        }
        if ($found_metric !== '') {
            return $this->finalize_highlight($found_metric);
        }
        return $this->finalize_highlight($found_change);
    }

    /**
     * ボトルネック型フレーズを構築（issue セクション用）
     *
     * 事実（指標の変化）から導かれる不足・課題を表現する。
     * 例: 「訪問増加に対する成果転換の不足」「検索露出はあるがクリック獲得の課題」
     */
    private function build_bottleneck_phrase(string $s, string $found_metric, string $found_change, array $easy_metric_patterns): string {

        // 変化の方向分類
        $negative_changes = ['減少', '大幅減少', '低下', '微減', '未発生', '未転換', '低水準', '横ばい'];
        $positive_changes = ['増加', '大幅増加', '上昇', '改善', '好調', '初発生', '微増'];

        // ケース1: 指標 + ネガティブ変化 → 関連的ボトルネック（ネガティブ版）
        if ($found_metric !== '' && in_array($found_change, $negative_changes, true)) {
            $relational = $this->build_relational_bottleneck($found_metric, $found_change);
            return $this->finalize_highlight($relational, 'issue');
        }

        // ケース2: 指標 + ポジティブ変化 → 関連的ボトルネック（ポジティブ版）
        if ($found_metric !== '' && in_array($found_change, $positive_changes, true)) {
            $relational = $this->build_relational_bottleneck($found_metric, $found_change);
            return $this->finalize_highlight($relational, 'issue');
        }

        // ケース3: 指標のみ → 関連的ボトルネック（方向不明）
        if ($found_metric !== '') {
            $relational = $this->build_relational_bottleneck($found_metric, '');
            return $this->finalize_highlight($relational, 'issue');
        }

        // ケース4: 変化語のみ → 文中から別の指標を探して関連的ボトルネック化
        if ($found_change !== '') {
            // 文中に別の指標がないか再探索
            foreach ($easy_metric_patterns as $pattern => $metric_name) {
                if (mb_strpos($s, $pattern) !== false) {
                    $relational = $this->build_relational_bottleneck($metric_name, $found_change);
                    return $this->finalize_highlight($relational, 'issue');
                }
            }
            // どの指標にも当たらない場合
            return $this->finalize_highlight('成果への転換不足', 'issue');
        }

        return '';
    }

    /**
     * 施策型フレーズを構築（action セクション用）
     *
     * 「～の強化」「～の見直し」のような名詞句にする。
     */
    private function build_action_phrase(string $s, string $found_metric, string $found_change): string {

        // 施策キーワードを文中から探す（具体的パターンのみ）
        $action_patterns = [
            '貼りましょう'     => '導線の追加',
            '載せましょう'     => 'コンテンツの追加',
            '書きましょう'     => '記事コンテンツの作成',
            '追加しましょう'   => 'ページへのコンテンツ追加',
        ];

        foreach ($action_patterns as $pattern => $action_name) {
            if (mb_strpos($s, $pattern) !== false) {
                return $this->finalize_highlight($action_name);
            }
        }

        // 汎用パターン（してみませんか, おすすめ等）や指標のみの場合は
        // 空文字を返して validate_or_derive_action() で課題連動アクションに補完
        if ($found_metric !== '') {
            return '';
        }

        return '';
    }

    /**
     * 元の文から文脈修飾語を抽出
     */
    private function extract_context_prefix(string $sentence): string {
        $s = strip_tags($sentence);
        $s = str_replace('**', '', $s);

        // --- パターン1: 都道府県名 ---
        $prefectures = [
            '北海道', '青森', '岩手', '宮城', '秋田', '山形', '福島',
            '茨城', '栃木', '群馬', '埼玉', '千葉', '東京', '神奈川',
            '新潟', '富山', '石川', '福井', '山梨', '長野', '岐阜', '静岡', '愛知',
            '三重', '滋賀', '京都', '大阪', '兵庫', '奈良', '和歌山',
            '鳥取', '島根', '岡山', '広島', '山口',
            '徳島', '香川', '愛媛', '高知',
            '福岡', '佐賀', '長崎', '熊本', '大分', '宮崎', '鹿児島', '沖縄',
        ];

        foreach ($prefectures as $pref) {
            if (mb_strpos($s, $pref) !== false) {
                if (preg_match('/' . preg_quote($pref, '/') . '(?:県|府|都|道)?(?:からの|エリアの|地域の)/u', $s)) {
                    return $pref . 'からの';
                }
                return $pref . 'の';
            }
        }

        // --- パターン2: 市区町村・エリア名 ---
        if (preg_match('/([\p{Han}\p{Katakana}ー]{2,6}(?:市|区|町|村|エリア|地域))(?:からの|の)/u', $s, $m)) {
            return $m[1] . 'からの';
        }

        // --- パターン3: サイト全体系 ---
        $site_wide = ['サイト全体', 'ウェブサイト全体', 'ホームページ全体', 'すべてのページ', '全ページ'];
        foreach ($site_wide as $pattern) {
            if (mb_strpos($s, $pattern) !== false) {
                return 'サイト全体の';
            }
        }

        // --- パターン4: チャネル・ページ名 ---
        $channel_map = [
            'Google検索' => 'Google検索からの', 'Yahoo検索' => 'Yahoo検索からの',
            '検索エンジン' => '検索からの',
            'トップページ' => 'トップページの', 'ブログ' => 'ブログの',
            'SNS' => 'SNSからの', '広告' => '広告からの',
            'モバイル' => 'モバイルの', 'スマートフォン' => 'スマホの', 'パソコン' => 'PCの',
        ];
        foreach ($channel_map as $pattern => $prefix) {
            if (mb_strpos($s, $pattern) !== false) {
                return $prefix;
            }
        }

        return '';
    }

    /**
     * 初心者向けモード：指標名を分かりやすい日本語に変換
     */
    private function metric_to_easy_name(string $metric): string {
        $map = [
            'セッション数'       => '訪問回数',
            'ページビュー数'     => 'ページ閲覧数',
            '新規ユーザー数'     => '初めての訪問者数',
            'ユーザー数'         => '訪問者数',
            'リピーター数'       => 'リピーター数',
            'コンバージョン率'   => 'ゴール達成率',
            'コンバージョン数'   => 'ゴール達成数',
            'エンゲージメント率' => 'ちゃんと読まれる割合',
            '直帰率'             => '1ページで終了した割合',
            '離脱率'             => '離脱率',
            '回遊率'             => '回遊率',
            '平均滞在時間'       => '平均閲覧時間',
            '滞在時間'           => '閲覧時間',
            '検索クリック数'     => '検索からの訪問数',
            '検索クリック率'     => '検索クリック率',
            '検索表示回数'       => '検索での表示回数',
            '検索平均掲載順位'   => '検索順位',
            '検索順位'           => '検索順位',
            '自然検索流入'       => '検索からの訪問',
            '検索流入'           => '検索からの訪問',
            '広告流入'           => '広告からの訪問',
            'SNS流入'            => 'SNSからの訪問',
            '参照流入'           => '他サイトからの訪問',
            'ダイレクト流入'     => '直接訪問',
            'お問い合わせ数'     => 'ゴール達成数',
            '資料請求数'         => '資料請求数',
        ];
        return $map[$metric] ?? $metric;
    }

    /**
     * 初心者向けモード：施策名を分かりやすい日本語に変換
     */
    private function action_to_easy_name(string $action): string {
        $map = [
            'コンテンツSEO'   => '記事による検索対策',
            'SEO対策'         => '検索対策',
            'SEO'             => '検索対策',
            'LP改善'          => 'ページ改善',
            'LP最適化'        => 'ページ改善',
            'CTA改善'         => '行動を促す改善',
            'フォーム改善'    => '入力フォーム改善',
            '導線改善'        => 'ゴール達成までの流れ改善',
            '導線見直し'      => 'ゴール達成までの流れ見直し',
            'MEO対策'         => '地図検索対策',
            'MEO'             => '地図検索対策',
            '地域SEO'         => '地域検索対策',
            'UI改善'          => '見た目と操作の改善',
            'UX改善'          => '使いやすさ改善',
        ];
        return $map[$action] ?? $action;
    }

    /**
     * 変化語を明確な名詞形に正規化
     */
    private function normalize_change_word(string $word): string {
        $map = [
            '伸び' => '増加', '伸びた' => '増加', '伸ばし' => '増加',
            '増え' => '増加', '増えた' => '増加',
            '減り' => '減少', '減った' => '減少',
            '上がり' => '上昇', '上がった' => '上昇',
            '下がり' => '低下', '下がった' => '低下',
            '良くな' => '改善', '良化' => '改善',
            '落ち' => '低下', '落ちた' => '低下',
        ];
        return $map[$word] ?? $word;
    }

    /**
     * 指標名＋文中の変化動詞の名詞形を結合
     */
    private function metric_with_verb_stem(string $sentence, string $metric): string {
        $change_word_map = [
            '急増' => '急増', '急減' => '急減', '微増' => '微増', '微減' => '微減',
            '増加' => '増加', '減少' => '減少',
            '上昇' => '上昇', '低下' => '低下',
            '向上' => '向上', '悪化' => '悪化',
            '改善' => '改善', '回復' => '回復',
            '成長' => '成長', '停滞' => '停滞',
            '下落' => '下落', '横ばい' => '横ばい',
            '伸び' => '増加', '伸びた' => '増加', '伸ばし' => '増加',
            '増え' => '増加', '増えた' => '増加',
            '減り' => '減少', '減った' => '減少',
            '上がり' => '上昇', '上がった' => '上昇',
            '下がり' => '低下', '下がった' => '低下',
            '良くな' => '改善', '良化' => '改善',
            '落ち' => '低下', '落ちた' => '低下',
        ];

        foreach ($change_word_map as $raw => $normalized) {
            if (mb_strpos($sentence, $raw) !== false) {
                return $metric . $normalized;
            }
        }
        return $metric;
    }

    /**
     * 記号・絵文字を完全除去
     */
    private function strip_highlight_symbols(string $text): string {
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text);
        $text = preg_replace('/[\x{2600}-\x{27BF}]/u', '', $text);
        $text = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $text);
        $text = preg_replace('/[\x{200D}]/u', '', $text);
        $text = preg_replace('/[📈⚠️🎯❌⭕✅🔴💡🔥★☆●▲▼◆■□◯※→←↑↓｜]/u', '', $text);
        $text = preg_replace('/[!！?？♪♫#＃&＆\*＊~〜\-－―]+/u', '', $text);
        return $text;
    }

    /**
     * 体言止め化：文末の動詞・助動詞・助詞を除去して名詞で終わらせる
     */
    private function force_taigen_dome(string $text): string {
        $verb_suffixes = [
            'していくことが求められます', 'していく必要があります', 'する必要があります',
            'ことが重要です', 'ことが必要です', 'ことが求められます',
            'と考えられます', 'が考えられます', 'と思われます',
            'が期待されます', 'が見込まれます', 'が見られます',
            'されています', 'しています', 'できています', 'なっています', 'られています',
            'が必要です', 'が重要です', 'が求められます',
            'しました', 'されました', 'でした', 'ました',
            'ています', 'ております', 'てきました',
            'になります', 'であります', 'でしょう', 'ください',
            'ません', 'します', 'されます', 'できます', 'なります',
            'あります', 'おります', 'れます',
            'です', 'ます', 'した', 'った',
            'ある', 'いる', 'する', 'なる', 'れる', 'せる', 'える', 'ない',
        ];

        $prev = '';
        $max_iterations = 5;
        $i = 0;
        while ($prev !== $text && $i < $max_iterations) {
            $prev = $text;
            $i++;
            foreach ($verb_suffixes as $suffix) {
                $suffix_len = mb_strlen($suffix, 'UTF-8');
                $text_len = mb_strlen($text, 'UTF-8');
                if ($text_len > $suffix_len + 2) {
                    if (mb_substr($text, -$suffix_len, null, 'UTF-8') === $suffix) {
                        $text = mb_substr($text, 0, $text_len - $suffix_len, 'UTF-8');
                        break;
                    }
                }
            }
        }

        $particles = ['として', 'について', 'において', 'に対して', 'から', 'まで', 'より',
                      'が', 'は', 'を', 'に', 'で', 'と', 'も', 'へ'];
        foreach ($particles as $p) {
            $p_len = mb_strlen($p, 'UTF-8');
            if (mb_strlen($text, 'UTF-8') > $p_len + 2) {
                if (mb_substr($text, -$p_len, null, 'UTF-8') === $p) {
                    $text = mb_substr($text, 0, mb_strlen($text, 'UTF-8') - $p_len, 'UTF-8');
                    break;
                }
            }
        }

        return trim($text);
    }

    /**
     * ハイライトフレーズの最終整形
     *
     * section_hint により出力形式を切り替え:
     *   - 'site_wide' → 体言止め、目標20文字（最大22文字）
     *   - 'issue'     → 完結した短い文、最大40文字
     *   - 'action'    → 体言止め、目標20文字（最大22文字）
     *   - その他      → 体言止め、目標20文字（最大22文字）
     */
    private function finalize_highlight(string $text, string $section_hint = ''): string {
        $text = $this->strip_highlight_symbols($text);
        $text = preg_replace('/\s+/u', '', $text);
        $text = trim($text);

        // issue の場合は文章形式を許容（体言止め化しない）
        if ($section_hint === 'issue') {
            // 句読点は残す（文として自然にするため）
            // 末尾の余計な「。」だけ除去
            $text = rtrim($text, '。');

            // --- 長さ制御: 最大40文字 ---
            if (mb_strlen($text, 'UTF-8') > 40) {
                $text = $this->smart_truncate_sentence($text, 40);
            }

            // 文末が名詞で終わっていて不自然な場合、補完
            if (!preg_match('/(です|ます|ません|でした|ました|れます|せん|ている|されている|あります|なります|できます|みられます|考えられます|出ています|必要です|見られます)$/u', $text)) {
                // 体言止めなら「が見られます」を付与
                if (preg_match('/(不足|低下|減少|悪化|停滞|低迷|課題|乖離|ミスマッチ|伸び悩み)$/u', $text)) {
                    $text .= 'が見られます';
                }
            }

            return $text;
        }

        // site_wide / action / その他 → 従来の体言止め
        $text = str_replace(['。', '、', '，', '．', ',', '.'], '', $text);

        // --- 口語・感想・評価表現の除去 ---
        $colloquial_suffixes = [
            'ですね', 'ですよ', 'ですよね', 'ました', 'ません',
            'しましょう', 'ましょう', 'ください',
            'と思います', 'と考えます', 'と言えます',
            'が見えます', 'が感じられます', 'かもしれません',
            'のようです', 'みたいです',
            'が嬉しい', 'で嬉しい', 'が心配', 'で心配',
            'が大事', 'が大切', 'が重要',
        ];
        foreach ($colloquial_suffixes as $suffix) {
            $suffix_len = mb_strlen($suffix, 'UTF-8');
            if (mb_strlen($text, 'UTF-8') > $suffix_len + 3) {
                if (mb_substr($text, -$suffix_len, null, 'UTF-8') === $suffix) {
                    $text = mb_substr($text, 0, mb_strlen($text, 'UTF-8') - $suffix_len, 'UTF-8');
                    break;
                }
            }
        }

        // 体言止め化
        $text = $this->force_taigen_dome($text);

        // --- 長さ制御：目標20文字、最大22文字 ---
        $max_len = 22;
        if (mb_strlen($text, 'UTF-8') > $max_len) {
            $text = $this->smart_truncate($text, $max_len);
        }

        return $text;
    }

    /**
     * 文章形式の短縮: 文の切れ目（句点・読点）で切る
     */
    private function smart_truncate_sentence(string $text, int $max_len): string {
        if (mb_strlen($text, 'UTF-8') <= $max_len) return $text;

        $search = mb_substr($text, 0, $max_len, 'UTF-8');
        // 句点で切れるか
        $last_period = mb_strrpos($search, '。', 0, 'UTF-8');
        if ($last_period !== false && $last_period > 10) {
            return mb_substr($text, 0, $last_period, 'UTF-8');
        }
        // 読点で切れるか
        $last_comma = mb_strrpos($search, '、', 0, 'UTF-8');
        if ($last_comma !== false && $last_comma > 10) {
            return mb_substr($text, 0, $last_comma, 'UTF-8');
        }
        // 助詞の切れ目
        $cut_particles = ['の', 'と', 'に', 'を', 'が', 'は', 'で', 'へ'];
        $best = 0;
        foreach ($cut_particles as $p) {
            $pos = mb_strrpos($search, $p, 0, 'UTF-8');
            if ($pos !== false && $pos > $best && $pos > 10) {
                $best = $pos;
            }
        }
        if ($best > 0) {
            return mb_substr($text, 0, $best, 'UTF-8');
        }
        return $search;
    }

    /**
     * 22文字以内で意味の切れ目で切る（途中切れ防止）
     */
    private function smart_truncate(string $text, int $max_len): string {
        $search_range = mb_substr($text, 0, $max_len, 'UTF-8');

        $cut_particles = ['の', 'と', 'や', 'に', 'を', 'が', 'は', 'で', 'へ'];
        $best_pos = 0;

        foreach ($cut_particles as $p) {
            $pos = mb_strrpos($search_range, $p, 0, 'UTF-8');
            if ($pos !== false && $pos >= 4 && $pos > $best_pos) {
                $best_pos = $pos;
            }
        }

        if ($best_pos > 0) {
            $result = mb_substr($text, 0, $best_pos, 'UTF-8');
            return $this->force_taigen_dome($result);
        }

        return mb_substr($text, 0, $max_len, 'UTF-8');
    }

    // =========================================================
    // 関連的ボトルネック生成 & 重複防止
    // =========================================================

    /**
     * 関連的ボトルネックフレーズを生成
     *
     * 事実（指標＋変化方向）から「次のステップの不足」を導く。
     * 例: metric="訪問者数", change="増加" → "増えた訪問の成果転換不足"
     */
    private function build_relational_bottleneck(string $metric, string $change): string {

        // 指標ごとの「次のステップ」定義（文章形式用）
        $next_step_sentence = [
            '訪問者数'       => '成果につなげきれていない状況です',
            '訪問数'         => '成果につなげきれていない状況です',
            '訪問回数'       => '成果につなげきれていない状況です',
            'セッション数'   => '成果につなげきれていない状況です',
            'ページ閲覧数'   => '具体的な行動にはつながっていません',
            'ページビュー数' => '具体的な行動にはつながっていません',
            '検索表示回数'   => 'クリックには結びついていません',
            '検索露出'       => 'クリックには結びついていません',
            '検索クリック数' => '問い合わせにはつながっていません',
            '検索流入数'     => '問い合わせにはつながっていません',
            '自然検索流入'   => '問い合わせにはつながっていません',
            '検索流入'       => '問い合わせにはつながっていません',
            '新規訪問者数'   => 'リピーターにはつながっていません',
            '新規ユーザー数' => 'リピーターにはつながっていません',
            'ユーザー数'     => '成果につなげきれていない状況です',
            '問い合わせ数'   => '安定的な獲得には至っていません',
            'お問い合わせ数' => '安定的な獲得には至っていません',
            '熟読率'         => '行動にはつながっていません',
            '成果'           => '安定的な成果獲得には至っていません',
            '直帰率'         => 'サイト内の回遊が不足しています',
            '離脱率'         => 'サイト内の回遊が不足しています',
            'エンゲージメント率' => '成果につなげきれていない状況です',
            'コンバージョン率'   => '安定的な獲得には至っていません',
            'コンバージョン数'   => '安定的な成果獲得には至っていません',
        ];

        $positive_changes = ['増加', '大幅増加', '上昇', '改善', '好調', '初発生', '微増', '成長', '回復', '向上', '獲得', '達成'];
        $negative_changes = ['減少', '大幅減少', '低下', '微減', '未発生', '未転換', '低水準', '横ばい', '悪化', '下落', '停滞', '不足'];

        $short = $this->shorten_metric($metric);
        $next_s = $next_step_sentence[$metric] ?? '成果につなげきれていない状況です';

        if (in_array($change, $positive_changes, true)) {
            // ポジティブ → "訪問は増えていますが、成果にはつながっていません"
            $verb = $this->change_to_progressive($change);
            return $short . 'は' . $verb . 'が、' . $next_s;
        }

        if (in_array($change, $negative_changes, true)) {
            // ネガティブ → "訪問数が減っており、改善が必要です"
            $neg_sentence_map = [
                '訪問者数'       => '訪問数が伸び悩んでおり、改善が必要です',
                '訪問数'         => '訪問数が伸び悩んでおり、改善が必要です',
                '訪問回数'       => '訪問数が伸び悩んでおり、改善が必要です',
                'セッション数'   => '訪問数が伸び悩んでおり、改善が必要です',
                'ページ閲覧数'   => '閲覧数が伸び悩んでいます',
                'ページビュー数' => '閲覧数が伸び悩んでいます',
                '検索表示回数'   => '検索での露出が不足しています',
                '検索露出'       => '検索での露出が不足しています',
                '検索クリック数' => '検索からの流入が不足しています',
                '検索流入数'     => '検索からの流入が不足しています',
                '自然検索流入'   => '検索からの流入が不足しています',
                '検索流入'       => '検索からの流入が不足しています',
                '新規訪問者数'   => '新規の訪問者が伸び悩んでいます',
                '新規ユーザー数' => '新規の訪問者が伸び悩んでいます',
                'ユーザー数'     => '訪問者数が伸び悩んでいます',
                '問い合わせ数'   => '問い合わせの獲得が課題です',
                'お問い合わせ数' => '問い合わせの獲得が課題です',
                '熟読率'         => 'ページの閲覧の質が低下しています',
                '成果'           => '成果の発生が課題となっています',
                '直帰率'         => 'すぐに離脱するユーザーが多い状況です',
                '離脱率'         => '途中で離脱するユーザーが多い状況です',
                'エンゲージメント率' => 'ページへの関心度が低下しています',
                'コンバージョン率'   => '成果につながる割合が低迷しています',
                'コンバージョン数'   => '成果の獲得が停滞しています',
            ];
            return $neg_sentence_map[$metric] ?? ($short . 'が伸び悩んでいます');
        }

        // 方向不明 → 汎用文
        return $short . 'を' . $next_s;
    }

    /**
     * 変化名詞を進行形に変換（「増加」→「増えています」）
     */
    private function change_to_progressive(string $change): string {
        $map = [
            '増加'     => '増えています',
            '大幅増加' => '大きく増えています',
            '上昇'     => '上がっています',
            '改善'     => '改善しています',
            '好調'     => '好調です',
            '初発生'   => '発生しています',
            '微増'     => '微増しています',
            '成長'     => '伸びています',
            '回復'     => '回復しています',
            '向上'     => '向上しています',
            '獲得'     => '獲得できています',
            '達成'     => '達成しています',
        ];
        return $map[$change] ?? ($change . 'しています');
    }

    /**
     * 元文から短い課題文を抽出（issueフォールバック用）
     * メトリクスが特定できない場合に、元の文から40文字以内の意味ある文を取り出す
     */
    private function extract_short_issue_sentence(string $text): string {
        // 句点で分割して最初の文を取得
        $sentences = preg_split('/[。！？]/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($sentences)) {
            $first = trim($sentences[0]);
            if (mb_strlen($first, 'UTF-8') <= 40 && mb_strlen($first, 'UTF-8') > 5) {
                // 文末が自然な形でない場合は補完
                if (!preg_match('/(です|ます|ません|ている|されている|あります|なります|できます|みられます|必要です|見られます)$/u', $first)) {
                    $first .= 'が課題です';
                }
                return $first;
            }
        }
        return '改善が必要な項目があります';
    }

    /**
     * 変化名詞を連体修飾語に変換（「増加」→「増えた」）
     */
    private function change_to_adjective(string $change): string {
        $map = [
            '増加'     => '増えた',
            '大幅増加' => '大きく増えた',
            '上昇'     => '上がった',
            '改善'     => '改善した',
            '好調'     => '好調な',
            '初発生'   => '発生した',
            '微増'     => '増えた',
            '成長'     => '伸びた',
            '回復'     => '回復した',
            '向上'     => '向上した',
            '獲得'     => '獲得した',
            '達成'     => '達成した',
        ];
        return $map[$change] ?? ($change . 'した');
    }

    /**
     * 指標名を短縮形に変換（ボトルネック文中で使う短い形）
     */
    private function shorten_metric(string $metric): string {
        $map = [
            '訪問者数'           => '訪問',
            '訪問数'             => '訪問',
            '訪問回数'           => '訪問',
            'セッション数'       => '訪問',
            'ページ閲覧数'       => '閲覧',
            'ページビュー数'     => '閲覧',
            '検索表示回数'       => '検索露出',
            '検索露出'           => '検索露出',
            '検索クリック数'     => '検索流入',
            '検索流入数'         => '検索流入',
            '自然検索流入'       => '検索流入',
            '検索流入'           => '検索流入',
            '新規訪問者数'       => '新規訪問',
            '新規ユーザー数'     => '新規訪問',
            'ユーザー数'         => '訪問者',
            '問い合わせ数'       => '問い合わせ',
            'お問い合わせ数'     => '問い合わせ',
            '熟読率'             => '閲覧の質',
            '成果'               => '成果',
            '直帰率'             => '直帰率',
            '離脱率'             => '離脱率',
            'エンゲージメント率' => '閲覧の質',
            'コンバージョン率'   => '成果率',
            'コンバージョン数'   => '成果',
            '検索からの訪問数'   => '検索流入',
            '検索平均掲載順位'   => '検索順位',
            '検索順位'           => '検索順位',
            '平均滞在時間'       => '滞在時間',
            '滞在時間'           => '滞在時間',
            '広告流入'           => '広告流入',
            'SNS流入'            => 'SNS流入',
            '参照流入'           => '参照流入',
            'ダイレクト流入'     => '直接流入',
            '資料請求数'         => '資料請求',
            'リピーター数'       => 'リピーター',
            '回遊率'             => '回遊率',
        ];
        return $map[$metric] ?? $metric;
    }

    /**
     * フレーズから指標名を逆引き
     */
    private function extract_metric_from_phrase(string $phrase): string {
        // 短い指標名から長い指標名の順にチェック（部分一致を防ぐため長い方優先）
        $metrics = [
            '検索からの訪問数', '検索平均掲載順位', 'エンゲージメント率',
            'コンバージョン率', 'コンバージョン数', 'ダイレクト流入',
            '新規訪問者数', '新規ユーザー数', 'ページ閲覧数', 'ページビュー数',
            '検索表示回数', '検索クリック数', '検索流入数', '自然検索流入',
            'お問い合わせ数', '問い合わせ数', '平均滞在時間', '資料請求数',
            'セッション数', 'リピーター数', '訪問者数', 'ユーザー数',
            '検索流入', '検索露出', '検索順位', '広告流入', 'SNS流入',
            '参照流入', '直帰率', '離脱率', '回遊率', '熟読率',
            '訪問回数', '訪問数', '滞在時間', '閲覧時間',
            '訪問', '閲覧', '成果',
        ];
        foreach ($metrics as $m) {
            if (mb_strpos($phrase, $m) !== false) {
                return $m;
            }
        }
        return '';
    }

    /**
     * フレーズから変化語を抽出
     */
    private function extract_change_from_phrase(string $phrase): string {
        $changes = [
            '大幅増加', '大幅減少', '増加', '減少', '上昇', '低下',
            '改善', '悪化', '向上', '好調', '停滞', '成長', '回復',
            '下落', '横ばい', '初発生', '未発生', '未転換', '低水準',
            '微増', '微減', '獲得', '達成',
        ];
        foreach ($changes as $c) {
            if (mb_strpos($phrase, $c) !== false) {
                return $c;
            }
        }
        return '';
    }

    /**
     * most_important と top_issue の重複を防止
     *
     * 同じ指標が両方に含まれる場合、top_issue を関連的ボトルネックに書き換える。
     */
    private function ensure_no_overlap(string $fact, string $issue): string {
        if ($fact === '' || $issue === '') {
            return $issue;
        }

        $fact_metric  = $this->extract_metric_from_phrase($fact);
        $issue_metric = $this->extract_metric_from_phrase($issue);

        // 同一指標名が含まれる → 関連的ボトルネックに書き換え
        if ($fact_metric !== '' && $fact_metric === $issue_metric) {
            $fact_change = $this->extract_change_from_phrase($fact);
            $relational = $this->build_relational_bottleneck($fact_metric, $fact_change);
            return $this->finalize_highlight($relational, 'issue');
        }

        // 構造的同一チェック（「Xの増加」「Xの減少」パターン）
        $fact_base  = preg_replace('/の(増加|減少|上昇|低下|改善|悪化|好調|停滞|向上|大幅増加|大幅減少|微増|微減)$/u', '', $fact);
        $issue_base = preg_replace('/の(増加|減少|上昇|低下|改善|悪化|好調|停滞|向上|大幅増加|大幅減少|微増|微減)$/u', '', $issue);

        if ($fact_base !== '' && $fact_base === $issue_base) {
            $fact_change = $this->extract_change_from_phrase($fact);
            $metric = $fact_metric !== '' ? $fact_metric : $fact_base;
            $relational = $this->build_relational_bottleneck($metric, $fact_change);
            return $this->finalize_highlight($relational, 'issue');
        }

        return $issue;
    }

    // =========================================================
    // ネクストアクション：課題連動生成
    // =========================================================

    /**
     * 抽象語の単体使用を検出
     *
     * 「SEO対策」「コンテンツ改善」「施策の実施」のような
     * 具体性に欠けるフレーズを true で返す。
     */
    private function is_abstract_standalone(string $phrase): bool {
        // 完全一致で弾くリスト
        $reject_exact = [
            '施策の実施', '推奨施策の実施', '改善施策の実施',
            '改善の取り組み', '対策の実施', '施策の検討', '改善の検討',
        ];
        if (in_array($phrase, $reject_exact, true)) {
            return true;
        }

        // 「〜改善の取り組み」「〜向上のための施策の実施」パターン
        if (preg_match('/改善の取り組み$/u', $phrase)) {
            return true;
        }
        if (preg_match('/向上のための(施策の実施|推奨施策の実施)$/u', $phrase)) {
            return true;
        }

        // 抽象語のみで構成（名詞 + 抽象動詞名詞）
        $abstract_patterns = [
            '/^(コンテンツ|サイト|ページ)(強化|改善|向上|対策|検討|推進)$/u',
            '/^(検索|地域|広告|SNS)(対策|改善|強化|推進)$/u',
            '/^(SEO|MEO|UI|UX)(対策|改善|強化)$/u',
        ];
        foreach ($abstract_patterns as $pat) {
            if (preg_match($pat, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * NLP抽出結果をバリデーションし、抽象的なら課題連動アクションで補完
     *
     * @param string $nlp_action   NLP抽出済みのアクション
     * @param string $top_issue    生成済みの最優先課題
     * @param bool   $is_easy_mode 初心者モード
     * @return string 具体的なアクション名詞句
     */
    private function validate_or_derive_action(string $nlp_action, string $top_issue, bool $is_easy_mode): string {
        // NLP結果が空 → 課題から導出
        if ($nlp_action === '') {
            $derived = $this->build_issue_linked_action($top_issue, $is_easy_mode);
            return $derived !== '' ? $derived : $nlp_action;
        }

        // 抽象語チェック
        if ($this->is_abstract_standalone($nlp_action)) {
            $derived = $this->build_issue_linked_action($top_issue, $is_easy_mode);
            if ($derived !== '') {
                return $derived;
            }
        }

        return $nlp_action;
    }

    /**
     * 課題文字列から具体的アクションを導出
     *
     * top_issue のボトルネック概念を抽出し、対応する具体的アクションを返す。
     * 3段階: (A) 概念直接マッチ → (B) 指標→概念→アクション → (C) ネガティブ表現フォールバック
     */
    private function build_issue_linked_action(string $top_issue, bool $is_easy_mode): string {
        if ($top_issue === '') {
            return '';
        }

        // --- (A) ボトルネック概念 → 具体的アクション ---
        $action_map = [
            '成果転換'         => '問い合わせ導線の明確化',
            '成果への結びつき' => '主要ページへのCTA追加',
            'クリック獲得'     => 'タイトル・説明文の見直し',
            'リピーター化'     => '再訪促進コンテンツの整備',
            '受注獲得'         => '成約事例・実績の掲載強化',
            '回遊促進'         => '関連ページへの内部リンク追加',
            '行動喚起'         => '問い合わせボタンの目立つ配置',
            '安定的な獲得'     => '成果獲得チャネルの多角化',
        ];
        $action_map_easy = [
            '成果転換'         => 'お問い合わせへの案内を明確に',
            '成果への結びつき' => '各ページにお問い合わせボタン追加',
            'クリック獲得'     => '検索結果の説明文を魅力的に',
            'リピーター化'     => 'また来たくなる情報の発信',
            '受注獲得'         => 'お客様の声・実績の追加',
            '回遊促進'         => '関連ページへのリンク追加',
            '行動喚起'         => 'お問い合わせボタンを目立たせる',
            '安定的な獲得'     => '集客の入り口を増やす工夫',
        ];

        $map = $is_easy_mode ? $action_map_easy : $action_map;

        foreach ($map as $concept => $action) {
            if (mb_strpos($top_issue, $concept) !== false) {
                return $this->finalize_highlight($action);
            }
        }

        // --- (B) 指標名逆引き → next_step_map → 概念 → アクション ---
        $issue_metric = $this->extract_metric_from_phrase($top_issue);
        if ($issue_metric !== '') {
            $next_step_map = [
                '訪問者数'       => '成果転換',
                '訪問数'         => '成果転換',
                '訪問回数'       => '成果転換',
                'セッション数'   => '成果転換',
                'ページ閲覧数'   => '成果への結びつき',
                'ページビュー数' => '成果への結びつき',
                '検索表示回数'   => 'クリック獲得',
                '検索露出'       => 'クリック獲得',
                '検索クリック数' => '成果転換',
                '検索流入数'     => '成果転換',
                '自然検索流入'   => '成果転換',
                '検索流入'       => '成果転換',
                '新規訪問者数'   => 'リピーター化',
                '新規ユーザー数' => 'リピーター化',
                'ユーザー数'     => '成果転換',
                '問い合わせ数'   => '受注獲得',
                'お問い合わせ数' => '受注獲得',
                '熟読率'         => '行動喚起',
                '成果'           => '安定的な獲得',
                '直帰率'         => '回遊促進',
                '離脱率'         => '回遊促進',
                'エンゲージメント率' => '成果転換',
                'コンバージョン率'   => '安定的な獲得',
                'コンバージョン数'   => '安定的な獲得',
                // shorten_metric 出力形式
                '訪問'           => '成果転換',
                '閲覧'           => '成果への結びつき',
                '新規訪問'       => 'リピーター化',
                '訪問者'         => '成果転換',
                '問い合わせ'     => '受注獲得',
            ];
            $concept = $next_step_map[$issue_metric] ?? '';
            if ($concept !== '' && isset($map[$concept])) {
                return $this->finalize_highlight($map[$concept]);
            }
        }

        // --- (C) フォールバック: ネガティブ表現パターンから推定 ---
        $fallback = $is_easy_mode ? [
            '低迷'     => 'お客さんの来るルートの見直し',
            '伸び悩み' => '改善ページの特定と修正',
            '不足'     => '不足部分への具体的な対策追加',
            '課題'     => '重点課題への集中対応',
            '高止まり' => 'ページ内容の見直し',
            '停滞'     => '新しい施策の追加',
            '未転換'   => 'お問い合わせへの案内を明確に',
            '未発生'   => 'お問い合わせしやすい仕組み作り',
        ] : [
            '低迷'     => '集客チャネルの見直し',
            '伸び悩み' => '改善対象ページの特定と修正',
            '不足'     => '不足指標の重点的な改善',
            '課題'     => '重点課題への施策実行',
            '高止まり' => 'ページ内容の見直しと最適化',
            '停滞'     => '新規施策の導入',
            '未転換'   => '問い合わせ導線の明確化',
            '未発生'   => '成果獲得の仕組みづくり',
        ];

        foreach ($fallback as $keyword => $action) {
            if (mb_strpos($top_issue, $keyword) !== false) {
                return $this->finalize_highlight($action);
            }
        }

        return '';
    }
}
