<?php
/*
Plugin Name: Broadstreet
Plugin URI: http://broadstreetads.com
Description: Integrate Broadstreet business directory and adserving power into your site
Version: 1.53.7
Tested up to: 6.9
Requires at least: 6.4
Author: Broadstreet
Author URI: http://broadstreetads.com
*/

require dirname(__FILE__) . '/Broadstreet/Core.php';

# Start the beast
$engine = new Broadstreet_Core;
$engine->execute();
