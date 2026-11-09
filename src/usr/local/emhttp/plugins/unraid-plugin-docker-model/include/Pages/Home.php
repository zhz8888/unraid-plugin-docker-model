<?php
/**
 * Home page of the unraid-plugin-docker-model WebGUI.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The page is a grid of cards, one card per function: the state of the
 * plugin, the Model Runner deployment, the local models, the installation
 * and the caveats that come with the runner. Everything that needs a docker
 * call is fetched from include/data/Status.php after the page has been
 * rendered, because such a call can block for as long as its timeout and
 * would otherwise delay the whole WebGUI page.
 */

require_once dirname(__DIR__) . '/common.php';

$info = dmr_plugin_info();
$statusEndpoint = '/plugins/' . DMR_PLUGIN_NAME . '/include/data/Status.php';
$actionEndpoint = '/plugins/' . DMR_PLUGIN_NAME . '/include/data/Action.php';
?>
<div class="dmr">
    <div class="dmr-hero">
        <img class="dmr-hero__img"
             src="/plugins/<?php echo dmr_esc(DMR_PLUGIN_NAME); ?>/unraid-plugin-docker-model.png"
             alt="">
        <div class="dmr-hero__body">
            <div class="dmr-hero__title">Docker Model Runner</div>
            <p class="dmr-hero__text">
                The <code>docker model</code> CLI plugin for the Unraid Docker CLI.
                Models are pulled and served locally, the runner container is
                managed from this page.
            </p>
            <div class="dmr-pills">
                <?php echo dmr_pill('dmr-pill-binary', 'CLI', 'idle'); ?>
                <?php echo dmr_pill('dmr-pill-runner', 'Runner', 'idle'); ?>
                <?php echo dmr_pill('dmr-pill-models', 'Models', 'idle'); ?>
            </div>
        </div>
        <button type="button" class="button button-small" id="dmr-refresh">Refresh</button>
    </div>

    <div class="dmr-grid">
        <?php
        echo dmr_card_open('Status', 'heartbeat');
        echo dmr_kv('Plugin version', dmr_esc($info['plugin_version']));
        echo dmr_kv('Bundled docker-model', dmr_esc($info['upstream_version']));
        echo dmr_kv('Upstream artifact', '<code>' . dmr_esc($info['upstream_source']) . '</code>');
        echo dmr_kv('CLI binary', '<code>' . dmr_esc(DMR_CLI_BINARY) . '</code>');
        echo dmr_kv('docker model version', '<span class="dmr-muted">checking ...</span>', 'dmr-status-cli');
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open('Model Runner', 'play-circle');
        echo '<p class="dmr-text">';
        echo 'The runner is a container that serves the models. It is pulled on demand.';
        echo '</p>';
        echo '<div class="dmr-state">';
        echo dmr_pill('dmr-pill-runner-state', 'checking ...', 'idle');
        echo '</div>';
        echo '<div class="dmr-actions">';
        echo '<button type="button" class="button" data-dmr-action="install-runner-cpu" id="dmr-btn-cpu" disabled>Install (CPU)</button>';
        echo '<button type="button" class="button" data-dmr-action="install-runner-gpu" id="dmr-btn-gpu" disabled>Install (GPU auto)</button>';
        echo '<button type="button" class="button" data-dmr-action="uninstall-runner" id="dmr-btn-remove" disabled>Uninstall</button>';
        echo '</div>';
        echo '<p class="dmr-hint">';
        echo 'The install pulls an image of about 1 GB and runs in the background.';
        echo ' The progress is written to <code>/var/log/docker-model-runner-install.log</code>.';
        echo '</p>';
        echo '<div id="dmr-action-result" class="dmr-result" hidden></div>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open('Local models', 'database');
        echo '<div id="dmr-models" class="dmr-models"><p class="dmr-muted">loading ...</p></div>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open('Installation', 'wrench');
        echo dmr_kv('CLI directory', '<code>' . dmr_esc(DMR_CLI_DIR) . '</code>');
        echo dmr_kv('Installed binary', '<span class="dmr-muted">checking ...</span>', 'dmr-status-binary');
        echo '<p class="dmr-hint">The same commands from a terminal:</p>';
        echo '<pre class="dmr-code">docker model version'
            . "
" . 'docker model install-runner --gpu none'
            . "
" . 'docker model install-runner --gpu auto'
            . "
" . 'docker model uninstall-runner'
            . "
" . 'docker model list</pre>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open('Notes', 'exclamation-triangle', 'dmr-card-notes', 'dmr-card--wide');
        echo '<ul class="dmr-notes">';
        echo '<li><code>docker model pull</code> does not honour the <code>registry-mirrors</code>'
            . ' setting of <code>daemon.json</code>. On a restricted network pull through a'
            . ' proxy prefix, for example <code>docker model pull gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M</code>.</li>';
        echo '<li>The runner API listens on <code>127.0.0.1:12434</code> and is unauthenticated.'
            . ' Do not expose that port to untrusted networks.</li>';
        echo '</ul>';
        echo dmr_card_close();
        ?>
    </div>
