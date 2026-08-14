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

function add_action()
{
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
    return true;
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

$broadstreet_visibility_meta = '1';
Broadstreet_Core::$_disableAds = false;
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
$core->writeInitCode();
broadstreet_assert_same(false, Broadstreet_Core::$_disableAds, 'Historical empty values should leave ads enabled.');
broadstreet_assert_same(
    array(array('broadstreet-init', '', 'after')),
    $broadstreet_inline_scripts,
    'Enabled posts should continue through the initialization path.'
);

echo "Ad visibility front-end characterization passed.\n";
