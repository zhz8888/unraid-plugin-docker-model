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
#   5. Assemble the Slackware package from src/ plus the binary, and pack it
#      as a txz (install/slack-desc and install/doinst.sh are required by
#      upgradepkg)
#   6. Render two .plg files from one template, with the txz SHA256 baked in:
#      a direct one and one that downloads through the gh-proxy.org mirror
#
# Usage:
#   ./scripts/build-plugin.sh
#   PLUGIN_VERSION=20261105 ./scripts/build-plugin.sh
#   DMR_VERSION=1.2.9 ./scripts/build-plugin.sh
#   DMR_ARCH=arm64 ./scripts/build-plugin.sh
#   DMR_UBUNTU=noble ./scripts/build-plugin.sh
#
# Version scheme:
#   PLUGIN_VERSION  the version of this plugin. It is the release date,
#                   YYYYMMDD, followed by a letter suffix when more than one
#                   release happens on the same day: YYYYMMDD.a, YYYYMMDD.b
#                   and so on. The Unraid installer compares plugin versions
#                   with strcmp(), a byte wise string compare, so this format
#                   is what sorts in release order:
#                     20261105 < 20261105.a < 20261105.b < 20261106
#                   The first release of a day therefore carries no suffix.
#                   The version must not be tied to the upstream version.
#   DMR_VERSION     the version of the upstream docker-model-plugin release
#                   that is bundled. Recorded in the package description, the
#                   changelog and the WebGUI page.
#
set -euo pipefail

# ---------------------------------------------------------------- config
DMR_VERSION="${DMR_VERSION:-1.2.9}"
DMR_ARCH="${DMR_ARCH:-amd64}"          # amd64 | arm64 | armhf
DMR_UBUNTU="${DMR_UBUNTU:-noble}"      # 22.04=jammy 24.04=noble

# The plugin version: the release date, YYYYMMDD. A second release on the
# same day adds a letter suffix, YYYYMMDD.a, YYYYMMDD.b and so on.
# The date is the local one, because "the day of the release" is what the
# version stands for.
PLUGIN_VERSION="${PLUGIN_VERSION:-$(date +%Y%m%d)}"

# Unraid plugin name (also used as the .plg filename, the flash cache
# directory name, and the Slackware package name)
PLUGIN_NAME="unraid-plugin-docker-model"

# Repository the release assets are published under. The direct .plg and the
# mirrored .plg are both rendered from the same template against this repo.
REPO_SLUG="zhz8888/unraid-plugin-docker-model"

# Mirror for networks that cannot reach github.com directly. The proxy takes
# the whole target URL as its path, so a GitHub release asset becomes
#   https://gh-proxy.org/https://github.com/<owner>/<repo>/releases/...
# The proxy returns the original bytes, so the SHA256 does not change.
GH_PROXY="https://gh-proxy.org/"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PKG_DIR="${REPO_ROOT}/pkg"
DIST_DIR="${REPO_ROOT}/dist"
WORK_DIR="${PKG_DIR}/work"
SRC_DIR="${REPO_ROOT}/src"

# Slackware package naming: <name>-<version>-<arch>-<build>
SLACK_ARCH="$([ "${DMR_ARCH}" = "amd64" ] && echo "x86_64" || echo "${DMR_ARCH}")"
TXZ_NAME="${PLUGIN_NAME}-${PLUGIN_VERSION}-${SLACK_ARCH}-1.txz"

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

# Reject anything that would not sort correctly under strcmp(), the
# comparison the Unraid installer uses for plugin versions.
if ! printf '%s' "${PLUGIN_VERSION}" | grep -Eq '^[0-9]{8}(\.[a-z])?$'; then
  die "invalid plugin version '${PLUGIN_VERSION}'
  expected YYYYMMDD, or YYYYMMDD.a when a day carries more than one release"
fi

