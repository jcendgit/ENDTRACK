<?php
/**
 * Template Name: ENDTrack Stats Fullscreen PRO
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb, $table_datos, $table_visitas, $table_posts, $active_launch, $target_reg_val, $sql_v, $sql_d, $primer_reg_cond_total, $visit_count_expr, $visit_label_h;
$table_datos = $wpdb->prefix . 'datos';
$table_visitas = $wpdb->prefix . 'visitas';
$table_posts = $wpdb->prefix . 'posts';

// Normalización "borja": en cada carga de la página se corrige en BBDD el tráfico
// organico+borja → afiliado 222 (el medio 'borja' se mantiene).
// Leads (uBCFl_datos): source='organico' + medium='borja' → source='afiliado', afiliado=222.
$wpdb->query("UPDATE $table_datos SET source = 'afiliado', afiliado = '222' WHERE LOWER(source) = 'organico' AND LOWER(medium) = 'borja'");
// Visitas (uBCFl_visitas): ref_s='organico' + ref_m='borja' → ref_s='afiliado', ref=222.
$wpdb->query("UPDATE $table_visitas SET ref_s = 'afiliado', ref = '222' WHERE LOWER(ref_s) = 'organico' AND LOWER(ref_m) = 'borja'");

// === 1. Filters & Context ===
$endtrack_launches_list = get_option('endtrack_launches', array());
$default_launch = !empty($endtrack_launches_list) ? end($endtrack_launches_list) : 'legacy';
$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : $default_launch;
$filter_date_start = isset($_GET['d_start']) ? sanitize_text_field($_GET['d_start']) : date('Y-m-d', strtotime('-30 days'));
$filter_date_end = isset($_GET['d_end']) ? sanitize_text_field($_GET['d_end']) : date('Y-m-d');
$filter_source = isset($_GET['var_source']) ? sanitize_text_field($_GET['var_source']) : '';
$filter_medium = isset($_GET['var_medium']) ? sanitize_text_field($_GET['var_medium']) : '';
$filter_campaign = isset($_GET['var_campaign']) ? sanitize_text_field($_GET['var_campaign']) : '';
$filter_afiliado = isset($_GET['var_afiliado']) ? sanitize_text_field($_GET['var_afiliado']) : '';
$filter_page = isset($_GET['var_page']) ? sanitize_text_field($_GET['var_page']) : '';

// Counting mode: 'sessions' (30-min window, default) or 'unique_ip' (1 visit per IP)
$count_mode = isset($_GET['count_mode']) ? sanitize_text_field($_GET['count_mode']) : 'unique_ip';
if (!in_array($count_mode, ['sessions', 'unique_ip']))
    $count_mode = 'sessions';
$visit_count_expr = ($count_mode === 'unique_ip')
    ? "COUNT(DISTINCT ip)"
    : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))";
$visit_label = ($count_mode === 'unique_ip') ? 'IPs únicas' : 'Sesiones';
$visit_label_h = $visit_label;

// Launch Config
$target_reg_val = 1; // Default Registrados
$launch_configs = get_option('endtrack_launch_configs', array());
$is_sales_launch = (isset($launch_configs[$active_launch]) && $launch_configs[$active_launch]['type'] == 1);
if ($is_sales_launch)
    $target_reg_val = 2; // Ventas

// Build where clauses
$where_v = "WHERE 1=1";
$where_d = "WHERE 1=1";
$args_v = [];
$args_d = [];

// Date
$where_v .= " AND fecha >= %s AND fecha <= %s";
$where_d .= " AND fecha >= %s AND fecha <= %s";
$args_v[] = $filter_date_start . ' 00:00:00';
$args_v[] = $filter_date_end . ' 23:59:59';
$args_d[] = $filter_date_start . ' 00:00:00';
$args_d[] = $filter_date_end . ' 23:59:59';

// Launch filter via tipo_cat_<launch> column (row-level, applies to wp_visitas and wp_datos)
if ($active_launch) {
    if ($active_launch === 'legacy') {
        $where_d .= " AND id_pag NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_endtrack_launch')";
        $where_v .= " AND id_pag NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_endtrack_launch')";
    } else {
        $safe_launch_tipo = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $col_tipo_cat = "tipo_cat_" . $safe_launch_tipo;
        $where_v .= " AND $col_tipo_cat > 0";
        $where_d .= " AND $col_tipo_cat > 0";

        // Extra strictness: also constrain to pages categorized under this launch
        $mapping = get_option('endtrack_launches_mapping', array());
        $launch_cat_slug = isset($mapping[$active_launch]) ? $mapping[$active_launch] : sanitize_title($active_launch);
        $launch_cat = get_category_by_slug($launch_cat_slug);
        if ($launch_cat) {
            $pages_query = new WP_Query(array('post_type' => 'page', 'posts_per_page' => -1, 'category_name' => $launch_cat_slug, 'fields' => 'ids'));
            if (!empty($pages_query->posts)) {
                $ids = implode(',', array_map('intval', $pages_query->posts));
                $where_v .= " AND id_pag IN ($ids)";
                $where_d .= " AND id_pag IN ($ids)";
            }
        }
    }
}

// User Filters
if ($filter_source) {
    $where_v .= " AND ref_s = %s";
    $args_v[] = $filter_source;
    $where_d .= " AND source = %s";
    $args_d[] = $filter_source;
}
if ($filter_medium) {
    $where_v .= " AND ref_m = %s";
    $args_v[] = $filter_medium;
    $where_d .= " AND medium = %s";
    $args_d[] = $filter_medium;
}
if ($filter_campaign) {
    $where_v .= " AND ref_c = %s";
    $args_v[] = $filter_campaign;
    $where_d .= " AND campaign = %s";
    $args_d[] = $filter_campaign;
}
if ($filter_afiliado) {
    $where_d .= " AND afiliado = %s";
    $args_d[] = $filter_afiliado;
    // Visitas no tiene affiliado como tal, pero a veces viene en ref_m o wp_datos join. Haremos joins si es necesario o aplicamos a conversiones.
}
if ($filter_page) {
    $where_v .= " AND id_pag = %s";
    $args_v[] = intval($filter_page);
    $where_d .= " AND id_pag = %s";
    $args_d[] = intval($filter_page);
}

// Prepare strings (with safety fallback if prepare() returns null)
if (!empty($args_v)) {
    $sql_v = $wpdb->prepare($where_v, ...$args_v);
} else {
    $sql_v = $where_v;
}
if (!empty($args_d)) {
    $sql_d = $wpdb->prepare($where_d, ...$args_d);
} else {
    $sql_d = $where_d;
}
// Ensure they are never null/empty
if (empty($sql_v))
    $sql_v = "WHERE 1=1";
if (empty($sql_d))
    $sql_d = "WHERE 1=1";

// Dropdowns data (for filters)
$filter_options = [
    'sources' => $wpdb->get_col("SELECT DISTINCT source FROM $table_datos WHERE source != '' AND source IS NOT NULL"),
    'mediums' => $wpdb->get_col("SELECT DISTINCT medium FROM $table_datos WHERE medium != '' AND medium IS NOT NULL"),
    'campaigns' => $wpdb->get_col("SELECT DISTINCT campaign FROM $table_datos WHERE campaign != '' AND campaign IS NOT NULL"),
    'afiliados' => $wpdb->get_col("SELECT DISTINCT afiliado FROM $table_datos WHERE afiliado != '' AND afiliado IS NOT NULL"),
    // Get pages that actually have recorded traffic
    'pages' => $wpdb->get_results("SELECT DISTINCT p.ID as id, p.post_title as title FROM $table_posts p JOIN $table_visitas v ON v.id_pag = p.ID WHERE p.post_type = 'page' AND p.post_status = 'publish'")
];

/**
 * Helper to get affiliate display name from WP User ID.
 * Matches WP User IDs to their display names safely.
 */
function get_affiliate_name($id)
{
    static $aff_names = [];
    $id = trim((string) $id);
    if (empty($id) || $id === '0' || $id === 'Sin definir')
        return 'Sin afiliado';
    if (!is_numeric($id))
        return $id;
    if (!isset($aff_names[$id])) {
        $u = get_userdata((int) $id);
        $aff_names[$id] = $u ? $u->display_name : "ID: $id";
    }
    return $aff_names[$id];
}


// === 2. Query Macros ===
function q_visits($select, $group_by = "", $order_by = "")
{
    global $wpdb, $table_visitas, $sql_v;
    if (empty($table_visitas))
        $table_visitas = $wpdb->prefix . 'visitas';
    if (empty($sql_v))
        $sql_v = "WHERE 1=1";
    $sql = "SELECT $select FROM $table_visitas $sql_v $group_by $order_by";
    return $wpdb->get_results($sql);
}

function q_datos($select, $group_by = "", $order_by = "")
{
    global $wpdb, $table_datos, $sql_d;
    if (empty($table_datos))
        $table_datos = $wpdb->prefix . 'datos';
    if (empty($sql_d))
        $sql_d = "WHERE 1=1";
    $sql = "SELECT $select FROM $table_datos $sql_d $group_by $order_by";
    return $wpdb->get_results($sql);
}

// === 3. Fetching Data for the 43 panels ===

// Section 1: KPIs
$primer_reg_cond_total = " AND primer_reg = $target_reg_val";
$primer_reg_cond_oneclick = " AND primer_reg = 3";
$primer_reg_cond_lead = " AND primer_reg = 5";
$primer_reg_cond_buyer = " AND primer_reg = 2";

if ($active_launch && $active_launch !== 'legacy') {
    // If it's a specific launch, registrations only count when primer_reg_LAUNCH is set to 1
    $safe_launch_preg = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $primer_reg_cond_total = " AND primer_reg_" . $safe_launch_preg . " = $target_reg_val";
    // For other types, we might need specific logic or fallback to the master `primer_reg`
// Assuming buyers and oneclicks still rely on the master column as per previous interactions unless specified otherwise.
}

$total_sessions = $wpdb->get_var("SELECT $visit_count_expr FROM $table_visitas $sql_v");
// Apply the exact condition logic to ensure it ONLY fetches regs when they are the primary registration for the launch
$total_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total");
// 'Por Landing' (target_regs) EXCLUDES lista de espera and oneclick correo; CVR uses target_regs directly
$target_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total AND (placement != 'correo' OR placement IS NULL) AND (LOWER(medium) != 'lista de espera' OR medium IS NULL)");
$cvr = ($total_sessions > 0) ? ($target_regs / $total_sessions) * 100 : 0;
// Regs by Type
$oneclick_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total AND placement = 'correo'");
$buyers_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_buyer");
// Lista de espera: rows where medium='lista de espera' + legacy primer_reg=5 marker
$lista_espera_medium_regs = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total AND LOWER(medium) = 'lista de espera'");
$waitlist_regs = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_lead");
$lista_espera_regs = $lista_espera_medium_regs + $waitlist_regs;

// Repeticiones (re-inscripciones): filas del lanzamiento con primer_reg_LAUNCH = 0
// (mismo scope que "Registrados TOTALES": páginas del lanzamiento + filtros + fecha).
$repeat_regs = 0;
if ($active_launch && $active_launch !== 'legacy') {
    $col_pr_rep = "primer_reg_" . preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $repeat_regs = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d AND $col_pr_rep = 0");
}

