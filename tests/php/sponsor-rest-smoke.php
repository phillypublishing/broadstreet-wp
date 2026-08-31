<?php

/**
 * Focused authenticated REST boundary proof for the sponsor editor panel.
 */

class WP_Widget
{
}

class WP_Error
{
    protected $code;
    protected $message;
    protected $data;

    public function __construct($code, $message, $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

class WP_REST_Response
{
    protected $data;
    public $headers = array();

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function header($name, $value)
    {
        $this->headers[$name] = $value;
    }
}

class Broadstreet_Test_REST_Request implements ArrayAccess
{
    protected $params;

    public function __construct($params)
    {
        $this->params = $params;
    }

    public function offsetExists($offset)
    {
        return isset($this->params[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->params[$offset]) ? $this->params[$offset] : null;
    }

    public function offsetSet($offset, $value)
    {
        $this->params[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        unset($this->params[$offset]);
    }
}

$broadstreet_routes = array();
$broadstreet_editable_posts = array(42, 43, 44, 45);
$broadstreet_post_types = array(42 => 'post', 43 => 'post', 44 => 'post', 45 => 'post', 77 => 'legacy');
$broadstreet_rest_meta = array();
$broadstreet_rest_meta_failures = array();
$broadstreet_rest_options = array();
$broadstreet_rest_transients = array();
$broadstreet_injected_client = null;

class Broadstreet_Test_REST_DB
{
    public $options = 'wp_options';

    public function insert($table, $data, $format = null)
    {
        global $broadstreet_rest_options;
        $key = $data['option_name'];
        if (isset($broadstreet_rest_options[$key])) {
            return false;
        }
        $broadstreet_rest_options[$key] = $data['option_value'];
        return 1;
    }

    public function delete($table, $where, $format = null)
    {
        global $broadstreet_rest_options;
        $key = $where['option_name'];
        if (!isset($broadstreet_rest_options[$key])
            || $broadstreet_rest_options[$key] !== $where['option_value']) {
            return false;
        }
        unset($broadstreet_rest_options[$key]);
        return 1;
    }

    public function prepare($query, $value)
    {
        return array($query, $value);
    }

    public function get_var($prepared)
    {
        global $broadstreet_rest_options;
        return isset($broadstreet_rest_options[$prepared[1]])
            ? $broadstreet_rest_options[$prepared[1]]
            : null;
    }

    public function suppress_errors($suppress = true)
    {
        return false;
    }
}

$wpdb = new Broadstreet_Test_REST_DB();

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
    global $broadstreet_injected_client;
    if ($hook === 'broadstreet_sponsor_client' && is_object($broadstreet_injected_client)) {
        return $broadstreet_injected_client;
    }

    return $value;
}

function get_option($key)
{
    global $broadstreet_rest_options;
    if ($key === 'Broadstreet_Network_Key') {
        return '88';
    }

    if ($key === 'Broadstreet_API_Key') {
        return 'fake-test-key';
    }

    return isset($broadstreet_rest_options[$key])
        ? maybe_unserialize($broadstreet_rest_options[$key])
        : false;
}

function add_option($key, $value, $deprecated = '', $autoload = 'yes')
{
    global $broadstreet_rest_options;
    if (isset($broadstreet_rest_options[$key])) {
        return false;
    }
    $broadstreet_rest_options[$key] = $value;
    return true;
}

function delete_option($key)
{
    global $broadstreet_rest_options;
    unset($broadstreet_rest_options[$key]);
    return true;
}

function maybe_serialize($value)
{
    return serialize($value);
}

function maybe_unserialize($value)
{
    if (!is_string($value)) {
        return $value;
    }

    $unserialized = @unserialize($value);
    return $unserialized === false && $value !== serialize(false) ? $value : $unserialized;
}

function get_transient($key)
{
    global $broadstreet_rest_transients;
    return isset($broadstreet_rest_transients[$key]) ? $broadstreet_rest_transients[$key] : false;
}

function set_transient($key, $value, $ttl = 0)
{
    global $broadstreet_rest_transients;
    $broadstreet_rest_transients[$key] = $value;
    return true;
}

function delete_transient($key)
{
    global $broadstreet_rest_transients;
    unset($broadstreet_rest_transients[$key]);
    return true;
}

function get_post_types($args = array(), $output = 'names')
{
    $types = array(
        'post' => (object) array('name' => 'post', 'show_in_rest' => true),
        'legacy' => (object) array('name' => 'legacy', 'show_in_rest' => false),
    );

    if (isset($args['show_in_rest']) && $args['show_in_rest']) {
        unset($types['legacy']);
    }

    return $output === 'objects' ? $types : array_keys($types);
}

function post_type_supports($post_type, $feature)
{
    return $post_type === 'post';
}

function use_block_editor_for_post_type($post_type)
{
    return $post_type === 'post';
}

function get_post_type($post_id)
{
    global $broadstreet_post_types;
    return isset($broadstreet_post_types[$post_id]) ? $broadstreet_post_types[$post_id] : false;
}

function current_user_can($capability, $post_id = null)
{
    global $broadstreet_editable_posts;
    if ($capability === 'edit_others_posts') {
        return true;
    }

    return $capability === 'edit_post' && in_array($post_id, $broadstreet_editable_posts, true);
}

function register_rest_route($namespace, $route, $args)
{
    global $broadstreet_routes;
    $broadstreet_routes[$namespace . $route] = $args;
}

function rest_ensure_response($data)
{
    return new WP_REST_Response($data);
}

function get_post_meta($post_id, $key, $single = false)
{
    global $broadstreet_rest_meta;
    return isset($broadstreet_rest_meta[$post_id][$key]) ? $broadstreet_rest_meta[$post_id][$key] : '';
}

function metadata_exists($meta_type, $post_id, $key)
{
    global $broadstreet_rest_meta;
    return isset($broadstreet_rest_meta[$post_id][$key]);
}

function update_post_meta($post_id, $key, $value)
{
    global $broadstreet_rest_meta, $broadstreet_rest_meta_failures;
    if (isset($broadstreet_rest_meta_failures[$post_id][$key])) {
        return false;
    }
    $broadstreet_rest_meta[$post_id][$key] = $value;
    return true;
}

function absint($value)
{
    return abs((int) $value);
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

class Broadstreet_Fake_REST_Client
{
    public $create_exception = null;
    public $create_calls = array();

    public function getAdvertisers($network_id)
    {
        return array(
            (object) array('id' => '20', 'name' => 'Zulu', 'access_token' => 'do-not-leak'),
            (object) array('id' => 3, 'name' => 'Alpha', 'internal' => 'do-not-leak'),
            (object) array('id' => 'invalid', 'name' => 'Broken'),
        );
    }

    public function createAdvertiser($network_id, $name)
    {
        $this->create_calls[] = array((string) $network_id, $name);
        if ($this->create_exception) {
            throw $this->create_exception;
        }

        return (object) array('id' => '41', 'name' => $name, 'access_token' => 'do-not-leak');
    }
}

class Broadstreet_Fake_REST_Sync
{
    public $sync_calls = array();
    public $status_calls = array();

    public function getStatus($post_id)
    {
        $this->status_calls[] = $post_id;
        return array(
            'state' => 'error',
            'message' => 'Broadstreet could not update the tracker. Save or retry to try again.',
            'retryable' => true,
            'updated_at' => 123,
            'private_debug' => 'access_token=secret',
        );
    }

    public function sync($post_id, $explicit = false)
    {
        $this->sync_calls[] = array($post_id, $explicit);
        return array(
            'state' => 'synced',
            'message' => 'Broadstreet tracking is synchronized.',
            'retryable' => false,
            'updated_at' => 124,
            'private_debug' => 'access_token=secret',
        );
    }

    public function getRewriteRepublishOriginal($post_id)
    {
        if (get_post_meta($post_id, '_dp_is_rewrite_republish_copy', true) === '1') {
            return (int) get_post_meta($post_id, '_dp_original', true);
        }

        return 0;
    }
}

$plugin_root = dirname(dirname(__DIR__));
require_once $plugin_root . '/Broadstreet/Core.php';

$base_reflection = new ReflectionClass('Broadstreet_Core');
$base_core = $base_reflection->newInstanceWithoutConstructor();
$broadstreet_injected_client = new stdClass();
broadstreet_assert_same(
    $broadstreet_injected_client,
    $base_core->getSponsorBroadstreetClient(),
    'MU/integration tests should be able to inject a fake client only for sponsor operations.'
);
broadstreet_assert_same(
    false,
    $base_core->getBroadstreetClient() === $broadstreet_injected_client,
    'The sponsor fake-client filter must not replace clients used by unrelated Broadstreet features.'
);
$broadstreet_injected_client = null;

class Broadstreet_Test_REST_Core extends Broadstreet_Core
{
    public $fake_client;
    public $fake_sync;

    public function getBroadstreetClient()
    {
        return $this->fake_client;
    }

    public function getSponsorSync()
    {
        return $this->fake_sync;
    }
}

$reflection = new ReflectionClass('Broadstreet_Test_REST_Core');
$core = $reflection->newInstanceWithoutConstructor();
$core->fake_client = new Broadstreet_Fake_REST_Client();
$core->fake_sync = new Broadstreet_Fake_REST_Sync();
$core->registerSponsorRoutes();

broadstreet_assert_same(
    array('broadstreet/v1/advertisers', 'broadstreet/v1/sponsor-status'),
    array_keys($broadstreet_routes),
    'The editor should receive only the narrow advertiser and reconciliation-status resources.'
);

$advertiser_routes = $broadstreet_routes['broadstreet/v1/advertisers'];
$status_routes = $broadstreet_routes['broadstreet/v1/sponsor-status'];
broadstreet_assert_same('GET', $advertiser_routes[0]['methods'], 'Advertiser enumeration should be a GET endpoint.');
broadstreet_assert_same('POST', $advertiser_routes[1]['methods'], 'Advertiser creation should be an explicit POST endpoint.');
broadstreet_assert_same('GET', $status_routes[0]['methods'], 'Reconciliation status should be read separately.');
broadstreet_assert_same('POST', $status_routes[1]['methods'], 'Reconciliation retry should require an explicit POST action.');

$allowed_request = new Broadstreet_Test_REST_Request(array('post_id' => 42));
$legacy_request = new Broadstreet_Test_REST_Request(array('post_id' => 77));
$denied_request = new Broadstreet_Test_REST_Request(array('post_id' => 99));
broadstreet_assert_same(true, call_user_func($advertiser_routes[0]['permission_callback'], $allowed_request), 'An editor of the target post may enumerate advertisers.');
broadstreet_assert_same(false, call_user_func($advertiser_routes[0]['permission_callback'], $legacy_request), 'Unsupported post types may not use the Gutenberg sponsor API.');
broadstreet_assert_same(false, call_user_func($advertiser_routes[1]['permission_callback'], $denied_request), 'Users without edit_post permission may not create advertisers.');

$advertisers_response = $core->getSponsorAdvertisers($allowed_request);
$advertisers = $advertisers_response->get_data();
broadstreet_assert_same(
    array(
        array('id' => '3', 'name' => 'Alpha'),
        array('id' => '20', 'name' => 'Zulu'),
    ),
    $advertisers,
    'Advertiser responses should be sorted and expose IDs/names only.'
);
broadstreet_assert_same(false, strpos(json_encode($advertisers), 'access_token'), 'Advertiser responses must not expose credentials.');
broadstreet_assert_same('no-store, private', $advertisers_response->headers['Cache-Control'], 'Account-derived GET responses should not be cached.');

$invalid_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => ' x ')));
broadstreet_assert_same(true, is_wp_error($invalid_create), 'Advertiser names below the legacy three-character bound should be rejected.');
broadstreet_assert_same(0, count($core->fake_client->create_calls), 'Invalid advertiser creation should not call the vendor.');

$created_response = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => ' New Sponsor ')));
$created = $created_response->get_data();
broadstreet_assert_same(array('id' => '41', 'name' => 'New Sponsor'), $created, 'Creation should expose only the new advertiser ID/name.');
broadstreet_assert_same(array(array('88', 'New Sponsor')), $core->fake_client->create_calls, 'One explicit request should create exactly one advertiser.');
broadstreet_assert_same(false, strpos(json_encode($created), 'access_token'), 'Creation responses must not expose credentials.');

