# unraid-plugin-docker-model

[![build](https://github.com/zhz8888/unraid-plugin-docker-model/actions/workflows/build.yml/badge.svg)](https://github.com/zhz8888/unraid-plugin-docker-model/actions/workflows/build.yml)
[![license: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![unraid](https://img.shields.io/badge/unraid-7.0%2B-orange.svg)](https://unraid.net)

**Bring `docker model` (Docker Model Runner) to Unraid OS.**

Unraid does not ship the `docker model` CLI plugin. This project packages it as a
native Unraid plugin (`.plg`) so you can pull and serve AI models locally on your
Unraid server with a single `plugin install`.

> [中文说明](README.zh-CN.md)

---

## The problem

`docker model` is **not** a built-in Docker Engine command. It ships as a separate
CLI plugin package, `docker-model-plugin`, which `docker-ce-cli` merely *suggests*
(not depends on).

Unraid's docker package (`docker-29.x-x86_64-1_LT`, repackaged by Limetech as a
Slackware txz) bundles only two CLI plugins:

```
/usr/libexec/docker/cli-plugins/docker-buildx
/usr/lib/docker/cli-plugins/docker-compose
```

No `docker-model`. Docker itself distributes the plugin only through distro
package managers (apt/yum) and publishes **no binary on GitHub Releases**, so
there is nothing for Unraid's package format to consume directly.

This plugin bridges that gap.

## What it does

```
┌─────────────────────────────────────────────────────────┐
│  docker CLI  (shipped with Unraid, /usr/bin/docker)     │
│    └── cli-plugins/docker-model   <- installed by this   │
│           │                          plugin              │
│           │ docker model install-runner                  │
│           ▼                                              │
│  container docker-model-runner  (docker/model-runner)    │
│    ├── llama.cpp backend (CPU / CUDA / ROCm / Vulkan)    │
│    ├── volume docker-model-runner-models -> /models      │
│    └── listening on 127.0.0.1:12434                      │
└─────────────────────────────────────────────────────────┘
```

The plugin installs the CLI binary into `/usr/libexec/docker/cli-plugins/` and
re-deploys it on every boot (Unraid's `/usr` is an overlay filesystem, so
anything installed there is lost on reboot). The runner container itself is
pulled on demand with `docker model install-runner`.

## Install

### From a release

```bash
# on your Unraid host
cd /boot/config/plugins
wget -O unraid-plugin-docker-model.plg \
  https://github.com/zhz8888/unraid-plugin-docker-model/releases/latest/download/unraid-plugin-docker-model.plg
plugin install /boot/config/plugins/unraid-plugin-docker-model.plg
```

Or install it from **Plugins → Install Plugin** in the Unraid web UI using the
same `.plg` URL.

Verify:

```bash
docker model version
# Client:
#  Version:    v1.2.9
# Server:
#  Version:    v1.2.9
```

### Then deploy the runner

```bash
# CPU only
docker model install-runner --gpu none

# with an NVIDIA / AMD GPU
docker model install-runner --gpu auto
```

Pull a model and run it:

```bash
docker model pull ai/smollm2:360M-Q4_K_M
docker model run ai/smollm2:360M-Q4_K_M "Hello"
```

## Build from source

No Go or Rust toolchain is required — this repo never compiles upstream code.

Requirements: Bash 4+, `curl`, `tar`, `xz`, Python 3.

```bash
git clone https://github.com/zhz8888/unraid-plugin-docker-model.git
cd unraid-plugin-docker-model

make build     # download -> verify SHA256 -> unpack -> txz -> plg
make lint      # static checks on the generated .plg
make verify    # hash and layout verification

ls dist/
# unraid-plugin-docker-model-1.2.9-x86_64-1.txz   <- Slackware package
# unraid-plugin-docker-model.plg                  <- Unraid plugin definition
```

Override the bundled version or architecture:

```bash
DMR_VERSION=1.2.6 make build
DMR_ARCH=arm64    make build
```

The build is **reproducible**: mtimes are normalised and macOS extended
attributes are stripped before packing, so the same upstream version produces
the same txz SHA256 on any machine and from any checkout location. CI enforces
this by building twice and comparing hashes.

## How the binary is sourced

This project redistributes the `docker-model` binary **unmodified** from Docker's
official apt artifact.

```
download.docker.com Packages index
        │  (SHA256 / Size / Filename)
        ▼
   curl the deb  ──►  byte-for-byte SHA256 verification
        │
        ▼
   pure-Python ar parse  ──►  data.tar.xz  ──►  usr/libexec/docker/cli-plugins/docker-model
        │
        ▼
   Slackware txz  ──►  .plg with the txz SHA256 baked in
```

Why the apt artifact rather than a source build? See
[`docs/01-design-decisions.md`](docs/01-design-decisions.md) for the full
evaluation. The short version:

| | apt artifact | source build |
|---|---|---|
| Official binary to compare against | yes (apt metadata) | **none** (no GitHub release assets) |
| Version string correct | `v1.2.9` | degrades to `dev` without manual `-ldflags` |
| Build time | ~2 s | ~2 min (860 MB of Go deps) |
| Supply chain | apt `InRelease` GPG + `Packages` SHA256 | 394 `go.sum` entries |
| Customisable | no | yes |

The binary is statically linked (`CGO_ENABLED=0`, `-trimpath`), so the Ubuntu
package runs unmodified on Slackware-based Unraid.

## Compatibility

| | Requirement |
|---|---|
| Unraid | 7.0+ (needs `/usr/libexec/docker/cli-plugins/`) |
| Architecture | x86_64 (Unraid is x86_64-only; arm64/armhf packages are also built) |
| Docker | 20.10+ (CLI plugin mechanism) |
| Memory | 8 GB+ free recommended for a 1.5B Q4 model |
| GPU | Optional — works CPU-only with `--gpu none` |

## Known limitations

1. **Nothing installed under `/usr` survives a reboot.** The `.plg` re-runs
   `upgradepkg` at boot to restore the binary.
2. **`/root/.docker` is a symlink to `/boot/config/plugins/dockerMan` (vfat).**
   A plugin placed in `~/.docker/cli-plugins/` loses its execute bit and shadows
   the system directory, causing `permission denied`. Use
   `/usr/libexec/docker/cli-plugins/` only.
3. **`docker model pull` ignores the `registry-mirrors` in `daemon.json`.**
   The runner resolves registries directly. On restricted networks use a fully
   qualified name with a proxy prefix, e.g.
   `docker model pull gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M`.
4. **The runner API is unauthenticated.** It binds to `127.0.0.1` and the docker
   bridge gateway by default. Do not expose port `12434` to untrusted networks.

## Naming conventions

| Where | Name | Note |
|---|---|---|
| Repo | `unraid-plugin-docker-model` | this project |
| Unraid plugin | `unraid-plugin-docker-model` | `<PLUGIN name="...">` |
| `.plg` file | `unraid-plugin-docker-model.plg` | goes in `/boot/config/plugins/` |
| Flash cache dir | `/boot/config/plugins/unraid-plugin-docker-model/` | holds the txz |
| Slackware package | `unraid-plugin-docker-model-<ver>-x86_64-1.txz` | installed by `upgradepkg` |
| Installed binary | `/usr/libexec/docker/cli-plugins/docker-model` | **name is fixed** |
| Runner container | `docker-model-runner` | created by `install-runner` |

The installed binary **must** be called `docker-model`: the docker CLI discovers
plugins by the `docker-<subcommand>` filename convention. Everything else uses the
`unraid-plugin-docker-model` prefix.

## Repository layout

```
.
├── README.md                              # this file
├── README.zh-CN.md                        # Chinese README
├── CHANGELOG.md
├── CONTRIBUTING.md
├── SECURITY.md
├── LICENSE                                # MIT (this project)
├── NOTICE                                 # Apache-2.0 attribution for the bundled binary
├── Makefile                               # build / lint / verify convenience targets
├── docs/
│   ├── README.md                          # docs index
│   ├── 01-design-decisions.md             # apt artifact vs source build
│   ├── 02-technical-design.md             # architecture, install/uninstall flow
│   ├── 03-verification-log.md             # measured evidence
│   └── 04-roadmap.md                      # milestones and TODOs
├── plugin/
│   └── unraid-plugin-docker-model.plg.in  # Unraid plugin template
├── scripts/
│   ├── build-plugin.sh                    # build: deb -> verify -> unpack -> txz -> plg
│   ├── fetch-plugin.sh                    # download + verify + unpack only (audit)
│   ├── lint-plg.sh                        # static checks on the .plg
│   └── verify-release.sh                  # verify hashes and layout
└── .github/
    ├── workflows/build.yml                # CI: build, lint, verify, reproducibility
    ├── workflows/upstream-check.yml       # daily upstream version check
    └── ISSUE_TEMPLATE/
```

## Documentation

Deep-dive documents live in [`docs/`](docs/README.md). They are written in
Chinese; the code, scripts and this README are in English.

- [Design decisions](docs/01-design-decisions.md) — why the apt artifact, not a source build
- [Technical design](docs/02-technical-design.md) — plugin mechanics, install/uninstall flow, registry prefix handling
- [Verification log](docs/03-verification-log.md) — measured evidence from a real Unraid host
- [Roadmap](docs/04-roadmap.md) — milestones and open questions

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Before opening a PR:

```bash
make build && make lint && make verify
```

Bugs in `docker model` itself belong upstream at
[docker/model-runner](https://github.com/docker/model-runner/issues) — this repo
only packages that binary for Unraid.

## License

- This project's scripts and documentation: **MIT** — see [LICENSE](LICENSE).
- The bundled `docker-model` binary: **Apache-2.0**, © Docker, Inc.,
  redistributed unmodified — see [NOTICE](NOTICE).

The `docker/model-runner` container image is not distributed by this project; it
is pulled by the user at runtime.
