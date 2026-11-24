<?php
    class Botzywpsync_Visibility {
        protected static string $signed_error = '';


        public static function init() {
            add_action('template_redirect', [__CLASS__, 'handle_signed_access'], 0);
            add_action('template_redirect', [__CLASS__, 'start_output_buffer'], 0);
            add_action('pre_get_posts', [__CLASS__, 'maybe_bypass_private_post']);
        }

        public static function handle_signed_access() {
        if (is_user_logged_in()) return;

        global $wp_query;

        $post_id = $wp_query->get('p');
        if (!$post_id) return;

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'private') return;

        $token = $_GET['token'] ?? null;
        if (!$token || substr_count($token, '.') !== 2) {
             return self::deny_access('Este enlace no contiene un token de acceso válido.');
         }




        list($header, $payload, $signature) = explode('.', $token);
        $secret = get_option('botzy_api_key') ?: BOTZYWPSYNC_SECRET;

        $expected_signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "$header.$payload", $secret, true)
        ), '+/', '-_'), '=');

        if (!hash_equals($expected_signature, $signature)) {
            return self::deny_access('🔒 Firma JWT inválida.');
        }


        $decoded_payload = json_decode(self::base64url_decode($payload), true);
        if (
    !$decoded_payload ||
    !isset($decoded_payload['exp'], $decoded_payload['post_id']) ||
    !is_numeric($decoded_payload['post_id']) ||
    !is_numeric($decoded_payload['exp'])
) {
    return self::deny_access('🛠️ Token mal formado o incompleto.');
}


        if ((int)$decoded_payload['post_id'] !== (int)$post->ID) {
    return self::deny_access('❌ Este token no corresponde a este post.');
}

if ((int)$_GET['p'] !== (int)$decoded_payload['post_id']) {
    return self::deny_access('❌ Este token no corresponde a este post.');
}



       if ((int)$decoded_payload['exp'] < time()) {
    return self::deny_access('⏳ El token ha expirado.');
}


/*$expected_fingerprint = $decoded_payload['fingerprint'] ?? null;
$current_fingerprint  = sha1($_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);

var_dump([
        'expected_fingerprint' => $expected_fingerprint,
        'current_fingerprint' => $current_fingerprint,
    ]);

if (!$expected_fingerprint || $expected_fingerprint !== $current_fingerprint) {
    return self::deny_access('🚫 Este token no es válido desde este dispositivo o red.');
}*/


/*$expected_origin = $decoded_payload['origin'] ?? null;
$current_origin  = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);

var_dump([
        'expected_origin' => $expected_origin,
        'current_origin' => $current_origin,
    ]);

// Validar solo si el token fue generado con origin
if ($expected_origin && (!$current_origin || $current_origin !== $expected_origin)) {
    return self::deny_access('🌍 Este enlace no puede abrirse desde este origen.');
}*/


$transient_key = 'botzy_token_' . md5($token);
if (!get_transient($transient_key)) {
    return self::deny_access('🚫 Este token ya fue usado o no es válido.');
}

delete_transient($transient_key); // Marcarlo como usado






        // Si llegamos aquí, todo está bien
        $wp_query->is_404 = false;
        $wp_query->found_posts = 1;
        $wp_query->post_count = 1;
        $wp_query->is_single = true;
        $wp_query->posts = [$post];
        $wp_query->queried_object = $post;
        $wp_query->queried_object_id = $post->ID;
    }


        public static function maybe_bypass_private_post($query) {
            if (is_admin() || !$query->is_main_query() || !$query->is_single) return;

            $token = $_GET['token'] ?? null;
            if (!$token || substr_count($token, '.') !== 2) return;

            list($header, $payload, $signature) = explode('.', $token);
            $secret = get_option('botzy_api_key') ?: BOTZYWPSYNC_SECRET;

            $expected_signature = rtrim(strtr(base64_encode(
                hash_hmac('sha256', "$header.$payload", $secret, true)
            ), '+/', '-_'), '=');

            if (!hash_equals($expected_signature, $signature)) return;

            $decoded_payload = json_decode(self::base64url_decode($payload), true);

            if (
                !$decoded_payload ||
                !isset($decoded_payload['exp'], $decoded_payload['post_id']) ||
                (int)$decoded_payload['exp'] < time()
            ) return;

            $current_post_id = $query->get('p');
            if ((int)$decoded_payload['post_id'] !== (int)$current_post_id) {
                return; // token no corresponde al post accedido
            }

            $query->set('post_status', ['private', 'publish']);
        }

        public static function start_output_buffer() {
        if (is_user_logged_in()) return;

        global $wp_query;

        if (
            $wp_query->is_404() &&
            !empty($wp_query->query_vars['p']) &&
            get_post_status((int)$wp_query->query_vars['p']) === 'private'
        ) {
            ob_start(function ($html) {
        return self::replace_404_body(self::$signed_error ?: 'Este contenido es privado.', $html);
    });

        }
    }


        public static function replace_404_body($message, $fullHtml = '') {
        $content = "
        <div style='max-width:600px;margin:4rem auto;padding:2rem;border:1px solid #ccc;background:#fff;border-radius:8px;font-family:sans-serif;text-align:center;'>
            <h1 ><strong style='color:#c00;font-size:2rem;'>⚠️ Acceso denegado</strong></h1>
            <h2 style='color:#000;font-size:1.2rem;'>(Este contenido es privado)</h2>
            <p>$message</p>
        </div>";

        if (preg_match('/<main[^>]*>.*?<\/main>/is', $fullHtml)) {
            return preg_replace('/<main[^>]*>.*?<\/main>/is', "<main>$content</main>", $fullHtml);
        }

        if (preg_match('/<body[^>]*>.*?<\/body>/is', $fullHtml)) {
            return preg_replace('/<body[^>]*>.*?<\/body>/is', "<body>$content</body>", $fullHtml);
        }

        return $content;
    }


        

        protected static function base64url_decode($data) {
            $remainder = strlen($data) % 4;
            if ($remainder) {
                $data .= str_repeat('=', 4 - $remainder);
            }
            return base64_decode(strtr($data, '-_', '+/'));
        }

    protected static function deny_access($message) {
    global $wp_query;
    $wp_query->set_404();
    self::$signed_error = $message;
    return;
}





    }
