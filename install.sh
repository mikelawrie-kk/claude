#!/usr/bin/env bash
# Filename:    install.sh
# Description: One-shot installer for the Safari Media OS Phase 1 ingest worker
#              on a Virtarix VPS (Ubuntu 24.04 LTS, Johannesburg, 2GB).
#              Idempotent: re-runnable. Installs system packages, creates the
#              /opt/media-os tree, builds a Python 3.11 venv, lays down a
#              systemd service + hourly timer, and verifies the secret files
#              are in place at /etc/media-os. MySQL schema is deployed via
#              `sql/schema.sql` against cPanel's MySQL — that step is manual
#              and documented in README.md (cPanel must whitelist this VPS IP).
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial.

set -euo pipefail

INSTALL_DIR="/opt/media-os"
ETC_DIR="/etc/media-os"
CACHE_DIR="/var/lib/media-os/cache"
LOG_DIR="/var/log/media-os"
RUN_USER="${RUN_USER:-mediaos}"

if [[ "$EUID" -ne 0 ]]; then
  echo "install.sh must run as root (sudo)." >&2
  exit 1
fi

echo "==> apt update + base packages"
apt-get update
apt-get install -y --no-install-recommends \
  ca-certificates curl gnupg git build-essential \
  python3.11 python3.11-venv python3.11-dev \
  ffmpeg libvips libvips-dev \
  default-libmysqlclient-dev pkg-config

echo "==> Node 20 (NodeSource)"
if ! command -v node >/dev/null 2>&1 || ! node --version | grep -q '^v20\.'; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

echo "==> system user ${RUN_USER}"
if ! id -u "${RUN_USER}" >/dev/null 2>&1; then
  useradd --system --create-home --shell /usr/sbin/nologin "${RUN_USER}"
fi

echo "==> directories"
install -d -m 750 -o "${RUN_USER}" -g "${RUN_USER}" "${INSTALL_DIR}"
install -d -m 700 -o root         -g root         "${ETC_DIR}"
install -d -m 750 -o "${RUN_USER}" -g "${RUN_USER}" "${CACHE_DIR}"
install -d -m 750 -o "${RUN_USER}" -g "${RUN_USER}" "${LOG_DIR}"

echo "==> sync source into ${INSTALL_DIR}"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
rsync -a --delete \
  --exclude='.git/' --exclude='.github/' --exclude='__pycache__/' \
  --exclude='*.pyc' --exclude='tests/' \
  "${SRC_DIR}/ingest" "${SRC_DIR}/sql" "${SRC_DIR}/requirements.txt" \
  "${INSTALL_DIR}/"
chown -R "${RUN_USER}:${RUN_USER}" "${INSTALL_DIR}"

echo "==> python venv"
sudo -u "${RUN_USER}" python3.11 -m venv "${INSTALL_DIR}/venv"
sudo -u "${RUN_USER}" "${INSTALL_DIR}/venv/bin/pip" install --upgrade pip
sudo -u "${RUN_USER}" "${INSTALL_DIR}/venv/bin/pip" install -r "${INSTALL_DIR}/requirements.txt"

echo "==> secret file checks (must be staged manually by Mike)"
for f in "${ETC_DIR}/drive-sa.json" "${ETC_DIR}/anthropic.env"; do
  if [[ ! -f "$f" ]]; then
    echo "  MISSING: $f"
    echo "  Place the file with mode 600, owner ${RUN_USER}:${RUN_USER}, then re-run."
    MISSING=1
  else
    chmod 600 "$f"
    chown "${RUN_USER}:${RUN_USER}" "$f"
    echo "  OK: $f"
  fi
done

echo "==> environment file ${ETC_DIR}/ingest.env"
if [[ ! -f "${ETC_DIR}/ingest.env" ]]; then
  cat > "${ETC_DIR}/ingest.env" <<'EOF'
# Filename:    /etc/media-os/ingest.env
# Description: Runtime environment for media-os-ingest.service.
#              Populate the placeholders below before enabling the timer.
# Project:     media (Safari Media OS, Build #123)
MEDIAOS_CLIENT_SLUG=sausage-tree
MEDIAOS_DRIVE_FOLDER_ID=1f_o_JY6-ILavBygqHQLW8ru9Sa6CzHqT
MEDIAOS_DRIVE_SA_PATH=/etc/media-os/drive-sa.json
MEDIAOS_MYSQL_HOST=REPLACE_WITH_CPANEL_MYSQL_HOST
MEDIAOS_MYSQL_PORT=3306
MEDIAOS_MYSQL_USER=REPLACE_WITH_CPANEL_USER
MEDIAOS_MYSQL_PASSWORD=REPLACE_WITH_CPANEL_PASSWORD
MEDIAOS_MYSQL_DATABASE=safariwe_media_os
MEDIAOS_CACHE_DIR=/var/lib/media-os/cache
MEDIAOS_CLIP_MODEL=ViT-B-32
MEDIAOS_CLIP_PRETRAINED=openai
MEDIAOS_CLIP_DEVICE=cpu
MEDIAOS_VISION_MODEL=claude-haiku-4-5-20251001
MEDIAOS_BATCH_LIMIT=200
MEDIAOS_VISION_MAX_RETRIES=4
EOF
  chmod 600 "${ETC_DIR}/ingest.env"
  chown "${RUN_USER}:${RUN_USER}" "${ETC_DIR}/ingest.env"
fi

echo "==> systemd unit + hourly timer"
cat > /etc/systemd/system/media-os-ingest.service <<EOF
[Unit]
Description=Safari Media OS — Phase 1 ingest run
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=${RUN_USER}
Group=${RUN_USER}
EnvironmentFile=${ETC_DIR}/anthropic.env
EnvironmentFile=${ETC_DIR}/ingest.env
WorkingDirectory=${INSTALL_DIR}
ExecStart=${INSTALL_DIR}/venv/bin/python -m ingest.main
StandardOutput=append:${LOG_DIR}/ingest.log
StandardError=append:${LOG_DIR}/ingest.err
Nice=10
EOF

cat > /etc/systemd/system/media-os-ingest.timer <<'EOF'
[Unit]
Description=Safari Media OS — hourly ingest

[Timer]
OnCalendar=hourly
Persistent=true
AccuracySec=1min

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable media-os-ingest.timer

echo ""
echo "Install complete."
if [[ "${MISSING:-0}" == "1" ]]; then
  echo "Action required: stage missing secret files, then start the timer:"
  echo "  sudo systemctl start media-os-ingest.timer"
else
  systemctl start media-os-ingest.timer
  echo "Timer started. First run within the hour."
  echo "Trigger a one-off run with:   sudo systemctl start media-os-ingest.service"
  echo "Tail logs with:               sudo tail -F ${LOG_DIR}/ingest.log"
fi
