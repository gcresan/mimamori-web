<?php
/*Template Name: 口コミ投稿支援フォーム */

// =====================================================
// アンケートデータ取得（トークン方式 or レガシー方式）
// =====================================================
global $wpdb;

$survey_token       = isset($_GET['t']) ? sanitize_text_field($_GET['t']) : '';
$target_user_id     = isset($_GET['u']) ? absint($_GET['u']) : 0;
$client_name        = '';
$survey_title       = '';
$survey_description = '';
$google_review_url  = '';
$review_questions   = [];
$survey_error       = '';

if (!empty($survey_token)) {
    // ----- トークン方式: DBからアンケート取得 -----
    $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
    $t_questions = $wpdb->prefix . 'gcrev_survey_questions';

    $survey = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t_surveys} WHERE token = %s",
        $survey_token
    ));

    if (!$survey) {
        $survey_error = 'このアンケートは存在しません。URLをご確認ください。';
    } elseif ($survey->status !== 'published') {
        $survey_error = 'このアンケートは現在公開されていません。';
    } else {
        $target_user_id    = (int) $survey->user_id;
        $survey_title      = $survey->title;
        $survey_description = $survey->description ?? '';
        $google_review_url = $survey->google_review_url;

        // クライアント名を取得
        $client_name = gcrev_get_business_name($target_user_id);

        // 質問取得
        $db_questions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t_questions} WHERE survey_id = %d AND is_active = 1 ORDER BY sort_order ASC, id ASC",
            (int) $survey->id
        ));

        foreach ($db_questions as $q) {
            $options = [];
            if (!empty($q->options)) {
                $decoded = json_decode($q->options, true);
                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }
            $review_questions[] = [
                'id'          => (int) $q->id,
                'type'        => $q->type,
                'label'       => $q->label,
                'required'    => (bool) $q->required,
                'description' => $q->description,
                'placeholder' => $q->placeholder,
                'options'     => $options,
            ];
        }

        if (empty($review_questions)) {
            $survey_error = 'このアンケートには質問が設定されていません。';
        }
    }
} elseif ($target_user_id > 0) {
    // ----- レガシー方式: user_id からビジネス情報取得（ハードコード質問） -----
    $target_user = get_userdata($target_user_id);
    if ($target_user) {
        $client_name = gcrev_get_business_name($target_user_id);
        $survey_title = $client_name . ' ご感想アンケート';
        $survey_description = '';
        $google_review_url = get_user_meta($target_user_id, '_gcrev_google_review_url', true);
    }

    // レガシー用ハードコード質問
    $review_questions = [
        ['id' => 'concerns',   'type' => 'checkbox', 'label' => '利用前に困っていたこと', 'required' => true, 'description' => '当てはまるものをすべて選択してください', 'placeholder' => '', 'options' => ['集客が伸び悩んでいた','どのエリアに配ればよいかわからなかった','反応があるか不安だった','費用対効果が見えづらかった','その他']],
        ['id' => 'reasons',    'type' => 'checkbox', 'label' => 'このサービスを選んだ理由', 'required' => true, 'description' => '当てはまるものをすべて選択してください', 'placeholder' => '', 'options' => ['説明がわかりやすかった','相談しやすかった','費用感に納得できた','地元で実績があった','対応が丁寧だった','その他']],
        ['id' => 'good_points','type' => 'checkbox', 'label' => '実際に利用して良かった点', 'required' => true, 'description' => '当てはまるものをすべて選択してください', 'placeholder' => '', 'options' => ['対応が早かった','配布内容について相談しやすかった','安心して任せられた','続けやすかった','反応につながった','その他']],
        ['id' => 'satisfaction','type' => 'radio',    'label' => '総合的な満足度', 'required' => true, 'description' => '1つ選択してください', 'placeholder' => '', 'options' => ['とても満足','満足','ふつう','やや不満','不満']],
        ['id' => 'impression', 'type' => 'textarea',  'label' => '特に印象に残っている対応や良かった点', 'required' => true, 'description' => '一言でも大丈夫です。箇条書きでも構いません。', 'placeholder' => '例：担当の方がとても親切で、初めてでも安心できました。', 'options' => []],
        ['id' => 'message',    'type' => 'textarea',  'label' => 'これから利用を検討している方にひとこと', 'required' => false, 'description' => '任意です。思いつく範囲でご記入ください。', 'placeholder' => '例：迷っている方はまず相談してみるとよいと思います。', 'options' => []],
    ];
} else {
    $survey_error = 'アンケートが指定されていません。URLをご確認ください。';
}

