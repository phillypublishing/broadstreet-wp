<?php

/**
 * Focused state-machine proof for remote sponsored tracker reconciliation.
 */

if (!class_exists('Broadstreet_ServerException')) {
    class Broadstreet_ServerException extends Exception
    {
        public $code;

        public function __construct($message, $error = '', $code = 500)
        {
            $this->code = $code;
            parent::__construct($message);
        }
    }
}

$broadstreet_meta = array();
$broadstreet_options = array();
$broadstreet_scheduled = array();
$broadstreet_posts = array();
$broadstreet_lock_mutation = null;
$broadstreet_meta_failures = array();
$broadstreet_meta_update_counts = array();
$broadstreet_steal_lock_after_network = false;

class Broadstreet_Test_Sponsor_DB
{
    public $options = 'wp_options';

    public function insert($table, $data, $format = null)
    {
        global $broadstreet_options, $broadstreet_lock_mutation;
        $key = $data['option_name'];
        if (isset($broadstreet_options[$key])) {
            return false;
        }
        $broadstreet_options[$key] = $data['option_value'];
        if (is_callable($broadstreet_lock_mutation)) {
            call_user_func($broadstreet_lock_mutation, $key);
        }
        return 1;
    }

    public function delete($table, $where, $format = null)
    {
        global $broadstreet_options;
        $key = $where['option_name'];
        if (!isset($broadstreet_options[$key])
            || $broadstreet_options[$key] !== $where['option_value']) {
            return false;
        }
        unset($broadstreet_options[$key]);
        return 1;
    }

    public function prepare($query, $value)
    {
        return array($query, $value);
    }

    public function get_var($prepared)
    {
        global $broadstreet_options;
        return isset($broadstreet_options[$prepared[1]])
            ? $broadstreet_options[$prepared[1]]
            : null;
    }

    public function suppress_errors($suppress = true)
    {
        return false;
    }
}

$wpdb = new Broadstreet_Test_Sponsor_DB();

function get_post_meta($post_id, $key = '', $single = false)
{
    global $broadstreet_meta;

    if ($key === '') {
        return isset($broadstreet_meta[$post_id]) ? $broadstreet_meta[$post_id] : array();
    }

    return isset($broadstreet_meta[$post_id][$key]) ? $broadstreet_meta[$post_id][$key] : '';
}

function update_post_meta($post_id, $key, $value)
{
    global $broadstreet_meta, $broadstreet_meta_failures, $broadstreet_meta_update_counts;

    if (!isset($broadstreet_meta_update_counts[$post_id][$key])) {
        $broadstreet_meta_update_counts[$post_id][$key] = 0;
    }
    ++$broadstreet_meta_update_counts[$post_id][$key];

    if (isset($broadstreet_meta_failures[$post_id][$key])) {
        return false;
    }

    $broadstreet_meta[$post_id][$key] = $value;
    return true;
}

function get_option($key, $default = false)
{
    global $broadstreet_options;

    return isset($broadstreet_options[$key])
        ? maybe_unserialize($broadstreet_options[$key])
        : $default;
}

function add_option($key, $value, $deprecated = '', $autoload = 'yes')
{
    global $broadstreet_options, $broadstreet_lock_mutation;

    if (isset($broadstreet_options[$key])) {
        return false;
    }

    $broadstreet_options[$key] = $value;
    if (is_callable($broadstreet_lock_mutation)) {
        call_user_func($broadstreet_lock_mutation, $key);
    }

    return true;
}

function delete_option($key)
{
    global $broadstreet_options;

    unset($broadstreet_options[$key]);
    return true;
}

function wp_next_scheduled($hook, $args = array())
{
    global $broadstreet_scheduled;

    foreach ($broadstreet_scheduled as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['time'];
        }
    }

    return false;
}

function wp_schedule_single_event($timestamp, $hook, $args = array())
{
    global $broadstreet_scheduled;

    $broadstreet_scheduled[] = array('time' => $timestamp, 'hook' => $hook, 'args' => $args);
    return true;
}

