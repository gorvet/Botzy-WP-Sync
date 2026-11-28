<?php
// Ejecutar por CLI dentro de una instalación local de WordPress con MySQL/MariaDB.
if (PHP_SAPI !== 'cli') exit;
define('WP_USE_THEMES', false);
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Botzy contract test';
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach (['active_plugins', 'active_sitewide_plugins'] as $option) {
    $GLOBALS['wp_filter']['pre_option_' . $option][10][] = ['function' => static function () { return []; }, 'accepted_args' => 1];
}
require dirname(__DIR__, 4) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!defined('BOTZYWPSYNC_DIR')) require dirname(__DIR__) . '/botzy-wp-sync.php';
if (!class_exists('Botzywpsync_REST_API')) Botzywpsync_Loader::init();
add_filter('pre_option_botzy_api_key', static function () { return 'unit-test-key'; });
add_filter('pre_option_botzy_enabled_types', static function () { return ['post']; });
add_filter('pre_option_botzy_output_limit', static function () { return 2; });
add_filter('pre_option_botzy_allow_private', static function () { return '0'; });
add_filter('pre_option_permalink_structure', static function () { return ''; });

$server = rest_get_server();
global $wpdb;
$source = $wpdb->posts;
$table = 'botzy_contract_' . bin2hex(random_bytes(6));
if (false === $wpdb->query("CREATE TEMPORARY TABLE $table LIKE $source")) throw new RuntimeException('Falta permiso para tablas temporales.');
$wpdb->posts = $table;
$meta_source = $wpdb->postmeta;
$wpdb->postmeta = $table . '_meta';
if (false === $wpdb->query("CREATE TEMPORARY TABLE {$wpdb->postmeta} LIKE $meta_source")) throw new RuntimeException('Falta la tabla temporal de metadatos.');
$base = random_int(100000000, 1900000000);
foreach ([
    [1, 'Renovación', 'publish', '', ''],
    [2, 'Renovación anual', 'publish', '', '<p>Solicitud anual.</p>'],
    [3, 'Renovación regional', 'publish', '', ''],
    [4, 'Formulario confidencial', 'private', '', ''],
    [5, 'Declaración especial', 'publish', 'protegido', ''],
    [6, 'Agenda', 'private', '', 'Contenido reservado.'],
    [7, 'Agenda local', 'publish', '', 'Actividades públicas.'],
    [8, 'Reserva', 'private', 'protegido', ''],
    [9, 'Contacto', 'publish', '', '', 'page'],
] as $fixture) {
    [$i, $title, $status, $password, $body] = $fixture;
    $ok = $wpdb->insert($table, ['ID' => $base + $i, 'post_title' => $title, 'post_name' => sanitize_title($title),
        'post_type' => $fixture[5] ?? 'post', 'post_status' => $status, 'post_password' => $password, 'post_content' => $body,
        'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '',
        'post_date' => '2024-01-01 00:00:00', 'post_date_gmt' => '2024-01-01 00:00:00']);
    if (!$ok) throw new RuntimeException('No se pudo preparar el catálogo temporal.');
}
foreach ([
    [$base + 2, 'duracion_minutos', '30'],
    [$base + 2, 'modalidades', ['Presencial', 'En línea']],
    [$base + 2, '_nota_interna', 'Reservada'],
    [$base + 2, 'estructura', ['nivel' => ['avanzado']]],
    [$base + 6, 'ubicacion', 'Sala 2'],
    [$base + 9, 'horario', 'Lunes a viernes'],
] as [$post_id, $meta_key, $meta_value]) {
    if (!$wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $meta_key, 'meta_value' => maybe_serialize($meta_value)])) {
        throw new RuntimeException('No se pudo preparar el metadato de prueba.');
    }
}
function get_fields($post_id, $format_value = true) {
    global $base;
    return $post_id === $base + 2 ? ['ponente' => '<b>Ana</b>'] : false;
}
function call_botzy($op, $data) {
    $request = new WP_REST_Request('POST', '/botzy/' . $op);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($data + ['apikey' => 'unit-test-key']));
    return rest_do_request($request);
}
function check_botzy($ok, $label) {
    if (!$ok) throw new RuntimeException($label);
    echo "[OK] $label\n";
}
function expect_response($op, $params, $status, $http, $count, $code = null) {
    $response = call_botzy($op, $params);
    $body = $response->get_data();
    check_botzy($response->get_status() === $http && $body['status'] === $status
        && count($body['items']) === $count && $body['returned_count'] === $count
        && ($body['error']['code'] ?? null) === $code, "$op $status $http ($count) $code");
    return $body['items'];
}
$caps = call_botzy('capabilities', [])->get_data();
foreach (['search','item'] as $op) {
    check_botzy(in_array('showRestrictedTitles', $caps['operations'][$op]['optional'], true)
        && $caps['defaults']['showRestrictedTitles'] === false && $caps['restricted_item_fields'] === ['access','id','title'], "Descubrimiento de títulos para $op");
}
check_botzy($caps['max_limit'] === 2 && array_column($caps['post_types'], 'type') === ['post'], 'Descubrimiento por sitio');
check_botzy($caps['dynamic_field_prefixes'] === ['meta.'], 'Descubrimiento de campos dinámicos');
$rows = expect_response('search', ['q' => 'renovación', 'limit' => 20], 'found', 200, 2);
check_botzy($rows[0]['id'] === (string)($base + 1) && $rows[0]['image_url'] === null && $rows[0]['summary'] === '', 'Orden, ID de cadena y campos vacíos');
check_botzy($rows[1]['summary'] === 'Solicitud anual.' && wp_parse_url($rows[1]['url'], PHP_URL_HOST), 'Candidato secundario completo');
$again = expect_response('search', ['q' => 'renobacion', 'limit' => 1], 'found', 200, 1);
check_botzy($again[0]['id'] === $rows[0]['id'], 'La aproximación conserva el ID');
$detail = expect_response('item', ['id' => $rows[1]['id']], 'found', 200, 1);
check_botzy($detail[0]['id'] === $rows[1]['id'], 'Selección posterior por ID');
$expected_fields = ['meta.duracion_minutos' => '30', 'meta.modalidades' => 'Presencial, En línea', 'meta.ponente' => 'Ana'];
check_botzy((array) $detail[0]['fields'] === $expected_fields && (array) $rows[1]['fields'] === $expected_fields,
    'Búsqueda y ficha detectan ACF y metadatos sin configuración y omiten los protegidos y anidados');
