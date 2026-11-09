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
 *
 * Every user facing string comes from the translation table in locales/, so
 * the page follows the language of the Unraid WebGUI.
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
            <div class="dmr-hero__title"><?php echo dmr_esc(dmr_t('title')); ?></div>
            <p class="dmr-hero__text"><?php echo dmr_t('hero.text'); ?></p>
            <div class="dmr-pills">
                <?php echo dmr_pill('dmr-pill-binary', dmr_t('pill.cli'), 'idle'); ?>
                <?php echo dmr_pill('dmr-pill-runner', dmr_t('pill.runner'), 'idle'); ?>
                <?php echo dmr_pill('dmr-pill-models', dmr_t('pill.models'), 'idle'); ?>
            </div>
        </div>
        <button type="button" class="button button-small" id="dmr-refresh"><?php echo dmr_esc(dmr_t('hero.refresh')); ?></button>
    </div>

    <div class="dmr-grid">
        <?php
        echo dmr_card_open(dmr_t('card.status'), 'heartbeat');
        echo dmr_kv(dmr_t('label.plugin_version'), dmr_esc($info['plugin_version']));
        echo dmr_kv(dmr_t('label.upstream_version'), dmr_esc($info['upstream_version']));
        echo dmr_kv(dmr_t('label.upstream_artifact'), '<code>' . dmr_esc($info['upstream_source']) . '</code>');
        echo dmr_kv(dmr_t('label.cli_binary'), '<code>' . dmr_esc(DMR_CLI_BINARY) . '</code>');
        echo dmr_kv(dmr_t('label.cli_version'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-status-cli');
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.runner'), 'play-circle');
        echo '<p class="dmr-text">';
        echo dmr_t('runner.intro');
        echo '</p>';
        echo '<div class="dmr-state">';
        echo dmr_pill('dmr-pill-runner-state', dmr_t('state.checking'), 'idle');
        echo '</div>';
        echo '<div class="dmr-actions">';
        echo '<button type="button" class="button" data-dmr-action="install-runner-cpu" id="dmr-btn-cpu" disabled>' . dmr_esc(dmr_t('runner.install_cpu')) . '</button>';
        echo '<button type="button" class="button" data-dmr-action="install-runner-gpu" id="dmr-btn-gpu" disabled>' . dmr_esc(dmr_t('runner.install_gpu')) . '</button>';
        echo '<button type="button" class="button" data-dmr-action="uninstall-runner" id="dmr-btn-remove" disabled>' . dmr_esc(dmr_t('runner.uninstall')) . '</button>';
        echo '</div>';
        echo '<p class="dmr-hint">';
        echo dmr_t('runner.hint');
        echo '</p>';
        echo '<div id="dmr-action-result" class="dmr-result" hidden></div>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.models'), 'database');
        echo '<div id="dmr-models" class="dmr-models"><p class="dmr-muted">' . dmr_esc(dmr_t('state.loading')) . '</p></div>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.install'), 'wrench');
        echo dmr_kv(dmr_t('label.cli_directory'), '<code>' . dmr_esc(DMR_CLI_DIR) . '</code>');
        echo dmr_kv(dmr_t('label.installed_binary'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-status-binary');
        echo '<p class="dmr-hint">';
        echo dmr_t('install.hint');
        echo '</p>';
        echo '<pre class="dmr-code">docker model version'
            . "\n" . 'docker model install-runner --gpu none'
            . "\n" . 'docker model install-runner --gpu auto'
            . "\n" . 'docker model uninstall-runner'
            . "\n" . 'docker model list</pre>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.notes'), 'exclamation-triangle', 'dmr-card-notes', 'dmr-card--wide');
        echo '<ul class="dmr-notes">';
        echo '<li>' . dmr_t('notes.registry') . '</li>';
        echo '<li>' . dmr_t('notes.api') . '</li>';
        echo '</ul>';
        echo dmr_card_close();
        ?>
    </div>
</div>

<script type="text/javascript">
(function () {
    var statusEndpoint = '<?php echo dmr_esc($statusEndpoint); ?>';
    var actionEndpoint = '<?php echo dmr_esc($actionEndpoint); ?>';
    var t = <?php echo dmr_js_translations(array(
        'state.checking',
        'state.loading',
        'state.running',
        'state.stopped',
        'state.unknown',
        'state.installed',
        'state.missing',
        'state.cli_installed',
        'state.cli_missing',
        'state.runner_running',
        'state.runner_stopped',
        'state.models_count',
        'models.empty',
        'action.working',
        'action.done',
        'action.refreshed',
        'action.failed',
        'action.log_hint',
    )); ?>;

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
            empty.textContent = t['models.empty'];
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

            var binaryOk = binary === 'installed';
            var runnerOk = runner === 'running';

            setText('dmr-status-cli', cli);
            setText('dmr-status-binary', binaryOk ? t['state.installed'] : t['state.missing']);

            setPill('dmr-pill-binary', binaryOk ? t['state.cli_installed'] : t['state.cli_missing'],
                binaryOk ? 'ok' : 'err');
            setPill('dmr-pill-runner', runnerOk ? t['state.runner_running'] : t['state.runner_stopped'],
                runnerOk ? 'ok' : 'idle');
            setPill('dmr-pill-models', t['state.models_count'].replace('%s', models.length),
                models.length > 0 ? 'ok' : 'idle');
            setPill('dmr-pill-runner-state', runnerOk ? t['state.running'] : t['state.stopped'],
                runnerOk ? 'ok' : 'idle');

            setButton('dmr-btn-cpu', binaryOk && !runnerOk);
            setButton('dmr-btn-gpu', binaryOk && !runnerOk);
            setButton('dmr-btn-remove', binaryOk && runnerOk);

            renderModels(models);
        }, 'json').fail(function () {
            setText('dmr-status-cli', 'unavailable');
            setText('dmr-status-binary', t['state.unknown']);
            setPill('dmr-pill-binary', t['state.unknown'], 'warn');
            setPill('dmr-pill-runner', t['state.unknown'], 'warn');
            setPill('dmr-pill-models', t['state.unknown'], 'warn');
            setPill('dmr-pill-runner-state', t['state.unknown'], 'warn');
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
        showResult(t['action.working'], '');
        $.post(actionEndpoint, {action: action}, function (data) {
            var message = (data && data.message) ? data.message : t['action.done'];
            showResult(message, t['action.refreshed']);
            window.setTimeout(loadStatus, 3000);
        }, 'json').fail(function () {
            showResult(t['action.failed'], t['action.log_hint']);
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
