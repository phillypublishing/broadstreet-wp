<?php

/**
 * Behavior proof for the broadstreet_sponsor_sync_decision filter, through
 * the real save paths: REST (rest_after_insert), the Classic meta box, and a
 * scheduled publish run by cron. The Broadstreet client is a fake injected
 * through broadstreet_sponsor_client; the synchronizer, controller and Core
 * handlers are the plugin's own.
 */

class WP_Widget
{
}

class WP_Error
{
}

$broadstreet_hooks = array();
$broadstreet_meta = array();
$broadstreet_meta_write_failures = array();
$broadstreet_transients = array();
$broadstreet_statuses = array();

// A small hook registry, so callbacks run the way WordPress runs them.
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $broadstreet_hooks;
    $broadstreet_hooks[$hook][$priority][] = array($callback, $accepted_args);
    ksort($broadstreet_hooks[$hook]);
    return true;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    return add_filter($hook, $callback, $priority, $accepted_args);
}

function apply_filters($hook, $value)
{
    global $broadstreet_hooks;
    $args = func_get_args();
    array_shift($args);
    if (!isset($broadstreet_hooks[$hook])) {
        return $value;
    }

    foreach ($broadstreet_hooks[$hook] as $callbacks) {
        foreach ($callbacks as $entry) {
            $args[0] = $value;
            $value = call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
        }
    }

    return $value;
}

function broadstreet_reset_hooks()
{
    global $broadstreet_hooks;
    $broadstreet_hooks = array();
}

function add_shortcode()
{
}

function get_option($key)
{
    if ($key === 'Broadstreet_API_Key') {
        return 'fake-test-key';
    }

    if ($key === 'Broadstreet_Network_Key') {
        return '7';
    }

    return false;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    global $broadstreet_meta;
    return isset($broadstreet_meta[$post_id][$key]) ? $broadstreet_meta[$post_id][$key] : '';
}

function metadata_exists($meta_type, $post_id, $key)
{
    global $broadstreet_meta;
    return isset($broadstreet_meta[$post_id]) && array_key_exists($key, $broadstreet_meta[$post_id]);
}

// Like WordPress, a write first passes through update_post_metadata, where
// Core stamps the advertiser that owns an existing tracker.
function update_post_meta($post_id, $key, $value)
{
    global $broadstreet_meta, $broadstreet_meta_write_failures;
    $check = apply_filters('update_post_metadata', null, $post_id, $key, $value, '');
    if ($check !== null) {
        return (bool) $check;
    }
    if (isset($broadstreet_meta_write_failures[$post_id][$key])) {
        return false;
    }
    $broadstreet_meta[$post_id][$key] = $value;
    return true;
}

/**
 * A manual write to land at a chosen moment of the default fill: run once,
 * just before the fill's own database write (the conditional UPDATE or the
 * unique insert), as an editor's save racing it would.
 */
$broadstreet_before_meta_write = null;
function broadstreet_run_before_meta_write()
{
    global $broadstreet_before_meta_write;
    if ($broadstreet_before_meta_write !== null) {
        $callback = $broadstreet_before_meta_write;
        $broadstreet_before_meta_write = null;
        $callback();
    }
}

$broadstreet_cache_deletes = 0;
function wp_cache_delete($key, $group = '')
{
    global $broadstreet_cache_deletes;
    $broadstreet_cache_deletes++;
    return true;
}

function do_action($hook)
{
    $args = func_get_args();
    call_user_func_array('apply_filters', $args);
}

// Like WordPress: with $unique, a key that already exists is refused.
function add_post_meta($post_id, $key, $value, $unique = false)
{
    global $broadstreet_meta, $broadstreet_meta_write_failures;
    broadstreet_run_before_meta_write();
    if ($unique && metadata_exists('post', $post_id, $key)) {
        return false;
    }
    if (isset($broadstreet_meta_write_failures[$post_id][$key])) {
        return false;
    }
    $broadstreet_meta[$post_id][$key] = $value;
    do_action('added_post_meta', 1, $post_id, $key, $value);
    return 1;
}

