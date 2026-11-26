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
if (!defined('DMR_CONFIG_FILE')) {
    // The Unraid conventions put the configuration of a plugin on the flash
    // device under /boot/config/plugins/<name>/, next to the cached package.
    define('DMR_CONFIG_FILE', '/boot/config/plugins/' . DMR_PLUGIN_NAME . '/' . DMR_PLUGIN_NAME . '.cfg');
}

/**
 * Read the build metadata that the package ships next to this file.
 *
 * The file is generated at build time and holds the plugin version and the
 * version of the bundled upstream docker-model release. Its keys are upper
 * case, the fields returned here are lower case, so the keys are normalised
 * on the way in. scripts/verify-release.sh checks that every field the page
 * shows can be resolved that way.
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
        // INI_SCANNER_RAW returns the values literally. The normal scanner
        // evaluates operators, and the upstream artifact name contains a
        // "~" (docker-model-plugin_1.2.9-1~ubuntu.24.04~noble_amd64.deb),
        // which is the bitwise negation operator, so the parser would reject
        // the whole file instead of returning the values.
        $parsed = parse_ini_file($file, false, INI_SCANNER_RAW);
        if (is_array($parsed)) {
            foreach ($parsed as $key => $value) {
                // The keys of the file are upper case, the fields of this
                // function are lower case, so they are normalised here.
                // Without that the page reports "unknown" for every version,
                // because the fields would never be found.
                // RAW mode keeps the surrounding quotes of a quoted value.
                $info[strtolower((string) $key)] = trim((string) $value, "\"'");
            }
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
 * Truncate a text to a number of bytes.
 *
 * The output of an external command can be arbitrarily long, and the page
 * embeds it. A cut in the middle of a multi byte character is acceptable:
 * Status.php encodes with JSON_INVALID_UTF8_SUBSTITUTE, so the broken byte is
 * replaced instead of turning the whole response into an empty body.
 *
 * @param string $text
 * @param int    $limit
 *
 * @return string
 */
function dmr_truncate($text, $limit = 4000)
{
    $text = (string) $text;
    if (strlen($text) <= $limit) {
        return $text;
    }

    return substr($text, 0, $limit) . "\n...";
}

/**
 * Parse the output of `docker model version` into its fields.
 *
 * The CLI prints two sections:
 *
 *     Client:
 *      Version:    1.2.9
 *      OS/Arch:    linux/amd64
 *
 *     Server:
 *      Version:    1.0.0
 *      Engine:     Docker Engine
 *
 * When the runner is not running the server section still exists, but its
 * values are "(not reachable)". A release of the CLI that prints an
 * unexpected layout yields structured = false; the page then falls back to the
 * raw output instead of showing a half parsed table.
 *
 * @param string $output
 *
 * @return array{structured:bool,client:array<string,string>,server:array<string,string>}
 */
function dmr_parse_version_output($output)
{
    $result = array(
        'structured' => false,
        'client' => array('version' => '', 'platform' => ''),
        'server' => array('version' => '', 'engine' => ''),
    );

    $section = '';

    foreach (preg_split('/\R/', (string) $output) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        $heading = strtolower(rtrim($trimmed, ':'));
        if ($heading === 'client' || $heading === 'server') {
            $section = $heading;
            $result['structured'] = true;
            continue;
        }

        if ($section === '' || !preg_match('/^\s*([A-Za-z][A-Za-z \/&()_-]*?)\s*:\s*(.*)$/', $line, $matches)) {
            continue;
        }

        $key = strtolower(trim($matches[1]));
        $value = trim($matches[2]);

        if ($section === 'client') {
            if ($key === 'version') {
                $result['client']['version'] = $value;
            } elseif ($key === 'os/arch') {
                $result['client']['platform'] = $value;
            }
            continue;
        }

        if ($key === 'version') {
            $result['server']['version'] = $value;
        } elseif ($key === 'engine') {
            $result['server']['engine'] = $value;
        }
    }

    return $result;
}

/**
 * The version report of the CLI, normalised for the page.
 *
 * @return array{status:string,structured:bool,client:array<string,string>,server:array<string,string>,raw:string,error:string}
 */
