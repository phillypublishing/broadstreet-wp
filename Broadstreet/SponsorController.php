<?php

/**
 * Sponsor-specific REST, advertiser-create, status, and queue orchestration.
 *
 * Broadstreet_Core keeps public compatibility delegates while this controller
 * provides one narrow foundation for the sponsored-content editor features.
 */
class Broadstreet_Sponsor_Controller
{
    protected $core;

    public function __construct($core)
    {
        $this->core = $core;
    }

    public function registerRoutes()
    {
        $post_id_arg = array(
            'required' => true,
            'type' => 'integer',
            'minimum' => 1,
        );

        register_rest_route('broadstreet/v1', '/advertisers', array(
            array(
                'methods' => 'GET',
                'callback' => array($this->core, 'getSponsorAdvertisers'),
                'permission_callback' => array($this->core, 'canEditSponsorPost'),
                'args' => array('post_id' => $post_id_arg),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this->core, 'createSponsorAdvertiser'),
                'permission_callback' => array($this->core, 'canEditSponsorPost'),
                'args' => array(
                    'post_id' => $post_id_arg,
                    'name' => array('required' => true, 'type' => 'string'),
                ),
            ),
        ));

        register_rest_route('broadstreet/v1', '/sponsor-status', array(
            array(
                'methods' => 'GET',
                'callback' => array($this->core, 'getSponsorStatus'),
                'permission_callback' => array($this->core, 'canEditSponsorPost'),
                'args' => array('post_id' => $post_id_arg),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this->core, 'retrySponsorStatus'),
                'permission_callback' => array($this->core, 'canEditSponsorPost'),
                'args' => array('post_id' => $post_id_arg),
            ),
        ));
    }

    public function canEditPost($request)
    {
        $post_id = absint($request['post_id']);
        return $post_id > 0
            && $this->core->isSponsorEditorPostType(get_post_type($post_id))
            && current_user_can('edit_post', $post_id);
    }

    public function getAdvertisers($request)
    {
        try {
            $advertisers = $this->core->getSponsorBroadstreetClient()->getAdvertisers(
                Broadstreet_Utility::getOption(Broadstreet_Core::KEY_NETWORK_ID)
            );
        } catch (Exception $exception) {
            return new WP_Error(
                'broadstreet_advertisers_unavailable',
                'Broadstreet advertisers could not be loaded. Try again.',
                array('status' => 502)
            );
        }

        $response = array();
        foreach (is_array($advertisers) ? $advertisers : array() as $advertiser) {
            $id = isset($advertiser->id) ? (string) $advertiser->id : '';
            if (!preg_match('/^[1-9][0-9]*$/D', $id)) {
                continue;
            }

            $response[] = array(
                'id' => $id,
                'name' => isset($advertiser->name) ? sanitize_text_field($advertiser->name) : '',
            );
        }

        usort($response, function ($left, $right) {
            return strcasecmp($left['name'], $right['name']);
        });

        return $this->restResponse($response);
    }

    public function createAdvertiser($request)
    {
        $result = $this->createAdvertiserForPost(
            absint($request['post_id']),
            isset($request['name']) ? $request['name'] : ''
        );

        return is_wp_error($result) ? $result : $this->restResponse($result);
    }

    public function createAdvertiserForPost($post_id, $name)
    {
        $name = trim(sanitize_text_field($name));
        $name_length = $this->stringLength($name);
        if ($name_length < 3 || $name_length > 127) {
            return new WP_Error(
                'broadstreet_invalid_advertiser_name',
                'Advertiser names must be between 3 and 127 characters.',
                array('status' => 400)
            );
        }

        $lock_key = '_broadstreet_sponsor_advertiser_create_lock_' . (int) $post_id;
        $locks = new Broadstreet_Option_Lock();
        $lock = $locks->acquire($lock_key);
        if ($lock === false) {
            return new WP_Error(
                'broadstreet_advertiser_create_in_progress',
                'Broadstreet advertiser creation is already in progress. Wait for it to finish before trying again.',
                array('status' => 409)
            );
        }

        try {
            return $this->createAdvertiserWithinLock($post_id, $name, $locks, $lock_key, $lock);
        } finally {
            $locks->release($lock_key, $lock);
        }
    }

    protected function stringLength($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : $count;
    }

    protected function createAdvertiserWithinLock($post_id, $name, $locks, $lock_key, $lock)
    {
        if (!$locks->owns($lock_key, $lock)) {
            return new WP_Error(
                'broadstreet_advertiser_create_in_progress',
                'Broadstreet advertiser creation is already in progress. Wait for it to finish before trying again.',
                array('status' => 409)
            );
        }

        $fingerprint = hash('sha256', $post_id . "\n" . $name);
        $attempt = get_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, true);

        if (is_array($attempt) && isset($attempt['state'])) {
            if (in_array($attempt['state'], array('dispatching', 'outcome_unknown'), true)) {
                $message = 'Broadstreet may have created the advertiser. Check the Broadstreet dashboard before trying again.';
                $this->core->getSponsorReconciler()->recordStatus($post_id, 'needs_action', $message, false);
                return new WP_Error(
                    'broadstreet_advertiser_outcome_unknown',
                    $message,
                    array('status' => 409)
                );
            }

            if (isset($attempt['fingerprint'])
                && $attempt['fingerprint'] === $fingerprint
                && $attempt['state'] === 'complete'
                && isset($attempt['advertiser_id'])
                && preg_match('/^[1-9][0-9]*$/D', (string) $attempt['advertiser_id'])) {
                return array('id' => (string) $attempt['advertiser_id'], 'name' => $name);
            }

            if (isset($attempt['fingerprint'])
                && $attempt['fingerprint'] === $fingerprint
                && $attempt['state'] === 'definite_failure') {
                // Reaching this method is itself an explicit advertiser-create
                // action. A definite 422 proves no remote object was created,
                // so the user may retry after correcting account-side state.
            }
        }

        $attempt = array(
            'state' => 'dispatching',
            'fingerprint' => $fingerprint,
            'created_at' => time(),
        );
        update_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, $attempt);
        $persisted_attempt = get_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, true);
        if (!$this->isExactAttempt($persisted_attempt, $attempt)) {
            $message = 'Broadstreet could not safely record the advertiser creation attempt. Try again.';
            $this->core->getSponsorReconciler()->recordStatus($post_id, 'error', $message, true);
            return new WP_Error(
                'broadstreet_advertiser_state_persistence_failed',
                $message,
                array('status' => 500)
            );
        }

        if (!$locks->owns($lock_key, $lock)) {
            return new WP_Error(
                'broadstreet_advertiser_create_in_progress',
                'Broadstreet advertiser creation is already in progress. Wait for it to finish before trying again.',
                array('status' => 409)
            );
        }

        try {
            $advertiser = $this->core->getSponsorBroadstreetClient()->createAdvertiser(
                Broadstreet_Utility::getOption(Broadstreet_Core::KEY_NETWORK_ID),
                $name
            );
        } catch (Exception $exception) {
            $definite_failure = $exception instanceof Broadstreet_ServerException
                && (int) $exception->code === 422;
            $attempt['state'] = $definite_failure ? 'definite_failure' : 'outcome_unknown';
            update_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, $attempt);

            $message = $definite_failure
                ? 'Broadstreet rejected that advertiser name. Correct the name before trying again.'
                : 'Broadstreet may have created the advertiser. Check the Broadstreet dashboard before trying again.';
            $this->core->getSponsorReconciler()->recordStatus(
                $post_id,
                $definite_failure ? 'error' : 'needs_action',
                $message,
                false
            );
            return new WP_Error(
                $definite_failure ? 'broadstreet_advertiser_rejected' : 'broadstreet_advertiser_outcome_unknown',
                $message,
                array('status' => $definite_failure ? 422 : 502)
            );
        }

        $advertiser_id = isset($advertiser->id) ? (string) $advertiser->id : '';
        if (!preg_match('/^[1-9][0-9]*$/D', $advertiser_id)) {
            $attempt['state'] = 'outcome_unknown';
            update_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, $attempt);
            $message = 'Broadstreet may have created the advertiser, but WordPress could not confirm its ID. Check the Broadstreet dashboard before trying again.';
            $this->core->getSponsorReconciler()->recordStatus($post_id, 'needs_action', $message, false);
            return new WP_Error(
                'broadstreet_advertiser_outcome_unknown',
                $message,
                array('status' => 502)
            );
        }

        $attempt['state'] = 'complete';
        $attempt['advertiser_id'] = $advertiser_id;
        update_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, $attempt);
        $persisted_attempt = get_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, true);
        if (!is_array($persisted_attempt)
            || !isset($persisted_attempt['state'])
            || $persisted_attempt['state'] !== 'complete'
            || !isset($persisted_attempt['advertiser_id'])
            || (string) $persisted_attempt['advertiser_id'] !== $advertiser_id) {
            $attempt['state'] = 'outcome_unknown';
            update_post_meta($post_id, Broadstreet_Core::META_ADVERTISER_CREATE_ATTEMPT, $attempt);
            $message = 'Broadstreet created the advertiser, but WordPress could not safely persist its ID. Check the Broadstreet dashboard before trying again.';
            $this->core->getSponsorReconciler()->recordStatus($post_id, 'needs_action', $message, false);
            return new WP_Error(
                'broadstreet_advertiser_outcome_unknown',
                $message,
                array('status' => 500)
            );
        }

        return array('id' => $advertiser_id, 'name' => $name);
    }

    protected function isExactAttempt($persisted, $expected)
    {
        return is_array($persisted)
            && isset($persisted['state'], $persisted['fingerprint'])
            && $persisted['state'] === $expected['state']
            && $persisted['fingerprint'] === $expected['fingerprint'];
    }

    public function getStatus($request)
    {
        return $this->restResponse(
            $this->publicStatus(
                $this->core->getSponsorReconciler()->getStatus(absint($request['post_id']))
            )
        );
    }

    public function retryStatus($request)
    {
        return $this->restResponse(
            $this->publicStatus(
                $this->core->getSponsorReconciler()->reconcile(absint($request['post_id']), true)
            )
        );
    }

    protected function restResponse($data)
    {
        $response = rest_ensure_response($data);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('Pragma', 'no-cache');
        return $response;
    }

    protected function publicStatus($status)
    {
        return array(
            'state' => isset($status['state']) ? (string) $status['state'] : 'idle',
            'message' => isset($status['message']) ? (string) $status['message'] : '',
            'retryable' => !empty($status['retryable']),
            'updated_at' => isset($status['updated_at']) ? (int) $status['updated_at'] : 0,
            'poll_after' => isset($status['state']) && $status['state'] === 'queued' ? 2 : 0,
        );
    }

    public function queuePost($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !Broadstreet_Utility::getOption(Broadstreet_Core::KEY_API_KEY)) {
            return false;
        }

        $reconciler = $this->core->getSponsorReconciler();
        if (!$this->core->sanitizeSponsorBoolean(get_post_meta($post_id, 'bs_sponsor_is_sponsored', true))) {
            return $reconciler->recordStatus(
                $post_id,
                'disabled',
                'Broadstreet tracking is disabled for this post.',
                false
            );
        }

        if ($this->core->sanitizeSponsorAdvertiserId(get_post_meta($post_id, 'bs_sponsor_advertiser_id', true)) === '') {
            return $reconciler->recordStatus(
                $post_id,
                'waiting',
                'Select an advertiser, then save the post to enable Broadstreet tracking.',
                false
            );
        }

        $args = array($post_id);
        if (!wp_next_scheduled(Broadstreet_Sponsor_Reconciler::RETRY_HOOK, $args)
            && !wp_schedule_single_event(time() + 1, Broadstreet_Sponsor_Reconciler::RETRY_HOOK, $args)) {
            return $reconciler->recordStatus(
                $post_id,
                'error',
                'Broadstreet synchronization could not be queued. Use Retry synchronization to try again.',
                true
            );
        }

        return $reconciler->recordStatus(
            $post_id,
            'queued',
            'Broadstreet synchronization is queued.',
            false
        );
    }
}
