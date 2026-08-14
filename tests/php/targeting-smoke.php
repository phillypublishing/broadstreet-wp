<?php

/**
 * Characterization proof for Broadstreet's page and user targeting payloads.
 */

class Broadstreet_Core
{
    const KEY_NETWORK_ID = 'Broadstreet_Network_Key';
    const KEY_PLACEMENTS = 'Broadstreet_Placements';
}

$broadstreet_targeting_logged_in = false;
$broadstreet_targeting_post = (object) array(
    'ID' => 42,
    'post_name' => '',
);
$post = $broadstreet_targeting_post;

function get_option($key)
{
    if ($key === Broadstreet_Core::KEY_NETWORK_ID) {
        return '123';
    }

    if ($key === Broadstreet_Core::KEY_PLACEMENTS) {
        return (object) array(
            'beta_tag_arguments' => '{}',
        );
    }

    return false;
}

function is_single()
{
    return true;
}

function is_page()
{
    return false;
}

function is_archive()
{
    return false;
}

function is_category()
{
    return false;
}

function is_front_page()
{
    return false;
}

function get_queried_object_id()
{
    return 42;
}

function wp_get_post_categories($post_id)
{
    return $post_id === 42 ? array(7) : array();
}

function get_category($category)
{
    return (object) array(
        'slug' => $category === 7 ? 'news' : 'parent-news',
        'category_parent' => 0,
    );
}

function wp_get_post_tags($post_id)
{
    return $post_id === 42
        ? array((object) array('slug' => 'feature'))
        : array();
}

function get_tag($tag)
{
    return $tag;
}

function get_post_type()
{
    return 'post';
}

function get_permalink()
{
    return 'https://example.test/story-42';
}

function is_user_logged_in()
{
    global $broadstreet_targeting_logged_in;

    return $broadstreet_targeting_logged_in;
}

function wp_get_current_user()
{
    return (object) array(
        'roles' => array('editor'),
    );
}

function broadstreet_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function broadstreet_assert_contains($needle, $haystack, $message)
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException(
            $message . "\nMissing: " . var_export($needle, true) . "\nActual: " . var_export($haystack, true)
        );
    }
}

$plugin_root = dirname(dirname(__DIR__));
require_once $plugin_root . '/Broadstreet/Utility.php';

broadstreet_assert_same(
    array('news', 'feature', 'post-42', 'post'),
    Broadstreet_Utility::getAllAdSlugs(),
    'Targeting should include category, tag, stable post ID, and post type while omitting an empty post slug.'
);

$logged_out_targets = Broadstreet_Utility::getTargets();
broadstreet_assert_same(
    array('post', 'not_home_page'),
    $logged_out_targets['pagetype'],
    'Structured targets should preserve the page-type contract.'
);
broadstreet_assert_same(
    array('news', 'feature', 'post-42', 'post'),
    $logged_out_targets['category'],
    'Structured targets should contain the expanded taxonomy and post identifiers.'
);
broadstreet_assert_same('story-42', $logged_out_targets['url'], 'Structured targets should contain the permalink basename.');

$broadstreet_targeting_logged_in = true;
$keywords = Broadstreet_Utility::getAllAdKeywordsString(true);
broadstreet_assert_contains('is_logged_in', $keywords, 'Logged-in visitors should receive the login keyword.');
broadstreet_assert_contains('editor', $keywords, 'Logged-in visitors should receive each WordPress role as a keyword.');
broadstreet_assert_contains('feature', $keywords, 'Tag slugs should flow into the legacy keyword payload.');
broadstreet_assert_contains('post-42', $keywords, 'A stable post-ID keyword should flow into the legacy payload.');

$init_code = Broadstreet_Utility::getInitCode();
broadstreet_assert_contains(
    'window.broadstreetKeywords = window.broadstreetKeywords ?? []',
    $init_code,
    'Initialization should preserve a keyword array created before Broadstreet runs.'
);
broadstreet_assert_contains(
    'window.broadstreetKeywords = [...window.broadstreetKeywords, ',
    $init_code,
    'Initialization should append the current page keywords instead of replacing existing values.'
);
broadstreet_assert_contains("'is_logged_in','editor'", $init_code, 'Generated initialization should include login and role keywords.');
broadstreet_assert_contains('"feature","post-42","post"', $init_code, 'Generated structured targets should include tag and post identifiers.');

echo "Broadstreet targeting smoke test passed.\n";
