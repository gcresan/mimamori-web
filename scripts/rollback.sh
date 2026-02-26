#!/bin/bash
# ===========================================================
# rollback.sh — 本番テーマをスナップショットからロールバック
#
# 配置先: /home/kusanagi/mimamori/scripts/rollback.sh
# 実行者: sudo -u kusanagi（PHP-FPM httpd ユーザーから）
#
# 使い方:
#   rollback.sh theme <filename.zip>  — テーマ ZIP から復元（補助）
#   rollback.sh kusanagi              — KUSANAGI snapshot 案内
# ===========================================================
set -euo pipefail

# --- 固定パス ---
PROD_THEME="/home/kusanagi/mimamori/DocumentRoot/wp-content/themes/mimamori"
SNAPSHOT_DIR="/home/kusanagi/mimamori/snapshots/theme"
LOG_FILE="/home/kusanagi/mimamori/snapshots/deploy.log"

TIMESTAMP=$(date +"%Y-%m-%d_%H%M%S")

# --- 引数: ロールバック方式 ---
MODE="${1:-}"
SNAPSHOT_FILE="${2:-}"

if [ -z "$MODE" ]; then
    echo "ERROR: usage: rollback.sh <theme|kusanagi> [snapshot_file]"
    exit 1
fi

case "$MODE" in
    theme)
        # --------------------------------------------------
        # テーマ ZIP からロールバック（補助・即時復元）
        # --------------------------------------------------
        if [ -z "$SNAPSHOT_FILE" ]; then
            echo "ERROR: snapshot filename required for theme rollback"
            exit 1
        fi

        # ファイル名サニタイズ（英数字・ハイフン・アンダースコア・ドットのみ）
        if [[ ! "$SNAPSHOT_FILE" =~ ^[a-zA-Z0-9._-]+\.zip$ ]]; then
            echo "ERROR: invalid snapshot filename: $SNAPSHOT_FILE"
            exit 1
        fi

        SNAPSHOT_PATH="${SNAPSHOT_DIR}/${SNAPSHOT_FILE}"
        if [ ! -f "$SNAPSHOT_PATH" ]; then
            echo "ERROR: Snapshot not found: $SNAPSHOT_PATH" | tee -a "$LOG_FILE"
            exit 1
        fi

        echo "[$TIMESTAMP] Rolling back from theme snapshot: $SNAPSHOT_FILE" | tee -a "$LOG_FILE"

        # 現在のテーマを退避（ロールバック前のバックアップ）
        BACKUP_NAME="pre-rollback_${TIMESTAMP}.zip"
        cd "$PROD_THEME/.."
        zip -rq "${SNAPSHOT_DIR}/${BACKUP_NAME}" mimamori \
            -x "mimamori/.git/*" \
            -x "mimamori/node_modules/*" \
            -x "mimamori/sass/*" \
            -x "mimamori/.sass-cache/*"

        # テーマディレクトリを削除して ZIP から展開
        rm -rf "$PROD_THEME"
        cd "$PROD_THEME/.."
        unzip -qo "$SNAPSHOT_PATH"

        # 所有権修正
        chown -R httpd:kusanagi "$PROD_THEME"

        echo "[$TIMESTAMP] Theme rollback complete. Pre-rollback backup: ${BACKUP_NAME}" | tee -a "$LOG_FILE"
        ;;

    kusanagi)
        # --------------------------------------------------
        # KUSANAGI snapshot ロールバック（本格・手動推奨）
        # --------------------------------------------------
        echo "[$TIMESTAMP] KUSANAGI snapshot rollback requested (manual operation)" | tee -a "$LOG_FILE"
        echo ""
        echo "KUSANAGI snapshot からの復元は影響範囲が大きいため、SSH で手動実行してください。"
        echo ""
        echo "手順:"
        echo "  1. スナップショット一覧: kusanagi snapshot list mimamori"
        echo "  2. 復元: kusanagi snapshot restore <snapshot-name> mimamori"
        echo ""
        echo "KUSANAGI_SNAPSHOT_MODE"
        exit 0
        ;;

    *)
        echo "ERROR: Unknown mode: $MODE (use 'theme' or 'kusanagi')"
        exit 1
        ;;
esac

# --- キャッシュクリア ---
if [ -f /var/run/php-fpm/php-fpm.pid ]; then
    kill -USR2 "$(cat /var/run/php-fpm/php-fpm.pid)" 2>/dev/null || true
fi
kusanagi fcache --clear mimamori 2>/dev/null || true
kusanagi bcache --clear mimamori 2>/dev/null || true
/opt/kusanagi/php-8.3/bin/php /opt/kusanagi/bin/wp transient delete --all \
    --path=/home/kusanagi/mimamori/DocumentRoot 2>/dev/null || true

echo "[$TIMESTAMP] Rollback complete ($MODE: $SNAPSHOT_FILE)" | tee -a "$LOG_FILE"
echo "OK"
