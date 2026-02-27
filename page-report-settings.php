<?php
/*
Template Name: AIレポート設定
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;
$is_admin = current_user_can('manage_options'); // 管理者判定

// ページタイトル設定
set_query_var('gcrev_page_title', 'AIレポート設定');

// パンくず設定
$breadcrumb = '<a href="' . home_url() . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="#">設定</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>AIレポート設定</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

// 保存済みの設定を取得
$saved_site_url      = get_user_meta($user_id, 'report_site_url',      true) ?: '';
$saved_target        = get_user_meta($user_id, 'report_target',        true) ?: '';
$saved_issue         = get_user_meta($user_id, 'report_issue',         true) ?: '';
$saved_goal_monthly  = get_user_meta($user_id, 'report_goal_monthly',  true) ?: '';
$saved_goal_main     = get_user_meta($user_id, 'report_goal_main',     true) ?: '';
$saved_focus_numbers = get_user_meta($user_id, 'report_focus_numbers', true) ?: '';
$saved_current_state = get_user_meta($user_id, 'report_current_state', true) ?: '';
$saved_output_mode   = get_user_meta($user_id, 'report_output_mode',   true) ?: 'normal';

// WP-Membersからサイト URL を取得（初期値用）
$default_site_url = get_user_meta($user_id, 'weisite_url', true) ?: '';
// 優先順位：保存済み > WP-Members
$initial_site_url = $saved_site_url ?: $default_site_url;

get_header();
?>

<style>
/* page-report-settings — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <p id="loadingTitle">処理中...</p>
            <p id="loadingMessage">しばらくお待ちください</p>
        </div>
    </div>

    <!-- エラー表示 -->
    <div class="error-box" id="errorBox">
        <strong>⚠️ エラー</strong>
        <span id="errorMessage"></span>
    </div>

    <?php if (isset($_GET['reset']) && $_GET['reset'] === '1'): ?>
    <!-- リセット成功メッセージ -->
    <div class="success-message">
        <strong>✅ リセットが完了しました！</strong><br>
        レポートキャッシュがクリアされ、生成回数がリセットされました。
    </div>
    <?php endif; ?>

    <?php if ($is_admin): ?>
    <!-- キャッシュ管理セクション（管理者のみ表示） -->
    <div class="admin-refresh-section">
        <h3>🗄 キャッシュ管理</h3>
        <p style="font-size:13px;color:#64748b;margin:0 0 12px;">データが古い場合や、表示がおかしい場合にキャッシュを削除してください。</p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn-refresh" onclick="clearMyCache()">
                🔄 自分のキャッシュを削除
            </button>
            <button type="button" class="btn-refresh" style="background:#B5574B;" onmouseover="this.style.background='#9C4940'" onmouseout="this.style.background='#B5574B'" onclick="clearAllCache()">
                🗑 全ユーザーのキャッシュを削除
            </button>
        </div>
    </div>

    <?php endif; ?>

    <?php if ($is_admin): ?>
    <!-- 管理者用：レポート機能 -->
    <div class="admin-refresh-section">
        <h3>🔑 管理者機能</h3>
        <button type="button" class="btn-refresh" onclick="resetGenerationCount()">
            🔄 レポートキャッシュクリア＆回数リセット
        </button>
    </div>
    <?php endif; ?>

    <!-- レポート情報の設定 -->
    <div class="settings-card">
        <h2>
            <span>📋</span>
            <span>レポート情報の設定</span>
        </h2>
        <p>
            AIレポート生成のためのクライアント情報を入力してください。入力内容は保存され、次回以降も利用できます。
        </p>

        <div class="form-group">
            <label for="input-site-url">サイトURL <span class="required">*</span></label>
            <input type="url" id="input-site-url" placeholder="https://example.com" value="<?php echo esc_attr($initial_site_url); ?>">
        </div>

        <div class="form-group">
            <label for="input-target">主要ターゲット <span class="required">*</span></label>
            <input type="text" id="input-target" placeholder="例：30代〜40代のファミリー層" value="<?php echo esc_attr($saved_target); ?>">
        </div>

        <div class="form-group">
            <label for="input-issue">課題</label>
            <textarea id="input-issue" placeholder="例：問い合わせ数の増加、コンバージョン率向上"><?php echo esc_textarea($saved_issue); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="input-goal-monthly">今月の目標</label>
                <input type="text" id="input-goal-monthly" placeholder="例：CV数を前月比110%に" value="<?php echo esc_attr($saved_goal_monthly); ?>">
            </div>
            <div class="form-group">
                <label for="input-focus-numbers">注目している指標</label>
                <input type="text" id="input-focus-numbers" placeholder="例：PV数、直帰率、滞在時間" value="<?php echo esc_attr($saved_focus_numbers); ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="input-current-state">現状の取り組み</label>
            <textarea id="input-current-state" placeholder="例：ブログ更新を週2回実施、SNS広告を月5万円で運用中"><?php echo esc_textarea($saved_current_state); ?></textarea>
        </div>

        <div class="form-group">
            <label for="input-goal-main">主要目標</label>
            <textarea id="input-goal-main" placeholder="例：年間で問い合わせ数を200件に到達"><?php echo esc_textarea($saved_goal_main); ?></textarea>
        </div>

        <div class="form-group">
            <label for="input-additional-notes">その他留意事項</label>
            <textarea id="input-additional-notes" placeholder="レポート生成時に考慮してほしい事項を記入してください(任意)&#10;例：12月にキャンペーンを実施、サイトリニューアルを予定、季節要因など"><?php echo esc_textarea(get_user_meta($user_id, 'report_additional_notes', true)); ?></textarea>
            <small class="form-text">季節要因、キャンペーン情報、サイト変更などを記入すると、より的確な分析が可能です</small>
        </div>

        <!-- 出力モード選択 -->
        <div class="output-mode-group">
            <h3>
                <span>🎯</span>
                <span>レポート出力モード</span>
            </h3>
            <div class="output-mode-options">
                <div class="output-mode-option">
                    <input type="radio" id="mode-normal" name="output-mode" value="normal" <?php checked($saved_output_mode, 'normal'); ?>>
                    <label for="mode-normal">
                        <strong>通常モード</strong>
                        <span>専門的な用語を使用した詳細なレポート</span>
                    </label>
                </div>
                <div class="output-mode-option">
                    <input type="radio" id="mode-easy" name="output-mode" value="easy" <?php checked($saved_output_mode, 'easy'); ?>>
                    <label for="mode-easy">
                        <strong>初心者向けモード</strong>
                        <span>わかりやすい表現と用語解説付きのレポート</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 生成回数表示 -->
        <div class="generation-count-info" id="generationCountInfo" style="display: none;">
            <span class="count-icon">📊</span>
            <div>
                <div class="count-title">今月の生成回数</div>
                <div class="count-detail">
                    <span class="current" id="current-count">0</span> / <span id="max-count">10</span> 回
                    <span class="remaining" id="remaining-count">（残り10回）</span>
                </div>
            </div>
        </div>

        <?php
        // 前々月データチェック（通知表示用）
        // 重いAPI呼び出し（has_prev2_data）を避け、キャッシュ → 設定チェックで軽量判定
        global $gcrev_api_instance;
        if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
            $gcrev_api_instance = new Gcrev_Insight_API(false);
        }
        $config_tmp = new Gcrev_Config();
        $user_config_rs = $config_tmp->get_user_config($user_id);
        $has_ga4_rs = !empty($user_config_rs['ga4_id']);
        if ( $has_ga4_rs ) {
            // キャッシュに前々月データがあればゼロ判定も可能
            $cached_prev2 = get_transient('gcrev_dash_' . $user_id . '_twoMonthsAgo');
            if ( $cached_prev2 && is_array($cached_prev2) ) {
                $sessions_rs   = (int) ($cached_prev2['ga4']['total']['sessions']  ?? 0);
                $page_views_rs = (int) ($cached_prev2['ga4']['total']['pageViews'] ?? 0);
                $prev2_check_rs = [
                    'available' => true,
                    'is_zero'   => ($sessions_rs === 0 && $page_views_rs === 0),
                ];
            } else {
                // キャッシュなし → GA4設定ありなので available とみなす（実際の判定はレポート生成時）
                $prev2_check_rs = ['available' => true, 'is_zero' => false];
            }
        } else {
            $prev2_check_rs = ['available' => false, 'reason' => 'GA4プロパティが設定されていません。'];
        }
        if (!$prev2_check_rs['available']):
        ?>
        <div class="gcrev-notice-prev2" id="prev2-notice">
          <span class="notice-icon">⚠️</span>
          <div class="notice-text">
            <strong>AIレポートを生成できません。</strong><br>
            <?php echo esc_html($prev2_check_rs['reason'] ?? 'GA4プロパティの設定を確認してください。'); ?>
          </div>
        </div>
        <?php elseif (!empty($prev2_check_rs['is_zero'])): ?>
        <div class="gcrev-notice-prev2" id="prev2-notice" style="background: #EFF6FF; border-left-color: #3B82F6;">
          <span class="notice-icon">ℹ️</span>
          <div class="notice-text">
            前々月のアクセスデータがゼロのため、「ゼロからの成長」としてレポートが生成されます。
          </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="button" class="btn btn-secondary" id="btn-save" onclick="saveClientInfo()">
                💾 情報を保存
            </button>
            <button type="button" class="btn btn-generate" id="btn-generate" onclick="generateReport()">
                ✨ AIレポートを生成する
            </button>
        </div>
    </div>

    <!-- 実質CV入力（経路別・日別） ※電話は手入力しない -->
    <div class="settings-card" id="actual-cv-card">
        <h2>
            <span>⚙️</span>
            <span>手動値を優先するキーイベントの設定</span>
        </h2>
        <p>GA4キーイベントのうち、手動入力値を優先させたいイベントを最大10件まで設定できます。設定しない場合はGA4の全キーイベント合計がCV数として使用されます。</p>
        <div id="cv-routes-editor">
            <table class="actual-cv-table cv-routes-table" style="margin-bottom:16px;">
                <thead><tr><th style="width:36px;"></th><th>GA4キーイベント名</th><th>表示ラベル</th><th style="width:60px;">削除</th></tr></thead>
                <tbody id="cv-routes-rows"></tbody>
            </table>
            <div style="margin-bottom:16px;">
                <button type="button" class="btn btn-outline" id="btn-add-cv-route" data-gcrev-ignore-unsaved="1" style="font-size:13px;">＋ キーイベントを追加</button>
                <span id="cv-routes-count" style="font-size:12px;color:#666666;margin-left:8px;"></span>
            </div>
            <div class="form-group">
                <label for="cv-only-configured" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cv-only-configured" data-gcrev-ignore-unsaved="1">
                    <span>設定したキーイベント以外はCV分析に含めない</span>
                </label>
            </div>
            <div class="form-group" id="phone-event-row" style="display:none;">
                <label for="phone-event-name">電話タップのGA4イベント名（常に加算）</label>
                <input type="text" id="phone-event-name" list="ga4-key-events-list" placeholder="例: phone_tap" data-gcrev-ignore-unsaved="1">
                <small class="form-text">上のチェックがONでも、ここで指定した電話タップイベントは常にCV合計に加算されます</small>
            </div>
            <div class="form-actions" style="margin-bottom:24px;">
                <button type="button" class="btn btn-secondary" id="btn-save-cv-routes" data-gcrev-ignore-unsaved="1">💾 設定を保存</button>
            </div>
            <datalist id="ga4-key-events-list"></datalist>
        </div>
        <hr style="margin:24px 0;border:none;border-top:1px solid #E2E6EA;">

        <h2>
            <span>🧾</span>
            <span>手動CV入力（キーイベント別・日別）</span>
        </h2>
        <p>
            設定したキーイベントの手動値を日別に入力してください。
            <strong>空欄</strong>は「未入力（GA4値を使用）」、<strong>0</strong>は「確定0（手動優先）」として保存されます。
        </p>

        <div class="actual-cv-note">
            <strong>入力の考え方：</strong><br>
            ・上で設定したキーイベントのみ手動入力が可能です<br>
            ・手動値が入力された月は、そのイベントのGA4値の代わりに手動値が使用されます<br>
            ・未設定のキーイベントはGA4の値がそのまま使われます
        </div>

        <input type="hidden" id="actual-cv-user" value="<?php echo esc_attr($user_id); ?>">

        <div class="actual-cv-toolbar">
            <div class="form-group" style="margin-bottom:0;">
                <label for="actual-cv-month">対象月</label>
                <?php
                    $default_month = (new DateTimeImmutable('first day of last month', wp_timezone()))->format('Y-m');
                ?>
                <div class="month-nav">
                    <button type="button" class="month-nav-btn" id="btn-prev-month" title="前月">◀</button>
                    <input type="month" id="actual-cv-month" value="<?php echo esc_attr($default_month); ?>" data-default="<?php echo esc_attr($default_month); ?>" data-gcrev-ignore-unsaved="1">
                    <button type="button" class="month-nav-btn" id="btn-next-month" title="翌月">▶</button>
                </div>
            </div>
            <button type="button" class="btn-reset-cv" id="btn-reset-cv" title="この月の入力をすべてクリアします">
                🗑 この月をリセット
            </button>
        </div>

        <div class="actual-cv-table-wrap">
            <table class="actual-cv-table">
                <thead id="actual-cv-thead">
                    <!-- JSでroutes対応ヘッダーを動的生成 -->
                </thead>
                <tbody id="actual-cv-rows">
                    <!-- JSで生成 -->
                </tbody>
            </table>
        </div>

        <div class="actual-cv-summary" id="actual-cv-summary-pills">
            <span class="actual-cv-pill">月合計：<span id="actual-cv-total-all">0</span> 件</span>
            <!-- JSでroutes対応pill動的生成 -->
        </div>

        <div class="form-actions" style="margin-top: 16px;">
            <button type="button" class="btn btn-secondary" id="btn-actual-cv-save" data-gcrev-ignore-unsaved="1">
                💾 実質CVを保存
            </button>
        </div>
    </div>

</div>

<script>
// ===== グローバル変数 =====
const restBase = '<?php echo esc_js(trailingslashit(rest_url('gcrev_insights/v1'))); ?>';
const wpNonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;

// フォーム変更検知用
let initialFormData = {};
let hasUnsavedChanges = false;

// ===== ページ読み込み時の初期化 =====
document.addEventListener('DOMContentLoaded', function() {
    loadGenerationCount();
    saveInitialFormData();
    attachFormChangeListeners();

    // 実質CV UI初期化（このページ内）
    initActualCvUI();
});

// ===== フォームの初期値を保存 =====
function saveInitialFormData() {
    initialFormData = getClientInputs();
}

// ===== フォーム変更検知 =====
function attachFormChangeListeners() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {

        // 実質CV入力は「クライアント情報の未保存検知」対象外
        if (input.dataset.gcrevIgnoreUnsaved === '1') return;
        if (input.closest('#actual-cv-card')) return;

        input.addEventListener('input', checkFormChanges);
        input.addEventListener('change', checkFormChanges);
    });
}

function checkFormChanges() {
    const currentData = getClientInputs();
    const saveBtn = document.getElementById('btn-save');

    hasUnsavedChanges = JSON.stringify(initialFormData) !== JSON.stringify(currentData);

    if (hasUnsavedChanges) {
        saveBtn.classList.add('has-changes');
    } else {
        saveBtn.classList.remove('has-changes');
    }
}

// ===== 生成回数取得 =====
async function loadGenerationCount() {
    try {
        const res = await fetch(restBase + 'report/generation-count', {
            headers: { 'X-WP-Nonce': wpNonce }
        });

        if (!res.ok) return;

        const json = await res.json();
        if (json.success && json.data) {
            displayGenerationCount(json.data);
        }
    } catch (e) {
        console.error('生成回数取得エラー:', e);
    }
}

// ===== 生成回数表示 =====
function displayGenerationCount(data) {
    const infoBox = document.getElementById('generationCountInfo');
    const currentCount = document.getElementById('current-count');
    const maxCount = document.getElementById('max-count');
    const remainingCount = document.getElementById('remaining-count');
    const generateBtn = document.getElementById('btn-generate');

    if (!infoBox || !currentCount || !maxCount || !remainingCount) return;

    currentCount.textContent = data.current_count;
    maxCount.textContent = data.max_count;
    remainingCount.textContent = `（残り${data.remaining}回）`;

    // 色変更
    if (data.remaining === 0) {
        remainingCount.style.color = '#B5574B';
        remainingCount.textContent = '（上限到達）';
        generateBtn.disabled = true;
    } else if (data.remaining <= 2) {
        remainingCount.style.color = '#ea580c';
    } else {
        remainingCount.style.color = '#3D8B6E';
    }

    infoBox.style.display = 'flex';
}

// ===== クライアント情報を保存 =====
async function saveClientInfo() {
    const data = getClientInputs();

    // バリデーション
    if (!data.site_url || !data.target) {
        showError('サイトURLと主要ターゲットは必須項目です。');
        return;
    }

    hideError();
    showLoading('情報を保存中...', '');

    try {
        const res = await fetch(restBase + 'save-client-info', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpNonce
            },
            body: JSON.stringify(data)
        });

        if (!res.ok) {
            throw new Error('保存に失敗しました');
        }

        const json = await res.json();
        if (json.success) {
            // 保存成功：初期値を更新してボタンをグレーに戻す
            saveInitialFormData();
            hasUnsavedChanges = false;
            document.getElementById('btn-save').classList.remove('has-changes');

            alert('✅ クライアント情報を保存しました！');
        } else {
            throw new Error(json.message || '保存に失敗しました');
        }
    } catch (err) {
        showError(err.message);
        console.error('保存エラー:', err);
    } finally {
        hideLoading();
    }
}

// ===== AIレポート生成 =====
async function generateReport() {
    // 未保存の変更がある場合は警告
    if (hasUnsavedChanges) {
        if (!confirm('未保存の変更があります。先に保存しますか？')) {
            return;
        }
        await saveClientInfo();
    }

    const clientData = getClientInputs();

    // バリデーション
    if (!clientData.site_url || !clientData.target) {
        showError('サイトURLと主要ターゲットは必須項目です。');
        return;
    }

    hideError();
    showLoading('レポートを生成しています...', 'GA4の設定を確認中...');
    const btnGenerate = document.getElementById('btn-generate');
    if (btnGenerate) btnGenerate.disabled = true;

    try {
        // Step 0: GA4プロパティ設定チェック
        const checkUrl = '<?php echo esc_js(rest_url('gcrev/v1/report/check-prev2-data')); ?>';
        const checkRes = await fetch(checkUrl, {
            headers: { 'X-WP-Nonce': wpNonce }
        });
        if (checkRes.ok) {
            const checkJson = await checkRes.json();
            if (checkJson.code === 'NO_PREV2_DATA') {
                hideLoading();
                if (btnGenerate) btnGenerate.disabled = false;
                showError(checkJson.reason || 'GA4プロパティの設定を確認してください。');
                return;
            }
        }

        // Step 1: 前月データを取得
        updateLoadingText('レポートを生成しています...', '前月のデータを取得中...');
        const prevMonthData = await fetchDashboardData('previousMonth');

        // Step 2: 前々月データを取得
        updateLoadingText('レポートを生成しています...', '前々月のデータを取得中...');
        const twoMonthsData = await fetchDashboardData('twoMonthsAgo');

        // Step 3: AIレポートを生成
        updateLoadingText('レポートを生成しています...', 'AIが分析レポートを作成中...');
        await callGenerateReport(prevMonthData, twoMonthsData, clientData);

        // Step 4: 生成回数を更新
        updateLoadingText('完了しました！', '生成回数を更新しています...');
        await loadGenerationCount();

        // Step 5: 成功 - ダッシュボードへ遷移
        updateLoadingText('完了しました！', 'ダッシュボードへ移動します...');
        await new Promise(resolve => setTimeout(resolve, 1500));
        window.location.href = '<?php echo esc_url(home_url('/dashboard/')); ?>';

    } catch (err) {
        showError(err.message);
        console.error('レポート生成エラー:', err);
        if (btnGenerate) btnGenerate.disabled = false;
        hideLoading();
    }
}

// ===== ダッシュボードデータ取得 =====
async function fetchDashboardData(range) {
    const url = restBase + 'dashboard?range=' + encodeURIComponent(range);
    const res = await fetch(url, {
        headers: { 'X-WP-Nonce': wpNonce }
    });

    if (!res.ok) {
        throw new Error('データ取得失敗 (' + range + '): HTTP ' + res.status);
    }

    const json = await res.json();
    if (!json.success || !json.data) {
        throw new Error('データ形式が不正です (' + range + ')');
    }

    return json.data;
}

// ===== レポート生成API呼び出し =====
async function callGenerateReport(prevData, twoData, clientData) {
    // 前月の年月を計算（レポートは前月分として保存される）
    const now = new Date();
    const prevMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const year_month = prevMonth.getFullYear() + '-' + String(prevMonth.getMonth() + 1).padStart(2, '0');

    const res = await fetch(restBase + 'generate-report', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': wpNonce
        },
        body: JSON.stringify({
            previous_month: prevData,
            two_months_ago: twoData,
            client_info: clientData,
            year_month: year_month  // 前月の年月を明示的に指定
        })
    });

    const json = await res.json();

    if (!res.ok || !json.success) {
        if (json.code === 'NO_PREV2_DATA') {
            throw new Error(json.message || 'GA4プロパティの設定を確認してください。');
        }
        throw new Error(json.message || 'レポート生成に失敗しました (HTTP ' + res.status + ')');
    }

    return json;
}

// ===== フォーム値取得 =====
function getClientInputs() {
    // 出力モードを取得
    const modeRadios = document.getElementsByName('output-mode');
    let outputMode = 'normal';
    for (const radio of modeRadios) {
        if (radio.checked) {
            outputMode = radio.value;
            break;
        }
    }

    return {
        site_url: getValue('input-site-url'),
        target: getValue('input-target'),
        issue: getValue('input-issue'),
        goal_monthly: getValue('input-goal-monthly'),
        focus_numbers: getValue('input-focus-numbers'),
        current_state: getValue('input-current-state'),
        goal_main: getValue('input-goal-main'),
        additional_notes: getValue('input-additional-notes'),
        output_mode: outputMode
    };
}

// ===== ユーティリティ関数 =====
function getValue(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : '';
}

function showLoading(title, message) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.add('active');
    updateLoadingText(title, message);
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('active');
}

function updateLoadingText(title, message) {
    const titleEl = document.getElementById('loadingTitle');
    const messageEl = document.getElementById('loadingMessage');
    if (titleEl) titleEl.textContent = title;
    if (messageEl) messageEl.textContent = message;
}

function showError(message) {
    const errorBox = document.getElementById('errorBox');
    const errorMessage = document.getElementById('errorMessage');
    if (errorBox && errorMessage) {
        errorMessage.textContent = message;
        errorBox.classList.add('visible');
    }
}

function hideError() {
    const errorBox = document.getElementById('errorBox');
    if (errorBox) errorBox.classList.remove('visible');
}

// ===== 自分のキャッシュ削除 =====
async function clearMyCache() {
    if (!confirm('あなたのキャッシュをすべて削除します。\nダッシュボード・分析ページのデータが次回アクセス時に再取得されます。\n\n実行しますか？')) {
        return;
    }
    showLoading('キャッシュ削除中...', 'しばらくお待ちください');
    try {
        const res = await fetch(restBase + 'clear-my-cache', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce }
        });
        if (!res.ok) throw new Error('キャッシュ削除に失敗しました (HTTP ' + res.status + ')');
        const json = await res.json();
        hideLoading();
        if (json.success) {
            alert('✅ キャッシュを削除しました。\n削除件数: ' + (json.deleted ?? '不明'));
        } else {
            alert('❌ ' + (json.message || 'キャッシュ削除に失敗しました'));
        }
    } catch (e) {
        hideLoading();
        alert('❌ エラー: ' + e.message);
    }
}

// ===== 管理者用：全キャッシュ削除 =====
async function clearAllCache() {
    if (!isAdmin) {
        alert('この機能は管理者のみ利用できます。');
        return;
    }
    if (!confirm('全ユーザーのキャッシュをすべて削除します。\nダッシュボード・分析・レポート等、全データが再取得されます。\n\n本当に実行しますか？')) {
        return;
    }
    showLoading('キャッシュ削除中...', 'しばらくお待ちください');
    try {
        const res = await fetch(restBase + 'clear-cache', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce }
        });
        if (!res.ok) throw new Error('キャッシュ削除に失敗しました (HTTP ' + res.status + ')');
        const json = await res.json();
        hideLoading();
        if (json.success) {
            alert('✅ 全ユーザーのキャッシュを削除しました。\n削除件数: ' + (json.deleted ?? '不明'));
        } else {
            alert('❌ ' + (json.message || 'キャッシュ削除に失敗しました'));
        }
    } catch (e) {
        hideLoading();
        alert('❌ エラー: ' + e.message);
    }
}

// ===== 管理者用：生成回数リセット機能 =====
async function resetGenerationCount() {
    if (!isAdmin) {
        alert('この機能は管理者のみ利用できます。');
        return;
    }

    if (!confirm('レポートキャッシュをクリアし、今月の生成回数をリセットします。\n\n本当に実行しますか？')) {
        return;
    }

    showLoading('リセット中...', 'しばらくお待ちください');

    try {
        const res = await fetch(restBase + 'report/reset-generation-count', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpNonce
            }
        });

        if (!res.ok) {
            throw new Error('リセットに失敗しました (HTTP ' + res.status + ')');
        }

        const json = await res.json();

        if (json.success) {
            hideLoading();
            alert('✅ ' + json.message + '\n\n削除されたレポート: ' + json.data.deleted_reports + '件\n削除されたキャッシュ: ' + json.data.deleted_cache + '件');

            // 生成回数を再読み込み
            await loadGenerationCount();
        } else {
            throw new Error(json.message || 'リセットに失敗しました');
        }
    } catch (e) {
        hideLoading();
        showError('リセットエラー: ' + e.message);
        console.error('Reset error:', e);
    }
}

/* ========================================
   実質CV（経路別・日別） UI
   ※電話は手入力しない（GTM/GA4の電話タップを採用）
   ※フォーム最大5種+LINE+その他をREST APIから動的取得
   ======================================== */

