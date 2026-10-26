#!/usr/bin/env bash
#
# build-plugin.sh -- Build the docker-model plugin for Unraid
#                   (unraid-plugin-docker-model)
#
# Pipeline:
#   1. Read SHA256 / Size / Filename for the target version from the
#      download.docker.com Packages index
#   2. Download the docker-model-plugin deb and verify SHA256 byte for byte
#   3. Parse the ar container (Python path + pure shell fallback, because
#      Unraid ships neither ar nor dpkg-deb)
#   4. Extract usr/libexec/docker/cli-plugins/docker-model from data.tar.xz
#   5. Pack a Slackware txz (with install/slack-desc, required by upgradepkg)
#   6. Render the .plg with the txz SHA256 baked in
#
# Usage:
#   ./scripts/build-plugin.sh
#   DMR_VERSION=1.2.9 ./scripts/build-plugin.sh
#   DMR_ARCH=arm64 ./scripts/build-plugin.sh
#   DMR_UBUNTU=noble ./scripts/build-plugin.sh
#
set -euo pipefail

# ---------------------------------------------------------------- config
DMR_VERSION="${DMR_VERSION:-1.2.9}"
DMR_ARCH="${DMR_ARCH:-amd64}"          # amd64 | arm64 | armhf
DMR_UBUNTU="${DMR_UBUNTU:-noble}"      # 22.04=jammy 24.04=noble

# Unraid plugin name (also used as the .plg filename, the flash cache
# directory name, and the Slackware package name prefix)
PLUGIN_NAME="unraid-plugin-docker-model"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PKG_DIR="${REPO_ROOT}/pkg"
DIST_DIR="${REPO_ROOT}/dist"
WORK_DIR="${PKG_DIR}/work"

# Slackware package naming: <name>-<version>-<arch>-<build>
SLACK_ARCH="$([ "${DMR_ARCH}" = "amd64" ] && echo "x86_64" || echo "${DMR_ARCH}")"
TXZ_NAME="${PLUGIN_NAME}-${DMR_VERSION}-${SLACK_ARCH}-1.txz"

# The upstream deb name is kept as-is: it is Docker's official artifact name
DEB_NAME="docker-model-plugin_${DMR_VERSION}-1~ubuntu.24.04~${DMR_UBUNTU}_${DMR_ARCH}.deb"
APT_BASE="https://download.docker.com/linux/ubuntu"
APT_DIST="dists/${DMR_UBUNTU}/stable/binary-${DMR_ARCH}"

# ---------------------------------------------------------------- helpers
log()  { printf '\033[1;34m[build]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn ]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[fatal]\033[0m %s\n' "$*" >&2; exit 1; }

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'
  elif command -v shasum   >/dev/null 2>&1; then shasum -a 256 "$1" | awk '{print $1}'
  else die "neither sha256sum nor shasum is available"; fi
}

need() { command -v "$1" >/dev/null 2>&1 || die "missing required command: $1"; }

need curl
need tar
need xz

mkdir -p "${PKG_DIR}" "${DIST_DIR}" "${WORK_DIR}"

log "target version: ${DMR_VERSION}  arch: ${DMR_ARCH}  ubuntu base: ${DMR_UBUNTU}"

# ---------------------------------------------------------------- 1. apt metadata
log "fetching the apt Packages index..."
PACKAGES_URL="${APT_BASE}/${APT_DIST}/Packages"
PKG_INDEX="${WORK_DIR}/Packages"
curl -fsSL --retry 3 --max-time 120 "${PACKAGES_URL}" -o "${PKG_INDEX}" \
  || die "failed to download the Packages index: ${PACKAGES_URL}"

# Pull the (Filename, Size, SHA256) triple for the requested version.
# Note: the index carries full Debian versions such as
# 1.2.9-1~ubuntu.24.04~noble, while DMR_VERSION is only 1.2.9,
# so match by prefix ("<ver>-")
read_meta() {
  awk -v ver="${DMR_VERSION}" '
    function ver_match(v) { return v == ver || index(v, ver "-") == 1 }
    /^Package: docker-model-plugin$/ { inblk=1; hit=0; next }
    inblk && /^Version: / { if (ver_match($2)) hit=1; next }
    inblk && hit && /^(Filename|Size|SHA256): / { print $1, $2; next }
    inblk && /^$/ { if (hit) exit }
  ' "${PKG_INDEX}"
}

META="$(read_meta || true)"
[ -n "${META}" ] || die "docker-model-plugin ${DMR_VERSION} not found in the Packages index (${DMR_ARCH}/${DMR_UBUNTU})"

APT_SHA256="$(echo "${META}" | awk '$1=="SHA256:"{print $2}')"
APT_SIZE="$(echo   "${META}" | awk '$1=="Size:"{print $2}')"
APT_FILE="$(echo   "${META}" | awk '$1=="Filename:"{print $2}')"

