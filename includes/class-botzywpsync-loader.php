<?php
defined('ABSPATH') || exit;

class Botzywpsync_Loader {

    public static function init() {
        require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-settings.php';
        require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-rest-api.php';
        require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-search.php';
        require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-utils.php';
        require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-visibility.php';
        require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-update.php';

        // Iniciar API
        add_action('rest_api_init', ['Botzywpsync_REST_API', 'register_routes']);

        // Iniciar ajustes de admin
        if (is_admin()) {
            Botzywpsync_Settings::init();
        }
        Botzywpsync_Visibility::init();
        Botzywpsync_Update::init();
    }
}

