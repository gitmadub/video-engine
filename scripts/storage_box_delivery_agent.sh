#!/usr/bin/env bash
set -euo pipefail

COMMAND="${1:-}"
shift || true

STORAGE_HOST=""
STORAGE_USERNAME=""
STORAGE_PASSWORD=""
STORAGE_PASSWORD_B64=""
MOUNT_PATH=""
LIBRARY_ROOT=""
CONFIG_PATH="/etc/video-engine/storage-box-agent.env"
INSTALL_PATH="/usr/local/bin/ve-storage-box-agent"
AGENT_VERSION="1.0.0"

usage() {
    cat <<'TXT'
Usage:
  storage_box_delivery_agent.sh install --storage-host <host> --storage-username <user> --storage-password <pass> --mount-path <path> --library-root <path>
  storage_box_delivery_agent.sh install --storage-host <host> --storage-username <user> --storage-password-b64 <base64> --mount-path <path> --library-root <path>
  storage_box_delivery_agent.sh snapshot [--mount-path <path>] [--library-root <path>]
  storage_box_delivery_agent.sh ensure-mounted [--mount-path <path>]
TXT
}

have_cmd() {
    command -v "$1" >/dev/null 2>&1
}

require_root() {
    if [ "${EUID:-0}" -ne 0 ]; then
        echo "This command must run as root." >&2
        exit 1
    fi
}

write_kv_config() {
    mkdir -p "$(dirname "$CONFIG_PATH")"
    {
        printf 'STORAGE_HOST=%q\n' "$STORAGE_HOST"
        printf 'STORAGE_USERNAME=%q\n' "$STORAGE_USERNAME"
        printf 'STORAGE_PASSWORD=%q\n' "$STORAGE_PASSWORD"
        printf 'MOUNT_PATH=%q\n' "$MOUNT_PATH"
        printf 'LIBRARY_ROOT=%q\n' "$LIBRARY_ROOT"
        printf 'AGENT_VERSION=%q\n' "$AGENT_VERSION"
    } >"$CONFIG_PATH"
    chmod 600 "$CONFIG_PATH"
}

load_config() {
    if [ -f "$CONFIG_PATH" ]; then
        # shellcheck disable=SC1090
        source "$CONFIG_PATH"
    fi
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --storage-host)
                STORAGE_HOST="${2:-}"
                shift 2
                ;;
            --storage-username)
                STORAGE_USERNAME="${2:-}"
                shift 2
                ;;
            --storage-password)
                STORAGE_PASSWORD="${2:-}"
                shift 2
                ;;
            --storage-password-b64)
                STORAGE_PASSWORD_B64="${2:-}"
                shift 2
                ;;
            --mount-path)
                MOUNT_PATH="${2:-}"
                shift 2
                ;;
            --library-root)
                LIBRARY_ROOT="${2:-}"
                shift 2
                ;;
            *)
                echo "Unknown argument: $1" >&2
                usage >&2
                exit 1
                ;;
        esac
    done
}

storage_url() {
    printf 'https://%s' "$STORAGE_HOST"
}

ensure_davfs_config() {
    mkdir -p /etc/davfs2 /root/.davfs2
    touch /etc/davfs2/secrets /root/.davfs2/secrets /etc/davfs2/davfs2.conf
    chmod 600 /etc/davfs2/secrets /root/.davfs2/secrets

    if ! grep -q '^use_locks' /etc/davfs2/davfs2.conf 2>/dev/null; then
        printf '\nuse_locks 0\ncache_size 0\n' >> /etc/davfs2/davfs2.conf
    fi

    local url
    url="$(storage_url)"
    local tmp_file
    tmp_file="$(mktemp)"
    grep -vF "$url " /etc/davfs2/secrets >"$tmp_file" || true
    printf '%s %s %s\n' "$url" "$STORAGE_USERNAME" "$STORAGE_PASSWORD" >>"$tmp_file"
    mv "$tmp_file" /etc/davfs2/secrets
    chmod 600 /etc/davfs2/secrets

    tmp_file="$(mktemp)"
    grep -vF "$url " /root/.davfs2/secrets >"$tmp_file" || true
    printf '%s %s %s\n' "$url" "$STORAGE_USERNAME" "$STORAGE_PASSWORD" >>"$tmp_file"
    mv "$tmp_file" /root/.davfs2/secrets
    chmod 600 /root/.davfs2/secrets
}

ensure_fstab_entry() {
    local url
    url="$(storage_url)"
    touch /etc/fstab
    if ! grep -qF "$MOUNT_PATH davfs" /etc/fstab 2>/dev/null; then
        printf '%s %s davfs _netdev,noauto,uid=www-data,gid=www-data,dir_mode=0775,file_mode=0664 0 0\n' "$url" "$MOUNT_PATH" >> /etc/fstab
    fi
}

