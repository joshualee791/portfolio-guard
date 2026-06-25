<?php

if (!defined('ABSPATH')) {
    exit;
}

class MSP_PG_UpdateScheduler
{
    const HOOK     = 'msp_pg_run_update_check';
    const RECUR    = 'msp_pg_six_hours';
    const INTERVAL = 21600;

    /**
     * Register the custom recurrence and the action handler.
     * Called on every page load via plugins_loaded.
     */
    public static function init()
    {
        add_filter('cron_schedules', array('MSP_PG_UpdateScheduler', 'add_recurrence'));
        add_action(self::HOOK, array('MSP_PG_Updater', 'run'));
    }

    /**
     * Called on plugin activation: schedule the recurring event and queue an
     * immediate first check (60 seconds after activation).
     */
    public static function activate()
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), self::RECUR, self::HOOK);
        }
        wp_schedule_single_event(time() + 60, self::HOOK);
    }

    /**
     * Called on plugin deactivation: remove all scheduled update events.
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Registers the six-hour recurrence interval with WordPress cron.
     */
    public static function add_recurrence(array $schedules)
    {
        $schedules[self::RECUR] = array(
            'interval' => self::INTERVAL,
            'display'  => 'Every Six Hours',
        );
        return $schedules;
    }
}