function dmr_version_report()
{
    $report = array(
        'status' => 'missing',
        'structured' => false,
        'client' => array('version' => '', 'platform' => ''),
        'server' => array('version' => '', 'engine' => ''),
        'raw' => '',
        'error' => '',
    );

    if (!dmr_binary_installed()) {
        return $report;
    }

    $result = dmr_cli_version();
    if ($result === null) {
        $report['status'] = 'unavailable';

        return $report;
    }

    $report['raw'] = dmr_truncate(trim($result['stdout'] . "\n" . $result['stderr']), 4000);

    if ($result['code'] !== 0) {
        $report['status'] = 'error';
        $report['error'] = dmr_truncate(trim($result['stdout'] . ' ' . $result['stderr']), 400);

        return $report;
    }

    $parsed = dmr_parse_version_output($result['stdout']);
    $report['structured'] = $parsed['structured'];
    $report['client'] = $parsed['client'];
    $report['server'] = $parsed['server'];
    $report['status'] = 'ok';

    return $report;
}

/**
 * Render a value of the version report for the page.
 *
 * @param string $value
 *
 * @return string
 */
function dmr_version_text($value)
{
    $value = trim((string) $value);

    if (preg_match('/^\(not reachable\)$/i', $value)) {
        return dmr_t('state.unreachable');
    }

    if ($value === '') {
        return dmr_t('state.unknown');
    }

    return $value;
}

/**
 * Render the engine of the version report for the page.
 *
 * The CLI prints the engine kind as an English phrase ("Docker Engine"). The
 * page translates the kinds it knows and keeps an unknown kind verbatim, so a
 * new engine kind of a future CLI release still shows up.
 *
 * @param string $engine
 *
 * @return string
 */
function dmr_engine_text($engine)
{
    $engine = trim((string) $engine);

    $keys = array(
        'Docker Engine' => 'engine.docker_engine',
        'Docker Engine (Manual Install)' => 'engine.docker_engine_manual',
        'Docker Desktop' => 'engine.docker_desktop',
        'Docker Cloud' => 'engine.docker_cloud',
    );

    if (isset($keys[$engine])) {
        return dmr_t($keys[$engine]);
    }

    if (preg_match('/^\(not reachable\)$/i', $engine)) {
        return dmr_t('state.unreachable');
    }

    if ($engine === '') {
        return dmr_t('state.unknown');
    }

    return $engine;
}

/**
 * The state of the Model Runner container.
 *
 * The ports are read in the same call as the name, so the page can show the
 * addresses the runner is actually published on, which is how a change of the
 * network setting becomes visible.
 *
 * @return array{running:bool,ports:string}
 */
function dmr_runner_state()
{
    $state = array('running' => false, 'ports' => '');

    if (!dmr_binary_installed()) {
        return $state;
    }

    // Two calls with one field each instead of one call with a separator: the
    // format is passed to the shell, and a separator such as "|" is a pipe
    // there, so it would have to survive the quoting of every shell the page
    // may run under. One field per call has nothing to escape.
    $names = dmr_exec('docker ps --filter name=docker-model-runner --filter status=running --format "{{.Names}}" 2>&1', 15);
    if ($names['code'] !== 0 || strpos($names['stdout'], 'docker-model-runner') === false) {
        return $state;
    }

    $state['running'] = true;

    $ports = dmr_exec('docker ps --filter name=docker-model-runner --filter status=running --format "{{.Ports}}" 2>&1', 15);
    if ($ports['code'] === 0) {
        $state['ports'] = trim($ports['stdout']);
    }

    return $state;
}

/**
 * Whether the Model Runner container is up.
 *
 * @return bool
 */
function dmr_runner_running()
{
    $state = dmr_runner_state();

    return $state['running'];
}

/**
 * Map an image name or tag to the GPU mode the CLI was installed with.
 *
 * The image variants name the GPU support: docker/model-runner:latest is the
 * CPU build, docker/model-runner:latest-cuda the CUDA build and so on. The
 * suffix decides, because the tag may also carry the version.
 *
 * @param string $label
 *
 * @return string
 */
