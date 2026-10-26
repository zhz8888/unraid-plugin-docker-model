# Security Policy

## Supported versions

Only the latest release receives fixes. Because this project tracks the upstream
`docker-model-plugin` release, security fixes in the bundled binary come from
Docker and arrive with a version bump.

| Version | Supported |
|---------|-----------|
| latest  | yes       |
| older   | no        |

## What this project controls

The redistributed binary is taken **unmodified** from Docker's official apt
artifact. The build verifies its SHA256 against the `Packages` index published
by `download.docker.com` before packaging. See `docs/01-design-decisions.md`
for the full verification chain.

Security issues that belong upstream (the `docker model` CLI itself, the
`docker/model-runner` image, llama.cpp) should be reported to
[docker/model-runner](https://github.com/docker/model-runner/security).

## Reporting a vulnerability in this repo

Report issues in the packaging layer only:

- the `.plg` install/uninstall scripts
- the build pipeline and its checksum handling
- anything that could cause a wrong or unverified binary to be distributed

Open a private security advisory at
<https://github.com/zhz8888/unraid-plugin-docker-model/security/advisories/new>
rather than a public issue.

Please include:

- affected version
- a description of the impact
- reproduction steps or a proof of concept

## Known operational caveats

These are documented limitations rather than vulnerabilities, but they matter
when deploying:

- The Docker Model Runner API is **unauthenticated**. The runner binds to
  `127.0.0.1` and the docker bridge gateway by default. Do not expose port
  `12434` to untrusted networks.
- `docker model pull` ignores the `registry-mirrors` configured in
  `daemon.json`; only fully qualified image names honour a proxy prefix.
