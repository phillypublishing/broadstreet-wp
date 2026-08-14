<?php

/**
 * Read-only, post-scoped REST delivery for the cached Broadstreet zone catalog.
 */
class Broadstreet_Zone_Controller
{
    protected $core;

    public function __construct($core)
    {
        $this->core = $core;
    }

    public function registerRoutes()
    {
        register_rest_route('broadstreet/v1', '/zones', array(
            'methods' => 'GET',
            'callback' => array($this->core, 'getEditorZones'),
            'permission_callback' => array($this->core, 'canEditZonePost'),
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'minimum' => 1,
                ),
            ),
        ));
    }

    public function canEditPost($request)
    {
        $post_id = absint($request['post_id']);
        return $post_id > 0
            && in_array(get_post_type($post_id), array('post', 'page'), true)
            && current_user_can('edit_post', $post_id);
    }

    public function getZones($request)
    {
        try {
            $zones = $this->core->getEditorZoneCache();
        } catch (Throwable $exception) {
            return new WP_Error(
                'broadstreet_zones_unavailable',
                'Broadstreet zones could not be loaded. Try again.',
                array('status' => 502)
            );
        }

        $response = array();
        foreach (is_array($zones) ? $zones : array() as $zone) {
            $id = is_object($zone) && isset($zone->id) && is_scalar($zone->id)
                ? (string) $zone->id
                : '';
            if (!preg_match('/^[1-9][0-9]*$/D', $id)
                || !isset($zone->name)
                || !is_scalar($zone->name)) {
                continue;
            }

            $response[] = array(
                'id' => $id,
                'name' => (string) $zone->name,
                'shortcode' => '[broadstreet zone="' . $id . '"]',
            );
        }

        usort($response, function ($left, $right) {
            return strcasecmp($left['name'], $right['name']);
        });

        return $this->restResponse($response);
    }

    protected function restResponse($data)
    {
        $response = rest_ensure_response($data);
        $response->header('Cache-Control', 'no-store, private');
        $response->header('Pragma', 'no-cache');
        return $response;
    }
}