# Download a URL with an explicit retry loop.
#
# curl's own --retry only covers transient HTTP errors and timeouts, so a TLS
# handshake failure aborts the build on the first attempt. The loop makes the
# build survive the occasional flaky connection.
#
#   fetch <url> <output> <max-time> [attempts]
fetch() {
  local url="$1" out="$2" max_time="$3" attempts="${4:-4}" attempt=1

  while true; do
    if curl -fsSL --retry 2 --max-time "${max_time}" "$url" -o "${out}"; then
      return 0
    fi
    if [ "${attempt}" -ge "${attempts}" ]; then
      return 1
    fi
    attempt=$((attempt + 1))
    warn "download failed, retrying in 3s (attempt ${attempt}/${attempts})"
    sleep 3
  done
}

need curl
need tar
need xz
need python3

mkdir -p "${PKG_DIR}" "${DIST_DIR}" "${WORK_DIR}"

log "plugin version: ${PLUGIN_VERSION}  upstream: ${DMR_VERSION}  arch: ${DMR_ARCH}  ubuntu base: ${DMR_UBUNTU}"

# ---------------------------------------------------------------- 1. apt metadata
log "fetching the apt Packages index..."
PACKAGES_URL="${APT_BASE}/${APT_DIST}/Packages"
PKG_INDEX="${WORK_DIR}/Packages"
fetch "${PACKAGES_URL}" "${PKG_INDEX}" 120 \
  || die "failed to download the Packages index: ${PACKAGES_URL}"

