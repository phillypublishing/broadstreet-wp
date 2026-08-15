<?php

/**
 * Characterization proof for front-end tracker suppression and legacy IDs.
 */

class Broadstreet_Core
{
    const KEY_PLACEMENTS = 'Broadstreet_Placements';
}

$broadstreet_frontend_meta = array();

function is_singular()
{
    return true;
}

function is_main_query()
{
    return true;
}

function get_queried_object_id()
{
    return 42;
}

function in_the_loop()
{
    return false;
}

function get_the_ID()
{
    return 42;
}

function get_post_meta($post_id, $key, $single = false)
{
    global $broadstreet_frontend_meta;
    return isset($broadstreet_frontend_meta[$key]) ? $broadstreet_frontend_meta[$key] : '';
}

function maybe_unserialize($value)
{
    return $value;
}

function get_option($key)
{
    return false;
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
require_once $plugin_root . '/Broadstreet/Utility.php';

$broadstreet_frontend_meta = array(
    'bs_sponsor_is_sponsored' => '',
    'bs_sponsor_advertisement_id' => '906',
);
broadstreet_assert_same(
    '',
    Broadstreet_Utility::getTrackerCode(),
    'A retained remote tracker ID must not emit when sponsorship is disabled.'
);

$broadstreet_frontend_meta['bs_sponsor_is_sponsored'] = '1';
$tracker = Broadstreet_Utility::getTrackerCode();
broadstreet_assert_same(
    true,
    strpos($tracker, 'street-address="906"') !== false,
    'Historical string sponsorship and advertisement IDs should continue to emit the tracker.'
);

echo "Sponsor front-end smoke test passed.\n";
