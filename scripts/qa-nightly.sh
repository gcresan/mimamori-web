#!/bin/bash
# ===========================================================
# qa-nightly.sh — QA バッチ夜間自動実行
#
# 配置先: /home/kusanagi/mimamori-dev/scripts/qa-nightly.sh
# 実行者: kusanagi（crontab -u kusanagi -e で登録）
#
# 処理:
#   1. 環境チェック（Dev 環境のみ実行）
#   2. ロック取得（二重実行防止）
#   3. QA バッチ実行（nightly モード: 100問）
#   4. ログ出力
#   5. 古い run データの自動削除（30日超過分）
#
# crontab 例（毎日 03:30 JST に実行）:
#   30 3 * * * /home/kusanagi/mimamori-dev/scripts/qa-nightly.sh >> /home/kusanagi/mimamori-dev/logs/qa-nightly.log 2>&1
# ===========================================================
set -euo pipefail

# --- 固定パス ---
WP_ROOT="/home/kusanagi/mimamori-dev/DocumentRoot"
WP_CLI="/opt/kusanagi/php/bin/php /opt/kusanagi/bin/wp"
WP_CONFIG="${WP_ROOT}/../wp-config.php"
LOCK_FILE="/tmp/mimamori-qa-nightly.lock"
QA_RUNS_DIR="${WP_ROOT}/wp-content/uploads/mimamori/qa_runs"
LOG_DIR="/home/kusanagi/mimamori-dev/logs"
MAX_AGE_DAYS=30

# --- QA 設定 ---
# 対象ユーザーID（GA4/GSC 設定済みユーザー）
QA_USER_ID="${QA_USER_ID:-1}"
# 実行モード: nightly(100問) / quick(5問) / custom
QA_MODE="${QA_MODE:-nightly}"
# スリープ（ms）: API レート制限対策
QA_SLEEP="${QA_SLEEP:-2000}"

# --- ログ関数 ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

# --- 環境チェック ---
# KUSANAGI: wp-config.php は DocumentRoot の親ディレクトリに配置
if [ ! -f "${WP_CONFIG}" ]; then
    log "ERROR: wp-config.php not found at ${WP_CONFIG}"
    exit 1
fi

# Dev 環境チェック（wp-config.php に MIMAMORI_ENV が development であること）
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
    # 古いロックファイルを削除
    rm -f "${LOCK_FILE}"
fi

echo $$ > "${LOCK_FILE}"
trap 'rm -f "${LOCK_FILE}"' EXIT

# --- ログディレクトリ作成 ---
mkdir -p "${LOG_DIR}"

# --- QA バッチ実行 ---
SEED=$(date '+%Y%m%d')

log "=== QA Nightly Start ==="
log "User: ${QA_USER_ID} | Mode: ${QA_MODE} | Seed: ${SEED} | Sleep: ${QA_SLEEP}ms"

${WP_CLI} mimamori qa run \
    --user_id="${QA_USER_ID}" \
    --mode="${QA_MODE}" \
    --seed="${SEED}" \
    --sleep="${QA_SLEEP}" \
    --path="${WP_ROOT}" \
    2>&1

EXIT_CODE=$?

if [ ${EXIT_CODE} -eq 0 ]; then
    log "=== QA Nightly Complete (success) ==="
else
    log "=== QA Nightly Complete (exit code: ${EXIT_CODE}) ==="
fi

# --- 古い run データの自動削除（MAX_AGE_DAYS 日超過分） ---
if [ -d "${QA_RUNS_DIR}" ]; then
    OLD_COUNT=$(find "${QA_RUNS_DIR}" -maxdepth 1 -mindepth 1 -type d -mtime +${MAX_AGE_DAYS} | wc -l)
    if [ "${OLD_COUNT}" -gt 0 ]; then
        log "Cleaning up ${OLD_COUNT} old QA runs (>${MAX_AGE_DAYS} days)..."
        find "${QA_RUNS_DIR}" -maxdepth 1 -mindepth 1 -type d -mtime +${MAX_AGE_DAYS} -exec rm -rf {} +
        log "Cleanup complete."
    fi
fi

exit ${EXIT_CODE}
