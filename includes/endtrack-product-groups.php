<?php
/**
 * ENDTrack — Grupos de producto por lanzamiento (Principal / Downsell / Cross-sell)
 *
 * Portado del mu-plugin de VISUAL BUSINESS SCHOOL (endtrack-product-groups.php)
 * al propio plugin para que viaje con él a todas las webs (GitHub jcendgit/ENDTRACK).
 *
 * Opciones usadas:
 *   endtrack_ext_products_{safe}            -> productos GENERALES / principal (ya existente)
 *   endtrack_ext_products_downsell_{safe}   -> productos DOWNSELL
 *   endtrack_ext_products_crosssell_{safe}  -> productos CROSS-SELL
 *
 * Lo usan stats-ventas-completas.php, stats-ventas-fullscreen.php, class-endtrack-ajax.php
 * y el panel admin (tarjetas de lanzamiento).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('endtrack_get_launch_product_groups')) {
    /**
     * Devuelve los 3 listados de producto de un lanzamiento + la unión y un mapa
     * id_producto => categoría ('principal' | 'downsell' | 'crosssell').
     *
     * Prioridad si un ID aparece en varias listas: downsell > crosssell > principal.
     */
    function endtrack_get_launch_product_groups($launch)
    {
        $safe = ($launch === 'legacy')
            ? 'legacy'
            : preg_replace('/[^a-zA-Z0-9_]/', '', (string) $launch);

        $load = function ($key) {
            $arr = json_decode(get_option($key, '[]'), true);
            if (!is_array($arr)) $arr = array();
            return array_values(array_unique(array_map('strval', $arr)));
        };

        $general   = $load('endtrack_ext_products_' . $safe);
        $downsell  = $load('endtrack_ext_products_downsell_' . $safe);
        $crosssell = $load('endtrack_ext_products_crosssell_' . $safe);

        // Mapa id => categoría (downsell gana a crosssell, que gana a principal)
        $map = array();
        foreach ($general as $id)   $map[(string) $id] = 'principal';
        foreach ($crosssell as $id) $map[(string) $id] = 'crosssell';
        foreach ($downsell as $id)  $map[(string) $id] = 'downsell';

        $all = array_values(array_unique(array_merge($general, $downsell, $crosssell)));

        return array(
            'safe'      => $safe,
            'general'   => $general,
            'downsell'  => $downsell,
            'crosssell' => $crosssell,
            'all'       => $all,
            'map'       => $map,
        );
    }
}

if (!function_exists('endtrack_product_group_label')) {
    /** Etiqueta legible de una categoría. */
    function endtrack_product_group_label($cat)
    {
        switch ($cat) {
            case 'downsell':  return 'Downsell';
            case 'crosssell': return 'Cross-sell';
            case 'principal': return 'Principal';
            default:          return '';
        }
    }
}

if (!function_exists('endtrack_product_group_color')) {
    /** Color de badge por categoría. */
    function endtrack_product_group_color($cat)
    {
        switch ($cat) {
            case 'downsell':  return '#f59e0b'; // ámbar
            case 'crosssell': return '#06b6d4'; // teal
            case 'principal': return '#6366f1'; // índigo
            default:          return '#94a3b8';
        }
    }
}

// ── AJAX: guardar listados Downsell / Cross-sell ─────────────────────────────
add_action('wp_ajax_endtrack_save_launch_products_group', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No tienes permisos.');
    }
    check_ajax_referer('endtrack_save_products_group_nonce', 'nonce');

    $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
    $group  = isset($_POST['group'])  ? sanitize_text_field($_POST['group'])  : '';
    $raw    = isset($_POST['products']) ? wp_unslash($_POST['products']) : '';

    if ($launch === '' || !in_array($group, array('downsell', 'crosssell'), true)) {
        wp_send_json_error('Parámetros no válidos.');
    }

    $safe  = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
    $parts = preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
    $products = array();
    foreach ($parts as $p) {
        $clean = sanitize_text_field(trim($p));
        if ($clean !== '') $products[] = $clean;
    }
    $products = array_values(array_unique($products));

    update_option('endtrack_ext_products_' . $group . '_' . $safe, wp_json_encode($products));

    wp_send_json_success(array(
        'group'    => $group,
        'count'    => count($products),
        'products' => $products,
    ));
});

// ── AJAX: guardar la comisión general (%) de un lanzamiento ──────────────────
// Antes se guardaba junto a los productos vía admin-post (endtrack_save_products).
// Es la comisión que se muestra en /afiliados-captacion/ y la que se aplica por
// defecto a los afiliados sin comisión propia.
add_action('wp_ajax_endtrack_save_launch_commission', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('No tienes permisos.');
    }
    check_ajax_referer('endtrack_save_launch_commission_nonce', 'nonce');

    $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
    if ($launch === '') {
        wp_send_json_error('Lanzamiento no válido.');
    }
    $safe = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);

    $comm_raw = isset($_POST['commission']) ? trim((string) wp_unslash($_POST['commission'])) : '';
    if ($comm_raw === '') {
        delete_option('endtrack_launch_commission_' . $safe);
        wp_send_json_success(array('commission' => ''));
    }
    $comm = floatval(str_replace(',', '.', $comm_raw));
    if ($comm < 0)   $comm = 0;
    if ($comm > 100) $comm = 100;
    update_option('endtrack_launch_commission_' . $safe, $comm);

    wp_send_json_success(array('commission' => $comm));
});
