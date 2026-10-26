#!/usr/bin/env bash
#
# fetch-plugin.sh -- Download, verify and unpack only; no packing.
# Intended for debugging, auditing and inspecting what a given deb
# version actually contains.
#
# Usage:
#   ./scripts/fetch-plugin.sh                 # default 1.2.9 amd64
#   DMR_VERSION=1.2.6 ./scripts/fetch-plugin.sh
#
set -euo pipefail

DMR_VERSION="${DMR_VERSION:-1.2.9}"
DMR_ARCH="${DMR_ARCH:-amd64}"
DMR_UBUNTU="${DMR_UBUNTU:-noble}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${REPO_ROOT}/pkg/fetch"
APT_BASE="https://download.docker.com/linux/ubuntu"
APT_DIST="dists/${DMR_UBUNTU}/stable/binary-${DMR_ARCH}"

log() { printf '\033[1;34m[fetch]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[fatal]\033[0m %s\n' "$*" >&2; exit 1; }

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  else shasum -a 256 "$1" | awk '{print $1}'; fi
}

mkdir -p "${OUT}"

log "fetching the Packages index (${DMR_ARCH}/${DMR_UBUNTU})..."
curl -fsSL --retry 3 --max-time 120 "${APT_BASE}/${APT_DIST}/Packages" -o "${OUT}/Packages"

log "looking up docker-model-plugin ${DMR_VERSION} ..."
META=$(awk -v ver="${DMR_VERSION}" '
  function ver_match(v) { return v == ver || index(v, ver "-") == 1 }
  /^Package: docker-model-plugin$/ { inblk=1; hit=0; next }
  inblk && /^Version: / { if (ver_match($2)) hit=1; next }
  inblk && hit && /^(Filename|Size|SHA256): / { print $1, $2; next }
  inblk && /^$/ { if (hit) exit }
' "${OUT}/Packages")

[ -n "${META}" ] || die "version ${DMR_VERSION} not found"

SHA=$(echo "${META}" | awk '$1=="SHA256:"{print $2}')
SIZE=$(echo "${META}" | awk '$1=="Size:"{print $2}')
FILE=$(echo "${META}" | awk '$1=="Filename:"{print $2}')

echo
echo "  version  : ${DMR_VERSION}"
echo "  arch     : ${DMR_ARCH}"
echo "  Filename : ${FILE}"
echo "  Size     : ${SIZE}"
echo "  SHA256   : ${SHA}"
echo

DEB="${OUT}/$(basename "${FILE}")"
if [ ! -f "${DEB}" ]; then
  log "downloading the deb ..."
  curl -fsSL --retry 3 --max-time 300 "https://download.docker.com/linux/ubuntu/${FILE}" -o "${DEB}"
fi

ACTUAL=$(sha256_of "${DEB}")
[ "${ACTUAL}" = "${SHA}" ] || die "SHA256 mismatch!
  expected ${SHA}
  actual   ${ACTUAL}"
log "SHA256 verified"

log "unpacking ..."
rm -rf "${OUT}/x" && mkdir -p "${OUT}/x"
python3 - "${DEB}" "${OUT}/x" <<'PY'
import sys, os
deb, outdir = sys.argv[1], sys.argv[2]
data = open(deb, 'rb').read()
assert data[:8] == b'!<arch>\n', 'not an ar archive'
off, got = 8, {}
while off < len(data):
    hdr = data[off:off+60]
    if len(hdr) < 60: break
    name = hdr[0:16].decode(errors='ignore').strip().rstrip('/')
    size = int(hdr[48:58].decode().strip())
    got[name] = data[off+60:off+60+size]
    off += 60 + size + (size % 2)
for key in ('control.tar.xz','control.tar.gz','data.tar.xz','data.tar.gz','data.tar'):
    if key in got:
        open(os.path.join(outdir, key), 'wb').write(got[key])
PY

cd "${OUT}/x"
for f in *.tar.xz; do tar -xf "$f"; done
cd - >/dev/null

echo
log "unpacked contents:"
find "${OUT}/x" -type f -not -name "*.tar.*" | sed "s#${OUT}/x#  #"

echo
log "binary details:"
BIN="${OUT}/x/usr/libexec/docker/cli-plugins/docker-model"
if [ -f "${BIN}" ]; then
  echo "  path   : ${BIN}"
  echo "  size   : $(wc -c < "${BIN}" | tr -d ' ') bytes"
  echo "  SHA256 : $(sha256_of "${BIN}")"
  if command -v file >/dev/null 2>&1; then
    echo "  type   : $(file -b "${BIN}")"
  fi
  if command -v go >/dev/null 2>&1; then
    echo "  Go info:"
    go version -m "${BIN}" 2>/dev/null | grep -E "^\s+(go|path|mod|build)" | sed 's/^/    /' || true
  fi
fi
