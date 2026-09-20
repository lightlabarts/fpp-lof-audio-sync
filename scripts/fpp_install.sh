#!/bin/bash
#
# LOF Audio File Sync / LOF Audio Supply - FPP plugin install hook.
#
# Preserves FPP's install conventions (sourcing scripts/common, honouring
# PLUGINDIR / PLUGIN_NAME) while replacing the lsyncd-based sync with the
# staged publication pipeline.
#
# Nothing here interpolates an operator-supplied value into a generated file.
# The only substitution that reaches a systemd unit is the plugin directory,
# and it is validated against a strict grammar first.

set -Eeuo pipefail

# FPP exports these; the guards keep the script runnable for a manual install.
FPPDIR="${FPPDIR:-/opt/fpp}"
PLUGINDIR="${PLUGINDIR:-/home/fpp/media/plugins}"
PLUGIN_NAME="${PLUGIN_NAME:-fpp-lof-audio-sync}"

if [ -f "${FPPDIR}/scripts/common" ]; then
    # shellcheck disable=SC1091
    . "${FPPDIR}/scripts/common"
fi

PLUGIN_DIR="${PLUGINDIR}/${PLUGIN_NAME}"
if [ ! -d "${PLUGIN_DIR}" ]; then
    # Older FPP layouts keep plugins under /opt/fpp/plugins.
    PLUGIN_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
fi

MEDIA_ROOT="/home/fpp/media"
PUBLICATION_ROOT="${MEDIA_ROOT}/lof-audio-supply"
CONFIG_ROOT="${MEDIA_ROOT}/config"
KNOWN_HOSTS="${CONFIG_ROOT}/lof-audio-known_hosts"
SERVICE_USER="fpp"
UNIT_DIR="/etc/systemd/system"
SERVICE_UNIT="${UNIT_DIR}/lof-audio-supply.service"
TIMER_UNIT="${UNIT_DIR}/lof-audio-supply.timer"

echo "Installing LOF Audio Supply..."

# --- validate the one value that reaches a generated file -------------------
if [[ ! "${PLUGIN_DIR}" =~ ^(/[A-Za-z0-9._-]+)+$ ]]; then
    echo "Refusing to install: plugin directory '${PLUGIN_DIR}' is not a plain absolute path." >&2
    exit 1
fi
if [ ! -x "${PLUGIN_DIR}/scripts/lof_audio_supply.sh" ]; then
    chmod +x "${PLUGIN_DIR}/scripts/lof_audio_supply.sh" 2>/dev/null || true
fi

# --- required tooling -------------------------------------------------------
missing=0
for binary in /usr/bin/php /usr/bin/rsync; do
    if [ ! -x "${binary}" ]; then
        echo "Missing required binary: ${binary}" >&2
        missing=1
    fi
done
if [ "${missing}" -ne 0 ]; then
    echo "Install the missing packages and re-run the plugin install." >&2
    exit 1
fi

# --- estate directories -----------------------------------------------------
install -d -m 0750 -o "${SERVICE_USER}" -g "${SERVICE_USER}" "${PUBLICATION_ROOT}" 2>/dev/null \
    || mkdir -p "${PUBLICATION_ROOT}"
install -d -m 0750 -o "${SERVICE_USER}" -g "${SERVICE_USER}" "${CONFIG_ROOT}" 2>/dev/null \
    || mkdir -p "${CONFIG_ROOT}"

# Pinned host keys. Created empty and 0600 so StrictHostKeyChecking=yes has a
# file to consult; the operator adds the destination's key deliberately.
if [ ! -f "${KNOWN_HOSTS}" ]; then
    touch "${KNOWN_HOSTS}"
    chmod 0600 "${KNOWN_HOSTS}"
    chown "${SERVICE_USER}:${SERVICE_USER}" "${KNOWN_HOSTS}" 2>/dev/null || true
fi

# --- settings ---------------------------------------------------------------
# Defaults are written by the CLI so the same validator that guards the web
# form also guards the installed file. An existing settings.json is migrated
# in place, never overwritten.
/usr/bin/php "${PLUGIN_DIR}/bin/lof-audio-supply" init || {
    echo "Settings initialisation failed." >&2
    exit 1
}
chown "${SERVICE_USER}:${SERVICE_USER}" "${PLUGIN_DIR}/settings.json" 2>/dev/null || true
chmod 0640 "${PLUGIN_DIR}/settings.json" 2>/dev/null || true

# --- systemd units ----------------------------------------------------------
# Static content. The timer cadence is fixed; the configured sync interval is
# applied inside the CLI, so no setting is ever written into a unit file.
if [ -d "${UNIT_DIR}" ] && command -v systemctl >/dev/null 2>&1; then
    cat > "${SERVICE_UNIT}" <<UNIT
[Unit]
Description=Lights on Falcon audio media supply (publish one generation)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=${SERVICE_USER}
Group=${SERVICE_USER}
ExecStart=${PLUGIN_DIR}/scripts/lof_audio_supply.sh publish
Nice=10
IOSchedulingClass=idle
TimeoutStartSec=3600
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=false
ReadWritePaths=${MEDIA_ROOT}
ProtectKernelTunables=true
ProtectKernelModules=true
ProtectControlGroups=true
RestrictSUIDSGID=true
RestrictRealtime=true
LockPersonality=true
SystemCallArchitectures=native
CapabilityBoundingSet=
UNIT
    chmod 0644 "${SERVICE_UNIT}"

    cat > "${TIMER_UNIT}" <<'UNIT'
[Unit]
Description=Periodic Lights on Falcon audio media supply

[Timer]
OnBootSec=2min
OnUnitActiveSec=60s
AccuracySec=15s
Persistent=false
Unit=lof-audio-supply.service

[Install]
WantedBy=timers.target
UNIT
    chmod 0644 "${TIMER_UNIT}"

    # Validate what we just generated before asking systemd to run it.
    if command -v systemd-analyze >/dev/null 2>&1; then
        if ! systemd-analyze verify "${SERVICE_UNIT}" "${TIMER_UNIT}" >/dev/null 2>&1; then
            echo "Generated systemd units failed verification; removing them." >&2
            rm -f "${SERVICE_UNIT}" "${TIMER_UNIT}"
            exit 1
        fi
    fi

    systemctl daemon-reload
    systemctl enable lof-audio-supply.timer >/dev/null 2>&1 || true
    systemctl start lof-audio-supply.timer >/dev/null 2>&1 || true
else
    echo "systemd not available; publish must be driven manually via scripts/lof_audio_supply.sh publish"
fi

# --- retire the lsyncd-era configuration ------------------------------------
# The old plugin generated /etc/lsyncd/lsyncd-lof.conf.lua and ran lsyncd with
# rsync --delete. Stop it. The file is left on disk for the operator to inspect
# rather than deleted by an install script.
if command -v systemctl >/dev/null 2>&1; then
    systemctl stop lsyncd >/dev/null 2>&1 || true
    systemctl disable lsyncd >/dev/null 2>&1 || true
fi
if [ -f /etc/lsyncd/lsyncd-lof.conf.lua ]; then
    echo "NOTE: /etc/lsyncd/lsyncd-lof.conf.lua is left in place for review; it is no longer used."
fi

echo "LOF Audio Supply installed. Configure it under Content Setup -> LOF Audio Supply."
