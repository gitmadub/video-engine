#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  install_media_pipeline_service.sh install [--repo-path <path>] [--php-bin <path>] [--interval <seconds>]
  install_media_pipeline_service.sh run-once [--repo-path <path>] [--php-bin <path>]
USAGE
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_PATH="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="$(command -v php || true)"
INTERVAL_SECONDS="10"
COMMAND="${1:-}"

if [[ -z "$COMMAND" ]]; then
  usage
  exit 1
fi

shift || true

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo-path)
      REPO_PATH="${2:-}"
      shift 2
      ;;
    --php-bin)
      PHP_BIN="${2:-}"
      shift 2
      ;;
    --interval)
      INTERVAL_SECONDS="${2:-10}"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$PHP_BIN" ]]; then
  echo "Unable to locate php binary." >&2
  exit 1
fi

run_once() {
  (
    cd "$REPO_PATH"
    "$PHP_BIN" scripts/process_remote_upload_queue.php
    "$PHP_BIN" scripts/process_video_queue.php
  )
}

if [[ "$COMMAND" == "run-once" ]]; then
  run_once
  exit 0
fi

if [[ "$COMMAND" != "install" ]]; then
  echo "Unknown command: $COMMAND" >&2
  usage
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "systemctl is required to install the media pipeline timer." >&2
  exit 1
fi

cat >/etc/systemd/system/ve-media-pipeline.service <<SERVICE
[Unit]
Description=Run Video Engine media pipeline queues
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=$REPO_PATH
ExecStart=/usr/bin/env bash -lc 'cd "$REPO_PATH" && "$PHP_BIN" scripts/process_remote_upload_queue.php && "$PHP_BIN" scripts/process_video_queue.php'
SERVICE

cat >/etc/systemd/system/ve-media-pipeline.timer <<TIMER
[Unit]
Description=Run Video Engine media pipeline every ${INTERVAL_SECONDS} seconds

[Timer]
OnBootSec=15s
OnUnitActiveSec=${INTERVAL_SECONDS}s
AccuracySec=1s
Unit=ve-media-pipeline.service

[Install]
WantedBy=timers.target
TIMER

systemctl daemon-reload
systemctl enable --now ve-media-pipeline.timer
systemctl restart ve-media-pipeline.timer
systemctl start ve-media-pipeline.service

echo "Installed ve-media-pipeline.timer for $REPO_PATH"
