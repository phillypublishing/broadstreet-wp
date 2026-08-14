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
$broadstreet_injected_client = null;
$broadstreet_rest_scheduled = array();
$broadstreet_schedule_failure = false;

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

function wp_next_scheduled($hook, $args = array())
{
    global $broadstreet_rest_scheduled;
    foreach ($broadstreet_rest_scheduled as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['time'];
        }
    }
    return false;
}

function wp_schedule_single_event($time, $hook, $args = array())
{
    global $broadstreet_rest_scheduled, $broadstreet_schedule_failure;
    if ($broadstreet_schedule_failure) {
        return false;
    }
    $broadstreet_rest_scheduled[] = array('time' => $time, 'hook' => $hook, 'args' => $args);
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

class Broadstreet_Fake_REST_Reconciler
{
    public $retry_calls = array();
    public $recorded_statuses = array();

    public function recordStatus($post_id, $state, $message, $retryable)
    {
        $status = array(
            'state' => $state,
            'message' => $message,
            'retryable' => $retryable,
            'updated_at' => 122,
        );
        $this->recorded_statuses[] = array($post_id, $status);
        return $status;
    }

    public function getStatus($post_id)
    {
        return array(
            'state' => 'error',
            'message' => 'Broadstreet could not update the tracker. Save or retry to try again.',
            'retryable' => true,
            'updated_at' => 123,
            'private_debug' => 'access_token=secret',
        );
    }

    public function reconcile($post_id, $explicit = false)
    {
        $this->retry_calls[] = array($post_id, $explicit);
        return array(
            'state' => 'synced',
            'message' => 'Broadstreet tracking is synchronized.',
            'retryable' => false,
            'updated_at' => 124,
            'private_debug' => 'access_token=secret',
        );
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
    public $fake_reconciler;

    public function getBroadstreetClient()
    {
        return $this->fake_client;
    }

    public function getSponsorReconciler()
    {
        return $this->fake_reconciler;
    }
}

$reflection = new ReflectionClass('Broadstreet_Test_REST_Core');
$core = $reflection->newInstanceWithoutConstructor();
$core->fake_client = new Broadstreet_Fake_REST_Client();
$core->fake_reconciler = new Broadstreet_Fake_REST_Reconciler();
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
    true,
    stripos($failed_create->get_error_message(), 'check the Broadstreet dashboard') !== false,
    'Ambiguous creation failures should give credential-free recovery guidance.'
);
$create_call_count = count($core->fake_client->create_calls);
$failed_create_again = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => 'Uncertain Sponsor')));
broadstreet_assert_same(true, is_wp_error($failed_create_again), 'Repeating an outcome-unknown request should remain blocked.');
broadstreet_assert_same($create_call_count, count($core->fake_client->create_calls), 'An outcome-unknown advertiser must never be created automatically again.');
$failed_changed_name = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => 'Different Sponsor')));
broadstreet_assert_same(true, is_wp_error($failed_changed_name), 'Changing the advertiser fingerprint must not bypass an outcome-unknown create.');
broadstreet_assert_same($create_call_count, count($core->fake_client->create_calls), 'Outcome-unknown advertiser state should be global to the post.');

$broadstreet_rest_meta_failures[43][Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT] = true;
$failed_marker = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 43, 'name' => 'Persist First')));
broadstreet_assert_same('broadstreet_advertiser_state_persistence_failed', $failed_marker->get_error_code(), 'Advertiser creation should fail closed when its dispatch marker is not readable.');
broadstreet_assert_same($create_call_count, count($core->fake_client->create_calls), 'A failed pre-dispatch marker must abort before the vendor create call.');

$core->fake_client->create_exception = null;
$unicode_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 44, 'name' => '猫猫猫')));
broadstreet_assert_same(false, is_wp_error($unicode_create), 'Server-side name bounds should count Unicode code points like the editor.');
$create_call_count = count($core->fake_client->create_calls);

