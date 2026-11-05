#!/bin/sh
#
# doinst.sh -- Slackware post-install hook.
#
# Runs from the package's install directory, with the package root as the
# current working directory. It runs on every `upgradepkg --install-new`,
# which is what the Unraid plugin does at every boot, so it has to be
# idempotent.

# The Docker CLI only looks for plugins in a fixed set of directories.
# Create the canonical one if the Docker package has not created it yet,
# otherwise the CLI plugin would be silently ignored.
CLI_PLUGIN_DIR="/usr/libexec/docker/cli-plugins"
if [ ! -d "${CLI_PLUGIN_DIR}" ]; then
    mkdir -p "${CLI_PLUGIN_DIR}"
    chmod 0755 "${CLI_PLUGIN_DIR}"
fi

# The binary ships with the executable bit already set, but the archive may
# have been repacked by a third party. Make sure it is executable.
if [ -f "${CLI_PLUGIN_DIR}/docker-model" ]; then
    chmod 0755 "${CLI_PLUGIN_DIR}/docker-model"
fi

# The WebGUI page lives under /usr/local/emhttp/plugins. Make sure the
# directory is traversable for the web server.
if [ -d "/usr/local/emhttp/plugins/unraid-plugin-docker-model" ]; then
    chmod 0755 "/usr/local/emhttp/plugins/unraid-plugin-docker-model"
fi
