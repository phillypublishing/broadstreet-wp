<?php

require_once dirname(__FILE__) . '/OptionLock.php';

/**
 * Idempotently reconciles editor-owned sponsorship meta with Broadstreet.
 *
 * The vendor create endpoint has no documented idempotency key or lookup by
 * client fingerprint. A durable pre-dispatch marker therefore guards every
 * create. If the outcome cannot be proven from a positive returned ID that was
 * persisted locally, automatic reconciliation stops rather than risking a
 * duplicate remote tracker.
 */
class Broadstreet_Sponsor_Reconciler
{
    const LOCK_PREFIX = '_broadstreet_sponsor_lock_';
    const RETRY_HOOK = 'broadstreet_reconcile_sponsor_post';
    const LOCK_TTL = Broadstreet_Option_Lock::DEFAULT_TTL;

    const META_REMOTE_ADVERTISER = '_bs_sponsor_remote_advertiser_id';
    const META_FINGERPRINT = '_bs_sponsor_reconciliation_fingerprint';
    const META_STATUS = '_bs_sponsor_reconciliation_status';
    const META_CREATE_ATTEMPT = '_bs_sponsor_tracker_create_attempt';
    const META_MOVE_ATTEMPT = '_bs_sponsor_tracker_move_attempt';

    protected $client;
    protected $network_id;
    protected $locks;
    protected $active_lock;

    public function __construct($client, $network_id)
    {
        $this->client = $client;
        $this->network_id = $network_id;
        $this->locks = new Broadstreet_Option_Lock();
        $this->active_lock = false;
    }