$core->fake_client->create_exception = new Broadstreet_ServerException('invalid', '', 422);
$rejected_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 45, 'name' => 'Retry Sponsor')));
$calls_after_rejection = count($core->fake_client->create_calls);
broadstreet_assert_same('broadstreet_advertiser_rejected', $rejected_create->get_error_code(), 'A definite advertiser 422 should be distinguished from an ambiguous outcome.');
$core->fake_client->create_exception = null;
$retried_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 45, 'name' => 'Retry Sponsor')));
broadstreet_assert_same(false, is_wp_error($retried_create), 'An explicit advertiser-create action may retry a definite 422.');
broadstreet_assert_same($calls_after_rejection + 1, count($core->fake_client->create_calls), 'The definite advertiser failure should dispatch exactly one explicit retry.');
$create_call_count = count($core->fake_client->create_calls);

$broadstreet_rest_options['_broadstreet_sponsor_advertiser_create_lock_42'] = maybe_serialize(array(
    'token' => 'other-request',
    'created_at' => time(),
));
$contended_create = $core->createSponsorAdvertiser(new Broadstreet_Test_REST_Request(array('post_id' => 42, 'name' => 'Another Sponsor')));
broadstreet_assert_same(true, is_wp_error($contended_create), 'Concurrent advertiser creation should stop at the per-post lock.');
broadstreet_assert_same($create_call_count, count($core->fake_client->create_calls), 'Lock contention must not dispatch a second create POST.');
unset($broadstreet_rest_options['_broadstreet_sponsor_advertiser_create_lock_42']);

$status_response = $core->getSponsorStatus($allowed_request);
$status = $status_response->get_data();
broadstreet_assert_same(
    array(
        'state' => 'error',
        'message' => 'Broadstreet could not update the tracker. Save or retry to try again.',
        'retryable' => true,
        'updated_at' => 123,
        'poll_after' => 0,
    ),
    $status,
    'Status responses should use a fixed public allowlist.'
);
broadstreet_assert_same('no-store, private', $status_response->headers['Cache-Control'], 'Status responses should not be cached.');

$retried = $core->retrySponsorStatus($allowed_request)->get_data();
broadstreet_assert_same('synced', $retried['state'], 'Explicit status POST should retry reconciliation.');
broadstreet_assert_same(array(array(42, true)), $core->fake_reconciler->retry_calls, 'REST retry must be marked explicit.');
broadstreet_assert_same(false, strpos(json_encode($retried), 'secret'), 'Retry responses must retain the status allowlist.');

$broadstreet_rest_meta[42] = array(
    'bs_sponsor_is_sponsored' => '',
    'bs_sponsor_advertiser_id' => '20',
);
$queued_disabled = $core->queueSponsorPost(42);
broadstreet_assert_same('disabled', $queued_disabled['state'], 'Disabled posts should receive a fixed synchronous status.');
broadstreet_assert_same(array(), $broadstreet_rest_scheduled, 'Disabled posts must not enqueue vendor work.');

$broadstreet_rest_meta[42]['bs_sponsor_is_sponsored'] = '1';
$broadstreet_rest_meta[42]['bs_sponsor_advertiser_id'] = '';
$queued_waiting = $core->queueSponsorPost(42);
broadstreet_assert_same('waiting', $queued_waiting['state'], 'Advertiser-missing posts should receive a fixed synchronous status.');
broadstreet_assert_same(array(), $broadstreet_rest_scheduled, 'Advertiser-missing posts must not enqueue vendor work.');

$broadstreet_rest_meta[42]['bs_sponsor_advertiser_id'] = '20';
$queued = $core->queueSponsorPost(42);
$queued_again = $core->queueSponsorPost(42);
broadstreet_assert_same('queued', $queued['state'], 'Eligible ordinary reconciliation should return a fixed queued status.');
broadstreet_assert_same('queued', $queued_again['state'], 'Repeated ordinary reconciliation should remain queued.');
broadstreet_assert_same(1, count($broadstreet_rest_scheduled), 'Ordinary reconciliation events should be deduplicated per post.');

$broadstreet_rest_scheduled = array();
$broadstreet_schedule_failure = true;
$queue_failure = $core->queueSponsorPost(42);
broadstreet_assert_same('error', $queue_failure['state'], 'Cron scheduling failure should be surfaced explicitly.');
broadstreet_assert_same(true, $queue_failure['retryable'], 'Scheduling failure should expose the direct Retry action.');

echo "Sponsor REST smoke test passed.\n";