function dmr_gpu_mode_from_label($label)
{
    $label = strtolower((string) $label);

    foreach (array('cuda', 'rocm', 'musa', 'cann') as $mode) {
        $suffix = '-' . $mode;
        if (strlen($label) > strlen($suffix) && substr($label, -strlen($suffix)) === $suffix) {
            return $mode;
        }
    }

    return 'none';
}

/**
 * The GPU mode the existing runner container was created with.
 *
 * The page needs it to recreate the container without changing the GPU
 * support it was installed with: `docker model reinstall-runner` defaults to
 * `--gpu auto`, which would turn a CPU-only installation on a GPU host into a
 * CUDA one.
 *
 * @return string none, cuda, rocm, musa or cann; auto when it is unknown
 */
function dmr_runner_gpu_mode()
{
    if (!dmr_binary_installed()) {
        return 'auto';
    }

    $result = dmr_exec('docker inspect docker-model-runner --format "{{.Config.Image}}" 2>&1', 15);
    if ($result['code'] !== 0) {
        return 'auto';
    }

    $image = trim($result['stdout']);
    if ($image === '') {
        return 'auto';
    }

    return dmr_gpu_mode_from_label($image);
}

/**
 * The defaults of the settings the page manages.
 *
 * The runner API is published on every host interface by default, so other
 * devices on the LAN can reach it. The CLI binds it to 127.0.0.1 only, and
 * the bridge gateway address it adds on top of that is not routable from
 * outside the host.
 *
 * @return array<string,string>
 */
function dmr_setting_defaults()
{
    return array(
        'host' => '0.0.0.0',
    );
}

/**
 * Whether a value is an IP address the CLI accepts for `--host`.
 *
 * The flag takes an IP address, not a host name: install-runner parses it
 * with netip.ParseAddr and exits on anything else.
 *
 * @param mixed $value
 *
 * @return bool
 */
function dmr_valid_host($value)
{
    if (!is_string($value)) {
        return false;
    }

    $value = trim($value);

    return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
}

/**
 * The settings of the plugin, merged over the defaults.
 *
 * A value that is missing or invalid falls back to the default, so a hand
 * edited file cannot break the page or the install command.
 *
 * @return array<string,string>
 */
function dmr_settings()
{
    $settings = dmr_setting_defaults();

    if (!is_readable(DMR_CONFIG_FILE)) {
        return $settings;
    }

    $parsed = @parse_ini_file(DMR_CONFIG_FILE, false, INI_SCANNER_RAW);
    if (!is_array($parsed)) {
        return $settings;
    }

    foreach ($parsed as $key => $value) {
        if (strtolower((string) $key) !== 'dmr_host') {
            continue;
        }

        $host = trim((string) $value, "\"'");
        if (dmr_valid_host($host)) {
            $settings['host'] = trim($host);
        }
    }

    return $settings;
}

/**
 * Write the settings file of the plugin.
 *
 * The file is written next to its final name and renamed, so an interrupted
 * request cannot leave a half written file behind.
 *
 * @param array<string,string> $values
 *
 * @return bool
 */