// The two queries the default fill makes, against the same meta store.
class Broadstreet_Test_Decision_DB
{
    public $postmeta = 'wp_postmeta';
    public $queries = array();

    public function prepare($query)
    {
        $args = func_get_args();
        array_shift($args);
        return array($query, $args);
    }

    public function get_var($prepared)
    {
        $this->queries[] = $prepared[0];
        list($post_id, $key) = $prepared[1];
        if (strpos($prepared[0], "SELECT meta_id FROM wp_postmeta WHERE post_id = %d AND meta_key = %s AND meta_value = ''") !== 0) {
            throw new RuntimeException('Unexpected query: ' . $prepared[0]);
        }
        return metadata_exists('post', $post_id, $key) && get_post_meta($post_id, $key, true) === '' ? '1' : null;
    }

    public function query($prepared)
    {
        global $broadstreet_meta, $broadstreet_meta_write_failures;
        $this->queries[] = $prepared[0];
        if ($prepared[0] !== "UPDATE wp_postmeta SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_id = %d AND meta_value = ''") {
            throw new RuntimeException('Unexpected query: ' . $prepared[0]);
        }
        broadstreet_run_before_meta_write();
        list($value, $post_id, $key) = $prepared[1];
        if (isset($broadstreet_meta_write_failures[$post_id][$key])
            || !metadata_exists('post', $post_id, $key)
            || $broadstreet_meta[$post_id][$key] !== '') {
            return 0;
        }
        $broadstreet_meta[$post_id][$key] = $value;
        return 1;
    }
}

$wpdb = new Broadstreet_Test_Decision_DB();

function delete_post_meta($post_id, $key)
{
    global $broadstreet_meta;
    unset($broadstreet_meta[$post_id][$key]);
    return true;
}

function get_transient($key)
{
    global $broadstreet_transients;
    return isset($broadstreet_transients[$key]) ? $broadstreet_transients[$key] : false;
}

function set_transient($key, $value, $ttl = 0)
{
    global $broadstreet_transients;
    $broadstreet_transients[$key] = $value;
    return true;
}

function delete_transient($key)
{
    global $broadstreet_transients;
    unset($broadstreet_transients[$key]);
    return true;
}

function get_the_title($post_id)
{
    return 'Story ' . $post_id;
}

function get_post_status($post_id)
{
    global $broadstreet_statuses;
    return isset($broadstreet_statuses[$post_id]) ? $broadstreet_statuses[$post_id] : 'publish';
}

function get_permalink($post_id)
{
    return 'https://example.test/post-' . $post_id;
}

function get_sample_permalink($post_id)
{
    return array('https://example.test/%postname%/', 'sample-' . $post_id);
}

function wp_is_post_revision($post_id)
{
    return false;
}

function wp_is_post_autosave($post_id)
{
    return false;
}

function current_user_can($capability, $post_id = null)
{
    return $capability === 'edit_post';
}

function plugin_basename($file)
{
    return 'Broadstreet/Core.php';
}