[ -n "${APT_SHA256}" ] || die "could not parse SHA256 from the Packages index"
log "apt index: SHA256=${APT_SHA256:0:16}...  Size=${APT_SIZE}"

DEB_URL="https://download.docker.com/linux/ubuntu/${APT_FILE}"
DEB_PATH="${PKG_DIR}/${DEB_NAME}"

# ---------------------------------------------------------------- 2. download + verify
if [ -f "${DEB_PATH}" ] && [ "$(sha256_of "${DEB_PATH}")" = "${APT_SHA256}" ]; then
  log "reusing cached deb (SHA256 already verified): ${DEB_NAME}"
else
  log "downloading ${DEB_NAME} ..."
  curl -fsSL --retry 3 --max-time 300 "${DEB_URL}" -o "${DEB_PATH}.part" \
    || die "download failed: ${DEB_URL}"
  mv "${DEB_PATH}.part" "${DEB_PATH}"
fi

ACTUAL_SHA256="$(sha256_of "${DEB_PATH}")"
if [ "${ACTUAL_SHA256}" != "${APT_SHA256}" ]; then
  die "SHA256 verification failed!
  expected: ${APT_SHA256}
  actual:   ${ACTUAL_SHA256}"
fi
log "SHA256 verified"

# ---------------------------------------------------------------- 3. unpack the ar archive
EXTRACT_DIR="${WORK_DIR}/extract"
rm -rf "${EXTRACT_DIR}"
mkdir -p "${EXTRACT_DIR}"

log "parsing the ar container and extracting data.tar.xz ..."
if command -v python3 >/dev/null 2>&1; then
  python3 - "${DEB_PATH}" "${EXTRACT_DIR}" <<'PY'
import sys, os
deb, outdir = sys.argv[1], sys.argv[2]
data = open(deb, 'rb').read()
if data[:8] != b'!<arch>\n':
    sys.exit('not an ar archive')
off, got = 8, {}
while off < len(data):
    hdr = data[off:off+60]
    if len(hdr) < 60:
        break
    name = hdr[0:16].decode(errors='ignore').strip().rstrip('/')
    try:
        size = int(hdr[48:58].decode().strip())
    except ValueError:
        sys.exit('failed to parse the ar header')
    got[name] = data[off+60:off+60+size]
    off += 60 + size + (size % 2)
for key in ('data.tar.xz', 'data.tar.gz', 'data.tar'):
    if key in got:
        open(os.path.join(outdir, key), 'wb').write(got[key])
        print(key)
        break
else:
    sys.exit('no data.tar.* member found')
PY
else
  warn "python3 not found, falling back to pure shell parsing (data.tar.xz only, fixed offsets)"
  # The ar header is always 60 bytes; walk the members with dd
  OFFSET=8
  TOTAL=$(wc -c < "${DEB_PATH}" | tr -d ' ')
  FOUND=""
  while [ "${OFFSET}" -lt "${TOTAL}" ]; do
    NAME=$(dd if="${DEB_PATH}" bs=1 skip="${OFFSET}" count=16 2>/dev/null | tr -d ' \0' | sed 's#/$##')
    SIZE=$(dd if="${DEB_PATH}" bs=1 skip=$((OFFSET + 48)) count=10 2>/dev/null | tr -d ' ')
    case "${SIZE}" in ''|*[!0-9]*) break;; esac
    if [ "${NAME}" = "data.tar.xz" ]; then
      dd if="${DEB_PATH}" bs=1 skip=$((OFFSET + 60)) count="${SIZE}" of="${EXTRACT_DIR}/data.tar.xz" 2>/dev/null
      FOUND="data.tar.xz"; break
    fi
    OFFSET=$((OFFSET + 60 + SIZE + (SIZE % 2)))
  done
  [ -n "${FOUND}" ] || die "pure shell parsing found no data.tar.xz"
fi

DATA_TAR="$(ls "${EXTRACT_DIR}"/data.tar.* 2>/dev/null | head -1)"
[ -n "${DATA_TAR}" ] || die "unpack failed: no data.tar.* produced"

tar -xf "${DATA_TAR}" -C "${EXTRACT_DIR}"
BIN_PATH="${EXTRACT_DIR}/usr/libexec/docker/cli-plugins/docker-model"
[ -f "${BIN_PATH}" ] || die "usr/libexec/docker/cli-plugins/docker-model not found inside the deb"

chmod 0755 "${BIN_PATH}"
BIN_SIZE=$(wc -c < "${BIN_PATH}" | tr -d ' ')
log "extracted binary: ${BIN_SIZE} bytes"

# ---------------------------------------------------------------- 4. pack the Slackware txz
STAGE="${WORK_DIR}/stage"
rm -rf "${STAGE}"
mkdir -p "${STAGE}/usr/libexec/docker/cli-plugins" "${STAGE}/install"
cp "${BIN_PATH}" "${STAGE}/usr/libexec/docker/cli-plugins/docker-model"
chmod 0755 "${STAGE}/usr/libexec/docker/cli-plugins/docker-model"

