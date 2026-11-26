<?php
/**
 * Home page of the unraid-plugin-docker-model WebGUI.
 *
 * Copyright (C) 2026 zhz8888
 * SPDX-License-Identifier: MIT
 *
 * The page is a grid of cards, one card per function: the state of the
 * plugin, the Model Runner deployment, the network the runner is published
 * on, the local models, the installation and the caveats that come with the
 * runner. Everything that needs a docker call is fetched from
 * include/data/Status.php after the page has been rendered, because such a
 * call can block for as long as its timeout and would otherwise delay the
 * whole WebGUI page.
 *
 * The cards are equal sized on purpose: the grid stretches every card of a
 * row to the height of the tallest one, so a row lines up instead of looking
 * ragged, and the model list takes the space its card has left instead of
 * stretching the whole row.
 *
 * Every user facing string comes from the translation table in locales/, so
 * the page follows the language of the Unraid WebGUI.
 */

require_once dirname(__DIR__) . '/common.php';

$info = dmr_plugin_info();
$settings = dmr_settings();
$host = $settings['host'];
// The address is one of the two presets, or the value of a custom entry. The
// page keeps the value itself and only uses this flag to preselect the select.
$preset = in_array($host, array('0.0.0.0', '127.0.0.1'), true) ? $host : 'custom';
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
        echo '<h3 class="dmr-section-title">' . dmr_esc(dmr_t('label.cli_version')) . '</h3>';
        echo dmr_kv(dmr_t('label.client_version'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-version-client');
        echo dmr_kv(dmr_t('label.client_platform'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-version-platform');
        echo dmr_kv(dmr_t('label.server_version'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-version-server');
        echo dmr_kv(dmr_t('label.server_engine'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-version-engine');
        echo '<p class="dmr-notice dmr-notice--err" id="dmr-version-error" hidden></p>';
        echo '<details class="dmr-raw" id="dmr-version-raw-wrap" hidden>';
        echo '<summary>' . dmr_esc(dmr_t('version.raw')) . '</summary>';
        echo '<pre class="dmr-code" id="dmr-version-raw"></pre>';
        echo '</details>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.runner'), 'play-circle');
        echo '<p class="dmr-text">' . dmr_t('runner.intro') . '</p>';
        echo '<div class="dmr-state">';
        echo dmr_pill('dmr-pill-runner-state', dmr_t('state.checking'), 'idle');
        echo '</div>';
        echo '<div class="dmr-actions">';
        echo '<button type="button" class="button" data-dmr-action="install-runner-cpu" id="dmr-btn-cpu" disabled>' . dmr_esc(dmr_t('runner.install_cpu')) . '</button>';
        echo '<button type="button" class="button" data-dmr-action="install-runner-gpu" id="dmr-btn-gpu" disabled>' . dmr_esc(dmr_t('runner.install_gpu')) . '</button>';
        echo '<button type="button" class="button dmr-button--warn" data-dmr-action="stop-runner" id="dmr-btn-stop" disabled>' . dmr_esc(dmr_t('runner.stop')) . '</button>';
        echo '<button type="button" class="button dmr-button--warn" data-dmr-action="uninstall-runner" id="dmr-btn-remove" disabled>' . dmr_esc(dmr_t('runner.uninstall')) . '</button>';
        echo '</div>';
        echo '<p class="dmr-hint">' . dmr_t('runner.hint') . '</p>';
        echo '<p class="dmr-hint">' . dmr_t('runner.stop_hint') . '</p>';
        echo '<div id="dmr-action-result" class="dmr-result" hidden></div>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.network'), 'globe');
        echo '<p class="dmr-text">' . dmr_t('network.intro') . '</p>';
        echo '<div class="dmr-field">';
        echo '<label class="dmr-field__label" for="dmr-host-select">' . dmr_esc(dmr_t('label.listen_address')) . '</label>';
        echo '<div class="dmr-field__control">';
        echo '<select id="dmr-host-select" class="dmr-select">';
        echo '<option value="0.0.0.0"' . ($preset === '0.0.0.0' ? ' selected' : '') . '>' . dmr_esc(dmr_t('network.preset_lan')) . '</option>';
        echo '<option value="127.0.0.1"' . ($preset === '127.0.0.1' ? ' selected' : '') . '>' . dmr_esc(dmr_t('network.preset_local')) . '</option>';
        echo '<option value="custom"' . ($preset === 'custom' ? ' selected' : '') . '>' . dmr_esc(dmr_t('network.preset_custom')) . '</option>';
        echo '</select>';
        echo '<input type="text" id="dmr-host-custom" class="dmr-input" value="' . dmr_esc($preset === 'custom' ? $host : '') . '"'
            . ' placeholder="192.168.1.10" spellcheck="false" autocomplete="off"'
            . ($preset === 'custom' ? '' : ' hidden') . '>';
        echo '</div>';
        echo '</div>';
        echo dmr_kv(dmr_t('label.published_ports'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-runner-ports');
        echo dmr_kv(dmr_t('label.config_file'), '<code>' . dmr_esc(DMR_CONFIG_FILE) . '</code>');
        echo '<p class="dmr-hint">' . dmr_t('network.fixed_port') . '</p>';
        echo '<p class="dmr-hint">' . dmr_t('network.security') . '</p>';
        echo '<div class="dmr-actions">';
        echo '<button type="button" class="button" id="dmr-btn-save">' . dmr_esc(dmr_t('network.save')) . '</button>';
        echo '<button type="button" class="button" data-dmr-action="reinstall-runner" id="dmr-btn-recreate" disabled>' . dmr_esc(dmr_t('network.recreate')) . '</button>';
        echo '</div>';
        echo '<p class="dmr-hint">' . dmr_t('network.apply_hint') . '</p>';
        echo '<div id="dmr-settings-result" class="dmr-result" hidden></div>';
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
        echo dmr_kv(dmr_t('label.cli_binary'), '<code>' . dmr_esc(DMR_CLI_BINARY) . '</code>');
        echo dmr_kv(dmr_t('label.installed_binary'), '<span class="dmr-muted">' . dmr_esc(dmr_t('state.checking')) . '</span>', 'dmr-status-binary');
        echo '<p class="dmr-hint">' . dmr_t('install.hint') . '</p>';
        echo '<pre class="dmr-code">docker model version'
            . "\n" . 'docker model install-runner --gpu none --host ' . dmr_esc($host)
            . "\n" . 'docker model install-runner --gpu auto --host ' . dmr_esc($host)
            . "\n" . 'docker model stop-runner'
            . "\n" . 'docker model reinstall-runner --host ' . dmr_esc($host)
            . "\n" . 'docker model uninstall-runner'
            . "\n" . 'docker model list</pre>';
        echo dmr_card_close();
        ?>

        <?php
        echo dmr_card_open(dmr_t('card.notes'), 'exclamation-triangle');
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
    // Read by dmr_csrf_valid() in the action endpoint: a page of another
    // origin cannot read the file the token comes from.
    var actionToken = '<?php echo dmr_esc(dmr_csrf_token()); ?>';
    var t = <?php echo dmr_js_translations(array(
        'state.checking',
        'state.unknown',
        'state.running',
        'state.stopped',
        'state.installed',
        'state.missing',
        'state.cli_installed',
        'state.cli_missing',
        'state.runner_running',
        'state.runner_stopped',
        'state.models_count',
        'models.empty',
        'version.error',
        'action.working',
        'action.done',
        'action.refreshed',
        'action.failed',
        'action.log_hint',
        'action.invalid_host',
        'network.none',
        'network.saved_hint',
    )); ?>;

    function setText(id, value) {
        var element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    function setHidden(id, hidden) {
        var element = document.getElementById(id);
        if (element) {
            element.hidden = hidden;
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

    // The version rows are one row per field instead of the raw output of
    // "docker model version". A layout this page does not know is kept
    // readable by showing its raw output, expanded.
    function renderVersion(version) {
        var v = version || {};

        setText('dmr-version-client', v.client_version || t['state.unknown']);
        setText('dmr-version-platform', v.client_platform || t['state.unknown']);
        setText('dmr-version-server', v.server_version || t['state.unknown']);
        setText('dmr-version-engine', v.server_engine || t['state.unknown']);

        var failed = v.status === 'error';
        setHidden('dmr-version-error', !failed);
        if (failed) {
            setText('dmr-version-error', v.error || t['version.error']);
        }

        var raw = v.raw || '';
        setHidden('dmr-version-raw-wrap', raw === '');
        if (raw !== '') {
            setText('dmr-version-raw', raw);
            var details = document.getElementById('dmr-version-raw-wrap');
            if (details) {
                details.open = !v.structured;
            }
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

    function renderPorts(ports) {
        setText('dmr-runner-ports', ports || t['network.none']);
    }

    // The address that is stored on the flash device is the truth; the select
    // only mirrors it. A refresh must not overwrite an edit that the user has
    // not saved yet, so the sync is skipped while the form is dirty.
    var settingsDirty = false;

    function syncSettings(host) {
        if (settingsDirty || !host) {
            return;
        }
        var select = document.getElementById('dmr-host-select');
        var custom = document.getElementById('dmr-host-custom');
        if (!select) {
            return;
        }
        if (host === '0.0.0.0' || host === '127.0.0.1') {
            select.value = host;
            if (custom) {
                custom.value = '';
                custom.hidden = true;
            }
        } else {
            select.value = 'custom';
            if (custom) {
                custom.value = host;
                custom.hidden = false;
            }
        }
    }

    function loadStatus() {
        $.post(statusEndpoint, {}, function (data) {
            var binary = (data && data.binary) ? data.binary : 'missing';
            var runner = (data && data.runner) ? data.runner : 'stopped';
            var models = (data && data.models) ? data.models : [];

            var binaryOk = binary === 'installed';
            var runnerOk = runner === 'running';

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
            setButton('dmr-btn-stop', binaryOk && runnerOk);
            setButton('dmr-btn-remove', binaryOk && runnerOk);
            setButton('dmr-btn-recreate', binaryOk && runnerOk);

            renderVersion(data ? data.version : null);
            renderPorts(data ? data.runner_ports : '');
            renderModels(models);
            syncSettings(data && data.settings ? data.settings.host : '');

            actionInFlight = false;
        }, 'json').fail(function () {
            setText('dmr-status-binary', t['state.unknown']);
            setPill('dmr-pill-binary', t['state.unknown'], 'warn');
            setPill('dmr-pill-runner', t['state.unknown'], 'warn');
            setPill('dmr-pill-models', t['state.unknown'], 'warn');
            setPill('dmr-pill-runner-state', t['state.unknown'], 'warn');
            actionInFlight = false;
        });
    }

    function showResult(boxId, text, hint) {
        var box = document.getElementById(boxId);
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

    // Every button that starts a docker command ends here. The commands run in
    // the background; the reload below is what re-enables the buttons, so they
    // are disabled here as well: a double click would otherwise start two
    // installs of the same runner.
    var actionInFlight = false;

    function runAction(name, resultId) {
        if (actionInFlight) {
            return;
        }
        if (!resultId) {
            resultId = name === 'reinstall-runner' ? 'dmr-settings-result' : 'dmr-action-result';
        }
        actionInFlight = true;
        setButton('dmr-btn-cpu', false);
        setButton('dmr-btn-gpu', false);
        setButton('dmr-btn-stop', false);
        setButton('dmr-btn-remove', false);
        setButton('dmr-btn-recreate', false);
        showResult(resultId, t['action.working'], '');
        $.post(actionEndpoint, {action: name, token: actionToken}, function (data) {
            var message = (data && data.message) ? data.message : t['action.done'];
            showResult(resultId, message, t['action.refreshed']);
            window.setTimeout(loadStatus, 3000);
        }, 'json').fail(function () {
            showResult(resultId, t['action.failed'], t['action.log_hint']);
            loadStatus();
        }).always(function () {
            actionInFlight = false;
        });
    }

    var saving = false;

    function saveSettings() {
        if (saving) {
            return;
        }

        var select = document.getElementById('dmr-host-select');
        var custom = document.getElementById('dmr-host-custom');
        if (!select) {
            return;
        }

        var host = select.value === 'custom' && custom ? custom.value.replace(/^\s+|\s+$/g, '') : select.value;
        if (host === '') {
            showResult('dmr-settings-result', t['action.invalid_host'], '');
            return;
        }

        saving = true;
        showResult('dmr-settings-result', t['action.working'], '');
        $.post(actionEndpoint, {action: 'save-settings', host: host, token: actionToken}, function (data) {
            var ok = !!(data && data.ok);
            var message = (data && data.message) ? data.message : (ok ? t['action.done'] : t['action.failed']);
            showResult('dmr-settings-result', message, ok ? t['network.saved_hint'] : '');
            if (ok) {
                settingsDirty = false;
                loadStatus();
            }
        }, 'json').fail(function () {
            showResult('dmr-settings-result', t['action.failed'], t['action.log_hint']);
        }).always(function () {
            saving = false;
        });
    }

    function updateCustomField() {
        var select = document.getElementById('dmr-host-select');
        var custom = document.getElementById('dmr-host-custom');
        if (!select || !custom) {
            return;
        }
        custom.hidden = select.value !== 'custom';
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
            return;
        }
        if (target.closest('#dmr-btn-save')) {
            event.preventDefault();
            saveSettings();
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.id) {
            return;
        }
        if (target.id === 'dmr-host-select') {
            settingsDirty = true;
            updateCustomField();
        } else if (target.id === 'dmr-host-custom') {
            settingsDirty = true;
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target && event.target.id === 'dmr-host-custom') {
            settingsDirty = true;
        }
    });

    loadStatus();
})();
</script>
