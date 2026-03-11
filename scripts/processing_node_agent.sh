#!/usr/bin/env bash
set -euo pipefail

COMMAND="${1:-}"
if [ $# -gt 0 ]; then
  shift
fi

INSTALL_PATH="/opt/video-engine-processing"
DOMAIN=""
IP_ADDRESS=""
AGENT_VERSION="1.1.0"
WORKDIR=""
SOURCE=""
KEYFILE=""
VF="scale=1280:-2"
PRESET="medium"
CRF="24"
MAXRATE="2500k"
BUFSIZE="5000k"
AUDIO_BITRATE="128k"
SEGMENT_SECONDS="6"
THREADS="2"
LIMIT="48"
RECORD="0"

while [ $# -gt 0 ]; do
  case "$1" in
    --install-path)
      INSTALL_PATH="${2:-$INSTALL_PATH}"
      shift 2
      ;;
    --domain)
      DOMAIN="${2:-}"
      shift 2
      ;;
    --ip)
      IP_ADDRESS="${2:-}"
      shift 2
      ;;
    --agent-version)
      AGENT_VERSION="${2:-$AGENT_VERSION}"
      shift 2
      ;;
    --workdir)
      WORKDIR="${2:-}"
      shift 2
      ;;
    --source)
      SOURCE="${2:-}"
      shift 2
      ;;
    --key)
      KEYFILE="${2:-}"
      shift 2
      ;;
    --vf)
      VF="${2:-$VF}"
      shift 2
      ;;
    --preset)
      PRESET="${2:-$PRESET}"
      shift 2
      ;;
    --crf)
      CRF="${2:-$CRF}"
      shift 2
      ;;
    --maxrate)
      MAXRATE="${2:-$MAXRATE}"
      shift 2
      ;;
    --bufsize)
      BUFSIZE="${2:-$BUFSIZE}"
      shift 2
      ;;
    --audio-bitrate)
      AUDIO_BITRATE="${2:-$AUDIO_BITRATE}"
      shift 2
      ;;
    --segment-seconds)
      SEGMENT_SECONDS="${2:-$SEGMENT_SECONDS}"
      shift 2
      ;;
    --threads)
      THREADS="${2:-$THREADS}"
      shift 2
      ;;
    --limit)
      LIMIT="${2:-$LIMIT}"
      shift 2
      ;;
    --record)
      RECORD="1"
      shift
      ;;
    *)
      echo "Unknown flag: $1" >&2
      exit 1
      ;;
  esac
done

BIN_DIR="$INSTALL_PATH/bin"
JOBS_DIR="$INSTALL_PATH/jobs"
RUNTIME_DIR="$INSTALL_PATH/runtime"
CURRENT_FILE="$RUNTIME_DIR/telemetry-current.json"
HISTORY_FILE="$RUNTIME_DIR/telemetry-history.jsonl"
STATE_FILE="$RUNTIME_DIR/telemetry-state.json"
META_FILE="$RUNTIME_DIR/node-meta.env"
SELF_TARGET="$BIN_DIR/ve-processing-agent.sh"

ensure_layout() {
  mkdir -p "$BIN_DIR" "$JOBS_DIR" "$RUNTIME_DIR"
}

write_meta() {
  cat >"$META_FILE" <<EOF
DOMAIN=${DOMAIN}
IP_ADDRESS=${IP_ADDRESS}
AGENT_VERSION=${AGENT_VERSION}
INSTALL_PATH=${INSTALL_PATH}
EOF
}

read_meta() {
  if [ -f "$META_FILE" ]; then
    # shellcheck disable=SC1090
    . "$META_FILE"
  fi
}

install_packages() {
  export DEBIAN_FRONTEND=noninteractive
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -y
    apt-get install -y ffmpeg python3 procps ca-certificates tar gzip coreutils util-linux
  fi
}

install_systemd_timer() {
  if ! command -v systemctl >/dev/null 2>&1; then
    return
  fi

  cat >/etc/systemd/system/ve-processing-telemetry.service <<EOF
[Unit]
Description=Video Engine processing telemetry sample
After=network-online.target

[Service]
Type=oneshot
ExecStart=${SELF_TARGET} snapshot --record --limit 120 --install-path ${INSTALL_PATH}
EOF

  cat >/etc/systemd/system/ve-processing-telemetry.timer <<EOF
[Unit]
Description=Video Engine processing telemetry timer

[Timer]
OnBootSec=30s
OnUnitActiveSec=10s
AccuracySec=1s
Unit=ve-processing-telemetry.service

[Install]
WantedBy=timers.target
EOF

  systemctl daemon-reload
  systemctl enable --now ve-processing-telemetry.timer
}

