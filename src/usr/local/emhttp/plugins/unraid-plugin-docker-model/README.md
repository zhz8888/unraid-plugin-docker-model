**Docker Model Runner**

This plugin installs the docker model CLI plugin package on your Unraid server and adds a manager page to the web ui.

The CLI plugin is installed into `/usr/libexec/docker/cli-plugins/`; the Model Runner container is deployed, stopped and removed from the page. Plugin version `@@PLUGIN_VERSION@@`, bundled docker-model `@@UPSTREAM_VERSION@@`.

The runner API is published on all network interfaces (`0.0.0.0`) by default, so other devices on the network can use it. The page also stores a local OpenAI-compatible client API Key (default `local`); this is a client placeholder and does not authenticate the runner. The API has no authentication: restrict it to the server or to one address on the page, or block port `12434` with a firewall, and never expose it to an untrusted network.

Upstream: <https://github.com/docker/model-runner>
