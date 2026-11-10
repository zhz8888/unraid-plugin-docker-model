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
 */

require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

$binary = dmr_binary_installed();

if (!$binary) {
    $cliText = dmr_t('cli.not_installed');
} else {
    $version = dmr_cli_version();
    if ($version === null) {
        $cliText = dmr_t('cli.unavailable');
    } elseif ($version['code'] !== 0) {
        $cliText = trim($version['stdout'] . ' ' . $version['stderr']);
    } else {
        $cliText = $version['stdout'];
    }
}

if ($cliText === '') {
    $cliText = dmr_t('cli.no_output');
}

$payload = array(
    'binary' => $binary ? 'installed' : 'missing',
    'cli' => $cliText,
    'runner' => dmr_runner_running() ? 'running' : 'not running',
    'models' => dmr_models(),
);

// The values come from the output of external commands, so a byte that is not
// valid UTF-8 is possible. Without the substitute flag json_encode() returns
// false, the body would be empty and the page would report an unknown state
// instead of the data that was read successfully.
$json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($json)) {
    http_response_code(500);
    $json = '{"binary":"missing","cli":"","runner":"not running","models":[]}';
}

echo $json;
