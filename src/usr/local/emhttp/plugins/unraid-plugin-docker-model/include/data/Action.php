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
 * Whether the request comes from a page of this WebGUI.
 *
 * The endpoint changes state (it deploys and removes the Model Runner
 * container), so a page from another origin must not be able to trigger it.
 * The check is deliberately permissive: a request without an Origin/Referer
 * header is accepted, only a mismatching value is rejected.
 *
 * @return bool
 */
function dmr_request_is_same_origin()
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));

    if ($host === '' || $source === '') {
        return true;
    }

    $sourceHost = parse_url($source, PHP_URL_HOST);
    if (!is_string($sourceHost) || $sourceHost === '') {
        return false;
    }

    $hostOnly = $host;
    $hostPort = '';
    if (preg_match('/^(.+):(\d+)$/', $host, $matches)) {
        $hostOnly = $matches[1];
        $hostPort = $matches[2];
    }
    if ($sourceHost !== $hostOnly) {
        return false;
    }

    $sourcePort = parse_url($source, PHP_URL_PORT);
    if ($sourcePort !== null && $sourcePort !== false && $hostPort !== '') {
        return (string) $sourcePort === $hostPort;
    }

    return true;
}

/**
 * Start a command in the background and log its output.
 *
 * The exit code of exec() only reports whether the detached shell could be
 * started, but that is exactly what used to be assumed unconditionally.
 *
 * @param string $command a fixed command, never built from request data
 * @param string $logfile
 *
 * @return bool whether the detached shell was started
 */
function dmr_run_in_background($command, $logfile)
{
    $cmd = 'nohup ' . $command . ' >> ' . escapeshellarg($logfile) . ' 2>&1 &';
    $output = array();
    $code = 1;
    exec($cmd, $output, $code);

    return $code === 0;
}

// Every action runs in the background: `docker model install-runner` pulls a
// runner image of about 1 GB and `uninstall-runner` has to talk to the Docker
// daemon, so a synchronous run can exceed the FastCGI request timeout and
// leave the browser with an empty response.
$commands = array(
    'install-runner-cpu' => array(
        'command' => 'docker model install-runner --gpu none',
        'logfile' => '/var/log/docker-model-runner-install.log',
        'message' => 'The Model Runner is being installed in the background. '
            . 'The first start pulls the runner image, which takes a few minutes. '
            . 'Progress is logged to /var/log/docker-model-runner-install.log.',
    ),
    'install-runner-gpu' => array(
        'command' => 'docker model install-runner --gpu auto',
        'logfile' => '/var/log/docker-model-runner-install.log',
        'message' => 'The Model Runner is being installed in the background. '
            . 'The runner image is pulled on first start and an NVIDIA or AMD GPU '
            . 'with container runtime support is required. '
            . 'Progress is logged to /var/log/docker-model-runner-install.log.',
    ),
    'uninstall-runner' => array(
        'command' => 'docker model uninstall-runner',
        'logfile' => '/var/log/docker-model-runner-uninstall.log',
        'message' => 'The Model Runner is being removed in the background. '
            . 'The runner image and the model volume are kept. '
            . 'Progress is logged to /var/log/docker-model-runner-uninstall.log.',
    ),
);

$ok = false;
$message = 'Unknown action.';

if (!dmr_request_is_same_origin()) {
    http_response_code(403);
    $message = 'The request did not come from the Unraid WebGUI.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    $message = 'Only POST is supported.';
} else {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if (!isset($commands[$action])) {
        $message = 'Unknown action.';
    } elseif (!dmr_binary_installed()) {
        $message = 'The docker model CLI plugin is not installed. Reinstall the plugin first.';
    } elseif (dmr_run_in_background($commands[$action]['command'], $commands[$action]['logfile'])) {
        $ok = true;
        $message = $commands[$action]['message'];
    } else {
        $message = 'The action could not be started. See '
            . $commands[$action]['logfile'] . ' for details.';
    }
}

echo json_encode(array('ok' => $ok, 'message' => $message));
