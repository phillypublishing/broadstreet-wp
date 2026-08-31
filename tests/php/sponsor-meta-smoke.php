<?php

/**
 * Focused registration and meta-box compatibility proof for sponsored content.
 */

class WP_Widget
{
}

$broadstreet_registered_meta = array();
$broadstreet_meta_boxes = array();
$broadstreet_capability_checks = array();
$broadstreet_actions = array();
$broadstreet_filters = array();
$broadstreet_meta = array();
$broadstreet_revision_ids = array(100);
$broadstreet_autosave_ids = array(101);

function __($text)
{
    return $text;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $broadstreet_actions;
    $broadstreet_actions[] = array($hook, $callback, $priority, $accepted_args);
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $broadstreet_filters;
    $broadstreet_filters[$hook][$priority][] = array($callback, $accepted_args);
}

function add_shortcode()
{
}

function apply_filters($hook, $value)
{
    global $broadstreet_filters;
    $args = func_get_args();

    if ($hook === 'broadstreet_meta_box_post_types') {
        $value = array_values(array_diff($value, array('filtered_cpt')));
        $args[1] = $value;
    }

    if (isset($broadstreet_filters[$hook])) {
        ksort($broadstreet_filters[$hook]);
        foreach ($broadstreet_filters[$hook] as $callbacks) {
            foreach ($callbacks as $registered) {
                $callback_args = array_slice($args, 1, $registered[1]);
                $value = call_user_func_array($registered[0], $callback_args);
                $args[1] = $value;
            }
        }
    }

    return $value;
}

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

