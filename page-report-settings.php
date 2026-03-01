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
            <textarea id="input-issue" placeholder="例：ゴール達成数の増加、ゴール達成率の向上"><?php echo esc_textarea($saved_issue); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="input-goal-monthly">今月の目標</label>
                <input type="text" id="input-goal-monthly" placeholder="例：ゴール数を前月比110%に" value="<?php echo esc_attr($saved_goal_monthly); ?>">
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
            <textarea id="input-goal-main" placeholder="例：年間でゴール達成数を200件に到達"><?php echo esc_textarea($saved_goal_main); ?></textarea>
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
});

// ===== フォームの初期値を保存 =====
function saveInitialFormData() {
    initialFormData = getClientInputs();
}

// ===== フォーム変更検知 =====
function attachFormChangeListeners() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        if (input.dataset.gcrevIgnoreUnsaved === '1') return;
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
</script>

<?php get_footer(); ?>
