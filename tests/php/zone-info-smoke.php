<?php

/**
 * Focused REST and editor-compatibility proof for read-only Zone Info.
 */

class WP_Widget
{
}

class WP_Error
{
    protected $code;
    protected $message;
    protected $data;

    public function __construct($code, $message, $data = array())
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

class WP_REST_Response
{
    protected $data;
    public $headers = array();

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function header($name, $value)
    {
        $this->headers[$name] = $value;
    }
}

class Broadstreet_Test_Zone_Request implements ArrayAccess
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return isset($this->data[$offset]) ? $this->data[$offset] : null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->data[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }
}

$broadstreet_zone_actions = array();
$broadstreet_zone_routes = array();
$broadstreet_zone_post_types = array(
    42 => 'post',
    43 => 'page',
    44 => 'bs_business',
    45 => 'story',
    99 => 'post',
);
$broadstreet_zone_editable_posts = array(42, 43, 44, 45);

function __($text)
{
    return $text;
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $broadstreet_zone_actions;
    $broadstreet_zone_actions[] = array($hook, $callback, $priority, $accepted_args);
}

function add_filter()
{
}

function add_shortcode()
{
}

function apply_filters($hook, $value)
{
    return $value;
}

function get_option()
{
    return false;
}

function get_post_type($post_id)
{
    global $broadstreet_zone_post_types;
    return isset($broadstreet_zone_post_types[$post_id])
        ? $broadstreet_zone_post_types[$post_id]
        : false;
}

function current_user_can($capability, $post_id = null)
{
    global $broadstreet_zone_editable_posts;
    return $capability === 'edit_post'
        && in_array($post_id, $broadstreet_zone_editable_posts, true);
}

function absint($value)
{
    return abs((int) $value);
}

function register_rest_route($namespace, $route, $args)
{
    global $broadstreet_zone_routes;
    $broadstreet_zone_routes[$namespace . $route] = $args;
}