function get_post_status($post_id)
{
    global $broadstreet_posts;
    return $broadstreet_posts[$post_id]['status'];
}

function get_the_permalink($post_id)
{
    return 'https://example.test/published-' . $post_id;
}

function get_permalink($post_id)
{
    return 'https://example.test/original-' . $post_id;
}

function get_sample_permalink($post_id)
{
    return array('https://example.test/%postname%/', 'sample-' . $post_id);
}

function get_the_title($post_id)
{
    global $broadstreet_posts;
    return $broadstreet_posts[$post_id]['title'];
}

function wp_json_encode($value)
{
    return json_encode($value);
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

function broadstreet_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function broadstreet_assert_true($actual, $message)
{
    broadstreet_assert_same(true, $actual, $message);
}

class Broadstreet_Fake_Sponsor_Client
{
    public $calls = array();
    public $use_tracker_v3 = true;
    public $create_exception = null;
    public $update_exception = null;
    public $next_ad_id = '7001';
    public $remote_advertisements = array();
    public $lose_move_response = false;

    public function getNetwork($network_id)
    {
        global $broadstreet_options, $broadstreet_steal_lock_after_network;

        $this->calls[] = array('getNetwork', (string) $network_id);
        if ($broadstreet_steal_lock_after_network) {
            $broadstreet_options['_broadstreet_sponsor_lock_42'] = maybe_serialize(array(
                'token' => 'replacement-owner',
                'created_at' => time(),
            ));
            $broadstreet_steal_lock_after_network = false;
        }
        return (object) array('use_tracker_v3' => $this->use_tracker_v3);
    }

    public function createAdvertisement($network_id, $advertiser_id, $name, $type, $options = array())
    {
        $this->calls[] = array(
            'createAdvertisement',
            (string) $network_id,
            (string) $advertiser_id,
            $name,
            $type,
            $options,
        );

        if ($this->create_exception) {
            throw $this->create_exception;
        }

        return (object) array('id' => $this->next_ad_id);
    }

    public function updateAdvertisement($network_id, $advertiser_id, $advertisement_id, $params = array())
    {
        $this->calls[] = array(
            'updateAdvertisement',
            (string) $network_id,
            (string) $advertiser_id,
            (string) $advertisement_id,
            $params,
        );

        if (isset($params['new_advertiser_id']) && $this->lose_move_response) {
            unset($this->remote_advertisements[(string) $advertiser_id][(string) $advertisement_id]);
            $this->remote_advertisements[(string) $params['new_advertiser_id']][(string) $advertisement_id] = true;
            throw new RuntimeException('response lost after remote move');
        }

        if ($this->update_exception) {
            throw $this->update_exception;
        }

        if (isset($params['new_advertiser_id'])) {
            unset($this->remote_advertisements[(string) $advertiser_id][(string) $advertisement_id]);
            $this->remote_advertisements[(string) $params['new_advertiser_id']][(string) $advertisement_id] = true;
        }
        return (object) array('id' => $advertisement_id);
    }

    public function getAdvertisement($network_id, $advertiser_id, $advertisement_id)
    {
        $this->calls[] = array(
            'getAdvertisement',
            (string) $network_id,
            (string) $advertiser_id,
            (string) $advertisement_id,
        );

        if (!empty($this->remote_advertisements[(string) $advertiser_id][(string) $advertisement_id])) {
            return (object) array('id' => (string) $advertisement_id);
        }

        throw new Broadstreet_ServerException('missing', '', 404);
    }
}

function broadstreet_reset_sponsor_fixture()
{
    global $broadstreet_meta, $broadstreet_options, $broadstreet_scheduled, $broadstreet_posts, $broadstreet_lock_mutation, $broadstreet_meta_failures, $broadstreet_meta_update_counts, $broadstreet_steal_lock_after_network;

    $broadstreet_meta = array();
    $broadstreet_options = array();
    $broadstreet_scheduled = array();
    $broadstreet_lock_mutation = null;
    $broadstreet_meta_failures = array();
    $broadstreet_meta_update_counts = array();
    $broadstreet_steal_lock_after_network = false;
    $broadstreet_posts = array(
        42 => array('status' => 'draft', 'title' => 'Sponsor story'),
    );
}

$plugin_root = dirname(dirname(__DIR__));
require_once $plugin_root . '/Broadstreet/SponsorReconciler.php';

// Turning sponsorship off is entirely local and preserves reusable remote IDs.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '',
    'bs_sponsor_advertiser_id' => '17',
    'bs_sponsor_advertisement_id' => '901',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('disabled', $result['state'], 'Disabled sponsorship should reconcile locally.');
broadstreet_assert_same('17', $broadstreet_meta[42]['bs_sponsor_advertiser_id'], 'Disabling must preserve the advertiser ID.');
broadstreet_assert_same('901', $broadstreet_meta[42]['bs_sponsor_advertisement_id'], 'Disabling must preserve the advertisement ID.');
broadstreet_assert_same(array(), $api->calls, 'Disabling should not make remote calls.');

// The lock is acquired before canonical state is read, and draft URLs/title/type retain legacy rules.
broadstreet_reset_sponsor_fixture();
$broadstreet_posts[42]['title'] = str_repeat('A', 140);
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '17',
    'bs_sponsor_advertisement_id' => '',
);
$broadstreet_lock_mutation = function ($key) use (&$broadstreet_meta) {
    if ($key === '_broadstreet_sponsor_lock_42') {
        $broadstreet_meta[42]['bs_sponsor_advertiser_id'] = '23';
    }
};
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
$create_call = $api->calls[1];
broadstreet_assert_same('synced', $result['state'], 'A new sponsored post should create its remote tracker.');
broadstreet_assert_same('23', $create_call[2], 'Canonical advertiser state must be re-read after acquiring the lock.');
broadstreet_assert_same(127, strlen($create_call[3]), 'Remote advertisement titles should remain bounded to 127 characters.');
broadstreet_assert_same('analytics_tracker', $create_call[4], 'Tracker v3 networks should use analytics_tracker.');
broadstreet_assert_same(
    'https://example.test/sample-42/',
    $create_call[5]['stencil_inputs']['url'],
    'Drafts should retain their sample permalink URL.'
);
broadstreet_assert_same('7001', $broadstreet_meta[42]['bs_sponsor_advertisement_id'], 'Created tracker IDs should be stored server-side.');

