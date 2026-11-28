<?php
defined('ABSPATH') || exit;

class Botzywpsync_Settings {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_init', [__CLASS__, 'maybe_generate_api_key']);
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('wp_ajax_botzy_regenerate_apikey', [__CLASS__, 'ajax_regenerate_apikey']);
    }

    public static function register_settings() {
        register_setting('botzy_settings_group', 'botzy_allow_private', ['sanitize_callback' => 'rest_sanitize_boolean']);
        register_setting('botzy_settings_group', 'botzy_enabled_types', ['sanitize_callback' => static function ($value) {
            return array_values(array_intersect(array_keys(Botzywpsync_REST_API::available_types()), (array) $value));
        }]);
        register_setting('botzy_settings_group', 'botzy_fuzzy_threshold', ['sanitize_callback' => 'intval']);
        register_setting('botzy_settings_group', 'botzy_fuzzy_scan_limit', ['sanitize_callback' => 'intval']);
        register_setting('botzy_settings_group', 'botzy_output_limit', [
            'sanitize_callback' => function($val) {
                $val = intval($val);
                return $val >= 1 ? $val : 5;
            }
        ]);
    }

    public static function maybe_generate_api_key() {
        if (!get_option('botzy_api_key')) {
            update_option('botzy_api_key', 'b_' . wp_generate_password(20, false));
        }
    }

    public static function add_settings_page() {
        add_menu_page(
            'Botzy Sync',
            'Botzy Sync',
            'manage_options',
            'botzy-sync',
            [__CLASS__, 'render_settings_page'],
            plugin_dir_url(__FILE__) . '../assets/icon.png',
            100
        );
    }

    public static function render_settings_page() {
        $token = get_option('botzy_api_key'); 
        ?>
        <div class="wrap">
            <h1><strong>Botzy WP Sync</strong></h1>
            <form method="post" action="options.php">
                <?php settings_fields('botzy_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Contenidos para Botzy</th>
                            <td><fieldset><legend class="screen-reader-text">Tipos habilitados en la API</legend>
                                <?php $enabled = Botzywpsync_REST_API::enabled_types();
                                foreach (Botzywpsync_REST_API::available_types() as $slug => $object) : ?>
                                    <label><input type="checkbox" name="botzy_enabled_types[]" value="<?= esc_attr($slug) ?>" <?php checked(isset($enabled[$slug])); ?>> <?= esc_html($object->labels->singular_name) ?></label><br>
                                <?php endforeach; ?>
                            </fieldset></td>
                        </tr>
                        <tr>
                            <th scope="row">Contenido privado</th>
                            <td><label><input type="checkbox" name="botzy_allow_private" value="1" <?php checked(Botzywpsync_REST_API::private_allowed()); ?>>
                                Permitir que esta clave consulte publicaciones privadas cuando el flujo lo solicite.</label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="botzy_api_key">API Key:</label></th>
                            <td>
                                
                                <code id="botzy_api_key" style="font-size:16px; padding: 5px; margin-right: 5px;  display: inline-block;"><?php echo esc_html($token); ?></code>
                                <button type="button" class="button" id="regenerate_apikey">Regenerar API Key</button>
                                <span id="regenerate_status" style="margin-left: 10px;"></span>
                                <p class="description">Esta clave debe ser usada en los mensajes <strong>"Botzy WP Sync"</strong> en tu Bot.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="botzy_output_limit">Cantidad máxima de resultados a mostrar</label></th>
                            <td>
                                <input type="number" id="botzy_output_limit" name="botzy_output_limit"
                                    value="<?php echo esc_attr(get_option('botzy_output_limit', 5)); ?>"
                                    class="small-text" min="1" />
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <hr>
                                <h2>⚙️ Parámetros de búsqueda avanzada</h2>
                                <p class="description">La búsqueda combina palabras del contenido con coincidencias aproximadas en el título. Un SKU exacto tiene prioridad en productos.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="botzy_fuzzy_threshold">Similitud mínima (fuzzy)</label></th>
                            <td>
                                <input type="number" id="botzy_fuzzy_threshold" name="botzy_fuzzy_threshold"
                                    value="<?php echo esc_attr(get_option('botzy_fuzzy_threshold', 30)); ?>"
                                    class="small-text" min="1" max="100" /> %
                                <p class="description">A mayor % más estricto se vuelve el comparador de similitud</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="botzy_fuzzy_scan_limit">Posts a analizar (fuzzy)</label></th>
                            <td>
                                <input type="number" id="botzy_fuzzy_scan_limit" name="botzy_fuzzy_scan_limit"
                                    value="<?php echo esc_attr(get_option('botzy_fuzzy_scan_limit', 50)); ?>"
                                    class="small-text" min="1" />
                                <p class="description">Cantidad de posts que se revisan en modo difuso. A mayor cantidad de post a revisar mayor es el tiempo de respuesta.</p>
                            </td>
                        </tr>
                        <tr><th colspan="2"><hr></th></tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>

        <script>
const botzyNonce = '<?php echo wp_create_nonce("botzy_nonce"); ?>';

document.getElementById('regenerate_apikey').addEventListener('click', function () {
    const status = document.getElementById('regenerate_status');
    const code = document.getElementById('botzy_api_key');
    status.textContent = 'Generando...';

    fetch(ajaxurl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action: 'botzy_regenerate_apikey',
            _wpnonce: botzyNonce
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            code.textContent = data.data.key;
            status.textContent = '✅ Generada';
        } else {
            status.textContent = '❌ Error';
        }
    });
});
</script>
        <?php
    }

    public static function ajax_regenerate_apikey() {
        check_ajax_referer('botzy_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error([], 403);
        $new_key = 'b_' . wp_generate_password(20, false);
        update_option('botzy_api_key', $new_key);
        wp_send_json_success(['key' => $new_key]);
    }
}
