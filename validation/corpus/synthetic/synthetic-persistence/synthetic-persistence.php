<?php
/**
 * Plugin Name: Synthetic Persistence
 * Description: Validation fixture — emulates Persistence behavioral signals.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_filter('all_plugins', function ($plugins) {
    unset($plugins['synthetic-persistence/synthetic-persistence.php']);
    return $plugins;
});

add_action('rest_api_init', function () {
    register_rest_route('synthetic-persist/v1', '/status', array(
        'methods'             => 'GET',
        'callback'            => '__return_empty_array',
        'permission_callback' => '__return_true',
    ));
});

add_action('wp_ajax_synth_persist_heartbeat', function () {
    $ts = get_option('synth_persist_last_seen', 0);
    update_option('synth_persist_last_seen', time(), false);
    wp_send_json_success(array('last' => $ts));
});

add_action('init', function () {
    wp_remote_get(rest_url('synthetic-persist/v1/status'));
});
