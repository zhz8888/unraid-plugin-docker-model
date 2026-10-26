#!/usr/bin/env bash
#
# verify-release.sh -- Verify release artifacts.
#
# Usage:
#   ./scripts/verify-release.sh                    # verify the local dist/
#   ./scripts/verify-release.sh <url-to-plg>       # verify the txz referenced
#                                                  # by a remote .plg
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="${REPO_ROOT}/dist"

log()  { printf '\033[1;34m[verify]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
bad()  { printf '\033[1;31m  FAIL\033[0m %s\n' "$*" >&2; }
die()  { bad "$*"; exit 1; }

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

FAIL=0

# ---------------------------------------------------------------- local verification
if [ $# -eq 0 ]; then
  log "verifying the local dist/ artifacts"

  PLGS=("${DIST}/unraid-plugin-docker-model.plg"
        "${DIST}/unraid-plugin-docker-model-proxy.plg")

  # 1. Every .plg must point at the same package, with the same checksum.
  #    The direct and the mirrored variant ship identical bytes, so a mismatch
  #    means one of the two was rendered from a stale template.
  TXZ_NAME=""
  EXPECTED=""
  for PLG in "${PLGS[@]}"; do
    [ -f "${PLG}" ] || die "missing ${PLG}"
    log "checking ${PLG##*/}"

    N=$(grep -o 'unraid-plugin-docker-model-[0-9.]*-[a-z0-9_]*-1\.txz' "${PLG}" | head -1)
    E=$(grep -o '<SHA256>[0-9a-f]\{64\}</SHA256>' "${PLG}" | head -1 | sed 's/<[^>]*>//g')
    URL=$(grep -o '<URL>[^<]*\.txz</URL>' "${PLG}" | head -1 | sed 's/<[^>]*>//g')

    [ -n "${N}" ] || die "could not parse the txz filename from ${PLG##*/}"
    [ -n "${E}" ] || die "could not parse the SHA256 from ${PLG##*/}"

    if [ -z "${TXZ_NAME}" ]; then
      TXZ_NAME="${N}"
      EXPECTED="${E}"
    else
      if [ "${N}" != "${TXZ_NAME}" ]; then
        bad "${PLG##*/} references ${N}, expected ${TXZ_NAME}"; FAIL=1
      fi
      if [ "${E}" != "${EXPECTED}" ]; then
        bad "${PLG##*/} expects SHA256 ${E}, expected ${EXPECTED}"; FAIL=1
      fi
    fi

    # The mirrored variant has to actually go through the mirror
    case "${PLG##*/}" in
      *-proxy.plg)
        case "${URL}" in
          https://gh-proxy.org/*) ok "downloads through the gh-proxy.org mirror" ;;
          *) bad "the mirrored variant does not use the gh-proxy.org mirror: ${URL}"; FAIL=1 ;;
        esac ;;
    esac
  done

  TXZ="${DIST}/${TXZ_NAME}"
  [ -f "${TXZ}" ] || die "missing ${TXZ}"

  log "txz: ${TXZ_NAME}"
  log "plg expects SHA256: ${EXPECTED}"

  ACTUAL=$(sha256_of "${TXZ}")
  if [ "${ACTUAL}" = "${EXPECTED}" ]; then
    ok "txz SHA256 matches the plg"
  else
    bad "SHA256 mismatch"
    bad "  expected ${EXPECTED}"
    bad "  actual   ${ACTUAL}"
    FAIL=1
  fi

  # 2. Verify the txz layout
  log "checking the txz contents..."
  TMP=$(mktemp -d)
  trap 'rm -rf "${TMP}"' EXIT
  tar -xf "${TXZ}" -C "${TMP}"

  if [ -x "${TMP}/usr/libexec/docker/cli-plugins/docker-model" ]; then
    ok "binary present and executable"
  else
    bad "binary missing or not executable"; FAIL=1
  fi

  if [ -f "${TMP}/install/slack-desc" ]; then
    ok "install/slack-desc present (required by upgradepkg)"
  else
    bad "install/slack-desc missing"; FAIL=1
  fi

  # 3. Sanity-check the binary size (around 28 MB)
  SIZE=$(wc -c < "${TMP}/usr/libexec/docker/cli-plugins/docker-model" | tr -d ' ')
  if [ "${SIZE}" -gt 20000000 ] && [ "${SIZE}" -lt 40000000 ]; then
    ok "binary size looks sane: ${SIZE} bytes"
  else
    bad "binary size out of range: ${SIZE} bytes (expected 20-40 MB)"; FAIL=1
  fi

  # 4. Compare against the unpacked deb, when pkg/fetch is still around
  REF="${REPO_ROOT}/pkg/fetch/x/usr/libexec/docker/cli-plugins/docker-model"
  if [ -f "${REF}" ]; then
    REF_SHA=$(sha256_of "${REF}")
    ACT_SHA=$(sha256_of "${TMP}/usr/libexec/docker/cli-plugins/docker-model")
    if [ "${REF_SHA}" = "${ACT_SHA}" ]; then
      ok "byte-identical to the deb payload"
    else
      bad "differs from the deb payload"; FAIL=1
    fi
  else
    log "skipping the deb comparison (pkg/fetch missing; run scripts/fetch-plugin.sh first)"
  fi

  echo
  if [ "${FAIL}" -eq 0 ]; then
    log "all checks passed"
  else
    die "some checks failed"
  fi
  exit 0
fi

# ---------------------------------------------------------------- remote verification
PLG_URL="$1"
log "verifying the remote plg: ${PLG_URL}"

TMP=$(mktemp -d)
trap 'rm -rf "${TMP}"' EXIT

curl -fsSL --max-time 60 "${PLG_URL}" -o "${TMP}/remote.plg" || die "failed to download the plg"

TXZ_URL=$(grep -o '<URL>[^<]*\.txz</URL>' "${TMP}/remote.plg" | head -1 | sed 's/<[^>]*>//g')
EXPECTED=$(grep -o '<SHA256>[0-9a-f]\{64\}</SHA256>' "${TMP}/remote.plg" | head -1 | sed 's/<[^>]*>//g')

[ -n "${TXZ_URL}" ] || die "no txz URL in the plg"
[ -n "${EXPECTED}" ] || die "no SHA256 in the plg"

log "txz URL: ${TXZ_URL}"
log "expected SHA256: ${EXPECTED}"

curl -fsSL --max-time 300 "${TXZ_URL}" -o "${TMP}/remote.txz" || die "failed to download the txz"
ACTUAL=$(sha256_of "${TMP}/remote.txz")

if [ "${ACTUAL}" = "${EXPECTED}" ]; then
  ok "remote txz SHA256 matches"
  echo
  log "ready to install:"
  echo "    cp ${PLG_URL##*/} /boot/config/plugins/"
  echo "    plugin install /boot/config/plugins/${PLG_URL##*/}"
else
  bad "SHA256 mismatch"
  bad "  expected ${EXPECTED}"
  bad "  actual   ${ACTUAL}"
  exit 1
fi
