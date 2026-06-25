<?php
/**
 * Plugin Name: Synthetic C2 Channel
 * Description: Validation fixture — emulates Command and Control behavioral signals.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('synthetic-c2/v1', '/config', array(
        'methods'             => 'GET',
        'callback'            => '__return_empty_array',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('synthetic-c2/v1', '/tasks', array(
        'methods'             => 'POST',
        'callback'            => '__return_empty_array',
        'permission_callback' => '__return_true',
    ));
});

add_action('init', function () {
    $response = wp_remote_get('https://example-fixture.invalid/api/config/');
    if (!is_wp_error($response)) {
        wp_remote_post('https://example-fixture.invalid/api/click', array(
            'body' => array('event' => 'init'),
        ));
    }
    wp_remote_request('https://example-fixture.invalid/api/status');
});
