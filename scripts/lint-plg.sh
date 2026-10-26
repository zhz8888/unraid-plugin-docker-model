#!/usr/bin/env bash
#
# lint-plg.sh -- Static checks that a .plg satisfies the requirements of the
#                Unraid plugin installer.
#
# Checks:
#   1. XML is well formed and the PLUGIN root carries the required attributes
#   2. Every <FILE> pairs a URL with a SHA256 (otherwise the installer skips
#      the download), and the URL is https
#   3. The INLINE bash scripts under <FILE Run> are syntactically valid (bash -n)
#   4. The install script is idempotent (upgradepkg --install-new / rm -f)
#   5. An uninstall script exists (Method="remove")
#   6. No template placeholder survived the render
#
# Usage:
#   ./scripts/lint-plg.sh              # checks every .plg in dist/
#   ./scripts/lint-plg.sh path/to/plg  # checks the given file(s)
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ $# -gt 0 ]; then
  PLGS=("$@")
else
  PLGS=("${REPO_ROOT}/dist/unraid-plugin-docker-model.plg"
        "${REPO_ROOT}/dist/unraid-plugin-docker-model-proxy.plg")
fi

FAIL=0
log() { printf '\033[1;34m[lint]\033[0m %s\n' "$*"; }
ok()  { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
bad() { printf '\033[1;31m  FAIL\033[0m %s\n' "$*" >&2; FAIL=1; }

TMP=$(mktemp -d)
trap 'rm -rf "${TMP}"' EXIT

for PLG in "${PLGS[@]}"; do
  [ -f "${PLG}" ] || { echo "not found: ${PLG}" >&2; exit 1; }
  log "checking ${PLG}"

  # ---------------------------------------------------------------- 1. XML + attributes
  python3 - "${PLG}" "${TMP}" <<'PY'
import sys, xml.etree.ElementTree as ET

plg, tmp = sys.argv[1], sys.argv[2]
fail = 0

try:
    tree = ET.parse(plg)
except ET.ParseError as e:
    print(f"BADXML {e}")
    sys.exit(1)

root = tree.getroot()
if root.tag != 'PLUGIN':
    print(f"BADROOT {root.tag}")
    fail = 1

# Required attributes
required = ['name', 'author', 'version']
optional_recommended = ['pluginURL', 'support', 'min']
for attr in required:
    if not root.get(attr):
        print(f"MISSING {attr}")
        fail = 1
for attr in optional_recommended:
    if not root.get(attr):
        print(f"WARN_MISSING {attr}")

# Collect FILE elements
files = root.findall('FILE')
print(f"FILE_COUNT {len(files)}")

for i, f in enumerate(files):
    name = f.get('Name') or ''
    url = (f.findtext('URL') or '').strip()
    sha = (f.findtext('SHA256') or '').strip()
    method = f.get('Method') or 'install'
    inline = (f.findtext('INLINE') or '')

    if url and not sha:
        print(f"FILE_NOSHA {i} {name or url}")
        fail = 1
    if sha and len(sha) != 64:
        print(f"FILE_BADSHA {i} {sha}")
        fail = 1
    if url and not url.startswith('https://'):
        print(f"FILE_INSECURE_URL {i} {url}")
        fail = 1

    # Drop INLINE scripts to disk so bash -n can check them
    if inline.strip():
        tag = 'remove' if 'remove' in method else 'install'
        path = f"{tmp}/inline-{i}-{tag}.sh"
        with open(path, 'w') as fh:
            fh.write(inline)
        print(f"INLINE_SCRIPT {path}")

print("OK" if fail == 0 else "FAIL")
PY

  PY_RC=$?
  if [ "${PY_RC}" -ne 0 ]; then
    bad "XML parsing or attribute check failed"
  fi

  # ---------------------------------------------------------------- 2. bash syntax
  log "checking inline script syntax..."
  for s in "${TMP}"/inline-*.sh; do
    [ -f "$s" ] || continue
    if bash -n "$s" 2>"${TMP}/err"; then
      ok "$(basename "$s") syntax OK"
    else
      bad "$(basename "$s") syntax error:"
      sed 's/^/      /' "${TMP}/err"
    fi
    rm -f "$s"
  done

  # ---------------------------------------------------------------- 3. idempotency
  log "checking idempotency..."
  if grep -q "upgradepkg --install-new" "${PLG}"; then
    ok "uses upgradepkg --install-new (idempotent)"
  else
    bad "upgradepkg --install-new not used; repeated runs at boot may fail"
  fi

  if grep -q 'rm -f' "${PLG}"; then
    ok "cleanup uses rm -f (idempotent)"
  fi

  # ---------------------------------------------------------------- 4. uninstall
  log "checking the uninstall script..."
  if grep -q 'Method="remove"' "${PLG}"; then
    ok "uninstall script present"
  else
    bad "no FILE with Method=\"remove\" found"
  fi

  # ---------------------------------------------------------------- 5. placeholders
  log "checking for leftover placeholders..."
  if grep -q '@@VERSION@@\|@@TXZ_NAME@@\|@@TXZ_SHA256@@\|@@PLG_URL@@\|@@TXZ_URL@@\|@@SOURCE_NOTE@@' "${PLG}"; then
    bad "unsubstituted functional placeholders found"
  else
    ok "no functional placeholders left"
  fi

  echo
done

if [ "${FAIL}" -eq 0 ]; then
  log "all checks passed"
  exit 0
else
  printf '\033[1;31m[lint] some checks failed\033[0m\n' >&2
  exit 1
fi
