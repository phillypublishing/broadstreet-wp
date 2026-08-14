<?php

/**
 * Focused registration and meta-box compatibility proof for Disable Ads.
 */

class WP_Widget
{
}

$broadstreet_visibility_registered_meta = array();
$broadstreet_visibility_meta_boxes = array();
$broadstreet_visibility_capability_checks = array();
$broadstreet_visibility_actions = array();

function __($text)
{
    return $text;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $broadstreet_visibility_actions;
    $broadstreet_visibility_actions[] = array($hook, $callback, $priority, $accepted_args);
}

function add_filter()
{
}

function add_shortcode()
{
}

function apply_filters($hook, $value)
{
    if ($hook === 'broadstreet_meta_box_post_types') {
        return array_values(array_diff($value, array('filtered_cpt')));
    }

    return $value;
}

function get_option($key = null)
{
    if ($key === 'Broadstreet_Biz_Enabled') {
        return true;
    }

    return false;
}

function get_post_types($args = array(), $output = 'names')
{
    $types = array(
        'post' => (object) array('name' => 'post', 'show_in_rest' => true),
        'page' => (object) array('name' => 'page', 'show_in_rest' => true),
        'classic_only' => (object) array('name' => 'classic_only', 'show_in_rest' => true),
        'no_custom_fields' => (object) array('name' => 'no_custom_fields', 'show_in_rest' => true),
        'no_revisions' => (object) array('name' => 'no_revisions', 'show_in_rest' => true),
        'legacy_restless' => (object) array('name' => 'legacy_restless', 'show_in_rest' => false),
        'filtered_cpt' => (object) array('name' => 'filtered_cpt', 'show_in_rest' => true),
        'story' => (object) array('name' => 'story', 'show_in_rest' => true),
    );

    if (isset($args['show_in_rest']) && $args['show_in_rest']) {
        $types = array_filter($types, function ($type) {
            return $type->show_in_rest;
        });
    }

    return $output === 'objects' ? $types : array_keys($types);
}

function post_type_supports($post_type, $feature)
{
    if ($feature === 'editor') {
        return true;
    }

    if ($feature === 'custom-fields') {
        return $post_type !== 'no_custom_fields';
    }

    if ($feature === 'revisions') {
        return $post_type !== 'no_revisions';
    }

    return false;
}

function use_block_editor_for_post_type($post_type)
{
    return $post_type !== 'classic_only';
}

function register_post_meta($post_type, $meta_key, $args)
{
    global $broadstreet_visibility_registered_meta;
    $broadstreet_visibility_registered_meta[$post_type][$meta_key] = $args;
}

function add_meta_box($id, $title, $callback, $screen, $context = 'advanced', $priority = 'default', $callback_args = null)
{
    global $broadstreet_visibility_meta_boxes;
    $broadstreet_visibility_meta_boxes[] = array(
        'id' => $id,
        'screen' => $screen,
        'callback' => $callback,
        'callback_args' => $callback_args,
    );
}

