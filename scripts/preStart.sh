#!/bin/bash
# Re-fetch on boot when the installed binary no longer matches the running FPP.
# An FPP OS upgrade carries the plugin directory forward, so a binary built for
# the previous major survives the upgrade and will not load against the new
# libraries. fetch-binary.sh is a no-op when the right binary is already there.
BASEDIR="$(cd "$(dirname "$0")/.." && pwd)"
"${BASEDIR}/scripts/fetch-binary.sh" || true
