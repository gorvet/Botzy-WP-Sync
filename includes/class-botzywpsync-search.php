<?php
defined('ABSPATH') || exit;

class Botzywpsync_Search {
    public static function plain($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('~</(?:p|li|div|tr|td|th|h[1-6])>|<br\s*/?>~i', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($value))));
    }

    public static function words($text) {
        $text = mb_strtolower(remove_accents(self::plain($text)));
        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function visible($post, $private) {
        if ($post->post_password !== '' || !in_array($post->post_status, $private ? ['publish', 'private'] : ['publish'], true)) return false;
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if (!$product || !in_array($product->get_catalog_visibility(), ['search', 'visible'], true)) return false;
            if (get_option('woocommerce_hide_out_of_stock_items') === 'yes' && !$product->is_in_stock()) return false;
        }
        return true;
    }

    public static function find($q, $type, $private, $limit, $lang, $titlesOnly = false) {
        global $wpdb;
        $words = self::words($q);
        $important = array_diff($words, ['de', 'del', 'el', 'la', 'las', 'los', 'un', 'una', 'en', 'por', 'para', 'que', 'como']);
        $tokens = array_slice(array_values(array_unique($important ?: $words)), 0, 12);
        $pool = max($limit, min(500, max(1, (int) get_option('botzy_fuzzy_scan_limit', 50))));
        $threshold = max(1, min(100, (int) get_option('botzy_fuzzy_threshold', 30)));

        // Busca fragmentos en todo el catálogo antes de aplicar el presupuesto de candidatos.
        $conditions = [];
        $params = [$type];
        foreach ($tokens as $word) {
            $pieces = [$word];
            if (mb_strlen($word) >= 5) {
                $half = (int) floor(mb_strlen($word) / 2);
                $pieces = [mb_substr($word, 0, $half), mb_substr($word, $half)];
            }
            foreach ($pieces as $piece) {
                $conditions[] = 'post_title LIKE %s';
                $params[] = '%' . $wpdb->esc_like($piece) . '%';
            }
        }
        $status = $private ? "('publish','private')" : "('publish')";
        if ($titlesOnly) $status = "('private')";
        $scoreSql = implode(' + ', array_map(static function ($condition) { return '(' . $condition . ')'; }, $conditions));
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN $status AND post_password = ''
                AND (" . implode(' OR ', $conditions) . ") ORDER BY ($scoreSql) DESC, ID ASC LIMIT %d";
        $ids = $wpdb->get_col($wpdb->prepare($sql, array_merge($params, array_slice($params, 1), [$pool])));
        if ($wpdb->last_error) throw new RuntimeException('Search unavailable');

        if (!$titlesOnly) {
            $native = new WP_Query(['post_type' => $type, 'post_status' => $private ? ['publish','private'] : 'publish',
            'has_password' => false, 's' => implode(' ', $tokens), 'fields' => 'ids',
            'posts_per_page' => $pool, 'no_found_rows' => true, 'ignore_sticky_posts' => true]);
            if ($wpdb->last_error) throw new RuntimeException('Search unavailable');
            $ids = array_merge($native->posts, $ids);
        }
        $sku = 0;
        if (!$titlesOnly && $type === 'product' && function_exists('wc_get_product_id_by_sku')) {
            $sku = (int) wc_get_product_id_by_sku(trim($q));
            if ($sku) $ids[] = $sku;
        }

        $ranked = [];
        $phrase = implode(' ', $words);
        foreach (array_unique($ids) as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== $type || !self::visible($post, $private)) continue;
            if ($lang !== '' && function_exists('pll_get_post_language')) {
                $language = pll_get_post_language($post->ID, 'slug');
                if (!$language || strcasecmp($language, $lang) !== 0) continue;
            }
            $title = self::words($post->post_title);
            $titleText = implode(' ', $title);
            $body = $titlesOnly ? [] : array_flip(self::words($post->post_excerpt . ' ' . $post->post_content));
            $score = 0;
            foreach ($tokens as $token) {
                $best = isset($body[$token]) ? 55 : 0;
                foreach ($title as $term) {
                    if ($term === $token) { $best = 100; break; }
                    $size = strlen($token);
                    if ($size < 4 || abs(strlen($term) - $size) > 2) continue;
                    $distance = levenshtein($token, $term);
                    if ($distance >= 0 && $distance <= ($size >= 7 ? 2 : 1)) $best = max($best, 80 - 8 * $distance);
                }
                $score += $best;
            }
            $score /= count($tokens);
            if ((int) $id === $sku) $score = 150;
            elseif ($titleText === $phrase) $score = 130;
            elseif (strpos(' ' . $titleText . ' ', ' ' . $phrase . ' ') !== false) $score += 12;
            if ($score >= $threshold) $ranked[] = ['post' => $post, 'score' => $score];
        }
        usort($ranked, static function ($a, $b) {
            return ($b['score'] <=> $a['score']) ?: ($a['post']->ID <=> $b['post']->ID);
        });
        return array_column(array_slice($ranked, 0, $limit), 'post');
    }
}
