**Docker Model Runner**

This plugin installs the docker model plugin package on your Unraid server and adds a simple manager page to the web ui.

The CLI plugin is installed into `/usr/libexec/docker/cli-plugins/`, the Model Runner container is deployed on demand with `docker model install-runner`. Plugin version `@@PLUGIN_VERSION@@`, bundled docker-model `@@UPSTREAM_VERSION@@`.

The runner API listens on `127.0.0.1:12434` without authentication, do not expose that port to untrusted networks.

Upstream: <https://github.com/docker/model-runner>
