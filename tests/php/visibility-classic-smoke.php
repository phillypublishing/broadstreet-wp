<?php

/**
 * Focused Classic Editor save proof for the retained Disable Ads meta box.
 */

class WP_Widget
{
}

$broadstreet_visibility_classic_meta = array();
$broadstreet_visibility_classic_can_edit = true;
$broadstreet_visibility_classic_autosave = false;
$broadstreet_visibility_classic_revision = false;

function add_action()
{
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

function get_post_meta($post_id, $key = '', $single = false)
{
    global $broadstreet_visibility_classic_meta;
    return isset($broadstreet_visibility_classic_meta[$post_id][$key])
        ? $broadstreet_visibility_classic_meta[$post_id][$key]
        : '';
}

function update_post_meta($post_id, $key, $value)
{
    global $broadstreet_visibility_classic_meta;
    $broadstreet_visibility_classic_meta[$post_id][$key] = $value;
    return true;
}

function add_post_meta($post_id, $key, $value)
{
    return update_post_meta($post_id, $key, $value);
}

function current_user_can($capability, $post_id = null)
{
    global $broadstreet_visibility_classic_can_edit;
    return $broadstreet_visibility_classic_can_edit
        && $capability === 'edit_post'
        && $post_id === 42;
}

function plugin_basename($file)
{
    return 'Broadstreet/Core.php';
}

function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'valid-visibility-nonce' && $action === 'Broadstreet/Core.php';
}

function wp_is_post_revision($post_id)
{
    global $broadstreet_visibility_classic_revision;
    return $broadstreet_visibility_classic_revision;
}

function wp_is_post_autosave($post_id)
{
    global $broadstreet_visibility_classic_autosave;
    return $broadstreet_visibility_classic_autosave;
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
require_once $plugin_root . '/Broadstreet/Core.php';

$reflection = new ReflectionClass('Broadstreet_Core');
$core = $reflection->newInstanceWithoutConstructor();

$broadstreet_visibility_classic_meta[42]['bs_ads_disabled'] = '';
$_POST = array(
    'bs_ads_disabled_submit' => '1',
    'broadstreetadvisibility' => 'valid-visibility-nonce',
    'bs_ads_disabled' => '1',
);
$core->saveAdVisibilityMeta(42);
broadstreet_assert_same('1', $broadstreet_visibility_classic_meta[42]['bs_ads_disabled'], 'Classic Editor should preserve the historical enabled marker.');

unset($_POST['bs_ads_disabled']);
$core->saveAdVisibilityMeta(42);
broadstreet_assert_same('', $broadstreet_visibility_classic_meta[42]['bs_ads_disabled'], 'An unchecked Classic checkbox should store the historical empty marker.');

$broadstreet_visibility_classic_meta[42]['bs_ads_disabled'] = '1';
$_POST['broadstreetadvisibility'] = 'invalid';
$core->saveAdVisibilityMeta(42);
broadstreet_assert_same('1', $broadstreet_visibility_classic_meta[42]['bs_ads_disabled'], 'Invalid Classic nonces must not mutate visibility meta.');

$_POST['broadstreetadvisibility'] = 'valid-visibility-nonce';
$broadstreet_visibility_classic_can_edit = false;
unset($_POST['bs_ads_disabled']);
$core->saveAdVisibilityMeta(42);
broadstreet_assert_same('1', $broadstreet_visibility_classic_meta[42]['bs_ads_disabled'], 'Users without edit_post must not mutate visibility meta.');

$broadstreet_visibility_classic_can_edit = true;
$broadstreet_visibility_classic_autosave = true;
$core->saveAdVisibilityMeta(42);
broadstreet_assert_same('1', $broadstreet_visibility_classic_meta[42]['bs_ads_disabled'], 'Classic autosaves must not mutate visibility meta.');

$broadstreet_visibility_classic_autosave = false;
$broadstreet_visibility_classic_revision = true;
$core->saveAdVisibilityMeta(42);
broadstreet_assert_same('1', $broadstreet_visibility_classic_meta[42]['bs_ads_disabled'], 'Revision saves must not mutate canonical visibility meta.');

$legacy_view = file_get_contents($plugin_root . '/Broadstreet/Views/admin/visibilityBox.php');
broadstreet_assert_same(true, strpos($legacy_view, 'bs_ads_disabled_submit') !== false, 'The Classic submission marker should remain.');
broadstreet_assert_same(true, strpos($legacy_view, 'broadstreetadvisibility') === false, 'The nonce should continue to be emitted by the controller.');

echo "Ad visibility Classic Editor smoke test passed.\n";
