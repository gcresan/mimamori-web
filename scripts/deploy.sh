#!/bin/bash
# ===========================================================
# deploy.sh — Dev テーマを本番にデプロイする
#
# 配置先: /home/kusanagi/mimamori/scripts/deploy.sh
# 実行者: sudo -u kusanagi（PHP-FPM httpd ユーザーから）
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
SNAPSHOT_DIR="/home/kusanagi/mimamori/snapshots/theme"
LOG_FILE="/home/kusanagi/mimamori/snapshots/deploy.log"
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
rsync -a --delete \
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

# --- 3. 所有権修正 ---
chown -R httpd:kusanagi "$PROD_THEME"

# --- 4. キャッシュクリア ---
# OPcache リセット（PHP-FPM reload）
if [ -f /var/run/php-fpm/php-fpm.pid ]; then
    kill -USR2 "$(cat /var/run/php-fpm/php-fpm.pid)" 2>/dev/null || true
fi

# KUSANAGI fcache/bcache パージ
kusanagi fcache --clear mimamori 2>/dev/null || true
kusanagi bcache --clear mimamori 2>/dev/null || true

# WordPress transient クリア（WP-CLI）
/opt/kusanagi/php-8.3/bin/php /opt/kusanagi/bin/wp transient delete --all \
    --path=/home/kusanagi/mimamori/DocumentRoot 2>/dev/null || true

# --- 5. 古いスナップショット削除（MAX_SNAPSHOTS 超過分） ---
cd "$SNAPSHOT_DIR"
# shellcheck disable=SC2012
ls -1t ./*.zip 2>/dev/null | tail -n +$((MAX_SNAPSHOTS + 1)) | xargs rm -f 2>/dev/null || true

# --- 完了ログ ---
echo "[$TIMESTAMP] Deploy complete. Snapshot: ${TIMESTAMP}.zip" | tee -a "$LOG_FILE"
echo "OK"
