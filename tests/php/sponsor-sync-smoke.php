<?php

/**
 * Behavior proof for the inline sponsor synchronizer: create, update,
 * advertiser moves, explicit 404 recovery, duplicate handling, and the
 * Rewrite & Republish skip.
 */

class Broadstreet_ServerException extends Exception
{
    public $code;

    public function __construct($message, $code)
    {
        parent::__construct($message);
        $this->code = $code;
    }
}

$broadstreet_meta = array();
$broadstreet_transients = array();
$broadstreet_titles = array();
$broadstreet_statuses = array();

function get_post_meta($post_id, $key = '', $single = false)
{
    global $broadstreet_meta;
    return isset($broadstreet_meta[$post_id][$key]) ? $broadstreet_meta[$post_id][$key] : '';
}

function update_post_meta($post_id, $key, $value)
{
    global $broadstreet_meta;
    $broadstreet_meta[$post_id][$key] = $value;
    return true;
}

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
    global $broadstreet_titles;
    return isset($broadstreet_titles[$post_id]) ? $broadstreet_titles[$post_id] : 'Post ' . $post_id;
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

$plugin_root = dirname(dirname(__DIR__));
require_once $plugin_root . '/Broadstreet/SponsorSync.php';

class Broadstreet_Fake_Sync_Client
{
    public $calls = array();
    public $use_tracker_v3 = false;
    public $next_created_id = 900;
    public $create_exception = null;
    public $update_exception = null;

    public function getNetwork($network_id)
    {
        $this->calls[] = array('getNetwork', $network_id);
        return (object) array('use_tracker_v3' => $this->use_tracker_v3);
    }

    public function createAdvertisement($network_id, $advertiser_id, $name, $type, $params)
    {
        $this->calls[] = array('createAdvertisement', $advertiser_id, $name, $type, $params);
        if ($this->create_exception) {
            $exception = $this->create_exception;
            $this->create_exception = null;
            throw $exception;
        }
        return (object) array('id' => $this->next_created_id++);
    }

    public function updateAdvertisement($network_id, $advertiser_id, $advertisement_id, $params)
    {
        $this->calls[] = array('updateAdvertisement', $advertiser_id, $advertisement_id, $params);
        if ($this->update_exception) {
            $exception = $this->update_exception;
            $this->update_exception = null;
            throw $exception;
        }
        return (object) array('id' => $advertisement_id);
    }
}

$client = new Broadstreet_Fake_Sync_Client();
$sync = new Broadstreet_Sponsor_Sync($client, '7');

// 1. A post with sponsorship disabled records a disabled status, no API calls.
$status = $sync->sync(10);
broadstreet_assert_same('disabled', $status['state'], 'Unsponsored posts should report disabled.');
broadstreet_assert_same(array(), $client->calls, 'Unsponsored posts must not reach the API.');

// 2. Sponsored without an advertiser waits for editor input.
$broadstreet_meta[10]['bs_sponsor_is_sponsored'] = '1';
$status = $sync->sync(10);
broadstreet_assert_same('waiting', $status['state'], 'A missing advertiser should report waiting.');
broadstreet_assert_same(array(), $client->calls, 'Waiting posts must not reach the API.');

// 3. First sync with an advertiser creates a tracker and stores its identity.
$broadstreet_meta[10]['bs_sponsor_advertiser_id'] = '50';
$broadstreet_titles[10] = 'Original story';
$status = $sync->sync(10);
broadstreet_assert_same('synced', $status['state'], 'A successful create should report synced.');
broadstreet_assert_same('900', get_post_meta(10, 'bs_sponsor_advertisement_id', true), 'The created tracker ID should be stored.');
broadstreet_assert_same('50', get_post_meta(10, Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER, true), 'The advertiser the tracker lives under should be stamped.');
$create_call = $client->calls[1];
broadstreet_assert_same('createAdvertisement', $create_call[0], 'The first sync should create.');
broadstreet_assert_same('50', $create_call[1], 'The tracker should be created under the selected advertiser.');
broadstreet_assert_same('tracker', $create_call[3], 'Default networks use the classic tracker type.');
broadstreet_assert_same('https://example.test/post-10', $create_call[4]['stencil_inputs']['url'], 'The tracker should point at the post permalink.');
broadstreet_assert_same(false, get_transient(Broadstreet_Sponsor_Sync::CREATE_GUARD_PREFIX . '10'), 'The create guard should be released after success.');

// 4. A later save updates the tracker in place, no advertiser move.
$client->calls = array();
$broadstreet_titles[10] = 'Original story, revised';
$status = $sync->sync(10);
broadstreet_assert_same('synced', $status['state'], 'A successful update should report synced.');
$update_call = $client->calls[1];
broadstreet_assert_same('updateAdvertisement', $update_call[0], 'Later saves should update.');
broadstreet_assert_same('50', $update_call[1], 'The update should be addressed to the stamped advertiser.');
broadstreet_assert_same('900', $update_call[2], 'The update should target the stored tracker.');
broadstreet_assert_same(false, isset($update_call[3]['new_advertiser_id']), 'No advertiser change means no move parameter.');

// 5. Changing the advertiser addresses the old advertiser and moves the tracker.
$client->calls = array();
$broadstreet_meta[10]['bs_sponsor_advertiser_id'] = '60';
$status = $sync->sync(10);
$move_call = $client->calls[1];
broadstreet_assert_same('50', $move_call[1], 'A move must be addressed to the advertiser that owns the tracker.');
broadstreet_assert_same('60', $move_call[3]['new_advertiser_id'], 'A move should carry the new advertiser.');
broadstreet_assert_same('60', get_post_meta(10, Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER, true), 'A successful move should update the stamp.');

