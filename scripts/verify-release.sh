#!/usr/bin/env bash
#
# verify-release.sh -- Verify release artifacts.
#
# Checks that the .plg files and the Slackware package agree with each other:
# the pinned checksums, the package layout, the WebGUI page and the build
# metadata that the page reads.
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

  TMP=$(mktemp -d)
  trap 'rm -rf "${TMP}"' EXIT

  # The .plg uses XML entities, so the files have to be parsed properly
  # instead of being grepped.
  for PLG in "${PLGS[@]}"; do
    [ -f "${PLG}" ] || die "missing ${PLG}"
    log "checking ${PLG##*/}"

    python3 - "${PLG}" >> "${TMP}/plgs.env" <<'PY'
import re
import sys
import xml.etree.ElementTree as ET

plg = sys.argv[1]
tree = ET.parse(plg)
root = tree.getroot()

entities = dict(re.findall(r'<!ENTITY\s+([A-Za-z][A-Za-z0-9_-]*)\s+"([^"]*)">',
                           open(plg, encoding='utf-8').read()))

files = [f for f in root.findall('FILE') if (f.findtext('URL') or '').strip().endswith('.txz')]
if not files:
    print('ERROR no package FILE entry found')
    sys.exit(1)

package = files[0]
print('TXZ_NAME', entities.get('txzName', ''))
print('TXZ_URL', package.findtext('URL').strip())
print('TXZ_SHA256', (package.findtext('SHA256') or '').strip())
print('BINARY_SHA256', entities.get('binarySHA256', ''))
print('PLUGIN_VERSION', root.get('version'))
print('UPSTREAM_VERSION', entities.get('upstream', ''))
PY
  done
  cat "${TMP}/plgs.env"

  TXZ_NAME=$(awk '/^TXZ_NAME /{print $2; exit}' "${TMP}/plgs.env")
  EXPECTED=$(awk '/^TXZ_SHA256 /{print $2; exit}' "${TMP}/plgs.env")
  BINARY_SHA=$(awk '/^BINARY_SHA256 /{print $2; exit}' "${TMP}/plgs.env")
  PLUGIN_VERSION=$(awk '/^PLUGIN_VERSION /{print $2; exit}' "${TMP}/plgs.env")
  UPSTREAM_VERSION=$(awk '/^UPSTREAM_VERSION /{print $2; exit}' "${TMP}/plgs.env")

  [ -n "${TXZ_NAME}" ] || die "could not parse the txz filename from the plg"
  [ -n "${EXPECTED}" ] || die "could not parse the SHA256 from the plg"

  # 1. Every .plg must point at the same package, with the same checksum.
  #    The direct and the mirrored variant ship identical bytes, so a mismatch
  #    means one of the two was rendered from a stale template.
  for PLG in "${PLGS[@]}"; do
    NAME=$(python3 - "${PLG}" <<'PY'
import re, sys
text = open(sys.argv[1], encoding='utf-8').read()
entities = dict(re.findall(r'<!ENTITY\s+([A-Za-z][A-Za-z0-9_-]*)\s+"([^"]*)">', text))
print(entities.get('txzName', ''))
PY
)
    SHA=$(python3 - "${PLG}" <<'PY'
import re, sys
import xml.etree.ElementTree as ET
root = ET.parse(sys.argv[1]).getroot()
for f in root.findall('FILE'):
    if (f.findtext('URL') or '').strip().endswith('.txz'):
        print((f.findtext('SHA256') or '').strip())
        break
PY
)
    if [ "${NAME}" != "${TXZ_NAME}" ]; then
      bad "${PLG##*/} references ${NAME}, expected ${TXZ_NAME}"; FAIL=1
    fi
    if [ "${SHA}" != "${EXPECTED}" ]; then
      bad "${PLG##*/} expects SHA256 ${SHA}, expected ${EXPECTED}"; FAIL=1
    fi

    # The mirrored variant has to actually go through the mirror
    case "${PLG##*/}" in
      *-proxy.plg)
        URL=$(python3 - "${PLG}" <<'PY'
import sys
import xml.etree.ElementTree as ET
root = ET.parse(sys.argv[1]).getroot()
for f in root.findall('FILE'):
    if (f.findtext('URL') or '').strip().endswith('.txz'):
        print(f.findtext('URL').strip())
        break
PY
)
        case "${URL}" in
          https://gh-proxy.org/*) ok "downloads through the gh-proxy.org mirror" ;;
          *) bad "the mirrored variant does not use the gh-proxy.org mirror: ${URL}"; FAIL=1
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
  tar -xf "${TXZ}" -C "${TMP}"

  check_file() {
    if [ -f "${TMP}/$1" ]; then
      ok "$1 present"
    else
      bad "$1 missing"; FAIL=1
    fi
  }

  check_exec() {
    if [ -x "${TMP}/$1" ]; then
      ok "$1 present and executable"
    else
      bad "$1 missing or not executable"; FAIL=1
    fi
  }

  check_exec "usr/libexec/docker/cli-plugins/docker-model"
  check_file "install/slack-desc"
  check_exec "install/doinst.sh"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/DockerModelRunner.page"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/sheets/DockerModelRunner.css"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/include/common.php"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/include/Pages/Home.php"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/include/data/Status.php"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/include/data/Action.php"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/diagnostics.json"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/README.md"
  check_file "usr/local/emhttp/plugins/unraid-plugin-docker-model/unraid-plugin-docker-model.png"

  # The launch attribute points at the page of the plugin, so the .page file
  # it names has to be part of the package.
  LAUNCH=$(python3 - "${PLGS[0]}" <<'PY'
import sys
import xml.etree.ElementTree as ET
print(ET.parse(sys.argv[1]).getroot().get('launch') or '')
PY
)
  if [ -n "${LAUNCH}" ]; then
    PAGE_BASENAME="${LAUNCH##*/}"
    if [ -f "${TMP}/usr/local/emhttp/plugins/unraid-plugin-docker-model/${PAGE_BASENAME}.page" ]; then
      ok "launch target ${LAUNCH} resolves to a .page file in the package"
    else
      bad "launch target ${LAUNCH} does not resolve to a .page file in the package"; FAIL=1
    fi
  fi

  # 3. The build metadata must match the plugin definition
  INFO="${TMP}/usr/local/emhttp/plugins/unraid-plugin-docker-model/plugin.info"
  if [ -f "${INFO}" ]; then
    ok "plugin.info present"
    # plugin.info values are double-quoted by the generator, because the PHP
    # INI scanner treats an unquoted "~" as an operator.
    INFO_VERSION=$(grep '^PLUGIN_VERSION=' "${INFO}" | cut -d= -f2- | tr -d '"' || true)
    INFO_UPSTREAM=$(grep '^UPSTREAM_VERSION=' "${INFO}" | cut -d= -f2- | tr -d '"' || true)
    if [ "${INFO_VERSION}" = "${PLUGIN_VERSION}" ]; then
      ok "plugin.info PLUGIN_VERSION matches the plg (${INFO_VERSION})"
    else
      bad "plugin.info PLUGIN_VERSION is ${INFO_VERSION}, expected ${PLUGIN_VERSION}"; FAIL=1
    fi
    if [ "${INFO_UPSTREAM}" = "${UPSTREAM_VERSION}" ]; then
      ok "plugin.info UPSTREAM_VERSION matches the plg (${INFO_UPSTREAM})"
    else
      bad "plugin.info UPSTREAM_VERSION is ${INFO_UPSTREAM}, expected ${UPSTREAM_VERSION}"; FAIL=1
    fi
  else
    bad "plugin.info missing"; FAIL=1
  fi

  # 4. The binary in the package must match the checksum pinned in the plg
  if [ -n "${BINARY_SHA}" ] && [ -f "${TMP}/usr/libexec/docker/cli-plugins/docker-model" ]; then
    ACTUAL_BIN=$(sha256_of "${TMP}/usr/libexec/docker/cli-plugins/docker-model")
    if [ "${ACTUAL_BIN}" = "${BINARY_SHA}" ]; then
      ok "binary SHA256 matches the value pinned in the plg"
    else
      bad "binary SHA256 mismatch"
      bad "  expected ${BINARY_SHA}"
      bad "  actual   ${ACTUAL_BIN}"
      FAIL=1
    fi
  fi

  # 5. Sanity-check the binary size (around 28 MB)
  SIZE=$(wc -c < "${TMP}/usr/libexec/docker/cli-plugins/docker-model" | tr -d ' ')
  if [ "${SIZE}" -gt 20000000 ] && [ "${SIZE}" -lt 40000000 ]; then
    ok "binary size looks sane: ${SIZE} bytes"
  else
    bad "binary size out of range: ${SIZE} bytes (expected 20-40 MB)"; FAIL=1
  fi

  # 6. Compare against the unpacked deb, when pkg/fetch is still around
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

python3 - "${TMP}/remote.plg" > "${TMP}/remote.env" <<'PY'
import re
import sys
import xml.etree.ElementTree as ET

plg = sys.argv[1]
root = ET.parse(plg).getroot()
for f in root.findall('FILE'):
    url = (f.findtext('URL') or '').strip()
    if url.endswith('.txz'):
        print('TXZ_URL', url)
        print('TXZ_SHA256', (f.findtext('SHA256') or '').strip())
        break
PY
cat "${TMP}/remote.env"

TXZ_URL=$(awk '/^TXZ_URL /{print $2; exit}' "${TMP}/remote.env")
EXPECTED=$(awk '/^TXZ_SHA256 /{print $2; exit}' "${TMP}/remote.env")

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