function get_option($key)
{
    if ($key === 'Broadstreet_API_Key') {
        return 'fake-test-key';
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

    if ($output === 'objects') {
        return $types;
    }

    return array_keys($types);
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
    global $broadstreet_registered_meta;

    $broadstreet_registered_meta[$post_type][$meta_key] = $args;
}

function add_meta_box($id, $title, $callback, $screen, $context = 'advanced', $priority = 'default', $callback_args = null)
{
    global $broadstreet_meta_boxes;

    $broadstreet_meta_boxes[] = array(
        'id' => $id,
        'screen' => $screen,
        'callback_args' => $callback_args,
    );
}

function current_user_can($capability, $post_id = null)
{
    global $broadstreet_capability_checks;

    $broadstreet_capability_checks[] = array($capability, $post_id);
    if ($capability === 'edit_others_posts') {
        return true;
    }

    return $capability === 'edit_post' && $post_id === 42;
}

function wp_is_post_revision($post_id)
{
    global $broadstreet_revision_ids;
    return in_array($post_id, $broadstreet_revision_ids, true);
}

function wp_is_post_autosave($post_id)
{
    global $broadstreet_autosave_ids;
    return in_array($post_id, $broadstreet_autosave_ids, true);
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
require_once $plugin_root . '/Broadstreet/Core.php';

$reflection = new ReflectionClass('Broadstreet_Core');
$core = $reflection->newInstanceWithoutConstructor();
$core->registerSponsorMeta();

broadstreet_assert_same(
    true,
    apply_filters('is_protected_meta', false, 'bs_sponsor_advertisement_id', 'post'),
    'The real protected-meta filter should hide the server-owned advertisement ID from generic Custom Fields.'
);
broadstreet_assert_same(
    false,
    apply_filters('auth_post_meta_bs_sponsor_advertisement_id', true, 'bs_sponsor_advertisement_id', 42, 9, 'edit_post_meta', array()),
    'The real meta-capability filter should deny generic writes to the server-owned advertisement ID.'
);
broadstreet_assert_same(
    array('do_not_allow'),
    apply_filters('map_meta_cap', array('edit_posts'), 'edit_post_meta', 9, array(42, 'bs_sponsor_advertisement_id')),
    'The real meta-cap mapping filter should deny generic capability-based writes even when another auth filter exists.'
);
update_post_meta(42, 'bs_sponsor_advertisement_id', '901');
broadstreet_assert_same(
    '901',
    get_post_meta(42, 'bs_sponsor_advertisement_id', true),
    'Internal reconciliation should still be able to persist server-owned advertisement IDs.'
);
foreach (array(
    'bs_sponsor_advertisement_id',
    Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER,
) as $server_meta_key) {
    broadstreet_assert_same(
        true,
        apply_filters('is_protected_meta', false, $server_meta_key, 'post'),
        'Every server-owned sponsor key should be hidden from generic Custom Fields.'
    );
    broadstreet_assert_same(
        false,
        apply_filters('auth_post_meta_' . $server_meta_key, true, $server_meta_key, 42, 9, 'edit_post_meta', array()),
        'Every server-owned sponsor key should deny generic meta writes.'
    );
    broadstreet_assert_same(
        array('do_not_allow'),
        apply_filters('map_meta_cap', array('edit_posts'), 'edit_post_meta', 9, array(42, $server_meta_key)),
        'Every server-owned sponsor key should deny capability-mapped writes.'
    );
}
update_post_meta(42, Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER, '55');
broadstreet_assert_same(
    '55',
    get_post_meta(42, Broadstreet_Sponsor_Sync::META_REMOTE_ADVERTISER, true),
    'Internal synchronization should still be able to persist remote-advertiser stamps.'
);

broadstreet_assert_same(
    array('_edit_lock', 'bs_sponsor_advertisement_id', '_bs_sponsor_*'),
    $core->excludeSponsorMetaFromDuplication(array('_edit_lock')),
    'Yoast Duplicate Post copies must never inherit server-owned tracker or sync state.'
);
broadstreet_assert_same(
    array('bs_sponsor_advertisement_id', '_bs_sponsor_*'),
    $core->excludeSponsorMetaFromDuplication(null),
    'The duplication excludelist filter should tolerate a non-array input.'
);

broadstreet_assert_same(
    array('post', 'page', 'story'),
    array_keys($broadstreet_registered_meta),
    'Only block-editor, REST, custom-fields, and revisions capable post types should receive shared sponsor meta.'
);

$rest_after_insert_hooks = array_values(array_filter($broadstreet_actions, function ($action) {
    return strpos($action[0], 'rest_after_insert_') === 0;
}));
broadstreet_assert_same(
    array('rest_after_insert_post', 'rest_after_insert_page', 'rest_after_insert_story'),
    array($rest_after_insert_hooks[0][0], $rest_after_insert_hooks[1][0], $rest_after_insert_hooks[2][0]),
    'Reconciliation hooks should be registered only for the exact eligible post types.'
);
broadstreet_assert_same(20, $rest_after_insert_hooks[0][2], 'REST reconciliation should run after registered meta is persisted.');
broadstreet_assert_same(3, $rest_after_insert_hooks[0][3], 'REST reconciliation should receive post, request, and create state.');

foreach (array('post', 'page', 'story') as $post_type) {
    $registered = $broadstreet_registered_meta[$post_type];
    broadstreet_assert_same(
        array('bs_sponsor_is_sponsored', 'bs_sponsor_advertiser_id'),
        array_keys($registered),
        'The historical editor-owned keys should be registered without exposing server-owned state.'
    );

    broadstreet_assert_same('boolean', $registered['bs_sponsor_is_sponsored']['type'], 'The sponsorship toggle should be boolean.');
    broadstreet_assert_true($registered['bs_sponsor_is_sponsored']['single'], 'The sponsorship toggle should be single meta.');
    broadstreet_assert_true($registered['bs_sponsor_is_sponsored']['show_in_rest'], 'The sponsorship toggle should be REST-visible.');
    broadstreet_assert_same(
        false,
        isset($registered['bs_sponsor_is_sponsored']['revisions_enabled']),
        'The sponsorship toggle must not be revisioned; restoring an old revision must not wipe it.'
    );

    broadstreet_assert_same('string', $registered['bs_sponsor_advertiser_id']['type'], 'Advertiser IDs should stay opaque strings.');
    broadstreet_assert_true($registered['bs_sponsor_advertiser_id']['single'], 'Advertiser IDs should be single meta.');
    broadstreet_assert_same(
        false,
        isset($registered['bs_sponsor_advertiser_id']['revisions_enabled']),
        'Advertiser IDs must not be revisioned; restoring an old revision must not wipe them.'
    );
    broadstreet_assert_same(
        '^(?:[1-9][0-9]*)?$',
        $registered['bs_sponsor_advertiser_id']['show_in_rest']['schema']['pattern'],
        'Advertiser IDs should accept only an unset value or positive digits.'
    );

    broadstreet_assert_true(
        call_user_func($registered['bs_sponsor_is_sponsored']['auth_callback'], false, 'bs_sponsor_is_sponsored', 42),
        'Meta authorization should allow users who can edit the concrete post.'
    );
    broadstreet_assert_same(
        false,
        call_user_func($registered['bs_sponsor_advertiser_id']['auth_callback'], false, 'bs_sponsor_advertiser_id', 7),
        'Meta authorization should deny users who cannot edit the concrete post.'
    );
}

broadstreet_assert_same(
    array(
        array('edit_post', 42),
        array('edit_others_posts', null),
        array('edit_post', 7),
        array('edit_post', 42),
        array('edit_others_posts', null),
        array('edit_post', 7),
        array('edit_post', 42),
        array('edit_others_posts', null),
        array('edit_post', 7),
    ),
    $broadstreet_capability_checks,
    'Authorization must use edit_post with the actual object ID plus the sponsorship-management capability.'
);

$core->addMetaBoxes();
$sponsor_boxes = array_values(array_filter($broadstreet_meta_boxes, function ($box) {
    return $box['id'] === 'broadstreet_sposnor_sectionid';
}));

broadstreet_assert_same(7, count($sponsor_boxes), 'The misspelled historical sponsor box ID must remain registered on every configured legacy screen.');

foreach ($sponsor_boxes as $box) {
    if (in_array($box['screen'], array('post', 'page', 'story'), true)) {
        broadstreet_assert_same(
            array('__back_compat_meta_box' => true),
            $box['callback_args'],
            'Eligible block-editor post types should hide the legacy box without breaking Classic Editor.'
        );
    } else {
        broadstreet_assert_same(
            null,
            $box['callback_args'],
            'Unsupported and classic-only post types should retain the legacy meta-box/blockade behavior.'
        );
    }
}

class Broadstreet_Test_Sponsor_Request
{
    protected $route;

    public function __construct($route)
    {
        $this->route = $route;
    }

    public function get_route()
    {
        return $this->route;
    }
}

class Broadstreet_Test_Sponsor_Core extends Broadstreet_Core
{
    public $synced = array();

    public function syncSponsorPost($post_id)
    {
        $this->synced[] = array(
            $post_id,
            get_post_meta($post_id, 'bs_sponsor_advertiser_id', true),
        );
    }
}

$test_reflection = new ReflectionClass('Broadstreet_Test_Sponsor_Core');
$test_core = $test_reflection->newInstanceWithoutConstructor();
$test_core->synced = array();
$test_core->reconcileSponsorRestPost((object) array('ID' => 100), new Broadstreet_Test_Sponsor_Request('/wp/v2/posts/100'), false);
$test_core->reconcileSponsorRestPost((object) array('ID' => 101), new Broadstreet_Test_Sponsor_Request('/wp/v2/posts/101'), false);
$test_core->reconcileSponsorRestPost((object) array('ID' => 42), new Broadstreet_Test_Sponsor_Request('/wp/v2/posts/42/autosaves'), false);
$test_core->reconcileSponsorRestPost((object) array('ID' => 42), new Broadstreet_Test_Sponsor_Request('/wp/v2/posts/42'), false);
broadstreet_assert_same(array(array(42, '')), $test_core->synced, 'REST saves should synchronize while revisions and autosaves remain side-effect free.');

echo "Sponsor meta registration smoke test passed.\n";