ensure_systemd_units() {
    cat >/etc/systemd/system/ve-storage-box-remount.service <<EOF
[Unit]
Description=Ensure Video Engine Storage Box mount is present
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${INSTALL_PATH} ensure-mounted
EOF

    cat >/etc/systemd/system/ve-storage-box-remount.timer <<'EOF'
[Unit]
Description=Periodic Storage Box mount check

[Timer]
OnBootSec=45s
OnUnitActiveSec=2min
Unit=ve-storage-box-remount.service

[Install]
WantedBy=timers.target
EOF

    systemctl daemon-reload
    systemctl enable --now ve-storage-box-remount.timer >/dev/null 2>&1 || true
}

ensure_mount() {
    load_config

    if [ -z "$STORAGE_HOST" ] || [ -z "$STORAGE_USERNAME" ] || [ -z "$STORAGE_PASSWORD" ] || [ -z "$MOUNT_PATH" ]; then
        echo "Storage Box agent is missing mount configuration." >&2
        exit 1
    fi

    mkdir -p "$MOUNT_PATH"
    if ! mountpoint -q "$MOUNT_PATH"; then
        mount "$MOUNT_PATH"
    fi

    if [ -n "$LIBRARY_ROOT" ]; then
        mkdir -p "$LIBRARY_ROOT"
        if id -u www-data >/dev/null 2>&1; then
            chown -R www-data:www-data "$LIBRARY_ROOT" || true
        fi
        chmod 0775 "$LIBRARY_ROOT" || true
    fi
}

install_agent() {
    require_root
    parse_args "$@"

    if [ -z "$STORAGE_PASSWORD" ] && [ -n "$STORAGE_PASSWORD_B64" ]; then
        STORAGE_PASSWORD="$(printf '%s' "$STORAGE_PASSWORD_B64" | base64 -d)"
    fi

    if [ -z "$STORAGE_HOST" ] || [ -z "$STORAGE_USERNAME" ] || [ -z "$STORAGE_PASSWORD" ] || [ -z "$MOUNT_PATH" ] || [ -z "$LIBRARY_ROOT" ]; then
        echo "All install arguments are required." >&2
        exit 1
    fi

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y >/dev/null
    apt-get install -y davfs2 python3 coreutils >/dev/null

    install -m 0755 "$0" "$INSTALL_PATH"
    write_kv_config
    ensure_davfs_config
    ensure_fstab_entry
    ensure_systemd_units
    ensure_mount
    snapshot_agent
}

snapshot_agent() {
    load_config
    parse_args "$@"

    if [ -z "$MOUNT_PATH" ] || [ -z "$LIBRARY_ROOT" ]; then
        echo "Mount path and library root are required." >&2
        exit 1
    fi

    local mount_ok=1
    local health_status="healthy"
    local total_bytes=0
    local used_bytes=0
    local available_bytes=0
    local library_bytes=0
    local file_count=0

    if ! mountpoint -q "$MOUNT_PATH"; then
        if ! mount "$MOUNT_PATH" >/dev/null 2>&1; then
            mount_ok=0
            health_status="offline"
        fi
    fi

    mkdir -p "$LIBRARY_ROOT" >/dev/null 2>&1 || true

    if [ "$mount_ok" -eq 1 ]; then
        local df_line
        df_line="$(df -B1 "$MOUNT_PATH" | tail -n 1)"
        total_bytes="$(awk '{print $2}' <<<"$df_line")"
        used_bytes="$(awk '{print $3}' <<<"$df_line")"
        available_bytes="$(awk '{print $4}' <<<"$df_line")"
        library_bytes="$(du -sb "$LIBRARY_ROOT" 2>/dev/null | awk '{print $1}' || echo 0)"
        file_count="$(find "$LIBRARY_ROOT" -type f 2>/dev/null | wc -l | awk '{print $1}')"
    fi

    python3 - <<PY
import json, socket, time
payload = {
    "ok": True,
    "agent_version": "${AGENT_VERSION}",
    "backend_type": "webdav_box",
    "remote_scheme": "webdav",
    "hostname": socket.gethostname(),
    "mount_path": "${MOUNT_PATH}",
    "library_root": "${LIBRARY_ROOT}",
    "mount_ok": ${mount_ok},
    "capacity_bytes": int(${total_bytes}),
    "used_bytes": int(${used_bytes}),
    "available_bytes": int(${available_bytes}),
    "library_bytes": int(${library_bytes}),
    "file_count": int(${file_count}),
    "health_status": "${health_status}",
    "captured_at": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime()),
}
print(json.dumps(payload, separators=(",", ":")))
PY
}

case "$COMMAND" in
    install)
        install_agent "$@"
        ;;
    snapshot|telemetry)
        snapshot_agent "$@"
        ;;
    ensure-mounted)
        load_config
        parse_args "$@"
        ensure_mount
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