// Granular Breakdown for "Por Web" — also excludes lista de espera
$target_regs_org = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total AND (placement != 'correo' OR placement IS NULL) AND (LOWER(medium) != 'lista de espera' OR medium IS NULL) AND source = 'organico'");
$target_regs_pub = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total AND (placement != 'correo' OR placement IS NULL) AND (LOWER(medium) != 'lista de espera' OR medium IS NULL) AND (source = 'publicidad' OR source = 'Publicidad')");
$target_regs_aff = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total AND (placement != 'correo' OR placement IS NULL) AND (LOWER(medium) != 'lista de espera' OR medium IS NULL) AND (afiliado > 0 AND afiliado != '')");


$dashboard = [];

// SECTION 1: KPIs
$dashboard['sections'][] = [
    'title' => '1. KPIs / Métricas clave',
    'id' => 'sec_kpi',
    'icon' => 'fa-tachometer-alt',
    'layout' => 'kpi_grid',
    'items' => [
        ['type' => 'kpi', 'title' => $visit_label, 'value' => number_format((float) $total_sessions, 0, ',', '.'), 'color' => 'pink'],
        ['type' => 'kpi', 'title' => '% Conversión', 'value' => number_format((float) $cvr, 1, ',', '.') . '%', 'color' => 'orange'],
        ['type' => 'kpi', 'title' => 'Registrados TOTALES', 'value' => number_format((float) $total_regs, 0, ',', '.') . "<br><small style='opacity:0.7; font-size:13px; font-weight:600;'>Lista de espera: " . number_format($lista_espera_regs, 0, ',', '.') . "</small><br><small style='opacity:0.6; font-size:12px; font-weight:600;'>Re-inscripciones (primer_reg=0): " . number_format($repeat_regs, 0, ',', '.') . "</small>", 'color' => 'purple'],
        ['type' => 'kpi', 'title' => 'Desglose por bloque', 'value' => "Por Landing: $target_regs <br><small style='opacity:0.8'>Org: $target_regs_org <br> Pub: $target_regs_pub <br> Aff: $target_regs_aff</small> <br> Oneclick: $oneclick_regs <br> Lista de espera: $lista_espera_regs", 'color' => 'purple', 'text_size' => 'small'],
        [
            'type' => 'funnel',
            'title' => 'Funnel Completo',
            'data' => [
                ['label' => 'Sesiones', 'value' => $total_sessions],
                ['label' => 'Registrados', 'value' => $target_regs],
                ['label' => 'Compradores', 'value' => $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d AND primer_reg = 2")]
            ]
        ]
    ]
];

// Helper: Tasa Conversion por variable
function get_conversion_table($by_column, $title, $in_visitas = 'id_pag', $in_datos = 'id_pag', $page_join = false, $only_reg_pages = false, $include_source = false, $exclude_val = '', $force_color = '')
{
    global $wpdb, $table_visitas, $table_datos, $sql_v, $sql_d, $target_reg_val, $table_posts, $active_launch, $visit_count_expr, $visit_label_h;
    if (empty($table_visitas))
        $table_visitas = $wpdb->prefix . 'visitas';
    if (empty($table_datos))
        $table_datos = $wpdb->prefix . 'datos';
    if (empty($table_posts))
        $table_posts = $wpdb->prefix . 'posts';
    if (empty($sql_v))
        $sql_v = "WHERE 1=1";
    if (empty($sql_d))
        $sql_d = "WHERE 1=1";
    if (empty($target_reg_val))
        $target_reg_val = 1;
    $v_cnt = !empty($visit_count_expr) ? str_replace(array('ip', 'fecha'), array('v.ip', 'v.fecha'), $visit_count_expr) : "COUNT(DISTINCT v.ip, FLOOR(UNIX_TIMESTAMP(v.fecha) / 1800))";

    $join = "";
    $sel_name = "v.$in_visitas as dimension";

    $has_page_slug = false;
    if ($page_join && $in_visitas == 'id_pag') {
        $join = "LEFT JOIN $table_posts p ON v.id_pag = p.ID";
        $sel_name = "IFNULL(p.post_title, 'Desconocido') as dimension, CONCAT('/', IFNULL(p.post_name, ''), '/') as page_slug";
        $has_page_slug = true;
    }

    // Cross-table comparisons (datos↔visitas) need an explicit collation: datos.source/medium
    // use utf8mb4_unicode_520_ci while visitas.ref_* use utf8mb4_general_ci → "Illegal mix of collations".
    $dcoll = ($in_datos === 'id_pag') ? '' : ' COLLATE utf8mb4_general_ci';

    $source_sel = "";
    $source_col = "";
    $group_by = "v.$in_visitas";
    if ($include_source) {
        $source_sel = "v.ref_s as dimension2, ";
        $source_col = "AND d.source COLLATE utf8mb4_general_ci = v.ref_s";
        $group_by = "v.$in_visitas, v.ref_s";
    }

    $where_v_extra = "";
    if ($only_reg_pages && $active_launch && $active_launch !== 'legacy') {
        $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $col_tipo_cat = "tipo_cat_" . $safe_launch;
        $where_v_extra .= " AND v.$col_tipo_cat = 1";
    }

    if ($exclude_val) {
        $where_v_extra .= " AND v.$in_visitas != '" . esc_sql($exclude_val) . "'";
    }

    if ($active_launch && $active_launch !== 'legacy') {
        $safe_launch_preg = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $col_primer_reg = "primer_reg_" . $safe_launch_preg;
        $d_primer_reg_cond = " AND d.$col_primer_reg = " . (int) $target_reg_val;
    } else {
        $d_primer_reg_cond = " AND d.primer_reg = " . (int) $target_reg_val;
    }

    if ($exclude_val) {
        $d_primer_reg_cond .= " AND d.$in_datos != '" . esc_sql($exclude_val) . "'";
    }

    $sub_sql_d_val = str_replace("WHERE 1=1", "AND 1=1", $sql_d ?: "WHERE 1=1");
    $sub_sql_d = str_replace(
        array("AND fecha", "AND id_pag", "AND source", "AND medium", "AND campaign", "AND afiliado"),
        array("AND d.fecha", "AND d.id_pag", "AND d.source", "AND d.medium", "AND d.campaign", "AND d.afiliado"),
        $sub_sql_d_val
    );

    $sql = "
    SELECT
        $source_sel
        $sel_name,
        $v_cnt as visitas,
        (SELECT COUNT(*) FROM $table_datos d WHERE d.$in_datos$dcoll = v.$in_visitas $source_col $sub_sql_d $d_primer_reg_cond AND (d.placement != 'correo' OR d.placement IS NULL)) as registrados,
        (SELECT COUNT(*) FROM $table_datos d WHERE d.$in_datos$dcoll = v.$in_visitas $source_col $sub_sql_d $d_primer_reg_cond AND (d.placement != 'correo' OR d.placement IS NULL) AND (d.medium != 'lista de espera' OR d.medium IS NULL)) as registrados_conv
    FROM $table_visitas v
    $join
    $sql_v $where_v_extra
    GROUP BY $group_by
    ORDER BY visitas DESC
    LIMIT 50
    ";

    $res = $wpdb->get_results($sql);
    $data = [];
    if (!empty($res)) {
        foreach ($res as $r) {
            // Rate uses registrados_conv (excludes 'lista de espera'); display column uses full registrados
            $r_rate = ($r->visitas > 0) ? ($r->registrados_conv / $r->visitas) * 100 : 0;
            $r_dim = $r->dimension ? $r->dimension : 'Sin definir';

            // Translate affiliate ID to name (handles both 'afiliado' and 'ref' columns)
            $dim_label = (in_array($by_column, ['afiliado', 'ref'])) ? get_affiliate_name($r_dim) : $r_dim;
            $dim_label2 = '';
            if ($include_source) {
                $r_dim2 = isset($r->dimension2) ? $r->dimension2 : 'Sin definir';
                $dim_label2 = (isset($select_column2) && $select_column2 === 'afiliado') ? get_affiliate_name($r_dim2) : $r_dim2;
            }

            if ($include_source) {
                $data[] = [$dim_label2, $dim_label, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            } elseif ($has_page_slug) {
                $data[] = [$dim_label, $r->page_slug, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            } else {
                $data[] = [$dim_label, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            }
        }
    }

    $color_class = '';
    if ($force_color === '#10b981')
        $color_class = 'accent-green';
    elseif ($force_color === '#facc15')
        $color_class = 'accent-yellow';
    elseif ($force_color === '#3b82f6')
        $color_class = 'accent-blue'; // Added for affiliates if needed

    return [
        'type' => 'table',
        'title' => $title,
        'id' => 'table_' . sanitize_title($title), // Added ID for export
        'class' => $color_class, // Added class for styling
        'headers' => ($include_source ? ['Fuente', 'Medio', $visit_label_h, 'Registrados', 'Tasa Conversión'] : ($has_page_slug ? ['Página', 'Ruta', $visit_label_h, 'Registrados', 'Tasa Conversión'] : ['Página / Origen', $visit_label_h, 'Registrados', 'Tasa Conversión'])),
        'data' => $data
    ];
}

// Source color mapping helper
function get_source_color($source)
{
    $s = strtolower(trim($source));
    if (strpos($s, 'organic') !== false || strpos($s, 'organico') !== false || $s === 'organico')
        return '#10b981'; // Green
    if (strpos($s, 'publicidad') !== false || strpos($s, 'paid') !== false || strpos($s, 'cpc') !== false || $s === 'publicidad')
        return '#facc15'; // Vivid Yellow
    if (strpos($s, 'afiliado') !== false || strpos($s, 'affiliate') !== false || $s === 'afiliado')
        return '#3b82f6'; // Blue
    return '#64748b'; // Gray for others
}

function get_time_series($select_column, $title, $table, $exclude_val = '', $force_color = '')
{
    global $wpdb, $table_visitas, $table_datos, $sql_v, $sql_d, $primer_reg_cond_total, $visit_count_expr;
    if (empty($table_visitas))
        $table_visitas = $wpdb->prefix . 'visitas';
    if (empty($table_datos))
        $table_datos = $wpdb->prefix . 'datos';
    if (empty($sql_v))
        $sql_v = "WHERE 1=1";
    if (empty($sql_d))
        $sql_d = "WHERE 1=1";
    if (empty($primer_reg_cond_total))
        $primer_reg_cond_total = " AND primer_reg = 1";
    $is_d = ($table == 'datos');
    $tbl = $is_d ? $table_datos : $table_visitas;
    $whr = $is_d ? $sql_d . $primer_reg_cond_total : $sql_v;
    if ($exclude_val) {
        $whr .= " AND $select_column != '" . esc_sql($exclude_val) . "'";
    }
    $cnt = $is_d ? "COUNT(*)" : (!empty($visit_count_expr) ? $visit_count_expr : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))");

    $is_source_col = in_array($select_column, ['ref_s', 'source']);

    $sql = "
    SELECT DATE(fecha) as date, $select_column as dimension, $cnt as total
    FROM $tbl
    $whr
    GROUP BY DATE(fecha), $select_column
    ORDER BY DATE(fecha) ASC
    ";
    $res = $wpdb->get_results($sql);

    $labels = [];
    $datasets = [];
    foreach ($res as $r) {
        if (!in_array($r->date, $labels))
            $labels[] = $r->date;
        $dim = $r->dimension ? $r->dimension : 'Sin definir';

        // Translate affiliate ID to name for datasets (check for both 'afiliado' and 'ref')
        $dim_label = (in_array($select_column, ['afiliado', 'medium', 'ref'])) ? get_affiliate_name($dim) : $dim;

        if (!isset($datasets[$dim_label]))
            $datasets[$dim_label] = [];
        $datasets[$dim_label][$r->date] = $r->total;
    }

    $fallback_colors = ['#ec4899', '#a855f7', '#3b82f6', '#10b981', '#facc15', '#f43f5e', '#8b5cf6', '#0ea5e9', '#14b8a6'];
    $formatted_datasets = [];
    $i = 0;
    foreach ($datasets as $dim => $data) {
        $data_array = [];
        foreach ($labels as $l) {
            $data_array[] = isset($data[$l]) ? $data[$l] : 0;
        }
        if ($force_color) {
            $color = $force_color;
        } elseif ($is_source_col) {
            $color = get_source_color($dim);
        } else {
            $color = $fallback_colors[$i % count($fallback_colors)];
        }
        $formatted_datasets[] = [
            'label' => $dim,
            'data' => $data_array,
            'borderColor' => $color,
            'backgroundColor' => $color . '22',
            'borderWidth' => 2,
            'fill' => true,
            'tension' => 0.4
        ];
        $i++;
    }

    return [
        'type' => 'line',
        'title' => $title,
        'labels' => $labels,
        'datasets' => $formatted_datasets
    ];
}

function get_bar_chart($select_column, $title, $table, $exclude_val = '', $force_color = '')
{
    global $wpdb, $table_visitas, $table_datos, $sql_v, $sql_d, $primer_reg_cond_total, $visit_count_expr;
    if (empty($table_visitas))
        $table_visitas = $wpdb->prefix . 'visitas';
    if (empty($table_datos))
        $table_datos = $wpdb->prefix . 'datos';
    if (empty($sql_v))
        $sql_v = "WHERE 1=1";
    if (empty($sql_d))
        $sql_d = "WHERE 1=1";
    if (empty($primer_reg_cond_total))
        $primer_reg_cond_total = " AND primer_reg = 1";
    $is_d = ($table == 'datos');
    $tbl = $is_d ? $table_datos : $table_visitas;
    $whr = $is_d ? $sql_d . $primer_reg_cond_total : $sql_v;
    if ($exclude_val) {
        $whr .= " AND $select_column != '" . esc_sql($exclude_val) . "'";
    }
    $cnt = $is_d ? "COUNT(*)" : (!empty($visit_count_expr) ? $visit_count_expr : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))");

    $is_source_col = in_array($select_column, ['ref_s', 'source']);

    $sql = "
    SELECT $select_column as dimension, $cnt as total
    FROM $tbl
    $whr
    GROUP BY $select_column
    ORDER BY total DESC
    LIMIT 15
    ";
    $res = $wpdb->get_results($sql);

    $labels = [];
    $data = [];
    $bar_colors = [];
    $fallback_colors = ['#ec4899', '#a855f7', '#3b82f6', '#10b981', '#facc15', '#f43f5e', '#8b5cf6', '#0ea5e9', '#14b8a6', '#f97316', '#06b6d4', '#d946ef'];

    $i = 0;
    foreach ($res as $r) {
        $dim = $r->dimension ? $r->dimension : 'Sin definir';
        $labels[] = ($select_column === 'afiliado') ? get_affiliate_name($dim) : $dim;
        $data[] = $r->total;
        if ($force_color) {
            $bar_colors[] = $force_color;
        } elseif ($is_source_col) {
            $bar_colors[] = get_source_color($dim);
        } else {
            // Use palette for variety (useful for countries, pages, etc.)
            $bar_colors[] = $fallback_colors[$i % count($fallback_colors)];
        }
        $i++;
    }

    return [
        'type' => 'bar',
        'title' => $title,
        'labels' => $labels,
        'data' => $data,
        'colors' => $bar_colors
    ];
}

function get_pie_chart($select_column, $title, $table, $exclude_val = '', $force_color = '')
{
    $bar = get_bar_chart($select_column, $title, $table, $exclude_val, $force_color);
    $bar['type'] = 'pie';
    return $bar;
}

// SECTION 2: Fuente
$dashboard['sections'][] = [
    'title' => '2. Sesiones y Registrados por Fuente',
    'id' => 'sec_source',
    'icon' => 'fa-compass',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('ref_s', 'Fuente: Tasa de Conversión', 'ref_s', 'source', false, false, false),
        get_pie_chart('source', 'TODOS LOS Registrados por fuente', 'datos'),
        get_time_series('source', 'Registrados por fuente temporal', 'datos'),
        get_time_series('ref_s', 'Sesiones por fuente temporal', 'visitas')

    ]
];

// SECTIONS 3-8: Lazy — se cargan bajo demanda al hacer clic en el header
$s3_src = isset($_GET['s3_src']) ? sanitize_text_field($_GET['s3_src']) : 'todo';
$s4_src = isset($_GET['s4_src']) ? sanitize_text_field($_GET['s4_src']) : 'todo';
$_filter_opts_country = ['todo' => 'TODO', 'organico' => 'ORGÁNICO', 'publicidad' => 'PUBLICIDAD', 'afiliado' => 'AFILIADOS'];
$_filter_opts = ['todo' => 'TODO', 'organico' => 'ORGÁNICO', 'publicidad' => 'PUBLICIDAD'];
$dashboard['sections'][] = ['title' => '3. Datos por País', 'id' => 'sec_country', 'icon' => 'fa-globe', 'layout' => 'two_col', 'lazy' => true, 'items' => [], 'quick_filter' => ['key' => 's3_src', 'current' => $s3_src, 'options' => $_filter_opts_country]];
$dashboard['sections'][] = ['title' => '4. Medios', 'id' => 'sec_medium', 'icon' => 'fa-bullseye', 'layout' => 'two_col', 'lazy' => true, 'items' => [], 'quick_filter' => ['key' => 's4_src', 'current' => $s4_src, 'options' => $_filter_opts]];
$dashboard['sections'][] = ['title' => '5. Tasa de Conversión por página', 'id' => 'sec_pages', 'icon' => 'fa-file-invoice', 'layout' => 'two_col', 'lazy' => true, 'items' => []];
$dashboard['sections'][] = ['title' => '6. Afiliados', 'id' => 'sec_affiliates', 'icon' => 'fa-users', 'layout' => 'two_col', 'lazy' => true, 'items' => []];
$dashboard['sections'][] = ['title' => '7. Campañas', 'id' => 'sec_campaigns', 'icon' => 'fa-flag', 'layout' => 'two_col', 'lazy' => true, 'items' => []];
$dashboard['sections'][] = ['title' => '8. Listado de registrados por correo', 'id' => 'sec_emails', 'icon' => 'fa-envelope', 'layout' => 'full_width', 'lazy' => true, 'items' => []];

$texts = get_option('endtrack_texts', array());
// Logo: usa el ajuste "Logo Admin (URL)" del panel admin; si está vacío, favicon por defecto.
$logo_url = !empty($texts['logo_admin_panel']) ? $texts['logo_admin_panel'] : 'https://visualbusiness.school/wp-content/uploads/2026/03/favicon-bss.png';

$json_dashboard = json_encode($dashboard);
$endtrack_nonce = wp_create_nonce('endtrack_load_section');

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas PRO - ENDTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-glow: rgba(99, 102, 241, 0.4);
            --secondary: #10b981;
            --accent: #f59e0b;
            --danger: #f43f5e;
            --bg-dark: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-dark);
            background-image:
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(16, 185, 129, 0.1) 0px, transparent 50%);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            background-attachment: fixed;
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .header {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 40px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-area img {
            height: 45px;
        }

        .logo-area h1 {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .filters-panel {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        select,
        input[type="date"] {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            width: 100%;
        }

        /* Export Button */
        .btn-export {
            font-size: 11px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15), rgba(255, 255, 255, 0.05));
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 6px 14px;
            border-radius: 99px;
            margin-left: 10px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(8px);
            font-weight: 600;
        }

        .btn-export i {
            font-size: 12px;
            color: var(--secondary);
        }

        .btn-export:hover {
            background: var(--primary);
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .quick-nav {
            position: -webkit-sticky;
            position: sticky;
            top: 0;
            z-index: 9999;
            background: transparent;
            padding: 15px 0;
            margin: 10px 0 30px 0;
            border-bottom: 1px solid transparent;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            transition: all 0.3s ease;
        }

        .quick-nav.is-sticky {
            padding: 15px 30px;
            margin-left: -30px;
            margin-right: -30px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        /* Full width background trick when sticky */
        .quick-nav.is-sticky::after {
            content: '';
            position: absolute;
            top: 0;
            left: -50vw;
            width: 200vw;
            height: 100%;
            background: inherit;
            backdrop-filter: inherit;
            -webkit-backdrop-filter: inherit;
            z-index: -1;
            border-bottom: inherit;
        }

        /* WordPress Admin Bar Fix */
        body.admin-bar .quick-nav {
            top: 32px;
        }

        @media screen and (max-width: 782px) {
            body.admin-bar .quick-nav {
                top: 46px;
            }
        }

        .btn-nav {
            font-size: 11px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-muted);
            border: 1px solid var(--card-border);
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-nav:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        .btn-filter, .btn-page, .btn-sfilter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        .btn-sfilter {
            padding: 4px 10px;
            font-size: 10px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-muted);
            box-shadow: none;
            border: 1px solid var(--card-border);
            text-transform: uppercase;
        }

        .btn-sfilter:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-sfilter.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 2px 8px var(--primary-glow);
        }

        .section-header-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 10px;
        }

        .section-header-wrap h2 {
            margin-bottom: 0;
            border-bottom: none;
        }

        .section-quick-filters {
            display: flex;
            gap: 5px;
            background: rgba(0, 0, 0, 0.2);
            padding: 4px;
            border-radius: 8px;
        }

        .nav-legend {
            margin-left: auto;
            display: flex;
            gap: 15px;
            background: rgba(30, 41, 59, 0.5);
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            align-items: center;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .leg-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .leg-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px var(--primary-glow);
        }

        /* Render Blocks */
        .dashboard-section {
            margin-bottom: 50px;
            scroll-margin-top: 120px; /* Offset for sticky header */
            opacity: 0;
            transform: translateY(20px);
            animation: fadeIn 0.5s forwards ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .section-title {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 10px;
        }

        .section-title i {
            color: var(--primary);
        }

        .grid-kpi {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .grid-2col {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
        }

        .panel-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            padding: 25px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }

        /* KPIs */
        .kpi-card {
            position: relative;
            overflow: hidden;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--bg-color);
        }

        .kpi-label {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 10px;
            display: block;
        }

        .kpi-value {
            font-size: 48px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -1px;
            display: block;
            margin-bottom: 5px;
        }

        .kpi-card.pink .kpi-value { color: #ec4899; text-shadow: 0 0 20px rgba(236, 72, 153, 0.3); }
        .kpi-card.purple .kpi-value { color: #a855f7; text-shadow: 0 0 20px rgba(168, 85, 247, 0.3); }
        .kpi-card.orange .kpi-value { color: #fb923c; text-shadow: 0 0 20px rgba(251, 146, 60, 0.3); }

        .kpi-card.small-text .kpi-value {
            font-size: 16px;
            line-height: 1.4;
            font-weight: 600;
            letter-spacing: normal;
        }

        /* Funnel */
        .funnel-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .funnel-step {
            background: rgba(255, 255, 255, 0.05);
            padding: 12px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .funnel-label {
            font-size: 14px;
            font-weight: 600;
        }

        .funnel-val {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
        }

        /* Tables */
        .table-responsive {
            max-height: 400px; /* Alrededor de 8-9 filas */
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .table-responsive::-webkit-scrollbar {
            width: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            text-align: left;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .table-pro.accent-green th { border-bottom: 2px solid #10b981; color: #10b981; }
        .table-pro.accent-yellow th { border-bottom: 2px solid #facc15; color: #facc15; }
        .table-pro.accent-blue th { border-bottom: 2px solid #3b82f6; color: #3b82f6; }

        .table-pro tr {
            border-bottom: 1px solid var(--card-border);
            transition: background 0.2s;
        }

        td {
            padding: 12px;
            font-size: 13px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        }

        .panel-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* === Ad Spend Section === */
        .adspend-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .adspend-kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .adspend-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .adspend-kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--kpi-accent, var(--primary));
            border-radius: 16px 16px 0 0;
        }

        .adspend-kpi-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: block;
        }

        .adspend-kpi-value {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -1px;
            display: block;
        }

        .adspend-chart-wrap {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .adspend-chart-wrap .panel-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .adspend-chart-canvas {
            height: 350px;
            position: relative;
        }

        .adspend-table-wrapper {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .adspend-table-wrapper th {
            position: sticky;
            top: 0;
            background: #1e293b; /* Solid color to prevent transparency issues when scrolling */
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .adspend-loading {
            text-align: center;
            padding: 60px 20px;
            opacity: 0.6;
        }

        .adspend-loading i {
            font-size: 28px;
            margin-bottom: 12px;
            display: block;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .adspend-error {
            text-align: center;
            padding: 40px 20px;
            color: var(--danger);
            background: rgba(244, 63, 94, 0.1);
            border: 1px solid rgba(244, 63, 94, 0.2);
            border-radius: 12px;
        }

        .adspend-table {
            margin-top: 24px;
        }

        .adspend-table .table-pro td:nth-child(2),
        .adspend-table .table-pro td:nth-child(3),
        .adspend-table .table-pro td:nth-child(4),
        .adspend-table .table-pro td:nth-child(5),
        .adspend-table .table-pro td:nth-child(6) {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .btn-refresh-cache {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: var(--text-muted);
            padding: 6px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .btn-refresh-cache:hover {
            background: rgba(255,255,255,0.15);
            color: var(--text-main);
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="header">
            <div class="header-top">
                <div class="logo-area">
                    <img src="<?php echo $logo_url; ?>" alt="VBS Logo">
                    <h1>Estadísticas PRO</h1>
                </div>
                <a href="/endtrack-estadisticas-ventas/?launch=<?php echo urlencode($active_launch); ?>" class="btn-nav" style="font-size:12px;padding:8px 14px;">
                    <i class="fas fa-shopping-cart"></i> Ver Ventas
                </a>
            </div>

            <form method="GET" class="filters-panel">
                <input type="hidden" name="count_mode" value="<?php echo esc_attr($count_mode); ?>" id="count_mode_hidden">
                <div class="filter-group">
                    <label>Lanzamiento</label>
                    <select name="launch" onchange="this.form.submit()">
                        <option value="legacy" <?php selected($active_launch, 'legacy'); ?>>Anteriores</option>
                        <?php
                        $launches = get_option('endtrack_launches', array());
                        foreach (array_reverse($launches) as $l): ?>
                            <option value="<?php echo esc_attr($l); ?>" <?php selected($active_launch, $l); ?>>
                                <?php echo esc_html(ucfirst($l)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Página</label>
                    <select name="var_page">
                        <option value="">Todas</option>
                        <?php foreach ($filter_options['pages'] as $p): ?>
                                                                                                                                <option value="<?php echo $p->id; ?>" <?php selected($filter_page, $p->id); ?>>
                                                                                                                                    <?php echo esc_html($p->title); ?>
                                                                                                                                </option>
                                                                                                                                <?php
                        endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Fuente (Source)</label>
                    <select name="var_source">
                        <option value="">Todas</option>
                        <?php foreach ($filter_options['sources'] as $s): ?>
                                                                                                                                <option value="<?php echo esc_attr($s); ?>" <?php selected($filter_source, $s); ?>>
                                                                                                                                    <?php echo esc_html($s); ?>
                                                                                                                                </option>
                                                                                                                                <?php
                        endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Medio</label>
                    <select name="var_medium">
                        <option value="">Todos</option>
                        <?php foreach ($filter_options['mediums'] as $m): ?>
                                                                                                                                <option value="<?php echo esc_attr($m); ?>" <?php selected($filter_medium, $m); ?>>
                                                                                                                                    <?php echo esc_html($m); ?>
                                                                                                                                </option>
                                                                                                                                <?php
                        endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Campaña</label>
                    <select name="var_campaign">
                        <option value="">Todas</option>
                        <?php foreach ($filter_options['campaigns'] as $c): ?>
                                                                                                                                <option value="<?php echo esc_attr($c); ?>" <?php selected($filter_campaign, $c); ?>>
                                                                                                                                    <?php echo esc_html($c); ?>
                                                                                                                                </option>
                                                                                                                                <?php
                        endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Afiliado</label>
                    <select name="var_afiliado">
                        <option value="">Todos</option>
                        <?php foreach ($filter_options['afiliados'] as $a): ?>
                                                                                                                                <option value="<?php echo esc_attr($a); ?>" <?php selected($filter_afiliado, $a); ?>>
                                                                                                                                    <?php echo get_affiliate_name($a); ?> (<?php echo esc_html($a); ?>)
                                                                                                                                </option>
                                                                                                                                <?php
                        endforeach; ?>
                    </select>
                </div>

                <div class="filter-group" style="min-width: 120px;">
                    <label>Desde</label>
                    <input type="date" name="d_start" value="<?php echo $filter_date_start; ?>">
                </div>
                <div class="filter-group" style="min-width: 120px;">
                    <label>Hasta</label>
                    <input type="date" name="d_end" value="<?php echo $filter_date_end; ?>">
                </div>

                <div class="filter-group">
                    <label>Conteo de visitas</label>
                    <div style="display:flex; gap:0; border-radius:8px; overflow:hidden; border:1px solid rgba(255,255,255,0.15);">
                        <button type="submit" class="btn-filter" onclick="document.getElementById('count_mode_hidden').value='sessions';" style="border-radius:0; margin:0; <?php echo ($count_mode === 'sessions') ? 'background:linear-gradient(135deg,#ec4899,#a855f7);' : 'background:rgba(255,255,255,0.08); box-shadow:none;'; ?>">
                            Sesiones
                        </button>
                        <button type="submit" class="btn-filter" onclick="document.getElementById('count_mode_hidden').value='unique_ip';" style="border-radius:0; margin:0; <?php echo ($count_mode === 'unique_ip') ? 'background:linear-gradient(135deg,#ec4899,#a855f7);' : 'background:rgba(255,255,255,0.08); box-shadow:none;'; ?>">
                            IP Única
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-filter">Aplicar Filtros</button>
                <?php if ($filter_source || $filter_medium || $filter_campaign || $filter_afiliado || $filter_page): ?>
                                                                                                                        <a href="?launch=<?php echo urlencode($active_launch); ?>" class="btn-filter"
                                                                                                                            style="background:rgba(255,255,255,0.1); box-shadow:none; text-decoration:none; text-align:center;">Limpiar</a>
                                                                                                                        <?php
                endif; ?>
            </form>
        </div><!-- /.header -->

        <div class="quick-nav">
            <a href="#sec_kpi" class="btn-nav"><i class="fas fa-tachometer-alt"></i> KPIs</a>
            <a href="#sec_source" class="btn-nav"><i class="fas fa-compass"></i> Fuentes</a>
            <a href="#sec_country" class="btn-nav"><i class="fas fa-globe"></i> Países</a>
            <a href="#sec_medium" class="btn-nav"><i class="fas fa-bullseye"></i> Medios</a>
            <a href="#sec_pages" class="btn-nav"><i class="fas fa-file-invoice"></i> Páginas</a>
            <a href="#sec_affiliates" class="btn-nav"><i class="fas fa-users"></i> Afiliados</a>
            <a href="#sec_campaigns" class="btn-nav"><i class="fas fa-flag"></i> Campañas</a>
            <a href="#sec_emails" class="btn-nav"><i class="fas fa-envelope"></i> Correos</a>
            <a href="#sec_adspend" class="btn-nav"><i class="fas fa-euro-sign"></i> Gasto/CPL</a>
            <div class="nav-legend">
                <div class="leg-item"><span class="leg-dot" style="background: #ec4899;"></span> Sesiones</div>
                <div class="leg-item"><span class="leg-dot" style="background: #a855f7;"></span> Leads</div>
                <div class="leg-item"><span class="leg-dot" style="background: #10b981;"></span> Orgánico</div>
                <div class="leg-item"><span class="leg-dot" style="background: #facc15;"></span> Publicidad</div>
                <div class="leg-item"><span class="leg-dot" style="background: #3b82f6;"></span> Afiliado</div>
            </div>
        </div>

        <!-- Container for dynamic sections -->
        <div id="dashboard-root"></div>
    </div>

    <script>
        const dashboardData  = <?php echo $json_dashboard; ?>;
        const activeLaunch   = <?php echo json_encode($active_launch); ?>;
        const endtrackNonce  = <?php echo json_encode($endtrack_nonce); ?>;
        const endtrackAjax   = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
        const endtrackParams = (new URLSearchParams(window.location.search)).toString();
        const root = document.getElementById('dashboard-root');

        // Theme Colors
        const colors = {
            indigo: '#6366f1', 
            emerald: '#10b981', // Organic
            amber: '#facc15',   // Paid (Updated to #facc15)
            danger: '#f43f5e', 
            blue: '#3b82f6',    // Affiliate
            pink: '#ec4899',    // Visits
            purple: '#a855f7',  // Registrados
            defaultColors: ['#3b82f6', '#facc15', '#10b981', '#ec4899', '#a855f7', '#6366f1', '#f97316', '#8b5cf6', '#0ea5e9']
        };
        Chart.register(ChartDataLabels);
        Chart.defaults.set('plugins.datalabels', {
            color: '#fff',
            font: { weight: 'bold', size: 11 },
            formatter: (value, ctx) => {
                let sum = ctx.dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                if (sum === 0) return null;
                let percentage = (Number(value) * 100 / sum).toFixed(1) + "%";
                return percentage === "0.0%" ? null : percentage;
            },
            display: (ctx) => {
                const type = ctx.chart.config.type;
                if (type !== 'doughnut' && type !== 'pie') return false;
                const value = Number(ctx.dataset.data[ctx.dataIndex]);
                const sum = ctx.dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                return (value * 100 / sum) > 3; // Solo mostrar si es mayor al 3% para evitar solapamiento
            },
            anchor: 'center',
            align: 'center',
            offset: 0
        });
        Chart.defaults.color = '#94a3b8';
        Chart.defaults.font.family = "'Outfit', sans-serif";

        function renderKPI(item) {
            let colorHex = colors[item.color] || colors.indigo;
            const isSmall = item.text_size === 'small';
            return `
        <div class="panel-box kpi-card" style="--bg-color: ${colorHex}">
            <span class="kpi-label">${item.title}</span>
            <span class="kpi-value" style="color: ${colorHex}; ${isSmall ? 'font-size: 22px; letter-spacing: 0;' : ''}">${item.value}</span>
        </div>
    `;
        }

        function renderFunnel(item) {
            let html = `<div class="panel-box kpi-card"><span class="kpi-label">${item.title}</span><div class="funnel-container">`;
            let prev = 0;
            item.data.forEach((step, i) => {
                let pct = "";
                if (i > 0 && prev > 0) {
                    pct = `<span style="font-size:11px; color:#10b981; padding:2px 6px; background:rgba(16,185,129,0.1); border-radius:4px;">${((step.value / prev) * 100).toFixed(1)}%</span>`;
                }
                html += `<div class="funnel-step"><span class="funnel-label">${step.label}</span><div style="display:flex; align-items:center; gap:10px;">${pct} <span class="funnel-val">${step.value.toLocaleString()}</span></div></div>`;
                prev = step.value;
            });
            html += '</div></div>';
            return html;
        }

        function sortTable(tableId, colIndex) {
            const table = document.getElementById(tableId);
            if (!table) return;
            const tbody = table.tBodies[0];
            const rows = Array.from(tbody.rows);
            
            // Toggle direction
            const currentDir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
            table.setAttribute('data-sort-dir', currentDir);
            table.setAttribute('data-sort-col', colIndex);

            // Update icons
            table.querySelectorAll('th i').forEach(i => {
                i.className = 'fas fa-sort';
                i.style.opacity = '0.3';
            });
            const thIcon = table.querySelectorAll('th')[colIndex].querySelector('i');
            thIcon.className = currentDir === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
            thIcon.style.opacity = '1';

            const sortedRows = rows.sort((a, b) => {
                let aVal = a.cells[colIndex].textContent.trim();
                let bVal = b.cells[colIndex].textContent.trim();

                // Check if it's potentially numeric (sessions, registered, percentage)
                const isNumeric = /^[0-9.,%]+$/.test(aVal.replace(/\s/g, '')) && /^[0-9.,%]+$/.test(bVal.replace(/\s/g, ''));

                if (isNumeric) {
                    // Normalize number: remove thousand dots, replace comma with dot, remove %
                    const clean = (val) => parseFloat(val.replace(/\./g, '').replace(',', '.').replace('%', '')) || 0;
                    const aNum = clean(aVal);
                    const bNum = clean(bVal);
                    return currentDir === 'asc' ? aNum - bNum : bNum - aNum;
                }
                
                return currentDir === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
            });

            tbody.append(...sortedRows);
        }

        function renderTable(item) {
            let tableId = item.id || 'table_' + Math.random().toString(36).substr(2, 9);
            let tableClass = item.class || '';
            let html = `<div class="panel-box">
                <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
                    ${item.title}
                    <button class="btn-export" onclick="exportCSV('${tableId}', '${item.title}')"><i class="fas fa-download"></i> CSV</button>
                </div>
                <div class="table-responsive"><table id="${tableId}" class="table-pro ${tableClass}"><thead><tr>`;
            
            item.headers.forEach((h, idx) => {
                html += `<th onclick="sortTable('${tableId}', ${idx})" style="cursor:pointer; user-select:none; white-space:nowrap;">
                    ${h} <i class="fas fa-sort" style="margin-left:5px; opacity:0.3; font-size:11px;"></i>
                </th>`;
            });
            
            html += `</tr></thead><tbody>`;
            if (item.data.length === 0) {
                html += `<tr><td colspan="${item.headers.length}" style="text-align:center; opacity:0.5;">No hay datos disponibles</td></tr>`;
            } else {
                item.data.forEach(row => {
                    html += `<tr>`;
                    row.forEach(cell => html += `<td>${cell}</td>`);
                    html += `</tr>`;
                });
            }
            html += `</tbody></table></div></div>`;
            return html;
        }

        function renderAjaxTable(item) {
            return `
                <div class="panel-box" id="ajax_wrapper_${item.action}">
                    <div class="panel-title">${item.title}</div>
                    <div class="search-area">
                        <input type="text" class="search-input" id="search_${item.action}" placeholder="Buscar correo...">
                        <button class="btn-filter" onclick="loadAjaxTable('${item.action}', 1)">Buscar</button>
                    </div>
                    <div id="data_${item.action}">
                        <button class="btn-filter" style="width:100%" onclick="loadAjaxTable('${item.action}', 1)">Cargar Listado de Correos</button>
                    </div>
                </div>
            `;
        }

        // Sticky Nav Logic
        window.addEventListener('scroll', () => {
            const nav = document.querySelector('.quick-nav');
            if (!nav) return;
            const threshold = document.body.classList.contains('admin-bar') ? 33 : 1;
            if (window.scrollY > nav.parentElement.offsetTop + nav.offsetTop - threshold) {
                nav.classList.add('is-sticky');
            } else {
                nav.classList.remove('is-sticky');
            }
        });

        async function loadAjaxTable(action, page) {
            const container = document.getElementById('data_' + action);
            const search = document.getElementById('search_' + action)?.value || '';
            
            container.innerHTML = '<div style="text-align:center; padding:40px; opacity:0.5;"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';

            const formData = new FormData();
            formData.append('action', action);
            formData.append('paged', page);
            formData.append('search', search);

            try {
                const response = await fetch('/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData
                });
                const res = await response.json();
                
                if (res.success) {
                    let html = `<div class="table-responsive"><table id="table_${action}" class="table-pro"><thead><tr>
                        <th>Correo</th><th>Source</th><th>Medium</th><th>Afiliado</th><th>Campaign</th><th>Fecha</th><th>Tipo</th>
                    </tr></thead><tbody>`;
                    
                    res.data.data.forEach(r => {
                        html += `<tr>
                            <td>${r.correo}</td>
                            <td>${r.source}</td>
                            <td>${r.medium}</td>
                            <td>${r.afiliado}</td>
                            <td>${r.campaign}</td>
                            <td>${r.fecha_crea}</td>
                            <td>${r.primer_reg}</td>
                        </tr>`;
                    });

                    if (res.data.data.length === 0) {
                         html += `<tr><td colspan="7" style="text-align:center; padding:20px;">No se encontraron resultados</td></tr>`;
                    }

                    html += `</tbody></table></div>`;

                    // Pagination
                    html += `<div class="pagination-area">
                        <button class="btn-page" ${page <= 1 ? 'disabled' : ''} onclick="loadAjaxTable('${action}', ${page - 1})">Anterior</button>
                        <span>Página ${res.data.current_page} de ${res.data.pages} (Total: ${res.data.total})</span>
                        <button class="btn-page" ${page >= res.data.pages ? 'disabled' : ''} onclick="loadAjaxTable('${action}', ${page + 1})">Siguiente</button>
                    </div>`;

                    // Add export button to the title area if not already there
                    const titleArea = container.closest('.panel-box').querySelector('.panel-title');
                    if (!titleArea.querySelector('.btn-export')) {
                        titleArea.innerHTML += `<button class="btn-export" onclick="exportCSV('table_${action}', 'Listado Correos')"><i class="fas fa-download"></i> CSV</button>`;
                    }

                    container.innerHTML = html;
                }
            } catch (err) {
                container.innerHTML = 'Error al cargar los datos: ' + err;
            }
        }

        function setSectionFilter(key, val, sectionId) {
            const url = new URL(window.location.href);
            url.searchParams.set(key, val);
            if (sectionId) url.hash = sectionId;
            window.location.href = url.href;
        }

        function exportCSV(tableId, filename) {
            let table = document.getElementById(tableId);
            let rows = Array.from(table.querySelectorAll('tr'));
            let csvContent = rows.map(row => {
                let cols = Array.from(row.querySelectorAll('th, td'));
                return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
            }).join('\n');

            let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            let link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.setAttribute('download', filename.replace(/\s+/g, '_') + '.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        window.__chartItems = window.__chartItems || {};

        function renderChartContainer(item, idx) {
            const chartId = `chart_${item.title.replace(/\s+/g, '_')}_${idx}`;
            window.__chartItems[chartId] = item;
            return `<div class="panel-box">
                <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
                    ${item.title}
                    <button class="btn-export" onclick="exportChartCSV('${chartId}')"><i class="fas fa-download"></i> CSV</button>
                </div>
                <div class="chart-container"><canvas id="${chartId}"></canvas></div>
            </div>`;
        }

        function exportChartCSV(chartId) {
            const item = window.__chartItems[chartId];
            if (!item) return;
            const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
            let csv = '';
            if (item.type === 'line' && Array.isArray(item.datasets)) {
                const headers = ['Fecha', ...item.datasets.map(d => d.label)];
                csv = headers.map(esc).join(',') + '\n';
                (item.labels || []).forEach((label, i) => {
                    const row = [label, ...item.datasets.map(d => (d.data && d.data[i] != null) ? d.data[i] : 0)];
                    csv += row.map(esc).join(',') + '\n';
                });
            } else {
                csv = ['Etiqueta', 'Valor'].map(esc).join(',') + '\n';
                (item.labels || []).forEach((label, i) => {
                    csv += [label, (item.data && item.data[i] != null) ? item.data[i] : 0].map(esc).join(',') + '\n';
                });
            }
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.setAttribute('download', item.title.replace(/\s+/g, '_') + '.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // ── Lazy section loader ──────────────────────────────────────────
        const loadedSections  = {};
        const lazySectionExtra = {}; // stores active filter params per section

        async function loadSection(sectionId, forceReload) {
            const wrapper = document.getElementById('lazy-content-' + sectionId);
            if (!wrapper) return;
            if (loadedSections[sectionId] && !forceReload) return;
            loadedSections[sectionId] = true;

            wrapper.innerHTML = `<div style="padding:40px;text-align:center;opacity:.6;"><i class="fas fa-spinner fa-spin" style="font-size:28px;margin-bottom:12px;display:block;"></i>Cargando datos…</div>`;

            const body = new URLSearchParams(endtrackParams);
            // Merge any active filter params for this section
            const extra = lazySectionExtra[sectionId] || {};
            for (const [k, v] of Object.entries(extra)) body.set(k, v);
            body.set('action',     'endtrack_load_stats_section');
            body.set('nonce',      endtrackNonce);
            body.set('section_id', sectionId);
            // Always pass the page's active launch (URL may not include ?launch=)
            if (activeLaunch) body.set('launch', activeLaunch);

            try {
                const res  = await fetch(endtrackAjax, { method: 'POST', body });
                const json = await res.json();
                if (!json.success) { wrapper.innerHTML = '<p style="color:#f43f5e;padding:20px;">Error al cargar la sección.</p>'; loadedSections[sectionId] = false; return; }

                const sec = json.data.section;

                // Update filter buttons to reflect current active state
                if (sec.quick_filter) {
                    const filterArea = document.querySelector(`#${sectionId} .lazy-filter-area`);
                    if (filterArea) {
                        filterArea.querySelectorAll('.btn-sfilter').forEach(btn => {
                            btn.classList.toggle('active', btn.dataset.val === sec.quick_filter.current);
                        });
                    }
                }

                let layoutClass = sec.layout === 'kpi_grid' ? 'grid-kpi' : (sec.layout === 'two_col' ? 'grid-2col' : (sec.layout === 'full_width' ? 'full-width' : ''));
                let innerHtml = `<div class="${layoutClass}">`;
                sec.items.forEach((item, iIdx) => {
                    if (item.type === 'kpi')             innerHtml += renderKPI(item);
                    else if (item.type === 'funnel')      innerHtml += renderFunnel(item);
                    else if (item.type === 'table')       innerHtml += renderTable(item);
                    else if (item.type === 'ajax_table')  innerHtml += renderAjaxTable(item);
                    else if (['line','bar','pie'].includes(item.type)) innerHtml += renderChartContainer(item, iIdx);
                });
                innerHtml += `</div>`;
                wrapper.innerHTML = innerHtml;

                sec.items.forEach((item, idx) => {
                    if (['line','bar','pie'].includes(item.type)) initChart(item, idx);
                });
            } catch(e) {
                wrapper.innerHTML = '<p style="color:#f43f5e;padding:20px;">Error de conexión.</p>';
                loadedSections[sectionId] = false;
            }
        }

        // Reload a lazy section with a new filter param (no page navigation)
        function filterLazySection(sectionId, paramKey, paramVal) {
            if (!lazySectionExtra[sectionId]) lazySectionExtra[sectionId] = {};
            lazySectionExtra[sectionId][paramKey] = paramVal;

            // Open section if not open
            const content = document.getElementById('lazy-content-' + sectionId);
            if (content && content.style.display === 'none') {
                content.style.display = 'block';
                const btn = document.getElementById('btn-lazy-' + sectionId);
                if (btn) btn.innerHTML = '<i class="fas fa-chevron-up" style="margin-right:6px;"></i>Ocultar';
            }
            loadSection(sectionId, true); // force reload
        }

        // ── Render Loop ──────────────────────────────────────────────────
        dashboardData.sections.forEach((sec, sIdx) => {
            if (sec.lazy) {
                // Build filter buttons if section has quick_filter
                let lazyFilterHtml = '';
                if (sec.quick_filter) {
                    lazyFilterHtml = `<div class="section-quick-filters lazy-filter-area">`;
                    for (let [val, label] of Object.entries(sec.quick_filter.options)) {
                        const isActive = sec.quick_filter.current === val;
                        lazyFilterHtml += `<button class="btn-sfilter${isActive ? ' active' : ''}" data-val="${val}" onclick="filterLazySection('${sec.id}','${sec.quick_filter.key}','${val}')">${label}</button>`;
                    }
                    lazyFilterHtml += `</div>`;
                }

                // Lazy section: accordion collapsed, load on click
                const secHtml = `
                <div class="dashboard-section lazy-section" id="${sec.id}" style="animation-delay:${sIdx * 0.1}s">
                    <div class="section-header-wrap" style="flex-wrap:wrap;gap:10px;">
                        <h2 class="section-title" onclick="toggleLazy('${sec.id}')" style="cursor:pointer;"><i class="fas ${sec.icon}"></i> ${sec.title}</h2>
                        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                            ${lazyFilterHtml}
                            <button class="btn-sfilter lazy-toggle-btn" id="btn-lazy-${sec.id}" onclick="toggleLazy('${sec.id}')" style="background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);color:#a5b4fc;padding:6px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                                <i class="fas fa-chevron-down" style="margin-right:6px;"></i>Cargar datos
                            </button>
                        </div>
                    </div>
                    <div id="lazy-content-${sec.id}" class="lazy-content" style="display:none;"></div>
                </div>`;
                root.insertAdjacentHTML('beforeend', secHtml);
            } else {
                // Eager section: render immediately
                let filterHtml = "";
                if (sec.quick_filter) {
                    filterHtml = `<div class="section-quick-filters">`;
                    for (let [val, label] of Object.entries(sec.quick_filter.options)) {
                        filterHtml += `<button class="btn-sfilter ${sec.quick_filter.current === val ? 'active' : ''}" onclick="setSectionFilter('${sec.quick_filter.key}','${val}','${sec.id}')">${label}</button>`;
                    }
                    filterHtml += `</div>`;
                }
                let layoutClass = sec.layout === 'kpi_grid' ? 'grid-kpi' : (sec.layout === 'two_col' ? 'grid-2col' : (sec.layout === 'full_width' ? 'full-width' : ''));
                let secHtml = `
                <div class="dashboard-section" id="${sec.id}" style="animation-delay:${sIdx * 0.1}s">
                    <div class="section-header-wrap">
                        <h2 class="section-title"><i class="fas ${sec.icon}"></i> ${sec.title}</h2>
                        ${filterHtml}
                    </div>
                    <div class="${layoutClass}">`;
                sec.items.forEach((item, iIdx) => {
                    if (item.type === 'kpi')        secHtml += renderKPI(item);
                    else if (item.type === 'funnel') secHtml += renderFunnel(item);
                    else if (item.type === 'table')  secHtml += renderTable(item);
                    else if (item.type === 'ajax_table') secHtml += renderAjaxTable(item);
                    else if (['line','bar','pie'].includes(item.type)) secHtml += renderChartContainer(item, iIdx);
                });
                secHtml += `</div></div>`;
                root.insertAdjacentHTML('beforeend', secHtml);
            }
        });

        function toggleLazy(sectionId) {
            const content = document.getElementById('lazy-content-' + sectionId);
            const btn     = document.getElementById('btn-lazy-' + sectionId);
            if (!content) return;
            const isOpen = content.style.display !== 'none';
            if (isOpen) {
                content.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-chevron-down" style="margin-right:6px;"></i>Cargar datos';
            } else {
                content.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-chevron-up" style="margin-right:6px;"></i>Ocultar';
                loadSection(sectionId);
            }
        }

        function hexToRgba(hex, alpha) {
            let r = 0, g = 0, b = 0;
            if (hex.length == 4) {
                r = parseInt(hex[1] + hex[1], 16);
                g = parseInt(hex[2] + hex[2], 16);
                b = parseInt(hex[3] + hex[3], 16);
            } else if (hex.length == 7) {
                r = parseInt(hex.slice(1, 3), 16);
                g = parseInt(hex.slice(3, 5), 16);
                b = parseInt(hex.slice(5, 7), 16);
            }
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        }

        function shadeColor(color, percent) {
            if (!color || typeof color !== 'string' || color.charAt(0) !== '#') return '#6366f1'; // Fallback color
            let f = parseInt(color.slice(1), 16),
                t = percent < 0 ? 0 : 255,
                p = percent < 0 ? percent * -1 : percent,
                R = f >> 16, G = f >> 8 & 0x00FF, B = f & 0x0000FF;
            return "#" + (0x1000000 + (Math.round((t - R) * p / 100) + R) * 0x10000 + (Math.round((t - G) * p / 100) + G) * 0x100 + (Math.round((t - B) * p / 100) + B)).toString(16).slice(1);
        }

        // Init Charts helper (used for eager sections and lazy sections after load)
        function initChart(item, idx) {
                    const canvas = document.getElementById(`chart_${item.title.replace(/\s+/g, '_')}_${idx}`);
                    if (canvas) {
                        const ctx = canvas.getContext('2d');
                        let config = { 
                            type: item.type, 
                            data: {}, 
                            options: { 
                                responsive: true, 
                                maintainAspectRatio: false,
                                plugins: {
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.label || '';
                                                let value = Number(context.raw) || 0;
                                                let total = context.dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                                                let percentage = ((value / total) * 100).toFixed(1) + '%';
                                                return label + ': ' + value.toLocaleString() + ' (' + percentage + ')';
                                            }
                                        }
                                    }
                                }
                            } 
                        };

                        if (item.type === 'line') {
                            config.data = { labels: item.labels, datasets: item.datasets };
                            config.options.plugins.legend = { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } };
                            config.options.scales = { x: { grid: { display: false } }, y: { grid: { color: 'rgba(255,255,255,0.05)' } } };
                        } else if (item.type === 'bar') {
                            config.data = { labels: item.labels, datasets: [{
                                data: item.data,
                                borderRadius: 8,
                                backgroundColor: (ctx) => {
                                    const baseColor = item.color || (colors && colors.indigo) || '#6366f1'; // Fallback color
                                    const index = ctx.dataIndex;
                                    const total = ctx.dataset.data.length;
                                    return shadeColor(baseColor, 20 - (index / total) * 50);
                                },
                            }]};
                            config.options.plugins.legend = { display: false };
                            config.options.indexAxis = item.labels.length > 5 ? 'y' : 'x';
                            config.options.scales = {
                                x: { grid: { color: 'rgba(255,255,255,0.05)' } },
                                y: { grid: { display: false } }
                            };
                        } else if (item.type === 'pie') {
                            config.type = 'doughnut';
                            const rawColors = item.colors || colors.defaultColors;
                            config.data = { labels: item.labels, datasets: [{
                                data: item.data,
                                borderWidth: 0,
                                hoverOffset: 12,
                                backgroundColor: (context) => {
                                    const {ctx, chartArea} = context.chart;
                                    const baseC = rawColors[context.dataIndex % rawColors.length];
                                    if (!chartArea || !baseC) return baseC;
                                    // Sombreado dinámico: cada porción se oscurece un poco para distinguirse
                                    const c = shadeColor(baseC, -(context.dataIndex * 5));
                                    const gradient = ctx.createRadialGradient(
                                        (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2, 0,
                                        (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2, (chartArea.right - chartArea.left) / 2
                                    );
                                    gradient.addColorStop(0, c);
                                    gradient.addColorStop(1, hexToRgba(c, 0.6));
                                    return gradient;
                                }
                            }]};
                            config.options.cutout = '70%';
                            config.options.plugins.legend = { 
                                position: 'right', 
                                labels: { 
                                    usePointStyle: true, 
                                    font: { size: 11 },
                                    generateLabels: (chart) => {
                                        const original = Chart.overrides.doughnut.plugins.legend.labels.generateLabels(chart);
                                        return original.map(label => {
                                            const baseC = rawColors[label.index % rawColors.length];
                                            label.fillStyle = shadeColor(baseC, -(label.index * 5));
                                            return label;
                                        });
                                    }
                                } 
                            };
                        }

                        new Chart(ctx, config);
                    }
        }

        // Init charts for eager (non-lazy) sections on page load
        dashboardData.sections.forEach(sec => {
            if (!sec.lazy) {
                sec.items.forEach((item, idx) => {
                    if (['line', 'bar', 'pie'].includes(item.type)) initChart(item, idx);
                });
            }
        });

        // ===============================================
        //  SECTION 9: Ad Spend + CPL (async loaded)
        // ===============================================
        (function() {
            const adspendConfig = {
                dateStart: '<?php echo esc_js($filter_date_start); ?>',
                dateEnd:   '<?php echo esc_js($filter_date_end); ?>',
                launch:    '<?php echo esc_js($active_launch); ?>',
                ajaxUrl:   '/wp-admin/admin-ajax.php'
            };

            // Insert section placeholder (collapsed — loads on demand)
            const secHtml = `
            <div class="dashboard-section lazy-section" id="sec_adspend" style="animation-delay: 0.9s">
                <div class="section-header-wrap">
                    <h2 class="section-title"><i class="fas fa-euro-sign"></i> Gasto Publicidad y CPL</h2>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button class="btn-refresh-cache" id="btn_adspend_refresh" title="Invalidar caché y recargar datos" style="display:none;">
                            <i class="fas fa-sync-alt"></i> Actualizar datos
                        </button>
                        <button class="btn-sfilter lazy-toggle-btn" id="btn-lazy-sec_adspend" onclick="toggleAdSpend()" style="background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);color:#a5b4fc;padding:6px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
                            <i class="fas fa-chevron-down" style="margin-right:6px;"></i>Cargar datos
                        </button>
                    </div>
                </div>
                <div id="adspend_content" style="display:none;"></div>
            </div>`;
            root.insertAdjacentHTML('beforeend', secHtml);

            let adspendLoaded = false;

            document.getElementById('btn_adspend_refresh').addEventListener('click', () => loadAdSpendData(true));

            window.toggleAdSpend = function() {
                const content = document.getElementById('adspend_content');
                const btn     = document.getElementById('btn-lazy-sec_adspend');
                const refresh = document.getElementById('btn_adspend_refresh');
                const isOpen  = content.style.display !== 'none';
                if (isOpen) {
                    content.style.display = 'none';
                    btn.innerHTML = '<i class="fas fa-chevron-down" style="margin-right:6px;"></i>Cargar datos';
                    refresh.style.display = 'none';
                } else {
                    content.style.display = 'block';
                    btn.innerHTML = '<i class="fas fa-chevron-up" style="margin-right:6px;"></i>Ocultar';
                    refresh.style.display = '';
                    if (!adspendLoaded) { adspendLoaded = true; loadAdSpendData(false); }
                }
            };

            async function loadAdSpendData(noCache) {
                const container = document.getElementById('adspend_content');
                container.innerHTML = '<div class="adspend-loading"><i class="fas fa-spinner"></i><div>Cargando datos de gasto publicitario...</div></div>';

                const formData = new FormData();
                formData.append('action', 'endtrack_load_adspend');
                formData.append('d_start', adspendConfig.dateStart);
                formData.append('d_end', adspendConfig.dateEnd);
                formData.append('launch', adspendConfig.launch);
                if (noCache) formData.append('nocache', '1');

                try {
                    const response = await fetch(adspendConfig.ajaxUrl, { method: 'POST', body: formData });
                    const res = await response.json();

                    if (!res.success) {
                        container.innerHTML = `<div class="adspend-error"><i class="fas fa-exclamation-triangle"></i><br>${res.data || 'Error cargando datos'}</div>`;
                        return;
                    }

                    if (res.data.pais_debug) console.log('[CPL pais_debug]', res.data.pais_debug);
                    renderAdSpendSection(res.data, container);
                } catch (err) {
                    container.innerHTML = `<div class="adspend-error"><i class="fas fa-exclamation-triangle"></i><br>Error de conexión: ${err.message}</div>`;
                }
            }

            function formatEur(num) {
                return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }).format(num);
            }

            function renderAdSpendSection(data, container) {
                const t = data.totals;
                const d = data.daily;

                // --- KPI Cards ---
                let html = `<div class="adspend-kpis">`;
                html += kpiCard('Gasto Total', formatEur(t.total_spend), '#a855f7');
                html += kpiCard('Gasto Meta', formatEur(t.meta_spend), '#1877F2');
                html += kpiCard('Gasto Google', formatEur(t.google_spend), '#EA4335');
                html += kpiCard('Leads Publi', (t.total_leads || 0).toLocaleString('es-ES'), '#10b981');
                html += kpiCard('CPL Medio', formatEur(t.cpl), t.cpl > 30 ? '#f43f5e' : '#10b981');
                html += kpiCard('CPL Meta', formatEur(t.cpl_meta), t.cpl_meta > 30 ? '#f43f5e' : '#1877F2');
                html += kpiCard('CPL Google', formatEur(t.cpl_google), t.cpl_google > 30 ? '#f43f5e' : '#EA4335');
                html += `</div>`;

                // --- Chart container ---
                html += `
                <div class="adspend-chart-wrap">
                    <div class="panel-title">Gasto diario + CPL</div>
                    <div class="adspend-chart-canvas"><canvas id="chart_adspend_daily"></canvas></div>
                </div>`;

                // --- Daily table ---
                html += `
                <div class="adspend-table adspend-chart-wrap" style="margin-top:16px;">
                    <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
                        Detalle diario
                        <button class="btn-export" onclick="exportCSV('table_adspend_daily', 'Gasto_Diario')"><i class="fas fa-download"></i> CSV</button>
                    </div>
                    <div class="adspend-table-wrapper">
                        <table id="table_adspend_daily" class="table-pro">
                            <thead><tr>
                                <th>Fecha</th>
                                <th style="text-align:right">Meta (€)</th>
                                <th style="text-align:right">Google (€)</th>
                                <th style="text-align:right">Total (€)</th>
                                <th style="text-align:right">Leads</th>
                                <th style="text-align:right">CPL (€)</th>
                                <th style="text-align:right">CPL Meta (€)</th>
                                <th style="text-align:right">CPL Google (€)</th>
                            </tr></thead>
                            <tbody>`;

                if (d.length === 0) {
                    html += `<tr><td colspan="8" style="text-align:center; opacity:0.5;">No hay datos en el rango seleccionado</td></tr>`;
                } else {
                    d.forEach(row => {
                        const cplColor = row.cpl === 0 ? 'var(--text-muted)' : (row.cpl > 30 ? '#f43f5e' : '#10b981');
                        const metaCplColor = row.cpl_meta === 0 ? 'var(--text-muted)' : (row.cpl_meta > 30 ? '#f43f5e' : '#1877F2');
                        const googleCplColor = row.cpl_google === 0 ? 'var(--text-muted)' : (row.cpl_google > 30 ? '#f43f5e' : '#EA4335');
                        
                        html += `<tr>
                            <td>${formatDate(row.date)}</td>
                            <td style="text-align:right; color:#1877F2;">${row.meta_spend.toFixed(2)}</td>
                            <td style="text-align:right; color:#EA4335;">${row.google_spend.toFixed(2)}</td>
                            <td style="text-align:right; font-weight:600;">${row.total_spend.toFixed(2)}</td>
                            <td style="text-align:right;">${row.leads}</td>
                            <td style="text-align:right; color:${cplColor}; font-weight:600;">${row.cpl > 0 ? row.cpl.toFixed(2) : '-'}</td>
                            <td style="text-align:right; color:${metaCplColor};">${row.cpl_meta > 0 ? row.cpl_meta.toFixed(2) : '-'}</td>
                            <td style="text-align:right; color:${googleCplColor};">${row.cpl_google > 0 ? row.cpl_google.toFixed(2) : '-'}</td>
                        </tr>`;
                    });
                }

                html += `</tbody></table></div></div>`;

                // --- Dimensions breakdown (Country/Campaign) ---
                html += `<div class="grid-2col" style="margin-top:16px;">`;
                
                // Country Table - CPL por País (from dedicated gasto_fb_pais + gasto_google_pais sheets)
                const nameToIsoCPL = {
                    'España': 'ES', 'Spain': 'ES', 'Mexico': 'MX', 'México': 'MX',
                    'Argentina': 'AR', 'Colombia': 'CO', 'Chile': 'CL', 'Peru': 'PE', 'Perú': 'PE',
                    'Venezuela': 'VE', 'Ecuador': 'EC', 'Bolivia': 'BO', 'Paraguay': 'PY',
                    'Uruguay': 'UY', 'Panama': 'PA', 'Panamá': 'PA', 'Guatemala': 'GT',
                    'Honduras': 'HN', 'El Salvador': 'SV', 'Nicaragua': 'NI', 'Costa Rica': 'CR',
                    'Dominican Republic': 'DO', 'República Dominicana': 'DO', 'Cuba': 'CU',
                    'Puerto Rico': 'PR', 'United States': 'US', 'Estados Unidos': 'US',
                    'Brazil': 'BR', 'Brasil': 'BR', 'Portugal': 'PT', 'France': 'FR', 'Francia': 'FR',
                    'Germany': 'DE', 'Alemania': 'DE', 'Italy': 'IT', 'Italia': 'IT',
                    'United Kingdom': 'GB', 'Reino Unido': 'GB', 'Netherlands': 'NL', 'Países Bajos': 'NL',
                    'Belgium': 'BE', 'Bélgica': 'BE', 'Switzerland': 'CH', 'Suiza': 'CH',
                    'Australia': 'AU', 'Canada': 'CA', 'Canadá': 'CA',
                    'Ireland': 'IE', 'Irlanda': 'IE', 'Austria': 'AT', 'Poland': 'PL', 'Polonia': 'PL',
                    'Romania': 'RO', 'Rumanía': 'RO', 'Sweden': 'SE', 'Suecia': 'SE',
                    'Denmark': 'DK', 'Dinamarca': 'DK', 'Finland': 'FI', 'Finlandia': 'FI',
                    'Czech Republic': 'CZ', 'Czechia': 'CZ', 'Hungary': 'HU', 'Hungría': 'HU',
                    'Greece': 'GR', 'Grecia': 'GR', 'Norway': 'NO', 'Noruega': 'NO',
                    'Japan': 'JP', 'Japón': 'JP', 'Andorra': 'AD', 'Slovenia': 'SI', 'Eslovenia': 'SI',
                    'Latvia': 'LV', 'Letonia': 'LV', 'Iceland': 'IS', 'Islandia': 'IS',
                    'Cyprus': 'CY', 'Chipre': 'CY', 'Estonia': 'EE', 'Slovakia': 'SK', 'Eslovaquia': 'SK',
                    'Croatia': 'HR', 'Croacia': 'HR', 'Thailand': 'TH', 'Tailandia': 'TH',
                    'New Zealand': 'NZ', 'Nueva Zelanda': 'NZ', 'Hong Kong': 'HK',
                    'Turkey': 'TR', 'Turquía': 'TR', 'Morocco': 'MA', 'Marruecos': 'MA',
                    'South Africa': 'ZA', 'Sudáfrica': 'ZA', 'China': 'CN', 'Singapore': 'SG',
                    'Israel': 'IL', 'Lithuania': 'LT', 'Lituania': 'LT', 'Luxembourg': 'LU', 'Luxemburgo': 'LU',
                    'Malta': 'MT', 'Bulgaria': 'BG', 'United Arab Emirates': 'AE', 'Emiratos Árabes': 'AE',
                    'Qatar': 'QA', 'Saudi Arabia': 'SA', 'Arabia Saudita': 'SA',
                };
                const isoToNameCPL = {
                    'ES':'España','MX':'México','US':'Estados Unidos','CO':'Colombia','EC':'Ecuador',
                    'PE':'Perú','CL':'Chile','AR':'Argentina','DO':'Rep. Dominicana','GT':'Guatemala',
                    'PA':'Panamá','CR':'Costa Rica','PT':'Portugal','DE':'Alemania','IT':'Italia',
                    'GB':'Reino Unido','BO':'Bolivia','CA':'Canadá','FR':'Francia','CH':'Suiza',
                    'NL':'Países Bajos','VE':'Venezuela','PY':'Paraguay','IE':'Irlanda','BE':'Bélgica',
                    'UY':'Uruguay','HN':'Honduras','SV':'El Salvador','AU':'Australia','PR':'Puerto Rico',
                    'AT':'Austria','PL':'Polonia','SE':'Suecia','NI':'Nicaragua','BR':'Brasil',
                    'RO':'Rumanía','AE':'Emiratos Árabes','LU':'Luxemburgo','DK':'Dinamarca',
                    'CZ':'Chequia','MT':'Malta','FI':'Finlandia','IL':'Israel','LT':'Lituania',
                    'HU':'Hungría','BG':'Bulgaria','GR':'Grecia','NO':'Noruega','JP':'Japón',
                    'AD':'Andorra','SI':'Eslovenia','LV':'Letonia','IS':'Islandia','QA':'Catar',
                    'CY':'Chipre','EE':'Estonia','SA':'Arabia Saudita','SK':'Eslovaquia','HR':'Croacia',
                    'TH':'Tailandia','NZ':'Nueva Zelanda','HK':'Hong Kong','TR':'Turquía',
                    'MA':'Marruecos','ZA':'Sudáfrica','CN':'China','SG':'Singapur',
                };

                // Normalize spend data: sheet may use names ("España") or ISO codes ("IE") → unify to ISO
                const spendByISO = {};
                Object.entries(data.by_pais_cpl || {}).forEach(([country, spend]) => {
                    const iso = nameToIsoCPL[country] || country;
                    if (!spendByISO[iso]) spendByISO[iso] = { fb: 0, google: 0, total: 0 };
                    spendByISO[iso].fb    += spend.fb;
                    spendByISO[iso].google += spend.google;
                    spendByISO[iso].total  += spend.total;
                });

                // Normalize lead data: DB stores English names ("Spain") → ISO
                const leadsByISO = {};
                Object.entries(data.leads_by_pais || {}).forEach(([country, leads]) => {
                    const iso = nameToIsoCPL[country] || country;
                    leadsByISO[iso] = (leadsByISO[iso] || 0) + leads;
                });

                const allIsosCPL = Array.from(new Set([...Object.keys(spendByISO), ...Object.keys(leadsByISO)]));
                allIsosCPL.sort((a, b) => {
                    const ta = spendByISO[a] ? spendByISO[a].total : 0;
                    const tb = spendByISO[b] ? spendByISO[b].total : 0;
                    return tb - ta;
                });

                html += `
                <div class="adspend-table adspend-chart-wrap">
                    <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
                        CPL por País
                        <button class="btn-export" onclick="exportCSV('table_adspend_country', 'Gasto_Pais')"><i class="fas fa-download"></i> CSV</button>
                    </div>
                    <div class="adspend-table-wrapper" style="max-height:450px; overflow-y:auto;">
                        <table id="table_adspend_country" class="table-pro">
                            <thead><tr>
                                <th onclick="sortTable('table_adspend_country', 0)" style="cursor:pointer">País <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_country', 1)" style="cursor:pointer; text-align:right">Gasto FB (€) <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_country', 2)" style="cursor:pointer; text-align:right">Gasto Google (€) <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_country', 3)" style="cursor:pointer; text-align:right">Total (€) <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_country', 4)" style="cursor:pointer; text-align:right">Leads <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_country', 5)" style="cursor:pointer; text-align:right">CPL (€) <i class="fas fa-sort"></i></th>
                            </tr></thead>
                            <tbody>`;
                allIsosCPL.forEach(iso => {
                    const spend = spendByISO[iso] || { fb: 0, google: 0, total: 0 };
                    const leads = leadsByISO[iso] || 0;
                    const cpl = (spend.total > 0 && leads > 0) ? spend.total / leads : 0;
                    const cplColor = cpl === 0 ? 'var(--text-muted)' : (cpl > 30 ? '#f43f5e' : '#10b981');
                    const label = isoToNameCPL[iso] || iso;
                    html += `<tr>
                        <td>${label}</td>
                        <td style="text-align:right">${spend.fb.toFixed(2)}</td>
                        <td style="text-align:right">${spend.google.toFixed(2)}</td>
                        <td style="text-align:right">${spend.total.toFixed(2)}</td>
                        <td style="text-align:right">${leads}</td>
                        <td style="text-align:right; font-weight:600; color:${cplColor}">${cpl > 0 ? cpl.toFixed(2) : '-'}</td>
                    </tr>`;
                });
                if (allIsosCPL.length === 0) html += `<tr><td colspan="6" style="text-align:center; opacity:0.5;">No hay datos por país</td></tr>`;
                html += `</tbody></table></div></div>`;

                // Campaign Table
                const campData = data.by_campaign || [];
                html += `
                <div class="adspend-table adspend-chart-wrap">
                    <div class="panel-title" style="display:flex; justify-content:space-between; align-items:center;">
                        CPL por Campaña
                        <button class="btn-export" onclick="exportCSV('table_adspend_campaign', 'Gasto_Campana')"><i class="fas fa-download"></i> CSV</button>
                    </div>
                    <div class="adspend-table-wrapper" style="max-height:450px; overflow-y:auto;">
                        <table id="table_adspend_campaign" class="table-pro">
                            <thead><tr>
                                <th onclick="sortTable('table_adspend_campaign', 0)" style="cursor:pointer">Campaña <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_campaign', 1)" style="cursor:pointer; text-align:right">Gasto (€) <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_campaign', 2)" style="cursor:pointer; text-align:right">Leads <i class="fas fa-sort"></i></th>
                                <th onclick="sortTable('table_adspend_campaign', 3)" style="cursor:pointer; text-align:right">CPL (€) <i class="fas fa-sort"></i></th>
                            </tr></thead>
                            <tbody>`;
                campData.forEach(row => {
                    const cplColor = row.cpl === 0 ? 'var(--text-muted)' : (row.cpl > 30 ? '#f43f5e' : '#10b981');
                    html += `<tr>
                        <td style="font-size:11px; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${row.label}">${row.label || 'Sin campaña'}</td>
                        <td style="text-align:right">${row.spend.toFixed(2)}</td>
                        <td style="text-align:right">${row.leads}</td>
                        <td style="text-align:right; font-weight:600; color:${cplColor}">${row.cpl > 0 ? row.cpl.toFixed(2) : '-'}</td>
                    </tr>`;
                });
                if (campData.length === 0) html += `<tr><td colspan="4" style="text-align:center; opacity:0.5;">No hay datos por campaña</td></tr>`;
                html += `</tbody></table></div></div>`;

                html += `</div>`;
                container.innerHTML = html;

                // --- Render Chart ---
                if (d.length > 0) renderAdSpendChart(d);
            }

            function kpiCard(label, value, color) {
                return `
                <div class="adspend-kpi-card" style="--kpi-accent: ${color}">
                    <span class="adspend-kpi-label">${label}</span>
                    <span class="adspend-kpi-value" style="color: ${color}">${value}</span>
                </div>`;
            }

            function formatDate(dateStr) {
                const d = new Date(dateStr + 'T00:00:00');
                return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
            }

            function renderAdSpendChart(daily) {
                const canvas = document.getElementById('chart_adspend_daily');
                if (!canvas) return;

                const labels = daily.map(d => formatDate(d.date));
                const metaData = daily.map(d => d.meta_spend);
                const googleData = daily.map(d => d.google_spend);
                const cplData = daily.map(d => d.cpl > 0 ? d.cpl : null);
                const metaCplData = daily.map(d => d.cpl_meta > 0 ? d.cpl_meta : null);
                const googleCplData = daily.map(d => d.cpl_google > 0 ? d.cpl_google : null);

                new Chart(canvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Meta Ads',
                                data: metaData,
                                backgroundColor: 'rgba(24, 119, 242, 0.8)',
                                borderColor: '#1877F2',
                                borderWidth: 1,
                                borderRadius: 4,
                                stack: 'spend',
                                order: 3
                            },
                            {
                                label: 'Google Ads',
                                data: googleData,
                                backgroundColor: 'rgba(234, 67, 53, 0.8)',
                                borderColor: '#EA4335',
                                borderWidth: 1,
                                borderRadius: 4,
                                stack: 'spend',
                                order: 3
                            },
                            {
                                label: 'CPL Total (€)',
                                data: cplData,
                                type: 'line',
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 3,
                                pointRadius: 4,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                tension: 0.3,
                                fill: false,
                                yAxisID: 'y1',
                                order: 1,
                                spanGaps: true
                            },
                            {
                                label: 'CPL Meta (€)',
                                data: metaCplData,
                                type: 'line',
                                borderColor: '#1877F2',
                                borderDash: [5, 5],
                                borderWidth: 2,
                                pointRadius: 3,
                                pointBackgroundColor: '#1877F2',
                                tension: 0.3,
                                fill: false,
                                yAxisID: 'y1',
                                order: 2,
                                spanGaps: true
                            },
                            {
                                label: 'CPL Google (€)',
                                data: googleCplData,
                                type: 'line',
                                borderColor: '#EA4335',
                                borderDash: [5, 5],
                                borderWidth: 2,
                                pointRadius: 3,
                                pointBackgroundColor: '#EA4335',
                                tension: 0.3,
                                fill: false,
                                yAxisID: 'y1',
                                order: 2,
                                spanGaps: true
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 8,
                                    padding: 20,
                                    font: { size: 12 }
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.95)',
                                borderColor: 'rgba(255,255,255,0.1)',
                                borderWidth: 1,
                                padding: 12,
                                titleFont: { size: 13, weight: '600' },
                                bodyFont: { size: 12 },
                                callbacks: {
                                    label: function(ctx) {
                                        if (ctx.dataset.yAxisID === 'y1') {
                                            return ctx.dataset.label + ': ' + (ctx.raw !== null ? ctx.raw.toFixed(2) + ' €' : '-');
                                        }
                                        return ctx.dataset.label + ': ' + (ctx.raw || 0).toFixed(2) + ' €';
                                    }
                                }
                            },
                            datalabels: { display: false }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { maxRotation: 45, font: { size: 11 } }
                            },
                            y: {
                                stacked: true,
                                title: {
                                    display: true,
                                    text: 'Gasto (€)',
                                    font: { size: 12, weight: '600' },
                                    color: '#94a3b8'
                                },
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: {
                                    callback: v => v.toFixed(0) + '€',
                                    font: { size: 11 }
                                }
                            },
                            y1: {
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'CPL (€)',
                                    font: { size: 12, weight: '600' },
                                    color: '#10b981'
                                },
                                grid: { drawOnChartArea: false },
                                ticks: {
                                    callback: v => v.toFixed(0) + '€',
                                    font: { size: 11 },
                                    color: '#10b981'
                                }
                            }
                        }
                    }
                });
            }

        })();

    </script>

</body>

</html>