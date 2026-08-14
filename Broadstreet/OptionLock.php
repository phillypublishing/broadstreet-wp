<?php

/**
 * Atomic, owner-checked option locks shared by sponsor operations.
 *
 * WordPress 6.7 changed add_option() to an upsert. Locks must instead rely on
 * the options table's unique option_name index so exactly one absent-only
 * INSERT can win.
 */
class Broadstreet_Option_Lock
{
    const DEFAULT_TTL = 120;

    /**
     * Acquire an option lock or recover one whose owner exceeded the TTL.
     *
     * @param string $key Option name.
     * @param int    $ttl Stale-owner lifetime in seconds.
     * @return array|false Owner token and creation time, or false on contention.
     */
    public function acquire($key, $ttl = self::DEFAULT_TTL)
    {
        $key = (string) $key;
        $ttl = max(1, (int) $ttl);
        $lock = array(
            'token' => $this->createToken(),
            'created_at' => time(),
        );

        if ($this->insertAbsent($key, $lock)) {
            return $lock;
        }

        $existing = $this->readAuthoritative($key);
        if (!is_array($existing)
            || !isset($existing['created_at'])
            || (int) $existing['created_at'] >= time() - $ttl
            || !$this->compareAndDelete($key, $existing)
            || !$this->insertAbsent($key, $lock)) {
            return false;
        }

        return $lock;
    }

    /**
     * Release only the exact lock token acquired by this owner.
     */
    public function release($key, $lock)
    {
        return $this->compareAndDelete((string) $key, $lock);
    }

    /**
     * Check ownership from the database immediately before a protected side
     * effect. This fences a request that resumes after its stale lock was
     * recovered by another worker.
     */
    public function owns($key, $lock)
    {
        return is_array($lock)
            && $this->readAuthoritative((string) $key) === $lock;
    }

    protected function insertAbsent($key, $value)
    {
        global $wpdb;

        if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'insert')) {
            return false;
        }

        $serialized = maybe_serialize($value);
        $previous_suppression = null;
        if (method_exists($wpdb, 'suppress_errors')) {
            $previous_suppression = $wpdb->suppress_errors(true);
        }

        $inserted = $wpdb->insert(
            $wpdb->options,
            array(
                'option_name' => $key,
                'option_value' => $serialized,
                'autoload' => 'no',
            ),
            array('%s', '%s', '%s')
        );

        if ($previous_suppression !== null) {
            $wpdb->suppress_errors($previous_suppression);
        }

        if (!$inserted) {
            return false;
        }

        $this->cachePresent($key, $serialized);
        return true;
    }

    protected function compareAndDelete($key, $expected)
    {
        global $wpdb;

        if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'delete')) {
            return false;
        }

        $deleted = $wpdb->delete(
            $wpdb->options,
            array(
                'option_name' => $key,
                'option_value' => maybe_serialize($expected),
            ),
            array('%s', '%s')
        );

        if (!$deleted) {
            $this->cacheUnknown($key);
            return false;
        }

        $this->cacheMissing($key);
        return true;
    }

    protected function readAuthoritative($key)
    {
        global $wpdb;

        if (!isset($wpdb)
            || !isset($wpdb->options)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        $serialized = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $key
            )
        );

        return $serialized === null ? false : maybe_unserialize($serialized);
    }

    protected function cachePresent($key, $serialized)
    {
        if (!function_exists('wp_cache_set') || !function_exists('wp_cache_get')) {
            return;
        }

        wp_cache_set($key, $serialized, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$key])) {
            unset($notoptions[$key]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }

    protected function cacheMissing($key)
    {
        if (!function_exists('wp_cache_set') || !function_exists('wp_cache_get')) {
            return;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, 'options');
        }

        $notoptions = wp_cache_get('notoptions', 'options');
        if (!is_array($notoptions)) {
            $notoptions = array();
        }
        $notoptions[$key] = true;
        wp_cache_set('notoptions', $notoptions, 'options');
    }

    protected function cacheUnknown($key)
    {
        if (!function_exists('wp_cache_set') || !function_exists('wp_cache_get')) {
            return;
        }

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, 'options');
        }

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$key])) {
            unset($notoptions[$key]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }

    protected function createToken()
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return hash('sha256', uniqid('', true) . ':' . mt_rand());
    }
}