# Pull the (Filename, Size, SHA256) triple for the requested version.
# Note: the index carries full Debian versions such as
# 1.2.9-1~ubuntu.24.04~noble, while DMR_VERSION is only 1.2.9,
# so match by prefix ("<ver>-")
read_meta() {
  awk -v ver="${DMR_VERSION}" '
    function ver_match(v) { return v == ver || index(v, ver "-") == 1 }
    # Every Package header resets the block, otherwise the fields of the
    # next package are reported when the requested version is missing.
    /^Package: / { inblk = ($2 == "docker-model-plugin"); hit = 0; next }
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
  fetch "${DEB_URL}" "${DEB_PATH}.part" 300 \
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

DATA_TAR="$(ls "${EXTRACT_DIR}"/data.tar.* 2>/dev/null | head -1)"
[ -n "${DATA_TAR}" ] || die "unpack failed: no data.tar.* produced"

tar -xf "${DATA_TAR}" -C "${EXTRACT_DIR}"
BIN_PATH="${EXTRACT_DIR}/usr/libexec/docker/cli-plugins/docker-model"
[ -f "${BIN_PATH}" ] || die "usr/libexec/docker/cli-plugins/docker-model not found inside the deb"

chmod 0755 "${BIN_PATH}"
BIN_SIZE=$(wc -c < "${BIN_PATH}" | tr -d ' ')
BIN_SHA256="$(sha256_of "${BIN_PATH}")"
log "extracted binary: ${BIN_SIZE} bytes  SHA256=${BIN_SHA256:0:16}..."

# ---------------------------------------------------------------- 4. assemble the Slackware package
STAGE="${WORK_DIR}/stage"
rm -rf "${STAGE}"
mkdir -p "${STAGE}"

# The package source tree holds everything that is not the upstream binary:
# the WebGUI page, the package description and the install hook.
[ -d "${SRC_DIR}" ] || die "package source tree not found: ${SRC_DIR}"
tar -cf - -C "${SRC_DIR}" . | tar -xf - -C "${STAGE}"
[ -f "${STAGE}/install/slack-desc" ] || die "install/slack-desc is missing from ${SRC_DIR}"
[ -f "${STAGE}/install/doinst.sh" ] || die "install/doinst.sh is missing from ${SRC_DIR}"
chmod 0755 "${STAGE}/install/doinst.sh"

# The upstream binary keeps its original name: the Docker CLI discovers
# plugins by the docker-<subcommand> filename convention.
mkdir -p "${STAGE}/usr/libexec/docker/cli-plugins"
cp "${BIN_PATH}" "${STAGE}/usr/libexec/docker/cli-plugins/docker-model"
chmod 0755 "${STAGE}/usr/libexec/docker/cli-plugins/docker-model"

# Build metadata for the WebGUI page. It is generated here so that it always
# matches the package it ships with.
PLUGIN_DIR_REL="usr/local/emhttp/plugins/${PLUGIN_NAME}"
mkdir -p "${STAGE}/${PLUGIN_DIR_REL}"
cat > "${STAGE}/${PLUGIN_DIR_REL}/plugin.info" <<EOF
; Generated by scripts/build-plugin.sh -- do not edit.
; Every value is double-quoted: the upstream artifact name contains "~",
; which the PHP INI scanner would otherwise read as a bitwise negation
; operator and reject the whole file.
PLUGIN_NAME="${PLUGIN_NAME}"
PLUGIN_VERSION="${PLUGIN_VERSION}"
UPSTREAM_VERSION="${DMR_VERSION}"
UPSTREAM_SOURCE="${DEB_NAME}"
BINARY_SHA256="${BIN_SHA256}"
EOF

# The page description mentions the bundled upstream version.
if [ -f "${STAGE}/${PLUGIN_DIR_REL}/README.md" ]; then
  python3 - "${STAGE}/${PLUGIN_DIR_REL}/README.md" "${PLUGIN_VERSION}" "${DMR_VERSION}" <<'PY'
import sys
path, plugin_version, upstream_version = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(path, encoding='utf-8').read()
text = text.replace('@@PLUGIN_VERSION@@', plugin_version)
text = text.replace('@@UPSTREAM_VERSION@@', upstream_version)
open(path, 'w', encoding='utf-8', newline='\n').write(text)
PY
fi

log "packing ${TXZ_NAME} ..."

# Drop packages of previous builds so that dist/ only ever holds the
# artifacts of the current version. This runs after the build succeeded, so
# a failed build never destroys the artifacts of the previous one.
rm -f "${DIST_DIR}/${PLUGIN_NAME}-"*.txz 2>/dev/null || true

# ---- Reproducible build -------------------------------------------------
# The archive must be byte-identical on every platform, not merely repeatable
# on one machine. Shelling out to `tar` cannot deliver that, because tar
# implementations disagree even when told to emit the same format:
#
#   field      GNU tar (Linux/CI)        bsdtar (macOS)
#   ---------  ------------------------  ------------------------
#   mode       "0000755\0"               "000755 \0"
#   uname      real user name            empty
#   order      readdir order             readdir order (differs)
#   xattrs     n/a                        LIBARCHIVE.xattr.* pax headers
#
# Two earlier attempts -- touch-based mtime normalisation, then xattr clearing
# plus --format=ustar and a sorted member list -- each fixed one dimension and
# still produced different hashes across OSes (Linux 0d545fb9 vs macOS
# 6cadda84).
#
# So the archive is now written by Python's tarfile module instead. It is a
# single implementation that ships with the interpreter, so macOS and Linux
# emit identical bytes by construction, and every header field is set
# explicitly rather than inherited from the platform tool.
python3 - "${STAGE}" "${DIST_DIR}/${TXZ_NAME}" <<'PY'
import hashlib
import io
import lzma
import os
import sys
import tarfile

stage, out_path = sys.argv[1], sys.argv[2]

# 2020-01-01T00:00:00Z -- fixed timestamp so mtime never varies
FIXED_MTIME = 1577836800

def collect(root):
    """Return [(relpath, realpath)] sorted with a stable C-style order."""
    entries = []
    for dirpath, dirnames, filenames in os.walk(root):
        rel_dir = os.path.relpath(dirpath, root)
        if rel_dir == '.':
            rel_dir = ''
        for name in dirnames + filenames:
            rel = os.path.join(rel_dir, name) if rel_dir else name
            entries.append(rel)
    # sort by path components so the order is locale-independent and matches
    # what a directory-first walk would produce
    entries.sort(key=lambda p: p.split(os.sep))
    return entries

def make_tarinfo(rel, realpath):
    st = os.lstat(realpath)
    ti = tarfile.TarInfo('./' + rel if not rel.startswith('.') else rel)
    ti.mtime = FIXED_MTIME
    ti.uid = 0
    ti.gid = 0
    ti.uname = ''
    ti.gname = ''
    if os.path.isdir(realpath):
        ti.type = tarfile.DIRTYPE
        ti.mode = 0o755
        ti.size = 0
    elif os.path.islink(realpath):
        ti.type = tarfile.SYMTYPE
        ti.mode = 0o755
        ti.linkname = os.readlink(realpath)
        ti.size = 0
    else:
        ti.type = tarfile.REGTYPE
        # keep the executable bit for scripts and binaries, drop the rest
        ti.mode = 0o755 if st.st_mode & 0o111 else 0o644
        ti.size = st.st_size
    return ti

raw = io.BytesIO()
with tarfile.open(fileobj=raw, mode='w', format=tarfile.USTAR_FORMAT) as tf:
    # root entry first, matching the previous archive layout
    root_ti = tarfile.TarInfo('.')
    root_ti.type = tarfile.DIRTYPE
    root_ti.mode = 0o755
    root_ti.mtime = FIXED_MTIME
    root_ti.uid = root_ti.gid = 0
    root_ti.uname = root_ti.gname = ''
    tf.addfile(root_ti)

    for rel in collect(stage):
        realpath = os.path.join(stage, rel)
        ti = make_tarinfo(rel, realpath)
        if ti.type == tarfile.REGTYPE:
            with open(realpath, 'rb') as fh:
                tf.addfile(ti, fh)
        else:
            tf.addfile(ti)

data = raw.getvalue()
# preset 6 with a fixed filter keeps the xz stream deterministic
compressed = lzma.compress(data, format=lzma.FORMAT_XZ, preset=6)
with open(out_path, 'wb') as fh:
    fh.write(compressed)

print('sha256', hashlib.sha256(compressed).hexdigest())
print('bytes', len(compressed))
PY

TXZ_SHA256="$(sha256_of "${DIST_DIR}/${TXZ_NAME}")"
TXZ_SIZE=$(wc -c < "${DIST_DIR}/${TXZ_NAME}" | tr -d ' ')
log "txz: ${TXZ_SIZE} bytes  SHA256=${TXZ_SHA256:0:16}..."

# ---------------------------------------------------------------- 5. render the .plg files
log "rendering ${PLUGIN_NAME}.plg and ${PLUGIN_NAME}-proxy.plg ..."
PLG_TEMPLATE="${REPO_ROOT}/plugin/${PLUGIN_NAME}.plg.in"
[ -f "${PLG_TEMPLATE}" ] || die "template not found: ${PLG_TEMPLATE}"

# The <CHANGES> body is taken from CHANGELOG.md so that every release shows
# its own notes instead of the notes that happened to be current when the
# template was written.
CHANGES_BODY="$(python3 - "${REPO_ROOT}/CHANGELOG.md" "${PLUGIN_VERSION}" <<'PY'
import re
import sys

changelog, version = sys.argv[1], sys.argv[2]
fallback = '- Release %s. See CHANGELOG.md for the full notes.' % version

try:
    text = open(changelog, encoding='utf-8').read()
except OSError:
    print(fallback)
    sys.exit(0)

match = re.search(r'^## \[' + re.escape(version) + r'\][^\n]*\n(.*?)(?=^## |\Z)',
                  text, re.S | re.M)
body = match.group(1).strip() if match else ''
body = re.sub(r'\n{3,}', '\n\n', body)
# The body is written into a CDATA section: a literal "]]>" would end it.
body = body.replace(']]>', ']]]]><![CDATA[>')
print(body or fallback)
PY
)"