// 全ルート（エディタ用・enabled関係なく全件）
let ALL_CV_ROUTES = [];
// 有効ルート（CV入力テーブル用）
let ACTUAL_CV_ROUTES = [];

// --- Dirty tracking: 変更があったらボタンを青くする ---
function markDirty(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '#3D6B6E';
    btn.style.borderColor = '#3D6B6E';
    btn.style.color = '#fff';
}
function markClean(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '';
    btn.style.borderColor = '';
    btn.style.color = '';
}

// === キーイベント設定 UI ===
let GA4_KEY_EVENTS = {}; // {eventName: count} from GA4

async function fetchGa4KeyEvents() {
    try {
        const userId = parseInt(document.getElementById('actual-cv-user').value, 10);
        const res = await fetch(restBase + 'ga4-key-events?user_id=' + userId + '&_=' + Date.now(), { headers: { 'X-WP-Nonce': wpNonce } });
        if (!res.ok) return;
        const json = await res.json();
        if (json.success && json.data) {
            GA4_KEY_EVENTS = json.data;
            const dl = document.getElementById('ga4-key-events-list');
            if (dl) {
                dl.innerHTML = '';
                Object.keys(GA4_KEY_EVENTS).forEach(name => {
                    const opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name + ' (' + GA4_KEY_EVENTS[name] + '件)';
                    dl.appendChild(opt);
                });
            }
        }
    } catch (e) { console.error('GA4 key events load error', e); }
}