$broadstreet_lock_mutation = null;
$call_count = count($api->calls);
$second_result = $reconciler->reconcile(42);
broadstreet_assert_same('noop', $second_result['state'], 'An unchanged desired fingerprint should be a no-op.');
broadstreet_assert_same($call_count, count($api->calls), 'A fingerprint no-op should make no vendor API calls.');

foreach (array('pending', 'future') as $unpublished_status) {
    broadstreet_reset_sponsor_fixture();
    $broadstreet_posts[42]['status'] = $unpublished_status;
    $broadstreet_posts[42]['title'] = 'Hi';
    $broadstreet_meta[42] = array(
        'bs_sponsor_is_sponsored' => '1',
        'bs_sponsor_advertiser_id' => '23',
        'bs_sponsor_advertisement_id' => '',
    );
    $api = new Broadstreet_Fake_Sponsor_Client();
    $reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
    $reconciler->reconcile(42);
    $create_call = $api->calls[1];
    broadstreet_assert_same(
        'https://example.test/sample-42/',
        $create_call[5]['stencil_inputs']['url'],
        ucfirst($unpublished_status) . ' posts should retain their sample permalink URL.'
    );
    broadstreet_assert_same('Hi***', $create_call[3], 'Short remote advertisement titles should retain the five-character minimum.');
}