RELEASE_BASE="https://github.com/${REPO_SLUG}/releases"
DIRECT_TXZ_URL="${RELEASE_BASE}/download/${PLUGIN_VERSION}/${TXZ_NAME}"
DIRECT_PLG_URL="${RELEASE_BASE}/latest/download/${PLUGIN_NAME}.plg"
PROXY_TXZ_URL="${GH_PROXY}${DIRECT_TXZ_URL}"
PROXY_PLG_URL="${GH_PROXY}${RELEASE_BASE}/latest/download/${PLUGIN_NAME}-proxy.plg"

# Substitute placeholders with python to avoid sed -i differences
# between BSD and GNU userlands
render_plg() {
  local out="$1" plg_url="$2" txz_url="$3" note="$4" sibling_cleanup="$5"
  python3 - "${PLG_TEMPLATE}" "${out}" \
    "${PLUGIN_VERSION}" "${DMR_VERSION}" "${TXZ_NAME}" "${TXZ_SHA256}" "${BIN_SHA256}" \
    "${plg_url}" "${txz_url}" "${note}" "${sibling_cleanup}" "${CHANGES_BODY}" <<'PY'
import sys
tmpl, out, plugin_version, upstream_version, txz, sha, binary_sha, plg_url, txz_url, note, sibling_cleanup, changes_body = sys.argv[1:13]
text = open(tmpl, encoding='utf-8').read()
repl = {
    '@@PLUGIN_NAME@@': 'unraid-plugin-docker-model',
    '@@PLUGIN_VERSION@@': plugin_version,
    '@@UPSTREAM_VERSION@@': upstream_version,
    '@@TXZ_NAME@@': txz,
    '@@TXZ_SHA256@@': sha,
    '@@BINARY_SHA256@@': binary_sha,
    '@@PLG_URL@@': plg_url,
    '@@TXZ_URL@@': txz_url,
    '@@SOURCE_NOTE@@': note,
    '@@SIBLING_CLEANUP@@': sibling_cleanup,
    '@@CHANGES_BODY@@': changes_body,
}
for k, v in repl.items():
    text = text.replace(k, v)
# newline='\n' is required, not cosmetic: the <INLINE> scripts are bash and a
# CRLF checkout would turn every "for ... \" continuation into a syntax error
# on the Unraid host.
open(out, 'w', encoding='utf-8', newline='\n').write(text)
print('written', out)
PY
}

