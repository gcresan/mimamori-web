#!/bin/bash
# ===========================================================
# deploy.sh — Dev テーマを本番にデプロイする
#
# 配置先: /home/kusanagi/mimamori-dev/scripts/deploy.sh
# 実行者: kusanagi（PHP-FPM 実行ユーザー）
#
# 処理:
#   1. 本番テーマの ZIP スナップショット作成
#   2. rsync で Dev → Prod にファイルコピー
#   3. 所有権修正
#   4. キャッシュクリア（OPcache, KUSANAGI, WP Transients）
#   5. 古いスナップショットの自動削除
# ===========================================================
set -euo pipefail

# --- 固定パス（ハードコード: 環境に合わせて変更不可） ---
DEV_THEME="/home/kusanagi/mimamori-dev/DocumentRoot/wp-content/themes/mimamori"
PROD_THEME="/home/kusanagi/mimamori/DocumentRoot/wp-content/themes/mimamori"
DEV_DOCROOT="/home/kusanagi/mimamori-dev/DocumentRoot"
PROD_DOCROOT="/home/kusanagi/mimamori/DocumentRoot"
DEV_CHATBOT_PLUGIN="/home/kusanagi/mimamori-dev/DocumentRoot/wp-content/plugins/mimamori-chatbot"
PROD_CHATBOT_PLUGIN="/home/kusanagi/mimamori/DocumentRoot/wp-content/plugins/mimamori-chatbot"
SNAPSHOT_DIR="/home/kusanagi/mimamori-dev/snapshots/theme"
LOG_FILE="/home/kusanagi/mimamori-dev/snapshots/deploy.log"
REGISTRY_EXPORT_PATH="/tmp/mimamori-registry-export.json"
WP_CLI_BIN="/opt/kusanagi/php-8.3/bin/php /opt/kusanagi/bin/wp"
MAX_SNAPSHOTS=10

TIMESTAMP=$(date +"%Y-%m-%d_%H%M%S")

# --- 前提チェック ---
if [ ! -d "$DEV_THEME" ]; then
    echo "ERROR: Dev theme not found: $DEV_THEME" | tee -a "$LOG_FILE"
    exit 1
fi
if [ ! -d "$PROD_THEME" ]; then
    echo "ERROR: Prod theme not found: $PROD_THEME" | tee -a "$LOG_FILE"
    exit 1
fi

# スナップショットディレクトリ確保
mkdir -p "$SNAPSHOT_DIR"

# --- 1. テーマ ZIP スナップショット作成 ---
echo "[$TIMESTAMP] Creating theme snapshot..." | tee -a "$LOG_FILE"
cd "$PROD_THEME/.."
zip -rq "${SNAPSHOT_DIR}/${TIMESTAMP}.zip" mimamori \
    -x "mimamori/.git/*" \
    -x "mimamori/node_modules/*" \
    -x "mimamori/sass/*" \
    -x "mimamori/.sass-cache/*"

# --- 2. rsync (Dev → Prod) ---
echo "[$TIMESTAMP] Deploying dev -> prod via rsync..." | tee -a "$LOG_FILE"
rsync -a --delete --no-group --no-owner \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='.env' \
    --exclude='sass' \
    --exclude='.sass-cache' \
    --exclude='.DS_Store' \
    --exclude='config.rb' \
    --exclude='sftp-config.json' \
    --exclude='scripts' \
    "$DEV_THEME/" "$PROD_THEME/"

# --- 2.5. mimamori-chatbot プラグインを Dev → Prod 同期 ---
# Dev に配置されていれば本番にも反映する (未配置なら何もしない)。
# --delete はターゲットサブディレクトリ内のみ作用するため、他プラグインには影響しない。
if [ -d "$DEV_CHATBOT_PLUGIN" ]; then
    echo "[$TIMESTAMP] Deploying mimamori-chatbot plugin..." | tee -a "$LOG_FILE"
    mkdir -p "$PROD_CHATBOT_PLUGIN"
    rsync -a --delete --no-group --no-owner \
        --exclude='.git' \
        --exclude='.DS_Store' \
        --exclude='vendor/.git' \
        "$DEV_CHATBOT_PLUGIN/" "$PROD_CHATBOT_PLUGIN/"
fi

# --- 3. 所有権 ---
# chown 不要: PHP-FPM が kusanagi ユーザーで動作するため rsync 後の所有権は kusanagi:kusanagi

# --- 4. キャッシュクリア ---
# OPcache リセット（本番 opcache.validate_timestamps=0 対策）
# 複数手段を順に試す。①ループバックHTTPでFPMプール内 opcache_reset()（最も確実・root不要）
# ②sudo systemctl reload php-fpm ③php-fpm マスターへ USR2（PIDパス複数候補）。
OPCACHE_CLEARED=0
PROD_HOST="mimamori-web.jp"

