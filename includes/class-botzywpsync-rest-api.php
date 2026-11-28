<?php
defined('ABSPATH') || exit;

class Botzywpsync_REST_API {
    const FIELDS = ['access', 'id', 'type', 'title', 'summary', 'url', 'image_url', 'fields'];

    public static function register_routes() {
        add_filter('rest_post_dispatch', [__CLASS__, 'format_parse_error'], 10, 3);
        foreach (['capabilities', 'search', 'item'] as $operation) {
            register_rest_route('botzy', '/' . $operation, [
                'methods' => 'POST',
                'permission_callback' => '__return_true',
                'callback' => static function ($request) use ($operation) {
                    return self::dispatch($request, $operation);
                },
            ]);
        }
    }

    public static function available_types() {
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']);
        return $types;
    }

    public static function format_parse_error($response, $server, $request) {
        if ($request->get_method() === 'POST' && in_array(rtrim($request->get_route(), '/'), ['/botzy/item','/botzy/search','/botzy/capabilities'], true)
            && ($response->get_data()['code'] ?? '') === 'rest_invalid_json') return self::error('invalid_query', 400);
        return $response;
    }

    private static function field_schema($type) {
        $schema = [];
        if ($type === 'product' && function_exists('wc_get_product')) {
            $schema = ['sku' => 'string', 'price' => 'decimal_string', 'currency' => 'string', 'stock_status' => 'string'];
        }
        return $schema;
    }

