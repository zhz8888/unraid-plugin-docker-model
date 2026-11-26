<?php
/**
 * Status endpoint of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The page (include/Pages/Home.php) renders the static part and fetches the
 * values that need a `docker` call from this endpoint, so a slow or wedged
 * Docker daemon never delays the WebGUI page itself. Every call is bounded
 * by the timeout of dmr_exec().
 *
 * The version of the CLI is normalised here instead of being handed to the
 * page as the raw output of `docker model version`: the page shows one row
 * per field, and the raw output only as a collapsed block for
 * troubleshooting. The values that name a state ("(not reachable)") or an
 * engine kind are translated as well, so the page does not mix a translated
 * label with an English value.
 */

require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

$version = dmr_version_report();
$runner = dmr_runner_state();
$settings = dmr_settings();

$payload = array(
    'binary' => dmr_binary_installed() ? 'installed' : 'missing',
    'version' => array(
        'status' => $version['status'],
        'structured' => $version['structured'],
        'client_version' => dmr_version_text($version['client']['version']),
        'client_platform' => dmr_version_text($version['client']['platform']),
        'server_version' => dmr_version_text($version['server']['version']),
        'server_engine' => dmr_engine_text($version['server']['engine']),
        'raw' => $version['raw'],
        'error' => $version['error'],
    ),
    'runner' => $runner['running'] ? 'running' : 'stopped',
    'runner_ports' => $runner['ports'],
    'models' => dmr_models(),
    'settings' => array(
        'host' => $settings['host'],
    ),
);

// The values come from the output of external commands, so a byte that is not
// valid UTF-8 is possible. Without the substitute flag json_encode() returns
// false, the body would be empty and the page would report an unknown state
// instead of the data that was read successfully.
$json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($json)) {
    http_response_code(500);
    $json = '{"binary":"missing","version":{"status":"unavailable","structured":false},'
        . '"runner":"stopped","runner_ports":"","models":[],"settings":{}}';
}

echo $json;
