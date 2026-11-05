<?php
/**
 * Shared helpers for the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The page is deliberately dependency free: it only uses functions that are
 * present in the PHP build shipped with Unraid, so it keeps working across
 * Unraid releases without a composer vendor directory.
 */

if (!defined('DMR_PLUGIN_NAME')) {
    define('DMR_PLUGIN_NAME', 'unraid-plugin-docker-model');
}
if (!defined('DMR_PLUGIN_ROOT')) {
    define('DMR_PLUGIN_ROOT', dirname(__DIR__));
}
if (!defined('DMR_CLI_DIR')) {
    define('DMR_CLI_DIR', '/usr/libexec/docker/cli-plugins');
}
if (!defined('DMR_CLI_BINARY')) {
    define('DMR_CLI_BINARY', DMR_CLI_DIR . '/docker-model');
}

/**
 * Read the build metadata that the package ships next to this file.
 *
 * The file is generated at build time and holds the plugin version and the
 * version of the bundled upstream docker-model release.
 *
 * @return array<string,string>
 */
function dmr_plugin_info()
{
    static $info = null;

    if ($info !== null) {
        return $info;
    }

    $info = array(
        'plugin_version' => 'unknown',
        'upstream_version' => 'unknown',
        'upstream_source' => 'unknown',
        'binary_sha256' => '',
    );

    $file = DMR_PLUGIN_ROOT . '/plugin.info';
    if (is_readable($file)) {
        foreach (parse_ini_file($file) as $key => $value) {
            $info[$key] = (string) $value;
        }
    }

    return $info;
}

/**
 * Run a command with a hard timeout and capture its output.
 *
 * exec() alone cannot be interrupted, and `docker model` talks to a
 * container that may be stopped or unreachable. A bounded runner keeps the
 * WebGUI responsive in every case.
 *
 * @param string $command
 * @param int    $timeout seconds
 *
 * @return array{code:int,stdout:string,stderr:string}
 */
function dmr_exec($command, $timeout = 15)
{
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return array('code' => -1, 'stdout' => '', 'stderr' => 'failed to start command');
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $code = -1;
    $deadline = microtime(true) + $timeout;

    while (true) {
        $status = proc_get_status($process);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        if (!$status['running']) {
            $code = (int) $status['exitcode'];
            break;
        }

        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            $stderr .= "\ncommand timed out after {$timeout}s\n";
            break;
        }

        usleep(50000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return array('code' => $code, 'stdout' => trim($stdout), 'stderr' => trim($stderr));
}

/**
 * Whether the CLI plugin binary is present and executable.
 *
 * @return bool
 */
function dmr_binary_installed()
{
    return is_file(DMR_CLI_BINARY) && is_executable(DMR_CLI_BINARY);
}

/**
 * The output of `docker model version`, or null when the CLI is unusable.
 *
 * @return array{code:int,stdout:string,stderr:string}|null
 */
function dmr_cli_version()
{
    if (!dmr_binary_installed()) {
        return null;
    }

    return dmr_exec('docker model version 2>&1', 15);
}

/**
 * Whether the Model Runner container is up.
 *
 * @return bool
 */
function dmr_runner_running()
{
    if (!dmr_binary_installed()) {
        return false;
    }

    $result = dmr_exec('docker ps --filter name=docker-model-runner --filter status=running --format "{{.Names}}" 2>&1', 15);

    return $result['code'] === 0 && strpos($result['stdout'], 'docker-model-runner') !== false;
}

/**
 * The list of locally available models.
 *
 * @return string[]
 */
function dmr_models()
{
    if (!dmr_binary_installed()) {
        return array();
    }

    $result = dmr_exec('docker model list 2>&1', 20);
    if ($result['code'] !== 0 || $result['stdout'] === '') {
        return array();
    }

    $models = array();
    foreach (preg_split('/\R/', $result['stdout']) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'MODEL') === 0) {
            continue;
        }
        $models[] = $line;
    }

    return $models;
}

/**
 * Escape a value for HTML output.
 *
 * @param mixed $value
 *
 * @return string
 */
function dmr_esc($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render a key/value row.
 *
 * @param string $label
 * @param string $value
 *
 * @return string
 */
function dmr_row($label, $value)
{
    return '<tr>'
        . '<td style="width:280px;vertical-align:top"><strong>' . dmr_esc($label) . '</strong></td>'
        . '<td>' . dmr_esc($value) . '</td>'
        . '</tr>';
}
