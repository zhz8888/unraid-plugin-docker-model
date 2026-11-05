# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Since the 2026.11.05 release the **plugin version** is date based
(`YYYY.MM.DD.HHMM`), the same scheme the official Limetech plugins use. The
Unraid installer compares plugin versions with `strcmp()`, a byte-wise string
compare, so the version has to sort lexicographically; a semantic version such
as `1.2.10` sorts *below* `1.2.9` and Unraid would refuse the upgrade.

The **upstream version** (the bundled `docker-model-plugin` release) is tracked
separately and is recorded in the plugin changelog, the package description and
the WebGUI page.

## [Unreleased]

### Added

- WebGUI page under `Settings → Docker Model Runner`: it reports the bundled
  CLI version, the Model Runner state and the locally available models, and can
  deploy or remove the Model Runner
- Plugin icon and a `README.md` in the plugin directory, so the Plugins tab
  shows a real icon and a real description
- `src/` — the Slackware package source tree (package description, install
  hook, WebGUI page), assembled into the txz by the build
- `ca_profile.xml` and `ca/unraid-plugin-docker-model.xml` — the files the
  Community Applications submission needs
- `scripts/make-icon.py` — regenerates the plugin icon
- `docs/05-unraid-plugin-conventions.md` — rule by rule mapping of the
  official Unraid plugin rules onto this plugin

### Fixed

- The plugin version is no longer tied to the upstream version. It used to be,
  which meant that Unraid compared `1.2.9` with `1.2.10` byte-wise, judged the
  new release to be *older* and refused the upgrade. The version is now date
  based, and the upstream version is recorded separately.
- The install script no longer exits non-zero on a recoverable error. The
  Unraid installer moves a `.plg` to `/boot/config/plugins-error/` when the
  install script fails, and does not register the plugin, so the user was left
  without an Uninstall button in the WebGUI and without a retry at the next
  boot.
- The install script now removes stale copies of the binary from every other
  directory the Docker CLI searches. The first match wins, so a leftover copy
  shadowed the installed one.
- The install script now removes superseded packages from the flash cache,
  which previously grew with every release.

### Changed

- The `.plg` now uses XML entities and the attribute set the Unraid installer
  and the WebGUI actually read: `name`, `author`, `version`, `pluginURL`,
  `support`, `min`, `launch` and `icon`.
- The Slackware package version is the plugin version, so `upgradepkg` sees an
  upgrade when the plugin is repackaged.
- The mirrored `.plg` no longer coexists with the canonical one: the canonical
  variant removes it on the next boot, the way the official Tailscale plugin
  removes its preview build.
- `lint-plg.sh` checks the official conventions: required attributes, the
  version scheme, the changelog layout, SHA256 on every download, the install
  and remove scripts, and the absence of a non-zero exit.
- `verify-release.sh` checks the package layout, the build metadata, the
  binary checksum pinned in the `.plg`, and that the `launch` target resolves
  to a page inside the package.
- `scripts/check-php-syntax.py` checks the WebGUI sources for unbalanced
  braces and unterminated strings, so a typo in the page does not break the
  Settings tab.
- The build retries the downloads in an explicit loop. `curl --retry` does not
  cover TLS handshake failures, so a single flaky connection used to abort the
  build.

## [1.2.9] - 2026-10-27

### Added

- Initial release
- Bundles the `docker model` CLI plugin from Docker's official apt artifact
  `docker-model-plugin_1.2.9-1~ubuntu.24.04~noble_amd64`
- Unraid `.plg` definition that installs the plugin into
  `/usr/libexec/docker/cli-plugins/` via `upgradepkg` and re-deploys it on boot
- Build pipeline that downloads the deb, verifies its SHA256 against the
  download.docker.com `Packages` index, unpacks the ar container with pure
  Python (no `ar`/`dpkg-deb` needed), and packs a reproducible Slackware txz
- Mirrored plugin file `unraid-plugin-docker-model-proxy.plg` for networks that
  cannot reach GitHub. It installs the identical package, with the same SHA256,
  but downloads it through `https://gh-proxy.org/`
- `scripts/fetch-plugin.sh` for audit/debug inspection of any upstream version
- `scripts/lint-plg.sh` for static validation of the generated `.plg`
- `scripts/verify-release.sh` for verifying artifact hashes and layout
- GitHub Actions workflows for build verification and upstream version checks
- Issue and pull request templates
- Documentation covering the design decision, technical design, verification
  log and roadmap

### Changed

- The plugin template renders two plugin files from a single source; the plugin
  URL, the download URL and the source note are the only differences
- `lint-plg.sh` checks every `.plg` in `dist/`, and `verify-release.sh` checks
  that the direct and the mirrored variant reference the same package and
  checksum

[Unreleased]: https://github.com/zhz8888/unraid-plugin-docker-model/compare/1.2.9...HEAD
[1.2.9]: https://github.com/zhz8888/unraid-plugin-docker-model/releases/tag/1.2.9
