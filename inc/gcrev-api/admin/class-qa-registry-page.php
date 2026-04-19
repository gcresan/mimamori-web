<?php
// FILE: inc/gcrev-api/admin/class-qa-registry-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_QA_Registry_Page' ) ) { return; }

/**
 * Mimamori_QA_Registry_Page
 *
 * 管理画面「みまもりウェブ > QA Prompt Registry」ページ。
 *
 * Phase 3: Phase 1.5 で実装した自動 QA 改善ループの中身を可視化し、
 * 手動 promote / rollback / 2 run 比較 を GUI で行う。
 *
 * セクション:
 *   A. Active Registry (intent 別 addendum + override)
 *   B. Version History (直近 10 世代 + rollback ボタン)
 *   C. Run スコア推移 (直近 14 run)
 *   D. 2 run 比較ビュー
 *   E. 手動 Promote パネル
 *
 * 全ての書き込み操作は gcrev/v1/qa-registry/* REST 経由。
 *
 * @package Mimamori_Web
 * @since   3.3.0
 */
class Mimamori_QA_Registry_Page {

    private const MENU_SLUG   = 'gcrev-qa-registry';
    private const OUTPUT_BASE = 'mimamori/qa_runs';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
    }

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'みまもりウェブ',
                'みまもりウェブ',
                'manage_options',
                'gcrev-insight',
                '__return_null',
                'dashicons-chart-area',
                30
            );
        }
        add_submenu_page(
            'gcrev-insight',
            'QA Prompt Registry - みまもりウェブ',
            "\xF0\x9F\x93\x9A QA Prompt Registry",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! class_exists( 'Mimamori_QA_Prompt_Registry' ) ) {
            echo '<div class="wrap"><h1>QA Prompt Registry</h1>';
            echo '<div class="notice notice-error"><p>Mimamori_QA_Prompt_Registry クラスが読み込まれていません。OPcache リセットまたはデプロイを確認してください。</p></div>';
            echo '</div>';
            return;
        }

        $registry       = Mimamori_QA_Prompt_Registry::get_registry();
        $history        = Mimamori_QA_Prompt_Registry::get_history( 10 );
        $active_intents = (array) ( $registry['intents'] ?? [] );
        $active_version = (string) ( $registry['active_version'] ?? '' );
        $runs           = $this->load_recent_runs( 14 );
        $canary_users   = Mimamori_QA_Prompt_Registry::get_canary_users();

        $rest_base = rest_url( 'gcrev/v1/qa-registry' );
        $nonce     = wp_create_nonce( 'wp_rest' );

        ?>
        <?php $this->render_inline_styles(); ?>
        <div class="wrap qa-registry-wrap">
            <h1><?php echo esc_html( "\xF0\x9F\x93\x9A QA Prompt Registry" ); ?></h1>
            <p class="qa-registry-subtitle">
                AI チャットの自動改善ループで承認・適用されている補遺プロンプト (addendum) と overrides を可視化・操作します。
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=gcrev-qa-report' ) ); ?>">AI 品質レポートを見る →</a>
            </p>

            <div id="qa-registry-banner" class="qa-registry-banner" style="display:none;"></div>

            <?php $this->render_section_a( $active_version, $active_intents, $history ); ?>
            <?php $this->render_section_b( $history ); ?>
            <?php $this->render_section_c( $runs ); ?>
            <?php $this->render_section_d( $runs ); ?>
            <?php $this->render_section_e( $runs ); ?>
            <?php $this->render_section_f( $canary_users ); ?>
        </div>

        <script>
        window.GcrevQaRegistry = {
            restBase: <?php echo wp_json_encode( $rest_base ); ?>,
            nonce:    <?php echo wp_json_encode( $nonce ); ?>,
            runs:     <?php echo wp_json_encode( $runs, JSON_UNESCAPED_UNICODE ); ?>
        };
        </script>
        <?php $this->render_inline_scripts(); ?>
        <?php
    }

    // =========================================================
    // A. Active Registry
    // =========================================================

    /**
     * @param string $active_version
     * @param array  $intents  intent_name => data
     * @param array  $history
     */
    private function render_section_a( string $active_version, array $intents, array $history ): void {
        ?>
        <h2>Active Registry</h2>
        <div class="qa-registry-meta">
            <span class="qa-registry-version-chip">active_version: <code><?php echo esc_html( $active_version ?: '(none)' ); ?></code></span>
            <span class="qa-registry-count-chip">intents: <?php echo count( $intents ); ?> 本</span>
        </div>
        <?php if ( empty( $intents ) ): ?>
            <div class="notice notice-info inline"><p>まだ intent が 1 本も登録されていません。<code>wp mimamori qa auto-promote</code> か下記「手動 Promote」から登録してください。</p></div>
            <?php return; ?>
        <?php endif; ?>

        <div class="qa-registry-cards">
            <?php foreach ( $intents as $intent_name => $data ):
                $addendum  = (string) ( $data['addendum'] ?? '' );
                $overrides = is_array( $data['overrides'] ?? null ) ? $data['overrides'] : [];
                $source    = is_array( $data['source'] ?? null ) ? $data['source'] : [];
                $is_global = ( $intent_name === Mimamori_QA_Prompt_Registry::GLOBAL_KEY );
                $stage     = is_array( $data['stage'] ?? null ) ? $data['stage'] : [];
                $stage_mode = (string) ( $stage['mode'] ?? 'full' );
                $stage_users = array_map( 'intval', (array) ( $stage['user_ids'] ?? [] ) );
                ?>
                <div class="qa-registry-card <?php echo $is_global ? 'is-global' : ''; ?>" data-intent="<?php echo esc_attr( $intent_name ); ?>">
                    <header>
                        <h3>
                            <?php if ( $is_global ): ?><span class="tag tag-global">_global</span><?php else: ?><code><?php echo esc_html( $intent_name ); ?></code><?php endif; ?>
                            <span class="qa-registry-version-chip-small">v: <?php echo esc_html( (string) ( $data['version'] ?? '-' ) ); ?></span>
                            <?php if ( ! $is_global ): ?>
                                <?php if ( $stage_mode === 'staged' ): ?>
                                    <span class="qa-stage-badge qa-stage-staged" title="ステージング中">🧪 staged (<?php echo count( $stage_users ); ?>名)</span>
                                <?php else: ?>
                                    <span class="qa-stage-badge qa-stage-full" title="全員に適用中">🌐 全員</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </h3>
                        <div class="qa-registry-card-actions">
                            <?php if ( ! $is_global ): ?>
                                <?php if ( $stage_mode === 'staged' ): ?>
                                    <button type="button" class="button" data-action="edit-stage" data-users="<?php echo esc_attr( implode( ',', $stage_users ) ); ?>">ステージング編集</button>
                                    <button type="button" class="button button-primary" data-action="unstage">全員に展開</button>
                                <?php else: ?>
                                    <button type="button" class="button" data-action="edit-stage" data-users="">ステージングに切替</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ( ! empty( $history ) ): ?>
                                <button type="button" class="button" data-action="open-rollback-modal">この intent を rollback</button>
                            <?php endif; ?>
                        </div>
                    </header>

                    <dl class="qa-registry-card-meta">
                        <dt>promoted</dt><dd><?php echo esc_html( (string) ( $data['auto_promoted_at'] ?? '' ) ?: '-' ); ?></dd>
                        <?php if ( ! empty( $source['run_id'] ) ): ?>
                            <dt>source</dt>
                            <dd>
                                <code><?php echo esc_html( (string) $source['run_id'] ); ?></code>
                                / <code><?php echo esc_html( (string) ( $source['case_id'] ?? '' ) ); ?></code>
                                / rev <?php echo (int) ( $source['revision_no'] ?? 0 ); ?>
                            </dd>
                        <?php endif; ?>
                        <?php if ( ! empty( $data['score_impact'] ) ):
                            $si = $data['score_impact']; ?>
                            <dt>score</dt>
                            <dd><?php echo (int) ( $si['before'] ?? 0 ); ?> → <?php echo (int) ( $si['after'] ?? 0 ); ?></dd>
                        <?php endif; ?>
                        <?php if ( ! empty( $overrides ) ): ?>
                            <dt>overrides</dt>
                            <dd>
                                <?php
                                $kv = [];
                                foreach ( $overrides as $k => $v ) {
                                    $kv[] = esc_html( $k . '=' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v ) );
                                }
                                echo implode( ', ', $kv );
                                ?>
                            </dd>
                        <?php endif; ?>
                    </dl>

                    <details class="qa-registry-addendum">
                        <summary>addendum 本文 (<?php echo mb_strlen( $addendum ); ?> 字)</summary>
                        <pre><?php echo esc_html( $addendum ); ?></pre>
                    </details>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // =========================================================
    // B. Version History
    // =========================================================

    private function render_section_b( array $history ): void {
        ?>
        <h2>Version History</h2>
        <?php if ( empty( $history ) ): ?>
            <div class="notice notice-info inline"><p>履歴はまだありません。</p></div>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped qa-registry-history">
            <thead>
                <tr>
                    <th>version</th>
                    <th>retired_at</th>
                    <th>reason</th>
                    <th>intents</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $history as $h ):
                    $ver    = (string) ( $h['version'] ?? '' );
                    $reason = (string) ( $h['retire_reason'] ?? '' );
                    $snap   = is_array( $h['snapshot']['intents'] ?? null ) ? $h['snapshot']['intents'] : [];
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( $ver ); ?></code></td>
                        <td><?php echo esc_html( (string) ( $h['retired_at'] ?? '' ) ); ?></td>
                        <td>
                            <span class="qa-retire-reason qa-retire-<?php echo esc_attr( $reason ); ?>">
                                <?php echo esc_html( $reason ?: '-' ); ?>
                            </span>
                        </td>
                        <td><?php echo count( $snap ); ?> intents</td>
                        <td>
                            <button type="button"
                                    class="button button-secondary"
                                    data-action="rollback-version"
                                    data-version="<?php echo esc_attr( $ver ); ?>">
                                この version に戻す
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // =========================================================
    // C. Run スコア推移
    // =========================================================

    private function render_section_c( array $runs ): void {
        ?>
        <h2>Run スコア推移 <small>（直近 <?php echo count( $runs ); ?> run）</small></h2>
        <?php if ( empty( $runs ) ): ?>
            <div class="notice notice-info inline"><p>まだ run がありません。</p></div>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped qa-registry-runs">
            <thead>
                <tr>
                    <th>run_id</th>
                    <th>started_at</th>
                    <th>mode</th>
                    <th>n</th>
                    <th>avg_score</th>
                    <th>fail</th>
                    <th>prompt_version</th>
                    <th>score bar</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $prev_ver = null;
                foreach ( $runs as $r ):
                    $ver = (string) ( $r['prompt_version'] ?? '' );
                    $ver_changed = ( $prev_ver !== null && $ver !== $prev_ver );
                    $prev_ver = $ver;
                    $avg = (float) ( $r['avg_score'] ?? 0 );
                    $w   = max( 0, min( 100, $avg ) );
                ?>
                <tr class="<?php echo $ver_changed ? 'qa-run-version-change' : ''; ?>">
                    <td><code><?php echo esc_html( (string) $r['run_id'] ); ?></code></td>
                    <td><?php echo esc_html( (string) ( $r['started_at'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $r['mode'] ?? '' ) ); ?></td>
                    <td><?php echo (int) ( $r['n'] ?? 0 ); ?></td>
                    <td><strong><?php echo number_format( $avg, 1 ); ?></strong></td>
                    <td><?php echo (int) ( $r['fail_count'] ?? 0 ); ?></td>
                    <td><code class="qa-run-ver"><?php echo esc_html( $ver ?: '-' ); ?></code></td>
                    <td>
                        <div class="qa-run-bar-wrap">
                            <div class="qa-run-bar" style="width: <?php echo (int) $w; ?>%;"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // =========================================================
    // D. 2 run 比較
    // =========================================================

    private function render_section_d( array $runs ): void {
        $options = array_map( static fn( $r ) => (string) $r['run_id'], $runs );
        ?>
        <h2>2 run 比較</h2>
        <?php if ( count( $options ) < 2 ): ?>
            <div class="notice notice-info inline"><p>比較には最低 2 run 必要です。</p></div>
            <?php return; ?>
        <?php endif; ?>

        <div class="qa-registry-compare-form">
            <label>current:
                <select id="qa-compare-current">
                    <?php foreach ( $options as $id ): ?>
                        <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $id ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>previous:
                <select id="qa-compare-previous">
                    <option value="">（自動検出）</option>
                    <?php foreach ( $options as $id ): ?>
                        <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $id ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="button" class="button button-primary" id="qa-compare-run">比較する</button>
        </div>
        <div id="qa-compare-result" class="qa-registry-compare-result"></div>
        <?php
    }

    // =========================================================
    // E. 手動 Promote
    // =========================================================

    private function render_section_e( array $runs ): void {
        $options = array_map( static fn( $r ) => (string) $r['run_id'], $runs );
        ?>
        <h2>手動 Promote</h2>
        <p class="qa-registry-help">
            QA run で改善案が生成済みのケース (<code>improvements/&lt;case_id&gt;.json</code>) を指定して、
            registry に手動で昇格します。Auto Promoter の条件に引っかかって昇格されなかった候補を、
            レビュー後に明示的に適用したい場合に使います。
        </p>

        <form class="qa-registry-promote-form" id="qa-promote-form" onsubmit="return false;">
            <table class="form-table">
                <tr>
                    <th><label for="qa-promote-run">run_id</label></th>
                    <td>
                        <select id="qa-promote-run">
                            <option value="">— 選択 —</option>
                            <?php foreach ( $options as $id ): ?>
                                <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $id ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="qa-promote-case">case_id</label></th>
                    <td>
                        <input type="text" id="qa-promote-case" class="regular-text" placeholder="例: qa_007" />
                        <p class="description">improvements/&lt;case_id&gt;.json のベース名。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="qa-promote-rev">revision_no</label></th>
                    <td>
                        <input type="number" id="qa-promote-rev" min="0" step="1" class="small-text" value="1" />
                        <p class="description">0 は初回スコア、1 以上が改善案。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="qa-promote-intent">intent</label></th>
                    <td>
                        <input type="text" id="qa-promote-intent" class="regular-text"
                               placeholder="例: site_improvement, reason_analysis, how_to, general..." />
                        <p class="description">_global は手動昇格不可。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="qa-promote-edited">edited_addendum<br><small>(任意)</small></label></th>
                    <td>
                        <textarea id="qa-promote-edited" rows="6" class="large-text code"
                                  placeholder="空欄の場合は revision の prompt_addendum をそのまま採用"></textarea>
                        <p class="description">最大 <?php echo (int) Mimamori_QA_Prompt_Registry::ADDENDUM_MAX_LEN; ?> 字。禁止ワード (system prompt 無視・model= 等) を含むと拒否されます。</p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="qa-promote-submit">🚀 手動 Promote 実行</button>
            </p>
        </form>
        <?php
    }

    // =========================================================
    // F. Canary Users 設定
    // =========================================================

    private function render_section_f( array $canary_users ): void {
        ?>
        <h2>Canary Users（Auto Promoter のデフォルト stage）</h2>
        <p class="qa-registry-help">
            ここに user_id を設定すると、<code>wp mimamori qa auto-promote</code> が自動昇格した intent を
            自動的に staged モード（これらのユーザーにだけ適用）として登録します。
            空欄の場合は現状どおり「全員に即時反映」です。リスクを抑えたい場合は管理者ユーザーだけを登録しておき、
            翌朝 QA ダッシュボードで挙動確認 → 問題なければ「全員に展開」ボタンで本番化、
            という canary 運用が可能です。
        </p>
        <form class="qa-registry-canary-form" id="qa-canary-form" onsubmit="return false;">
            <table class="form-table">
                <tr>
                    <th><label for="qa-canary-users">user_ids</label></th>
                    <td>
                        <input type="text" id="qa-canary-users" class="regular-text"
                               value="<?php echo esc_attr( implode( ',', $canary_users ) ); ?>"
                               placeholder="例: 1,5 （空欄で解除）" />
                        <p class="description">カンマ区切り。空で保存すると canary 解除（full rollout）。</p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" class="button button-primary" id="qa-canary-submit">保存</button>
            </p>
        </form>
        <?php
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function load_recent_runs( int $limit ): array {
        $upload = wp_upload_dir();
        $base   = trailingslashit( $upload['basedir'] ) . self::OUTPUT_BASE;
        if ( ! is_dir( $base ) ) {
            return [];
        }
        $dirs = glob( $base . '/*', GLOB_ONLYDIR );
        if ( ! is_array( $dirs ) ) {
            return [];
        }
        $ids = [];
        foreach ( $dirs as $d ) {
            $id = basename( $d );
            if ( preg_match( '/^\d{8}_\d{6}$/', $id ) ) {
                $ids[] = $id;
            }
        }
        rsort( $ids );
        $ids = array_slice( $ids, 0, $limit );

        $out = [];
        foreach ( $ids as $id ) {
            $meta_path = $base . '/' . $id . '/meta.json';
            if ( ! file_exists( $meta_path ) ) {
                continue;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $raw  = @file_get_contents( $meta_path );
            $meta = $raw === false ? null : json_decode( $raw, true );
            if ( ! is_array( $meta ) ) {
                continue;
            }
            $out[] = [
                'run_id'         => $id,
                'user_id'        => (int) ( $meta['user_id'] ?? 0 ),
                'n'              => (int) ( $meta['n'] ?? 0 ),
                'mode'           => (string) ( $meta['mode'] ?? '' ),
                'avg_score'      => (float) ( $meta['avg_score'] ?? 0 ),
                'fail_count'     => (int) ( $meta['fail_count'] ?? 0 ),
                'started_at'     => (string) ( $meta['started_at'] ?? '' ),
                'prompt_version' => (string) ( $meta['prompt_version'] ?? '' ),
            ];
        }
        return $out;
    }

    // =========================================================
    // CSS / JS
    // =========================================================

    private function render_inline_styles(): void {
        ?>
        <style>
        .qa-registry-wrap h2 { margin-top: 28px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; }
        .qa-registry-subtitle { color: #64748b; margin: 4px 0 16px; }
        .qa-registry-banner { padding: 12px 16px; border-radius: 6px; margin: 12px 0; font-weight: 600; }
        .qa-registry-banner.success { background: #ecfdf5; border: 1px solid #10b981; color: #065f46; }
        .qa-registry-banner.error   { background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; }
        .qa-registry-meta { margin: 8px 0 16px; display: flex; gap: 10px; flex-wrap: wrap; }
        .qa-registry-version-chip,
        .qa-registry-count-chip,
        .qa-registry-version-chip-small {
            display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px;
        }
        .qa-registry-version-chip { background: #dbeafe; color: #1e40af; }
        .qa-registry-count-chip { background: #f1f5f9; color: #334155; }
        .qa-registry-version-chip-small { background: #f1f5f9; color: #475569; margin-left: 8px; font-size: 11px; }

        .qa-registry-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 14px; }
        .qa-registry-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; }
        .qa-registry-card.is-global { border-color: #fbbf24; background: #fffbeb; }
        .qa-registry-card header { display: flex; justify-content: space-between; align-items: baseline; }
        .qa-registry-card header h3 { margin: 0; font-size: 14px; }
        .qa-registry-card .tag-global { background: #fbbf24; color: #78350f; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .qa-registry-card-meta { display: grid; grid-template-columns: 80px 1fr; gap: 4px 12px; margin: 10px 0; font-size: 12px; }
        .qa-registry-card-meta dt { color: #64748b; }
        .qa-registry-card-meta dd { margin: 0; }
        .qa-registry-addendum summary { cursor: pointer; color: #475569; font-size: 12px; }
        .qa-registry-addendum pre {
            background: #0f172a; color: #e2e8f0; padding: 10px 12px; border-radius: 6px;
            font-size: 12px; max-height: 260px; overflow: auto; white-space: pre-wrap;
        }

        .qa-registry-history td code { font-size: 12px; }
        .qa-retire-reason { padding: 2px 8px; border-radius: 4px; font-size: 11px; }
        .qa-retire-auto_promote { background: #dbeafe; color: #1e40af; }
        .qa-retire-auto_rollback { background: #fef2f2; color: #991b1b; }
        .qa-retire-import_from_dev { background: #d1fae5; color: #065f46; }
        .qa-retire-promote { background: #e0e7ff; color: #3730a3; }
        .qa-retire-rollback { background: #fee2e2; color: #991b1b; }

        .qa-registry-runs .qa-run-bar-wrap { background: #f1f5f9; border-radius: 3px; height: 14px; width: 180px; }
        .qa-registry-runs .qa-run-bar { background: linear-gradient(to right, #60a5fa, #10b981); height: 100%; border-radius: 3px; }
        .qa-registry-runs tr.qa-run-version-change td { border-top: 2px solid #fbbf24 !important; }
        .qa-run-ver { font-size: 11px; }

        .qa-registry-compare-form { display: flex; gap: 12px; align-items: end; margin: 10px 0; flex-wrap: wrap; }
        .qa-registry-compare-form label { display: flex; flex-direction: column; font-size: 12px; gap: 4px; }
        .qa-registry-compare-result { margin-top: 12px; }
        .qa-registry-compare-result table { width: 100%; }
        .qa-delta-positive { color: #059669; font-weight: 600; }
        .qa-delta-negative { color: #dc2626; font-weight: 600; }
        .qa-delta-zero { color: #64748b; }
        .qa-registry-help { color: #64748b; font-size: 13px; max-width: 780px; }
        .qa-registry-promote-form .form-table th { width: 160px; }
        .qa-registry-canary-form .form-table th { width: 160px; }

        .qa-stage-badge { margin-left: 8px; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .qa-stage-full   { background: #e0e7ff; color: #3730a3; }
        .qa-stage-staged { background: #fef3c7; color: #92400e; }
        .qa-registry-card-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        </style>
        <?php
    }

    private function render_inline_scripts(): void {
        ?>
        <script>
        (function(){
            if ( ! window.GcrevQaRegistry ) { return; }
            var base  = window.GcrevQaRegistry.restBase;
            var nonce = window.GcrevQaRegistry.nonce;

            function showBanner(kind, msg) {
                var el = document.getElementById('qa-registry-banner');
                if (!el) return;
                el.className = 'qa-registry-banner ' + kind;
                el.style.display = 'block';
                el.textContent = msg;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function callApi(method, path, body) {
                var opts = {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce
                    },
                    credentials: 'same-origin'
                };
                if (body) {
                    opts.body = JSON.stringify(body);
                }
                return fetch(base + path, opts).then(function(r) {
                    return r.json().then(function(j){ return { status: r.status, body: j }; });
                });
            }

            // ---- Rollback buttons ----
            document.querySelectorAll('[data-action="rollback-version"]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var version = btn.getAttribute('data-version');
                    if (!version) return;
                    if (!confirm('registry を ' + version + ' に戻します。現在の active は history に退避されます。よろしいですか？')) {
                        return;
                    }
                    btn.disabled = true;
                    callApi('POST', '/rollback', { version: version }).then(function(res){
                        if (res.status === 200 && res.body.success) {
                            showBanner('success', 'Rolled back to ' + version + '. 画面を再読み込みします...');
                            setTimeout(function(){ window.location.reload(); }, 1200);
                        } else {
                            showBanner('error', 'Rollback failed: ' + (res.body.message || 'unknown'));
                            btn.disabled = false;
                        }
                    }).catch(function(e){
                        showBanner('error', 'Rollback error: ' + e.message);
                        btn.disabled = false;
                    });
                });
            });

            // ---- Compare ----
            var cmpBtn = document.getElementById('qa-compare-run');
            var cmpOut = document.getElementById('qa-compare-result');
            if (cmpBtn && cmpOut) {
                cmpBtn.addEventListener('click', function(){
                    var current  = document.getElementById('qa-compare-current').value;
                    var previous = document.getElementById('qa-compare-previous').value;
                    cmpOut.innerHTML = '<em>読み込み中...</em>';
                    var qs = '?current=' + encodeURIComponent(current) + (previous ? '&previous=' + encodeURIComponent(previous) : '');
                    callApi('GET', '/compare' + qs, null).then(function(res){
                        if (res.status !== 200 || !res.body.success) {
                            cmpOut.innerHTML = '<div class="notice notice-error inline"><p>' + (res.body.message || '比較失敗') + '</p></div>';
                            return;
                        }
                        if (!res.body.diff) {
                            cmpOut.innerHTML = '<div class="notice notice-info inline"><p>' + (res.body.message || '比較対象なし') + '</p></div>';
                            return;
                        }
                        cmpOut.innerHTML = renderDiff(res.body);
                    }).catch(function(e){
                        cmpOut.innerHTML = '<div class="notice notice-error inline"><p>' + e.message + '</p></div>';
                    });
                });
            }

            function renderDiff(payload) {
                var d = payload.diff;
                var meta = d.meta || {};
                var deltaCls = function(n) {
                    if (n == null) return 'qa-delta-zero';
                    if (n > 0) return 'qa-delta-positive';
                    if (n < 0) return 'qa-delta-negative';
                    return 'qa-delta-zero';
                };
                var signed = function(n) {
                    if (n == null) return '-';
                    return (n > 0 ? '+' : '') + Number(n).toFixed(2);
                };
                var html = '';
                html += '<h3>' + payload.current + ' vs ' + payload.previous + '</h3>';
                html += '<table class="widefat striped" style="max-width:640px;"><tbody>';
                html += '<tr><th>avg score</th><td>' + (d.avg_current||0).toFixed(1) + ' vs ' + (d.avg_previous||0).toFixed(1) + '</td><td class="' + deltaCls(d.avg_score_delta) + '">' + signed(d.avg_score_delta) + '</td></tr>';
                html += '<tr><th>fail 件数</th><td>' + (meta.current_fails||0) + ' vs ' + (meta.previous_fails||0) + '</td><td class="' + deltaCls(d.fail_count_delta) + '">' + signed(d.fail_count_delta) + '</td></tr>';
                html += '<tr><th>hallucination</th><td>-</td><td class="' + deltaCls(d.hallucination_delta) + '">' + signed(d.hallucination_delta) + '</td></tr>';
                html += '</tbody></table>';

                // intent 別
                if (d.by_intent && Object.keys(d.by_intent).length) {
                    html += '<h4>intent 別</h4><table class="widefat striped" style="max-width:720px;"><thead><tr><th>intent</th><th>current</th><th>previous</th><th>Δ</th></tr></thead><tbody>';
                    Object.keys(d.by_intent).sort().forEach(function(k){
                        var row = d.by_intent[k];
                        html += '<tr><td><code>' + escapeHtml(k) + '</code></td><td>' + (row.current_avg != null ? row.current_avg.toFixed(1) : '-') + '</td><td>' + (row.previous_avg != null ? row.previous_avg.toFixed(1) : '-') + '</td><td class="' + deltaCls(row.delta) + '">' + signed(row.delta) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                return html;
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]; });
            }

            // ---- Stage / Unstage ----
            document.querySelectorAll('[data-action="edit-stage"]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var card = btn.closest('.qa-registry-card');
                    var intent = card ? card.getAttribute('data-intent') : '';
                    if (!intent) return;
                    var current = btn.getAttribute('data-users') || '';
                    var input = prompt(
                        'intent "' + intent + '" に適用する user_id をカンマ区切りで入力してください。\n' +
                        '空で OK するとキャンセル（何も変更しません）。\n' +
                        '全員展開に戻す場合は "全員に展開" ボタンを使ってください。',
                        current
                    );
                    if (input === null) return;
                    var trimmed = input.trim();
                    if (trimmed === '') {
                        showBanner('error', 'user_ids が空のためキャンセルしました。');
                        return;
                    }
                    var userIds = trimmed.split(',').map(function(s){ return parseInt(s.trim(), 10); }).filter(function(n){ return !isNaN(n) && n > 0; });
                    if (userIds.length === 0) {
                        showBanner('error', '有効な user_id がありません。');
                        return;
                    }
                    btn.disabled = true;
                    callApi('POST', '/stage', { intent: intent, user_ids: userIds }).then(function(res){
                        if (res.status === 200 && res.body.success) {
                            showBanner('success', intent + ' を staged に変更しました (users: ' + userIds.join(',') + '). 再読み込みします...');
                            setTimeout(function(){ window.location.reload(); }, 1200);
                        } else {
                            showBanner('error', 'Stage failed: ' + (res.body.message || 'unknown'));
                            btn.disabled = false;
                        }
                    }).catch(function(e){
                        showBanner('error', 'Stage error: ' + e.message);
                        btn.disabled = false;
                    });
                });
            });

            document.querySelectorAll('[data-action="unstage"]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var card = btn.closest('.qa-registry-card');
                    var intent = card ? card.getAttribute('data-intent') : '';
                    if (!intent) return;
                    if (!confirm('intent "' + intent + '" を全ユーザーに展開します。よろしいですか？')) {
                        return;
                    }
                    btn.disabled = true;
                    callApi('POST', '/unstage', { intent: intent }).then(function(res){
                        if (res.status === 200 && res.body.success) {
                            showBanner('success', intent + ' を全員に展開しました。再読み込みします...');
                            setTimeout(function(){ window.location.reload(); }, 1200);
                        } else {
                            showBanner('error', 'Unstage failed: ' + (res.body.message || 'unknown'));
                            btn.disabled = false;
                        }
                    }).catch(function(e){
                        showBanner('error', 'Unstage error: ' + e.message);
                        btn.disabled = false;
                    });
                });
            });

            // ---- Canary form ----
            var canaryBtn = document.getElementById('qa-canary-submit');
            var canaryInput = document.getElementById('qa-canary-users');
            if (canaryBtn && canaryInput) {
                canaryBtn.addEventListener('click', function(){
                    var raw = canaryInput.value || '';
                    var ids = raw.split(',').map(function(s){ return parseInt(s.trim(), 10); }).filter(function(n){ return !isNaN(n) && n > 0; });
                    canaryBtn.disabled = true;
                    callApi('POST', '/canary', { user_ids: ids }).then(function(res){
                        if (res.status === 200 && res.body.success) {
                            var msg = res.body.user_ids && res.body.user_ids.length
                                ? 'Canary ユーザーを ' + res.body.user_ids.join(',') + ' に設定しました。'
                                : 'Canary 解除しました（Auto Promoter は全員に即時反映）。';
                            showBanner('success', msg);
                        } else {
                            showBanner('error', 'Canary save failed: ' + (res.body.message || 'unknown'));
                        }
                        canaryBtn.disabled = false;
                    }).catch(function(e){
                        showBanner('error', 'Canary error: ' + e.message);
                        canaryBtn.disabled = false;
                    });
                });
            }

            // ---- Promote ----
            var prBtn = document.getElementById('qa-promote-submit');
            if (prBtn) {
                prBtn.addEventListener('click', function(){
                    var payload = {
                        run_id:      document.getElementById('qa-promote-run').value,
                        case_id:     document.getElementById('qa-promote-case').value.trim(),
                        revision_no: parseInt(document.getElementById('qa-promote-rev').value, 10) || 0,
                        intent:      document.getElementById('qa-promote-intent').value.trim(),
                    };
                    var edited = document.getElementById('qa-promote-edited').value;
                    if (edited && edited.trim() !== '') {
                        payload.edited_addendum = edited;
                    }
                    if (!payload.run_id || !payload.case_id || !payload.intent) {
                        showBanner('error', 'run_id / case_id / intent は必須です。');
                        return;
                    }
                    if (payload.intent === '_global') {
                        showBanner('error', '_global は手動昇格できません。');
                        return;
                    }
                    if (!confirm('registry に ' + payload.intent + ' を昇格します。現在の active は history に退避されます。続行しますか？')) {
                        return;
                    }
                    prBtn.disabled = true;
                    callApi('POST', '/promote', payload).then(function(res){
                        if (res.status === 200 && res.body.success) {
                            showBanner('success', 'Promoted ' + res.body.intent + ' → ' + res.body.new_version + '. 画面を再読み込みします...');
                            setTimeout(function(){ window.location.reload(); }, 1200);
                        } else {
                            showBanner('error', 'Promote failed: ' + (res.body.message || 'unknown'));
                            prBtn.disabled = false;
                        }
                    }).catch(function(e){
                        showBanner('error', 'Promote error: ' + e.message);
                        prBtn.disabled = false;
                    });
                });
            }
        })();
        </script>
        <?php
    }
}