function rest_ensure_response($data)
{
    return new WP_REST_Response($data);
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

class Broadstreet_Test_Zone_Core extends Broadstreet_Core
{
    public $zone_cache = array();
    public $zone_exception = null;

    public function getEditorZoneCache()
    {
        if ($this->zone_exception) {
            throw $this->zone_exception;
        }

        return $this->zone_cache;
    }
}

$reflection = new ReflectionClass('Broadstreet_Test_Zone_Core');
$core = $reflection->newInstanceWithoutConstructor();
$core->execute();

$zone_route_hooks = array_values(array_filter($broadstreet_zone_actions, function ($action) {
    return $action[0] === 'rest_api_init'
        && is_array($action[1])
        && $action[1][1] === 'registerZoneRoutes';
}));
broadstreet_assert_same(1, count($zone_route_hooks), 'The private Zone Info route should be registered once.');

$core->registerZoneRoutes();
broadstreet_assert_same(
    array('broadstreet/v1/zones'),
    array_keys($broadstreet_zone_routes),
    'Zone Info should add one narrow REST resource.'
);

$route = $broadstreet_zone_routes['broadstreet/v1/zones'];
broadstreet_assert_same('GET', $route['methods'], 'Zone enumeration must be read-only.');
broadstreet_assert_same(true, $route['args']['post_id']['required'], 'Zone requests require a concrete post.');
broadstreet_assert_same('integer', $route['args']['post_id']['type'], 'Zone post IDs should be integers.');
broadstreet_assert_same(1, $route['args']['post_id']['minimum'], 'Zone post IDs must be positive.');

foreach (array(42, 43) as $allowed_id) {
    broadstreet_assert_same(
        true,
        call_user_func($route['permission_callback'], new Broadstreet_Test_Zone_Request(array('post_id' => $allowed_id))),
        'An editor of a post or page should be allowed to read Zone Info.'
    );
}

foreach (array(0, 44, 45, 99) as $denied_id) {
    broadstreet_assert_same(
        false,
        call_user_func($route['permission_callback'], new Broadstreet_Test_Zone_Request(array('post_id' => $denied_id))),
        'Only editable post/page objects should expose Zone Info.'
    );
}

$core->zone_cache = array(
    20 => (object) array(
        'id' => '20',
        'name' => 'Zulu <script>',
        'alias' => 'private-alias',
        'access_token' => 'do-not-leak',
    ),
    3 => (object) array(
        'id' => 3,
        'name' => 'Alpha & Sons',
        'width' => 300,
    ),
    0 => (object) array('id' => '0', 'name' => 'Invalid zero'),
    5 => (object) array('id' => 'not-an-id', 'name' => 'Invalid text'),
);

$response = call_user_func(
    $route['callback'],
    new Broadstreet_Test_Zone_Request(array('post_id' => 42))
);
$zones = $response->get_data();
broadstreet_assert_same(
    array(
        array(
            'id' => '3',
            'name' => 'Alpha & Sons',
            'shortcode' => '[broadstreet zone="3"]',
        ),
        array(
            'id' => '20',
            'name' => 'Zulu <script>',
            'shortcode' => '[broadstreet zone="20"]',
        ),
    ),
    $zones,
    'Zone responses should be sorted and contain only exact display values.'
);
broadstreet_assert_same(false, strpos(json_encode($zones), 'access_token'), 'Zone responses must never expose credentials.');
broadstreet_assert_same(false, strpos(json_encode($zones), 'private-alias'), 'Zone responses must not expose unrelated zone fields.');
broadstreet_assert_same('no-store, private', $response->headers['Cache-Control'], 'Zone catalogs should not be browser-cached.');
broadstreet_assert_same('no-cache', $response->headers['Pragma'], 'Legacy caches should not retain zone catalogs.');

$core->zone_cache = array();
$empty = call_user_func(
    $route['callback'],
    new Broadstreet_Test_Zone_Request(array('post_id' => 42))
);
broadstreet_assert_same(array(), $empty->get_data(), 'Empty or unconfigured catalogs should remain a safe empty list.');

$core->zone_exception = new RuntimeException('access_token=raw-secret cache failure');
$error = call_user_func(
    $route['callback'],
    new Broadstreet_Test_Zone_Request(array('post_id' => 42))
);
broadstreet_assert_same(true, $error instanceof WP_Error, 'Zone cache failures should become fixed REST errors.');
broadstreet_assert_same('broadstreet_zones_unavailable', $error->get_error_code(), 'Unexpected Zone Info error code.');
broadstreet_assert_same(502, $error->get_error_data()['status'], 'Zone cache failures should use a gateway error status.');
broadstreet_assert_same(
    'Broadstreet zones could not be loaded. Try again.',
    $error->get_error_message(),
    'Zone failures should provide fixed credential-free guidance.'
);
broadstreet_assert_same(false, strpos($error->get_error_message(), 'raw-secret'), 'Zone errors must not expose raw failures.');

$zones = array(
    3 => (object) array('id' => '3', 'name' => 'Alpha & Sons'),
);
ob_start();
include $plugin_root . '/Broadstreet/Views/admin/infoBox.php';
$classic_html = ob_get_clean();
broadstreet_assert_same(true, strpos($classic_html, 'Alpha &amp; Sons') !== false, 'Classic Zone Info should retain escaped zone names.');
broadstreet_assert_same(true, strpos($classic_html, '[broadstreet zone="3"]') !== false, 'Classic Zone Info should retain exact shortcodes.');
broadstreet_assert_same(true, strpos($classic_html, 'admin.php?page=Broadstreet-Zone-Options') !== false, 'Classic Zone Info should retain its settings link.');

$zones = array();
ob_start();
include $plugin_root . '/Broadstreet/Views/admin/infoBox.php';
$classic_empty_html = ob_get_clean();
broadstreet_assert_same(
    true,
    strpos($classic_empty_html, "Broadstreet isn't configured correctly") !== false,
    'Classic Zone Info should retain empty and unconfigured guidance.'
);

echo "Zone Info REST and compatibility smoke test passed.\n";
