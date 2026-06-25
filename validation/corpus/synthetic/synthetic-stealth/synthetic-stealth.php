<?php
/**
 * Plugin Name: Synthetic Stealth
 * Description: Validation fixture — emulates Stealth behavioral signals.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('all_plugins', function ($plugins) {
    foreach (array_keys($plugins) as $plugin_file) {
        if (strpos($plugin_file, 'synthetic-stealth') !== false) {
            unset($plugins[$plugin_file]);
        }
    }
    return $plugins;
});

add_action('rest_api_init', function () {
    register_rest_route('synthetic-stealth/v1', '/ping', array(
        'methods'             => 'POST',
        'callback'            => '__return_true',
        'permission_callback' => '__return_true',
    ));
});

add_action('wp_enqueue_scripts', function () {
    wp_register_script('synth-stealth', plugin_dir_url(__FILE__) . 'runtime.js', array(), null, true);
    wp_enqueue_script('synth-stealth');
    wp_add_inline_script('synth-stealth', '(function(){ /* stealth init */ })();');
});