// 6. A v3 network uses the analytics tracker type.
$client->calls = array();
$client->use_tracker_v3 = true;
$sync->sync(10);
broadstreet_assert_same('analytics_tracker', $client->calls[1][3]['type'], 'v3 networks should update with the analytics tracker type.');
$client->use_tracker_v3 = false;

// 7. A 404 on update reports a retryable error and leaves meta untouched.
$client->calls = array();
$client->update_exception = new Broadstreet_ServerException('missing', 404);
$status = $sync->sync(10);
broadstreet_assert_same('error', $status['state'], 'A missing tracker should report an error.');
broadstreet_assert_true($status['retryable'], 'A missing tracker should offer an explicit retry.');
broadstreet_assert_same('900', get_post_meta(10, 'bs_sponsor_advertisement_id', true), 'An automatic sync must never abandon a tracker on 404.');

// 8. An explicit retry after a 404 replaces the tracker with a fresh one.
$client->calls = array();
$client->update_exception = new Broadstreet_ServerException('missing', 404);
$status = $sync->sync(10, true);
broadstreet_assert_same('synced', $status['state'], 'An explicit retry should recover from a missing tracker.');
broadstreet_assert_same('901', get_post_meta(10, 'bs_sponsor_advertisement_id', true), 'The replacement tracker ID should be stored.');
broadstreet_assert_same('createAdvertisement', $client->calls[2][0], 'The explicit retry should create a replacement tracker.');

// 9. Non-404 API failures report a retryable error without changing meta.
$client->calls = array();
$client->update_exception = new Exception('server down');
$status = $sync->sync(10);
broadstreet_assert_same('error', $status['state'], 'A failed update should report an error.');
broadstreet_assert_true($status['retryable'], 'A failed update should be retryable.');
broadstreet_assert_same('901', get_post_meta(10, 'bs_sponsor_advertisement_id', true), 'A failed update must not change the stored tracker.');

// 10. A 422 on create is a definite rejection, not retryable.
$broadstreet_meta[11] = array(
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '50',
);
$client->create_exception = new Broadstreet_ServerException('invalid', 422);
$status = $sync->sync(11);
broadstreet_assert_same('error', $status['state'], 'A rejected create should report an error.');
broadstreet_assert_same(false, $status['retryable'], 'A rejected create should not advertise a blind retry.');
broadstreet_assert_same('', get_post_meta(11, 'bs_sponsor_advertisement_id', true), 'A rejected create should store no tracker ID.');
broadstreet_assert_same(false, get_transient(Broadstreet_Sponsor_Sync::CREATE_GUARD_PREFIX . '11'), 'The create guard should be released after a failure.');

// 11. An in-flight create guard blocks a concurrent create.
set_transient(Broadstreet_Sponsor_Sync::CREATE_GUARD_PREFIX . '11', 1, 30);
$client->calls = array();
$status = $sync->sync(11);
broadstreet_assert_same('error', $status['state'], 'A guarded create should report an error.');
broadstreet_assert_true($status['retryable'], 'A guarded create should be retryable.');
broadstreet_assert_same(1, count($client->calls), 'Only the network lookup may run while the guard is held.');
delete_transient(Broadstreet_Sponsor_Sync::CREATE_GUARD_PREFIX . '11');

// 12. A Rewrite & Republish draft never syncs itself.
$broadstreet_meta[20] = array(
    '_dp_original' => '10',
    '_dp_is_rewrite_republish_copy' => '1',
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '50',
);
$client->calls = array();
$status = $sync->sync(20);
broadstreet_assert_same('noop', $status['state'], 'Rewrite & Republish drafts should report a noop.');
broadstreet_assert_same(array(), $client->calls, 'Rewrite & Republish drafts must not reach the API.');
broadstreet_assert_same(10, $sync->getRewriteRepublishOriginal(20), 'The draft should expose its original for syncing instead.');

// 13. A plain Yoast duplicate (stale _dp_original, no republish marker) is an
// ordinary post: it creates its own tracker rather than adopting the original's.
$broadstreet_meta[21] = array(
    '_dp_original' => '10',
    'bs_sponsor_is_sponsored' => '1',
    'bs_sponsor_advertiser_id' => '50',
);
$broadstreet_titles[21] = 'Cloned story';
$client->calls = array();
$status = $sync->sync(21);
broadstreet_assert_same('synced', $status['state'], 'A plain duplicate should sync as itself.');
broadstreet_assert_same(0, $sync->getRewriteRepublishOriginal(21), 'A plain duplicate has no republish original.');
broadstreet_assert_same('createAdvertisement', $client->calls[1][0], 'A plain duplicate should create its own tracker.');
broadstreet_assert_same(
    'https://example.test/post-21',
    $client->calls[1][4]['stencil_inputs']['url'],
    'A plain duplicate tracker should point at the duplicate, not the original.'
);

// 14. Legacy string toggles stay honored.
$broadstreet_meta[22] = array(
    'bs_sponsor_is_sponsored' => 'true',
    'bs_sponsor_advertiser_id' => '50',
);
$status = $sync->sync(22);
broadstreet_assert_same('synced', $status['state'], 'Legacy true-string toggles should still synchronize.');

echo "Sponsor sync smoke test passed.\n";
