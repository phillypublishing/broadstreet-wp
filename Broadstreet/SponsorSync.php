<?php

/**
 * Synchronizes editor-owned sponsorship meta with Broadstreet, inline.
 *
 * The flow mirrors the reference plugin: when a post is sponsored and has an
 * advertiser, a tracker advertisement is created once and updated on later
 * saves. Two rules keep duplicated posts safe without ownership bookkeeping:
 *
 * - Yoast Duplicate Post copies never inherit tracker state, because
 *   Broadstreet_Core excludes the server-owned meta keys from duplication.
 *   A plain duplicate therefore creates its own tracker on first save.
 * - A Rewrite & Republish draft is never synchronized itself. The original
 *   post owns the tracker, and saving the draft re-synchronizes the original.
 */
class Broadstreet_Sponsor_Sync
{
    const META_STATUS = '_bs_sponsor_reconciliation_status';
    const META_REMOTE_ADVERTISER = '_bs_sponsor_remote_advertiser_id';
    const CREATE_GUARD_PREFIX = 'bs_sponsor_creating_';
    const CREATE_GUARD_TTL = 30;

    protected $client;
    protected $network_id;

    public function __construct($client, $network_id)
    {
        $this->client = $client;
        $this->network_id = $network_id;
    }

    /**
     * Synchronize one post's tracker with Broadstreet.
     *
     * @param int  $post_id  Post ID.
     * @param bool $explicit Whether a user explicitly requested a retry. An
     *                       explicit retry may replace a tracker Broadstreet
     *                       reports missing (404) with a new one.
     * @return array Public status: state, message, retryable, updated_at.
     */
    public function sync($post_id, $explicit = false)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return $this->status('error', 'Broadstreet could not identify the post to synchronize.', false);
        }

        if ($this->isRewriteRepublishCopy($post_id)) {
            return $this->recordStatus(
                $post_id,
                'noop',
                'This Rewrite & Republish draft shares the original post\'s Broadstreet tracker. The original synchronizes when the draft is saved or republished.',
                false
            );
        }

        if (!$this->isEnabled(get_post_meta($post_id, 'bs_sponsor_is_sponsored', true))) {
            return $this->recordStatus(
                $post_id,
                'disabled',
                'Broadstreet tracking is disabled for this post.',
                false
            );
        }

        $advertiser_id = (string) get_post_meta($post_id, 'bs_sponsor_advertiser_id', true);
        if (!$this->isPositiveId($advertiser_id)) {
            return $this->recordStatus(
                $post_id,
                'waiting',
                'Select an advertiser, then save the post to enable Broadstreet tracking.',
                false
            );
        }

        $type = $this->getTrackerType($post_id);
        if ($type === false) {
            return $this->getStatus($post_id);
        }

        $title = substr(str_pad((string) get_the_title($post_id), 5, '*'), 0, 127);
        $url = $this->getTrackerUrl($post_id);
        $advertisement_id = (string) get_post_meta($post_id, 'bs_sponsor_advertisement_id', true);

        if (!$this->isPositiveId($advertisement_id)) {
            return $this->createTracker($post_id, $advertiser_id, $title, $url, $type);
        }

        return $this->updateTracker($post_id, $advertiser_id, $advertisement_id, $title, $url, $type, $explicit);
    }

    /**
     * Return the stored public status for the editor and Classic UI.
     */
    public function getStatus($post_id)
    {
        $status = get_post_meta((int) $post_id, self::META_STATUS, true);
        if (!is_array($status)) {
            return $this->status('idle', '', false, 0);
        }

        return $this->status(
            isset($status['state']) ? $status['state'] : 'idle',
            isset($status['message']) ? $status['message'] : '',
            !empty($status['retryable']),
            isset($status['updated_at']) ? (int) $status['updated_at'] : 0
        );
    }

    /**
     * Store a credential-free status. Callers must pass fixed plugin messages,
     * never exception text or vendor response bodies.
     */
    public function recordStatus($post_id, $state, $message, $retryable)
    {
        $status = $this->status($state, $message, $retryable);
        $current = get_post_meta((int) $post_id, self::META_STATUS, true);
        if (is_array($current)
            && isset($current['state'], $current['message'], $current['retryable'])
            && (string) $current['state'] === $status['state']
            && (string) $current['message'] === $status['message']
            && (bool) $current['retryable'] === $status['retryable']) {
            return $this->status(
                $current['state'],
                $current['message'],
                $current['retryable'],
                isset($current['updated_at']) ? (int) $current['updated_at'] : 0
            );
        }

        update_post_meta((int) $post_id, self::META_STATUS, $status);
        return $status;
    }

    /**
     * Only a copy created by Yoast's Rewrite & Republish shares its original's
     * tracker. Plain duplicates also carry _dp_original, permanently, so that
     * key alone must never mark a post as a republish draft.
     */
    public function isRewriteRepublishCopy($post_id)
    {
        return (string) get_post_meta($post_id, '_dp_is_rewrite_republish_copy', true) === '1'
            && $this->isPositiveId(get_post_meta($post_id, '_dp_original', true));
    }

    /**
     * The original post behind a Rewrite & Republish draft, or 0.
     */
    public function getRewriteRepublishOriginal($post_id)
    {
        if (!$this->isRewriteRepublishCopy($post_id)) {
            return 0;
        }

        return (int) get_post_meta($post_id, '_dp_original', true);
    }

    protected function createTracker($post_id, $advertiser_id, $title, $url, $type)
    {
        $guard_key = self::CREATE_GUARD_PREFIX . $post_id;
        if (get_transient($guard_key)) {
            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet tracker creation is already in progress. Retry synchronization in a moment.',
                true
            );
        }
        set_transient($guard_key, 1, self::CREATE_GUARD_TTL);

        try {
            $advertisement = $this->client->createAdvertisement(
                $this->network_id,
                $advertiser_id,
                $title,
                $type,
                array(
                    'stencil_inputs' => array('url' => $url),
                    'post_id' => $post_id,
                )
            );
        } catch (Exception $exception) {
            delete_transient($guard_key);
            if ($this->isValidationFailure($exception)) {
                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet rejected the tracker details. Correct the post or advertiser, then save again.',
                    false
                );
            }

            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not create the tracker. Save or retry to try again.',
                true
            );
        }

        delete_transient($guard_key);

        $advertisement_id = isset($advertisement->id) ? (string) $advertisement->id : '';
        if (!$this->isPositiveId($advertisement_id)) {
            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet did not return a tracker ID. Check the Broadstreet dashboard before retrying.',
                false
            );
        }

        update_post_meta($post_id, 'bs_sponsor_advertisement_id', $advertisement_id);
        update_post_meta($post_id, self::META_REMOTE_ADVERTISER, $advertiser_id);

        return $this->recordStatus(
            $post_id,
            'synced',
            'Broadstreet tracking is synchronized.',
            false
        );
    }

    protected function updateTracker($post_id, $advertiser_id, $advertisement_id, $title, $url, $type, $explicit)
    {
        // The tracker lives under the advertiser it was created (or last
        // moved) under; updating through a different advertiser 404s. When the
        // editor changes advertisers, the update is addressed to the old one
        // with new_advertiser_id to move the tracker.
        $remote_advertiser_id = (string) get_post_meta($post_id, self::META_REMOTE_ADVERTISER, true);
        if (!$this->isPositiveId($remote_advertiser_id)) {
            $remote_advertiser_id = $advertiser_id;
        }

        $params = array(
            'name' => $title,
            'stencil_inputs' => array('url' => $url),
            'type' => $type,
        );
        if ($remote_advertiser_id !== $advertiser_id) {
            $params['new_advertiser_id'] = $advertiser_id;
        }

        try {
            $this->client->updateAdvertisement(
                $this->network_id,
                $remote_advertiser_id,
                $advertisement_id,
                $params
            );
        } catch (Broadstreet_ServerException $exception) {
            if ((int) $exception->code === 404) {
                if ($explicit) {
                    // The user asked for this recovery: abandon the missing
                    // tracker and create a fresh one. Never done automatically
                    // so a transient API problem cannot fork trackers.
                    delete_post_meta($post_id, 'bs_sponsor_advertisement_id');
                    delete_post_meta($post_id, self::META_REMOTE_ADVERTISER);
                    return $this->createTracker($post_id, $advertiser_id, $title, $url, $type);
                }

                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet could not find this post\'s tracker; it may have been deleted or moved in Broadstreet. Retry synchronization to create a new tracker.',
                    true
                );
            }

            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not update the tracker. Save or retry to try again.',
                true
            );
        } catch (Exception $exception) {
            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not update the tracker. Save or retry to try again.',
                true
            );
        }

        update_post_meta($post_id, self::META_REMOTE_ADVERTISER, $advertiser_id);

        return $this->recordStatus(
            $post_id,
            'synced',
            'Broadstreet tracking is synchronized.',
            false
        );
    }

    protected function getTrackerType($post_id)
    {
        try {
            $network = $this->client->getNetwork($this->network_id);
            return !empty($network->use_tracker_v3) ? 'analytics_tracker' : 'tracker';
        } catch (Exception $exception) {
            $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not read the network settings. Save or retry to try again.',
                true
            );
            return false;
        }
    }

    /**
     * Unpublished posts have no public permalink yet, so simulate the
     * published one; get_sample_permalink is admin-only and must be loaded
     * explicitly under REST or cron.
     */
    protected function getTrackerUrl($post_id)
    {
        if (!in_array(get_post_status($post_id), array('draft', 'future', 'pending'), true)) {
            return (string) get_permalink($post_id);
        }

        if (!function_exists('get_sample_permalink') && defined('ABSPATH')) {
            $admin_post_file = ABSPATH . 'wp-admin/includes/post.php';
            if (file_exists($admin_post_file)) {
                require_once $admin_post_file;
            }
        }

        if (function_exists('get_sample_permalink')) {
            $sample = get_sample_permalink($post_id);
            return (string) preg_replace('/\%postname\%/', $sample[1], $sample[0]);
        }

        return (string) get_permalink($post_id);
    }

    protected function status($state, $message, $retryable, $updated_at = null)
    {
        return array(
            'state' => (string) $state,
            'message' => (string) $message,
            'retryable' => (bool) $retryable,
            'updated_at' => $updated_at === null ? time() : (int) $updated_at,
        );
    }

    protected function isValidationFailure($exception)
    {
        return $exception instanceof Broadstreet_ServerException && (int) $exception->code === 422;
    }

    protected function isEnabled($value)
    {
        return in_array($value, array(true, 1, '1', 'true'), true);
    }

    protected function isPositiveId($value)
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1;
    }
}
