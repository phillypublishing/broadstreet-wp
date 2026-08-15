<?php

/**
 * Focused shared-client seam proof for legacy and utility call paths.
 */

class WP_Widget
{
}

$broadstreet_client_filter_calls = array();
$broadstreet_injected_client = null;
$broadstreet_sponsor_injected_client = null;
$broadstreet_client_options = array(
    'Broadstreet_API_Key' => 'fixture-api-key',
    'Broadstreet_Network_Key' => '4242',
    'Broadstreet_Placements' => (object) array(),
);

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
    global $broadstreet_client_filter_calls;
    global $broadstreet_injected_client;
    global $broadstreet_sponsor_injected_client;

    if ($hook === 'broadstreet_client') {
        $arguments = func_get_args();
        $broadstreet_client_filter_calls[] = array_slice($arguments, 1);
        return is_object($broadstreet_injected_client)
            ? $broadstreet_injected_client
            : $value;
    }

    if ($hook === 'broadstreet_sponsor_client' && is_object($broadstreet_sponsor_injected_client)) {
        return $broadstreet_sponsor_injected_client;
    }

    return $value;
}

function get_option($key)
{
    global $broadstreet_client_options;
    return array_key_exists($key, $broadstreet_client_options)
        ? $broadstreet_client_options[$key]
        : false;
}

function update_option($key, $value)
{
    global $broadstreet_client_options;
    $broadstreet_client_options[$key] = $value;
    return true;
}

function add_option($key, $value)
{
    global $broadstreet_client_options;
    if (array_key_exists($key, $broadstreet_client_options)) {
        return false;
    }
    $broadstreet_client_options[$key] = $value;
    return true;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    if ($key === '') {
        return array();
    }
    return '';
}

function maybe_unserialize($value)
{
    return $value;
}

function plugin_basename($path)
{
    return basename($path);
}

function wp_nonce_field()
{
}

function wp_create_nonce($action)
{
    return 'nonce-' . $action;
}

function esc_attr($value)
{
    return (string) $value;
}

function esc_html($value)
{
    return (string) $value;
}

function esc_url($value)
{
    return (string) $value;
}

function site_url()
{
    return 'https://example.test';
}

function plugins_url($path = '', $plugin = '')
{
    return 'https://example.test/wp-content/plugins/broadstreet/' . ltrim($path, '/');
}

function broadstreet_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

class Broadstreet_Test_Shared_Client
{
    public $calls = array();

    public function getNetwork($network_id)
    {
        $this->calls[] = array('getNetwork', (string) $network_id);
        return (object) array('use_tracker_v3' => true);
    }

    public function getAdvertisers($network_id)
    {
        $this->calls[] = array('getAdvertisers', (string) $network_id);
        return array((object) array('id' => 101, 'name' => 'Fixture Advertiser'));
    }
}

$plugin_root = dirname(dirname(__DIR__));
require_once $plugin_root . '/Broadstreet/Core.php';

$default_client = Broadstreet_Utility::getBroadstreetClient();
broadstreet_assert_same(true, $default_client instanceof Broadstreet, 'The unfiltered shared client must remain Broadstreet.');
broadstreet_assert_same(1, count($broadstreet_client_filter_calls), 'The shared client filter must run for the default client.');
broadstreet_assert_same(
    1,
    count($broadstreet_client_filter_calls[0]),
    'The shared client filter must receive only the client object, never raw key or host arguments.'
);
broadstreet_assert_same(
    $default_client,
    $broadstreet_client_filter_calls[0][0],
    'The shared client filter must receive the constructed Broadstreet client.'
);

$broadstreet_injected_client = new Broadstreet_Test_Shared_Client();
broadstreet_assert_same(
    $broadstreet_injected_client,
    Broadstreet_Utility::getBroadstreetClient(),
    'Utility callers must receive the generic injected client.'
);

$network = Broadstreet_Utility::getNetwork(true);
broadstreet_assert_same(true, is_object($network), 'The utility network lookup should use the injected client.');
broadstreet_assert_same(
    array('getNetwork', '4242'),
    $broadstreet_injected_client->calls[0],
    'The utility network lookup must run through the generic injected client.'
);

$core_reflection = new ReflectionClass('Broadstreet_Core');
$core = $core_reflection->newInstanceWithoutConstructor();
broadstreet_assert_same(
    $broadstreet_injected_client,
    $core->getBroadstreetClient(),
    'Legacy Core callers must receive the generic injected client.'
);
broadstreet_assert_same(
    $broadstreet_injected_client,
    $core->getSponsorBroadstreetClient(),
    'Sponsor fallback must naturally use the generic shared client seam.'
);

$broadstreet_sponsor_injected_client = new stdClass();
broadstreet_assert_same(
    $broadstreet_sponsor_injected_client,
    $core->getSponsorBroadstreetClient(),
    'The sponsor-specific seam must keep precedence over the generic fallback.'
);
$broadstreet_sponsor_injected_client = null;

$post = (object) array('ID' => 77, 'post_title' => 'Fixture Business');
$GLOBALS['post'] = $post;
ob_start();
$core->broadstreetBusinessBox($post);
ob_end_clean();

$advertiser_calls = array_values(array_filter(
    $broadstreet_injected_client->calls,
    function ($call) {
        return $call[0] === 'getAdvertisers';
    }
));
broadstreet_assert_same(
    array(array('getAdvertisers', '4242')),
    $advertiser_calls,
    'The legacy business box must fetch advertisers through the generic injected client.'
);

echo "Shared Broadstreet client filter smoke test passed.\n";