// Existing trackers move from their last known remote advertiser to the selected advertiser.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => true,
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '902',
    '_bs_sponsor_remote_advertiser_id' => '17',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->use_tracker_v3 = false;
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
$update_call = $api->calls[1];
broadstreet_assert_same('synced', $result['state'], 'An existing tracker should update in place.');
broadstreet_assert_same('17', $update_call[2], 'A move should address the tracker through its old advertiser.');
broadstreet_assert_same('31', $update_call[4]['new_advertiser_id'], 'A move should send the new advertiser explicitly.');
broadstreet_assert_same('tracker', $update_call[4]['type'], 'Legacy networks should retain tracker type.');
broadstreet_assert_same('31', $broadstreet_meta[42]['_bs_sponsor_remote_advertiser_id'], 'Successful moves should advance server-owned remote state.');

// An advertiser-scoped 404 cannot prove network-wide absence, so replacement
// must stop for operator reconciliation rather than creating a duplicate.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '902',
    '_bs_sponsor_remote_advertiser_id' => '31',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->update_exception = new Broadstreet_ServerException('secret vendor response', '', 404);
$api->next_ad_id = '903';
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('needs_action', $result['state'], 'A scoped 404 should stop for operator reconciliation.');
broadstreet_assert_same('902', $broadstreet_meta[42]['bs_sponsor_advertisement_id'], 'An unproven 404 must preserve the canonical tracker ID.');
broadstreet_assert_same('1', $broadstreet_meta[42]['bs_sponsor_is_sponsored'], '404 handling must preserve editorial sponsorship.');
broadstreet_assert_same(
    0,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'createAdvertisement'; })),
    'A single advertiser-scoped 404 must never create a replacement.'
);

// A request that loses its lock after reading canonical state is fenced before
// the non-idempotent create dispatch.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '',
);
$broadstreet_steal_lock_after_network = true;
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('queued', $result['state'], 'A stale lock owner should stop as queued.');
broadstreet_assert_same(
    0,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'createAdvertisement'; })),
    'A stale lock owner must not dispatch a tracker create.'
);

// An ambiguous create outcome blocks automatic retry and exposes no vendor details.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->create_exception = new RuntimeException('access_token=super-secret timeout body');
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('needs_action', $result['state'], 'Ambiguous creation must require human action.');
broadstreet_assert_same(false, strpos(json_encode($result), 'super-secret'), 'Status must not expose credentials or raw vendor errors.');
$call_count = count($api->calls);
$blocked_result = $reconciler->reconcile(42);
broadstreet_assert_same('needs_action', $blocked_result['state'], 'Automatic saves must remain blocked after ambiguous creation.');
broadstreet_assert_same($call_count, count($api->calls), 'Automatic retry must not repeat an ambiguous create.');

$api->create_exception = null;
$explicit_result = $reconciler->reconcile(42, true);
broadstreet_assert_same('needs_action', $explicit_result['state'], 'A generic retry must not repeat an outcome-unknown create.');
broadstreet_assert_same($call_count, count($api->calls), 'Even an explicit retry must stop until the remote ID can be reconciled safely.');
$broadstreet_posts[42]['title'] = 'Changed title';
$broadstreet_meta[42]['bs_sponsor_advertiser_id'] = '99';
$changed_result = $reconciler->reconcile(42, true);
broadstreet_assert_same('needs_action', $changed_result['state'], 'Fingerprint changes must not bypass an outcome-unknown tracker create.');
broadstreet_assert_same($call_count, count($api->calls), 'Ambiguous tracker create state should be global to the post.');

