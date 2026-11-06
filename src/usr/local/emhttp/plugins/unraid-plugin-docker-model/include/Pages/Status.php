<?php
/**
 * Status tab of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The static part of the page renders immediately. Everything that needs a
 * `docker` call is loaded afterwards from include/data/Status.php, because
 * such a call can block for as long as its timeout and would otherwise delay
 * the whole WebGUI tab.
 */

require_once dirname(__DIR__) . '/common.php';

$info = dmr_plugin_info();
$endpoint = '/plugins/' . DMR_PLUGIN_NAME . '/include/data/Status.php';
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
    echo dmr_row('CLI binary', DMR_CLI_BINARY);
    ?>
    <tr>
        <td style="width:280px;vertical-align:top"><strong>Installed</strong></td>
        <td id="dmr-status-binary">checking ...</td>
    </tr>
    <tr>
        <td style="vertical-align:top"><strong>docker model version</strong></td>
        <td id="dmr-status-cli">checking ...</td>
    </tr>
    <tr>
        <td style="vertical-align:top"><strong>Model Runner</strong></td>
        <td id="dmr-status-runner">checking ...</td>
    </tr>
</table>

<br>
<div class="fieldset">
    <div class="legend">Local models</div>
    <div id="dmr-status-models"><em>loading ...</em></div>
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

<script type="text/javascript">
(function () {
    function setText(id, text) {
        var element = document.getElementById(id);
        if (element) {
            element.textContent = text;
        }
    }

    function renderModels(models) {
        var box = document.getElementById('dmr-status-models');
        if (!box) {
            return;
        }
        box.textContent = '';
        if (!models || models.length === 0) {
            box.textContent = 'No models found. Pull one with '
                + 'docker model pull ai/smollm2:360M-Q4_K_M.';
            return;
        }
        var list = document.createElement('pre');
        list.style.margin = '0';
        list.textContent = models.join('\n');
        box.appendChild(list);
    }

    $.post('<?php echo dmr_esc($endpoint); ?>', {}, function (data) {
        setText('dmr-status-binary', (data && data.binary) ? data.binary : 'unknown');
        setText('dmr-status-cli', (data && data.cli) ? data.cli : 'unavailable');
        setText('dmr-status-runner', (data && data.runner) ? data.runner : 'unknown');
        renderModels(data ? data.models : []);
    }, 'json').fail(function () {
        setText('dmr-status-binary', 'unknown');
        setText('dmr-status-cli', 'unavailable');
        setText('dmr-status-runner', 'unknown');
        var box = document.getElementById('dmr-status-models');
        if (box) {
            box.textContent = 'Could not load the status. See the WebGUI log.';
        }
    });
})();
</script>