function wp_verify_nonce($nonce, $action)
{
    return $nonce === 'valid-classic-nonce' && $action === 'Broadstreet/Core.php';
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

class Broadstreet_Fake_Decision_Client
{
    public $calls = array();
    public $next_created_id = 900;

    public function getNetwork($network_id)
    {
        $this->calls[] = array('getNetwork', $network_id);
        return (object) array('use_tracker_v3' => false);
    }

    public function createAdvertisement($network_id, $advertiser_id, $name, $type, $params)
    {
        $this->calls[] = array('createAdvertisement', (string) $advertiser_id, $params['post_id']);
        return (object) array('id' => $this->next_created_id++);
    }

    public function updateAdvertisement($network_id, $advertiser_id, $advertisement_id, $params)
    {
        $this->calls[] = array('updateAdvertisement', (string) $advertiser_id, (string) $advertisement_id);
        return (object) array('id' => $advertisement_id);
    }
}

$client = new Broadstreet_Fake_Decision_Client();
$reflection = new ReflectionClass('Broadstreet_Core');
$core = $reflection->newInstanceWithoutConstructor();

/**
 * Hooks for one case: the fake client, Core's own meta capture, and
 * optionally a decision callback that records what it was asked.
 */
$broadstreet_decision_calls = array();
function broadstreet_install_hooks($core, $client, $decide = null)
{
    global $broadstreet_decision_calls;
    broadstreet_reset_hooks();
    $broadstreet_decision_calls = array();
    add_filter('broadstreet_sponsor_client', function () use ($client) {
        return $client;
    });
    add_filter('update_post_metadata', array($core, 'captureSponsorAdvertiserBeforeUpdate'), 10, 5);
    if ($decide !== null) {
        add_filter('broadstreet_sponsor_sync_decision', function ($decision, $post_id) use ($decide) {
            global $broadstreet_decision_calls;
            $broadstreet_decision_calls[] = array($decision, $post_id);
            return $decide($post_id);
        }, 10, 2);
    }
}

/**
 * Save a post through one of the three real paths. Each path reads the
 * post's meta as it stands, like WordPress after the request has written it.
 */
function broadstreet_save($core, $path, $post_id)
{
    global $broadstreet_meta, $broadstreet_statuses;
    $_POST = array();
    if ($path === 'rest') {
        $core->reconcileSponsorRestPost((object) array('ID' => $post_id), null, false);
    } elseif ($path === 'classic') {
        $_POST = array(
            'bs_sponsor_submit' => '1',
            'broadstreetsponsored' => 'valid-classic-nonce',
            'bs_sponsor_is_sponsored' => '1',
            'bs_sponsor_advertiser_id' => (string) get_post_meta($post_id, 'bs_sponsor_advertiser_id', true),
        );
        $core->saveSponsorPostMeta($post_id);
        $_POST = array();
    } elseif ($path === 'cron') {
        // wp_publish_post from cron: no REST request and no Classic form.
        $broadstreet_statuses[$post_id] = 'publish';
        $core->monitorForScheduledPostStatus('publish', 'future', (object) array('ID' => $post_id));
    } else {
        throw new RuntimeException('Unknown path ' . $path);
    }

    return get_post_meta($post_id, Broadstreet_Sponsor_Sync::META_STATUS, true);
}

$next_post_id = 100;
function broadstreet_new_post($meta, $status = 'future')
{
    global $broadstreet_meta, $broadstreet_statuses, $next_post_id;
    $post_id = $next_post_id++;
    $broadstreet_meta[$post_id] = array_merge(array('bs_sponsor_is_sponsored' => '1'), $meta);
    $broadstreet_statuses[$post_id] = $status;
    return $post_id;
}

foreach (array('rest', 'classic', 'cron') as $path) {
    $label = '[' . $path . '] ';

    // 1. Nothing hooked: a staff post with an advertiser gets its tracker on
    // first save, exactly as before.
    broadstreet_install_hooks($core, $client);
    $client->calls = array();
    $post_id = broadstreet_new_post(array('bs_sponsor_advertiser_id' => '50'));
    $status = broadstreet_save($core, $path, $post_id);
    broadstreet_assert_same('synced', $status['state'], $label . 'With nothing hooked, a staff post should sync.');
    broadstreet_assert_same(
        array(array('getNetwork', '7'), array('createAdvertisement', '50', $post_id)),
        $client->calls,
        $label . 'With nothing hooked, the tracker should be created under the selected advertiser.'
    );
    broadstreet_assert_same('900', (string) get_post_meta($post_id, 'bs_sponsor_advertisement_id', true), $label . 'The tracker ID should be stored.');
    $client->next_created_id = 900;

    // 2. Nothing hooked, no advertiser: waiting, as before.
    $post_id = broadstreet_new_post(array());
    $client->calls = array();
    $status = broadstreet_save($core, $path, $post_id);
    broadstreet_assert_same('waiting', $status['state'], $label . 'With nothing hooked, a post without an advertiser should wait.');
    broadstreet_assert_same('Select an advertiser, then save the post to enable Broadstreet tracking.', $status['message'], $label . 'The waiting message should be unchanged.');
    broadstreet_assert_same(array(), $client->calls, $label . 'A waiting post must not reach the API.');

    // 3. A callback that answers null for staff posts and holds app posts:
    // the staff post is unaffected, the app post is held, in one request.
    $decide = function ($post_id) {
        return get_post_meta($post_id, 'lebtown_advertise_submission_id', true) !== ''
            ? array('hold' => 'Waiting for <b>approval</b>.')
            : null;
    };
    broadstreet_install_hooks($core, $client, $decide);
    $staff_id = broadstreet_new_post(array('bs_sponsor_advertiser_id' => '50'));
    $app_id = broadstreet_new_post(array(
        'bs_sponsor_advertiser_id' => '51',
        'lebtown_advertise_submission_id' => 'sub_1',
    ));
    $client->calls = array();
    $status = broadstreet_save($core, $path, $staff_id);
    broadstreet_assert_same('synced', $status['state'], $label . 'A null answer should leave a staff post syncing.');
    broadstreet_assert_same(
        array(array('getNetwork', '7'), array('createAdvertisement', '50', $staff_id)),
        $client->calls,
        $label . 'A null answer should create the tracker as before.'
    );
    broadstreet_assert_same(array(array(null, $staff_id)), $broadstreet_decision_calls, $label . 'The filter should get null and the integer post ID.');

    // 4. Hold: status waiting with the plugin's message, no HTTP at all,
    // even with an advertiser picked by hand.
    $client->calls = array();
    $status = broadstreet_save($core, $path, $app_id);
    broadstreet_assert_same('waiting', $status['state'], $label . 'A hold should record waiting.');
    broadstreet_assert_same('Waiting for approval.', $status['message'], $label . 'A hold should show its message as plain text.');
    broadstreet_assert_same(false, $status['retryable'], $label . 'A hold is not retryable.');
    broadstreet_assert_same(array(), $client->calls, $label . 'A held post must not reach the API.');
    broadstreet_assert_same('', get_post_meta($app_id, 'bs_sponsor_advertisement_id', true), $label . 'A held post must get no tracker.');
    broadstreet_assert_same('51', get_post_meta($app_id, 'bs_sponsor_advertiser_id', true), $label . 'A hold must leave the advertiser alone.');

    // 5. Hold on a post that already has a tracker: no update either.
    $broadstreet_meta[$app_id]['bs_sponsor_advertisement_id'] = '555';
    $broadstreet_meta[$app_id]['bs_sponsor_advertiser_id'] = '52';
    $client->calls = array();
    $status = broadstreet_save($core, $path, $app_id);
    broadstreet_assert_same('waiting', $status['state'], $label . 'A hold should apply to a post with a tracker.');
    broadstreet_assert_same(array(), $client->calls, $label . 'A hold must not update an existing tracker.');
    broadstreet_assert_same('555', get_post_meta($app_id, 'bs_sponsor_advertisement_id', true), $label . 'A hold must keep the existing tracker ID.');

    // 6. Default advertiser on a post with none: written, then synced.
    broadstreet_install_hooks($core, $client, function ($post_id) {
        return array('advertiser_id' => '77', 'source' => 'default');
    });
    $post_id = broadstreet_new_post(array('lebtown_advertise_submission_id' => 'sub_2'));
    $client->calls = array();
    $status = broadstreet_save($core, $path, $post_id);
    broadstreet_assert_same('synced', $status['state'], $label . 'A default advertiser should let the post sync.');
    broadstreet_assert_same('77', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), $label . 'The default advertiser should be stored.');
    broadstreet_assert_same(
        array(array('getNetwork', '7'), array('createAdvertisement', '77', $post_id)),
        $client->calls,
        $label . 'The tracker should be created under the default advertiser.'
    );
    broadstreet_assert_same('77', get_post_meta($post_id, Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER, true), $label . 'The tracker should be stamped with the default advertiser.');

    // 7. The next save with the same answer changes nothing and costs no HTTP.
    $client->calls = array();
    $status = broadstreet_save($core, $path, $post_id);
    broadstreet_assert_same('synced', $status['state'], $label . 'A repeat default should stay synced.');
    broadstreet_assert_same(array(), $client->calls, $label . 'A repeat default with nothing changed must not reach the API.');
    $client->next_created_id = 900;

    // 8. Default on a post with an advertiser picked by hand: the editor's
    // choice wins.
    $post_id = broadstreet_new_post(array(
        'bs_sponsor_advertiser_id' => '60',
        'lebtown_advertise_submission_id' => 'sub_3',
    ));
    $client->calls = array();
    $status = broadstreet_save($core, $path, $post_id);
    broadstreet_assert_same('synced', $status['state'], $label . 'A default should not block a hand-picked advertiser.');
    broadstreet_assert_same('60', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), $label . 'A default must not replace a hand-picked advertiser.');
    broadstreet_assert_same(
        array(array('getNetwork', '7'), array('createAdvertisement', '60', $post_id)),
        $client->calls,
        $label . 'The tracker should be created under the hand-picked advertiser.'
    );
    $client->next_created_id = 900;

    // 9. A malformed answer fails closed: error, no HTTP, nothing written.
    broadstreet_install_hooks($core, $client, function ($post_id) {
        return false;
    });
    $post_id = broadstreet_new_post(array('bs_sponsor_advertiser_id' => '50'));
    $client->calls = array();
    $status = broadstreet_save($core, $path, $post_id);
    broadstreet_assert_same('error', $status['state'], $label . 'A malformed answer should record an error.');
    broadstreet_assert_same(false, $status['retryable'], $label . 'A malformed answer is not retryable from the editor.');
    broadstreet_assert_same(array(), $client->calls, $label . 'A malformed answer must not reach the API.');
    broadstreet_assert_same('', get_post_meta($post_id, 'bs_sponsor_advertisement_id', true), $label . 'A malformed answer must create no tracker.');
}

