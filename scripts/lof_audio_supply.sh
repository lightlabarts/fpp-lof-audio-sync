#!/bin/bash
#
# LOF Audio Supply - the single startup/maintenance entry point.
#
# This replaces the two divergent copies of start_sync.sh that the plugin used
# to ship (one at the repository root, one under scripts/, generating slightly
# different lsyncd configurations). There is no generated Lua any more and no
# lsyncd: the publish pipeline is a PHP command driven by a static systemd
# timer, so there is nothing for an operator-supplied value to be interpolated
# into.
#
# The web page does NOT call this script. It calls the same PHP library
# in-process, so there is exactly one set of rules to audit and no sudo
# surface reachable from a browser.

set -Eeuo pipefail
IFS=$'\n\t'

PLUGIN_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CLI="${PLUGIN_DIR}/bin/lof-audio-supply"

if [ ! -x "${PHP_BIN}" ]; then
    echo "lof-audio-supply: PHP interpreter not found at ${PHP_BIN}" >&2
    exit 1
fi

if [ ! -f "${CLI}" ]; then
    echo "lof-audio-supply: CLI not found at ${CLI}" >&2
    exit 1
fi

# Arguments are forwarded as a proper argument vector; the CLI parses them
# against a strict grammar and refuses anything it does not recognise.
exec "${PHP_BIN}" "${CLI}" "$@"
