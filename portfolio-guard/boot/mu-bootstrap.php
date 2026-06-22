<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/includes/class-msp-pg-config.php';
require_once dirname(__DIR__) . '/includes/class-msp-pg-signatures.php';
require_once dirname(__DIR__) . '/includes/class-msp-pg-utils.php';
require_once dirname(__DIR__) . '/includes/class-msp-pg-runtime.php';

function msp_pg_mu_boot()
{
    MSP_PG_Runtime::boot();
}

msp_pg_mu_boot();
