<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package    BotzyConnector
 */

// Asegurar que es una llamada legítima desde WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Puedes evitar que se borren los datos usando:
 * add_filter( 'botzy_connector_delete_all', '__return_false' );
 */
if ( apply_filters( 'botzy_connector_delete_all', true ) ) {
    delete_option('botzy_api_key');
    delete_option('botzy_enabled_types');
    delete_option('botzy_shared_fields');
    delete_option('botzy_allow_private');
    delete_option('botzy_output_limit');
    delete_option('botzy_fuzzy_scan_limit');
    delete_option('botzy_fuzzy_threshold');
    delete_option('botzy_fuzzy_limit');

    // Limpieza de cache
    wp_cache_flush();
}
