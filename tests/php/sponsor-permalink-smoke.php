<?php

/**
 * REST/cron permalink proof when wp-admin post helpers are not preloaded.
 */

define('ABSPATH', '/definitely-missing-wordpress-root/');

function get_post_meta($post_id, $key = '', $single = false)
{
    return '';
}

function get_post_status($post_id)
{
    return 'draft';
}

function get_permalink($post_id)
{
    return 'https://example.test/fallback-' . $post_id;
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
require_once $plugin_root . '/Broadstreet/SponsorSync.php';

class Broadstreet_Test_Permalink_Sync extends Broadstreet_Sponsor_Sync
{
    public function trackerUrl($post_id)
    {
        return $this->getTrackerUrl($post_id);
    }
}

$sync = new Broadstreet_Test_Permalink_Sync(new stdClass(), '99');
broadstreet_assert_same(
    'https://example.test/fallback-42',
    $sync->trackerUrl(42),
    'Draft synchronization should fall back safely when get_sample_permalink cannot be loaded.'
);

echo "Sponsor permalink smoke test passed.\n";