// The rest of the contract, on the synchronizer directly.
broadstreet_install_hooks($core, $client);
$sync = $core->getSponsorSync();
$answer = null;
add_filter('broadstreet_sponsor_sync_decision', function ($decision, $post_id) use (&$answer) {
    return $answer;
}, 10, 2);

// Hold wins over an advertiser in the same answer.
$post_id = broadstreet_new_post(array());
$answer = array('hold' => 'Not yet.', 'advertiser_id' => '77', 'source' => 'default');
$client->calls = array();
$status = $sync->sync($post_id);
broadstreet_assert_same(array('waiting', 'Not yet.'), array($status['state'], $status['message']), 'Hold should win over an advertiser.');
broadstreet_assert_same('', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), 'A hold must not write the advertiser.');
broadstreet_assert_same(array(), $client->calls, 'A hold must not reach the API.');

// Hold applies to explicit retries too.
$broadstreet_meta[$post_id]['bs_sponsor_advertiser_id'] = '50';
$status = $sync->sync($post_id, true);
broadstreet_assert_same('waiting', $status['state'], 'A hold should apply to explicit retries.');
broadstreet_assert_same(array(), $client->calls, 'A held retry must not reach the API.');

// An empty or non-string hold message gets the generic one; long ones are cut.
foreach (array('', "  \n ", true, array('x')) as $message) {
    $answer = array('hold' => $message);
    $status = $sync->sync($post_id);
    broadstreet_assert_same('Broadstreet tracking is on hold for this post.', $status['message'], 'A hold without a usable message should use the generic one.');
}
$answer = array('hold' => str_repeat('a', 600));
$status = $sync->sync($post_id);
broadstreet_assert_same(500, strlen($status['message']), 'Hold messages should be capped at 500 characters.');

