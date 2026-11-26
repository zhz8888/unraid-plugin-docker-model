<?php
/**
 * Regression checks for the persistent settings of the WebGUI page.
 *
 * Run from the repository root:
 *
 *     php tests/settings.php
 */

$root = dirname(__DIR__);
// Keep the fixture beside the test so the check also works on Windows hosts
// where the PHP process cannot create directories in the system temp folder.
$temporary = $root . '/tests/settings-' . bin2hex(random_bytes(6));
if (!@mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    fwrite(STDERR, "could not create the settings test directory\n");
    exit(1);
}

define('DMR_CONFIG_FILE', $temporary . '/unraid-plugin-docker-model.cfg');
require $root . '/src/usr/local/emhttp/plugins/unraid-plugin-docker-model/include/common.php';

$failures = 0;

function check_setting($name, $condition, $detail = '')
{
    global $failures;
    if ($condition) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name} {$detail}\n";
    }
}

function stored_settings()
{
    return is_readable(DMR_CONFIG_FILE) ? (string) file_get_contents(DMR_CONFIG_FILE) : '';
}

check_setting('missing config defaults API Key to local', dmr_settings()['api_key'] === 'local');
check_setting('missing config keeps the default host', dmr_settings()['host'] === '0.0.0.0');

foreach (array('local', 'sk-test_123', 'abc.TOKEN-~+/=', str_repeat('a', 128)) as $key) {
    check_setting('valid API Key accepted: ' . substr($key, 0, 24), dmr_valid_api_key($key));
}
foreach (array('', ' ', 'two words', "line\nbreak", 'quote"key', 'semi;colon', 'dollar$key', str_repeat('a', 129), null, array('local')) as $key) {
    check_setting('invalid API Key refused: ' . substr(json_encode($key), 0, 24), !dmr_valid_api_key($key));
}

check_setting('host and API Key save together', dmr_save_settings(array('host' => '192.168.1.10', 'api_key' => 'sk-local_123')));
$settings = dmr_settings();
check_setting('saved host reads back', $settings['host'] === '192.168.1.10');
check_setting('saved API Key reads back', $settings['api_key'] === 'sk-local_123');
check_setting('config stores the API Key setting', strpos(stored_settings(), 'DMR_API_KEY="sk-local_123"') !== false);
if (DIRECTORY_SEPARATOR !== '\\') {
    clearstatcache(true, DMR_CONFIG_FILE);
    check_setting('config permissions are owner-only', (fileperms(DMR_CONFIG_FILE) & 0777) === 0600);
}

check_setting('host-only update preserves API Key', dmr_save_settings(array('host' => '127.0.0.1')));
$settings = dmr_settings();
check_setting('preserved API Key reads back', $settings['api_key'] === 'sk-local_123');
check_setting('host-only update reads back', $settings['host'] === '127.0.0.1');

check_setting('key-only update preserves host', dmr_save_settings(array('api_key' => 'custom.key-123')));
$settings = dmr_settings();
check_setting('key-only update reads back', $settings['api_key'] === 'custom.key-123');
check_setting('preserved host reads back', $settings['host'] === '127.0.0.1');

check_setting('empty API Key resets to local', dmr_save_settings(array('api_key' => '   ')));
check_setting('reset value reads back', dmr_settings()['api_key'] === 'local');

$before = stored_settings();
check_setting('invalid API Key is not saved', !dmr_save_settings(array('api_key' => 'bad;key')));
check_setting('invalid save leaves config unchanged', stored_settings() === $before);

file_put_contents(DMR_CONFIG_FILE, "DMR_HOST=\"127.0.0.1\"\n");
$settings = dmr_settings();
check_setting('legacy config keeps its host', $settings['host'] === '127.0.0.1');
check_setting('legacy config defaults API Key to local', $settings['api_key'] === 'local');

file_put_contents(DMR_CONFIG_FILE, "DMR_HOST=\"garbage\"\nDMR_API_KEY=\"bad key\"\n");
$settings = dmr_settings();
check_setting('invalid stored host falls back', $settings['host'] === '0.0.0.0');
check_setting('invalid stored API Key falls back', $settings['api_key'] === 'local');

check_setting('API Key containing a slash saves', dmr_save_settings(array('api_key' => 'abc/TOKEN')));
ob_start();
require $root . '/src/usr/local/emhttp/plugins/unraid-plugin-docker-model/include/Pages/Home.php';
$rendered = ob_get_clean();
check_setting('client example renders a slash key literally', strpos($rendered, 'id="dmr-client-key">&quot;abc/TOKEN&quot;</span>') !== false);
check_setting('client example does not add a Python backslash', strpos($rendered, 'abc\\/TOKEN') === false);

@unlink(DMR_CONFIG_FILE);
@rmdir($temporary);

echo $failures === 0 ? "\nall settings checks passed\n" : "\n{$failures} settings check(s) failed\n";
exit($failures === 0 ? 0 : 1);
