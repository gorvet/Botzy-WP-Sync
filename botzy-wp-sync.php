<?php
/**
 * Plugin Name: Botzy WP Sync
 * Plugin URI:        https://www.botzy.app
 * Description: Conecta WordPress con Botzy para responder preguntas mediante búsqueda inteligente por tipo de contenido.
 * Version: 1.2.9
 * Author: Juank de Gorvet
 * Author URI:        https://api.whatsapp.com/send/?phone=5353779424
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: botzy
 * Domain Path: /languages
 * Requires at least: 5.5
 * Tested up to: 6.5
 * Requires PHP: 7.1
 * 
 * @package Botzy
 */

 
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('BOTZYWPSYNC_VERSION', get_plugin_data(__FILE__)['Version']);
define('BOTZYWPSYNC_DIR', plugin_dir_path(__FILE__));
define('BOTZYWPSYNC_URL', plugin_dir_url(__FILE__));
define('BOTZYWPSYNC_SLUG', plugin_basename(__FILE__));

define('BOTZYWPSYNC_SECRET','Z32YykRFq05uN1mTgb2W');

// Cargar traducciones
add_action('plugins_loaded', function () {
    load_plugin_textdomain('botzy', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once BOTZYWPSYNC_DIR . 'includes/class-botzywpsync-loader.php';

add_action('plugins_loaded', ['Botzywpsync_Loader', 'init']);
