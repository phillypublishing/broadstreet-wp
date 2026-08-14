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
    const META_REMOTE_OWNER_POST = '_bs_sponsor_remote_owner_post_id';
    const META_FINGERPRINT = '_bs_sponsor_reconciliation_fingerprint';
    const META_STATUS = '_bs_sponsor_reconciliation_status';
    const META_CREATE_ATTEMPT = '_bs_sponsor_tracker_create_attempt';
    const META_MOVE_ATTEMPT = '_bs_sponsor_tracker_move_attempt';

    protected $client;
    protected $network_id;
    protected $locks;
    protected $active_lock;
    protected $active_lock_key;
    protected $active_canonical_owner_post_id;

    public function __construct($client, $network_id)
    {
        $this->client = $client;
        $this->network_id = $network_id;
        $this->locks = new Broadstreet_Option_Lock();
        $this->active_lock = false;
        $this->active_lock_key = '';
        $this->active_canonical_owner_post_id = '';
    }

    /**
     * Reconcile one post through its canonical owner lock. Canonical identity
     * is sampled only to select the lock, then verified again after acquisition
     * before any editorial or remote state is read.
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

        $canonical_owner_post_id = $this->getCanonicalOwnerPostId($post_id);
        $lock_key = $this->getLockKey($canonical_owner_post_id);
        $lock = $this->acquireLock($lock_key);
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
        $this->active_lock_key = $lock_key;
        $this->active_canonical_owner_post_id = $canonical_owner_post_id;

        try {
            if ($this->getCanonicalOwnerPostId($post_id) !== $canonical_owner_post_id) {
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
                    'Broadstreet synchronization is queued because the canonical post changed during this save.',
                    true
                );
            }

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

            if ($this->isRepublishDraft($post_id)
                && !$this->isPositiveId($desired['advertisement_id'])) {
                if (!$this->hydrateRepublishTracker($post_id, $canonical_owner_post_id)) {
                    return $this->recordStatus(
                        $post_id,
                        'needs_action',
                        'Broadstreet could not prove a tracker owned by the original post for this Rewrite & Republish draft. Check the original post and Broadstreet dashboard before taking further action.',
                        false
                    );
                }

                $desired = $this->readDesiredState($post_id, $advertiser_id);
            }

            $ownership = $this->resolveRemoteOwnership($post_id, $desired['advertisement_id']);
            if ($ownership === 'ambiguous') {
                return $this->recordStatus(
                    $post_id,
                    'needs_action',
                    'Broadstreet could not prove which WordPress post owns this tracker. Check the Broadstreet dashboard before taking further action.',
                    false
                );
            }
            if ($ownership === 'foreign_republish_copy') {
                return $this->recordStatus(
                    $post_id,
                    'needs_action',
                    'This Rewrite & Republish draft references a tracker that is not owned by its original post. Check the Broadstreet dashboard before taking further action.',
                    false
                );
            }
            if ($ownership === 'copied') {
                if (!$this->resetCopiedTrackerState($post_id)) {
                    return $this->recordStatus(
                        $post_id,
                        'error',
                        'Broadstreet could not safely separate this duplicate from the original tracker. Try again.',
                        true
                    );
                }
                $desired['advertisement_id'] = '';
            }

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
            $this->releaseLock($lock);
            $this->active_lock = false;
            $this->active_lock_key = '';
            $this->active_canonical_owner_post_id = '';
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
        if (!$this->ownsActiveLock()) {
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
            'owner_post_id' => $this->getOperationOwnerPostId($post_id),
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


        if (!$this->ownsActiveLock()) {
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

        if (!$this->persistTrackerIdentity($post_id, $advertisement_id)) {
            $attempt['state'] = 'outcome_unknown';
            update_post_meta($post_id, self::META_CREATE_ATTEMPT, $attempt);
            return $this->recordStatus(
                $post_id,
                'needs_action',
                'Broadstreet created a tracker, but WordPress could not safely store its ID and owner. Check the Broadstreet dashboard before taking further action.',
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
        if (!$this->ownsActiveLock()) {
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
                'owner_post_id' => $this->getOperationOwnerPostId($post_id),
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
            if (!$this->ownsActiveLock()) {
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
        $matches = is_array($persisted)
            && isset($persisted['state'], $persisted['fingerprint'])
            && $persisted['state'] === $expected['state']
            && $persisted['fingerprint'] === $expected['fingerprint'];

        if ($matches && isset($expected['owner_post_id'])) {
            return isset($persisted['owner_post_id'])
                && (string) $persisted['owner_post_id'] === (string) $expected['owner_post_id'];
        }

        return $matches;
    }

    protected function isExactMovePersisted($post_id, $expected)
    {
        $persisted = get_post_meta($post_id, self::META_MOVE_ATTEMPT, true);
        foreach (array('state', 'advertisement_id', 'from_advertiser_id', 'to_advertiser_id', 'fingerprint', 'owner_post_id') as $key) {
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
        if (!$this->persistRemoteOwner($post_id)) {
            return false;
        }

        update_post_meta($post_id, self::META_REMOTE_ADVERTISER, $advertiser_id);
        if ((string) get_post_meta($post_id, self::META_REMOTE_ADVERTISER, true) !== (string) $advertiser_id) {
            return false;
        }

        update_post_meta($post_id, self::META_FINGERPRINT, $fingerprint);
        return (string) get_post_meta($post_id, self::META_FINGERPRINT, true) === (string) $fingerprint;
    }

    protected function persistTrackerIdentity($post_id, $advertisement_id)
    {
        update_post_meta($post_id, 'bs_sponsor_advertisement_id', $advertisement_id);
        if ((string) get_post_meta($post_id, 'bs_sponsor_advertisement_id', true) !== (string) $advertisement_id) {
            return false;
        }

        return $this->persistRemoteOwner($post_id);
    }

    protected function persistRemoteOwner($post_id)
    {
        $canonical_owner_post_id = $this->getOperationOwnerPostId($post_id);
        update_post_meta($post_id, self::META_REMOTE_OWNER_POST, $canonical_owner_post_id);
        return (string) get_post_meta($post_id, self::META_REMOTE_OWNER_POST, true) === $canonical_owner_post_id;
    }

    /**
     * Establish whether the current post may update a positive remote tracker.
     * Legacy unstamped IDs are adopted only when the postmeta table proves that
     * every local reference is the canonical post or its Rewrite & Republish
     * draft.
     */
    protected function resolveRemoteOwnership($post_id, $advertisement_id)
    {
        if (!$this->isPositiveId($advertisement_id)) {
            return 'owned';
        }

        $canonical_owner_post_id = $this->getCanonicalOwnerPostId($post_id);
        $owner_post_id = (string) get_post_meta($post_id, self::META_REMOTE_OWNER_POST, true);
        if ($this->isPositiveId($owner_post_id)) {
            if ($canonical_owner_post_id === $owner_post_id) {
                return 'owned';
            }

            return $this->isRepublishDraft($post_id) ? 'foreign_republish_copy' : 'copied';
        }

        $legacy_owner = $this->findLegacyCanonicalTrackerOwner(
            $advertisement_id,
            $canonical_owner_post_id
        );
        if ((string) $legacy_owner !== $canonical_owner_post_id) {
            return 'ambiguous';
        }

        return $this->persistRemoteOwner($post_id) ? 'owned' : 'ambiguous';
    }

    protected function isRepublishDraft($post_id)
    {
        $original_post_id = get_post_meta($post_id, '_dp_original', true);
        return $this->isPositiveId($original_post_id)
            && (string) $original_post_id !== (string) $post_id;
    }

    protected function getCanonicalOwnerPostId($post_id)
    {
        $original_post_id = get_post_meta($post_id, '_dp_original', true);
        if ($this->isPositiveId($original_post_id)) {
            return (string) $original_post_id;
        }

        return (string) $post_id;
    }

    protected function getOperationOwnerPostId($post_id)
    {
        if ($this->isPositiveId($this->active_canonical_owner_post_id)) {
            return (string) $this->active_canonical_owner_post_id;
        }

        return $this->getCanonicalOwnerPostId($post_id);
    }

    protected function findLegacyCanonicalTrackerOwner($advertisement_id, $canonical_owner_post_id)
    {
        global $wpdb;

        if (!isset($wpdb)
            || !isset($wpdb->postmeta)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_col')) {
            return false;
        }

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                'bs_sponsor_advertisement_id',
                (string) $advertisement_id
            )
        );

        if (!is_array($post_ids) || !$post_ids) {
            return false;
        }

        $found_canonical = false;
        foreach (array_values(array_unique($post_ids)) as $referencing_post_id) {
            if (!$this->isPositiveId($referencing_post_id)) {
                return false;
            }

            if ((string) $referencing_post_id === (string) $canonical_owner_post_id) {
                $found_canonical = true;
                continue;
            }

            $original_post_id = get_post_meta($referencing_post_id, '_dp_original', true);
            if (!$this->isPositiveId($original_post_id)
                || (string) $original_post_id !== (string) $canonical_owner_post_id) {
                return false;
            }
        }

        return $found_canonical ? (string) $canonical_owner_post_id : false;
    }

    /**
     * A foreign owner stamp proves the tracker state was copied. Clear only the
     * duplicate's local remote state, journal first, so a crash can never leave
     * an unjournaled create path.
     */
    protected function resetCopiedTrackerState($post_id)
    {
        $keys = array(
            self::META_CREATE_ATTEMPT,
            self::META_MOVE_ATTEMPT,
            self::META_FINGERPRINT,
            self::META_REMOTE_ADVERTISER,
            'bs_sponsor_advertisement_id',
            self::META_REMOTE_OWNER_POST,
        );

        foreach ($keys as $key) {
            delete_post_meta($post_id, $key);
        }

        foreach ($keys as $key) {
            if (get_post_meta($post_id, $key, true) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Copy only proven canonical server state into a blank Rewrite & Republish
     * draft. The advertisement ID is written last so a partial write cannot
     * expose an unowned positive tracker to reconciliation.
     */
    protected function hydrateRepublishTracker($post_id, $canonical_owner_post_id)
    {
        $advertisement_id = (string) get_post_meta(
            $canonical_owner_post_id,
            'bs_sponsor_advertisement_id',
            true
        );
        if (!$this->isPositiveId($advertisement_id)
            || $this->resolveRemoteOwnership($canonical_owner_post_id, $advertisement_id) !== 'owned') {
            return false;
        }

        $remote_advertiser_id = (string) get_post_meta(
            $canonical_owner_post_id,
            self::META_REMOTE_ADVERTISER,
            true
        );
        if (!$this->isPositiveId($remote_advertiser_id)) {
            $remote_advertiser_id = (string) get_post_meta(
                $canonical_owner_post_id,
                'bs_sponsor_advertiser_id',
                true
            );
        }
        if (!$this->isPositiveId($remote_advertiser_id)) {
            return false;
        }

        if (!$this->persistRemoteOwner($post_id)) {
            return false;
        }

        update_post_meta($post_id, self::META_REMOTE_ADVERTISER, $remote_advertiser_id);
        if ((string) get_post_meta($post_id, self::META_REMOTE_ADVERTISER, true) !== $remote_advertiser_id) {
            return false;
        }

        update_post_meta($post_id, 'bs_sponsor_advertisement_id', $advertisement_id);
        return (string) get_post_meta($post_id, 'bs_sponsor_advertisement_id', true) === $advertisement_id;
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

    protected function getLockKey($canonical_owner_post_id)
    {
        return self::LOCK_PREFIX . $canonical_owner_post_id;
    }

    protected function acquireLock($lock_key)
    {
        return $this->locks->acquire($lock_key, self::LOCK_TTL);
    }

    protected function releaseLock($lock)
    {
        if ($this->active_lock_key !== '') {
            $this->locks->release($this->active_lock_key, $lock);
        }
    }

    protected function ownsActiveLock()
    {
        return is_array($this->active_lock)
            && $this->active_lock_key !== ''
            && $this->locks->owns($this->active_lock_key, $this->active_lock);
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