// Integer advertiser IDs are accepted and stored as strings.
$post_id = broadstreet_new_post(array());
$answer = array('advertiser_id' => 78, 'source' => 'default');
$status = $sync->sync($post_id);
broadstreet_assert_same('synced', $status['state'], 'An integer advertiser ID should be accepted.');
broadstreet_assert_same('78', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), 'An integer advertiser ID should be stored as a string.');

// Malformed answers all fail closed.
$malformed = array(
    'false' => false,
    'string' => 'hold',
    'empty array' => array(),
    'no source' => array('advertiser_id' => '77'),
    'other source' => array('advertiser_id' => '77', 'source' => 'override'),
    'zero id' => array('advertiser_id' => '0', 'source' => 'default'),
    'non-numeric id' => array('advertiser_id' => '77abc', 'source' => 'default'),
    'float id' => array('advertiser_id' => 77.0, 'source' => 'default'),
    'null id' => array('advertiser_id' => null, 'source' => 'default'),
);
foreach ($malformed as $name => $value) {
    $post_id = broadstreet_new_post(array());
    $answer = $value;
    $client->calls = array();
    $status = $sync->sync($post_id);
    broadstreet_assert_same('error', $status['state'], 'A malformed answer (' . $name . ') should record an error.');
    broadstreet_assert_same(array(), $client->calls, 'A malformed answer (' . $name . ') must not reach the API.');
    broadstreet_assert_same('', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), 'A malformed answer (' . $name . ') must not write an advertiser.');
}