// Contending saves schedule one coalesced retry instead of entering the critical section.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '',
);
$broadstreet_options['_broadstreet_sponsor_lock_42'] = maybe_serialize(array('token' => 'other-request', 'created_at' => time()));
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$first_contention = $reconciler->reconcile(42);
$second_contention = $reconciler->reconcile(42);
broadstreet_assert_same('queued', $first_contention['state'], 'Lock contention should queue reconciliation.');
broadstreet_assert_same('queued', $second_contention['state'], 'Repeated contention should stay queued.');
broadstreet_assert_same(1, count($broadstreet_scheduled), 'Concurrent saves should coalesce to one retry event.');
broadstreet_assert_same(array(), $api->calls, 'Contending requests must not call the vendor.');

// Scheduled publication and _dp_original keep the canonical public URL behavior.
broadstreet_reset_sponsor_fixture();
$broadstreet_posts[42]['status'] = 'publish';
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '905',
    '_bs_sponsor_remote_advertiser_id' => '31',
    '_dp_original' => '8',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$reconciler->reconcile(42);
$update_call = $api->calls[1];
broadstreet_assert_same(
    'https://example.test/original-8',
    $update_call[4]['stencil_inputs']['url'],
    'Yoast republished posts should track the original public permalink.'
);

// Ordinary update failures preserve all editorial/server IDs and return a generic retryable status.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '906',
    '_bs_sponsor_remote_advertiser_id' => '31',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->update_exception = new Broadstreet_ServerException('access_token=secret raw 500', '', 500);
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('error', $result['state'], 'Update failures should surface an actionable generic error.');
broadstreet_assert_true($result['retryable'], 'Known update failures should be retryable.');
broadstreet_assert_same('31', $broadstreet_meta[42]['bs_sponsor_advertiser_id'], 'API errors must preserve advertiser meta.');
broadstreet_assert_same('906', $broadstreet_meta[42]['bs_sponsor_advertisement_id'], 'API errors must preserve remote tracker IDs.');
broadstreet_assert_same(false, strpos(json_encode($result), 'secret'), 'API status must never expose raw vendor details.');

// A create attempt must be durably readable before the vendor create dispatch.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '',
);
$broadstreet_meta_failures[42][Broadstreet_Sponsor_Reconciler::META_CREATE_ATTEMPT] = true;
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('error', $result['state'], 'Unreadable pre-dispatch state should fail closed.');
broadstreet_assert_same(
    0,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'createAdvertisement'; })),
    'Failed tracker attempt persistence must abort before the vendor create.'
);

// Only a definite 422 can be explicitly retried.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->create_exception = new Broadstreet_ServerException('invalid', '', 422);
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$first_422 = $reconciler->reconcile(42);
$calls_after_422 = count($api->calls);
$automatic_422 = $reconciler->reconcile(42);
broadstreet_assert_same('error', $first_422['state'], 'A definite 422 should be recorded as a correctable failure.');
broadstreet_assert_same($calls_after_422, count($api->calls), 'Automatic reconciliation must not retry a definite 422.');
$api->create_exception = null;
$explicit_422 = $reconciler->reconcile(42, true);
broadstreet_assert_same('synced', $explicit_422['state'], 'An explicit user action may retry a definite 422.');

// A lost move response is recovered by exact lookup under the intended owner.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '902',
    '_bs_sponsor_remote_advertiser_id' => '17',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->remote_advertisements['17']['902'] = true;
$api->lose_move_response = true;
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('synced', $result['state'], 'A response-lost move should recover as synced from remote evidence.');
broadstreet_assert_same('31', $broadstreet_meta[42][Broadstreet_Sponsor_Reconciler::META_REMOTE_ADVERTISER], 'Recovered moves should persist the confirmed owner.');
broadstreet_assert_same(
    0,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'createAdvertisement'; })),
    'A confirmed remote move must never create a replacement.'
);
broadstreet_assert_true(
    count(array_filter($api->calls, function ($call) { return $call[0] === 'getAdvertisement' && $call[2] === '31'; })) > 0,
    'Move recovery should query the exact advertisement under the intended owner.'
);

