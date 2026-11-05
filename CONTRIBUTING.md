# Contributing

Thanks for your interest in improving `unraid-plugin-docker-model`.

## What this project is

A thin distribution wrapper. It does **not** patch Docker Model Runner. The
`docker-model` binary is redistributed unmodified from Docker's official apt
artifact, so bugs in `docker model` itself belong upstream at
[docker/model-runner](https://github.com/docker/model-runner/issues).

In scope for this repo:

- the Unraid `.plg` definition and its install/uninstall scripts
- the Slackware package source tree under `src/` (package description, install
  hook, WebGUI page)
- the build, lint and verification scripts
- the Community Applications submission files
- documentation

## Development setup

Requirements:

- Bash 4+
- `curl`, `tar`, `xz`
- Python 3 (used to parse the ar container and render the `.plg`)
- Optional: `file`, and Go (only to inspect binaries with `go version -m`)

No toolchain for the upstream project is needed; this repo never compiles Go.

```bash
git clone https://github.com/zhz8888/unraid-plugin-docker-model.git
cd unraid-plugin-docker-model

./scripts/build-plugin.sh     # build dist/
./scripts/lint-plg.sh         # static checks on the generated .plg
./scripts/check-php-syntax.py src/   # syntax check on the WebGUI sources
./scripts/verify-release.sh   # hash + layout verification
```

## Version scheme

Two versions are tracked independently:

| Variable | Format | Meaning |
|---|---|---|
| `PLUGIN_VERSION` | `YYYY.MM.DD.HHMM` | This plugin's own release version. Defaults to the current UTC time; pin it for a reproducible build. |
| `DMR_VERSION` | `1.2.9` | The bundled `docker-model-plugin` release. |

The Unraid installer compares plugin versions with `strcmp()`, a byte-wise
string compare, so the plugin version has to sort lexicographically. A
fixed-width date does; a semantic version does not — `1.2.10` sorts *below*
`1.2.9`, and Unraid would refuse the upgrade. The plugin version must also be
independent of the upstream version, otherwise a repackaging of the same
upstream release never reaches the users who already installed it.

## Before opening a pull request

Run all three checks and make sure they pass:

```bash
./scripts/build-plugin.sh
./scripts/lint-plg.sh
./scripts/verify-release.sh
```

The build assembles the Slackware package from `src/` plus the upstream binary
and produces **two** plugin files from the single template
`plugin/unraid-plugin-docker-model.plg.in`:

- `unraid-plugin-docker-model.plg` — downloads from the GitHub release assets
- `unraid-plugin-docker-model-proxy.plg` — downloads the same asset through
  the `https://gh-proxy.org/` mirror, for networks that cannot reach GitHub

Only the plugin URL, the download URL and the source note differ between them;
the package, its SHA256 and the install scripts are identical. `lint-plg.sh`
checks both files, and `verify-release.sh` asserts that they reference the same
package with the same checksum.

> Install **one** of the two files, never both: the Unraid installer registers
> one symlink per `.plg` *file name*, so two files would show up as two entries
> in the Plugins tab. The canonical variant removes the mirrored one on the
> next boot.

The build is **reproducible**: building the same version twice must yield the
same txz SHA256, and it must not depend on *where* the working tree lives.

The archive is written by Python's `tarfile` module rather than by shelling out
to `tar`, because tar implementations disagree even when told to emit the same
format — GNU tar and bsdtar differ in the `mode` and `uname` header fields, in
member order (which follows `readdir`) and in how they write macOS extended
attributes. Using one implementation removes the whole class of problems: the
script sets every header field explicitly (uid/gid 0, empty uname/gname, fixed
mtime, 0755 for directories and executables, 0644 otherwise) and sorts the
member list, so Linux and macOS produce identical bytes by construction.

If your change alters the txz hash for an unchanged upstream version, check
staging determinism rather than bumping a checksum. Verify with:

```bash
git clone . /tmp/rebuild-check && (cd /tmp/rebuild-check && make build)
sha256sum dist/*.txz /tmp/rebuild-check/dist/*.txz   # must match
```

## Coding conventions

- Shell scripts: `#!/usr/bin/env bash` with `set -euo pipefail`
- All comments, log messages and user-facing output in **English**
- Keep scripts POSIX-friendly where practical, but bash is the target
- Prefer `command -v x` over `which x`
- Quote all variable expansions
- Do not hardcode hostnames, IP addresses or personal paths

## Updating the bundled version

1. Find the new upstream version:
   `curl -fsSL https://download.docker.com/linux/ubuntu/dists/noble/stable/binary-amd64/Packages | grep -A2 '^Package: docker-model-plugin'`
2. Build it: `DMR_VERSION=<new-version> ./scripts/build-plugin.sh`
3. Run the three checks above
4. Update `CHANGELOG.md`
5. Open a PR titled `chore: bump docker-model-plugin to <version>`

The maintainer then tags a release and attaches the txz. The **tag is the
plugin version**, because the `.plg` points at the release asset under that
tag.

## Rules the plugin scripts have to follow

These come from the Unraid plugin installer itself and are enforced by
`scripts/lint-plg.sh`:

- The install script must be **idempotent** — it runs again on every boot.
- The install script must **never exit non-zero**. A non-zero exit aborts the
  install, moves the `.plg` to `/boot/config/plugins-error/` and leaves the
  plugin unregistered, so the user cannot uninstall it from the WebGUI.
- The install script must not prompt for input; the installer runs it
  non-interactively.
- The remove script must clean up the package, the binaries and the flash
  cache — but must not delete user data such as the runner container, its
  image or the model volume.
- Every `<FILE>` with a `<URL>` needs a `<SHA256>`, and the cached file name
  has to contain the version, because the installer never overwrites a file
  that already exists.
- XML entities (`&name;`) are only expanded in attribute values and element
  text. Inside a CDATA section — which is where the `<CHANGES>` block and the
  `<INLINE>` scripts live — they stay literal, so use the build time
  placeholders (`@@NAME@@`) there instead. `scripts/lint-plg.sh` fails the
  build if an entity reference ends up inside a CDATA section.

See [`docs/05-unraid-plugin-conventions.md`](docs/05-unraid-plugin-conventions.md)
for the full mapping.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/) are preferred.
Commit messages are written in **English**, matching the language of the code
and of the user-facing output.

```
feat: add arm64 build support
fix: handle missing install/slack-desc
docs: clarify gh-proxy usage
chore: bump docker-model-plugin to 1.2.10
```

## Reporting issues

Please include:

- Unraid version (`cat /etc/unraid-version`)
- Docker version (`docker version --format '{{.Server.Version}}'`)
- Plugin version (`docker model version`)
- The exact command and its full output

## License

By contributing you agree that your contributions are licensed under the MIT
License (see `LICENSE`).
