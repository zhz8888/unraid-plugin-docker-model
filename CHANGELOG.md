# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

Since the 20261027 release the **plugin version** is the release date,
`YYYYMMDD`. When more than one release happens on the same day, the later ones
carry a letter suffix: `20261105.a`, `20261105.b` and so on. The Unraid
installer compares plugin versions with `strcmp()`, a byte-wise string compare,
so the format has to sort in release order, and this one does:

```
20261105 < 20261105.a < 20261105.b < 20261106
```

A semantic version does not sort that way — `1.2.10` sorts *below* `1.2.9` and
Unraid would refuse the upgrade.

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

## [20261106] - 2026-11-06

The bundled `docker-model-plugin` is unchanged at 1.2.9: this is a repackaging
release that fixes the WebGUI layer, the package build and the release checks.

### Added

- `include/data/Status.php` — the status tab now loads the CLI version, the
  Model Runner state and the model list asynchronously. It used to run three
  docker commands while rendering, so a stopped Model Runner delayed the whole
  WebGUI tab for up to fifty seconds
- `lint-plg.sh` fails on a shell error mode (`set -e`, `set -u`) in the install
  or remove script. Such a mode turns an unset variable into a non-zero exit,
  which aborts the install exactly like an explicit `exit 1`
- The upstream check verifies that the pinned version exists in the apt index
  and that `Makefile` agrees with `scripts/build-plugin.sh`, and
  `verify-release.sh` checks the new status endpoint

### Changed

- The install and remove scripts drop `set -u` and carry
  `trap 'exit 0' EXIT`. A non-zero exit makes the installer move the `.plg` to
  `/boot/config/plugins-error/` and leaves the plugin unregistered, so the user
  lost the Uninstall button
- The action endpoint starts every Model Runner action in the background and
  reports whether the start succeeded. The synchronous uninstall could run into
  the FastCGI request timeout
- `build.yml` no longer repeats the bundled upstream version: an empty workflow
  input means "use the default of `scripts/build-plugin.sh`", so the pin has a
  single source of truth again. The verify job uploads only the package,
  because the `.plg` of a non-release build points at a tag that does not exist
- The Community Applications wrapper is rooted at `<Plugin>`, the element the
  current CA template uses for plugins, instead of `<Containers>`
- The `<CHANGES>` block of the `.plg` is generated from this file at build
  time, so a release no longer repeats the notes of an older one

### Fixed

- Both WebGUI tabs showed `unknown` for the plugin and upstream versions. The
  values in `plugin.info` were written unquoted, and the INI scanner reads the
  `~` of the upstream artifact name as a bitwise negation operator, so
  `parse_ini_file()` rejected the file as a whole
- A build on Windows wrote CRLF into the generated `.plg`. The `<INLINE>`
  scripts are bash, and CRLF breaks their line continuations on the host
- The upstream check reported `0.1.24` as the newest release while the
  repository already bundled `1.2.9`. The apt index lists the versions oldest
  first and the check printed the first block, which turned the scheduled run
  into a downgrade request
- A page exception was printed into the page together with its stack trace and
  absolute paths. It is appended to `/var/log` now and the page only reports
  where to look
- The result and status panels are built with `textContent` instead of
  `innerHTML`, because the message can carry the raw docker output

### Removed

- `settings.json`, which declared two settings that no code ever read
- `locales/en_US.json`, which the page never consulted
- `page.php`, a second entry point that duplicated the status tab

## [20261027] - 2026-10-27

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

[Unreleased]: https://github.com/zhz8888/unraid-plugin-docker-model/compare/20261106...HEAD
[20261106]: https://github.com/zhz8888/unraid-plugin-docker-model/releases/tag/20261106
[20261027]: https://github.com/zhz8888/unraid-plugin-docker-model/releases/tag/20261027
