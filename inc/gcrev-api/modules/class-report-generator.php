<?php
/**
 * Gcrev_Report_Generator
 *
 * マルチパスセクション生成・HTMLレイアウト組み立て・ヘルパーメソッド群。
 * Step3-B で class-gcrev-api.php から分離。
 *
 * @package GCREV_INSIGHT
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Report_Generator') ) { return; }

class Gcrev_Report_Generator {

    private Gcrev_Config            $config;
    private Gcrev_AI_Client         $ai;
    private Gcrev_GA4_Fetcher       $ga4;
    private Gcrev_Report_Repository $repo;

    // ===== レポート・セクション生成設定 =====
    // 1回のGemini呼び出しで生成するセクション数の上限
    private const SECTIONS_PER_CALL    = 2;
    // セクション不完全時の最大リトライ回数（10→3 に削減: 暴走防止）
    private const MAX_SECTION_RETRIES  = 3;

    /**
     * セクション定義の順序と必須クラス
     * この順序で分割生成する
     */
    public const REPORT_SECTIONS = [
        [
            'id'    => 'summary',
            'class' => 'summary',
            'label' => '結論サマリー',
        ],
        [
            'id'    => 'good',
            'class' => 'good-points',
            'label' => '今月の良かった点',
        ],
        [
            'id'    => 'bad',
            'class' => 'improvement-points',
            'label' => '改善が必要な点',
        ],
        [
            'id'    => 'area',
            'class' => 'area-box',
            'label' => 'ターゲットエリアの状況',
        ],
        [
            'id'    => 'insight',
            'class' => 'insight-box',
            'label' => '考察（事実と可能性）',
        ],
        [
            'id'    => 'action',
            'class' => 'actions',
            'label' => 'ネクストアクション',
        ],
    ];

    public function __construct(
        Gcrev_Config            $config,
        Gcrev_AI_Client         $ai,
        Gcrev_GA4_Fetcher       $ga4,
        Gcrev_Report_Repository $repo
    ) {
        $this->config = $config;
        $this->ai     = $ai;
        $this->ga4    = $ga4;
        $this->repo   = $repo;
    }

    // =========================================================
    // Multi-pass レポート生成（既存コード維持）
    // =========================================================
    public function generate_report_multi_pass(array $prev_data, array $two_data, array $client_info, ?string $target_area = null): string {

        $all_html = '';
        $retry_count = 0;

        // 追加仕様：全国ターゲット（エリア未指定）の場合はエリアセクションを生成しない
        $sections = self::REPORT_SECTIONS;
        if ($target_area === null) {
            $sections = array_values(array_filter($sections, fn($s) => ($s['id'] ?? '') !== 'area'));
        }

        while (count($this->get_missing_sections($all_html, $sections)) > 0 && $retry_count < self::MAX_SECTION_RETRIES) {
            $missing = $this->get_missing_sections($all_html, $sections);
            if (empty($missing)) break;

            $target_sections = array_slice($missing, 0, self::SECTIONS_PER_CALL);

            // 詳細ログ
            $section_ids = array_column($target_sections, 'id');
            $section_labels = array_column($target_sections, 'label');
            error_log("[GCREV] === Retry {$retry_count} ===");
            error_log("[GCREV] Target sections: " . implode(', ', $section_ids));
            error_log("[GCREV] Labels: " . implode(', ', $section_labels));
            error_log("[GCREV] All missing: " . implode(', ', array_column($missing, 'id')));

            $prompt = $this->build_section_prompt($prev_data, $two_data, $client_info, $target_sections, $target_area);
            
            // プロンプト長をログ
            error_log("[GCREV] Prompt length: " . strlen($prompt) . " chars");
            
            $text   = $this->ai->call_gemini_api($prompt);
            $html   = $this->normalize_report_html($text);

            // レスポンス分析
            error_log("[GCREV] Gemini response length: " . strlen($text) . " chars");
            error_log("[GCREV] Normalized HTML length: " . strlen($html) . " chars");
            
            // 各セクションの有無を確認
            foreach ($target_sections as $check_sec) {
                $class_pattern = 'class="' . $check_sec['class'] . '"';
                if (stripos($html, $class_pattern) !== false) {
                    error_log("[GCREV] ✅ Section '{$check_sec['id']}' ({$check_sec['label']}) found in HTML");
                } else {
                    error_log("[GCREV] ❌ Section '{$check_sec['id']}' ({$check_sec['label']}) NOT found in HTML");
                    error_log("[GCREV] Looking for: " . $class_pattern);
                }
            }

            // セクション抽出
            foreach ($target_sections as $sec) {
                $section_html = $this->extract_section_html($html, $sec);
                if ($section_html !== '') {
                    $all_html .= "\n" . $section_html;
                    error_log("[GCREV] ✅ Extracted '{$sec['id']}': " . strlen($section_html) . " chars");
                    
                    // actionsセクションの場合、内容をプレビュー
                    if ($sec['id'] === 'action') {
                        $preview = mb_substr(strip_tags($section_html), 0, 100);
                        error_log("[GCREV] Actions preview: {$preview}...");
                    }
                } else {
                    error_log("[GCREV] ❌ Failed to extract '{$sec['id']}'");
                }
            }

            $retry_count++;
            error_log("[GCREV] === End of Retry {$retry_count} ===");
        }

        $still_missing = $this->get_missing_sections($all_html, $sections);
        if (!empty($still_missing)) {
            $missing_labels = implode(', ', array_column($still_missing, 'label'));
            error_log("[GCREV] Multi-pass: WARNING - still missing sections after retries: {$missing_labels}");
        }

        return trim($all_html);
    }

    public function get_missing_sections(string $html, ?array $sections = null): array {
        $missing = [];
        foreach (($sections ?? self::REPORT_SECTIONS) as $sec) {
            if (stripos($html, 'class="' . $sec['class'] . '"') === false) {
                $missing[] = $sec;
            }
        }
        return $missing;
    }

    public function build_section_prompt(array $prev, array $two, array $client, array $target_sections, ?string $target_area = null): string {

        $section_labels = implode('、', array_column($target_sections, 'label'));
        $area_label = $target_area ?? '全国';

         // 出力モード取得
        $output_mode = $client['output_mode'] ?? 'normal';
        $is_easy_mode = ($output_mode === 'easy');
        
        // 初心者向けモード用の追加指示
        $mode_instruction = '';
        if ($is_easy_mode) {
            $mode_instruction = <<<'MODE'


        🔰🔰🔰 【最重要：初心者向けかみくだきモード】 🔰🔰🔰

        このレポートは「ウェブの知識がまったくない個人事業主・中小企業の社長」が読みます。
        あなたは、この方の隣に座って優しく教えてくれる「頼れるWeb担当の友人」です。

        ■ 絶対ルール
        1. **専門用語は一切使わない**。PV・セッション・直帰率・CTR・CVR・エンゲージメント等のWeb用語は禁止。
           代わりに以下のような言葉に置き換える：
           - セッション → 「ホームページに来てくれた人の数」
           - PV → 「見られたページの数」
           - 直帰率 → 「1ページだけ見て帰った人の割合」
           - CVR → 「来てくれた人のうち問い合わせしてくれた割合」
           - エンゲージメント率 → 「じっくり見てくれた人の割合」
           - オーガニック検索 → 「Google検索から来た人」
           - インプレッション → 「検索結果に表示された回数」
           - クリック数 → 「検索結果からクリックされた回数」
           - 流入元 → 「どこからホームページに来たか」
        2. **「ホームページ」という言葉をそのまま使う**。「お店」などの比喩は使わない。
        3. **1文は短く**（30文字前後）。だらだら続けない。
        4. **「つまりどういうこと？」を毎回書く**。数字だけ並べない。必ず「これは○○ということです」と意味を添える。
        5. **具体的な改善アクション**は「何を」「どこに」「どうする」まで書く。
           - ❌「コンテンツを強化しましょう」
           - ⭕「トップページに『愛媛の制作実績3選』というバナーを大きく貼りましょう」
        6. **数値は必ず前月と比較**して「増えた/減った/変わらない」を明記する。
        7. **感情や温度感のある表現を使う**。「すごい！」「ちょっと心配」「これはチャンス！」など。
        8. **ターゲットエリアがある場合は「地元の○○の人」と表現**する。

        ■ 各セクションの書き方

        【summaryセクション】
        今月のホームページの状態を、友人に話すように**2〜3文で簡潔にまとめる**。
        見出しやテーブルは入れず、<p>タグだけで書く。
        例：「今月はホームページに来てくれた人がグッと増えました！ただ、1ページだけ見て帰る人も増えているので、もっと読みたくなる工夫が必要です。検索での存在感は着実に育っています。」

        【good-pointsセクション】
        良いニュースを伝える。
        - 各ポイントに**小見出し**（太字）を付けて、その下に「なぜ良いのか」「どういう意味か」を友人に話すように書く。
        - 例：「<strong>見られた回数は1.7倍！</strong>検索結果に表示された回数がドンと増えました。まずは知ってもらわないと始まらないので、これは大きな一歩です。」
        - 最低2つ、最大4つ。

        【improvement-pointsセクション】
        問題点を伝える。
        - 冒頭に1〜2文で「今の悩みはこういうことです」と要約。
        - 各課題は番号付きで、**見出し→説明→「対策：」**の3段構成。
        - 「対策：」は太字にして、具体的に「何を」「どこに」「どうする」まで書く。
        - 最低2つ、最大4つ。

        【area-boxセクション】（ターゲットエリアがある場合）
        「地元の人の動き」として書く。
        - 「○○県からホームページに来た人が□□人で、先月より△△人減りました」のように書く。
        - 「これは○○ということです」の解説を必ず付ける。

        【insight-boxセクション】
        **「今のホームページの状態をひと言で言うと」**という親しみやすいトーンで書く。
        - データから見えることと、そこから推測できることを分けて書く。
        - 推測は「〜かもしれません」「〜の可能性があります」と断定しない。

        【actionsセクション】
        「今後の作戦」として書く。
        - 冒頭に1〜2文で全体の方向性を示す。
        - **「私からの提案：」**として、一番手軽で効果が出やすい1つの施策を具体的に提案する。
        - 「まずは○○してみませんか？」という提案口調で締める。

        MODE;
                }

                $prompt = <<<PROMPT
        あなたは日本企業向けのウェブ解析レポート作成AIです。

        以下のデータとクライアント情報を元に、**{$section_labels}**のセクションのみを生成してください。
        {$mode_instruction}

        # クライアント情報
        - サイトURL: {$client['site_url']}
        - 主要ターゲット: {$client['target']}
        - ターゲットエリア（都道府県）: {$area_label}
        - 課題: {$client['issue']}
        - 今月の目標: {$client['goal_monthly']}
        - 注目指標: {$client['focus_numbers']}
        - 現状: {$client['current_state']}
        - 主要目標: {$client['goal_main']}
        - その他留意事項: {$client['additional_notes']}

        # 前々月データ（比較基準）
        PROMPT;
        $prompt .= "\n" . $this->format_data_for_prompt($two);

        $prompt .= "\n\n# 前月データ（最新）\n";
        $prompt .= $this->format_data_for_prompt($prev);
        $prompt .= <<<INSTRUCTIONS


        # 必須要件
        1. 以下のセクションのみを、必ず指定されたclass名で出力すること：

        INSTRUCTIONS;

        foreach ($target_sections as $sec) {
            $prompt .= "\n- {$sec['label']}: `<div class=\"{$sec['class']}\">...</div>`";

            // actionsセクションの場合は詳細な指示を追加（モード別）
            if ($sec['id'] === 'action') {
                $prompt .= "\n\n";
                $prompt .= "⚠️⚠️⚠️ **【最重要】ネクストアクション（actions）の生成について** ⚠️⚠️⚠️\n\n";
                $prompt .= "このセクションは**絶対に省略できません**。以下の要件を**必ず**守ること:\n";
                $prompt .= "**「AI生成に失敗しました」などのエラーメッセージは絶対に出力しないこと**\n\n";

                if ($is_easy_mode) {
                    $prompt .= "【初心者モード：actionsの構造】\n";
                    $prompt .= "```html\n";
                    $prompt .= "<div class=\"actions\">\n";
                    $prompt .= "  <h2>💡 今後の作戦（ネクストステップ）</h2>\n";
                    $prompt .= "  <p>（全体の方向性を1〜2文で）</p>\n";
                    $prompt .= "  <div class=\"action-item\">\n";
                    $prompt .= "    <div class=\"action-priority\">私からの提案</div>\n";
                    $prompt .= "    <div class=\"action-title\">（一番手軽で効果が出やすい施策タイトル）</div>\n";
                    $prompt .= "    <div class=\"action-description\">（「何を」「どこに」「どうする」を具体的に。「〜してみませんか？」の口調で）</div>\n";
                    $prompt .= "  </div>\n";
                    $prompt .= "</div>\n";
                    $prompt .= "```\n";
                    $prompt .= "※ 初心者モードでは Priority 表記は不要。「私からの提案」1つに絞って具体的に書くこと。\n";
                } else {
                    $prompt .= "【必須のHTML構造】以下の構造を**完全に**再現すること:\n\n";
                    $prompt .= "```html\n";
                    $prompt .= "<div class=\"actions\">\n";
                    $prompt .= "  <h2>🚀 今すぐやるべき3つのアクション</h2>\n";
                    $prompt .= "  <div class=\"action-item\">\n";
                    $prompt .= "    <div class=\"action-priority\">Priority 1 - 最優先</div>\n";
                    $prompt .= "    <div class=\"action-title\">【20文字以内の具体的なアクション名】</div>\n";
                    $prompt .= "    <div class=\"action-description\">【100文字程度の詳細説明。必ず数値目標を含める】</div>\n";
                    $prompt .= "  </div>\n";
                    $prompt .= "  <div class=\"action-item\">\n";
                    $prompt .= "    <div class=\"action-priority\">Priority 2 - 最優先</div>\n";
                    $prompt .= "    <div class=\"action-title\">【2つ目のアクション名】</div>\n";
                    $prompt .= "    <div class=\"action-description\">【2つ目の詳細説明】</div>\n";
                    $prompt .= "  </div>\n";
                    $prompt .= "  <div class=\"action-item\">\n";
                    $prompt .= "    <div class=\"action-priority\">Priority 3 - 中優先</div>\n";
                    $prompt .= "    <div class=\"action-title\">【3つ目のアクション名】</div>\n";
                    $prompt .= "    <div class=\"action-description\">【3つ目の詳細説明】</div>\n";
                    $prompt .= "  </div>\n";
                    $prompt .= "</div>\n";
                    $prompt .= "```\n\n";
                    $prompt .= "- 各アクションには具体的な数値目標を含めること\n";
                    $prompt .= "- GA4データとSearch Consoleデータに基づいた実行可能な施策であること\n";
                }
                $prompt .= "\n**このセクションを省略した場合、レポートは不完全とみなされます。必ず生成してください。**\n";
            }
        }

        if ($is_easy_mode) {
            $prompt .= <<<STYLE_EASY


        2. **これらのセクション以外は絶対に出力しないこと**

        3a. 「ターゲットエリアの状況（area-box）」を生成する場合：
           - 「地元のお客さんの動き」として、{$area_label} からの来店数・全体に占める割合・先月との比較を書く
           - 専門用語は使わない

        3. 各セクションの内容は具体的な数値を引用し、前月と比較すること

        4. HTMLのみ出力し、コードブロック（```）は使用しない

        5. **視認性向上のため、以下を必ず適用すること**：
           - **すべての数値を `<strong>` タグで囲む**（例外なし）
           - 重要なキーワード・結論も `<strong>` タグで強調する
           - **Markdownの「**」や「__」は絶対に使用禁止。HTMLの `<strong>` タグのみ使用**

        6. HTML構造例（初心者モード — この形を厳守）：

           - 結論サマリー（summary）:
             `<div class="summary"><p>（今月の状態を友人に話すように2〜3文で。見出し・テーブルは入れない）</p></div>`

           - 今月の良かった点（good-points）:
             `<div class="good-points"><h3>⭕ 良かったこと</h3><ul class="point-list"><li><strong>見出し</strong>説明文...</li></ul></div>`
             各liの中は「<strong>小見出し</strong>＋説明」の形。最低2つ、最大4つ。

           - 改善が必要な点（improvement-points）:
             `<div class="improvement-points"><h3>❌ 課題</h3><p>（要約1〜2文）</p><ol><li><strong>見出し</strong><br>説明文<br><strong>対策：</strong>具体的な改善アクション</li></ol></div>`
             各liの中は「<strong>見出し</strong>＋説明＋<strong>対策：</strong>具体策」の3段構成。最低2つ、最大4つ。

           - ターゲットエリアの状況（area-box）:
             `<div class="area-box"><div class="consideration"><h3>🏠 地元のお客さんの動き</h3><p>...</p></div></div>`

           - 考察（insight-box）:
             `<div class="insight-box"><div class="consideration"><h3>今のサイトの状態をひと言で言うと</h3><p>...</p></div></div>`

           - ネクストアクション（actions）:
             `<div class="actions"><h2>💡 今後の作戦（ネクストステップ）</h2><p>（方向性）</p><div class="action-item"><div class="action-priority">私からの提案</div><div class="action-title">...</div><div class="action-description">...してみませんか？</div></div></div>`

        7. **出力はHTMLのみ**（``` は使用しない）。`<style>` や `<html><head><body>`、`<div class="container">`、`<div class="section">` は出力しない（こちらで外枠は組み立てます）

        8. 必ず日本語で出力すること

        それでは**{$section_labels}**のセクションのみを生成してください：
        STYLE_EASY;
        } else {
            $prompt .= <<<STYLE


        2. **これらのセクション以外は絶対に出力しないこと**

        3a. 「ターゲットエリアの状況（area-box）」を生成する場合：
           - ターゲットエリアが「全国」ではないときは、**GA4の地域別（都道府県=region）のセッション内訳**を必ず参照し、{$area_label} からのセッション数・全体比・前々月比/前月比を具体的に書くこと
           - city（例：Matsuyama など）の上位も参考情報として触れてよい
           - 「把握できない」「データがない」等の断定はしない（データは提示済み）


        3. 各セクションの内容は具体的な数値を引用し、前々月と前月を比較すること

        4. HTMLのみ出力し、コードブロック（```）は使用しない

        5. **視認性向上のため、以下を必ず適用すること**：
           - **すべての数値を `<strong>` タグで囲む**（例外なし）
           - 重要なキーワード・動詞・形容詞も `<strong>` タグで強調する
           - **Markdownの「**」や「__」は絶対に使用禁止。HTMLの `<strong>タグ` のみ使用**

           具体例（必ずこの形式で出力すること）：
           ❌ 悪い例: セッション数が前々月と比較して増加し、+8.1%の322セッションを記録
           ✅ 良い例: セッション数が前々月と比較して<strong>増加</strong>し、<strong>+8.1%</strong>の<strong>322セッション</strong>を記録

           ❌ 悪い例: **大幅に減少**しており、約**45%減**となっています
           ✅ 良い例: <strong>大幅に減少</strong>しており、約<strong>45%減</strong>となっています

           ❌ 悪い例: 愛媛県からのセッションは73セッションから41セッションへと減少
           ✅ 良い例: 愛媛県からのセッションは<strong>73セッション</strong>から<strong>41セッション</strong>へと<strong>減少</strong>

           強調すべき要素：
           - 数値: 8.1%, 322, 73, 41, 780, 430 など → すべて<strong>で囲む
           - 増減: 増加、減少、上昇、低下、改善、悪化 → すべて<strong>で囲む
           - 程度: 大幅に、微増、激減、大きく → すべて<strong>で囲む
           - 重要語: ターゲット層、ミスマッチ、課題、改善 → すべて<strong>で囲む

        6. HTML構造例（この形を厳守）：
           - 結論サマリー（summary）:
             `<div class="summary"><p>...</p></div>`

           - 今月の良かった点（good-points）:
             `<div class="good-points"><ul class="point-list"><li>...</li></ul></div>`
             **【重要】最低3つの項目を必ず記載すること。ただし、不必要に項目を増やさないこと(最大5つ程度)**
             **【禁止】「つまり何をすればいいか」「具体的なアクション」などの余計な小見出しは絶対に出力しないこと**

           - 改善が必要な点（improvement-points）:
             `<div class="improvement-points"><ul class="point-list"><li>...</li></ul></div>`
             **【重要】最低3つの項目を必ず記載すること。ただし、不必要に項目を増やさないこと(最大5つ程度)**
             **【禁止】「つまり何をすればいいか」「具体的なアクション」などの余計な小見出しは絶対に出力しないこと**

           - ターゲットエリアの状況（area-box）※target_area がある場合のみ:
             `<div class="area-box"><div class="consideration"><h3>データから分かる事実</h3><p>...</p><h3>考えられる可能性</h3><p>...</p><ul style="padding-left: 20px; margin-top: 10px;"><li style="margin-bottom: 8px;">...</li></ul></div></div>`

           - 考察（insight-box）:
             `<div class="insight-box"><div class="consideration"><h3>データから分かる事実</h3><ul style="padding-left: 20px; margin-bottom: 20px;"><li style="margin-bottom: 8px;">...</li></ul><h3>そこから考えられる可能性</h3><p>...</p></div></div>`

           - ネクストアクション（actions）:
             `<div class="actions"><h2>🚀 今すぐやるべき3つのアクション</h2><div class="action-item"><div class="action-priority">Priority 1 - 最優先</div><div class="action-title">...</div><div class="action-description">...</div></div> ...</div>`

        7. **出力はHTMLのみ**（``` は使用しない）。`<style>` や `<html><head><body>`、`<div class="container">`、`<div class="section">` は出力しない（こちらで外枠は組み立てます）

        8. 必ず日本語で出力すること

        それでは**{$section_labels}**のセクションのみを生成してください：
        STYLE;
        }

        return $prompt;
    }

    public function format_data_for_prompt(array $data): string {

        // null安全なネストアクセス（前々月データが空の場合の白画面防止）
        $current_period = $data['current_period'] ?? [];
        $display = $current_period['display'] ?? [];
        $period = $display['text'] ?? '期間不明';

        $ga4 = $data['ga4'] ?? [];
        $gsc = $data['gsc'] ?? [];

        $output = "期間: {$period}\n";
        $output .= "【GA4データ】\n";
        $output .= "- 合計PV: " . ($ga4['total']['pageViews'] ?? '不明') . "\n";
        $output .= "- セッション数: " . ($ga4['total']['sessions'] ?? '不明') . "\n";
        $output .= "- エンゲージメント率: " . ($ga4['total']['engagementRate'] ?? '不明') . "\n";

        if (!empty($ga4['pages'])) {
            $output .= "- 上位ページ（PV順）:\n";
            $pages_to_show = array_slice($ga4['pages'], 0, 5);
            foreach ($pages_to_show as $p) {
                $title = $p['title'] ?? 'no-title';
                $pv    = $p['pageViews'] ?? '0';
                $output .= "  - {$title}: {$pv}PV\n";
            }
        }

        
        // 地域（都道府県 / city）の内訳（セッション順）
        $geo_region = $data['geo_region'] ?? [];
        $geo_city   = $data['geo'] ?? [];

        if (!empty($geo_region)) {
            $output .= "- 地域別（都道府県）上位（セッション順）:\n";
            $rows = array_slice($geo_region, 0, 5);
            foreach ($rows as $r) {
                $name = $r['name'] ?? ($r['region'] ?? '');
                $ses  = $r['sessions'] ?? '0';
                $output .= "  - {$name}: {$ses} sessions\n";
            }
        } elseif (!empty($geo_city)) {
            $output .= "- 地域別（city）上位（セッション順）:\n";
            $rows = array_slice($geo_city, 0, 5);
            foreach ($rows as $r) {
                $name = $r['name'] ?? ($r['city'] ?? '');
                $ses  = $r['sessions'] ?? '0';
                $output .= "  - {$name}: {$ses} sessions\n";
            }
        }

        $output .= "\n【Search Consoleデータ】\n";
                $output .= "- 合計表示回数: " . ($gsc['total']['impressions'] ?? '不明') . "\n";
                $output .= "- 合計クリック数: " . ($gsc['total']['clicks'] ?? '不明') . "\n";
                $output .= "- 平均CTR: " . ($gsc['total']['ctr'] ?? '不明') . "\n";

                if (!empty($gsc['keywords'])) {
                    $output .= "- 上位キーワード（表示回数順）:\n";
                    $keywords_to_show = array_slice($gsc['keywords'], 0, 5);
                    foreach ($keywords_to_show as $k) {
                        $query = $k['query'] ?? 'no-query';
                        $impr  = $k['impressions'] ?? '0';
                        $click = $k['clicks'] ?? '0';
                        $output .= "  - {$query}: 表示 {$impr}回, クリック {$click}回\n";
                    }
                }

                return $output;
            }

    public function validate_section_complete(string $html, array $section): bool {

        $class = $section['class'];

        if (stripos($html, 'class="' . $class . '"') === false) {
            return false;
        }

        $section_html = $this->extract_section_html($html, $section);
        if ($section_html === '') return false;

        if (stripos($section_html, '</div>') === false) {
            return false;
        }

        return true;
    }

    public function detect_target_area(string $target_text): ?string {
        return Gcrev_Area_Detector::detect($target_text);
    }

    // =========================================================
    // 追加仕様：sample_report.html と同等のレイアウトで最終HTMLを組み立て
    // =========================================================
    public function build_sample_layout_report_html(
        array $prev_data,
        array $two_data,
        array $client_info,
        string $sections_html,
        ?string $target_area,
        array $selected_events,
        array $prev_key_events,
        array $two_key_events
       ): string {

        // ---- CSS（sample_report.html をレポート部分だけにスコープ） ----
        $css = <<<'CSS'
        .ai-report *{
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }.ai-report{
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
            line-height: 1.8;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }.ai-report .container{
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }.ai-report .sample-notice{
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
            border: 3px solid #c92a2a;
            box-shadow: 0 4px 15px rgba(201, 42, 42, 0.3);
        }.ai-report .sample-notice h3{
            font-size: 20px;
            margin-bottom: 10px;
            font-weight: bold;
        }.ai-report .sample-notice p{
            font-size: 15px;
            line-height: 1.6;
            margin: 5px 0;
        }.ai-report .header{
            border-bottom: 3px solid #2c5aa0;
            padding-bottom: 20px;
            margin-bottom: 40px;
        }.ai-report .header h1{
            font-size: 28px;
            color: #2c5aa0;
            margin-bottom: 10px;
        }.ai-report .header .period{
            font-size: 16px;
            color: #666;
        }.ai-report .stats-grid{
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }.ai-report .stat-card{
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }.ai-report .stat-label{
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }.ai-report .stat-value{
            font-size: 32px;
            font-weight: bold;
            color: #2c5aa0;
            margin-bottom: 5px;
        }.ai-report .stat-change{
            font-size: 14px;
            font-weight: bold;
        }.ai-report .stat-change.negative{
            color: #e53935;
        }.ai-report .stat-change.positive{
            color: #43a047;
        }.ai-report .stat-change.neutral{
            color: #757575;
        }.ai-report .stat-subtext{
            font-size: 13px;
            color: #888;
            margin-top: 5px;
        }.ai-report .summary{
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 40px;
        }.ai-report .summary, .ai-report .summary *{
            color: #fff !important;
        }.ai-report .easy-summary-table{
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
            font-size: 14px;
        }.ai-report .easy-summary-table th,
        .ai-report .easy-summary-table td{
            padding: 12px 14px;
            border: 1px solid #e0e0e0;
            text-align: left;
            vertical-align: top;
        }.ai-report .easy-summary-table thead th{
            background: #2c5aa0;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
        }.ai-report .easy-summary-table tbody td:nth-child(2){
            white-space: nowrap;
            text-align: center;
            font-size: 15px;
        }.ai-report .easy-summary-table tbody tr:nth-child(even){
            background: #f8f9fa;
        }.ai-report .summary h2{
            font-size: 22px;
            margin-bottom: 15px;
            border-bottom: 2px solid rgba(255,255,255,0.3);
            padding-bottom: 10px;
        }.ai-report .summary-content{
            font-size: 16px;
            line-height: 2;
        }.ai-report .section{
            margin-bottom: 50px;
        }.ai-report .section h2{
            font-size: 22px;
            color: #2c5aa0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }.ai-report .good-points{
            background: #e8f5e9;
            padding: 25px;
            border-radius: 8px;
            border-left: 5px solid #4caf50;
        }.ai-report .improvement-points{
            background: #fff3e0;
            padding: 25px;
            border-radius: 8px;
            border-left: 5px solid #ff9800;
        }.ai-report .point-list{
            list-style: none;
            padding-left: 0;
        }.ai-report .point-list li{
            padding: 12px 0;
            padding-left: 30px;
            position: relative;
        }.ai-report .good-points .point-list li:before{
            content: "✓";
            position: absolute;
            left: 5px;
            color: #4caf50;
            font-weight: bold;
            font-size: 18px;
        }.ai-report .improvement-points .point-list li:before{
            content: "▶";
            position: absolute;
            left: 5px;
            color: #ff9800;
            font-weight: bold;
        }.ai-report .table-container{
            overflow-x: auto;
            margin: 20px 0;
        }.ai-report table{
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
        }.ai-report thead{
            background: #2c5aa0;
            color: white;
        }.ai-report th{
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }.ai-report td{
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }.ai-report tbody tr:hover{
            background: #f5f5f5;
        }.ai-report .consideration{
            background: #f8f9fa;
            padding: 30px;
            border-radius: 8px;
            margin: 30px 0;
            border-left: 4px solid #2c5aa0;
        }.ai-report .consideration h3{
            color: #2c5aa0;
            margin-bottom: 15px;
            font-size: 18px;
        }.ai-report .consideration p{
            margin-bottom: 15px;
            line-height: 2;
        }.ai-report .actions{
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: black;
            padding: 30px;
            border-radius: 8px;
        }.ai-report .actions h2{
            color: white;
            border-bottom-color: rgba(255,255,255,0.3);
        }.ai-report .action-item{
            background: rgba(255,255,255,1);
            padding: 20px;
            margin: 15px 0;
            border-radius: 6px;
            border-left: none;
            }.ai-report .action-priority{
            display: inline-block;
            background: #f5576c;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 10px;
        }.ai-report .action-title{
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }.ai-report .action-description{
            font-size: 16px;
            line-height: 1.8;
        }.ai-report .alert-box{
            background: #ffebee;
            border-left: 4px solid #e53935;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }.ai-report .alert-box strong{
            color: #c62828;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }.ai-report .sample-notice{
                padding: 15px 20px;
            }.ai-report .sample-notice h3{
                font-size: 18px;
            }.ai-report .sample-notice p{
                font-size: 14px;
            }.ai-report .header h1{
                font-size: 22px;
            }.ai-report .stats-grid{
                grid-template-columns: 1fr;
            }.ai-report .stat-value{
                font-size: 28px;
            }.ai-report table{
                font-size: 12px;
            }.ai-report th, .ai-report td{
                padding: 8px;
            }.ai-report .company-info-grid{
                grid-template-columns: 1fr;
                gap: 5px;
            }.ai-report .company-info-label{
                margin-top: 10px;
        }
        
        /* 視認性向上: strong タグのスタイル */
        .ai-report strong {
            font-weight: 700;
            color: #1a1a1a;
            background: linear-gradient(transparent 60%, #fff59d 60%);
            padding: 0 2px;
        }
        
        /* セクション別のstrongスタイル */
        .ai-report .summary strong {
            color: #fff;
            background: linear-gradient(transparent 60%, rgba(255,255,255,0.3) 60%);
            font-weight: 700;
        }
        
        .ai-report .actions strong {
            color: #c62828;
            background: transparent;
            font-weight: 700;
        }
        
        .ai-report .good-points strong {
            color: #1b5e20;
            background: linear-gradient(transparent 60%, #c8e6c9 60%);
            font-weight: 700;
        }
        
        .ai-report .improvement-points strong {
            color: #e65100;
            background: linear-gradient(transparent 60%, #ffe0b2 60%);
            font-weight: 700;
        }
        
        .ai-report .consideration strong {
            color: #0d47a1;
            background: linear-gradient(transparent 60%, #bbdefb 60%);
        }
        
        /* 印刷対応 */
        @media print {
            @page {
                margin: 10mm;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
            
            .ai-report {
                background: white;
                padding: 0;
                margin: 0;
            }
            .ai-report .container {
                max-width: 100%;
                padding: 20px;
                box-shadow: none;
                page-break-before: avoid;
                margin: 0;
            }
            .ai-report .header {
                page-break-after: avoid;
                margin-top: 0;
            }
            .ai-report .section {
                page-break-inside: avoid;
            }
            .ai-report .action-item {
                page-break-inside: avoid;
            }
            .ai-report .stats-grid {
                page-break-inside: avoid;
            }
            /* 印刷時もマーカー表示を維持 */
            .ai-report strong {
                background: #ffeb3b;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .ai-report .good-points strong {
                background: #c8e6c9;
            }
            .ai-report .improvement-points strong {
                background: #ffe0b2;
            }
            .ai-report .consideration strong {
                background: #bbdefb;
            }
        }
        }
        CSS;

        // ---- 期間表示（null安全） ----
        $two_period  = $two_data['current_period'] ?? [];
        $prev_period = $prev_data['current_period'] ?? [];
        $two_start = (string)($two_period['start'] ?? '');
        $prev_end  = (string)($prev_period['end']  ?? '');
        $site_url  = (string)($client_info['site_url'] ?? '');

        $period_text = '';
        if ($two_start && $prev_end) {
            $period_text = "対象期間：{$two_start}〜{$prev_end}（前月・前々月の比較）";
        }

        $site_line = $site_url !== '' ? "サイト：{$site_url} 様" : '';

        // ---- 数値（前月=prev / 前々月=two） ----
        $pv_prev   = (int)($prev_data['ga4']['total']['_pageViews'] ?? 0);
        $pv_two    = (int)($two_data['ga4']['total']['_pageViews'] ?? 0);

        $sess_prev = (int)($prev_data['ga4']['total']['_sessions'] ?? 0);
        $sess_two  = (int)($two_data['ga4']['total']['_sessions'] ?? 0);

        $users_prev= (int)($prev_data['ga4']['total']['_users'] ?? 0);
        $users_two = (int)($two_data['ga4']['total']['_users'] ?? 0);

        $new_prev  = (int)($prev_data['ga4']['total']['_newUsers'] ?? 0);
        $new_two   = (int)($two_data['ga4']['total']['_newUsers'] ?? 0);

        $dur_prev  = (float)($prev_data['ga4']['total']['_avgDuration'] ?? 0);
        $dur_two   = (float)($two_data['ga4']['total']['_avgDuration'] ?? 0);

        $engR_prev = (float)($prev_data['ga4']['total']['_engagementRate'] ?? 0);
        $engR_two  = (float)($two_data['ga4']['total']['_engagementRate'] ?? 0);

        // 1訪問あたりのページ数
        $pps_prev = $sess_prev > 0 ? ($pv_prev / $sess_prev) : 0;
        $pps_two  = $sess_two  > 0 ? ($pv_two  / $sess_two)  : 0;

        // 検索流入（organic sessions）
        $org_prev = $this->sum_medium_sessions($prev_data['medium'] ?? [], ['organic', 'Organic Search', '(organic)']);
        $org_two  = $this->sum_medium_sessions($two_data['medium']  ?? [], ['organic', 'Organic Search', '(organic)']);

        // スマホ比率
        [$mobile_prev, $pc_prev, $tab_prev] = $this->device_ratio($prev_data['devices'] ?? [], $sess_prev);
        [$mobile_two,  $pc_two,  $tab_two ] = $this->device_ratio($two_data['devices']  ?? [], $sess_two);

        // キーイベント（お問い合わせ / 資料DL）
        $contact = $selected_events['contact']['name'] ?? null;
        $download= $selected_events['download']['name'] ?? null;

        $contact_prev = $contact ? (int)($prev_key_events[$contact] ?? 0) : 0;
        $contact_two  = $contact ? (int)($two_key_events[$contact]  ?? 0) : 0;

        $download_prev = $download ? (int)($prev_key_events[$download] ?? 0) : 0;
        $download_two  = $download ? (int)($two_key_events[$download]  ?? 0) : 0;

        $cvr_prev = ($sess_prev > 0) ? ($contact_prev / $sess_prev) * 100 : 0;

        // ターゲットエリア流入（sessions by region）
        $area_card_html = '';
        if ($target_area !== null) {
            $area_prev = $this->sum_region_sessions($prev_data['geo_region'] ?? ($prev_data['geo'] ?? []), $target_area);
            $area_two  = $this->sum_region_sessions($two_data['geo_region']  ?? ($two_data['geo'] ?? []), $target_area);

            $area_rate = ($sess_prev > 0) ? ($area_prev / $sess_prev) * 100 : 0;
            $area_change = $this->pct_change($area_prev, $area_two);

            $area_card_html = $this->stat_card_html(
                "{$target_area}からの訪問",
                number_format($area_prev),
                $area_change,
                "全体の" . number_format($area_rate, 1) . "%を占める"
            );
        }

        // ---- セクションHTML（Gemini生成分を抽出して配置） ----
        $sections = [
            ['id'=>'summary','class'=>'summary'],
            ['id'=>'good','class'=>'good-points'],
            ['id'=>'bad','class'=>'improvement-points'],
            ['id'=>'area','class'=>'area-box'],
            ['id'=>'insight','class'=>'insight-box'],
            ['id'=>'action','class'=>'actions'],
        ];

        $by_id = [];
        foreach ($sections as $s) {
            $by_id[$s['id']] = $this->extract_section_html($sections_html, $s);
        }

        // ---- HTML構築 ----
        $html  = '<style>' . $css . '</style>';
        $html .= '<div class="ai-report"><div class="container">';

        $html .= '<div class="header">';
        $html .= '<h1>📊 アクセス解析レポート</h1>';
        if ($period_text !== '') {
            $html .= '<p class="period">' . esc_html($period_text) . '</p>';
        }
        if ($site_line !== '') {
            $html .= '<p class="period">' . esc_html($site_line) . '</p>';
        }
        $html .= '</div>';

        // ---- 重要指標サマリー ----
        $month_label = (string)($prev_data['current_period']['display']['text'] ?? '前月');
        $html .= '<div class="section">';
        $html .= '<h2>📈 重要指標サマリー（' . esc_html($month_label) . ' 実績）</h2>';
        $html .= '<div class="stats-grid">';

        $html .= $this->stat_card_html('ページビュー（PV）', number_format($pv_prev), $this->pct_change($pv_prev, $pv_two), '前月：' . number_format($pv_prev) . ' / 前々月：' . number_format($pv_two));
        $html .= $this->stat_card_html('訪問回数（セッション）', number_format($sess_prev), $this->pct_change($sess_prev, $sess_two), '前月：' . number_format($sess_prev) . ' / 前々月：' . number_format($sess_two));
        $html .= $this->stat_card_html('ユーザー数', number_format($users_prev), $this->pct_change($users_prev, $users_two), '前月：' . number_format($users_prev) . ' / 前々月：' . number_format($users_two));
        $html .= $this->stat_card_html('新規ユーザー', number_format($new_prev), $this->pct_change($new_prev, $new_two), '前月：' . number_format($new_prev) . ' / 前々月：' . number_format($new_two));
        $html .= $this->stat_card_html('検索流入（organic）', number_format($org_prev), $this->pct_change($org_prev, $org_two), '前月：' . number_format($org_prev) . ' / 前々月：' . number_format($org_two));

        // お問い合わせ / 資料DL
        if ($contact) {
            $label = $selected_events['contact']['label'] ?? $contact;
            $html .= $this->stat_card_html(
                (string)$label,
                number_format($contact_prev),
                $this->pct_change($contact_prev, $contact_two),
                'CVR：' . number_format($cvr_prev, 2) . '%'
            );
        }
        if ($download) {
            $label = $selected_events['download']['label'] ?? $download;
            $html .= $this->stat_card_html(
                (string)$label,
                number_format($download_prev),
                $this->pct_change($download_prev, $download_two),
                '前月：' . number_format($download_prev) . ' / 前々月：' . number_format($download_two)
            );
        }

        // エリアカード（条件付き）
        $html .= $area_card_html;

        // スマホ比率
        $html .= $this->stat_card_html(
            'スマホ比率',
            number_format($mobile_prev, 1) . '%',
            $this->pct_change($mobile_prev, $mobile_two),
            'PC：' . number_format($pc_prev, 1) . '% / タブレット：' . number_format($tab_prev, 1) . '%'
        );

        // エンゲージメント率
        $html .= $this->stat_card_html(
            'エンゲージメント率',
            number_format($engR_prev * 100, 1) . '%',
            $this->pct_change($engR_prev * 100, $engR_two * 100),
            '前月：' . number_format($engR_prev * 100, 1) . '% / 前々月：' . number_format($engR_two * 100, 1) . '%'
        );

        // 平均滞在時間
        $html .= $this->stat_card_html(
            '平均滞在時間（秒）',
            (string)round($dur_prev),
            $this->pct_change($dur_prev, $dur_two),
            '前月：' . (string)round($dur_prev) . ' / 前々月：' . (string)round($dur_two)
        );

        // 1訪問あたりPV
        $html .= $this->stat_card_html(
            '1訪問あたりPV',
            number_format($pps_prev, 2),
            $this->pct_change($pps_prev, $pps_two),
            '前月：' . number_format($pps_prev, 2) . ' / 前々月：' . number_format($pps_two, 2)
        );

        $html .= '</div></div>'; // stats-grid / section

        // ---- 文章セクション（結論・良かった点・改善点） ----
        $is_easy = ($client_info['output_mode'] ?? 'normal') === 'easy';

        // 初心者モード: 「ひと目でわかる！今月のまとめ」テーブルをPHPで生成
        if ($is_easy) {
            $easy_rows = [
                ['ホームページに来た人の数', $sess_prev, $sess_two],
                ['見られたページの数',        $pv_prev,   $pv_two],
                ['検索での見つかりやすさ',     (int)str_replace(',', '', (string)($prev_data['gsc']['total']['impressions'] ?? '0')),
                                               (int)str_replace(',', '', (string)($two_data['gsc']['total']['impressions'] ?? '0'))],
            ];
            // ターゲットエリアがある場合は地元の行を追加
            if ($target_area !== null) {
                $area_prev_val = $this->sum_region_sessions($prev_data['geo_region'] ?? ($prev_data['geo'] ?? []), $target_area);
                $area_two_val  = $this->sum_region_sessions($two_data['geo_region']  ?? ($two_data['geo'] ?? []),  $target_area);
                $easy_rows[] = ['地元（' . esc_html($target_area) . '）の人', $area_prev_val, $area_two_val];
            }

            $html .= '<div class="section"><h2>ひと目でわかる！今月のまとめ</h2>';
            $html .= '<table class="easy-summary-table"><thead><tr><th>項目</th><th>状況</th><th>どういうこと？</th></tr></thead><tbody>';
            foreach ($easy_rows as $er) {
                $er_pct = $this->pct_change($er[1], $er[2]);
                if ($er_pct >= 15.0)       { $er_icon = '✨ 絶好調'; }
                elseif ($er_pct >= 5.0)    { $er_icon = '📈 アップ'; }
                elseif ($er_pct >= -4.0)   { $er_icon = '➡️ 変わらず'; }
                elseif ($er_pct >= -14.0)  { $er_icon = '📉 ダウン'; }
                else                        { $er_icon = '🚨 要注意'; }
                $er_desc = number_format($er[2]) . ' → ' . number_format($er[1]) . '（' . ($er_pct >= 0 ? '+' : '') . number_format($er_pct, 1) . '%）';
                $html .= '<tr><td>' . esc_html($er[0]) . '</td><td>' . $er_icon . '</td><td>' . esc_html($er_desc) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        if (!empty($by_id['summary'])) {
            $html .= $by_id['summary'];
        }

        $html .= '<div class="section"><h2>' . ($is_easy ? '⭕ 良かったこと' : '👍 今月の良かった点') . '</h2>';
        $html .= !empty($by_id['good']) ? $by_id['good'] : '<div class="good-points"><ul class="point-list"><li>（AI生成に失敗しました）</li></ul></div>';
        $html .= '</div>';

        $html .= '<div class="section"><h2>' . ($is_easy ? '❌ 課題' : '⚠️ 改善が必要な点') . '</h2>';
        $html .= !empty($by_id['bad']) ? $by_id['bad'] : '<div class="improvement-points"><ul class="point-list"><li>（AI生成に失敗しました）</li></ul></div>';
        $html .= '</div>';

        // ---- エリア状況（条件付き） ----
        if ($target_area !== null) {
            $area_heading = $is_easy
                ? '🏠 地元のお客さんの動き（' . esc_html($target_area) . '）'
                : '🏠 ' . esc_html($target_area) . 'エリアの状況';
            $html .= '<div class="section"><h2>' . $area_heading . '</h2>';
            $html .= !empty($by_id['area']) ? $by_id['area'] : '<div class="area-box"><div class="consideration"><p>（AI生成に失敗しました）</p></div></div>';
            $html .= '</div>';
        }

        // ---- 考察 ----
        $html .= '<div class="section"><h2>' . ($is_easy ? '🔍 今のサイトの状態をひと言で言うと' : '🤔 考察（事実と可能性）') . '</h2>';
        $html .= !empty($by_id['insight']) ? $by_id['insight'] : '<div class="insight-box"><div class="consideration"><p>（AI生成に失敗しました）</p></div></div>';
        $html .= '</div>';

        // ---- ネクストアクション ----
        if ($is_easy) {
            $html .= !empty($by_id['action'])
                ? $by_id['action']
                : '<div class="actions"><h2>💡 今後の作戦（ネクストステップ）</h2><div class="action-item"><div class="action-priority">私からの提案</div><div class="action-title">（AI生成に失敗しました）</div><div class="action-description">-</div></div></div>';
        } else {
            $html .= !empty($by_id['action'])
                ? $by_id['action']
                : '<div class="actions"><h2>🚀 今すぐやるべき3つのアクション</h2><div class="action-item"><div class="action-priority">Priority 1 - 最優先</div><div class="action-title">（AI生成に失敗しました）</div><div class="action-description">-</div></div></div>';
        }

        $html .= '</div></div>'; // container / ai-report

        return $html;
    }

    public function pct_change($current, $previous): float {
        $cur = (float)$current;
        $prev = (float)$previous;
        if ($prev == 0.0) {
            return $cur == 0.0 ? 0.0 : 100.0;
        }
        return (($cur - $prev) / $prev) * 100.0;
    }

    public function stat_card_html(string $label, string $value, float $pct_change, string $subtext): string {

        $cls = 'neutral';
        if ($pct_change > 0.05) $cls = 'positive';
        if ($pct_change < -0.05) $cls = 'negative';

        $sign = $pct_change > 0 ? '+' : '';
        $change_text = '前月比 ' . $sign . number_format($pct_change, 1) . '%';

        return '<div class="stat-card">'
            . '<div class="stat-label">' . esc_html($label) . '</div>'
            . '<div class="stat-value">' . esc_html($value) . '</div>'
            . '<div class="stat-change ' . esc_attr($cls) . '">' . esc_html($change_text) . '</div>'
            . '<div class="stat-subtext">' . esc_html($subtext) . '</div>'
            . '</div>';
    }

    public function sum_medium_sessions(array $medium_rows, array $targets): int {
        $sum = 0;
        foreach ($medium_rows as $r) {
            $name = (string)($r['medium'] ?? '');
            $sessions = (string)($r['sessions'] ?? '0');
            $val = (int)str_replace(',', '', $sessions);

            foreach ($targets as $t) {
                if (strcasecmp($name, $t) === 0) {
                    $sum += $val;
                }
            }
        }
        return $sum;
    }

    public function device_ratio(array $device_rows, int $total_sessions): array {
        $mobile = 0; $desktop = 0; $tablet = 0;
        foreach ($device_rows as $r) {
            $dev = (string)($r['device'] ?? '');
            $cnt = (int)str_replace(',', '', (string)($r['count'] ?? '0'));
            if ($dev === 'mobile') $mobile += $cnt;
            if ($dev === 'desktop') $desktop += $cnt;
            if ($dev === 'tablet') $tablet += $cnt;
        }
        if ($total_sessions <= 0) return [0.0, 0.0, 0.0];

        return [
            ($mobile / $total_sessions) * 100,
            ($desktop / $total_sessions) * 100,
            ($tablet / $total_sessions) * 100,
        ];
    }

    public function sum_region_sessions(array $region_rows, string $target_area): int {
        // $region_rows は GA4 の region または city の内訳配列を想定
        // - 日本語（愛媛県）/英語（Ehime）など表記揺れを吸収して合算する
        $target_norm = $this->normalize_pref_name($target_area);

        $sum = 0;
        foreach ($region_rows as $r) {
            $name = (string)($r['name'] ?? ($r['region'] ?? ($r['city'] ?? '')));
            $sessions = (string)($r['sessions'] ?? '0');
            $val = (int)str_replace(',', '', $sessions);

            $name_norm = $this->normalize_pref_name($name);
            if ($name_norm !== '' && $name_norm === $target_norm) {
                $sum += $val;
            }
        }
        return $sum;
    }

    public function normalize_pref_name(string $name): string {
        $n = trim($name);
        if ($n === '') return '';

        // 日本語の末尾（県/府/都/道）を落として基底名に
        $jp = preg_replace('/(都|道|府|県)$/u', '', $n);

        // 英語圏の表記は簡易的に都道府県の日本語基底名へ寄せる
        $ascii = strtolower($n);
        $ascii = preg_replace('/\s+/', '', $ascii);
        $ascii = str_replace(['-', '_'], '', $ascii);
        $ascii = preg_replace('/prefecture$/', '', $ascii);
        $ascii = preg_replace('/metropolis$/', '', $ascii);

        // 47都道府県（英語→日本語基底名）簡易マップ
        $map = [
            'hokkaido' => '北海道',
            'aomori' => '青森', 'iwate' => '岩手', 'miyagi' => '宮城', 'akita' => '秋田', 'yamagata' => '山形', 'fukushima' => '福島',
            'ibaraki' => '茨城', 'tochigi' => '栃木', 'gunma' => '群馬', 'saitama' => '埼玉', 'chiba' => '千葉', 'tokyo' => '東京', 'kanagawa' => '神奈川',
            'niigata' => '新潟', 'toyama' => '富山', 'ishikawa' => '石川', 'fukui' => '福井', 'yamanashi' => '山梨', 'nagano' => '長野',
            'gifu' => '岐阜', 'shizuoka' => '静岡', 'aichi' => '愛知', 'mie' => '三重',
            'shiga' => '滋賀', 'kyoto' => '京都', 'osaka' => '大阪', 'hyogo' => '兵庫', 'nara' => '奈良', 'wakayama' => '和歌山',
            'tottori' => '鳥取', 'shimane' => '島根', 'okayama' => '岡山', 'hiroshima' => '広島', 'yamaguchi' => '山口',
            'tokushima' => '徳島', 'kagawa' => '香川', 'ehime' => '愛媛', 'kochi' => '高知',
            'fukuoka' => '福岡', 'saga' => '佐賀', 'nagasaki' => '長崎', 'kumamoto' => '熊本', 'oita' => '大分', 'miyazaki' => '宮崎', 'kagoshima' => '鹿児島', 'okinawa' => '沖縄',
        ];

        if (preg_match('/^[a-z0-9]+$/', $ascii) && isset($map[$ascii])) {
            return $map[$ascii];
        }

        // 日本語基底名に寄せた上で返す
        return $jp;
    }

    // =========================================================
    // 初心者向け全文リライト（Markdown出力）
    // =========================================================

    /**
     * 生成済みセクションHTMLからプレーンテキスト（素材テキスト）を組み立てる
     *
     * 各セクションのHTMLをstrip_tagsし、
     * セクション名をラベルとして付与したテキストを返す。
     * ※ summary セクションはリライト対象外（独立表示のため除外）
     *
     * @param  string      $sections_html 全セクションを結合したHTML文字列
     * @param  ?string     $target_area   ターゲットエリア（nullなら全国）
     * @return string      素材テキスト
     */
    public function build_raw_report_text( string $sections_html, ?string $target_area = null ): string {

        $sections = self::REPORT_SECTIONS;
        // summary はリライト対象外（独立ブロックとして表示する）
        $sections = array_values( array_filter( $sections, fn( $s ) => ( $s['id'] ?? '' ) !== 'summary' ) );
        if ( $target_area === null ) {
            $sections = array_values( array_filter( $sections, fn( $s ) => ( $s['id'] ?? '' ) !== 'area' ) );
        }

        $lines = [];
        foreach ( $sections as $sec ) {
            $extracted = $this->extract_section_html( $sections_html, $sec );
            if ( $extracted === '' ) {
                continue;
            }
            // HTMLタグを除去し、連続空白・改行を整理
            $text = strip_tags( $extracted );
            $text = preg_replace( '/[ \t]+/', ' ', $text );
            $text = preg_replace( '/\n{3,}/', "\n\n", $text );
            $text = trim( $text );
            if ( $text === '' ) {
                continue;
            }
            $lines[] = "【{$sec['label']}】\n{$text}";
        }

        return implode( "\n\n", $lines );
    }

    /**
     * 初心者モード用：素材テキスト → AIリライト → Markdown を返す
     *
     * 同一 user / year_month の組み合わせで transient キャッシュを利用し、
     * 同じ素材テキストに対する再生成を防止する。
     *
     * @param  string  $sections_html  Multi-pass 生成済みのセクションHTML
     * @param  ?string $target_area    ターゲットエリア
     * @param  int     $user_id        ユーザーID
     * @param  string  $year_month     対象年月（YYYY-MM）
     * @return string  リライト済みMarkdown
     */
    public function rewrite_report_for_beginner(
        string  $sections_html,
        ?string $target_area,
        int     $user_id,
        string  $year_month
    ): string {

        // --- 素材テキスト構築 ---
        $raw_text = $this->build_raw_report_text( $sections_html, $target_area );
        if ( $raw_text === '' ) {
            error_log( '[GCREV] rewrite_report_for_beginner: raw_text is empty, skip rewrite' );
            return '';
        }

        // --- キャッシュチェック（素材テキストのハッシュで一意化） ---
        $hash      = md5( $raw_text );
        $cache_key = "gcrev_beginner_rw_{$user_id}_{$year_month}_{$hash}";
        $cached    = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            error_log( "[GCREV] rewrite_report_for_beginner: cache HIT ({$cache_key})" );
            return $cached;
        }

        // --- AI リライト ---
        error_log( '[GCREV] rewrite_report_for_beginner: calling AI rewrite, raw_text length=' . mb_strlen( $raw_text ) );

        try {
            $markdown = $this->ai->rewrite_for_beginner( $raw_text );
        } catch ( \Exception $e ) {
            error_log( '[GCREV] rewrite_report_for_beginner: AI error: ' . $e->getMessage() );
            return '';
        }

        // Geminiが ```markdown ... ``` で囲むケースを剥がす
        $markdown = trim( $markdown );
        if ( preg_match( '/^```(?:markdown|md)?\s*(.+?)\s*```$/is', $markdown, $m ) ) {
            $markdown = trim( $m[1] );
        }

        // --- キャッシュ保存（24時間） ---
        set_transient( $cache_key, $markdown, DAY_IN_SECONDS );
        error_log( "[GCREV] rewrite_report_for_beginner: cached ({$cache_key}), md length=" . mb_strlen( $markdown ) );

        return $markdown;
    }

    public function extract_section_html(string $html, array $section): string {
            $class_name = $section['class'] ?? '';
            if ($class_name === '') return '';

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

            // outerHTML を返す（divごと）
            return trim($doc->saveHTML($node));
    }

    // =========================================================
    // Gemini 応答の正規化
    // =========================================================
    public function normalize_report_html(string $text): string {

        $t = trim($text);

        // ```html ... ``` / ``` ... ``` を剥がす
        if (preg_match('/^```(?:html)?\s*(.+?)\s*```$/is', $t, $m)) {
            $t = trim($m[1]);
        }

        // <html>や<body>を含む丸ごとHTMLが来た場合は、body内を優先
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $t, $m)) {
            $t = trim($m[1]);
        }

        // Geminiが「ここからHTMLです」等の前置きを付けることがある
        if (!str_starts_with($t, '<')) {
            $pos = strpos($t, '<');
            if ($pos !== false) {
                $t = trim(substr($t, $pos));
            }
        }

        return trim($t);
    }
}
