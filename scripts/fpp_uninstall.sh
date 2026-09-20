#!/bin/bash
#
# LOF Audio File Sync / LOF Audio Supply - FPP plugin uninstall hook.
#
# Removes what this plugin installed and nothing else. Published generations,
# manifests, and quarantine are media, not plugin artefacts, so they are left
# on disk; an uninstall script is the wrong place to delete an operator's audio.

set -Eeuo pipefail

UNIT_DIR="/etc/systemd/system"
PUBLICATION_ROOT="/home/fpp/media/lof-audio-supply"

echo "Uninstalling LOF Audio Supply..."

if command -v systemctl >/dev/null 2>&1; then
    systemctl stop lof-audio-supply.timer >/dev/null 2>&1 || true
    systemctl disable lof-audio-supply.timer >/dev/null 2>&1 || true
    systemctl stop lof-audio-supply.service >/dev/null 2>&1 || true
fi

rm -f "${UNIT_DIR}/lof-audio-supply.timer" "${UNIT_DIR}/lof-audio-supply.service"

if command -v systemctl >/dev/null 2>&1; then
    systemctl daemon-reload >/dev/null 2>&1 || true
fi

if [ -d "${PUBLICATION_ROOT}" ]; then
    echo "Published media left in place at ${PUBLICATION_ROOT} (generations, manifests, quarantine)."
    echo "Remove it manually if you no longer need it."
fi

echo "LOF Audio Supply uninstalled."