function dmr_save_settings(array $values)
{
    $settings = dmr_settings();

    if (array_key_exists('host', $values)) {
        if (!dmr_valid_host($values['host'])) {
            return false;
        }
        $settings['host'] = trim((string) $values['host']);
    }

    $dir = dirname(DMR_CONFIG_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    $body = "; Settings of the unraid-plugin-docker-model WebGUI page.\n"
        . "; The page writes this file; change the values there.\n"
        . "\n"
        . 'DMR_HOST="' . str_replace('"', '', $settings['host']) . "\"\n";

    $temporary = DMR_CONFIG_FILE . '.tmp';
    if (@file_put_contents($temporary, $body) === false) {
        return false;
    }
    @chmod($temporary, 0644);

    if (!@rename($temporary, DMR_CONFIG_FILE)) {
        @unlink($temporary);

        return false;
    }

    return true;
}

/**
 * The locally available models, one row per tag.
 *
 * `docker model list` prints a fixed width table meant for a terminal, which
 * is awkward to parse and carries less than the machine readable mode:
 * `docker model list --json` prints the same data as JSON, with the full tag
 * of every model and its numeric creation time. The JSON is the source of the
 * table on the page; the rows are built here so the page only renders them.
 *
 * The GGUF metadata that comes with a model is dropped: it can be large and
 * none of it is shown on the page.
 *
 * @return array<int,array<string,mixed>>
 */
function dmr_models()
{
    $report = dmr_models_report();

    return $report['models'];
}

/**
 * The result of listing the local models.
 *
 * The error is kept apart from the rows: an empty list and a listing that
 * failed look the same otherwise, and a failed listing is what a stopped or
 * broken Docker daemon produces.
 *
 * @return array{ok:bool,models:array<int,array<string,mixed>>,error:string}
 */
function dmr_models_report()
{
    $report = array('ok' => false, 'models' => array(), 'error' => '');

    if (!dmr_binary_installed()) {
        $report['error'] = dmr_t('state.cli_missing');

        return $report;
    }

    // stderr is deliberately not merged into stdout: a warning of the CLI
    // would end up inside the JSON and break the parse. dmr_exec() captures
    // both streams separately, and the message below uses both.
    $result = dmr_exec('docker model list --json', 30);
    if ($result['code'] !== 0) {
        $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
        $report['error'] = dmr_truncate($detail, 400);

        return $report;
    }

    $output = trim($result['stdout']);
    if ($output === '') {
        // No models at all: the CLI prints nothing.
        $report['ok'] = true;

        return $report;
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        $report['error'] = dmr_t('models.parse_error');

        return $report;
    }

    $report['models'] = dmr_parse_models($decoded);
    $report['ok'] = true;

    return $report;
}

/**
 * Build the table rows of the page from the decoded output of
 * `docker model list --json`.
 *
 * @param array<mixed> $decoded
 *
 * @return array<int,array<string,mixed>>
 */
function dmr_parse_models(array $decoded)
{
    $rows = array();

    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $id = (isset($entry['id']) && is_string($entry['id'])) ? $entry['id'] : '';
        $config = (isset($entry['config']) && is_array($entry['config'])) ? $entry['config'] : array();
        $created = (isset($entry['created']) && is_numeric($entry['created'])) ? (int) $entry['created'] : 0;

        $tags = array();
        if (isset($entry['tags']) && is_array($entry['tags'])) {
            foreach ($entry['tags'] as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    $tags[] = trim($tag);
                }
            }
        }

        // A model that was imported without a tag has no name and no tag that
        // could be pulled again; the page shows the id and only offers to
        // remove it, which the CLI accepts by id as well.
        if (!$tags) {
            $tags = array('');
        }

        foreach ($tags as $tag) {
            $rows[] = array(
                'id' => $id,
                'tag' => $tag,
                'name' => $tag === '' ? dmr_model_short_id($id) : dmr_model_display_name($tag),
                'parameters' => dmr_model_config_value($config, 'parameters'),
                'quantization' => dmr_model_config_value($config, 'quantization'),
                'architecture' => dmr_model_config_value($config, 'architecture'),
                'size' => dmr_model_config_value($config, 'size'),
                'format' => dmr_model_config_value($config, 'format'),
                'created' => $created,
            );
        }
    }

    return $rows;
}

/**
 * Read one field of the config object of a model as a string.
 *
 * @param array<mixed> $config
 * @param string $key
 *
 * @return string
 */
function dmr_model_config_value(array $config, $key)
{
    if (!isset($config[$key])) {
        return '';
    }

    $value = $config[$key];
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return '';
}

/**
 * The name of a model as the CLI table shows it: without the default registry.
 *
 * The tag itself stays the identifier of the actions, because
 * `docker model pull` and `docker model rm` take the name as it is listed.
 *
 * @param string $tag
 *
 * @return string
 */
function dmr_model_display_name($tag)
{
    $name = (string) $tag;

    foreach (array('index.docker.io/', 'docker.io/') as $prefix) {
        if (strpos($name, $prefix) === 0) {
            return substr($name, strlen($prefix));
        }
    }

    return $name;
}

