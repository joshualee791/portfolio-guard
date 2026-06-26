<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_PluginUpdater
{
    const CHECK_INTERVAL = 43200; // 12 hours in seconds

    public static function register()
    {
        add_action('admin_init', array('MSP_PG_PluginUpdater', 'maybe_check'));
        add_filter('pre_set_site_transient_update_plugins', array('MSP_PG_PluginUpdater', 'inject'));
        add_filter('plugins_api', array('MSP_PG_PluginUpdater', 'api'), 10, 3);
    }

    public static function maybe_check()
    {
        $lastChecked = (int) get_option('msp_pg_plugin_update_last_checked', 0);
        if ((time() - $lastChecked) < self::CHECK_INTERVAL) {
            return;
        }

        self::check();
    }

    public static function check()
    {
        $response = wp_remote_get(MSP_PG_Config::plugin_update_url(), array(
            'sslverify'  => true,
            'timeout'    => 10,
            'user-agent' => 'MSP-PortfolioGuard/' . MSP_PG_VERSION,
        ));

        update_option('msp_pg_plugin_update_last_checked', time(), false);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }

        $body = @json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['version']) || empty($body['download_url'])) {
            return;
        }

        update_option('msp_pg_plugin_update_cache', $body, false);
    }

    public static function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $cache = get_option('msp_pg_plugin_update_cache', array());
        if (empty($cache['version']) || empty($cache['download_url'])) {
            return $transient;
        }

        if (strncmp($cache['download_url'], 'https://', 8) !== 0) {
            return $transient;
        }

        $pluginBasename = plugin_basename(MSP_PG_PLUGIN_FILE);
        $info = (object) array(
            'id'            => 'msp-portfolio-guard/portfolio-guard',
            'slug'          => 'portfolio-guard',
            'plugin'        => $pluginBasename,
            'new_version'   => $cache['version'],
            'url'           => MSP_PG_Config::plugin_update_url(),
            'package'       => $cache['download_url'],
            'requires'      => isset($cache['requires'])     ? $cache['requires']     : '5.0',
            'requires_php'  => isset($cache['requires_php']) ? $cache['requires_php'] : '7.4',
            'tested'        => isset($cache['tested'])       ? $cache['tested']       : '',
        );

        if (version_compare($cache['version'], MSP_PG_VERSION, '>')) {
            if (!isset($transient->response)) {
                $transient->response = array();
            }
            $transient->response[$pluginBasename] = $info;
        } else {
            if (!isset($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$pluginBasename] = $info;
        }

        return $transient;
    }

    public static function api($result, $action, $args)
    {
        if (!isset($args->slug) || $args->slug !== 'portfolio-guard') {
            return $result;
        }

        $cache = get_option('msp_pg_plugin_update_cache', array());
        $version = !empty($cache['version']) ? $cache['version'] : MSP_PG_VERSION;
        $downloadUrl = !empty($cache['download_url']) ? $cache['download_url'] : '';
        $changelog   = !empty($cache['changelog'])    ? $cache['changelog']    : 'See readme.txt for changelog.';

        $info = new stdClass();
        $info->name          = 'MSP Portfolio Guard';
        $info->slug          = 'portfolio-guard';
        $info->version       = $version;
        $info->download_link = $downloadUrl;
        $info->sections      = array('changelog' => $changelog);

        return $info;
    }
}