    private static function custom_fields($post_id) {
        $meta = get_post_meta($post_id);
        $acf = function_exists('get_fields') ? get_fields($post_id, false) : [];
        $acf = is_array($acf) ? $acf : [];
        $names = array_unique(array_merge(array_keys($meta), array_keys($acf)));
        $fields = [];

        foreach ($names as $name) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $name)
                || is_protected_meta($name, 'post')) {
                continue;
            }

            $values = isset($meta[$name]) ? array_map('maybe_unserialize', $meta[$name]) : [];
            $value = $acf[$name] ?? (count($values) === 1 ? reset($values) : $values);
            if (is_scalar($value)) {
                $fields['meta.' . $name] = Botzywpsync_Search::plain($value);
                continue;
            }

            if (is_array($value) && $value && array_filter($value, 'is_scalar') === $value
                && array_values($value) === $value) {
                $fields['meta.' . $name] = implode(', ', array_map([Botzywpsync_Search::class, 'plain'], $value));
            }
        }

        return $fields;
    }

    public static function enabled_types() {
        $types = self::available_types();
        $enabled = get_option('botzy_enabled_types', ['post', 'page', 'product']);
        return array_intersect_key($types, array_flip((array) $enabled));
    }

    public static function private_allowed() {
        return (bool) get_option('botzy_allow_private', false);
    }

    private static function response($body, $http = 200) {
        return new WP_REST_Response($body, $http, ['Cache-Control' => 'private, no-store']);
    }

    private static function error($code, $http, $field = null) {
        $error = ['code' => $code];
        if ($field !== null) $error['field'] = $field;
        return self::response(['status' => 'error', 'returned_count' => 0, 'items' => [], 'error' => $error], $http);
    }

    public static function dispatch($request, $operation) {
        try {
            $params = $request->get_json_params();
            if (!is_array($params)) return self::error('invalid_query', 400);
            $key = get_option('botzy_api_key');
            if (!is_string($key) || $key === '' || !is_string($params['apikey'] ?? null)
                || !hash_equals($key, $params['apikey'])) {
                return self::error('unauthorized', 401);
            }

            $max = max(1, (int) get_option('botzy_output_limit', 5));
            $arguments = [
                'capabilities' => ['apikey'],
                'search' => ['apikey', 'q', 'post_type', 'limit', 'lang', 'allowPrivate', 'showRestrictedTitles'],
                'item' => ['apikey', 'id', 'allowPrivate', 'showRestrictedTitles'],
            ];
            foreach (array_keys($params) as $field) {
                if (!in_array($field, $arguments[$operation], true)) return self::error('invalid_query', 400, sanitize_key((string) $field));
            }
            $types = self::enabled_types();
            if ($operation === 'capabilities') {
                $contentTypes = [];
                foreach ($types as $slug => $type) {
                    $contentTypes[] = ['type' => $slug, 'label' => $type->labels->singular_name, 'fields' => (object) self::field_schema($slug)];
                }
                return self::response([
                    'operations' => [
                        'search' => ['method' => 'POST', 'url' => rest_url('botzy/search'),
                            'required' => ['apikey', 'q'], 'optional' => ['post_type', 'limit', 'lang', 'allowPrivate', 'showRestrictedTitles']],
                        'item' => ['method' => 'POST', 'url' => rest_url('botzy/item'),
                            'required' => ['apikey', 'id'], 'optional' => ['allowPrivate', 'showRestrictedTitles']],
                    ],
                    'post_types' => $contentTypes, 'item_fields' => self::FIELDS,
                    'dynamic_field_prefixes' => ['meta.'],
                    'restricted_item_fields' => ['access', 'id', 'title'],
                    'defaults' => ['allowPrivate' => false, 'showRestrictedTitles' => false],
                    'max_limit' => $max, 'allow_private' => self::private_allowed(),
                    'language_filter' => function_exists('pll_get_post_language'),
                ]);
            }

            $private = $params['allowPrivate'] ?? false;
            if (!is_bool($private)) return self::error('invalid_query', 400, 'allowPrivate');
            if ($private && !self::private_allowed()) return self::error('forbidden', 403, 'allowPrivate');
            $showRestricted = array_key_exists('showRestrictedTitles', $params) ? $params['showRestrictedTitles'] : false;
            if (!is_bool($showRestricted)) return self::error('invalid_query', 400, 'showRestrictedTitles');

            if ($operation === 'item') {
                $id = $params['id'] ?? '';
                if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
                    || (string)(int)$id !== (string)$id) return self::error('invalid_query', 400, 'id');
                $post = get_post((int) $id);
                $posts = $post && isset($types[$post->post_type]) && Botzywpsync_Search::visible($post, $private || $showRestricted) ? [$post] : [];
            } else {
                $type = $params['post_type'] ?? 'post';
                if (!is_string($type) || !isset($types[$type])) return self::error('unsupported_post_type', 400, 'post_type');
                $q = $params['q'] ?? '';
                if (!is_string($q) || strlen($q) > 512 || !Botzywpsync_Search::words($q)) return self::error('invalid_query', 400, 'q');
                $limit = $params['limit'] ?? $max;
                if (!is_int($limit) || $limit < 1) return self::error('invalid_query', 400, 'limit');
                $lang = $params['lang'] ?? '';
                if (!is_string($lang) || ($lang !== '' && !preg_match('/^[a-z]{2,3}([-_][a-z0-9]{2,8})*$/iD', $lang))) {
                    return self::error('invalid_query', 400, 'lang');
                }
                $limit = min($limit, $max);
                $posts = Botzywpsync_Search::find($q, $type, $private, $limit, $lang);
                if (!$private && $showRestricted && count($posts) < $limit) {
                    $titles = Botzywpsync_Search::find($q, $type, true, $limit - count($posts), $lang, true);
                    $posts = array_merge($posts, $titles);
                }
            }

            $items = array_map(static function ($post) use ($private) {
                return self::item($post, $private);
            }, $posts);
            return self::response(['status' => $items ? 'found' : 'empty', 'returned_count' => count($items), 'items' => $items]);
        } catch (Throwable $error) {
            return self::error('search_failed', 500);
        }
    }

    private static function absolute_url($value) {
        $url = esc_url_raw((string) $value, ['http', 'https']);
        return $url && wp_parse_url($url, PHP_URL_HOST) && preg_match('~^https?://~i', $url) ? $url : null;
    }

    private static function item($post, $private) {
        if ($post->post_status === 'private' && !$private) {
            return ['access' => 'restricted', 'id' => (string) $post->ID, 'title' => Botzywpsync_Search::plain($post->post_title)];
        }
        $url = get_permalink($post);
        if ($post->post_status === 'private') {
            $url = add_query_arg(['p' => $post->ID, 'post_type' => $post->post_type,
                'token' => Botzywpsync_Utils::generate_jwt_for_post($post->ID)], home_url('/'));
        }
        $url = self::absolute_url($url);
        if (!$url) throw new RuntimeException('Invalid permalink');
        $summary = $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
        $fields = [];
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            $fields += ['sku' => $product->get_sku(), 'price' => $product->get_price(),
                'currency' => get_woocommerce_currency(), 'stock_status' => $product->get_stock_status()];
        }
        $fields += self::custom_fields($post->ID);
        return ['access' => 'available', 'id' => (string) $post->ID, 'type' => $post->post_type, 'fields' => (object) $fields,
            'title' => Botzywpsync_Search::plain($post->post_title),
            'summary' => wp_trim_words(Botzywpsync_Search::plain($summary), 45, '…'),
            'url' => $url, 'image_url' => self::absolute_url(Botzywpsync_Utils::get_post_image($post->ID))];
    }
}
