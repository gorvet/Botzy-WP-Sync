<?php
defined('ABSPATH') || exit;

class Botzywpsync_Utils {

    public static function get_clean_content($post_id) {
    $html = get_post_field('post_content', $post_id);

    // 0. Quitar shortcodes de Gutenberg o plugins
    $html = strip_shortcodes($html);

    // 1. Eliminar tablas completas (con o sin atributos)
    $html = preg_replace('/<table.*?<\/table>/is', '', $html);

    // 2. Decodificar entidades HTML (&nbsp;, &amp;, etc.)
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 3. Quitar todas las etiquetas restantes
    $content = wp_strip_all_tags($html);

    // 4. Eliminar saltos múltiples y normalizar espacios
    $content = preg_replace('/(\s*[\r\n]+\s*){2,}/', "\n\n", $content);
    $content = preg_replace('/\s+/', ' ', $content);

    return trim($content);
}



    public static function generate_excerpt($content, $query) {
        $query = trim($query);
        $lower_content = mb_strtolower($content);
        $lower_query   = mb_strtolower($query);
        $pos = stripos($lower_content, $lower_query);

        if ($pos !== false) {
            $words = preg_split('/\s+/u', $content);
            $query_words = preg_split('/\s+/u', $query);
            $query_word_count = count($query_words);

            $lower_words = array_map('mb_strtolower', $words);
            $query_key = implode(' ', $query_words);

            $match_index = null;
            for ($i = 0; $i <= count($lower_words) - $query_word_count; $i++) {
                $chunk = implode(' ', array_slice($lower_words, $i, $query_word_count));
                if (stripos($chunk, $query_key) !== false) {
                    $match_index = $i;
                    break;
                }
            }

            if ($match_index !== null) {
                $words_before = 5;
                $words_after = 10;

                $start = max(0, $match_index - $words_before);
                $length = $query_word_count + $words_after + ($match_index - $start);

                $snippet_words = array_slice($words, $start, $length);
                $snippet = implode(' ', $snippet_words);
            } else {
                $snippet = mb_substr($content, 0, 120);
            }
        } else {
            $snippet = mb_substr($content, 0, 120);
        }

        // Evita cortar palabras al final
        $snippet = preg_replace('/\s+\S*$/u', '', $snippet);

        // Resalta coincidencias con asteriscos (estilo Markdown para bots)
        $snippet = preg_replace_callback(
            '/\b(' . preg_quote($query, '/') . ')\b/iu',
            fn($m) => '*' . $m[1] . '*',
            $snippet
        );

        return $snippet;
    }

    public static function get_post_image($post_id) {
        // Imagen destacada primero
        $thumbnail = get_the_post_thumbnail_url($post_id, 'medium');
        if ($thumbnail) return $thumbnail;

        // Buscar la primera imagen del contenido
        $content = get_post_field('post_content', $post_id);
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) {
            return esc_url_raw($matches[1]);
        }

        return null;
    }

    public static function build_result_item($post, $query = '', $search_type = '') {
        $query   = trim($query);
        $content = self::get_clean_content($post->ID);
        $excerpt = self::generate_excerpt($content, $query);

        $link_url = self::get_result_link($post->ID);

        $item = [
            'id'          => $post->ID,
            'title' => get_post_field('post_title', $post->ID),
            'img'         => self::get_post_image($post->ID),
            'excerpt'     => trim($excerpt) . '...',
            'link'        => [
                'url'   => $link_url,
                'label' => 'Ver más'
            ],
            'searchType'  => $search_type,
        ];

        // Taxonomías
        $post_type = get_post_type($post->ID);
        $taxonomies = get_object_taxonomies($post_type, 'names');
        $item['taxonomies'] = [];

        foreach ($taxonomies as $tax) {
            $terms = get_the_terms($post->ID, $tax);
            if (!empty($terms) && !is_wp_error($terms)) {
                $term_list = [];
                foreach ($terms as $term) {
                    $url = get_term_link($term);
                    if (!is_wp_error($url)) {
                        $term_list[] = [
                            'name' => $term->name,
                            'url'  => $url,
                        ];
                    }
                }

                $taxonomy_object = get_taxonomy($tax);
                $item['taxonomies'][$tax] = [
                    'label' => $taxonomy_object->labels->singular_name ?? $tax,
                    'terms' => $term_list
                ];
            }
        }

        // ACF
        if (function_exists('get_fields')) {
            $acf = get_fields($post->ID);
            if (!empty($acf)) {
                $item['acf'] = $acf;
            }
        }

        return $item;
    }

    public static function get_signed_permalink($post_id) {
        $token = self::generate_jwt_for_post($post_id);
        return add_query_arg(['token' => $token], get_permalink($post_id));
    }



    public static function generate_jwt_for_post($post_id, $expires_in = 600) {
        $secret = get_option('botzy_api_key') ?: BOTZYWPSYNC_SECRET;

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $fingerprint = sha1($_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']);
        $referer_host = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);

$payload = [
    'post_id'     => $post_id,
    'exp'         => time() + $expires_in,
    'iat'         => time(),
    'iss'         => 'botzy-wp-sync',
    'fingerprint' => $fingerprint,
    'origin'      => $referer_host, // ← aquí se guarda
];


        $base64UrlHeader  = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $base64UrlPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $secret, true);
        $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt = "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";

// Verificar que el token no esté vacío
if ($jwt && is_string($jwt)) {
    set_transient('botzy_token_' . md5($jwt), true);
}

return $jwt;


    }

    public static function get_result_link($post_id) {
    $status = get_post_status($post_id);

    // Firmamos solo si es explícitamente "private"
    if ($status === 'private') {
        return self::get_signed_permalink($post_id);
    }

    // Todo lo demás: permalink limpio
    return get_permalink($post_id);
}


}