# The canonical variant removes the mirrored one. The installer registers one
# symlink per .plg file name, so both files being present would show up as
# two entries in the Plugins tab and would install the same package twice on
# every boot. The mirrored variant does not remove the canonical one: the
# canonical variant sorts later, so it is always the one that wins.
SIBLING_CLEANUP="rm -f /boot/config/plugins/${PLUGIN_NAME}-proxy.plg
rm -f /var/log/plugins/${PLUGIN_NAME}-proxy.plg"

render_plg "${DIST_DIR}/${PLUGIN_NAME}.plg" \
  "${DIRECT_PLG_URL}" "${DIRECT_TXZ_URL}" \
  "The plugin package is downloaded from the GitHub Release assets directly." \
  "${SIBLING_CLEANUP}"

render_plg "${DIST_DIR}/${PLUGIN_NAME}-proxy.plg" \
  "${PROXY_PLG_URL}" "${PROXY_TXZ_URL}" \
  "The plugin package is downloaded from the GitHub Release assets through the gh-proxy.org mirror, for networks that cannot reach github.com directly." \
  "# The mirrored variant keeps the canonical one in place: only one of the
# two .plg files may be installed, and the canonical one removes this file
# on the next boot if both are present."

log "done"
echo
echo "  output directory: ${DIST_DIR}"
ls -la "${DIST_DIR}"
echo
echo "  install on Unraid with:"
echo "    cp dist/${PLUGIN_NAME}.plg /boot/config/plugins/"
echo "    plugin install /boot/config/plugins/${PLUGIN_NAME}.plg"
echo
echo "  or, on a network that cannot reach github.com:"
echo "    cp dist/${PLUGIN_NAME}-proxy.plg /boot/config/plugins/"
echo "    plugin install /boot/config/plugins/${PLUGIN_NAME}-proxy.plg"