$core->fake_client->create_exception = new RuntimeException('access_token=raw-secret vendor response');
$failed_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => 'Uncertain Sponsor')));
broadstreet_assert_same(true, is_wp_error($failed_create), 'Vendor creation failures should become REST errors.');
broadstreet_assert_same(false, strpos($failed_create->get_error_message(), 'raw-secret'), 'REST errors must not include raw vendor failures.');
broadstreet_assert_same(
    false,
    isset($broadstreet_rest_transients['bs_advertiser_creating_42']),
    'The create guard should be released after a failure so the user can retry.'
);

$core->fake_client->create_exception = null;
$unicode_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 44, 'name' => '猫猫猫')));
broadstreet_assert_same(false, is_wp_error($unicode_create), 'Server-side name bounds should count Unicode code points like the editor.');
$create_call_count = count($core->fake_client->create_calls);

$core->fake_client->create_exception = new Broadstreet_ServerException('invalid', '', 422);
$rejected_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 45, 'name' => 'Retry Sponsor')));
$calls_after_rejection = count($core->fake_client->create_calls);
broadstreet_assert_same('broadstreet_advertiser_rejected', $rejected_create->get_error_code(), 'A definite advertiser 422 should be distinguished from other failures.');
$core->fake_client->create_exception = null;
$retried_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 45, 'name' => 'Retry Sponsor')));
broadstreet_assert_same(false, is_wp_error($retried_create), 'An explicit advertiser-create action may retry a definite 422.');
broadstreet_assert_same($calls_after_rejection + 1, count($core->fake_client->create_calls), 'The definite advertiser failure should dispatch exactly one explicit retry.');
$create_call_count = count($core->fake_client->create_calls);

