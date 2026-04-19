#!/bin/bash
# ===========================================================
# qa-nightly.sh — QA 夜間自動自己改善ループ
#
# 配置先: /home/kusanagi/mimamori-dev/scripts/qa-nightly.sh
# 実行者: kusanagi（crontab -u kusanagi -e で登録）
#
# 処理（完全自動 / human approval なし）:
#   1. 環境チェック（Dev 環境のみ実行）
#   2. ロック取得（二重実行防止）
#   3. qa:run           — 今晩の QA 実行
#   4. qa:improve       — 低スコアケースの改訂案生成
#   5. qa:auto-promote  — 安全条件を満たす改訂案を Prompt Registry に反映
#   6. qa:compare       — 前回 run との差分を保存
#   7. qa:auto-rollback — 悪化していれば 1 世代前に戻す
#   8. 古い run データの自動削除（30日超過分）
#
# crontab 例（毎日 03:30 JST に実行）:
#   30 3 * * * /home/kusanagi/mimamori-dev/scripts/qa-nightly.sh >> /home/kusanagi/mimamori-dev/logs/qa-nightly.log 2>&1
# ===========================================================
set -uo pipefail

# --- 固定パス ---
WP_ROOT="/home/kusanagi/mimamori-dev/DocumentRoot"
WP_CLI="/opt/kusanagi/php/bin/php /opt/kusanagi/bin/wp"
WP_CONFIG="${WP_ROOT}/../wp-config.php"
LOCK_FILE="/tmp/mimamori-qa-nightly.lock"
QA_RUNS_DIR="${WP_ROOT}/wp-content/uploads/mimamori/qa_runs"
LOG_DIR="/home/kusanagi/mimamori-dev/logs"
MAX_AGE_DAYS=30

# --- QA 設定 ---
QA_USER_ID="${QA_USER_ID:-1}"
QA_MODE="${QA_MODE:-nightly}"
QA_SLEEP="${QA_SLEEP:-2000}"
# qa:improve の合格基準（auto promote は固有の最終スコア閾値を持つので緩くてよい）
IMPROVE_PASS_SCORE="${IMPROVE_PASS_SCORE:-95}"
IMPROVE_PASS_MODE="${IMPROVE_PASS_MODE:-no_critical}"
IMPROVE_MAX_REV="${IMPROVE_MAX_REV:-3}"

# --- ログ関数 ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

run_step() {
    local label="$1"; shift
    log "--- ${label} ---"
    if "$@"; then
        log "${label}: OK"
        return 0
    else
        local code=$?
        log "${label}: FAILED (exit=${code})"
        return ${code}
    fi
}

# --- 環境チェック ---
if [ ! -f "${WP_CONFIG}" ]; then
    log "ERROR: wp-config.php not found at ${WP_CONFIG}"
    exit 1
fi

ENV_CHECK=$(grep -c "MIMAMORI_ENV.*development" "${WP_CONFIG}" 2>/dev/null || true)
if [ "${ENV_CHECK}" -eq 0 ]; then
    log "SKIP: Not a development environment. QA nightly runs only on Dev."
    exit 0
fi

# --- ロック取得（二重実行防止） ---
if [ -f "${LOCK_FILE}" ]; then
    LOCK_PID=$(cat "${LOCK_FILE}" 2>/dev/null || echo "")
    if [ -n "${LOCK_PID}" ] && kill -0 "${LOCK_PID}" 2>/dev/null; then
        log "SKIP: Another QA batch is running (PID: ${LOCK_PID})"
        exit 0
    fi
    rm -f "${LOCK_FILE}"
fi

echo $$ > "${LOCK_FILE}"
trap 'rm -f "${LOCK_FILE}"' EXIT

mkdir -p "${LOG_DIR}"

# ============================================================
# 1. QA 本実行
# ============================================================
SEED=$(date '+%Y%m%d')

log "=== QA Nightly Start ==="
log "User: ${QA_USER_ID} | Mode: ${QA_MODE} | Seed: ${SEED} | Sleep: ${QA_SLEEP}ms"

if ! run_step "qa:run" ${WP_CLI} mimamori qa run \
    --user_id="${QA_USER_ID}" \
    --mode="${QA_MODE}" \
    --seed="${SEED}" \
    --sleep="${QA_SLEEP}" \
    --path="${WP_ROOT}"; then
    log "qa:run failed — aborting pipeline."
    exit 1
fi

# 今回の run_id は qa_runs 配下で一番新しい YYYYMMDD_HHMMSS ディレクトリ
CURRENT_RUN=$(ls -1 "${QA_RUNS_DIR}" 2>/dev/null \
    | grep -E '^[0-9]{8}_[0-9]{6}$' \
    | sort \
    | tail -n 1 || true)

if [ -z "${CURRENT_RUN:-}" ]; then
    log "ERROR: could not detect current run_id — aborting downstream steps."
    exit 1
fi
log "Current run_id: ${CURRENT_RUN}"

# ============================================================
# 2. 改訂案生成（低スコアケース）
# ============================================================
run_step "qa:improve" ${WP_CLI} mimamori qa improve \
    --run="${CURRENT_RUN}" \
    --user_id="${QA_USER_ID}" \
    --pass-score="${IMPROVE_PASS_SCORE}" \
    --pass-mode="${IMPROVE_PASS_MODE}" \
    --max-revisions="${IMPROVE_MAX_REV}" \
    --sleep="${QA_SLEEP}" \
    --path="${WP_ROOT}" || log "qa:improve had a non-zero exit; continuing."

# ============================================================
# 3. Auto Promote（安全条件を満たすものだけ）
# ============================================================
run_step "qa:auto-promote" ${WP_CLI} mimamori qa auto-promote \
    --run="${CURRENT_RUN}" \
    --path="${WP_ROOT}" || log "qa:auto-promote had a non-zero exit; continuing."

# ============================================================
# 4. 前回 run との比較
# ============================================================
run_step "qa:compare" ${WP_CLI} mimamori qa compare \
    --current="${CURRENT_RUN}" \
    --path="${WP_ROOT}" || log "qa:compare had a non-zero exit; continuing."

# ============================================================
# 5. Auto Rollback（悪化していれば 1 世代戻す）
# ============================================================
run_step "qa:auto-rollback" ${WP_CLI} mimamori qa auto-rollback \
    --current="${CURRENT_RUN}" \
    --path="${WP_ROOT}" || log "qa:auto-rollback had a non-zero exit; continuing."

# ============================================================
# 6. 古い run データの削除
# ============================================================
if [ -d "${QA_RUNS_DIR}" ]; then
    OLD_COUNT=$(find "${QA_RUNS_DIR}" -maxdepth 1 -mindepth 1 -type d -mtime +${MAX_AGE_DAYS} | wc -l)
    if [ "${OLD_COUNT}" -gt 0 ]; then
        log "Cleaning up ${OLD_COUNT} old QA runs (>${MAX_AGE_DAYS} days)..."
        find "${QA_RUNS_DIR}" -maxdepth 1 -mindepth 1 -type d -mtime +${MAX_AGE_DAYS} -exec rm -rf {} +
        log "Cleanup complete."
    fi
fi

log "=== QA Nightly Complete ==="
exit 0
