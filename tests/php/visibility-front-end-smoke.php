<?php

/**
 * Characterization proof for the historical per-post ad suppression contract.
 */

class WP_Widget
{
    public function __construct()
    {
    }
}

$broadstreet_visibility_meta = '';
$broadstreet_inline_scripts = array();
$broadstreet_visibility_actions = array();
$broadstreet_is_singular = true;

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $broadstreet_visibility_actions;

    $broadstreet_visibility_actions[] = array($hook, $callback, $priority, $accepted_args);
}

function add_filter()
{
}

function add_shortcode()
{
}

function apply_filters($hook, $value)
{
    return $value;
}

function get_option($key)
{
    if ($key === 'Broadstreet_Placements') {
        return (object) array();
    }

    return false;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $broadstreet_visibility_meta;

    if ($post_id === 42 && $key === 'bs_ads_disabled') {
        return $broadstreet_visibility_meta;
    }

    return '';
}

function maybe_unserialize($value)
{
    return $value;
}

function is_singular()
{
    global $broadstreet_is_singular;

    return $broadstreet_is_singular;
}

function get_queried_object_id()
{
    return 42;
}

function wp_add_inline_script($handle, $code, $position)
{
    global $broadstreet_inline_scripts;
    $broadstreet_inline_scripts[] = array($handle, $code, $position);
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
$core->execute();

$visibility_front_end_hooks = array_values(array_filter($broadstreet_visibility_actions, function ($action) {
    return $action[0] === 'wp'
        && is_array($action[1])
        && $action[1][1] === 'captureAdVisibilityState';
}));
broadstreet_assert_same(1, count($visibility_front_end_hooks), 'Ad visibility should be captured once when the main query is available.');
broadstreet_assert_same(10, $visibility_front_end_hooks[0][2], 'Ad visibility should use the standard wp hook priority.');

$broadstreet_visibility_meta = '1';
Broadstreet_Core::$_disableAds = false;
call_user_func($visibility_front_end_hooks[0][1]);
broadstreet_assert_same(true, Broadstreet_Core::$_disableAds, 'The wp hook should capture historical string 1 before block-theme content renders.');
broadstreet_assert_same(
    '<!-- Broadstreet plugin: Ads disabled on this post -->',
    Broadstreet_Utility::getZoneCode(7),
    'Zone rendering before wp_enqueue_scripts should honor the stored visibility state.'
);

$core->writeInitCode();
broadstreet_assert_same(true, Broadstreet_Core::$_disableAds, 'Historical string 1 should disable ads.');
broadstreet_assert_same(array(), $broadstreet_inline_scripts, 'Disabled posts should not receive Broadstreet initialization.');
broadstreet_assert_same(
    '<!-- Broadstreet plugin: Ads disabled on this post -->',
    Broadstreet_Utility::getZoneCode(7),
    'Existing zone output should keep its disabled marker.'
);

$widget_reflection = new ReflectionClass('Broadstreet_Zone_Widget');
$widget = $widget_reflection->newInstanceWithoutConstructor();
ob_start();
$widget->widget(array(), array());
$widget_output = ob_get_clean();
broadstreet_assert_same(
    '<!-- Broadstreet plugin: Ads disabled on this post -->',
    $widget_output,
    'Existing zone widgets should keep their disabled marker.'
);

ob_start();
$core->addPoweredBy();
$powered_by_output = ob_get_clean();
broadstreet_assert_same('', $powered_by_output, 'Disabled posts should not receive fallback initialization.');

$broadstreet_visibility_meta = '';
Broadstreet_Core::$_disableAds = true;
$broadstreet_is_singular = false;
call_user_func($visibility_front_end_hooks[0][1]);
broadstreet_assert_same(false, Broadstreet_Core::$_disableAds, 'Non-singular requests should deterministically clear stale visibility state.');
$broadstreet_is_singular = true;
$core->writeInitCode();
broadstreet_assert_same(false, Broadstreet_Core::$_disableAds, 'Historical empty values should leave ads enabled.');
broadstreet_assert_same(
    array(array('broadstreet-init', '', 'after')),
    $broadstreet_inline_scripts,
    'Enabled posts should continue through the initialization path.'
);

echo "Ad visibility front-end characterization passed.\n";