record_sample_json() {
  local sample_json="$1"
  printf '%s\n' "$sample_json" >"$CURRENT_FILE"
  printf '%s\n' "$sample_json" >>"$HISTORY_FILE"
  python3 - "$HISTORY_FILE" <<'PY'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
lines = path.read_text(encoding="utf-8").splitlines()
path.write_text("\n".join(lines[-512:]) + ("\n" if lines else ""), encoding="utf-8")
PY
}

generate_sample_json() {
  read_meta
  python3 - "$INSTALL_PATH" "$STATE_FILE" "$DOMAIN" "$IP_ADDRESS" "$AGENT_VERSION" <<'PY'
import json
import os
import pathlib
import shutil
import socket
import subprocess
import sys
import time

install_path = pathlib.Path(sys.argv[1])
state_path = pathlib.Path(sys.argv[2])
domain = sys.argv[3]
ip_address = sys.argv[4]
agent_version = sys.argv[5]

now = int(time.time())
captured_at = time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(now))

def read_file(path: str) -> str:
    try:
        return pathlib.Path(path).read_text(encoding="utf-8")
    except Exception:
        return ""

def network_totals():
    rx_total = 0
    tx_total = 0
    text = read_file("/proc/net/dev")
    for line in text.splitlines()[2:]:
        if ":" not in line:
            continue
        iface, values = line.split(":", 1)
        iface = iface.strip()
        if iface == "lo":
            continue
        columns = values.split()
        if len(columns) >= 9:
            rx_total += int(columns[0])
            tx_total += int(columns[8])
    return rx_total, tx_total

def memory_snapshot():
    values = {}
    for line in read_file("/proc/meminfo").splitlines():
        if ":" not in line:
            continue
        key, value = line.split(":", 1)
        parts = value.strip().split()
        if parts:
            values[key] = int(parts[0]) * 1024
    total = int(values.get("MemTotal", 0))
    available = int(values.get("MemAvailable", values.get("MemFree", 0)))
    used = max(0, total - available)
    percent = round((used / total) * 100, 1) if total > 0 else 0
    return total, used, available, percent

def load_snapshot():
    try:
        load = os.getloadavg()
        return round(load[0], 2), round(load[1], 2), round(load[2], 2)
    except Exception:
        return 0.0, 0.0, 0.0

def uptime_seconds():
    raw = read_file("/proc/uptime").strip().split()
    try:
        return int(float(raw[0]))
    except Exception:
        return 0

def ffmpeg_version():
    try:
        output = subprocess.check_output(["ffmpeg", "-version"], stderr=subprocess.STDOUT, text=True, timeout=8)
        return output.splitlines()[0].strip()
    except Exception:
        return ""

def php_version():
    try:
        output = subprocess.check_output(["php", "-v"], stderr=subprocess.STDOUT, text=True, timeout=8)
        return output.splitlines()[0].strip()
    except Exception:
        return ""

cpu_cores = os.cpu_count() or 0
load_1m, load_5m, load_15m = load_snapshot()
cpu_percent = round((load_1m / cpu_cores) * 100, 1) if cpu_cores > 0 else 0.0
mem_total, mem_used, mem_available, mem_percent = memory_snapshot()
usage = shutil.disk_usage(str(install_path))
disk_total = int(usage.total)
disk_used = int(usage.used)
disk_free = int(usage.free)
disk_percent = round((disk_used / disk_total) * 100, 1) if disk_total > 0 else 0.0
rx_total, tx_total = network_totals()
rx_rate = 0
tx_rate = 0

if state_path.exists():
    try:
        state = json.loads(state_path.read_text(encoding="utf-8"))
        elapsed = max(1, now - int(state.get("captured_at_ts", now)))
        rx_rate = max(0, int((rx_total - int(state.get("rx_total", rx_total))) / elapsed))
        tx_rate = max(0, int((tx_total - int(state.get("tx_total", tx_total))) / elapsed))
    except Exception:
        rx_rate = 0
        tx_rate = 0

state_path.write_text(json.dumps({
    "captured_at_ts": now,
    "rx_total": rx_total,
    "tx_total": tx_total,
}), encoding="utf-8")

jobs_dir = install_path / "jobs"
processing_jobs = sum(1 for _ in jobs_dir.glob("*/job.running"))

sample = {
    "captured_at": captured_at,
    "hostname": socket.gethostname(),
    "domain": domain,
    "ip_address": ip_address,
    "agent_version": agent_version,
    "ffmpeg_version": ffmpeg_version(),
    "php_version": php_version(),
    "cpu_cores": cpu_cores,
    "cpu_percent": cpu_percent,
    "load_1m": load_1m,
    "load_5m": load_5m,
    "load_15m": load_15m,
    "memory_total_bytes": mem_total,
    "memory_used_bytes": mem_used,
    "memory_available_bytes": mem_available,
    "memory_percent": mem_percent,
    "disk_total_bytes": disk_total,
    "disk_used_bytes": disk_used,
    "disk_free_bytes": disk_free,
    "disk_percent": disk_percent,
    "network_rx_bytes": rx_total,
    "network_tx_bytes": tx_total,
    "network_rx_rate_bytes": rx_rate,
    "network_tx_rate_bytes": tx_rate,
    "network_total_rate_bytes": rx_rate + tx_rate,
    "uptime_seconds": uptime_seconds(),
    "processing_jobs": processing_jobs,
    "live_player_connections": 0,
}