expect_response('search', ['q' => 'ornitorrinco'], 'empty', 200, 0);
expect_response('search', ['q' => 'formulario'], 'empty', 200, 0);
expect_response('search', ['q' => 'confidensial'], 'empty', 200, 0);
expect_response('search', ['q' => 'declaracion'], 'empty', 200, 0);
expect_response('item', ['id' => (string)($base + 4)], 'empty', 200, 0);
expect_response('search', ['q' => 'confidensial', 'allowPrivate' => true], 'error', 403, 0, 'forbidden');
expect_response('search', ['q' => 'renovación', 'apikey' => 'invalid'], 'error', 401, 0, 'unauthorized');
expect_response('search', ['q' => ''], 'error', 400, 0, 'invalid_query');
expect_response('search', ['q' => 'test', 'post_type' => 'page'], 'error', 400, 0, 'unsupported_post_type');
expect_response('search', ['q' => 'test', 'limit' => 0], 'error', 400, 0, 'invalid_query');
expect_response('search', ['q' => 'test', 'sede' => 'Caracas'], 'error', 400, 0, 'invalid_query');
wp_using_ext_object_cache(true);
$announce = ['allowPrivate' => false, 'showRestrictedTitles' => true];
$agenda = ['id' => (string)($base + 6)];
$restricted = ['access' => 'restricted', 'id' => $agenda['id'], 'title' => 'Agenda'];
$mixed = expect_response('search', ['q' => 'agenda'] + $announce, 'found', 200, 2);
check_botzy($mixed[0]['access'] === 'available' && $mixed[0]['title'] === 'Agenda local' && $mixed[1] === $restricted, 'Mixtos con prioridad pública y título restringido mínimo');
$first = expect_response('search', ['q' => 'agenda', 'limit' => 1] + $announce, 'found', 200, 1);
check_botzy($first[0]['access'] === 'available' && $first[0]['id'] === $mixed[0]['id'], 'La ficha accesible tiene prioridad antes del límite');
$hidden = expect_response('search', ['q' => 'agenda', 'allowPrivate' => false, 'showRestrictedTitles' => false], 'found', 200, 1);
check_botzy($hidden[0]['id'] === $first[0]['id'], 'Los privados ocultos quedan fuera del contador');
$only = expect_response('search', ['q' => 'confidensial'] + $announce, 'found', 200, 1);
check_botzy($only[0] === ['access' => 'restricted', 'id' => (string)($base + 4), 'title' => 'Formulario confidencial'], 'Coincidencia aproximada solo con título');
check_botzy(expect_response('item', $agenda + $announce, 'found', 200, 1) === [$restricted], 'Selección restringida por ID');
expect_response('item', $agenda, 'empty', 200, 0);
expect_response('item', $agenda + ['allowPrivate' => true, 'showRestrictedTitles' => true], 'error', 403, 0, 'forbidden');
expect_response('search', ['q' => 'reservado'] + $announce, 'empty', 200, 0);
expect_response('item', ['id' => (string)($base + 8)] + $announce, 'empty', 200, 0);
expect_response('search', ['q' => 'reserva'] + $announce, 'empty', 200, 0);
expect_response('item', $agenda + ['showRestrictedTitles' => null], 'error', 400, 0, 'invalid_query');
add_filter('pre_option_botzy_allow_private', static function () { return '1'; }, 20);
$full = expect_response('item', $agenda + ['allowPrivate' => true, 'showRestrictedTitles' => true], 'found', 200, 1);
check_botzy($full[0]['access'] === 'available' && $full[0]['summary'] === 'Contenido reservado.' && strpos($full[0]['url'], 'token=') !== false, 'Selección privada completa con permiso actual');
check_botzy((array) $full[0]['fields'] === ['meta.ubicacion' => 'Sala 2'], 'Los metadatos privados requieren acceso a la ficha');
check_botzy(expect_response('item', $agenda + $announce, 'found', 200, 1) === [$restricted], 'La clave autorizada respeta allowPrivate en cada petición');
$authorized = expect_response('search', ['q' => 'confidensial', 'allowPrivate' => true], 'found', 200, 1);
check_botzy($authorized[0]['id'] === (string)($base + 4) && strpos($authorized[0]['url'], 'token=') !== false, 'Privado autorizado con enlace firmado');
expect_response('search', ['q' => 'confidensial'], 'empty', 200, 0);
expect_response('item', ['id' => (string)($base + 5), 'allowPrivate' => true], 'empty', 200, 0);
add_filter('pre_option_botzy_enabled_types', static function () { return ['post', 'page']; }, 20);
$page = expect_response('item', ['id' => (string)($base + 9)], 'found', 200, 1);
check_botzy((array) $page[0]['fields'] === ['meta.horario' => 'Lunes a viernes'], 'Las páginas también detectan sus metadatos');
check_botzy(!isset($server->get_routes()['/botzy/v1/search']) && !isset($server->get_routes()['/botzy/v2/search']), 'API única sin rutas anteriores');
add_filter('posts_pre_query', static function () { throw new RuntimeException('Fixture'); });
expect_response('search', ['q' => 'test'], 'error', 500, 0, 'search_failed');
echo "Contrato verificado.\n";