// Even two known-owner 404s cannot prove the tracker was not moved to a third
// advertiser, so the plugin stops instead of creating a replacement.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '902',
    '_bs_sponsor_remote_advertiser_id' => '17',
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->update_exception = new Broadstreet_ServerException('missing current owner', '', 404);
$api->next_ad_id = '904';
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
$lookup_owners = array_map(function ($call) { return $call[2]; }, array_values(array_filter($api->calls, function ($call) {
    return $call[0] === 'getAdvertisement';
})));
broadstreet_assert_same(array('31', '17'), $lookup_owners, 'Ambiguous moves should prove absence under intended and current owners.');
broadstreet_assert_same('needs_action', $result['state'], 'Known-owner absence should require operator reconciliation.');
broadstreet_assert_same('902', $broadstreet_meta[42]['bs_sponsor_advertisement_id'], 'Known-owner absence must preserve the canonical ID.');
broadstreet_assert_same(
    0,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'createAdvertisement'; })),
    'Known-owner absence must never create a replacement without network-wide proof.'
);

// A prior ambiguous move is recovered before another update is dispatched.
broadstreet_reset_sponsor_fixture();
$broadstreet_meta[42] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '31',
    'bs_sponsor_advertisement_id' => '902',
    '_bs_sponsor_remote_advertiser_id' => '17',
    Broadstreet_Sponsor_Reconciler::META_MOVE_ATTEMPT => array(
        'state' => 'outcome_unknown',
        'advertisement_id' => '902',
        'from_advertiser_id' => '17',
        'to_advertiser_id' => '31',
        'fingerprint' => 'prior-fingerprint',
    ),
);
$api = new Broadstreet_Fake_Sponsor_Client();
$api->remote_advertisements['31']['902'] = true;
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$result = $reconciler->reconcile(42);
broadstreet_assert_same('synced', $result['state'], 'A journaled move should recover from the confirmed intended owner.');
broadstreet_assert_same(
    1,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'updateAdvertisement'; })),
    'A recovered move with an older fingerprint should update current title and URL once.'
);
broadstreet_assert_same(
    0,
    count(array_filter($api->calls, function ($call) { return $call[0] === 'createAdvertisement'; })),
    'A recovered move must not create a replacement.'
);

// Successful remote updates are not called synced until both local state writes verify.
foreach (array(Broadstreet_Sponsor_Reconciler::META_REMOTE_ADVERTISER, Broadstreet_Sponsor_Reconciler::META_FINGERPRINT) as $failed_key) {
    broadstreet_reset_sponsor_fixture();
    $broadstreet_meta[42] = array(
        'bs_sponsor_is_sponsored' => '1',
        'bs_sponsor_advertiser_id' => '31',
        'bs_sponsor_advertisement_id' => '906',
        '_bs_sponsor_remote_advertiser_id' => $failed_key === Broadstreet_Sponsor_Reconciler::META_REMOTE_ADVERTISER ? '30' : '31',
    );
    $broadstreet_meta_failures[42][$failed_key] = true;
    $api = new Broadstreet_Fake_Sponsor_Client();
    $reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
    $result = $reconciler->reconcile(42);
    broadstreet_assert_same(false, $result['state'] === 'synced', 'Failed local post-update writes must not report synced.');
}

// Repeating the same public status should not churn post meta or updated_at.
broadstreet_reset_sponsor_fixture();
$api = new Broadstreet_Fake_Sponsor_Client();
$reconciler = new Broadstreet_Sponsor_Reconciler($api, '99');
$first_status = $reconciler->recordStatus(42, 'waiting', 'Select an advertiser.', false);
$second_status = $reconciler->recordStatus(42, 'waiting', 'Select an advertiser.', false);
broadstreet_assert_same($first_status, $second_status, 'Unchanged status should preserve its original timestamp.');
broadstreet_assert_same(1, $broadstreet_meta_update_counts[42][Broadstreet_Sponsor_Reconciler::META_STATUS], 'Unchanged status should not rewrite meta.');

echo "Sponsor reconciler smoke test passed.\n";