# ① ループバックHTTP（FPMプール内で opcache_reset を実行）
RESET_TOKEN=$(${WP_CLI_BIN} eval 'if (function_exists("gcrev_opcache_reset_token")) echo gcrev_opcache_reset_token();' --path="${PROD_DOCROOT}" 2>/dev/null || true)
if [ -n "${RESET_TOKEN}" ]; then
    if curl -ksS --max-time 20 "https://${PROD_HOST}/?gcrev_opcache_reset=${RESET_TOKEN}" 2>>"$LOG_FILE" | grep -q '"opcache_reset":true'; then
        OPCACHE_CLEARED=1
        echo "[$TIMESTAMP] OPcache cleared via loopback HTTP" | tee -a "$LOG_FILE"
    fi
fi

# ② sudo systemctl reload php-fpm（sudoers 設定がある場合）
if [ "$OPCACHE_CLEARED" -eq 0 ]; then
    if sudo -n systemctl reload php-fpm 2>/dev/null; then
        OPCACHE_CLEARED=1
        echo "[$TIMESTAMP] OPcache cleared via systemctl reload php-fpm" | tee -a "$LOG_FILE"
    fi
fi

# ③ php-fpm マスターへ USR2（PIDパス複数候補）
if [ "$OPCACHE_CLEARED" -eq 0 ]; then
    for _pid in /run/php-fpm/php-fpm.pid /var/run/php-fpm/php-fpm.pid /run/php-fpm.pid; do
        if [ -f "$_pid" ] && kill -USR2 "$(cat "$_pid")" 2>/dev/null; then
            OPCACHE_CLEARED=1
            echo "[$TIMESTAMP] OPcache reloaded via USR2 ($_pid)" | tee -a "$LOG_FILE"
            break
        fi
    done
fi

if [ "$OPCACHE_CLEARED" -eq 0 ]; then
    echo "[$TIMESTAMP] WARN: OPcache reset could not be confirmed — run 'systemctl restart php-fpm' manually if changes are not reflected" | tee -a "$LOG_FILE"
fi

# KUSANAGI fcache/bcache パージ
kusanagi fcache --clear mimamori 2>/dev/null || true
kusanagi bcache --clear mimamori 2>/dev/null || true

# WordPress transient クリア（gcrev_* は除外）
# 理由: gcrev_dash_* / gcrev_meo_* / gcrev_source_* 等は GA4/GSC/GBP の prefetch データで、
#       1ユーザー20-30秒かけて Cron が毎朝生成している。デプロイのたびに消すと
#       直後にログインしたユーザーが「読み込み中…」を長時間見ることになる。
# API レスポンス構造を変えた場合は手動で `wp transient delete --all` を実行すること。
PROD_DB_PREFIX=$(${WP_CLI_BIN} db prefix --path="${PROD_DOCROOT}" 2>/dev/null)
${WP_CLI_BIN} db query "DELETE FROM \`${PROD_DB_PREFIX}options\` WHERE (option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_%') AND option_name NOT LIKE '\\_transient\\_gcrev\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_gcrev\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_gcrev\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_timeout\\_gcrev\\_%'" \
    --path="${PROD_DOCROOT}" 2>/dev/null || true

# --- 4.5. Prompt Registry 同期（Dev → Prod） ---
# QA 自動改善ループで Dev に蓄積された registry を Prod にコピーする。
# 失敗してもデプロイ全体を止めたくないので || true でソフト化。
echo "[$TIMESTAMP] Exporting Dev registry..." | tee -a "$LOG_FILE"
if ${WP_CLI_BIN} mimamori qa registry-export \
    --to="${REGISTRY_EXPORT_PATH}" \
    --path="${DEV_DOCROOT}" >> "$LOG_FILE" 2>&1; then

    if [ -s "${REGISTRY_EXPORT_PATH}" ]; then
        echo "[$TIMESTAMP] Importing registry into Prod..." | tee -a "$LOG_FILE"
        ${WP_CLI_BIN} mimamori qa registry-import \
            --from="${REGISTRY_EXPORT_PATH}" \
            --path="${PROD_DOCROOT}" >> "$LOG_FILE" 2>&1 \
            || echo "[$TIMESTAMP] WARN: registry-import failed (continuing)" | tee -a "$LOG_FILE"
    else
        echo "[$TIMESTAMP] Skipping registry import (export empty)" | tee -a "$LOG_FILE"
    fi
    # 一時ファイル削除
    rm -f "${REGISTRY_EXPORT_PATH}" 2>/dev/null || true
else
    echo "[$TIMESTAMP] WARN: registry-export failed (continuing)" | tee -a "$LOG_FILE"
fi

# --- 5. 古いスナップショット削除（MAX_SNAPSHOTS 超過分） ---
cd "$SNAPSHOT_DIR"
# shellcheck disable=SC2012
ls -1t ./*.zip 2>/dev/null | tail -n +$((MAX_SNAPSHOTS + 1)) | xargs rm -f 2>/dev/null || true

# --- 完了ログ ---
echo "[$TIMESTAMP] Deploy complete. Snapshot: ${TIMESTAMP}.zip" | tee -a "$LOG_FILE"
echo "OK"
