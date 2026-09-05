#!/bin/bash
#############################################################################
# Download the cape programmer binary matching this device's platform and the
# installed FPP major version, and place it at bin/programmer-cli.
#
# Binaries are published per FPP major to a rolling release tag on the public
# eeprom repo, e.g.
#   releases/download/fpp10/kl-programmer-BB64-10.gz
#
# Keying on the FPP major is not cosmetic: the binary links libgpiod, libjsoncpp
# and libcurl, whose sonames change between the Debian releases the FPP majors
# are built on (libgpiodcxx.so.1 -> .so.2, libjsoncpp.so.25 -> .26). A binary
# for the wrong major does not load at all.
#
# Run at install time and again on every boot: the boot run re-fetches when the
# binary is missing or no longer matches the running FPP, which is what happens
# after an FPP OS upgrade carries the old plugin directory forward.
#############################################################################

BASEDIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")/.." && pwd)"

# FPP exports FPPDIR when it runs our scripts. Set it before sourcing common:
# common derives FPPDIR from $0 when it is unset, which resolves to the CALLING
# script's parent directory - so a hand-run from the plugin directory would
# otherwise look for FPP under /home/fpp/media/plugins.
FPPDIR="${FPPDIR:-/opt/fpp}"
export FPPDIR
if [ -f "${FPPDIR}/scripts/common" ]; then
    . "${FPPDIR}/scripts/common"
fi
# Belt and braces: if anything upstream handed us an FPPDIR that is not an FPP
# tree, fall back rather than fail with a confusing path.
if [ ! -f "${FPPDIR}/src/fppversion_defines.h" ] && [ -f /opt/fpp/src/fppversion_defines.h ]; then
    FPPDIR=/opt/fpp
fi

REPO_URL="${KLPROG_REPO_URL:-https://github.com/KulpLights/cape-eeproms}"
# The binary lives at the plugin root, not in a bin/ subdirectory: it chdirs to
# its own directory on startup and resolves programmer-config.json, the cache
# and serialNumbers.txt from there, so it has to sit alongside them.
TARGET="${BASEDIR}/programmer-cli"
MARKER="${BASEDIR}/.binary-major"

# Determine whether the installed FPP is 64-bit. `uname -m` is NOT reliable:
# a Pi 4/5 boots a 64-bit kernel even under a 32-bit FPP, so it reports
# "aarch64" for what is really an armhf userspace. Read the ELF class of an
# actual FPP binary instead - byte 5 of the ELF header is 2 for 64-bit.
fpp_is_64bit() {
    _f=""
    for _c in "${FPPDIR}/src/fppd" "${FPPDIR}/src/libfpp.so"; do
        [ -r "${_c}" ] && { _f="${_c}"; break; }
    done
    if [ -n "${_f}" ]; then
        case "$(od -An -t u1 -j4 -N1 "${_f}" 2>/dev/null | tr -d '[:space:]')" in
            2) return 0 ;;
            1) return 1 ;;
        esac
    fi
    if command -v getconf >/dev/null 2>&1; then
        [ "$(getconf LONG_BIT 2>/dev/null)" = "64" ]
        return $?
    fi
    [ "$(uname -m)" = "aarch64" ]
}

# Only the BeagleBone platforms are supported. The programmer reaches the cape
# over i2c-2, which a Pi does not have, and no Pi cape carries an MSPM0 - so
# there is nothing for this plugin to do there. Exit 0 rather than 1: the
# plugin simply has no work on that hardware, which is not an install failure.
case "${FPPPLATFORM}" in
    "BeagleBone 64")    PLAT="BB64" ;;
    "BeagleBone Black") PLAT="BBB" ;;
    *)
        echo "KL-MSPM0Flasher: platform '${FPPPLATFORM}' has no MSPM0 capes; no binary needed"
        exit 0
        ;;
esac
# "BeagleBone Black" covers the whole AM335x family; a PocketBeagle2 reports
# "BeagleBone 64". Trust the bitness over the label if the two disagree.
if [ "${PLAT}" = "BBB" ] && fpp_is_64bit; then
    PLAT="BB64"
fi