async function initCvRoutesUI() {
    await fetchGa4KeyEvents();
    try {
        const res = await fetch(restBase + 'actual-cv/routes?_=' + Date.now(), { headers: { 'X-WP-Nonce': wpNonce } });
        if (!res.ok) return;
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            ALL_CV_ROUTES = json.data;
            ACTUAL_CV_ROUTES = json.data.filter(r => r.enabled == 1).map(r => ({ key: r.route_key, label: r.label, enabled: 1 }));
            renderCvRoutesEditor(ALL_CV_ROUTES);
            updateSummaryPills();
            updateRoutesCount();
        }
        // チェックボックス・電話タップ設定の復元
        const chk = document.getElementById('cv-only-configured');
        const phoneRow = document.getElementById('phone-event-row');
        const phoneInput = document.getElementById('phone-event-name');
        if (chk) {
            chk.checked = !!json.cv_only_configured;
            if (phoneRow) phoneRow.style.display = chk.checked ? 'block' : 'none';
            chk.addEventListener('change', () => {
                if (phoneRow) phoneRow.style.display = chk.checked ? 'block' : 'none';
                markDirty('btn-save-cv-routes');
            });
        }
        if (phoneInput) {
            phoneInput.value = json.phone_event_name || '';
            phoneInput.addEventListener('input', () => markDirty('btn-save-cv-routes'));
        }
    } catch (e) { console.error('CV routes load error', e); }
}

