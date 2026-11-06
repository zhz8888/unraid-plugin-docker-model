<?php
/**
 * Settings tab of the unraid-plugin-docker-model WebGUI page.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 */

require_once dirname(__DIR__) . '/common.php';

$info = dmr_plugin_info();
$runnerRunning = dmr_runner_running();
$binaryInstalled = dmr_binary_installed();
?>
<div class="fieldset">
    <div class="legend">Model Runner</div>
    <table class="unraid">
        <tr>
            <td style="width:280px;vertical-align:top">
                <strong>Current state</strong>
            </td>
            <td><?php echo $runnerRunning ? 'running' : 'not running'; ?></td>
        </tr>
        <tr>
            <td style="vertical-align:top"><strong>Install with CPU only</strong></td>
            <td>
                <button type="button" class="button" data-dmr-action="install-runner-cpu"
                        <?php echo $runnerRunning ? 'disabled' : ''; ?>>
                    Install runner (CPU)
                </button>
                <div class="hint">
                    Runs <code>docker model install-runner --gpu none</code> in the
                    background. The runner image is about 1&nbsp;GB and is pulled
                    on demand.
                </div>
            </td>
        </tr>
        <tr>
            <td style="vertical-align:top"><strong>Install with GPU</strong></td>
            <td>
                <button type="button" class="button" data-dmr-action="install-runner-gpu"
                        <?php echo $runnerRunning ? 'disabled' : ''; ?>>
                    Install runner (GPU auto)
                </button>
                <div class="hint">
                    Runs <code>docker model install-runner --gpu auto</code> in the
                    background. Requires an NVIDIA or AMD GPU with the matching
                    container runtime support.
                </div>
            </td>
        </tr>
        <tr>
            <td style="vertical-align:top"><strong>Remove runner</strong></td>
            <td>
                <button type="button" class="button" data-dmr-action="uninstall-runner"
                        <?php echo $runnerRunning ? '' : 'disabled'; ?>>
                    Uninstall runner
                </button>
                <div class="hint">
                    Runs <code>docker model uninstall-runner</code>. The runner
                    image and the model volume are kept, so downloaded models
                    survive.
                </div>
            </td>
        </tr>
    </table>
</div>

<div id="dmr-action-result" style="margin-top:10px"></div>

<script type="text/javascript">
(function () {
    function show(text, hint) {
        var box = document.getElementById('dmr-action-result');
        if (!box) {
            return;
        }
        box.textContent = '';
        var line = document.createElement('strong');
        line.textContent = text;
        box.appendChild(line);
        if (hint) {
            var note = document.createElement('div');
            note.className = 'hint';
            note.textContent = hint;
            box.appendChild(note);
        }
    }

    function post(action) {
        show('Working ...', '');
        $.post('/plugins/unraid-plugin-docker-model/include/data/Action.php',
            {action: action},
            function (data) {
                var message = (data && data.message) ? data.message : 'Done.';
                show(message, 'Reload the page to see the updated state.');
            },
            'json').fail(function () {
                show('The action failed.', 'See the WebGUI log for details.');
            });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.getAttribute) {
            return;
        }
        var action = target.getAttribute('data-dmr-action');
        if (action) {
            event.preventDefault();
            post(action);
        }
    });
})();
</script>

<br>
<div class="fieldset">
    <div class="legend">Installation</div>
    <table class="unraid">
        <?php
        echo dmr_row('Plugin version', $info['plugin_version']);
        echo dmr_row('Bundled docker-model', $info['upstream_version']);
        echo dmr_row('CLI binary', $binaryInstalled ? 'installed' : 'missing');
        ?>
        <tr>
            <td style="vertical-align:top"><strong>Manual commands</strong></td>
            <td>
                <pre style="margin:0">docker model version
docker model install-runner --gpu none
docker model install-runner --gpu auto
docker model uninstall-runner
docker model list</pre>
            </td>
        </tr>
    </table>
</div>