/**
 * The short id of a model, for a model that has no tag.
 *
 * @param string $id
 *
 * @return string
 */
function dmr_model_short_id($id)
{
    $id = (string) $id;
    if (strpos($id, 'sha256:') === 0) {
        $id = substr($id, 7);
    }

    return substr($id, 0, 12);
}

/**
 * Resolve a model reference of the page against the list the CLI reports.
 *
 * A request value never becomes a command line argument by itself: it is only
 * used to select one of the identifiers that `docker model list` printed, and
 * that identifier is what the command is built from. A reference that is not
 * in the current list is refused, so a crafted request cannot make the plugin
 * run `docker model` against an arbitrary target.
 *
 * @param array{models:array<int,array<string,mixed>>} $report
 * @param mixed $requested
 * @param bool $require_tag whether the action needs a tag (pull needs one)
 *
 * @return string|null
 */
function dmr_resolve_model(array $report, $requested, $require_tag = false)
{
    if (!is_string($requested)) {
        return null;
    }

    $requested = trim($requested);
    if ($requested === '') {
        return null;
    }

    foreach ($report['models'] as $row) {
        $tag = isset($row['tag']) ? (string) $row['tag'] : '';
        $id = isset($row['id']) ? (string) $row['id'] : '';

        if ($tag !== '' && $tag === $requested) {
            return $tag;
        }

        if (!$require_tag && $id !== '' && $id === $requested) {
            return $id;
        }
    }

    return null;
}

/**
 * The command that updates or removes one model.
 *
 * The identifier has to come from dmr_resolve_model(): it is quoted here, but
 * validation is what keeps an arbitrary value out of the command.
 *
 * @param string $action update-model or remove-model
 * @param string $target a tag or id reported by `docker model list`
 *
 * @return string|null
 */
function dmr_model_command($action, $target)
{
    $target = (string) $target;
    if ($target === '') {
        return null;
    }

    if ($action === 'update-model') {
        return 'docker model pull ' . escapeshellarg($target);
    }

    if ($action === 'remove-model') {
        return 'docker model rm ' . escapeshellarg($target);
    }

    return null;
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
 * The file that holds the request token of this installation.
 *
 * /var/run is preferred because it is cleared on every boot, /tmp is the
 * fallback of a host where the WebGUI process may not write to it.
 *
 * @return string
 */
function dmr_csrf_path()
{
    foreach (array('/var/run', '/tmp') as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            return $dir . '/unraid-plugin-docker-model.csrf';
        }
    }

    return rtrim(sys_get_temp_dir(), '/') . '/unraid-plugin-docker-model.csrf';
}

/**
 * The token that proves a write request came from the page of this plugin.
 *
 * The action endpoint changes the state of the box, so it must not run a
 * request that a foreign page triggered. The Origin/Referer check of the
 * endpoint is not enough on its own: a browser may omit both headers and a
 * same-host page on another port passes a comparison of the host alone. The
 * token lives in a file outside the web root that only the PHP process can
 * read, the page embeds it and the endpoint compares it with hash_equals(),
 * so a request from another origin fails even without an Origin header.
 *
 * The token is created on first use and reused afterwards, so a page that was
 * opened before a restart still matches. An empty return value means the
 * token could not be stored; dmr_csrf_valid() then falls back to the origin
 * check instead of locking the user out of the page.
 *
 * @return string
 */