function renderCvRoutesEditor(routes) {
    const tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    tbody.innerHTML = '';
    routes.forEach((r, i) => {
        addRouteRow(r.route_key, r.label, i + 1);
    });
    markClean('btn-save-cv-routes');
}

function addRouteRow(eventName, label, order) {
    const tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    const currentCount = tbody.querySelectorAll('tr').length;
    if (currentCount >= 10) {
        alert('キーイベントは最大10件まで設定できます');
        return;
    }
    const tr = document.createElement('tr');
    tr.draggable = true;
    tr.innerHTML = `
        <td class="drag-handle" title="ドラッグで並べ替え">⠿</td>
        <td><input type="text" list="ga4-key-events-list" value="${eventName||''}" data-field="route_key" placeholder="GA4イベント名を入力..." data-gcrev-ignore-unsaved="1" style="width:100%;font-family:monospace;font-size:13px;"></td>
        <td><input type="text" value="${label||''}" data-field="label" placeholder="表示ラベル" data-gcrev-ignore-unsaved="1"></td>
        <td style="text-align:center;"><button type="button" class="btn-remove-route" style="background:none;border:none;cursor:pointer;font-size:16px;color:#C0392B;" title="削除">✕</button></td>`;
    tr.querySelectorAll('input').forEach(inp => {
        inp.addEventListener('change', () => markDirty('btn-save-cv-routes'));
        inp.addEventListener('input', () => markDirty('btn-save-cv-routes'));
    });
    tr.querySelector('.btn-remove-route').addEventListener('click', () => {
        tr.remove();
        markDirty('btn-save-cv-routes');
        updateRoutesCount();
    });
    setupRowDragEvents(tr);
    tbody.appendChild(tr);
    updateRoutesCount();
}

