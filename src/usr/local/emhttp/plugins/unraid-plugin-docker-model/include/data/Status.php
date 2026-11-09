<?php
/**
 * Status endpoint of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The status tab renders its static part from include/Pages/Status.php and
 * fetches the values that need a `docker` call here, so a slow or wedged
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

echo json_encode(array(
    'binary' => $binary ? 'installed' : 'missing',
    'cli' => $cliText,
    'runner' => dmr_runner_running() ? 'running' : 'not running',
    'models' => dmr_models(),
));
