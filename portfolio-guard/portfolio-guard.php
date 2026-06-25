<?php
/**
 * Plugin Name: MSP Portfolio Guard
 * Description: Autonomous family-specific malware remediation with metadata-first evidence retention and reporting.
 * Version: 1.5.6
 * Author: My Social Practice
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MSP_PG_VERSION', '1.5.6');
define('MSP_PG_PLUGIN_FILE', __FILE__);
define('MSP_PG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MSP_PG_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-config.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-signatures.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-utils.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-feature-extractor.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-behavior-classifier.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-detector.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-runtime.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-remediator.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-update-verifier.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-updater.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-update-scheduler.php';
require_once MSP_PG_PLUGIN_DIR . 'includes/class-msp-pg-plugin.php';

register_activation_hook(MSP_PG_PLUGIN_FILE, array('MSP_PG_Plugin', 'activate'));
register_activation_hook(MSP_PG_PLUGIN_FILE, array('MSP_PG_UpdateScheduler', 'activate'));
register_deactivation_hook(MSP_PG_PLUGIN_FILE, array('MSP_PG_Plugin', 'deactivate'));
register_deactivation_hook(MSP_PG_PLUGIN_FILE, array('MSP_PG_UpdateScheduler', 'deactivate'));
register_uninstall_hook(MSP_PG_PLUGIN_FILE, array('MSP_PG_Plugin', 'uninstall'));

add_action('plugins_loaded', array('MSP_PG_UpdateScheduler', 'init'));
MSP_PG_Plugin::instance();