function dmr_csrf_token()
{
    static $token = null;

    if ($token !== null) {
        return $token;
    }

    $path = dmr_csrf_path();

    $existing = is_readable($path) ? trim((string) @file_get_contents($path)) : '';
    if (preg_match('/^[0-9a-f]{64}$/', $existing)) {
        $token = $existing;

        return $token;
    }

    try {
        $candidate = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // No CSPRNG on this host: a weaker value is still better than none.
        $candidate = hash('sha256', uniqid('', true) . microtime(true));
    }

    // 'x' creates the file exclusively, so two concurrent first requests agree
    // on the token of the winner instead of overwriting each other.
    $handle = @fopen($path, 'x');
    if ($handle !== false) {
        fwrite($handle, $candidate . "\n");
        fclose($handle);
        @chmod($path, 0600);

        $token = $candidate;

        return $token;
    }

    // The file exists but does not hold a token yet, or it was written
    // between the read above and this call: reuse what is there now and only
    // overwrite a file that still is not a token. Without that a truncated
    // file would disable the check until the next reboot.
    $existing = is_readable($path) ? trim((string) @file_get_contents($path)) : '';
    if (preg_match('/^[0-9a-f]{64}$/', $existing)) {
        $token = $existing;

        return $token;
    }

    if (@file_put_contents($path, $candidate . "\n") !== false) {
        @chmod($path, 0600);
        $token = $candidate;

        return $token;
    }

    $token = '';

    return $token;
}

/**
 * Whether the request carries the token of this installation.
 *
 * An empty expected token means the token file could not be written, which
 * only happens on a host where neither /var/run nor /tmp is writable. The
 * check is skipped in that case rather than turning the page into a set of
 * buttons that always fails.
 *
 * @param mixed $candidate
 *
 * @return bool
 */
function dmr_csrf_valid($candidate)
{
    $expected = dmr_csrf_token();
    if ($expected === '') {
        return true;
    }

    $candidate = is_string($candidate) ? $candidate : '';
    if ($candidate === '') {
        return false;
    }

    return hash_equals($expected, $candidate);
}


/**
 * The locale of the Unraid WebGUI, for example "zh_CN".
 *
 * The WebGUI keeps the locale in the session, the login page falls back to
 * the login locale and the plugin manager reads it from the dynamix
 * configuration. All three are checked, so the plugin also works when it is
 * called outside a WebGUI request.
 *
 * @return string the locale, or an empty string when it is unknown
 */
function dmr_locale()
{
    $login_locale = $GLOBALS['login_locale'] ?? '';
    $locale = $_SESSION['locale'] ?? $login_locale;

    if (!is_string($locale) || $locale === '') {
        $config = '/boot/config/plugins/dynamix/dynamix.cfg';
        $parsed = @parse_ini_file($config, true);
        $locale = is_array($parsed) ? ($parsed['display']['locale'] ?? '') : '';
    }

    return is_string($locale) ? $locale : '';
}

/**
 * Flatten a nested translation array into a dotted key map.
 *
 * @param array<mixed,mixed> $array
 * @param string $prefix
 *
 * @return array<string,string>
 */
function dmr_flatten_translations(array $array, $prefix = '')
{
    $result = array();

    foreach ($array as $key => $value) {
        $name = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $result = array_merge($result, dmr_flatten_translations($value, $name));
        } elseif (is_string($value) && $value !== '') {
            $result[$name] = $value;
        }
    }

    return $result;
}

/**
 * The translation table of the plugin.
 *
 * The English file is the base, the file of the current locale overrides it.
 * A key that is missing from the locale file therefore falls back to the
 * English text instead of disappearing.
 *
 * @return array<string,string>
 */
function dmr_translations()
{
    static $table = null;

    if ($table !== null) {
        return $table;
    }

    $table = array();

    $english = DMR_PLUGIN_ROOT . '/locales/en_US.json';
    if (is_readable($english)) {
        $decoded = json_decode((string) file_get_contents($english), true);
        if (is_array($decoded)) {
            $table = dmr_flatten_translations($decoded);
        }
    }

    $locale = dmr_locale();
    if ($locale !== '' && $locale !== 'en_US') {
        $translated = DMR_PLUGIN_ROOT . '/locales/' . $locale . '.json';
        if (is_readable($translated)) {
            $decoded = json_decode((string) file_get_contents($translated), true);
            if (is_array($decoded)) {
                $table = array_replace($table, dmr_flatten_translations($decoded));
            }
        }
    }

    return $table;
}

