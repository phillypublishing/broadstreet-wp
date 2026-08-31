<?php

/**
 * Sponsor-specific REST, advertiser-create, status, and sync orchestration.
 *
 * Broadstreet_Core keeps public compatibility delegates while this controller
 * provides one narrow foundation for the sponsored-content editor features.
 */
class Broadstreet_Sponsor_Controller
{
    const ADVERTISER_CREATE_GUARD_PREFIX = 'bs_advertiser_creating_';
    const ADVERTISER_CREATE_GUARD_TTL = 30;

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
            && current_user_can('edit_post', $post_id)
            && current_user_can($this->core->getSponsorManageCapability());
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

        $guard_key = self::ADVERTISER_CREATE_GUARD_PREFIX . (int) $post_id;
        if (get_transient($guard_key)) {
            return new WP_Error(
                'broadstreet_advertiser_create_in_progress',
                'Broadstreet advertiser creation is already in progress. Wait for it to finish before trying again.',
                array('status' => 409)
            );
        }
        set_transient($guard_key, 1, self::ADVERTISER_CREATE_GUARD_TTL);

        try {
            $advertiser = $this->core->getSponsorBroadstreetClient()->createAdvertiser(
                Broadstreet_Utility::getOption(Broadstreet_Core::KEY_NETWORK_ID),
                $name
            );
        } catch (Exception $exception) {
            delete_transient($guard_key);
            if ($exception instanceof Broadstreet_ServerException
                && (int) $exception->code === 422) {
                return new WP_Error(
                    'broadstreet_advertiser_rejected',
                    'Broadstreet rejected that advertiser name. Correct the name before trying again.',
                    array('status' => 422)
                );
            }

            return new WP_Error(
                'broadstreet_advertiser_create_failed',
                'Broadstreet could not create the advertiser. Try again.',
                array('status' => 502)
            );
        }

        delete_transient($guard_key);

        $advertiser_id = isset($advertiser->id) ? (string) $advertiser->id : '';
        if (!preg_match('/^[1-9][0-9]*$/D', $advertiser_id)) {
            return new WP_Error(
                'broadstreet_advertiser_create_failed',
                'Broadstreet did not return an advertiser ID. Check the Broadstreet dashboard before trying again.',
                array('status' => 502)
            );
        }

        return array('id' => $advertiser_id, 'name' => $name);
    }

    protected function stringLength($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : $count;
    }

    public function getStatus($request)
    {
        return $this->restResponse(
            $this->publicStatus(
                $this->core->getSponsorSync()->getStatus(
                    $this->resolveStatusPostId(absint($request['post_id']))
                )
            )
        );
    }

    public function retryStatus($request)
    {
        $post_id = $this->resolveStatusPostId(absint($request['post_id']));

        // A retry on a post that never touched sponsorship must not write
        // status meta and opt it into the sync path.
        if (!$this->postUsesSponsorship($post_id)) {
            return $this->restResponse(
                $this->publicStatus($this->core->getSponsorSync()->getStatus($post_id))
            );
        }

        return $this->restResponse(
            $this->publicStatus(
                $this->core->getSponsorSync()->sync($post_id, true)
            )
        );
    }

    /**
     * The editor panel on a Rewrite & Republish draft must surface, and be
     * able to retry, the original post's synchronization: the draft itself is
     * always a noop and any failure lands on the original.
     */
    protected function resolveStatusPostId($post_id)
    {
        $original_post_id = $this->core->getSponsorSync()->getRewriteRepublishOriginal($post_id);
        if ($original_post_id > 0 && current_user_can('edit_post', $original_post_id)) {
            return $original_post_id;
        }

        return $post_id;
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
        );
    }

    /**
     * Synchronize a saved post inline. Posts that have never touched
     * sponsorship are left completely alone so ordinary saves write no meta.
     * A Rewrite & Republish draft never syncs itself; its original does, so
     * saving or republishing the draft keeps the original's tracker current.
     */
    public function syncPost($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !Broadstreet_Utility::getOption(Broadstreet_Core::KEY_API_KEY)) {
            return false;
        }

        $sync = $this->core->getSponsorSync();

        $original_post_id = $sync->getRewriteRepublishOriginal($post_id);
        if ($original_post_id > 0) {
            if ($this->postUsesSponsorship($post_id)) {
                self::$synced_this_request[$post_id] = true;
                $sync->sync($post_id);
            }
            $post_id = $original_post_id;
        }

        if (!$this->postUsesSponsorship($post_id)) {
            return false;
        }

        self::$synced_this_request[$post_id] = true;
        return $sync->sync($post_id);
    }

    /**
     * Posts already synchronized during this request, so a deferred
     * end-of-request sync does not repeat work rest_after_insert did.
     *
     * @var array<int, bool>
     */
    protected static $synced_this_request = array();

    /**
     * Synchronize at shutdown, after every meta write of the request has
     * landed. Used for REST publishes: transition_post_status fires before
     * the REST controller persists meta, and custom REST routes never fire
     * rest_after_insert at all.
     */
    public function deferSyncToShutdown($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $controller = $this;
        add_action('shutdown', function () use ($controller, $post_id) {
            $controller->syncPostUnlessAlreadySynced($post_id);
        });
    }

    public function syncPostUnlessAlreadySynced($post_id)
    {
        if (isset(self::$synced_this_request[(int) $post_id])) {
            return false;
        }

        return $this->syncPost($post_id);
    }

    protected function postUsesSponsorship($post_id)
    {
        // metadata_exists, not get_post_meta: registered defaults would make
        // the boolean read false instead of empty on untouched posts.
        return metadata_exists('post', $post_id, 'bs_sponsor_is_sponsored')
            || get_post_meta($post_id, 'bs_sponsor_advertiser_id', true) !== ''
            || get_post_meta($post_id, 'bs_sponsor_advertisement_id', true) !== ''
            || get_post_meta($post_id, Broadstreet_Sponsor_Sync::META_STATUS, true) !== '';
    }
}
