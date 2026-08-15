<?php

/**
 * Focused concurrency proof for Broadstreet's shared option-lock primitive.
 */

$broadstreet_lock_rows = array();
$broadstreet_lock_cache = array(
    'options' => array(
        'notoptions' => array(),
    ),
);
$broadstreet_insert_observer = null;

class Broadstreet_Test_Option_DB
{
    public $options = 'wp_options';
    public $insert_calls = 0;
    public $delete_calls = 0;

    public function insert($table, $data, $format = null)
    {
        global $broadstreet_lock_rows, $broadstreet_insert_observer;

        ++$this->insert_calls;
        $key = $data['option_name'];
        if (isset($broadstreet_lock_rows[$key])) {
            return false;
        }

        $broadstreet_lock_rows[$key] = $data['option_value'];
        if (is_callable($broadstreet_insert_observer)) {
            $observer = $broadstreet_insert_observer;
            $broadstreet_insert_observer = null;
            call_user_func($observer, $key);
        }

        return 1;
    }

    public function delete($table, $where, $where_format = null)
    {
        global $broadstreet_lock_rows;

        ++$this->delete_calls;
        $key = $where['option_name'];
        if (!isset($broadstreet_lock_rows[$key])
            || $broadstreet_lock_rows[$key] !== $where['option_value']) {
            return false;
        }

        unset($broadstreet_lock_rows[$key]);
        return 1;
    }

    public function prepare($query, $value)
    {
        return array($query, $value);
    }

    public function get_var($prepared)
    {
        global $broadstreet_lock_rows;

        $key = $prepared[1];
        return isset($broadstreet_lock_rows[$key]) ? $broadstreet_lock_rows[$key] : null;
    }

    public function suppress_errors($suppress = true)
    {
        return false;
    }
}

$wpdb = new Broadstreet_Test_Option_DB();

function maybe_serialize($value)
{
    return serialize($value);
}

function maybe_unserialize($value)
{
    return unserialize($value);
}

function get_option($key, $default = false)
{
    global $broadstreet_lock_rows, $broadstreet_lock_cache;

    if (isset($broadstreet_lock_cache['options']['notoptions'][$key])) {
        return $default;
    }

    if (isset($broadstreet_lock_cache['options'][$key])) {
        return maybe_unserialize($broadstreet_lock_cache['options'][$key]);
    }

    return isset($broadstreet_lock_rows[$key])
        ? maybe_unserialize($broadstreet_lock_rows[$key])
        : $default;
}

function wp_cache_get($key, $group = '')
{
    global $broadstreet_lock_cache;

    return isset($broadstreet_lock_cache[$group][$key])
        ? $broadstreet_lock_cache[$group][$key]
        : false;
}

function wp_cache_set($key, $value, $group = '')
{
    global $broadstreet_lock_cache;

    $broadstreet_lock_cache[$group][$key] = $value;
    return true;
}

function wp_cache_delete($key, $group = '')
{
    global $broadstreet_lock_cache;

    unset($broadstreet_lock_cache[$group][$key]);
    return true;
}

function wp_installing()
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
require_once $plugin_root . '/Broadstreet/OptionLock.php';

$locks = new Broadstreet_Option_Lock();
$key = '_broadstreet_lock_test';

// The database uniqueness constraint, not a cached pre-check, chooses one winner.
$contender = null;
$broadstreet_lock_cache['options']['notoptions'][$key] = true;
$broadstreet_insert_observer = function ($inserted_key) use ($locks, &$contender) {
    $contender = $locks->acquire($inserted_key);
};
$first = $locks->acquire($key);
broadstreet_assert_same(true, is_array($first), 'The first simultaneous contender should acquire the lock.');
broadstreet_assert_same(false, $contender, 'The second simultaneous contender must lose the atomic insert.');
broadstreet_assert_same(true, $locks->owns($key, $first), 'The winner should pass the authoritative ownership fence.');
broadstreet_assert_same(false, isset($broadstreet_lock_cache['options']['notoptions'][$key]), 'Acquisition must invalidate the notoptions entry.');
broadstreet_assert_same(maybe_serialize($first), $broadstreet_lock_cache['options'][$key], 'Acquisition must refresh the individual option cache.');

// Releasing and reacquiring in one request must not be poisoned by notoptions.
broadstreet_assert_same(true, $locks->release($key, $first), 'The owner should release its lock.');
broadstreet_assert_same(false, $locks->owns($key, $first), 'A released owner must fail the authoritative ownership fence.');
broadstreet_assert_same(true, isset($broadstreet_lock_cache['options']['notoptions'][$key]), 'Release must refresh notoptions.');
$second = $locks->acquire($key);
broadstreet_assert_same(true, is_array($second), 'The same request should reacquire after release.');
broadstreet_assert_same(false, isset($broadstreet_lock_cache['options']['notoptions'][$key]), 'Reacquisition must clear the stale notoptions entry again.');

// A lock older than the shared TTL can be taken over.
$stale_key = '_broadstreet_stale_lock';
$old_owner = array('token' => 'old-owner', 'created_at' => time() - Broadstreet_Option_Lock::DEFAULT_TTL - 1);
$broadstreet_lock_rows[$stale_key] = maybe_serialize($old_owner);
$broadstreet_lock_cache['options'][$stale_key] = maybe_serialize(array(
    'token' => 'poisoned-cache-owner',
    'created_at' => time(),
));
$new_owner = $locks->acquire($stale_key);
broadstreet_assert_same(true, is_array($new_owner), 'A stale database lock should be recoverable despite a poisoned fresh cache entry.');
broadstreet_assert_same(false, $new_owner['token'] === $old_owner['token'], 'Stale takeover must mint a new owner token.');

// A delayed finally block from the old owner cannot release the replacement.
broadstreet_assert_same(false, $locks->release($stale_key, $old_owner), 'A late old-owner release must fail its token comparison.');
broadstreet_assert_same($new_owner, get_option($stale_key), "The new owner's lock must survive a late old-owner release.");

$broadstreet_lock_cache['options'][$stale_key] = maybe_serialize($old_owner);
$broadstreet_lock_cache['options']['notoptions'][$stale_key] = true;
broadstreet_assert_same(false, $locks->release($stale_key, $old_owner), 'A zero-row compare-and-delete should remain a failed release.');
broadstreet_assert_same(false, isset($broadstreet_lock_cache['options'][$stale_key]), 'A failed CAS should clear a poisoned individual cache entry.');
broadstreet_assert_same(false, isset($broadstreet_lock_cache['options']['notoptions'][$stale_key]), 'A failed CAS should clear a poisoned notoptions entry so the database can self-heal the cache.');

broadstreet_assert_same(true, Broadstreet_Option_Lock::DEFAULT_TTL > 10, 'The lock TTL must exceed the vendor HTTP timeout.');

echo "Sponsor option lock smoke test passed.\n";
