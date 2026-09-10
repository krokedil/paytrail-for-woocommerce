<?php
/**
 * Shared test bootstrap, required by every suite bootstrap.
 *
 * Syncs tests/_mu-plugins/ into the install and loads tests/.env. Scaffolding the
 * install itself happens earlier, in composer's post-autoload-dump hook.
 */

if (defined('PAYTRAIL_TEST_BOOTSTRAP_DONE')) {
    return;
}
define('PAYTRAIL_TEST_BOOTSTRAP_DONE', true);

$paytrail_mu_source  = dirname(__DIR__) . '/tests/_mu-plugins';
$paytrail_mu_plugins = dirname(__DIR__) . '/tests/_wordpress/wp-content/mu-plugins';

if (is_dir($paytrail_mu_plugins) && is_dir($paytrail_mu_source)) {
    $paytrail_wanted = [];

    foreach ((array) glob($paytrail_mu_source . '/*.php') as $paytrail_src) {
        $paytrail_wanted[] = basename((string) $paytrail_src);
        $paytrail_dest     = $paytrail_mu_plugins . '/' . basename((string) $paytrail_src);
        if (! is_file($paytrail_dest) || filemtime((string) $paytrail_src) > filemtime($paytrail_dest)) {
            copy((string) $paytrail_src, $paytrail_dest);
        }
    }

    // Drop the ones an earlier checkout installed and this one no longer has, matched on
    // our own header so wp-browser's SQLite drop-in is left alone.
    foreach ((array) glob($paytrail_mu_plugins . '/*.php') as $paytrail_installed) {
        if (in_array(basename((string) $paytrail_installed), $paytrail_wanted, true)) {
            continue;
        }
        if (strpos((string) file_get_contents((string) $paytrail_installed), 'Plugin Name: Paytrail Tests,') !== false) {
            unlink((string) $paytrail_installed);
        }
    }
}

unset($paytrail_mu_source, $paytrail_mu_plugins, $paytrail_src, $paytrail_dest, $paytrail_wanted, $paytrail_installed);

// The test WP install's DB connection string and the rest of the config.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
