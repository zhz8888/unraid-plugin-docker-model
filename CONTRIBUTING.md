# Contributing

Thanks for your interest in improving `unraid-plugin-docker-model`.

## What this project is

A thin distribution wrapper. It does **not** patch Docker Model Runner. The
`docker-model` binary is redistributed unmodified from Docker's official apt
artifact, so bugs in `docker model` itself belong upstream at
[docker/model-runner](https://github.com/docker/model-runner/issues).

In scope for this repo:

- the Unraid `.plg` definition and its install/uninstall scripts
- the build, lint and verification scripts
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
./scripts/verify-release.sh   # hash + layout verification
```

## Before opening a pull request

Run all three checks and make sure they pass:

```bash
./scripts/build-plugin.sh
./scripts/lint-plg.sh
./scripts/verify-release.sh
```

The build is **reproducible**: building the same version twice must yield the
same txz SHA256, and it must not depend on *where* the working tree lives.

Two things are normalised for this, and both matter:

- **mtimes** — `bsdtar` has no `--mtime`, so the script `touch`es the whole
  staging tree to a fixed timestamp before packing.
- **extended attributes** — `bsdtar` writes macOS xattrs as
  `LIBARCHIVE.xattr.*` pax headers. A tree cloned from git carries
  `com.apple.provenance`, which would otherwise leak into the archive and make
  the hash depend on the checkout origin. The script clears them with
  `xattr -rc` and passes `--no-xattrs` to tar where supported.

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

The maintainer then tags a release and attaches the txz.

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
