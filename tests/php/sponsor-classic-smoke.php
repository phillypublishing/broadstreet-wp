<?php

/**
 * Focused Classic Editor save proof for the retained legacy meta box.
 */

class WP_Widget
{
}

class WP_Error
{
}

$broadstreet_classic_meta = array();
$broadstreet_classic_reconciles = array();
$broadstreet_classic_creates = array();
$broadstreet_classic_autosave = false;
$broadstreet_classic_revision = false;

function add_action()
{
}

function add_filter()
{
}

function add_shortcode()
{
}

function get_option($key)
{
    return $key === 'Broadstreet_API_Key' ? 'fake-test-key' : false;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $broadstreet_classic_meta;
    return isset($broadstreet_classic_meta[$post_id][$key])
        ? $broadstreet_classic_meta[$post_id][$key]
        : '';
}

function update_post_meta($post_id, $key, $value)
{
    global $broadstreet_classic_meta;
    $broadstreet_classic_meta[$post_id][$key] = $value;
    return true;
}

function add_post_meta($post_id, $key, $value)
{
    return update_post_meta($post_id, $key, $value);
}

function current_user_can($capability, $post_id = null)
{
    return $capability === 'edit_post' && $post_id === 42;
}

function plugin_basename($file)
{
    return 'Broadstreet/Core.php';
}

function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'valid-classic-nonce' && $action === 'Broadstreet/Core.php';
}

function wp_is_post_revision($post_id)
{
    global $broadstreet_classic_revision;
    return $broadstreet_classic_revision;
}

function wp_is_post_autosave($post_id)
{
    global $broadstreet_classic_autosave;
    return $broadstreet_classic_autosave;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
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

class Broadstreet_Test_Classic_Core extends Broadstreet_Core
{
    public function syncSponsorPost($post_id)
    {
        global $broadstreet_classic_reconciles;
        $broadstreet_classic_reconciles[] = $post_id;
        return array('state' => 'synced');
    }

    public function createSponsorAdvertiserForPost($post_id, $name)
    {
        global $broadstreet_classic_creates;
        $broadstreet_classic_creates[] = array($post_id, $name);
        return array('id' => '44', 'name' => $name);
    }
}

$reflection = new ReflectionClass('Broadstreet_Test_Classic_Core');
$core = $reflection->newInstanceWithoutConstructor();

$broadstreet_classic_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '17',
    'bs_sponsor_advertisement_id' => '901',
);
$_POST = array(
    'bs_sponsor_submit' => '1',
    'broadstreetsponsored' => 'valid-classic-nonce',
    'bs_sponsor_advertiser_id' => '17',
    // A Classic form cannot take ownership of the remote tracker ID.
    'bs_sponsor_advertisement_id' => '999',
);
$core->saveSponsorPostMeta(42);
broadstreet_assert_same(false, $broadstreet_classic_meta[42]['bs_sponsor_is_sponsored'], 'Classic Editor should now store an explicit false when disabled.');
broadstreet_assert_same('17', $broadstreet_classic_meta[42]['bs_sponsor_advertiser_id'], 'Classic disable should preserve the advertiser.');
broadstreet_assert_same('901', $broadstreet_classic_meta[42]['bs_sponsor_advertisement_id'], 'Posted forms must not overwrite server-owned advertisement IDs.');
broadstreet_assert_same(array(42), $broadstreet_classic_reconciles, 'Classic saves should use the shared synchronizer.');

$_POST = array(
    'bs_sponsor_submit' => '1',
    'broadstreetsponsored' => 'valid-classic-nonce',
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => 'new_advertiser',
    'bs_sponsor_advertiser_name' => 'X',
);
$core->saveSponsorPostMeta(42);
broadstreet_assert_same(array(array(42, 'X**')), $broadstreet_classic_creates, 'Classic advertiser creation should remain an explicit form selection and retain the three-character bound.');
broadstreet_assert_same('44', $broadstreet_classic_meta[42]['bs_sponsor_advertiser_id'], 'Classic creation should select the returned advertiser.');
broadstreet_assert_same('901', $broadstreet_classic_meta[42]['bs_sponsor_advertisement_id'], 'Classic advertiser changes should preserve the remote tracker ID for a move.');
broadstreet_assert_same(array(42, 42), $broadstreet_classic_reconciles, 'Classic creation should continue through the shared synchronizer.');

$before_invalid = $broadstreet_classic_meta[42];
$_POST['broadstreetsponsored'] = 'invalid';
$core->saveSponsorPostMeta(42);
broadstreet_assert_same($before_invalid, $broadstreet_classic_meta[42], 'Invalid Classic nonces should not mutate sponsor meta.');

$broadstreet_classic_autosave = true;
$_POST['broadstreetsponsored'] = 'valid-classic-nonce';
$_POST['bs_sponsor_advertiser_id'] = '55';
$core->saveSponsorPostMeta(42);
broadstreet_assert_same($before_invalid, $broadstreet_classic_meta[42], 'Classic autosaves should not reconcile or mutate sponsor meta.');

$legacy_view = file_get_contents($plugin_root . '/Broadstreet/Views/admin/sponsoredBox.php');
broadstreet_assert_same(true, strpos($legacy_view, 'bs_sponsor_submit') !== false, 'The Classic Editor form submission marker should remain.');
broadstreet_assert_same(false, strpos($legacy_view, "wp.data.subscribe") !== false, 'The obsolete Gutenberg DOM subscription should be removed.');
broadstreet_assert_same(false, strpos($legacy_view, 'get_sponsored_meta') !== false, 'The obsolete sponsor-meta AJAX refresh should be removed.');

echo "Sponsor Classic Editor smoke test passed.\n";