</div>

<script type="text/javascript">
(function () {
    var statusEndpoint = '<?php echo dmr_esc($statusEndpoint); ?>';
    var actionEndpoint = '<?php echo dmr_esc($actionEndpoint); ?>';

    function setText(id, value) {
        var element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    function setPill(id, label, tone) {
        var element = document.getElementById(id);
        if (!element) {
            return;
        }
        var colour = '';
        if (tone === 'ok') {
            colour = ' green-text';
        } else if (tone === 'warn') {
            colour = ' orange-text';
        } else if (tone === 'err') {
            colour = ' red-text';
        }
        element.className = 'dmr-pill' + colour;
        var span = element.querySelector('.dmr-pill__text');
        if (span) {
            span.textContent = label;
        }
    }

    function setButton(id, enabled) {
        var element = document.getElementById(id);
        if (element) {
            element.disabled = !enabled;
        }
    }

    function renderModels(models) {
        var box = document.getElementById('dmr-models');
        if (!box) {
            return;
        }
        while (box.firstChild) {
            box.removeChild(box.firstChild);
        }
        if (!models || models.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'dmr-muted';
            empty.textContent = 'No models found. Pull one with docker model pull ai/smollm2:360M-Q4_K_M.';
            box.appendChild(empty);
            return;
        }
        var list = document.createElement('ul');
        list.className = 'dmr-list';
        for (var i = 0; i < models.length; i++) {
            var item = document.createElement('li');
            item.className = 'dmr-list__item';
            item.textContent = models[i];
            list.appendChild(item);
        }
        box.appendChild(list);
    }

    function loadStatus() {
        $.post(statusEndpoint, {}, function (data) {
            var binary = (data && data.binary) ? data.binary : 'missing';
            var runner = (data && data.runner) ? data.runner : 'not running';
            var cli = (data && data.cli) ? data.cli : 'unavailable';
            var models = (data && data.models) ? data.models : [];

            setText('dmr-status-cli', cli);
            setText('dmr-status-binary', binary);

            var binaryOk = binary === 'installed';
            var runnerOk = runner === 'running';

            setPill('dmr-pill-binary', binaryOk ? 'CLI installed' : 'CLI missing', binaryOk ? 'ok' : 'err');
            setPill('dmr-pill-runner', runnerOk ? 'Runner running' : 'Runner stopped', runnerOk ? 'ok' : 'idle');
            setPill('dmr-pill-models', models.length + ' model(s)', models.length > 0 ? 'ok' : 'idle');
            setPill('dmr-pill-runner-state', runnerOk ? 'running' : 'not running', runnerOk ? 'ok' : 'idle');

            setButton('dmr-btn-cpu', binaryOk && !runnerOk);
            setButton('dmr-btn-gpu', binaryOk && !runnerOk);
            setButton('dmr-btn-remove', binaryOk && runnerOk);

            renderModels(models);
        }, 'json').fail(function () {
            setText('dmr-status-cli', 'unavailable');
            setText('dmr-status-binary', 'unknown');
            setPill('dmr-pill-binary', 'unknown', 'warn');
            setPill('dmr-pill-runner', 'unknown', 'warn');
            setPill('dmr-pill-models', 'unknown', 'warn');
            setPill('dmr-pill-runner-state', 'unknown', 'warn');
        });
    }

    function showResult(text, hint) {
        var box = document.getElementById('dmr-action-result');
        if (!box) {
            return;
        }
        box.hidden = false;
        while (box.firstChild) {
            box.removeChild(box.firstChild);
        }
        var line = document.createElement('strong');
        line.textContent = text;
        box.appendChild(line);
        if (hint) {
            var note = document.createElement('span');
            note.className = 'dmr-hint';
            note.textContent = hint;
            box.appendChild(note);
        }
    }

    function runAction(action) {
        showResult('Working ...', '');
        $.post(actionEndpoint, {action: action}, function (data) {
            var message = (data && data.message) ? data.message : 'Done.';
            showResult(message, 'The state is refreshed automatically.');
            window.setTimeout(loadStatus, 3000);
        }, 'json').fail(function () {
            showResult('The action failed.', 'See the WebGUI log for details.');
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }
        var button = target.closest('[data-dmr-action]');
        if (button) {
            event.preventDefault();
            runAction(button.getAttribute('data-dmr-action'));
            return;
        }
        if (target.closest('#dmr-refresh')) {
            event.preventDefault();
            loadStatus();
        }
    });

    loadStatus();
})();
</script>
