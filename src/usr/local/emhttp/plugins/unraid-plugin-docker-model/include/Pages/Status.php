<?php
/**
 * Status tab of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 */

require_once dirname(__DIR__) . '/common.php';

$info = dmr_plugin_info();
$binaryInstalled = dmr_binary_installed();
$cliVersion = dmr_cli_version();
$runnerRunning = dmr_runner_running();
$models = dmr_models();

if (!$binaryInstalled) {
    $cliText = 'not installed';
} elseif ($cliVersion === null) {
    $cliText = 'unavailable';
} elseif ($cliVersion['code'] !== 0) {
    $cliText = trim($cliVersion['stdout'] . ' ' . $cliVersion['stderr']);
} else {
    $cliText = $cliVersion['stdout'];
}

if ($cliText === '') {
    $cliText = 'no output';
}
?>
<table class="unraid">
    <tr>
        <td colspan="2" style="padding-bottom:8px">
            <strong>Docker Model Runner</strong> &mdash; the <code>docker model</code>
            CLI plugin for the Unraid Docker CLI.
        </td>
    </tr>
    <?php
    echo dmr_row('Plugin version', $info['plugin_version']);
    echo dmr_row('Bundled docker-model', $info['upstream_version']);
    echo dmr_row('Upstream artifact', $info['upstream_source']);
    echo dmr_row(
        'CLI binary',
        $binaryInstalled ? DMR_CLI_BINARY . ' (installed)' : DMR_CLI_BINARY . ' (missing)'
    );
    echo dmr_row('docker model version', $cliText);
    echo dmr_row('Model Runner', $runnerRunning ? 'running' : 'not running');
    ?>
</table>

<br>
<div class="fieldset">
    <div class="legend">Local models</div>
    <table class="unraid">
        <?php if (count($models) === 0) { ?>
        <tr>
            <td>No models found. Pull one with
                <code>docker model pull ai/smollm2:360M-Q4_K_M</code>.
            </td>
        </tr>
        <?php } else { ?>
        <tr>
            <td style="width:280px;vertical-align:top"><strong>Models</strong></td>
            <td><?php echo dmr_esc(implode("\n", $models)); ?></td>
        </tr>
        <?php } ?>
    </table>
</div>

<br>
<div class="fieldset">
    <div class="legend">Notes</div>
    <table class="unraid">
        <tr>
            <td>
                <code>docker model pull</code> does not honour the
                <code>registry-mirrors</code> setting of <code>daemon.json</code>.
                On a restricted network pull through a proxy prefix, for example
                <code>docker model pull gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M</code>.
            </td>
        </tr>
        <tr>
            <td>
                The runner API listens on <code>127.0.0.1:12434</code> and is
                unauthenticated. Do not expose that port to untrusted networks.
            </td>
        </tr>
    </table>
</div>