/**
 * Translate a key of the plugin translation table.
 *
 * The lookup goes through the file of the current locale first and through
 * the English base file second, so an incomplete translation falls back to
 * English instead of disappearing. A key that is in neither file is handed
 * to the translation table of Unraid itself, which is what a contributor
 * gets when the key happens to be a plain English phrase that Unraid knows.
 *
 * The title of the page and the title of its card in the User Utilities
 * grid are not translated here: Unraid renders both from the Title header
 * of the page file, against its own translation table, before the body of
 * the page runs. A plugin local table cannot reach them, so they keep the
 * name of the product. The heading inside the page is translated.
 *
 * @param string $key
 *
 * @return string
 */
function dmr_t($key)
{
    $table = dmr_translations();

    if (isset($table[$key])) {
        return $table[$key];
    }

    if (function_exists('_')) {
        $translated = _($key);
        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }
    }

    return $key;
}

/**
 * Render a set of translations as a JSON object for the page script.
 *
 * The values are escaped so that the result can be embedded in a script
 * element without ending it.
 *
 * @param string[] $keys
 *
 * @return string
 */
function dmr_js_translations(array $keys)
{
    $table = dmr_translations();
    $result = array();

    foreach ($keys as $key) {
        $result[$key] = isset($table[$key]) ? $table[$key] : $key;
    }

    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    return (string) json_encode($result, $flags);
}

/**
 * Render the opening tag of a card.
 *
 * Unraid 7 dropped the Unraid 6 "fieldset" and "legend" styles, so the cards
 * of this page are built from plain HTML and styled by
 * sheets/DockerModelRunner.css, which Unraid includes automatically for this
 * page.
 *
 * @param string $title
 * @param string $icon  a Font Awesome name, without the fa- prefix
 * @param string $id    optional id of the card element
 * @param string $class optional extra class
 *
 * @return string
 */
function dmr_card_open($title, $icon, $id = '', $class = '')
{
    $id_attr = $id === '' ? '' : ' id="' . dmr_esc($id) . '"';
    $class_attr = $class === '' ? '' : ' ' . dmr_esc($class);

    return '<section class="dmr-card' . $class_attr . '"' . $id_attr . '>'
        . '<header class="dmr-card__head">'
        . '<i class="fa fa-' . dmr_esc($icon) . ' dmr-card__icon"></i>'
        . '<h2 class="dmr-card__title">' . dmr_esc($title) . '</h2>'
        . '</header>'
        . '<div class="dmr-card__body">';
}

/**
 * Render the closing tag of a card.
 *
 * @return string
 */
function dmr_card_close()
{
    return '</div></section>';
}

/**
 * Render a label/value row.
 *
 * The value is emitted as it is, so it may contain markup such as a code
 * element. Callers that pass untrusted data have to escape it first.
 *
 * @param string $label
 * @param string $value
 * @param string $id    optional id of the value element
 *
 * @return string
 */
function dmr_kv($label, $value, $id = '')
{
    $id_attr = $id === '' ? '' : ' id="' . dmr_esc($id) . '"';

    return '<div class="dmr-kv">'
        . '<div class="dmr-kv__k">' . dmr_esc($label) . '</div>'
        . '<div class="dmr-kv__v"' . $id_attr . '>' . $value . '</div>'
        . '</div>';
}

/**
 * Render a status pill.
 *
 * The tone is expressed with the colour classes that Unraid ships for every
 * theme, so the pill stays readable in the light and the dark themes.
 *
 * @param string $id
 * @param string $label
 * @param string $tone one of idle, ok, warn, err
 *
 * @return string
 */
function dmr_pill($id, $label, $tone = 'idle')
{
    $classes = array('dmr-pill');

    switch ($tone) {
        case 'ok':
            $classes[] = 'green-text';
            break;
        case 'warn':
            $classes[] = 'orange-text';
            break;
        case 'err':
            $classes[] = 'red-text';
            break;
    }

    $id_attr = $id === '' ? '' : ' id="' . dmr_esc($id) . '"';

    return '<span class="' . dmr_esc(implode(' ', $classes)) . '"' . $id_attr . '>'
        . '<i class="dmr-pill__dot"></i>'
        . '<span class="dmr-pill__text">' . dmr_esc($label) . '</span>'
        . '</span>';
}