$broadstreet_rest_transients['bs_advertiser_creating_42'] = 1;
$contended_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => 'Another Sponsor')));
broadstreet_assert_same(true, is_wp_error($contended_create), 'Concurrent advertiser creation should stop at the per-post guard.');
broadstreet_assert_same('broadstreet_advertiser_create_in_progress', $contended_create->get_error_code(), 'Guarded creation should return the in-progress error code.');
broadstreet_assert_same($create_call_count, count($core->fake_client->create_calls), 'Guard contention must not dispatch a second create POST.');
unset($broadstreet_rest_transients['bs_advertiser_creating_42']);

$status_response = $core->getSponsorStatus($allowed_request);
$status = $status_response->get_data();
broadstreet_assert_same(
    array(
        'state' => 'error',
        'message' => 'Broadstreet could not update the tracker. Save or retry to try again.',
        'retryable' => true,
        'updated_at' => 123,
    ),
    $status,
    'Status responses should use a fixed public allowlist.'
);
broadstreet_assert_same('no-store, private', $status_response->headers['Cache-Control'], 'Status responses should not be cached.');

// A retry on a post that never touched sponsorship must not synchronize or
// write status meta onto it.
$pristine_retry = $core->retrySponsorStatus($allowed_request)->get_data();
broadstreet_assert_same(array(), $core->fake_sync->sync_calls, 'Retrying a pristine post must not enter the sync path.');
broadstreet_assert_same('error', $pristine_retry['state'], 'A pristine retry should just echo the stored status.');