function current_user_can($capability, $post_id = null)
{
    global $broadstreet_visibility_capability_checks;
    $broadstreet_visibility_capability_checks[] = array($capability, $post_id);
    return $capability === 'edit_post' && $post_id === 42;
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

$reflection = new ReflectionClass('Broadstreet_Core');
$core = $reflection->newInstanceWithoutConstructor();
$core->execute();

$visibility_init_hooks = array_values(array_filter($broadstreet_visibility_actions, function ($action) {
    return $action[0] === 'init'
        && is_array($action[1])
        && $action[1][1] === 'registerAdVisibilityMeta';
}));
broadstreet_assert_same(1, count($visibility_init_hooks), 'Visibility meta registration should be attached to init once.');
broadstreet_assert_same(20, $visibility_init_hooks[0][2], 'Visibility meta should register after post types are available.');

$core->registerAdVisibilityMeta();

broadstreet_assert_same(
    array('post', 'page', 'story'),
    array_keys($broadstreet_visibility_registered_meta),
    'Only configured block-editor, REST, custom-fields, and revisions capable post types should receive visibility meta.'
);

foreach (array('post', 'page', 'story') as $post_type) {
    $registered = $broadstreet_visibility_registered_meta[$post_type]['bs_ads_disabled'];
    broadstreet_assert_same('boolean', $registered['type'], 'Disable Ads should be typed as boolean meta.');
    broadstreet_assert_same(true, $registered['single'], 'Disable Ads should be single meta.');
    broadstreet_assert_same(false, $registered['default'], 'Disable Ads should default to false.');
    broadstreet_assert_same(true, $registered['show_in_rest'], 'Disable Ads should be REST-visible.');
    broadstreet_assert_same(true, $registered['revisions_enabled'], 'Disable Ads should be revisioned.');
    broadstreet_assert_same(
        true,
        call_user_func($registered['sanitize_callback'], '1'),
        'Historical string 1 should sanitize to true.'
    );
    broadstreet_assert_same(
        false,
        call_user_func($registered['sanitize_callback'], ''),
        'Historical empty values should sanitize to false.'
    );
    broadstreet_assert_same(
        true,
        call_user_func($registered['auth_callback'], false, 'bs_ads_disabled', 42),
        'REST writes should allow users who can edit the concrete post.'
    );
    broadstreet_assert_same(
        false,
        call_user_func($registered['auth_callback'], true, 'bs_ads_disabled', 7),
        'REST writes should deny users who cannot edit the concrete post.'
    );
}

foreach (array(true, 1, '1', 'true') as $truthy) {
    broadstreet_assert_same(true, $core->sanitizeAdVisibilityBoolean($truthy), 'Expected a supported true value.');
}

foreach (array(false, 0, '0', '', 'false', 'yes', array(), null) as $falsey) {
    broadstreet_assert_same(false, $core->sanitizeAdVisibilityBoolean($falsey), 'Unexpected true value.');
}

broadstreet_assert_same(
    array(
        array('edit_post', 42),
        array('edit_post', 7),
        array('edit_post', 42),
        array('edit_post', 7),
        array('edit_post', 42),
        array('edit_post', 7),
    ),
    $broadstreet_visibility_capability_checks,
    'Authorization should always use edit_post with the concrete object ID.'
);

$core->addMetaBoxes();
$visibility_boxes = array_values(array_filter($broadstreet_visibility_meta_boxes, function ($box) {
    return $box['id'] === 'broadstreet_visibility_sectionid';
}));

broadstreet_assert_same(7, count($visibility_boxes), 'The historical visibility box should remain registered on every configured screen.');

foreach ($visibility_boxes as $box) {
    if (in_array($box['screen'], array('post', 'page', 'story'), true)) {
        broadstreet_assert_same(
            array('__back_compat_meta_box' => true),
            $box['callback_args'],
            'Eligible block-editor screens should omit the legacy visibility box from Gutenberg.'
        );
    } else {
        broadstreet_assert_same(
            null,
            $box['callback_args'],
            'Classic and unsupported screens should retain the legacy visibility box.'
        );
    }
}

$zone_info_boxes = array_values(array_filter($broadstreet_visibility_meta_boxes, function ($box) {
    return $box['id'] === 'broadstreet_sectionid'
        && is_array($box['callback'])
        && $box['callback'][1] === 'broadstreetInfoBox';
}));
broadstreet_assert_same(2, count($zone_info_boxes), 'Zone Info should retain exactly its post and page registrations.');
foreach ($zone_info_boxes as $box) {
    broadstreet_assert_same(
        array('__back_compat_meta_box' => true),
        $box['callback_args'],
        'Only the converted post/page Zone Info callbacks should be hidden from Gutenberg.'
    );
}

$business_boxes = array_values(array_filter($broadstreet_visibility_meta_boxes, function ($box) {
    return $box['id'] === 'broadstreet_sectionid'
        && is_array($box['callback'])
        && $box['callback'][1] === 'broadstreetBusinessBox';
}));
broadstreet_assert_same(1, count($business_boxes), 'The same-ID Business Details box should remain registered.');
broadstreet_assert_same('bs_business', $business_boxes[0]['screen'], 'Business Details should remain scoped to bs_business.');
broadstreet_assert_same(null, $business_boxes[0]['callback_args'], 'Business Details must not be hidden as a converted Zone Info box.');

echo "Ad visibility meta registration smoke test passed.\n";
