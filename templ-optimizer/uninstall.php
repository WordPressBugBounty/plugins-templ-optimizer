<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's stored settings and the per-site HTTP diagnostics log
 * table on every site of the install (including each Multisite site), so no
 * potentially sensitive diagnostic records are left orphaned after removal.
 *
 * @package Templ_Optimizer
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || die();

require_once __DIR__ . '/templ-optimizer.php';

/**
 * Removes this site's plugin data: settings, schema markers and the log table.
 *
 * @return void
 */
function templ_optimizer_uninstall_site(): void {
    $http_log = new templOptimizerHttpLog();
    $http_log->disable();
    $http_log->drop_table();
    delete_option( 'templ_optimizer_settings' );
    delete_option( templOptimizerHttpLog::SCHEMA_OPTION );
    delete_option( templOptimizerHttpLog::PRUNE_OPTION );
}

if ( is_multisite() ) {
    $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( (int) $site_id );
        templ_optimizer_uninstall_site();
        restore_current_blog();
    }
} else {
    templ_optimizer_uninstall_site();
}