VERFILE="${FPPDIR}/src/fppversion_defines.h"
MAJ="$(grep -oE 'FPP_MAJOR_VERSION[[:space:]]+[0-9]+' "${VERFILE}" 2>/dev/null | grep -oE '[0-9]+$')"
if [ -z "${MAJ}" ]; then
    echo "KL-MSPM0Flasher: could not determine FPP major version from ${VERFILE}" >&2
    exit 1
fi

if [ -s "${TARGET}" ] && [ "$(cat "${MARKER}" 2>/dev/null)" = "${PLAT}-${MAJ}" ]; then
    echo "KL-MSPM0Flasher: ${PLAT} binary for FPP ${MAJ} already present"
    exit 0
fi

ASSET="kl-programmer-${PLAT}-${MAJ}.gz"
URL="${REPO_URL}/releases/download/fpp${MAJ}/${ASSET}"
SUMSURL="${REPO_URL}/releases/download/fpp${MAJ}/checksums.txt"
echo "KL-MSPM0Flasher: downloading ${ASSET} ..."

TMP="$(mktemp "${BASEDIR}/.programmer-cli.XXXXXX.gz")"
# The checksum list is not an install artifact, so keep it out of the plugin
# directory, which is a git working tree.
SUMS="$(mktemp "${TMPDIR:-/tmp}/kl-mspm0.XXXXXX.sums")"
trap 'rm -f "${TMP}" "${TMP%.gz}" "${SUMS}"' EXIT

sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | cut -d' ' -f1
    else
        shasum -a 256 "$1" 2>/dev/null | cut -d' ' -f1
    fi
}

# Verify before the download can replace a working binary. CI uploads the
# binaries and checksums.txt as separate assets, so a fetch landing mid-publish
# can see a mismatched pair; one re-fetch of both resolves that, and a mismatch
# that survives the retry is fatal.
VERIFY="pending"
for ATTEMPT in 1 2; do
    if ! curl -fSL --retry 3 -o "${TMP}" "${URL}"; then
        echo "KL-MSPM0Flasher: ERROR downloading ${URL}" >&2
        echo "KL-MSPM0Flasher: no binary published for ${PLAT} on FPP ${MAJ}." >&2
        exit 1
    fi
    if ! curl -fsL --retry 3 -o "${SUMS}" "${SUMSURL}"; then
        VERIFY="no-checksums"; break
    fi
    EXPECTED="$(awk -v a="${ASSET}" '$2 == a { print tolower($1); exit }' "${SUMS}")"
    if [ -z "${EXPECTED}" ]; then VERIFY="unlisted"; break; fi
    ACTUAL="$(sha256_of "${TMP}")"
    if [ -z "${ACTUAL}" ]; then VERIFY="no-tool"; break; fi
    if [ "${ACTUAL}" = "${EXPECTED}" ]; then VERIFY="ok"; break; fi
    VERIFY="mismatch"
    [ "${ATTEMPT}" = "1" ] && echo "KL-MSPM0Flasher: checksum mismatch, re-fetching ..." >&2
done
case "${VERIFY}" in
    ok)           echo "KL-MSPM0Flasher: checksum verified for ${ASSET}" ;;
    no-checksums) echo "KL-MSPM0Flasher: release fpp${MAJ} has no checksums.txt, skipping verification" ;;
    unlisted)     echo "KL-MSPM0Flasher: WARNING: ${ASSET} not listed in checksums.txt" >&2 ;;
    no-tool)      echo "KL-MSPM0Flasher: WARNING: no sha256 tool available" >&2 ;;
    *)
        echo "KL-MSPM0Flasher: ERROR: checksum mismatch for ${ASSET}; keeping what is installed." >&2
        echo "KL-MSPM0Flasher: (a release may be mid-publish -- retry in a few minutes)" >&2
        exit 1
        ;;
esac

if ! gunzip -f "${TMP}"; then
    echo "KL-MSPM0Flasher: ERROR decompressing ${ASSET}" >&2
    exit 1
fi

# mktemp creates 0600 and mv preserves it, which would leave the binary
# unreadable to anything but root. It is run via sudo but the web UI stats it.
chmod 755 "${TMP%.gz}"
mv -f "${TMP%.gz}" "${TARGET}"
echo "${PLAT}-${MAJ}" > "${MARKER}"
chmod 644 "${MARKER}"
echo "KL-MSPM0Flasher: installed ${PLAT} programmer for FPP ${MAJ}"
