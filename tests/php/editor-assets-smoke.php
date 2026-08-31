<?php

/**
 * Focused integration smoke test for the block-editor asset loader.
 *
 * This intentionally avoids a WordPress test-suite dependency: the small set
 * of APIs used by the loader are captured below so every PHP matrix job can
 * prove that generated metadata reaches wp_enqueue_script().
 */

class WP_Widget
{
}

$broadstreet_enqueued_scripts = array();
$broadstreet_plugins_url_base = null;
$broadstreet_actions = array();
$broadstreet_current_user_can = true;
$broadstreet_capability_checks = array();

function add_action($hook, $callback, $priority = 10)
{
    global $broadstreet_actions;

    $broadstreet_actions[] = array($hook, $callback, $priority);
}

function add_filter()
{
}

function add_shortcode()
{
}

function get_option()
{
    return false;
}

function current_user_can($capability)
{
    global $broadstreet_current_user_can, $broadstreet_capability_checks;

    $broadstreet_capability_checks[] = $capability;

    return $broadstreet_current_user_can;
}

function plugins_url($path, $plugin_file)
{
    global $broadstreet_plugins_url_base;

    $broadstreet_plugins_url_base = $plugin_file;

    return 'https://example.test/wp-content/plugins/broadstreet/' . ltrim($path, '/');
}

function wp_enqueue_script($handle, $src, $dependencies, $version, $in_footer)
{
    global $broadstreet_enqueued_scripts;

    $broadstreet_enqueued_scripts[] = array(
        'handle' => $handle,
        'src' => $src,
        'dependencies' => $dependencies,
        'version' => $version,
        'in_footer' => $in_footer,
    );
}

function broadstreet_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

$plugin_root = dirname(dirname(__DIR__));
$asset_path = $plugin_root . '/build/editor.asset.php';

if (!file_exists($asset_path)) {
    throw new RuntimeException('Run npm run build before the PHP editor asset smoke test.');
}

require_once $plugin_root . '/Broadstreet/Core.php';

$reflection = new ReflectionClass('Broadstreet_Core');
$core = $reflection->newInstanceWithoutConstructor();
$core->execute();
broadstreet_assert_same(
    array(),
    $broadstreet_capability_checks,
    'Registering hooks should not check the current user capability.'
);
$core->enqueueEditorAssets();

$asset = require $asset_path;
$editor_hooks = array_filter($broadstreet_actions, function ($action) {
    return $action[0] === 'enqueue_block_editor_assets';
});

broadstreet_assert_same(1, count($editor_hooks), 'The block-editor asset hook should be registered once.');
$editor_hook = reset($editor_hooks);
broadstreet_assert_same($core, $editor_hook[1][0], 'The block-editor hook should target the active core instance.');
broadstreet_assert_same('enqueueEditorAssets', $editor_hook[1][1], 'The block-editor hook should target the asset loader.');
broadstreet_assert_same(100, $editor_hook[2], 'The block-editor hook should keep its late priority.');
broadstreet_assert_same(1, count($broadstreet_enqueued_scripts), 'The editor script should be enqueued once.');
broadstreet_assert_same('broadstreet-editor', $broadstreet_enqueued_scripts[0]['handle'], 'Unexpected script handle.');
broadstreet_assert_same(
    'https://example.test/wp-content/plugins/broadstreet/build/editor.js',
    $broadstreet_enqueued_scripts[0]['src'],
    'Unexpected editor script URL.'
);
broadstreet_assert_same($asset['dependencies'], $broadstreet_enqueued_scripts[0]['dependencies'], 'Generated dependencies were not used.');
broadstreet_assert_same(
    false,
    in_array('react-jsx-runtime', $asset['dependencies'], true),
    'The WordPress 6.4 build must use the classic JSX transform.'
);
broadstreet_assert_same($asset['version'], $broadstreet_enqueued_scripts[0]['version'], 'Generated version was not used.');
broadstreet_assert_same(true, $broadstreet_enqueued_scripts[0]['in_footer'], 'The editor script should load in the footer.');
broadstreet_assert_same(
    $plugin_root . '/broadstreet.php',
    $broadstreet_plugins_url_base,
    'plugins_url() should resolve relative to the main plugin file.'
);
broadstreet_assert_same(
    array('edit_posts'),
    $broadstreet_capability_checks,
    'The editor asset loader should require post-editing capability.'
);

$broadstreet_current_user_can = false;
$broadstreet_enqueued_scripts = array();
$core->enqueueEditorAssets();

broadstreet_assert_same(
    0,
    count($broadstreet_enqueued_scripts),
    'The editor script should not be enqueued for users who cannot edit posts.'
);
broadstreet_assert_same(
    array('edit_posts', 'edit_posts'),
    $broadstreet_capability_checks,
    'The post-editing capability should be checked when editor assets are enqueued.'
);

echo "Editor asset smoke test passed.\n";