    /**
     * Reconcile one canonical post. The lock is intentionally acquired before
     * reading meta so an editor save that waited behind another request cannot
     * act on stale values.
     *
     * @param int  $post_id Post ID.
     * @param bool $explicit Whether a user explicitly requested a safe retry.
     * @return array Public, credential-free status.
     */
    public function reconcile($post_id, $explicit = false)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return $this->status('error', 'Broadstreet could not identify the post to synchronize.', false);
        }

        $lock = $this->acquireLock($post_id);
        if ($lock === false) {
            if (!$this->scheduleRetry($post_id)) {
                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet synchronization could not be queued. Use Retry synchronization to try again.',
                    true
                );
            }
            return $this->recordStatus(
                $post_id,
                'queued',
                'Broadstreet synchronization is queued behind another save.',
                true
            );
        }

        $this->active_lock = $lock;

        try {
            $enabled = $this->isEnabled(
                get_post_meta($post_id, 'bs_sponsor_is_sponsored', true)
            );

            if (!$enabled) {
                return $this->recordStatus(
                    $post_id,
                    'disabled',
                    'Broadstreet tracking is disabled for this post.',
                    false
                );
            }

            $advertiser_id = (string) get_post_meta(
                $post_id,
                'bs_sponsor_advertiser_id',
                true
            );
            if (!$this->isPositiveId($advertiser_id)) {
                return $this->recordStatus(
                    $post_id,
                    'waiting',
                    'Select an advertiser, then save the post to enable Broadstreet tracking.',
                    false
                );
            }

            $desired = $this->readDesiredState($post_id, $advertiser_id);

            $fingerprint = $this->fingerprint($desired);
            $attempt = get_post_meta($post_id, self::META_CREATE_ATTEMPT, true);

            // A local positive ID is the only proof that an earlier create
            // completed. It can safely recover a process that died after the ID
            // was stored but before the attempt marker was advanced.
            if ($this->isPositiveId($desired['advertisement_id'])
                && is_array($attempt)
                && isset($attempt['fingerprint'])
                && $attempt['fingerprint'] === $fingerprint
                && isset($attempt['state'])
                && $attempt['state'] === 'dispatching') {
                $attempt['state'] = 'complete';
                $attempt['advertisement_id'] = $desired['advertisement_id'];
                update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);
            }

            if (!$this->isPositiveId($desired['advertisement_id'])
                && $this->attemptBlocksCreate($attempt, $fingerprint, $explicit)) {
                return $this->recordStatus(
                    $post_id,
                    'needs_action',
                    'Broadstreet may have created a tracker, but WordPress could not confirm its ID. Check the Broadstreet dashboard before taking further action.',
                    false
                );
            }

            if ($this->isPositiveId($desired['advertisement_id'])
                && get_post_meta($post_id, self::META_FINGERPRINT, true) === $fingerprint) {
                return $this->recordStatus(
                    $post_id,
                    'noop',
                    'Broadstreet tracking is already synchronized.',
                    false
                );
            }

            $type = $this->getTrackerType($post_id);
            if ($type === false) {
                return $this->getStatus($post_id);
            }

            if (!$this->isPositiveId($desired['advertisement_id'])) {
                return $this->createTracker($post_id, $desired, $fingerprint, $type, $explicit);
            }

            return $this->updateTracker($post_id, $desired, $fingerprint, $type);
        } finally {
            $this->releaseLock($post_id, $lock);
            $this->active_lock = false;
        }
    }

    /**
     * Return only the public reconciliation state used by editor and Classic UI.
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

    protected function status($state, $message, $retryable, $updated_at = null)
    {
        return array(
            'state' => (string) $state,
            'message' => (string) $message,
            'retryable' => (bool) $retryable,
            'updated_at' => $updated_at === null ? time() : (int) $updated_at,
        );
    }

    protected function readDesiredState($post_id, $advertiser_id)
    {
        $original_post_id = get_post_meta($post_id, '_dp_original', true);
        if ($original_post_id) {
            $post_link = get_permalink($original_post_id);
        } elseif (in_array(get_post_status($post_id), array('draft', 'future', 'pending'), true)) {
            if (!function_exists('get_sample_permalink') && defined('ABSPATH')) {
                $admin_post_file = ABSPATH . 'wp-admin/includes/post.php';
                if (file_exists($admin_post_file)) {
                    require_once $admin_post_file;
                }
            }

            if (function_exists('get_sample_permalink')) {
                $sample = get_sample_permalink($post_id);
                $post_link = preg_replace('/\%postname\%/', $sample[1], $sample[0]);
            } else {
                $post_link = get_permalink($post_id);
            }
        } else {
            $post_link = get_the_permalink($post_id);
        }

        $title = substr(str_pad((string) get_the_title($post_id), 5, '*'), 0, 127);

        return array(
            'enabled' => true,
            'advertiser_id' => $advertiser_id,
            'advertisement_id' => (string) get_post_meta($post_id, 'bs_sponsor_advertisement_id', true),
            'title' => $title,
            'url' => (string) $post_link,
        );
    }

    protected function fingerprint($desired)
    {
        return hash('sha256', wp_json_encode(array(
            'advertiser_id' => $desired['advertiser_id'],
            'title' => $desired['title'],
            'url' => $desired['url'],
        )));
    }

    protected function getTrackerType($post_id)
    {
        try {
            $network = $this->getClient()->getNetwork($this->getNetworkId());
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

    protected function createTracker($post_id, $desired, $fingerprint, $type, $explicit = false)
    {
        if (!$this->ownsActiveLock($post_id)) {
            return $this->recordStatus(
                $post_id,
                'queued',
                'Broadstreet synchronization is queued behind another save.',
                true
            );
        }

        $attempt = get_post_meta($post_id, self::META_CREATE_ATTEMPT, true);
        if ($this->attemptBlocksCreate($attempt, $fingerprint, $explicit)) {
            return $this->recordStatus(
                $post_id,
                'needs_action',
                'Broadstreet may have created a tracker, but WordPress could not confirm its ID. Check the Broadstreet dashboard before taking further action.',
                false
            );
        }

        $attempt = array(
            'state' => 'dispatching',
            'fingerprint' => $fingerprint,
            'created_at' => time(),
        );
        update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);
        if (!$this->isExactAttemptPersisted($post_id, self::META_CREATE_ATTEMPT, $attempt)) {
            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not safely record the tracker creation attempt. Try again.',
                true
            );
        }


        if (!$this->ownsActiveLock($post_id)) {
            return $this->recordStatus(
                $post_id,
                'queued',
                'Broadstreet synchronization is queued behind another save.',
                true
            );
        }

        try {
            $advertisement = $this->getClient()->createAdvertisement(
                $this->getNetworkId(),
                $desired['advertiser_id'],
                $desired['title'],
                $type,
                array(
                    'stencil_inputs' => array('url' => $desired['url']),
                    'post_id' => $post_id,
                )
            );
        } catch (Exception $exception) {
            $state = $this->isDefiniteValidationFailure($exception) ? 'definite_failure' : 'outcome_unknown';
            $attempt['state'] = $state;
            update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);

            if ($state === 'definite_failure') {
                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet rejected the tracker details. Correct the post or advertiser, then explicitly retry.',
                    false
                );
            }

            return $this->recordStatus(
                $post_id,
                'needs_action',
                'Broadstreet may have created a tracker, but WordPress could not confirm its ID. Check the Broadstreet dashboard before taking further action.',
                false
            );
        }

        $advertisement_id = isset($advertisement->id) ? (string) $advertisement->id : '';
        if (!$this->isPositiveId($advertisement_id)) {
            $attempt['state'] = 'outcome_unknown';
            update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);
            return $this->recordStatus(
                $post_id,
                'needs_action',
                'Broadstreet may have created a tracker, but WordPress could not confirm its ID. Check the Broadstreet dashboard before taking further action.',
                false
            );
        }

        update_post_meta($post_id, 'bs_sponsor_advertisement_id', $advertisement_id);
        if ((string) get_post_meta($post_id, 'bs_sponsor_advertisement_id', true) !== $advertisement_id) {
            $attempt['state'] = 'outcome_unknown';
            update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);
            return $this->recordStatus(
                $post_id,
                'needs_action',
                'Broadstreet created a tracker, but WordPress could not safely store its ID. Check the Broadstreet dashboard before taking further action.',
                false
            );
        }

        $attempt['state'] = 'complete';
        $attempt['advertisement_id'] = $advertisement_id;
        update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);
        if (!$this->persistPostUpdateState($post_id, $desired['advertiser_id'], $fingerprint)) {
            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet created the tracker, but WordPress could not safely store its synchronization state.',
                true
            );
        }

        return $this->recordStatus(
            $post_id,
            'synced',
            'Broadstreet tracking is synchronized.',
            false
        );
    }

    protected function updateTracker($post_id, $desired, $fingerprint, $type)
    {
        if (!$this->ownsActiveLock($post_id)) {
            return $this->recordStatus(
                $post_id,
                'queued',
                'Broadstreet synchronization is queued behind another save.',
                true
            );
        }

        $remote_advertiser_id = (string) get_post_meta($post_id, self::META_REMOTE_ADVERTISER, true);
        if (!$this->isPositiveId($remote_advertiser_id)) {
            $remote_advertiser_id = $desired['advertiser_id'];
        }

        $move_attempt = get_post_meta($post_id, self::META_MOVE_ATTEMPT, true);
        if ($this->isPendingMoveAttempt($move_attempt, $desired['advertisement_id'])) {
            $recovery = $this->inspectMoveLocation($move_attempt);
            $recovered = $this->applyMoveRecovery(
                $post_id,
                $desired,
                $fingerprint,
                $type,
                $move_attempt,
                $recovery,
                true
            );
            if (is_array($recovered)) {
                return $recovered;
            }
            $remote_advertiser_id = (string) $recovered;
        }

        $params = array(
            'name' => $desired['title'],
            'stencil_inputs' => array('url' => $desired['url']),
            'type' => $type,
        );

        $is_move = $remote_advertiser_id !== $desired['advertiser_id'];
        $move_attempt = null;
        if ($is_move) {
            $params['new_advertiser_id'] = $desired['advertiser_id'];
            $move_attempt = array(
                'state' => 'dispatching',
                'advertisement_id' => $desired['advertisement_id'],
                'from_advertiser_id' => $remote_advertiser_id,
                'to_advertiser_id' => $desired['advertiser_id'],
                'fingerprint' => $fingerprint,
                'created_at' => time(),
            );
            update_post_meta($post_id, self::META_MOVE_ATTEMPT, $move_attempt);
            if (!$this->isExactMovePersisted($post_id, $move_attempt)) {
                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet could not safely record the tracker move attempt. Try again.',
                    true
                );
            }
        }

        try {
            if (!$this->ownsActiveLock($post_id)) {
                return $this->recordStatus(
                    $post_id,
                    'queued',
                    'Broadstreet synchronization is queued behind another save.',
                    true
                );
            }

            $this->getClient()->updateAdvertisement(
                $this->getNetworkId(),
                $remote_advertiser_id,
                $desired['advertisement_id'],
                $params
            );
        } catch (Broadstreet_ServerException $exception) {
            if ($is_move) {
                $move_attempt['state'] = 'outcome_unknown';
                update_post_meta($post_id, self::META_MOVE_ATTEMPT, $move_attempt);
                return $this->applyMoveRecovery(
                    $post_id,
                    $desired,
                    $fingerprint,
                    $type,
                    $move_attempt,
                    $this->inspectMoveLocation($move_attempt),
                    false
                );
            }

            if ((int) $exception->code === 404) {
                // The endpoint is advertiser-scoped. A 404 proves only that
                // the tracker is not under this known owner; it may still be
                // live under another advertiser, so automatic replacement is
                // unsafe without an operator-confirmed network-wide lookup.
                return $this->recordStatus(
                    $post_id,
                    'needs_action',
                    'Broadstreet could not find the tracker under its recorded advertiser. Check the Broadstreet dashboard before taking further action.',
                    false
                );
            }

            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not update the tracker. Save or retry to try again.',
                true
            );
        } catch (Exception $exception) {
            if ($is_move) {
                $move_attempt['state'] = 'outcome_unknown';
                update_post_meta($post_id, self::META_MOVE_ATTEMPT, $move_attempt);
                return $this->applyMoveRecovery(
                    $post_id,
                    $desired,
                    $fingerprint,
                    $type,
                    $move_attempt,
                    $this->inspectMoveLocation($move_attempt),
                    false
                );
            }

            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet could not update the tracker. Save or retry to try again.',
                true
            );
        }

        if (!$this->persistPostUpdateState($post_id, $desired['advertiser_id'], $fingerprint)) {
            if ($is_move) {
                $move_attempt['state'] = 'outcome_unknown';
                update_post_meta($post_id, self::META_MOVE_ATTEMPT, $move_attempt);
            }
            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet updated the tracker, but WordPress could not safely store its synchronization state.',
                true
            );
        }

        if ($is_move) {
            $move_attempt['state'] = 'complete';
            update_post_meta($post_id, self::META_MOVE_ATTEMPT, $move_attempt);
        }

        return $this->recordStatus(
            $post_id,
            'synced',
            'Broadstreet tracking is synchronized.',
            false
        );
    }

    protected function attemptBlocksCreate($attempt, $fingerprint, $explicit)
    {
        if (!is_array($attempt) || !isset($attempt['state'])) {
            return false;
        }

        if (in_array($attempt['state'], array('dispatching', 'outcome_unknown'), true)) {
            return true;
        }

        if ($attempt['state'] === 'definite_failure'
            && isset($attempt['fingerprint'])
            && $attempt['fingerprint'] === $fingerprint) {
            return !$explicit;
        }

        return false;
    }

    protected function isDefiniteValidationFailure($exception)
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

    protected function getClient()
    {
        return $this->client;
    }

    protected function getNetworkId()
    {
        return $this->network_id;
    }

    protected function isExactAttemptPersisted($post_id, $meta_key, $expected)
    {
        $persisted = get_post_meta($post_id, $meta_key, true);
        return is_array($persisted)
            && isset($persisted['state'], $persisted['fingerprint'])
            && $persisted['state'] === $expected['state']
            && $persisted['fingerprint'] === $expected['fingerprint'];
    }

    protected function isExactMovePersisted($post_id, $expected)
    {
        $persisted = get_post_meta($post_id, self::META_MOVE_ATTEMPT, true);
        foreach (array('state', 'advertisement_id', 'from_advertiser_id', 'to_advertiser_id', 'fingerprint') as $key) {
            if (!is_array($persisted)
                || !isset($persisted[$key])
                || (string) $persisted[$key] !== (string) $expected[$key]) {
                return false;
            }
        }

        return true;
    }

    protected function persistPostUpdateState($post_id, $advertiser_id, $fingerprint)
    {
        update_post_meta($post_id, self::META_REMOTE_ADVERTISER, $advertiser_id);
        if ((string) get_post_meta($post_id, self::META_REMOTE_ADVERTISER, true) !== (string) $advertiser_id) {
            return false;
        }

        update_post_meta($post_id, self::META_FINGERPRINT, $fingerprint);
        return (string) get_post_meta($post_id, self::META_FINGERPRINT, true) === (string) $fingerprint;
    }

    protected function isPendingMoveAttempt($attempt, $advertisement_id)
    {
        return is_array($attempt)
            && isset($attempt['state'], $attempt['advertisement_id'], $attempt['from_advertiser_id'], $attempt['to_advertiser_id'])
            && in_array($attempt['state'], array('dispatching', 'outcome_unknown'), true)
            && (string) $attempt['advertisement_id'] === (string) $advertisement_id
            && $this->isPositiveId($attempt['from_advertiser_id'])
            && $this->isPositiveId($attempt['to_advertiser_id']);
    }

    protected function inspectMoveLocation($attempt)
    {
        $under_intended = $this->advertisementExists(
            $attempt['to_advertiser_id'],
            $attempt['advertisement_id']
        );
        if ($under_intended === true) {
            return 'intended';
        }
        if ($under_intended === null) {
            return 'unknown';
        }

        $under_current = $this->advertisementExists(
            $attempt['from_advertiser_id'],
            $attempt['advertisement_id']
        );
        if ($under_current === true) {
            return 'current';
        }
        if ($under_current === false) {
            return 'absent';
        }

        return 'unknown';
    }

    protected function advertisementExists($advertiser_id, $advertisement_id)
    {
        try {
            $advertisement = $this->getClient()->getAdvertisement(
                $this->getNetworkId(),
                $advertiser_id,
                $advertisement_id
            );
            return isset($advertisement->id)
                && (string) $advertisement->id === (string) $advertisement_id
                ? true
                : null;
        } catch (Broadstreet_ServerException $exception) {
            return (int) $exception->code === 404 ? false : null;
        } catch (Exception $exception) {
            return null;
        }
    }

    /**
     * Return a public status, or a confirmed current owner for a safe retry.
     */
    protected function applyMoveRecovery($post_id, $desired, $fingerprint, $type, $attempt, $location, $allow_retry)
    {
        if ($location === 'intended') {
            if (!$this->persistRemoteAdvertiser($post_id, $attempt['to_advertiser_id'])) {
                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet moved the tracker, but WordPress could not safely store its synchronization state.',
                    true
                );
            }
            $attempt['state'] = 'complete';
            update_post_meta($post_id, self::META_MOVE_ATTEMPT, $attempt);

            if ((string) $attempt['to_advertiser_id'] !== (string) $desired['advertiser_id']
                || !isset($attempt['fingerprint'])
                || (string) $attempt['fingerprint'] !== (string) $fingerprint) {
                return (string) $attempt['to_advertiser_id'];
            }

            update_post_meta($post_id, self::META_FINGERPRINT, $fingerprint);
            if ((string) get_post_meta($post_id, self::META_FINGERPRINT, true) !== (string) $fingerprint) {
                return $this->recordStatus(
                    $post_id,
                    'error',
                    'Broadstreet moved the tracker, but WordPress could not safely store its synchronization state.',
                    true
                );
            }

            return $this->recordStatus(
                $post_id,
                'synced',
                'Broadstreet tracking is synchronized.',
                false
            );
        }

        if ($location === 'current') {
            $attempt['state'] = 'not_moved';
            update_post_meta($post_id, self::META_MOVE_ATTEMPT, $attempt);
            if ($allow_retry) {
                return (string) $attempt['from_advertiser_id'];
            }

            return $this->recordStatus(
                $post_id,
                'error',
                'Broadstreet did not move the tracker. Save or retry to try again.',
                true
            );
        }

        if ($location === 'absent') {
            // Broadstreet lookups are advertiser-scoped. Even two known-owner
            // 404s cannot prove that an operator did not move the tracker to a
            // third advertiser, so automatic replacement remains unsafe.
            $attempt['state'] = 'needs_action';
            update_post_meta($post_id, self::META_MOVE_ATTEMPT, $attempt);
            return $this->recordStatus(
                $post_id,
                'needs_action',
                'Broadstreet could not find the tracker under either recorded advertiser. Check the Broadstreet dashboard before taking further action.',
                false
            );
        }

        $attempt['state'] = 'outcome_unknown';
        update_post_meta($post_id, self::META_MOVE_ATTEMPT, $attempt);
        return $this->recordStatus(
            $post_id,
            'needs_action',
            'Broadstreet may have moved the tracker, but WordPress could not confirm its owner. Check the Broadstreet dashboard before taking further action.',
            false
        );
    }

    protected function persistRemoteAdvertiser($post_id, $advertiser_id)
    {
        update_post_meta($post_id, self::META_REMOTE_ADVERTISER, $advertiser_id);
        return (string) get_post_meta($post_id, self::META_REMOTE_ADVERTISER, true) === (string) $advertiser_id;
    }

    protected function acquireLock($post_id)
    {
        return $this->locks->acquire(self::LOCK_PREFIX . $post_id, self::LOCK_TTL);
    }

    protected function releaseLock($post_id, $lock)
    {
        $this->locks->release(self::LOCK_PREFIX . $post_id, $lock);
    }

    protected function ownsActiveLock($post_id)
    {
        return is_array($this->active_lock)
            && $this->locks->owns(self::LOCK_PREFIX . $post_id, $this->active_lock);
    }

    protected function scheduleRetry($post_id)
    {
        $args = array((int) $post_id);
        if (!wp_next_scheduled(self::RETRY_HOOK, $args)) {
            return (bool) wp_schedule_single_event(time() + 5, self::RETRY_HOOK, $args);
        }

        return true;
    }
}