// === ドラッグ＆ドロップ並べ替え ===
let dragSrcRow = null;

function setupRowDragEvents(tr) {
    tr.addEventListener('dragstart', (e) => {
        dragSrcRow = tr;
        tr.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    tr.addEventListener('dragend', () => {
        tr.classList.remove('dragging');
        document.querySelectorAll('#cv-routes-rows tr.drag-over').forEach(r => r.classList.remove('drag-over'));
        dragSrcRow = null;
    });
    tr.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (dragSrcRow && dragSrcRow !== tr) {
            tr.classList.add('drag-over');
        }
    });
    tr.addEventListener('dragleave', () => {
        tr.classList.remove('drag-over');
    });
    tr.addEventListener('drop', (e) => {
        e.preventDefault();
        tr.classList.remove('drag-over');
        if (!dragSrcRow || dragSrcRow === tr) return;
        const tbody = tr.parentNode;
        const rows = [...tbody.querySelectorAll('tr')];
        const fromIdx = rows.indexOf(dragSrcRow);
        const toIdx = rows.indexOf(tr);
        if (fromIdx < toIdx) {
            tbody.insertBefore(dragSrcRow, tr.nextSibling);
        } else {
            tbody.insertBefore(dragSrcRow, tr);
        }
        markDirty('btn-save-cv-routes');
    });
}

