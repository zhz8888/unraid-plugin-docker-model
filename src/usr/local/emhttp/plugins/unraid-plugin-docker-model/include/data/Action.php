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
 *
 * Two guards sit in front of the whitelist. dmr_request_is_same_origin()
 * compares scheme, host and port of the Origin/Referer with the host the
 * request was addressed to, and dmr_csrf_valid() requires the token that the
 * page reads from a file outside the web root. The token is what stops a
 * page of another origin: it cannot read that file.
 */

require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

/**
 * Split an absolute URL into the three parts that identify its origin.
 *
 * A port that the URL omits falls back to the default of its scheme, so
 * "http://nas" and "http://nas:80" describe the same origin. Without that
 * normalisation a check that only compares the host accepts a page of the
 * same host on another port, which is a different origin for the browser.
 *
 * @param string $url
 *
 * @return array{scheme:string,host:string,port:int}|null
 */
function dmr_url_origin($url)
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($scheme) || $scheme === '' || !is_string($host) || $host === '') {
        return null;
    }

    $scheme = strtolower($scheme);
    $port = parse_url($url, PHP_URL_PORT);
    if (!is_int($port)) {
        $port = $scheme === 'https' ? 443 : 80;
    }

    return array('scheme' => $scheme, 'host' => strtolower($host), 'port' => $port);
}

/**
 * The origin the browser addressed, taken from the Host header.
 *
 * The scheme comes from HTTPS or, when a reverse proxy terminates TLS, from
 * X-Forwarded-Proto. Unraid itself serves the WebGUI over plain HTTP, so the
 * proxy header only matters for the routers that wrap it.
 *
 * @return array{scheme:string,host:string,port:int}|null
 */
function dmr_request_origin()
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^(\[[^\]]+\]|[^:]+)(?::(\d+))?$/', $host, $matches)) {
        return null;
    }

    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        if (strtolower(trim($forwarded[0])) === 'https') {
            $scheme = 'https';
        }
    }

    $port = isset($matches[2]) && $matches[2] !== ''
        ? (int) $matches[2]
        : ($scheme === 'https' ? 443 : 80);

    return array('scheme' => $scheme, 'host' => strtolower($matches[1]), 'port' => $port);
}

/**
 * Whether the request comes from a page of this WebGUI.
 *
 * The endpoint changes state (it deploys and removes the Model Runner
 * container), so a page from another origin must not be able to trigger it.
 * A request without Origin and without Referer is rejected: a browser sends
 * Origin on a POST, so a request without one is a script, and scripts have no
 * reason to drive this endpoint. The token of dmr_csrf_valid() is checked as
 * well, because this comparison cannot see a page that the browser itself
 * considers same-origin.
 *
 * @return bool
 */
function dmr_request_is_same_origin()
{
    $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($source === '') {
        return false;
    }

    $self = dmr_request_origin();
    if ($self === null) {
        // No usable Host header: there is nothing to compare against, so the
        // request is only held to the token check.
        return true;
    }

    $foreign = dmr_url_origin($source);
    if ($foreign === null) {
        return false;
    }

    return $self['scheme'] === $foreign['scheme']
        && $self['host'] === $foreign['host']
        && $self['port'] === $foreign['port'];
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
        'message' => dmr_t('action.install_cpu'),
    ),
    'install-runner-gpu' => array(
        'command' => 'docker model install-runner --gpu auto',
        'logfile' => '/var/log/docker-model-runner-install.log',
        'message' => dmr_t('action.install_gpu'),
    ),
    'uninstall-runner' => array(
        'command' => 'docker model uninstall-runner',
        'logfile' => '/var/log/docker-model-runner-uninstall.log',
        'message' => dmr_t('action.uninstall'),
    ),
);

$ok = false;
$message = dmr_t('action.unknown');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    $message = dmr_t('action.post_only');
} elseif (!dmr_request_is_same_origin()) {
    http_response_code(403);
    $message = dmr_t('action.cross_origin');
} elseif (!dmr_csrf_valid($_POST['token'] ?? '')) {
    http_response_code(403);
    $message = dmr_t('action.bad_token');
} else {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if (!isset($commands[$action])) {
        $message = dmr_t('action.unknown');
    } elseif (!dmr_binary_installed()) {
        $message = dmr_t('action.not_installed');
    } elseif (dmr_run_in_background($commands[$action]['command'], $commands[$action]['logfile'])) {
        $ok = true;
        $message = $commands[$action]['message'];
    } else {
        $message = sprintf(dmr_t('action.could_not_start'), $commands[$action]['logfile']);
    }
}

echo json_encode(array('ok' => $ok, 'message' => $message));