// A default advertiser that cannot be stored is a retryable error, no HTTP.
$post_id = broadstreet_new_post(array());
$broadstreet_meta_write_failures[$post_id]['bs_sponsor_advertiser_id'] = true;
$answer = array('advertiser_id' => '77', 'source' => 'default');
$client->calls = array();
$status = $sync->sync($post_id);
broadstreet_assert_same(array('error', true), array($status['state'], $status['retryable']), 'An unstored default advertiser should be a retryable error.');
broadstreet_assert_same(array(), $client->calls, 'An unstored default advertiser must not reach the API.');

// The default fill decides at the write: an editor's pick that lands while
// it runs wins, and the post syncs with it. Each case: where the row starts
// (absent, or present and empty) and when the editor's 60 lands.
$answer = array('advertiser_id' => '77', 'source' => 'default');
$editor_picks = function ($post_id) {
    return function () use ($post_id) {
        global $broadstreet_meta;
        $broadstreet_meta[$post_id]['bs_sponsor_advertiser_id'] = '60';
    };
};
foreach (array('absent', 'empty') as $start) {
    foreach (array('before the write', 'before the read-back') as $moment) {
        $label = 'Default fill, row ' . $start . ', editor picks ' . $moment . ': ';
        $post_id = broadstreet_new_post($start === 'empty' ? array('bs_sponsor_advertiser_id' => '') : array());
        $read_back = $editor_picks($post_id);
        if ($moment === 'before the write') {
            $broadstreet_before_meta_write = $read_back;
        } else {
            // Right after the fill's write lands, before it reads back.
            add_action($start === 'empty' ? 'updated_post_meta' : 'added_post_meta', function () use (&$read_back) {
                if ($read_back !== null) {
                    $callback = $read_back;
                    $read_back = null;
                    $callback();
                }
            });
        }
        $client->calls = array();
        $status = $sync->sync($post_id);
        broadstreet_assert_same(null, $moment === 'before the write' ? $broadstreet_before_meta_write : $read_back, $label . 'the race should have run.');
        broadstreet_assert_same('synced', $status['state'], $label . 'the post should sync.');
        broadstreet_assert_same('60', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), $label . 'the editor\'s advertiser must be kept.');
        broadstreet_assert_same(
            array(array('getNetwork', '7'), array('createAdvertisement', '60', $post_id)),
            $client->calls,
            $label . 'the tracker should be created under the editor\'s advertiser.'
        );
        broadstreet_assert_same('60', get_post_meta($post_id, Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER, true), $label . 'the tracker should be stamped with the editor\'s advertiser.');
    }
}
broadstreet_install_hooks($core, $client);
$sync = $core->getSponsorSync();
add_filter('broadstreet_sponsor_sync_decision', function ($decision, $post_id) use (&$answer) {
    return $answer;
}, 10, 2);

