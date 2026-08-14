<?php

/**
 * REST/cron permalink proof when wp-admin post helpers are not preloaded.
 */

define('ABSPATH', '/definitely-missing-wordpress-root/');

function get_post_meta($post_id, $key = '', $single = false)
{
    if ($key === 'bs_sponsor_advertisement_id') {
        return '';
    }
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

function get_the_title($post_id)
{
    return 'Draft title';
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
require_once $plugin_root . '/Broadstreet/SponsorReconciler.php';

class Broadstreet_Test_Permalink_Reconciler extends Broadstreet_Sponsor_Reconciler
{
    public function desired($post_id, $advertiser_id)
    {
        return $this->readDesiredState($post_id, $advertiser_id);
    }
}

$reconciler = new Broadstreet_Test_Permalink_Reconciler(new stdClass(), '99');
$desired = $reconciler->desired(42, '17');
broadstreet_assert_same(
    'https://example.test/fallback-42',
    $desired['url'],
    'Draft reconciliation should fall back safely when get_sample_permalink cannot be loaded.'
);

echo "Sponsor permalink smoke test passed.\n";