function updateRoutesCount() {
    const tbody = document.getElementById('cv-routes-rows');
    const counter = document.getElementById('cv-routes-count');
    if (!tbody || !counter) return;
    const count = tbody.querySelectorAll('tr').length;
    counter.textContent = count + ' / 10 件';
    const addBtn = document.getElementById('btn-add-cv-route');
    if (addBtn) addBtn.disabled = count >= 10;
}

document.getElementById('btn-add-cv-route')?.addEventListener('click', () => {
    addRouteRow('', '', 0);
    markDirty('btn-save-cv-routes');
});

function updateSummaryPills() {
    const c = document.getElementById('actual-cv-summary-pills');
    if (!c) return;
    c.innerHTML = '<span class="actual-cv-pill">月合計：<span id="actual-cv-total-all">0</span> 件</span>';
    ACTUAL_CV_ROUTES.forEach(r => {
        const s = document.createElement('span');
        s.className = 'actual-cv-pill';
        s.innerHTML = `${r.label || r.key}：<span id="actual-cv-total-${r.key}">0</span>`;
        c.appendChild(s);
    });
}

document.getElementById('btn-save-cv-routes')?.addEventListener('click', async () => {
    const rows = document.querySelectorAll('#cv-routes-rows tr');
    const routes = [];
    let hasError = false;
    rows.forEach((tr, i) => {
        const rkInput = tr.querySelector('input[data-field="route_key"]');
        const li = tr.querySelector('input[data-field="label"]');
        if (!rkInput) return;
        const rk = rkInput.value.trim();
        if (!rk) { hasError = true; return; }
        routes.push({
            route_key: rk,
            label: (li ? li.value.trim() : '') || rk,
            enabled: 1,
            sort_order: i + 1
        });
    });

    if (hasError) {
        alert('GA4キーイベント名が空の行があります。入力するか、行を削除してください。');
        return;
    }

    const btn = document.getElementById('btn-save-cv-routes');
    const origText = btn.textContent;
    btn.textContent = '保存中...';
    btn.disabled = true;
    const userId = parseInt(document.getElementById('actual-cv-user').value, 10);
    try {
        const res = await fetch(restBase + 'actual-cv/routes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
            body: JSON.stringify({
                user_id: userId,
                routes,
                cv_only_configured: !!document.getElementById('cv-only-configured')?.checked,
                phone_event_name: (document.getElementById('phone-event-name')?.value || '').trim(),
            }),
            cache: 'no-store'
        });
        if (!res.ok) {
            const errText = await res.text();
            console.error('[GCREV] Save routes HTTP error:', res.status, errText);
            btn.textContent = '❌ HTTP ' + res.status;
            setTimeout(() => { btn.textContent = origText; }, 3000);
            return;
        }
        const json = await res.json();
        if (json.success) {
            btn.textContent = '✅ 保存完了';
            markClean('btn-save-cv-routes');
            await initCvRoutesUI();
            const me = document.getElementById('actual-cv-month');
            if (me) await loadActualCv(me.value, userId);
            setTimeout(() => { btn.textContent = origText; }, 1500);
        } else {
            btn.textContent = '❌ ' + (json.message || '保存失敗');
            setTimeout(() => { btn.textContent = origText; }, 3000);
        }
    } catch (e) {
        console.error('[GCREV] Save routes error:', e);
        btn.textContent = '❌ エラー';
        setTimeout(() => { btn.textContent = origText; }, 2000);
    } finally {
        btn.disabled = false;
    }
});

