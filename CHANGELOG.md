# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Version numbers track the upstream `docker-model-plugin` release that is bundled.

## [Unreleased]

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
