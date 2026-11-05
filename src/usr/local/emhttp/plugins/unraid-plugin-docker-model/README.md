# Docker Model Runner

Adds the `docker model` subcommand to the Unraid Docker CLI, so AI models can
be pulled, run and served locally.

The plugin installs the official Docker `docker-model` CLI plugin into
`/usr/libexec/docker/cli-plugins/`. The Model Runner itself is deployed on
demand with `docker model install-runner`.

- Plugin version: `@@PLUGIN_VERSION@@`
- Bundled docker-model: `@@UPSTREAM_VERSION@@`

## Usage

```bash
docker model version
docker model install-runner --gpu none     # CPU only
docker model install-runner --gpu auto     # with an NVIDIA/AMD GPU
docker model pull ai/smollm2:360M-Q4_K_M
docker model run ai/smollm2:360M-Q4_K_M "Hello"
```

## Notes

- `docker model pull` does not honour the `registry-mirrors` setting of
  `daemon.json`. On a restricted network use a proxy prefix in the image name,
  for example `docker model pull gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M`.
- The runner API listens on `127.0.0.1:12434` and is unauthenticated. Do not
  expose that port to untrusted networks.

Upstream: <https://github.com/docker/model-runner>