// フォールバック: Google口コミURLが未設定
if (empty($google_review_url)) {
    $google_review_url = '#';
}

// REST API URL
$api_url = rest_url('gcrev/v1/review/generate');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($survey_title ?: 'アンケート'); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url(get_template_directory_uri()); ?>/images/favicon.ico">
    <style>
    /* ===== Reset & Base ===== */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', Meiryo, sans-serif;
        background: #f5f6fa;
        color: #333;
        line-height: 1.7;
        -webkit-text-size-adjust: 100%;
    }

    /* ===== Layout ===== */
    .review-container {
        max-width: 640px;
        margin: 0 auto;
        padding: 24px 16px 60px;
    }

    /* ===== Header ===== */
    .review-header {
        background: #2C3E50;
        color: #fff;
        text-align: center;
        padding: 28px 20px;
        margin: -24px -16px 24px;
    }
    .review-header-client {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: 0.5px;
        line-height: 1.5;
    }

    /* ===== Survey Intro ===== */
    .survey-intro {
        text-align: center;
        padding: 0 0 8px;
        margin-bottom: 20px;
    }
    .survey-intro-title {
        font-size: 17px;
        font-weight: 700;
        color: #2C3E40;
        margin-bottom: 8px;
        line-height: 1.6;
    }
    .survey-intro-desc {
        font-size: 14px;
        color: #666;
        line-height: 1.7;
    }

    /* ===== スマホ: intro左寄せ・改行無効 ===== */
    @media (max-width: 640px) {
        .survey-intro {
            text-align: left;
        }
        .survey-intro-desc br {
            display: none;
        }
    }

    /* ===== Card ===== */
    .review-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        padding: 28px 24px;
        margin-bottom: 20px;
    }

    /* ===== Question ===== */
    .question-block {
        margin-bottom: 32px;
    }
    .question-block:last-child {
        margin-bottom: 0;
    }
    .question-label {
        font-size: 15px;
        font-weight: 700;
        color: #2C3E40;
        margin-bottom: 4px;
        line-height: 1.8;
        display: flex;
        align-items: baseline;
    }
    .question-number {
        flex-shrink: 0;
        width: 1.6em;
        color: #2C3E40;
    }
    .question-label-text {
        flex: 1;
        min-width: 0;
    }
    .question-badge {
        font-size: 11px;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 4px;
        margin-left: 6px;
        display: inline;
        vertical-align: middle;
        white-space: nowrap;
    }
    .badge-required {
        background: #fef2f2;
        color: #dc2626;
    }
    .badge-optional {
        background: #f0fdf4;
        color: #16a34a;
    }
    .question-description {
        font-size: 13px;
        color: #888;
        margin-bottom: 12px;
    }

    /* ===== Checkbox / Radio ===== */
    .option-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .option-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 14px;
        background: #f9fafb;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
        -webkit-tap-highlight-color: transparent;
    }
    .option-item:hover {
        border-color: #93c5fd;
        background: #f0f7ff;
    }
    .option-item.selected {
        border-color: #3b82f6;
        background: #eff6ff;
    }
    .option-item input[type="checkbox"],
    .option-item input[type="radio"] {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
        accent-color: #3b82f6;
        cursor: pointer;
    }
    .option-item label {
        font-size: 14px;
        color: #444;
        cursor: pointer;
        flex: 1;
        user-select: none;
    }

    /* ===== Textarea ===== */
    .review-textarea {
        width: 100%;
        min-height: 100px;
        padding: 12px 14px;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        line-height: 1.7;
        resize: vertical;
        transition: border-color 0.15s;
        background: #f9fafb;
    }
    .review-textarea:focus {
        outline: none;
        border-color: #3b82f6;
        background: #fff;
    }
    .review-textarea::placeholder {
        color: #aaa;
    }

    /* ===== Error ===== */
    .question-error {
        font-size: 13px;
        color: #dc2626;
        margin-top: 6px;
        display: none;
    }
    .question-error.visible {
        display: block;
    }

    /* ===== Submit Button ===== */
    .submit-area {
        text-align: center;
        margin-top: 8px;
    }
    .btn-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        max-width: 360px;
        padding: 14px 24px;
        background: #2C3E50;
        color: #fff;
        font-size: 16px;
        font-weight: 700;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .btn-submit:hover {
        background: #1a2a3a;
    }
    .btn-submit:disabled {
        background: #7f8c9b;
        cursor: not-allowed;
    }

    /* ===== Loading ===== */
    .loading-section {
        display: none;
        text-align: center;
        padding: 60px 20px;
    }
    .loading-spinner {
        width: 48px;
        height: 48px;
        border: 4px solid #e5e7eb;
        border-top-color: #3b82f6;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin: 0 auto 20px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading-text {
        font-size: 15px;
        color: #555;
        line-height: 1.8;
    }

    /* ===== Result ===== */
    .result-section {
        display: none;
    }
    .result-notice {
        font-size: 13px;
        color: #666;
        background: #fefce8;
        border: 1px solid #fde68a;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 20px;
        line-height: 1.7;
    }
    .result-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        padding: 20px;
        margin-bottom: 16px;
    }
    .result-card-header {
        font-size: 14px;
        font-weight: 700;
        color: #2C3E40;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .result-card-header .badge {
        font-size: 11px;
        padding: 2px 10px;
        border-radius: 20px;
        font-weight: 600;
    }
    .badge-short { background: #dbeafe; color: #1d4ed8; }
    .badge-normal { background: #d1fae5; color: #065f46; }
    .result-text {
        font-size: 14px;
        color: #444;
        line-height: 1.8;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 14px;
        margin-bottom: 12px;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .btn-copy {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: #fff;
        color: #3b82f6;
        font-size: 13px;
        font-weight: 600;
        border: 1.5px solid #3b82f6;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .btn-copy:hover { background: #eff6ff; }
    .btn-copy.copied {
        background: #d1fae5;
        color: #059669;
        border-color: #059669;
    }

    .result-actions {
        text-align: center;
        margin-top: 24px;
    }
    .btn-google-review {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        max-width: 360px;
        padding: 14px 24px;
        background: #ea4335;
        color: #fff;
        font-size: 16px;
        font-weight: 700;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.15s;
    }
    .btn-google-review:hover { background: #d32f2f; }
    .btn-retry {
        display: inline-block;
        margin-top: 12px;
        padding: 10px 20px;
        background: #fff;
        color: #555;
        font-size: 14px;
        font-weight: 600;
        border: 1.5px solid #d1d5db;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
    }
    .btn-retry:hover { background: #f9fafb; }

    /* ===== Error Screen ===== */
    .error-section {
        display: none;
        text-align: center;
        padding: 40px 20px;
    }
    .error-icon { font-size: 48px; margin-bottom: 16px; }
    .error-message {
        font-size: 15px;
        color: #555;
        line-height: 1.8;
        margin-bottom: 24px;
    }

    /* ===== Consent (AI確認) ===== */
    .consent-section { display: none; }
    .consent-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        padding: 32px 24px;
        text-align: center;
    }
    .consent-title {
        font-size: 18px;
        font-weight: 700;
        color: #2C3E40;
        margin-bottom: 8px;
    }
    .consent-desc {
        font-size: 14px;
        color: #666;
        line-height: 1.7;
        margin-bottom: 24px;
    }
    .consent-buttons {
        display: flex;
        flex-direction: column;
        gap: 12px;
        align-items: center;
    }
    .btn-ai-support {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        max-width: 360px;
        padding: 14px 24px;
        background: #3b82f6;
        color: #fff;
        font-size: 16px;
        font-weight: 700;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .btn-ai-support:hover { background: #2563eb; }
    .btn-manual-input {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        max-width: 360px;
        padding: 14px 24px;
        background: #fff;
        color: #555;
        font-size: 15px;
        font-weight: 600;
        border: 1.5px solid #d1d5db;
        border-radius: 10px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .btn-manual-input:hover { background: #f9fafb; }

    /* ===== Manual Input (自分で入力) ===== */
    .manual-section { display: none; }
    .manual-textarea {
        width: 100%;
        min-height: 160px;
        padding: 14px;
        border: 1.5px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        font-family: inherit;
        line-height: 1.8;
        resize: vertical;
        background: #f9fafb;
    }
    .manual-textarea:focus {
        outline: none;
        border-color: #3b82f6;
        background: #fff;
    }
    .manual-hint {
        font-size: 13px;
        color: #888;
        margin-bottom: 12px;
        line-height: 1.7;
    }

    /* ===== Footer ===== */
    .review-footer {
        text-align: center;
        padding: 24px 0;
        font-size: 12px;
        color: #aaa;
    }
    </style>
</head>
<body>
    <div class="review-container">

        <!-- ヘッダー（クライアント名帯） -->
        <div class="review-header">
            <div class="review-header-client"><?php echo esc_html($client_name ?: 'アンケート'); ?></div>
        </div>

        <!-- アンケートタイトル＋説明文 -->
        <div class="survey-intro">
            <h1 class="survey-intro-title"><?php echo esc_html($survey_title); ?></h1>
            <?php if (!empty($survey_description)) : ?>
                <p class="survey-intro-desc"><?php echo nl2br(esc_html($survey_description)); ?></p>
            <?php endif; ?>
        </div>

<?php if (!empty($survey_error)) : ?>
        <!-- ===== エラー表示（無効なアンケート） ===== -->
        <div class="review-card" style="text-align:center; padding: 48px 24px;">
            <div style="font-size:48px; margin-bottom:16px;">...</div>
            <p style="font-size:15px; color:#555; line-height:1.8;"><?php echo esc_html($survey_error); ?></p>
        </div>

        <!-- フッター -->
        <div class="review-footer">
            &copy; <?php echo esc_html(gmdate('Y')); ?> | <?php echo esc_html($client_name ?: 'アンケート'); ?>
        </div>
    </div>
</body>
</html>
<?php return; endif; ?>

        <!-- ===== 入力画面 ===== -->
        <div id="review-form-section">
            <form id="review-form" novalidate>
                <div class="review-card">
                    <div id="questions-container"></div>
                    <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e5e7eb;">
                        <label style="display:block; font-size:14px; font-weight:600; color:#374151; margin-bottom:6px;">お名前・ニックネーム（任意）</label>
                        <input type="text" id="respondent-name" placeholder="例：田中" style="width:100%; padding:10px 12px; font-size:14px; border:1.5px solid #e5e7eb; border-radius:8px; background:#f9fafb; font-family:inherit;">
                    </div>
                </div>
                <div class="submit-area">
                    <button type="submit" class="btn-submit" id="btn-submit">
                        回答を送信する
                    </button>
                </div>
            </form>
        </div>

        <!-- ===== AI利用確認画面 ===== -->
        <div id="review-consent-section" class="consent-section">
            <div class="consent-card">
                <div class="consent-title">ご利用のご感想を、Googleの口コミでもお聞かせいただけると嬉しいです</div>
                <div class="consent-desc">
                    回答内容をもとにAIが口コミの下書きを作成できます。<br>
                    ご自身で自由に書くこともできます。
                </div>
                <div style="margin:16px 0; padding:12px 16px; background:#f9fafb; border-radius:8px;">
                    <label style="display:flex; align-items:flex-start; gap:8px; font-size:13px; color:#374151; cursor:pointer;">
                        <input type="checkbox" id="consent-review" style="margin-top:2px; flex-shrink:0;">
                        <span>生成された口コミ文を店舗サイト等で紹介しても構いません</span>
                    </label>
                </div>
                <div class="consent-buttons">
                    <button type="button" class="btn-ai-support" id="btn-use-ai">
                        ✨ AIサポートで下書きを作成する
                    </button>
                    <button type="button" class="btn-manual-input" id="btn-use-manual">
                        ✏️ 自分で入力する
                    </button>
                </div>
            </div>
        </div>

        <!-- ===== 自分で入力画面 ===== -->
        <div id="review-manual-section" class="manual-section">
            <div class="review-card">
                <div class="consent-title" style="margin-bottom:12px;">口コミを入力してください</div>
                <div class="manual-hint">
                    ご感想を自由にご記入ください。書いた内容をそのままGoogle口コミに貼り付けできます。
                </div>
                <textarea class="manual-textarea" id="manual-review-text" placeholder="例：とても丁寧に対応していただきました。初めてでも安心して利用できました。"></textarea>
            </div>
            <div class="result-actions" style="margin-top:20px;">
                <button type="button" class="btn-copy" id="btn-copy-manual" data-target="manual-review-text" style="margin-bottom:16px;">
                    この文章をコピー
                </button>
                <br>
                <a href="<?php echo esc_url($google_review_url); ?>" target="_blank" rel="noopener noreferrer" class="btn-google-review">
                    Google口コミを書く
                </a>
                <br>
                <button type="button" class="btn-retry" id="btn-manual-back">
                    もう一度やり直す
                </button>
            </div>
        </div>

        <!-- ===== ローディング画面 ===== -->
        <div id="review-loading-section" class="loading-section">
            <div class="review-card" style="padding: 48px 24px;">
                <div class="loading-spinner"></div>
                <div class="loading-text">
                    AIが口コミの下書きを作成しています...<br>
                    <span style="font-size: 13px; color: #888;">入力内容をもとに下書きを整えています</span>
                </div>
            </div>
        </div>

        <!-- ===== 結果表示画面 ===== -->
        <div id="review-result-section" class="result-section">
            <div class="result-notice">
                以下はAIが作成した<strong>下書き</strong>です。あなたの言葉で自由に修正してからご利用ください。<br>
                <span style="font-size:12px;color:#999;">※ この文章はアンケートの回答内容をもとにAIが作成したものです。実際の体験と異なる表現が含まれる場合は修正してください。</span>
            </div>

            <div class="result-card" id="result-short">
                <div class="result-card-header">
                    <span class="badge badge-short">短め</span> 口コミ案A
                </div>
                <div class="result-text" id="result-short-text"></div>
                <button type="button" class="btn-copy" data-target="result-short-text">
                    この文章をコピー
                </button>
            </div>

            <div class="result-card" id="result-normal">
                <div class="result-card-header">
                    <span class="badge badge-normal">標準</span> 口コミ案B
                </div>
                <div class="result-text" id="result-normal-text"></div>
                <button type="button" class="btn-copy" data-target="result-normal-text">
                    この文章をコピー
                </button>
            </div>

            <div class="result-actions">
                <a href="<?php echo esc_url($google_review_url); ?>" target="_blank" rel="noopener noreferrer" class="btn-google-review" id="btn-google-review">
                    Google口コミを書く
                </a>
                <br>
                <button type="button" class="btn-retry" id="btn-back-to-form">
                    もう一度やり直す
                </button>
            </div>
        </div>

        <!-- ===== エラー画面 ===== -->
        <div id="review-error-section" class="error-section">
            <div class="review-card" style="padding: 40px 24px;">
                <div class="error-icon">...</div>
                <div class="error-message" id="error-message">
                    口コミ案の作成に失敗しました。<br>少し時間をおいてもう一度お試しください。
                </div>
                <button type="button" class="btn-retry" id="btn-error-retry">
                    もう一度試す
                </button>
            </div>
        </div>

        <!-- フッター -->
        <div class="review-footer">
            &copy; <?php echo esc_html(gmdate('Y')); ?> | <?php echo esc_html($client_name ?: 'アンケート'); ?>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        // =====================================================
        // 設定（PHPから注入）
        // =====================================================
        var REVIEW_CONFIG = {
            apiUrl:         <?php echo wp_json_encode($api_url); ?>,
            surveyToken:    <?php echo wp_json_encode($survey_token); ?>,
            userId:         <?php echo (int) $target_user_id; ?>,
            googleReviewUrl: <?php echo wp_json_encode($google_review_url); ?>,
            questions:      <?php echo wp_json_encode($review_questions, JSON_UNESCAPED_UNICODE); ?>
        };

        // =====================================================
        // DOM参照
        // =====================================================
        var formSection    = document.getElementById('review-form-section');
        var consentSection = document.getElementById('review-consent-section');
        var manualSection  = document.getElementById('review-manual-section');
        var loadingSection = document.getElementById('review-loading-section');
        var resultSection  = document.getElementById('review-result-section');
        var errorSection   = document.getElementById('review-error-section');
        var form           = document.getElementById('review-form');
        var container      = document.getElementById('questions-container');

        // =====================================================
        // フォーム描画
        // =====================================================
        function renderQuestions() {
            var html = '';
            REVIEW_CONFIG.questions.forEach(function(q, idx) {
                html += '<div class="question-block" data-qid="' + q.id + '">';
                html += '<div class="question-label">';
                html += '<span class="question-number">' + (idx + 1) + '.</span>';
                html += '<span class="question-label-text">';
                html += escapeHtml(q.label);
                if (q.required) {
                    html += '<span class="question-badge badge-required">必須</span>';
                } else {
                    html += '<span class="question-badge badge-optional">任意</span>';
                }
                html += '</span>';
                html += '</div>';

                if (q.description) {
                    html += '<div class="question-description">' + escapeHtml(q.description) + '</div>';
                }

                if (q.type === 'checkbox' || q.type === 'radio') {
                    html += renderOptions(q);
                } else if (q.type === 'textarea') {
                    html += '<textarea class="review-textarea" name="' + q.id + '" placeholder="' + escapeAttr(q.placeholder || '') + '"></textarea>';
                } else if (q.type === 'text') {
                    html += '<input type="text" class="review-textarea" style="min-height:auto;height:44px;" name="' + q.id + '" placeholder="' + escapeAttr(q.placeholder || '') + '">';
                }

                html += '<div class="question-error" id="error-' + q.id + '"></div>';
                html += '</div>';
            });
            container.innerHTML = html;
            bindOptionClicks();
        }

        function renderOptions(q) {
            var html = '<div class="option-list">';
            q.options.forEach(function(opt, i) {
                var inputId = q.id + '_' + i;
                html += '<div class="option-item" data-input="' + inputId + '">';
                html += '<input type="' + q.type + '" id="' + inputId + '" name="' + q.id + '" value="' + escapeAttr(opt) + '">';
                html += '<label for="' + inputId + '">' + escapeHtml(opt) + '</label>';
                html += '</div>';
            });
            html += '</div>';
            return html;
        }

        function bindOptionClicks() {
            var items = document.querySelectorAll('.option-item');
            items.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    if (e.target.tagName === 'INPUT') return; // input直接クリック時はブラウザに任せる
                    var input = item.querySelector('input');
                    if (!input) return;

                    if (input.type === 'checkbox') {
                        input.checked = !input.checked;
                    } else if (input.type === 'radio') {
                        input.checked = true;
                    }
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });

                // selected クラスの切替
                var input = item.querySelector('input');
                if (input) {
                    input.addEventListener('change', function() {
                        if (input.type === 'radio') {
                            // 同グループの全itemからselectedを外す
                            var group = document.querySelectorAll('input[name="' + input.name + '"]');
                            group.forEach(function(r) {
                                var parent = r.closest('.option-item');
                                if (parent) parent.classList.remove('selected');
                            });
                        }
                        item.classList.toggle('selected', input.checked);
                    });
                }
            });
        }

        // =====================================================
        // バリデーション
        // =====================================================
        function validate() {
            var valid = true;
            // 前回のエラーをクリア
            document.querySelectorAll('.question-error').forEach(function(el) {
                el.textContent = '';
                el.classList.remove('visible');
            });

            REVIEW_CONFIG.questions.forEach(function(q) {
                if (!q.required) return;

                var errorEl = document.getElementById('error-' + q.id);
                var hasError = false;

                if (q.type === 'checkbox') {
                    var checked = document.querySelectorAll('input[name="' + q.id + '"]:checked');
                    if (checked.length === 0) {
                        hasError = true;
                        errorEl.textContent = 'この項目を選択してください';
                    }
                } else if (q.type === 'radio') {
                    var selected = document.querySelector('input[name="' + q.id + '"]:checked');
                    if (!selected) {
                        hasError = true;
                        errorEl.textContent = 'この項目を選択してください';
                    }
                } else if (q.type === 'textarea' || q.type === 'text') {
                    var input = document.querySelector('[name="' + q.id + '"]');
                    if (!input || input.value.trim() === '') {
                        hasError = true;
                        errorEl.textContent = 'ご記入をお願いします';
                    }
                }

                if (hasError) {
                    errorEl.classList.add('visible');
                    if (valid) {
                        // 最初のエラー箇所にスクロール
                        var block = document.querySelector('[data-qid="' + q.id + '"]');
                        if (block) block.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    valid = false;
                }
            });

            return valid;
        }

        // =====================================================
        // 回答データ収集
        // =====================================================
        function collectAnswers() {
            var answers = {};
            REVIEW_CONFIG.questions.forEach(function(q) {
                if (q.type === 'checkbox') {
                    var checked = document.querySelectorAll('input[name="' + q.id + '"]:checked');
                    answers[q.id] = Array.from(checked).map(function(el) { return el.value; });
                } else if (q.type === 'radio') {
                    var selected = document.querySelector('input[name="' + q.id + '"]:checked');
                    answers[q.id] = selected ? selected.value : '';
                } else {
                    var input = document.querySelector('[name="' + q.id + '"]');
                    answers[q.id] = input ? input.value.trim() : '';
                }
            });
            return answers;
        }

        // =====================================================
        // 画面切替
        // =====================================================
        function showSection(section) {
            formSection.style.display    = 'none';
            consentSection.style.display = 'none';
            manualSection.style.display  = 'none';
            loadingSection.style.display = 'none';
            resultSection.style.display  = 'none';
            errorSection.style.display   = 'none';
            section.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // =====================================================
        // API呼び出し
        // =====================================================
        function submitReview(answers) {
            showSection(loadingSection);

            // 質問ラベルと回答を紐づけた構造を作る
            var labeled = [];
            REVIEW_CONFIG.questions.forEach(function(q) {
                var answer = answers[q.id];
                if (Array.isArray(answer) && answer.length === 0) return;
                if (typeof answer === 'string' && answer === '') return;

                labeled.push({
                    question_id: q.id,
                    question: q.label,
                    answer: answer
                });
            });

            var payload = { answers: labeled };
            if (REVIEW_CONFIG.surveyToken) {
                payload.survey_token = REVIEW_CONFIG.surveyToken;
            } else {
                payload.user_id = REVIEW_CONFIG.userId;
            }
            var nameEl = document.getElementById('respondent-name');
            if (nameEl) payload.respondent_name = nameEl.value.trim();
            payload.consent_ai = true;
            var consentReviewEl = document.getElementById('consent-review');
            if (consentReviewEl) payload.consent_review = consentReviewEl.checked;

            fetch(REVIEW_CONFIG.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.short_review && data.normal_review) {
                    document.getElementById('result-short-text').textContent = data.short_review;
                    document.getElementById('result-normal-text').textContent = data.normal_review;
                    showSection(resultSection);
                } else {
                    var msg = data.message || '口コミ案の作成に失敗しました。\n少し時間をおいてもう一度お試しください。';
                    document.getElementById('error-message').innerHTML = escapeHtml(msg).replace(/\n/g, '<br>');
                    showSection(errorSection);
                }
            })
            .catch(function() {
                document.getElementById('error-message').innerHTML = '口コミ案の作成に失敗しました。<br>少し時間をおいてもう一度お試しください。';
                showSection(errorSection);
            });
        }

        // =====================================================
        // コピー機能
        // =====================================================
        function initCopyButtons() {
            document.querySelectorAll('.btn-copy').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var targetId = btn.getAttribute('data-target');
                    var textEl = document.getElementById(targetId);
                    if (!textEl) return;
                    var copyText = textEl.tagName === 'TEXTAREA' ? textEl.value : textEl.textContent;

                    navigator.clipboard.writeText(copyText).then(function() {
                        var originalText = btn.innerHTML;
                        btn.classList.add('copied');
                        btn.textContent = 'コピーしました';
                        setTimeout(function() {
                            btn.classList.remove('copied');
                            btn.innerHTML = originalText;
                        }, 2000);
                    });
                });
            });
        }

        // =====================================================
        // イベント登録
        // =====================================================
        var pendingAnswers = null;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!validate()) return;
            pendingAnswers = collectAnswers();
            showSection(consentSection);
        });

        // AIサポートを使う → API呼び出し
        document.getElementById('btn-use-ai').addEventListener('click', function() {
            if (pendingAnswers) submitReview(pendingAnswers);
        });

        // 自分で入力する → 手動入力画面
        document.getElementById('btn-use-manual').addEventListener('click', function() {
            showSection(manualSection);
        });

        // 手動入力: やり直す
        document.getElementById('btn-manual-back').addEventListener('click', function() {
            showSection(formSection);
        });

        // 「もう一度やり直す」ボタン
        document.getElementById('btn-back-to-form').addEventListener('click', function() {
            showSection(formSection);
        });

        // 「もう一度試す」（エラー画面から）
        document.getElementById('btn-error-retry').addEventListener('click', function() {
            showSection(formSection);
        });

        // =====================================================
        // ユーティリティ
        // =====================================================
        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
        function escapeAttr(str) {
            return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // =====================================================
        // 初期化
        // =====================================================
        renderQuestions();
        initCopyButtons();
    })();
    </script>
</body>
</html>
