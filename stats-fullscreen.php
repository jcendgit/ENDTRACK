<?php
/**
 * Template Name: ENDTrack Stats Fullscreen PRO
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb, $table_datos, $table_visitas, $table_posts, $active_launch, $target_reg_val, $sql_v, $sql_d, $primer_reg_cond_total;
$table_datos = $wpdb->prefix . 'datos';
$table_visitas = $wpdb->prefix . 'visitas';
$table_posts = $wpdb->prefix . 'posts';

// === 1. Filters & Context ===
$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : '';
$filter_date_start = isset($_GET['d_start']) ? sanitize_text_field($_GET['d_start']) : date('Y-m-d', strtotime('-30 days'));
$filter_date_end = isset($_GET['d_end']) ? sanitize_text_field($_GET['d_end']) : date('Y-m-d');
$filter_source = isset($_GET['var_source']) ? sanitize_text_field($_GET['var_source']) : '';
$filter_medium = isset($_GET['var_medium']) ? sanitize_text_field($_GET['var_medium']) : '';
$filter_campaign = isset($_GET['var_campaign']) ? sanitize_text_field($_GET['var_campaign']) : '';
$filter_afiliado = isset($_GET['var_afiliado']) ? sanitize_text_field($_GET['var_afiliado']) : '';
$filter_page = isset($_GET['var_page']) ? sanitize_text_field($_GET['var_page']) : '';

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

// Pages filter from Launch logic
if ($active_launch) {
    if ($active_launch === 'legacy') {
        $where_d .= " AND id_pag NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_endtrack_launch')";
        $where_v .= " AND id_pag NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_endtrack_launch')";
    }
    else {
        $mapping = get_option('endtrack_launches_mapping', array());
        $launch_cat_slug = isset($mapping[$active_launch]) ? $mapping[$active_launch] : sanitize_title($active_launch);
        $launch_cat = get_category_by_slug($launch_cat_slug);

        if ($launch_cat) {
            $pages_query = new WP_Query(array('post_type' => 'page', 'posts_per_page' => -1, 'category_name' => $launch_cat_slug, 'fields' => 'ids'));
            if (!empty($pages_query->posts)) {
                $ids = implode(',', array_map('intval', $pages_query->posts));
                $where_d .= " AND id_pag IN ($ids)";
                $where_v .= " AND id_pag IN ($ids)";
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
}
else {
    $sql_v = $where_v;
}
if (!empty($args_d)) {
    $sql_d = $wpdb->prepare($where_d, ...$args_d);
}
else {
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
$primer_reg_cond_lead = " AND primer_reg = 0";
$primer_reg_cond_buyer = " AND primer_reg = 2";

if ($active_launch && $active_launch !== 'legacy') {
    // If it's a specific launch, registrations only count when primer_reg_LAUNCH is set to 1
    $safe_launch_preg = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $primer_reg_cond_total = " AND primer_reg_" . $safe_launch_preg . " = 1";
// For other types, we might need specific logic or fallback to the master `primer_reg`
// Assuming buyers and oneclicks still rely on the master column as per previous interactions unless specified otherwise.
}

$total_sessions = $wpdb->get_var("SELECT COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800)) FROM $table_visitas $sql_v");
// Apply the exact condition logic to ensure it ONLY fetches regs when they are the primary registration for the launch
$total_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_total");
$target_regs = $total_regs;
$cvr = ($total_sessions > 0) ? ($target_regs / $total_sessions) * 100 : 0;
// Regs by Type
$oneclick_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_oneclick");
$waitlist_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_lead");
$buyers_regs = $wpdb->get_var("SELECT COUNT(*) FROM $table_datos $sql_d $primer_reg_cond_buyer");

$dashboard = [];

// SECTION 1: KPIs
$dashboard['sections'][] = [
    'title' => '1. KPIs / Métricas clave',
    'id' => 'sec_kpi',
    'icon' => 'fa-tachometer-alt',
    'layout' => 'kpi_grid',
    'items' => [
        ['type' => 'kpi', 'title' => 'Número de sesiones', 'value' => number_format((float)$total_sessions, 0, ',', '.'), 'color' => 'indigo'],
        ['type' => 'kpi', 'title' => '% Conversión', 'value' => number_format((float)$cvr, 1, ',', '.') . '%', 'color' => 'emerald'],
        ['type' => 'kpi', 'title' => 'Registrados TOTALES', 'value' => number_format((float)$total_regs, 0, ',', '.'), 'color' => 'amber'],
        ['type' => 'kpi', 'title' => 'Desglose por bloque', 'value' => "Reg: $target_regs | OpC: $oneclick_regs | LE: $waitlist_regs", 'color' => 'blue', 'text_size' => 'small'],
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
function get_conversion_table($by_column, $title, $in_visitas = 'id_pag', $in_datos = 'id_pag', $page_join = false, $only_reg_pages = false, $include_source = false)
{
    global $wpdb, $table_visitas, $table_datos, $sql_v, $sql_d, $target_reg_val, $table_posts, $active_launch;
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
    $join = "";
    $sel_name = "v.$in_visitas as dimension";

    if ($page_join && $in_visitas == 'id_pag') {
        $join = "LEFT JOIN $table_posts p ON v.id_pag = p.ID";
        $sel_name = "IFNULL(p.post_title, 'Desconocido') as dimension";
    }

    $source_sel = "";
    $source_col = "";
    $group_by = "v.$in_visitas";
    if ($include_source) {
        $source_sel = "v.ref_s as dimension2, ";
        $source_col = "AND d.source = v.ref_s"; // link source to make conversion accurate per source+medium
        $group_by = "v.$in_visitas, v.ref_s";
    }

    $where_v_extra = "";
    if ($only_reg_pages && $active_launch && $active_launch !== 'legacy') {
        $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $col_tipo_cat = "tipo_cat_" . $safe_launch;
        $where_v_extra = " AND v.$col_tipo_cat = 1";
    }

    // New condition: "solo los que tengan un uno en primer_reg_LANZabril2026, o primer_reg_*"
    // Since the database might have a dynamic column for primer_reg per launch:
    if ($active_launch && $active_launch !== 'legacy') {
        $safe_launch_preg = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $col_primer_reg = "primer_reg_" . $safe_launch_preg;

        // We need to check if this column exists in wp_datos (fastest way is a try/catch or just ignore error, but let's assume it does based on prompt)
        // If the user said "primer_reg_LANZ... o primer_reg_*", we'll enforce this condition for registrations.
        // Instead of AND d.primer_reg = $target_reg_val... we'll do AND d.$col_primer_reg = 1
        $d_primer_reg_cond = " AND d.$col_primer_reg = 1";

    // Let's also update the main target_reg_val logic if it's applied globally?
    // Wait, the user specifically requested "Y los registrados quiero que me metas solo los que tengan un uno en..." for THIS table? Or globally?
    // "Y los registrados quiero que me metas solo los que tengan un uno en primer_reg_LANZabril2026, o primer_reg_*" -> Usually implies all registered. Let's apply it just to the table queries here that use it, or globally. We'll modify the subquery condition.
    }
    else {
        $d_primer_reg_cond = " AND d.primer_reg = " . (int)$target_reg_val;
    }

    // Performance optimization: We do subqueries instead of full outer joins
    $sub_sql_d_val = str_replace("WHERE 1=1", "AND 1=1", $sql_d ?: "WHERE 1=1");
    // Avoid ambiguous 'fecha' or 'id_pag' etc.
    $sub_sql_d = str_replace(
        array("AND fecha", "AND id_pag", "AND source", "AND medium", "AND campaign", "AND afiliado"),
        array("AND d.fecha", "AND d.id_pag", "AND d.source", "AND d.medium", "AND d.campaign", "AND d.afiliado"),
        $sub_sql_d_val
    );

    $sql = "
    SELECT 
        $source_sel
        $sel_name,
        COUNT(DISTINCT v.ip, FLOOR(UNIX_TIMESTAMP(v.fecha) / 1800)) as visitas,
        (SELECT COUNT(*) FROM $table_datos d WHERE d.$in_datos = v.$in_visitas $source_col $sub_sql_d $d_primer_reg_cond) as registrados
    FROM $table_visitas v
    $join
    $sql_v $where_v_extra
    GROUP BY $group_by
    ORDER BY visitas DESC
    LIMIT 50
    ";

    $res = $wpdb->get_results($sql);
    $data = [];
    if ($wpdb->last_error) {
        $data[] = ['ERROR SQL:', $wpdb->last_error, '0', '0', '0%'];
    }
    else if (empty($res)) {
    // Just return empty data
    }
    else {
        foreach ($res as $r) {
            $r_rate = ($r->visitas > 0) ? ($r->registrados / $r->visitas) * 100 : 0;
            $r_dim = $r->dimension ? $r->dimension : 'Sin definir';

            if ($include_source) {
                $data[] = [$r->dimension2, $r->dimension, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            }
            else {
                $data[] = [$r->dimension, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            }
        }
    }

    $headers = ['Página / Origen', 'Visitas', 'Registrados', 'Tasa Conversión'];
    if ($include_source) {
        $headers = ['Fuente', 'Medio', 'Visitas', 'Registrados', 'Tasa Conversión'];
    }

    return [
        'type' => 'table',
        'title' => $title,
        'headers' => $headers,
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
        return '#f59e0b'; // Yellow
    if (strpos($s, 'afiliado') !== false || strpos($s, 'affiliate') !== false || $s === 'afiliado')
        return '#3b82f6'; // Blue
    return '#64748b'; // Gray for others
}

function get_time_series($select_column, $title, $table)
{
    global $wpdb, $table_visitas, $table_datos, $sql_v, $sql_d, $primer_reg_cond_total;
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
    $cnt = $is_d ? "COUNT(*)" : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))";

    // Determine if this is a source-type column
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
        if (!isset($datasets[$dim]))
            $datasets[$dim] = [];
        $datasets[$dim][$r->date] = $r->total;
    }

    // Fallback colors for non-source columns
    $fallback_colors = ['#ec4899', '#a855f7', '#6366f1', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6', '#0ea5e9', '#14b8a6'];
    // If table is 'datos' (registrados), default to purple; if 'visitas', default to pink
    $default_color = $is_d ? '#a855f7' : '#ec4899';

    $formatted_datasets = [];
    $i = 0;
    foreach ($datasets as $dim => $data) {
        $data_array = [];
        foreach ($labels as $l) {
            $data_array[] = isset($data[$l]) ? $data[$l] : 0;
        }
        if ($is_source_col) {
            $color = get_source_color($dim);
        }
        else {
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

function get_bar_chart($select_column, $title, $table)
{
    global $wpdb, $table_visitas, $table_datos, $sql_v, $sql_d, $primer_reg_cond_total;
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
    $cnt = $is_d ? "COUNT(*)" : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))";

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
    $default_color = $is_d ? '#a855f7' : '#ec4899';

    foreach ($res as $r) {
        $dim = $r->dimension ? $r->dimension : 'Sin definir';
        $labels[] = $dim;
        $data[] = $r->total;
        if ($is_source_col) {
            $bar_colors[] = get_source_color($dim);
        }
        else {
            $bar_colors[] = $default_color;
        }
    }

    return [
        'type' => 'bar',
        'title' => $title,
        'labels' => $labels,
        'data' => $data,
        'colors' => $bar_colors
    ];
}

function get_pie_chart($select_column, $title, $table)
{
    $bar = get_bar_chart($select_column, $title, $table);
    $bar['type'] = 'pie';
    return $bar;
}

// SECTION 2: Conversión por página
$dashboard['sections'][] = [
    'title' => '2. Tasa de Conversión por página',
    'id' => 'sec_pages',
    'icon' => 'fa-file-invoice',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('id_pag', 'Conversión por página (Primer registrado)', 'id_pag', 'id_pag', true)
    ]
];

// SECTION 3: Fuente
$dashboard['sections'][] = [
    'title' => '3. Sesiones y Registrados por Fuente',
    'id' => 'sec_source',
    'icon' => 'fa-compass',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('ref_s', 'Fuente: Tasa de Conversión', 'ref_s', 'source'),
        get_time_series('ref_s', 'Sesiones por fuente temporal', 'visitas'),
        get_time_series('source', 'Registrados por fuente temporal', 'datos'),
        get_pie_chart('source', 'TODOS LOS Registrados por fuente', 'datos')
    ]
];

// SECTION 4: País
$dashboard['sections'][] = [
    'title' => '4. Datos por País',
    'id' => 'sec_country',
    'icon' => 'fa-globe',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('pais', 'País: Tasa de Conversión', 'pais', 'pais'),
        get_bar_chart('pais', 'Visitas por país', 'visitas'),
        get_bar_chart('pais', 'Registrados por país', 'datos'),
        get_pie_chart('pais', 'Distribución visitas por país', 'visitas')
    ]
];

// SECTION 5: Afiliados
$dashboard['sections'][] = [
    'title' => '5. Afiliados',
    'id' => 'sec_affiliates',
    'icon' => 'fa-users',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('ref', 'Afiliados: Tasa de Conversión (Solo Pág. Reg)', 'ref', 'afiliado', false, true),
        get_time_series('afiliado', 'Registrados por afiliado temporal', 'datos'),
        get_bar_chart('afiliado', 'Registrados por afiliado', 'datos'),
        get_time_series('ref', 'Sesiones por afiliado temporal', 'visitas')
    ]
];

// SECTION 6: Medios
$dashboard['sections'][] = [
    'title' => '6. Medios',
    'id' => 'sec_medium',
    'icon' => 'fa-bullseye',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('ref_m', 'Medios: Tasa de Conversión', 'ref_m', 'medium', false, false, true),
        get_time_series('ref_m', 'Sesiones por medio temporal', 'visitas'),
        get_time_series('medium', 'Registrados por medio temporal', 'datos'),
        get_pie_chart('ref_m', 'Sesiones por medio', 'visitas')
    ]
];

// SECTION 7: Campañas
$dashboard['sections'][] = [
    'title' => '7. Campañas',
    'id' => 'sec_campaigns',
    'icon' => 'fa-flag',
    'layout' => 'two_col',
    'items' => [
        get_conversion_table('ref_c', 'Campañas: Tasa de Conversión', 'ref_c', 'campaign'),
        get_time_series('ref_c', 'Sesiones por campaña temporal', 'visitas'),
        get_time_series('campaign', 'Registrados por campaña temporal', 'datos')
    ]
];

// SECTION 8: Correos
$regs_data = $wpdb->get_results("SELECT correo, source, medium, afiliado, campaign, url_actual, fecha as fecha_crea, url_anterior, primer_reg FROM $table_datos $sql_d ORDER BY fecha DESC LIMIT 100");
$regs_table_data = [];
foreach ($regs_data as $r) {
    $regs_table_data[] = [
        esc_html($r->correo),
        esc_html($r->source),
        esc_html($r->medium),
        esc_html($r->afiliado),
        esc_html($r->campaign),
        esc_html($r->fecha_crea),
        esc_html($r->primer_reg)
    ];
}

$dashboard['sections'][] = [
    'title' => '8. Listado de registrados por correo (Últimos 100)',
    'id' => 'sec_emails',
    'icon' => 'fa-envelope',
    'layout' => 'full_width',
    'items' => [
        [
            'type' => 'table',
            'title' => 'Listado detallado',
            'headers' => ['Correo', 'Source', 'Medium', 'Afiliado', 'Campaign', 'Fecha', 'Tipo Reg'],
            'data' => $regs_table_data
        ]
    ]
];

$texts = get_option('endtrack_texts', array());
$logo_url = 'https://visualbusiness.school/wp-content/uploads/2026/03/favicon-vbs.png';

$json_dashboard = json_encode($dashboard);

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

        .btn-filter {
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px var(--primary-glow);
        }

        /* Render Blocks */
        .dashboard-section {
            margin-bottom: 50px;
            animation: fadeIn 0.5s ease-out forwards;
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
            font-size: 72px;
            font-weight: 800;
            display: block;
            margin-bottom: 5px;
            line-height: 1;
            letter-spacing: -2px;
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
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
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
            </div>

            <form method="GET" class="filters-panel">
                <div class="filter-group">
                    <label>Lanzamiento</label>
                    <select name="launch" onchange="this.form.submit()">
                        <option value="">Todos los datos</option>
                        <option value="legacy" <?php selected($active_launch, 'legacy' ); ?>>Anteriores</option>
                        <?php
$launches = get_option('endtrack_launches', array());
$visibility_map = get_option('endtrack_launch_visibility', array());
foreach ($launches as $l):
    if (isset($visibility_map[$l]) && $visibility_map[$l] === true):
?>
                        <option value="<?php echo $l; ?>" <?php selected($active_launch, $l); ?>>
                            <?php echo ucfirst($l); ?>
                        </option>
                        <?php
    endif;
endforeach; ?>
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
                            <?php echo esc_html($a); ?>
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
                <button type="submit" class="btn-filter">Aplicar Filtros</button>
                <?php if ($filter_source || $filter_medium || $filter_campaign || $filter_afiliado || $filter_page): ?>
                <a href="?launch=<?php echo urlencode($active_launch); ?>" class="btn-filter"
                    style="background:rgba(255,255,255,0.1); box-shadow:none; text-decoration:none; text-align:center;">Limpiar</a>
                <?php
endif; ?>
            </form>
        </div>

        <!-- Container for dynamic sections -->
        <div id="dashboard-root"></div>
    </div>

    <script>
        const dashboardData = <?php echo $json_dashboard; ?>;
        const root = document.getElementById('dashboard-root');

        // Theme Colors
        const colors = {
            indigo: '#6366f1', emerald: '#10b981', amber: '#f59e0b', danger: '#f43f5e', blue: '#3b82f6',
            defaultColors: ['#6366f1', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6', '#0ea5e9', '#ec4899', '#14b8a6', '#f97316']
        };
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

        function renderTable(item) {
            let html = `<div class="panel-box"><div class="panel-title">${item.title}</div><div class="table-responsive"><table><thead><tr>`;
            item.headers.forEach(h => html += `<th>${h}</th>`);
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

        function renderChartContainer(item, idx) {
            return `<div class="panel-box"><div class="panel-title">${item.title}</div><div class="chart-container"><canvas id="chart_${item.title.replace(/\s+/g, '_')}_${idx}"></canvas></div></div>`;
        }

        // Render Loop
        dashboardData.sections.forEach((sec, sIdx) => {
            let secHtml = `
        <div class="dashboard-section" style="animation-delay: ${sIdx * 0.1}s">
            <h2 class="section-title"><i class="fas ${sec.icon}"></i> ${sec.title}</h2>
    `;

            let layoutClass = sec.layout === 'kpi_grid' ? 'grid-kpi' : (sec.layout === 'two_col' ? 'grid-2col' : '');
            secHtml += `<div class="${layoutClass}">`;

            sec.items.forEach((item, iIdx) => {
                if (item.type === 'kpi') secHtml += renderKPI(item);
                else if (item.type === 'funnel') secHtml += renderFunnel(item);
                else if (item.type === 'table') secHtml += renderTable(item);
                else if (['line', 'bar', 'pie'].includes(item.type)) secHtml += renderChartContainer(item, iIdx);
            });

            secHtml += `</div></div>`;
            root.innerHTML += secHtml;
        });

        // Init Charts
        dashboardData.sections.forEach((sec) => {
            sec.items.forEach((item, idx) => {
                if (['line', 'bar', 'pie'].includes(item.type)) {
                    const ctx = document.getElementById(`chart_${item.title.replace(/\s+/g, '_')}_${idx}`);
                    if (ctx) {
                        let config = { type: item.type, data: {}, options: { responsive: true, maintainAspectRatio: false } };

                        if (item.type === 'line') {
                            config.data = { labels: item.labels, datasets: item.datasets };
                            config.options.plugins = { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } };
                            config.options.scales = { x: { grid: { display: false } }, y: { grid: { color: 'rgba(255,255,255,0.05)' } } };
                        } else if (item.type === 'bar') {
                            let bgColors = item.colors || [colors.indigo];
                            let dataset = {
                                data: item.data,
                                backgroundColor: bgColors,
                                borderRadius: 6
                            };
                            config.data = { labels: item.labels, datasets: [dataset] };
                            config.options.plugins = { legend: { display: false } };
                            config.options.indexAxis = item.labels.length > 5 ? 'y' : 'x';
                            config.options.scales = {
                                x: { grid: { color: 'rgba(255,255,255,0.05)' } },
                                y: { grid: { display: false } }
                            };
                        } else if (item.type === 'pie') {
                            config.type = 'doughnut';
                            let pieColors = item.colors || colors.defaultColors;
                            config.data = {
                                labels: item.labels,
                                datasets: [{ data: item.data, backgroundColor: pieColors, borderWidth: 0, hoverOffset: 10 }]
                            };
                            config.options.cutout = '70%';
                            config.options.plugins = { legend: { position: 'right', labels: { usePointStyle: true, font: { size: 11 } } } };
                        }

                        new Chart(ctx, config);
                    }
                }
            });
        });
    </script>

</body>

</html>