// === sessionStorage による未保存CV入力の一時保持 ===
// ブラウザを閉じるまで、月を切り替えても入力値が保持される

function cvDraftKey(userId, month) {
    return 'gcrev_cv_draft_' + userId + '_' + month;
}

/** 現在のテーブル入力値を sessionStorage に退避 */
function saveCvDraftToSession(month, userId) {
    const tbody = document.getElementById('actual-cv-rows');
    if (!tbody) return;
    const draft = {};
    tbody.querySelectorAll('input[type="number"]').forEach(inp => {
        const date  = inp.dataset.date;
        const route = inp.dataset.route;
        if (!date || !route) return;
        if (!draft[date]) draft[date] = {};
        // 空文字は null で保持（「未入力」と「0」を区別）
        draft[date][route] = inp.value === '' ? null : parseInt(inp.value, 10);
    });
    try {
        sessionStorage.setItem(cvDraftKey(userId, month), JSON.stringify(draft));
    } catch (_) { /* QuotaExceeded 等は無視 */ }
}

/** sessionStorage から下書きを取得（無ければ null） */
function getCvDraftFromSession(userId, month) {
    try {
        const raw = sessionStorage.getItem(cvDraftKey(userId, month));
        return raw ? JSON.parse(raw) : null;
    } catch (_) { return null; }
}

/** 保存成功時に下書きを破棄 */
function clearCvDraft(userId, month) {
    try { sessionStorage.removeItem(cvDraftKey(userId, month)); } catch (_) {}
}

/** テーブル描画後、下書きがあれば入力欄へ復元 */
function restoreCvDraftToTable(userId, month) {
    const draft = getCvDraftFromSession(userId, month);
    if (!draft) return false;
    const tbody = document.getElementById('actual-cv-rows');
    if (!tbody) return false;
    let restored = false;
    tbody.querySelectorAll('input[type="number"]').forEach(inp => {
        const date  = inp.dataset.date;
        const route = inp.dataset.route;
        if (!draft[date] || !(route in draft[date])) return;
        const v = draft[date][route];
        inp.value = v === null ? '' : String(v);
        restored = true;
    });
    if (restored) {
        // 日合計・月合計を再計算
        tbody.querySelectorAll('tr').forEach(tr => updateActualCvDayTotalRow(tr));
        recalcActualCvTotals();
        markDirty('btn-actual-cv-save');
    }
    return restored;
}

// === 実質CV日別入力 UI ===
let _currentCvMonth = null; // 現在表示中の月（退避用）
let _currentCvUserId = null;

async function initActualCvUI() {
    const monthEl = document.getElementById('actual-cv-month');
    const userEl  = document.getElementById('actual-cv-user');
    const saveBtn = document.getElementById('btn-actual-cv-save');
    const prevBtn = document.getElementById('btn-prev-month');
    const nextBtn = document.getElementById('btn-next-month');

    if (!monthEl || !userEl || !saveBtn) return;

    _currentCvUserId = parseInt(userEl.value, 10);

    await initCvRoutesUI();

    _currentCvMonth = monthEl.value;
    await loadActualCv(monthEl.value, _currentCvUserId);

    monthEl.addEventListener('change', async () => {
        if (!monthEl.value) {
            monthEl.value = monthEl.dataset.default;
        }
        // 切り替え前の月の入力を退避
        if (_currentCvMonth && _currentCvMonth !== monthEl.value) {
            saveCvDraftToSession(_currentCvMonth, _currentCvUserId);
        }
        _currentCvMonth = monthEl.value;
        await loadActualCv(monthEl.value, _currentCvUserId);
    });

    saveBtn.addEventListener('click', async () => {
        await saveActualCv(monthEl.value, _currentCvUserId);
    });

    // 前月・翌月ボタン
    function shiftMonth(offset) {
        const val = monthEl.value || monthEl.dataset.default;
        const [y, m] = val.split('-').map(Number);
        const d = new Date(y, m - 1 + offset, 1);
        const newVal = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
        monthEl.value = newVal;
        monthEl.dispatchEvent(new Event('change'));
    }

    if (prevBtn) prevBtn.addEventListener('click', () => shiftMonth(-1));
    if (nextBtn) nextBtn.addEventListener('click', () => shiftMonth(1));

    // リセットボタン
    const resetBtn = document.getElementById('btn-reset-cv');
    if (resetBtn) {
        resetBtn.addEventListener('click', async () => {
            const month = monthEl.value || monthEl.dataset.default;
            if (!confirm(month + ' の入力データをすべて削除します。\nよろしいですか？')) return;

            // 全入力をクリア
            const tbody = document.getElementById('actual-cv-rows');
            if (tbody) {
                tbody.querySelectorAll('input[type="number"]').forEach(i => { i.value = ''; });
            }

            // 下書きも破棄
            clearCvDraft(_currentCvUserId, month);

            // クリア状態で保存（count: null → サーバー側でDELETE）
            await saveActualCv(month, _currentCvUserId);
        });
    }
}