$broadstreet_rest_meta[42]['bs_sponsor_is_sponsored'] = '1';
$retried = $core->retrySponsorStatus($allowed_request)->get_data();
broadstreet_assert_same('synced', $retried['state'], 'Explicit status POST should synchronize immediately.');
broadstreet_assert_same(array(array(42, true)), $core->fake_sync->sync_calls, 'REST retry must be marked explicit.');
broadstreet_assert_same(false, strpos(json_encode($retried), 'secret'), 'Retry responses must retain the status allowlist.');
$core->fake_sync->sync_calls = array();
unset($broadstreet_rest_meta[42]);

// A post that has never touched sponsorship is left completely alone.
$untouched = $core->syncSponsorPost(43);
broadstreet_assert_same(false, $untouched, 'Untouched posts should not synchronize.');
broadstreet_assert_same(array(), $core->fake_sync->sync_calls, 'Untouched posts must not reach the synchronizer.');

// A post with sponsor meta synchronizes inline on save.
$broadstreet_rest_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '20',
);
$synced = $core->syncSponsorPost(42);
broadstreet_assert_same('synced', $synced['state'], 'Sponsored posts should synchronize inline.');
broadstreet_assert_same(array(array(42, false)), $core->fake_sync->sync_calls, 'Ordinary saves are not explicit retries.');
$core->fake_sync->sync_calls = array();

// Saving a Rewrite & Republish draft keeps the original's tracker current.
$broadstreet_rest_meta[44] = array(
    '_dp_is_rewrite_republish_copy' => '1',
    '_dp_original' => '42',
    'bs_sponsor_is_sponsored' => '1',
);
$core->syncSponsorPost(44);
broadstreet_assert_same(
    array(array(44, false), array(42, false)),
    $core->fake_sync->sync_calls,
    'A Rewrite & Republish draft save should record its own status and synchronize the original.'
);

// The editor panel on a Rewrite & Republish draft must see, and be able to
// retry, the ORIGINAL post's synchronization; the draft itself is always noop.
$core->fake_sync->status_calls = array();
$core->getSponsorStatus(new Broadstreet_Test_REST_Request(array('post_id' => 44)));
broadstreet_assert_same(
    array(42),
    $core->fake_sync->status_calls,
    'Status reads on a Rewrite & Republish draft should resolve to the original post.'
);
$core->fake_sync->sync_calls = array();
$core->retrySponsorStatus(new Broadstreet_Test_REST_Request(array('post_id' => 44)));
broadstreet_assert_same(
    array(array(42, true)),
    $core->fake_sync->sync_calls,
    'Explicit retries from a Rewrite & Republish draft should recover the original post.'
);

// The end-of-request deferred sync (REST publishes) never repeats work that
// rest_after_insert already did in the same request.
$core->fake_sync->sync_calls = array();
$core->getSponsorController()->syncPostUnlessAlreadySynced(42);
broadstreet_assert_same(
    array(),
    $core->fake_sync->sync_calls,
    'A deferred sync must skip posts already synchronized during this request.'
);
$broadstreet_rest_meta[43] = array('bs_sponsor_is_sponsored' => '1');
$core->getSponsorController()->syncPostUnlessAlreadySynced(43);
broadstreet_assert_same(
    array(array(43, false)),
    $core->fake_sync->sync_calls,
    'A deferred sync must cover posts no rest_after_insert hook reached.'
);

echo "Sponsor REST smoke test passed.\n";
