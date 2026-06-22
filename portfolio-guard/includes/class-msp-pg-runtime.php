<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_Runtime
{
    private static $booted = false;

    public static function boot()
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        add_filter('option_active_plugins', array(__CLASS__, 'filter_known_active_plugins'), 1);

        self::block_known_entrypoints();
    }

    public static function filter_known_active_plugins($activePlugins)
    {
        if (!is_array($activePlugins)) {
            return $activePlugins;
        }

        $blocked = MSP_PG_Signatures::known_slugs();

        return array_values(array_filter($activePlugins, function ($plugin) use ($blocked) {
            foreach ($blocked as $slug) {
                if (strpos($plugin, $slug . '/') === 0) {
                    return false;
                }
            }

            return true;
        }));
    }

    public static function block_known_entrypoints()
    {
        foreach (MSP_PG_Signatures::backdoor_pairs() as $backdoor) {
            if (
                isset($_GET[$backdoor['id_param']], $_GET[$backdoor['token_param']]) &&
                hash_equals($backdoor['token_value'], (string) wp_unslash($_GET[$backdoor['token_param']]))
            ) {
                self::deny('Blocked known malware backdoor parameter pair: ' . $backdoor['slug']);
            }
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        foreach (MSP_PG_Signatures::route_namespaces() as $route) {
            if ($requestUri !== '' && strpos($requestUri, '/wp-json/' . $route . '/') !== false) {
                self::deny('Blocked known malware REST namespace: ' . $route);
            }
        }
    }

    private static function deny($reason)
    {
        status_header(403);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Request blocked by MSP Portfolio Guard.';

        do_action('msp_pg_runtime_blocked', $reason);

        exit;
    }
}
