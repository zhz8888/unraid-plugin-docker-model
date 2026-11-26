<?php
/**
 * Action endpoint of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The endpoint only accepts a fixed set of action names. The command that
 * belongs to an action is defined here, never taken from the request, so no
 * request value ever reaches the shell as it was sent. Two request values
 * select the target of a command:
 *
 * - the listen address of the runner, validated as an IP address by
 *   dmr_valid_host() and quoted with escapeshellarg() on top of that;
 * - the API key of the local OpenAI-compatible client configuration, limited
 *   to a safe token alphabet by dmr_valid_api_key() and never sent to a command;
 * - the model of a model action, resolved by dmr_resolve_model() against the
 *   identifiers that `docker model list` just printed. Only the identifier
 *   from that list reaches the command, so a crafted reference that is not a
 *   local model is refused.
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
 * The endpoint changes state (it deploys, stops and removes the Model Runner
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
//
// The listen address is read from the settings file of the plugin. It is
// appended to the install command, because that is what `docker model`
// supports: the CLI has no `--network` flag, it publishes the container port
// with `--host <ip>`. Publishing on 0.0.0.0 is what makes the runner reachable
// from other devices on the LAN, the default of the CLI only binds 127.0.0.1.
$settings = dmr_settings();
$hostArgument = ' --host ' . escapeshellarg($settings['host']);

$commands = array(
    'install-runner-cpu' => array(
        'command' => 'docker model install-runner --gpu none' . $hostArgument,
        'logfile' => '/var/log/docker-model-runner-install.log',
        'message' => dmr_t('action.install_cpu'),
    ),
    'install-runner-gpu' => array(
        'command' => 'docker model install-runner --gpu auto' . $hostArgument,
        'logfile' => '/var/log/docker-model-runner-install.log',
        'message' => dmr_t('action.install_gpu'),
    ),
    'stop-runner' => array(
        'command' => 'docker model stop-runner',
        'logfile' => '/var/log/docker-model-runner-stop.log',
        'message' => dmr_t('action.stop'),
    ),
    // Recreating the container is how a changed listen address takes effect:
    // the address of a container is fixed when it is created. The GPU mode is
    // read back from the running container, so a CPU-only installation on a
    // GPU host does not silently turn into a CUDA one.
    'reinstall-runner' => array(
        'command' => static function () use ($hostArgument) {
            return 'docker model reinstall-runner --gpu '
                . escapeshellarg(dmr_runner_gpu_mode())
                . $hostArgument;
        },
        'logfile' => '/var/log/docker-model-runner-reinstall.log',
        'message' => dmr_t('action.reinstall'),
    ),
    'uninstall-runner' => array(
        'command' => 'docker model uninstall-runner',
        'logfile' => '/var/log/docker-model-runner-uninstall.log',
        'message' => dmr_t('action.uninstall'),
    ),
);

$ok = false;
$message = dmr_t('action.unknown');
$extra = array();

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

    if ($action === 'save-settings') {
        // Only the settings request stores values. The host is validated before
        // it can reach a command; the API key is validated for safe persistence
        // and is deliberately not passed to the runner, which has no key flag.
        $host = trim(isset($_POST['host']) ? (string) $_POST['host'] : '');
        $values = array('host' => $host);
        $apiKeyProvided = array_key_exists('api_key', $_POST);
        $apiKey = '';
        if ($apiKeyProvided) {
            $submittedKey = $_POST['api_key'];
            $apiKey = is_string($submittedKey) ? trim($submittedKey) : '';
            if ($apiKey === '' && is_string($submittedKey)) {
                $apiKey = 'local';
            }
            $values['api_key'] = $apiKey;
        }

        if (!dmr_valid_host($host)) {
            $message = dmr_t('action.invalid_host');
        } elseif ($apiKeyProvided && !dmr_valid_api_key($apiKey)) {
            $message = dmr_t('action.invalid_api_key');
        } elseif (!dmr_save_settings($values)) {
            $message = sprintf(dmr_t('action.save_failed'), DMR_CONFIG_FILE);
        } else {
            $ok = true;
            $message = sprintf(dmr_t('action.settings_saved'), $host);
            $extra['host'] = $host;
        }
    } elseif ($action === 'update-model' || $action === 'remove-model') {
        // Update is a pull of the same tag, which fetches the layers that
        // changed; remove is a plain `docker model rm`, so a model that the
        // runner still has loaded is refused by the CLI and the row stays.
        if (!dmr_binary_installed()) {
            $message = dmr_t('action.not_installed');
        } else {
            $updating = $action === 'update-model';
            $report = dmr_models_report();
            $requested = isset($_POST['model']) ? $_POST['model'] : null;
            $target = dmr_resolve_model($report, $requested, $updating);
            $logfile = '/var/log/docker-model-runner-model.log';

            $command = $target === null ? null : dmr_model_command($action, $target);

            if ($command === null) {
                $message = dmr_t('action.model_not_found');
            } elseif (dmr_run_in_background($command, $logfile)) {
                $ok = true;
                $message = sprintf(dmr_t($updating ? 'action.update_model' : 'action.remove_model'), $target);
                $extra['model'] = $target;
            } else {
                $message = sprintf(dmr_t('action.could_not_start'), $logfile);
            }
        }
    } elseif (!isset($commands[$action])) {
        $message = dmr_t('action.unknown');
    } elseif (!dmr_binary_installed()) {
        $message = dmr_t('action.not_installed');
    } else {
        $command = $commands[$action]['command'];
        if (is_callable($command)) {
            $command = $command();
        }

        $logfile = $commands[$action]['logfile'];
        if (dmr_run_in_background($command, $logfile)) {
            $ok = true;
            $message = $commands[$action]['message'];
        } else {
            $message = sprintf(dmr_t('action.could_not_start'), $logfile);
        }
    }
}

$payload = array_merge(array('ok' => $ok, 'message' => $message), $extra);

// The messages are translated text, so a locale file that carries a byte that
// is not valid UTF-8 must not turn the whole response into an empty body.
$json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($json)) {
    http_response_code(500);
    $json = '{"ok":false,"message":"unavailable"}';
}

echo $json;