// With no race, an empty row is filled by the conditional UPDATE, announced
// like update_metadata() does, and read back from a cleared cache.
$post_id = broadstreet_new_post(array('bs_sponsor_advertiser_id' => ''));
$announced = array();
add_action('updated_post_meta', function ($meta_id, $object_id, $key, $value) use (&$announced) {
    $announced[] = array($object_id, $key, $value);
}, 10, 4);
$wpdb->queries = array();
$broadstreet_cache_deletes = 0;
$status = $sync->sync($post_id);
broadstreet_assert_same('synced', $status['state'], 'An empty row should take the default.');
broadstreet_assert_same('77', get_post_meta($post_id, 'bs_sponsor_advertiser_id', true), 'The default should fill the empty row.');
broadstreet_assert_same(array(array($post_id, 'bs_sponsor_advertiser_id', '77')), $announced, 'The conditional UPDATE should fire updated_post_meta.');
broadstreet_assert_same(2, count($wpdb->queries), 'An empty row should be filled with one SELECT and one conditional UPDATE.');
broadstreet_assert_same(3, $broadstreet_cache_deletes, 'The meta cache should be cleared before the check, after the write and before the read-back.');

// An empty row whose conditional UPDATE fails is a retryable error, no HTTP.
$post_id = broadstreet_new_post(array('bs_sponsor_advertiser_id' => ''));
$broadstreet_meta_write_failures[$post_id]['bs_sponsor_advertiser_id'] = true;
$client->calls = array();
$status = $sync->sync($post_id);
broadstreet_assert_same(array('error', true), array($status['state'], $status['retryable']), 'An unfilled empty row should be a retryable error.');
broadstreet_assert_same(array(), $client->calls, 'An unfilled empty row must not reach the API.');

// Hold, then release: the post stays waiting until something syncs it, and
// then syncs, whether the filter answers null or a default.
foreach (array('null' => null, 'default' => array('advertiser_id' => '77', 'source' => 'default')) as $name => $release) {
    foreach (array('explicit retry', 'programmatic sync') as $how) {
        $label = 'Hold released to ' . $name . ', then ' . $how . ': ';
        $post_id = broadstreet_new_post($name === 'null' ? array('bs_sponsor_advertiser_id' => '50') : array());
        $answer = array('hold' => 'Waiting for approval.');
        $client->calls = array();
        broadstreet_assert_same('waiting', $sync->sync($post_id)['state'], $label . 'the hold should apply first.');
        $answer = $release;
        $status = get_post_meta($post_id, Broadstreet_Sponsor_Sync::META_STATUS, true);
        broadstreet_assert_same(array('waiting', false), array($status['state'], $status['retryable']), $label . 'a released hold stays waiting until something syncs.');
        broadstreet_assert_same(array(), $client->calls, $label . 'releasing a hold does not reach the API by itself.');
        $status = $how === 'explicit retry' ? $sync->sync($post_id, true) : $core->syncSponsorPost($post_id);
        $advertiser_id = $name === 'null' ? '50' : '77';
        broadstreet_assert_same('synced', $status['state'], $label . 'the post should sync.');
        broadstreet_assert_same(
            array(array('getNetwork', '7'), array('createAdvertisement', $advertiser_id, $post_id)),
            $client->calls,
            $label . 'the tracker should be created.'
        );
    }
}

// A malformed answer recovers the same way once the callback is fixed.
$post_id = broadstreet_new_post(array('bs_sponsor_advertiser_id' => '50'));
$answer = array('advertiser_id' => '77');
broadstreet_assert_same('error', $sync->sync($post_id)['state'], 'A malformed answer should record an error.');
$answer = null;
broadstreet_assert_same('synced', $core->syncSponsorPost($post_id)['state'], 'A fixed callback and a sync should recover the post.');

// Disabled posts and Rewrite & Republish drafts never ask.
$asked = 0;
add_filter('broadstreet_sponsor_sync_decision', function ($decision) use (&$asked) {
    $asked++;
    return $decision;
}, 5);
$post_id = broadstreet_new_post(array('bs_sponsor_is_sponsored' => false));
broadstreet_assert_same('disabled', $sync->sync($post_id)['state'], 'A post with tracking off should report disabled.');
$post_id = broadstreet_new_post(array('_dp_original' => '100', '_dp_is_rewrite_republish_copy' => '1'));
broadstreet_assert_same('noop', $sync->sync($post_id)['state'], 'A Rewrite & Republish draft should report a noop.');
broadstreet_assert_same(0, $asked, 'Disabled posts and Rewrite & Republish drafts must not ask for a decision.');

echo "Sponsor sync decision smoke test passed.\n";