cat > "${STAGE}/install/slack-desc" <<EOF
# HOW TO EDIT THIS FILE:
# The "handy ruler" below makes it easier to edit a package description.
${PLUGIN_NAME}: ${PLUGIN_NAME} (Docker Model Runner CLI plugin for Unraid)
${PLUGIN_NAME}:
${PLUGIN_NAME}: Provides the 'docker model' subcommand for the Docker CLI.
${PLUGIN_NAME}: Docker Model Runner lets you pull and run AI models locally
${PLUGIN_NAME}: and serve them via OpenAI- and Ollama-compatible APIs.
${PLUGIN_NAME}:
${PLUGIN_NAME}: Upstream: https://github.com/docker/model-runner
${PLUGIN_NAME}: Packaged from the official Docker apt artifact.
${PLUGIN_NAME}:
${PLUGIN_NAME}:
EOF

log "packing ${TXZ_NAME} ..."

# ---- Reproducible build -------------------------------------------------
# Two independent sources of nondeterminism have to be removed:
#
#  1. mtimes -- bsdtar has no --mtime (GNU tar does), so normalise with touch.
#  2. macOS extended attributes -- bsdtar (libarchive) writes them as
#     LIBARCHIVE.xattr.* pax extended headers, and a file copied out of a git
#     clone or downloaded via a browser carries com.apple.provenance /
#     com.apple.quarantine. That makes the txz hash depend on where the tree
#     came from. Clear them before packing.
#
# Without both, the "reproducible build" claim in the README does not hold.
REPRO_MTIME="202001010000"

if command -v xattr >/dev/null 2>&1; then
  # macOS: drop every extended attribute under the staging tree
  xattr -rc "${STAGE}" 2>/dev/null || true
fi

# GNU tar (Linux, CI) refuses to pack when --no-xattrs is unknown, so probe first
TAR_XATTR_FLAG=""
if tar --no-xattrs -cf /dev/null --files-from /dev/null 2>/dev/null; then
  TAR_XATTR_FLAG="--no-xattrs"
fi

# Normalise mtimes last: xattr and touch both perturb ctime but tar only
# records mtime, and touch must win so the recorded value is fixed.
find "${STAGE}" -exec touch -t "${REPRO_MTIME}" {} +

# --owner/--group 0 makes the extracted files root-owned on Unraid.
# Both BSD tar and GNU tar accept these flags.
( cd "${STAGE}" && tar ${TAR_XATTR_FLAG} --owner=0 --group=0 --numeric-owner -cJf "${DIST_DIR}/${TXZ_NAME}" . )

TXZ_SHA256="$(sha256_of "${DIST_DIR}/${TXZ_NAME}")"
TXZ_SIZE=$(wc -c < "${DIST_DIR}/${TXZ_NAME}" | tr -d ' ')
log "txz: ${TXZ_SIZE} bytes  SHA256=${TXZ_SHA256:0:16}..."

# ---------------------------------------------------------------- 5. render the .plg
log "rendering ${PLUGIN_NAME}.plg ..."
PLG_TEMPLATE="${REPO_ROOT}/plugin/${PLUGIN_NAME}.plg.in"
[ -f "${PLG_TEMPLATE}" ] || die "template not found: ${PLG_TEMPLATE}"

# Substitute placeholders with python to avoid sed -i differences
# between BSD and GNU userlands
python3 - "${PLG_TEMPLATE}" "${DIST_DIR}/${PLUGIN_NAME}.plg" \
  "${DMR_VERSION}" "${TXZ_NAME}" "${TXZ_SHA256}" "${TXZ_SIZE}" "${SLACK_ARCH}" "${PLUGIN_NAME}" <<'PY'
import sys
tmpl, out, ver, txz, sha, size, arch, name = sys.argv[1:9]
text = open(tmpl, encoding='utf-8').read()
repl = {
    '@@VERSION@@': ver,
    '@@TXZ_NAME@@': txz,
    '@@TXZ_SHA256@@': sha,
    # Not referenced by the current template, but kept available so a
    # template can use them without touching the build script.
    '@@TXZ_SIZE@@': size,
    '@@ARCH@@': arch,
    '@@PLUGIN_NAME@@': name,
}
for k, v in repl.items():
    text = text.replace(k, v)
open(out, 'w', encoding='utf-8').write(text)
print('written', out)
PY

log "done"
echo
echo "  output directory: ${DIST_DIR}"
ls -la "${DIST_DIR}"
echo
echo "  install on Unraid with:"
echo "    cp dist/${PLUGIN_NAME}.plg /boot/config/plugins/"
echo "    plugin install /boot/config/plugins/${PLUGIN_NAME}.plg"