print(json.dumps(sample, separators=(",", ":")))
PY
}

snapshot_payload() {
  local sample_json
  sample_json="$(generate_sample_json)"
  if [ "$RECORD" = "1" ]; then
    record_sample_json "$sample_json"
  fi

  python3 - "$CURRENT_FILE" "$HISTORY_FILE" "$LIMIT" "$AGENT_VERSION" "$sample_json" <<'PY'
import json
import pathlib
import sys

current_file = pathlib.Path(sys.argv[1])
history_file = pathlib.Path(sys.argv[2])
limit = max(1, int(sys.argv[3]))
agent_version = sys.argv[4]
fallback_json = sys.argv[5]

history = []
if history_file.exists():
    for line in history_file.read_text(encoding="utf-8").splitlines()[-limit:]:
        line = line.strip()
        if not line:
            continue
        try:
            history.append(json.loads(line))
        except Exception:
            continue

try:
    current = json.loads(current_file.read_text(encoding="utf-8")) if current_file.exists() else json.loads(fallback_json)
except Exception:
    current = json.loads(fallback_json)

if not history:
    history = [current]

print(json.dumps({
    "ok": True,
    "agent_version": agent_version,
    "current": current,
    "history": history[-limit:],
}, separators=(",", ":")))
PY
}

process_video() {
  if [ -z "$WORKDIR" ] || [ -z "$SOURCE" ] || [ -z "$KEYFILE" ]; then
    echo "Missing workdir, source, or key path." >&2
    exit 1
  fi

  ensure_layout
  mkdir -p "$WORKDIR"
  cp -f "$KEYFILE" "$WORKDIR/stream.key"
  printf 'stream.key\n%s\n' "$WORKDIR/stream.key" >"$WORKDIR/stream.keyinfo"
  : >"$WORKDIR/job.running"

  cat >"$WORKDIR/job.meta" <<EOF
status=running
source=${SOURCE}
captured_at=$(date -u +"%Y-%m-%d %H:%M:%S")
EOF

  trap 'rm -f "$WORKDIR/job.running" "$WORKDIR/stream.keyinfo"' EXIT

  ffmpeg -y \
    -hide_banner \
    -loglevel error \
    -i "$SOURCE" \
    -map 0:v:0 \
    -map 0:a:0? \
    -sn \
    -dn \
    -threads "$THREADS" \
    -vf "$VF" \
    -c:v libx264 \
    -preset "$PRESET" \
    -crf "$CRF" \
    -profile:v high \
    -pix_fmt yuv420p \
    -maxrate "$MAXRATE" \
    -bufsize "$BUFSIZE" \
    -g 48 \
    -keyint_min 48 \
    -sc_threshold 0 \
    -c:a aac \
    -ac 2 \
    -ar 48000 \
    -b:a "$AUDIO_BITRATE" \
    -f hls \
    -hls_time "$SEGMENT_SECONDS" \
    -hls_list_size 0 \
    -hls_playlist_type vod \
    -hls_segment_filename "$WORKDIR/part_%05d.bin" \
    -hls_key_info_file "$WORKDIR/stream.keyinfo" \
    "$WORKDIR/stream.m3u8"

  tar -czf "$WORKDIR/artifacts.tar.gz" -C "$WORKDIR" stream.m3u8 stream.key part_*.bin
  rm -f "$WORKDIR/job.running"
  printf 'status=complete\nfinished_at=%s\n' "$(date -u +"%Y-%m-%d %H:%M:%S")" >"$WORKDIR/job.meta"
  printf '{"status":"ok","archive":"%s"}\n' "$WORKDIR/artifacts.tar.gz"
}

cleanup_workdir() {
  if [ -z "$WORKDIR" ]; then
    echo "Missing workdir." >&2
    exit 1
  fi

  rm -rf "$WORKDIR"
  printf '{"status":"ok"}\n'
}

install_agent() {
  install_packages
  ensure_layout
  install -m 755 "$0" "$SELF_TARGET"
  write_meta
  install_systemd_timer
  RECORD="1"
  snapshot_payload >/dev/null
  printf '{"status":"ok","install_path":"%s","agent_version":"%s"}\n' "$INSTALL_PATH" "$AGENT_VERSION"
}

case "$COMMAND" in
  install)
    install_agent
    ;;
  telemetry|snapshot)
    ensure_layout
    snapshot_payload
    ;;
  process)
    process_video
    ;;
  cleanup)
    cleanup_workdir
    ;;
  *)
    echo "Unknown command: ${COMMAND}" >&2
    exit 1
    ;;
esac
