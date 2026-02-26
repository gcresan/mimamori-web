#!/bin/bash
# ===========================================================
# snapshot.sh — KUSANAGI snapshot を作成する
#
# 配置先: /home/kusanagi/mimamori-dev/scripts/snapshot.sh
# 実行者: kusanagi（PHP-FPM 実行ユーザー）
#
# DB + ファイル全体のスナップショットを作成する。
# 重大な問題が発生した場合、SSH から完全復元できる。
# ===========================================================
set -euo pipefail

LOG_FILE="/home/kusanagi/mimamori-dev/snapshots/deploy.log"
TIMESTAMP=$(date +"%Y-%m-%d_%H%M%S")
SNAPSHOT_NAME="pre-deploy-${TIMESTAMP}"

# ログディレクトリ確保
mkdir -p "$(dirname "$LOG_FILE")"

echo "[$TIMESTAMP] Creating KUSANAGI snapshot: $SNAPSHOT_NAME ..." | tee -a "$LOG_FILE"

# KUSANAGI snapshot 作成（root 権限が必要なため sudo 経由）
sudo kusanagi snapshot create "$SNAPSHOT_NAME" mimamori 2>&1 | tee -a "$LOG_FILE"

echo "[$TIMESTAMP] KUSANAGI snapshot created: $SNAPSHOT_NAME" | tee -a "$LOG_FILE"
echo "OK"
