<?php
/**
 * Action endpoint of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The endpoint only accepts a fixed set of action names. The command that
 * belongs to an action is defined here, never taken from the request, so no
 * request value ever reaches the shell.
 */

require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

/**
 * Start a command in the background and log its output.
 *
 * @param string $command a fixed command, never built from request data
 * @param string $logfile
 *
 * @return bool
 */
function dmr_run_in_background($command, $logfile)
{
    $cmd = 'nohup ' . $command . ' >> ' . escapeshellarg($logfile) . ' 2>&1 &';
    exec($cmd);

    return true;
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';

$commands = array(
    'install-runner-cpu' => 'docker model install-runner --gpu none',
    'install-runner-gpu' => 'docker model install-runner --gpu auto',
    'uninstall-runner' => 'docker model uninstall-runner',
);

$ok = false;
$message = 'Unknown action.';

if (!isset($commands[$action])) {
    $message = 'Unknown action.';
} elseif (!dmr_binary_installed()) {
    $message = 'The docker model CLI plugin is not installed. Reinstall the plugin first.';
} elseif ($action === 'uninstall-runner') {
    $result = dmr_exec($commands[$action] . ' 2>&1', 60);
    $ok = ($result['code'] === 0);
    $message = $ok
        ? 'The Model Runner was removed. The runner image and the model volume were kept.'
        : 'Removing the Model Runner failed: ' . trim($result['stdout'] . ' ' . $result['stderr']);
} else {
    $logfile = '/var/log/docker-model-runner-install.log';
    dmr_run_in_background($commands[$action], $logfile);
    $ok = true;
    $message = 'The Model Runner is being installed in the background. '
        . 'The first start pulls the runner image, which takes a few minutes. '
        . 'Progress is logged to ' . $logfile . '.';
}

echo json_encode(array('ok' => $ok, 'message' => $message));