async function loadActualCv(month, userId) {
    try {
        const url = new URL(restBase + 'actual-cv');
        url.searchParams.set('month', month);
        url.searchParams.set('user_id', String(userId));
        url.searchParams.set('_', String(Date.now()));

        const res = await fetch(url.toString(), {
            headers: { 'X-WP-Nonce': wpNonce }
        });
        if (!res.ok) throw new Error('Load failed');

        const json = await res.json();
        if (!json.success) throw new Error('Load failed');

        if (json.data.routes && Array.isArray(json.data.routes)
            && json.data.routes.length > 0 && typeof json.data.routes[0] === 'object' && json.data.routes[0].route_key) {
            ACTUAL_CV_ROUTES = json.data.routes.map(r => ({ key: r.route_key, label: r.label || r.route_key, enabled: 1 }));
            updateSummaryPills();
        }

        renderActualCvTable(json.data.items);
        recalcActualCvTotals();

        // sessionStorage に下書きがあれば復元（未保存入力の保持）
        const restored = restoreCvDraftToTable(userId, month);
        if (!restored) {
            markClean('btn-actual-cv-save');
        }
    } catch (e) {
        console.error(e);
    }
}

function renderActualCvTable(items) {
    const thead = document.getElementById('actual-cv-thead');
    if (thead) {
        let h = '<tr><th>日付</th>';
        ACTUAL_CV_ROUTES.forEach(r => { h += `<th>${r.label}</th>`; });
        h += '<th>日合計</th></tr>';
        thead.innerHTML = h;
    }

    const tbody = document.getElementById('actual-cv-rows');
    if (!tbody) return;
    tbody.innerHTML = '';

    Object.keys(items).forEach(dateStr => {
        const tr = document.createElement('tr');

        const tdDate = document.createElement('td');
        tdDate.className = 'date';
        // 日付表示: YYYY-MM-DD → D日（例: 1日, 15日）
        const dp = dateStr.split('-');
        const dayNum = dp.length === 3 ? parseInt(dp[2], 10) : dateStr;
        tdDate.textContent = dayNum + '日';
        tdDate.dataset.fullDate = dateStr;
        tdDate.title = dateStr;
        tr.appendChild(tdDate);

        ACTUAL_CV_ROUTES.forEach(r => {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'number';
            input.min = '0';
            input.max = '99';
            input.step = '1';
            input.dataset.date = dateStr;
            input.dataset.route = r.key;
            input.dataset.gcrevIgnoreUnsaved = '1';

            const val = items[dateStr] ? (items[dateStr][r.key] ?? null) : null;
            input.value = (val === null || typeof val === 'undefined') ? '' : String(val);

            input.addEventListener('input', () => {
                updateActualCvDayTotalRow(tr);
                recalcActualCvTotals();
                markDirty('btn-actual-cv-save');
            });

            td.appendChild(input);
            tr.appendChild(td);
        });

        const tdSum = document.createElement('td');
        tdSum.className = 'day-sum';
        tdSum.textContent = '0';
        tr.appendChild(tdSum);

        tbody.appendChild(tr);
        updateActualCvDayTotalRow(tr);
    });
}

function updateActualCvDayTotalRow(tr) {
    let sum = 0;
    tr.querySelectorAll('input[type="number"]').forEach(i => {
        if (i.value !== '') sum += parseInt(i.value, 10) || 0;
    });
    const td = tr.querySelector('.day-sum');
    if (td) td.textContent = String(sum);
}

function recalcActualCvTotals() {
    const totals = {};
    ACTUAL_CV_ROUTES.forEach(r => { totals[r.key] = 0; });

    const tbody = document.getElementById('actual-cv-rows');
    if (!tbody) return;

    tbody.querySelectorAll('input[type="number"]').forEach(i => {
        if (i.value === '') return;
        const route = i.dataset.route;
        if (totals.hasOwnProperty(route)) totals[route] += parseInt(i.value, 10) || 0;
    });

    const totalAll = Object.values(totals).reduce((a, b) => a + b, 0);
    const el = document.getElementById('actual-cv-total-all');
    if (el) el.textContent = String(totalAll);
    ACTUAL_CV_ROUTES.forEach(r => {
        const e = document.getElementById('actual-cv-total-' + r.key);
        if (e) e.textContent = String(totals[r.key] || 0);
    });
}

async function saveActualCv(month, userId) {
    const tbody = document.getElementById('actual-cv-rows');
    if (!tbody) return;

    const btn = document.getElementById('btn-actual-cv-save');
    const origText = btn.textContent;
    btn.textContent = '保存中...';
    btn.disabled = true;

    const items = [];
    tbody.querySelectorAll('input[type="number"]').forEach(i => {
        const date = i.dataset.date;
        const route = i.dataset.route;
        const raw = i.value;

        if (raw === '') {
            items.push({ date, route, count: null });
            return;
        }
        let n = parseInt(raw, 10);
        if (isNaN(n) || n < 0) n = 0;
        if (n > 99) n = 99;
        items.push({ date, route, count: n });
    });

    try {
        const res = await fetch(restBase + 'actual-cv', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
            body: JSON.stringify({ user_id: userId, month, items })
        });
        if (!res.ok) throw new Error('Save failed');
        const json = await res.json();
        if (!json.success) throw new Error('Save failed');

        btn.textContent = '✅ 保存完了';
        // 保存成功 → 下書きを破棄
        clearCvDraft(userId, month);
        markClean('btn-actual-cv-save');
        await loadActualCv(month, userId);
        setTimeout(() => { btn.textContent = origText; }, 1500);
    } catch (e) {
        console.error(e);
        btn.textContent = '❌ 保存失敗';
        setTimeout(() => { btn.textContent = origText; }, 2000);
    } finally {
        btn.disabled = false;
    }
}
</script>

<?php get_footer(); ?>
