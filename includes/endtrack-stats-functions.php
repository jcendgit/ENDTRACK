<?php
/**
 * ENDTrack Stats Helper Functions
 * Shared between stats-fullscreen.php (eager load) and the AJAX section loader.
 */

if (!defined('ABSPATH'))
    exit;

/**
 * Build the global query context (sql_v, sql_d, etc.) from a params array.
 * Works with both $_GET (page load) and $_POST (AJAX).
 */
function endtrack_stats_build_context($params)
{
    global $wpdb, $table_datos, $table_visitas, $table_posts;
    global $sql_v, $sql_d;
    global $active_launch, $target_reg_val, $is_sales_launch;
    global $visit_count_expr, $visit_label, $visit_label_h, $primer_reg_cond_total;
    global $filter_date_start, $filter_date_end, $filter_source, $filter_medium;
    global $filter_campaign, $filter_afiliado, $filter_page, $count_mode;

    $table_datos = $wpdb->prefix . 'datos';
    $table_visitas = $wpdb->prefix . 'visitas';
    $table_posts = $wpdb->prefix . 'posts';

    $endtrack_launches_list = get_option('endtrack_launches', array());
    $default_launch = !empty($endtrack_launches_list) ? end($endtrack_launches_list) : 'legacy';
    $active_launch = isset($params['launch']) ? sanitize_text_field($params['launch']) : $default_launch;
    $filter_date_start = isset($params['d_start']) ? sanitize_text_field($params['d_start']) : date('Y-m-d', strtotime('-30 days'));
    $filter_date_end = isset($params['d_end']) ? sanitize_text_field($params['d_end']) : date('Y-m-d');
    $filter_source = isset($params['var_source']) ? sanitize_text_field($params['var_source']) : '';
    $filter_medium = isset($params['var_medium']) ? sanitize_text_field($params['var_medium']) : '';
    $filter_campaign = isset($params['var_campaign']) ? sanitize_text_field($params['var_campaign']) : '';
    $filter_afiliado = isset($params['var_afiliado']) ? sanitize_text_field($params['var_afiliado']) : '';
    $filter_page = isset($params['var_page']) ? sanitize_text_field($params['var_page']) : '';
    $count_mode = isset($params['count_mode']) ? sanitize_text_field($params['count_mode']) : 'unique_ip';

    if (!in_array($count_mode, ['sessions', 'unique_ip']))
        $count_mode = 'sessions';
    $visit_count_expr = ($count_mode === 'unique_ip')
        ? "COUNT(DISTINCT ip)"
        : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))";
    $visit_label = ($count_mode === 'unique_ip') ? 'IPs únicas' : 'Sesiones';
    $visit_label_h = $visit_label;

    $launch_configs = get_option('endtrack_launch_configs', array());
    $is_sales_launch = isset($launch_configs[$active_launch]) && $launch_configs[$active_launch]['type'] == 1;
    $target_reg_val = $is_sales_launch ? 2 : 1;

    $where_v = "WHERE 1=1";
    $where_d = "WHERE 1=1";
    $args_v = [];
    $args_d = [];

    $where_v .= " AND fecha >= %s AND fecha <= %s";
    $where_d .= " AND fecha >= %s AND fecha <= %s";
    $args_v[] = $filter_date_start . ' 00:00:00';
    $args_v[] = $filter_date_end . ' 23:59:59';
    $args_d[] = $filter_date_start . ' 00:00:00';
    $args_d[] = $filter_date_end . ' 23:59:59';

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
                $pages_query = new WP_Query(['post_type' => 'page', 'posts_per_page' => -1, 'category_name' => $launch_cat_slug, 'fields' => 'ids']);
                if (!empty($pages_query->posts)) {
                    $ids = implode(',', array_map('intval', $pages_query->posts));
                    $where_v .= " AND id_pag IN ($ids)";
                    $where_d .= " AND id_pag IN ($ids)";
                }
            }
        }
    }

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
    }
    if ($filter_page) {
        $where_v .= " AND id_pag = %s";
        $args_v[] = intval($filter_page);
        $where_d .= " AND id_pag = %s";
        $args_d[] = intval($filter_page);
    }

    $sql_v = !empty($args_v) ? $wpdb->prepare($where_v, ...$args_v) : $where_v;
    $sql_d = !empty($args_d) ? $wpdb->prepare($where_d, ...$args_d) : $where_d;
    if (empty($sql_v))
        $sql_v = "WHERE 1=1";
    if (empty($sql_d))
        $sql_d = "WHERE 1=1";

    $primer_reg_cond_total = " AND primer_reg = $target_reg_val";
    if ($active_launch && $active_launch !== 'legacy') {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $primer_reg_cond_total = " AND primer_reg_{$safe} = $target_reg_val";
    }
}

/**
 * Compute a lazy section's items array.
 * Must be called AFTER endtrack_stats_build_context().
 */
function endtrack_stats_compute_section($section_id, $extra_params = [])
{
    global $wpdb, $table_datos, $table_visitas, $sql_v, $sql_d;

    $af_exclude = 'No tiene afiliado';
    $af_blue = '#3b82f6';

    switch ($section_id) {
        case 'sec_country':
            $s3_src = isset($extra_params['s3_src']) ? sanitize_text_field($extra_params['s3_src']) : 'todo';
            $old_v = $sql_v;
            $old_d = $sql_d;
            $f3_c = '';
            if ($s3_src === 'organico') {
                $sql_v .= " AND ref_s = 'organico'";
                $sql_d .= " AND source = 'organico'";
                $f3_c = '#10b981';
            } elseif ($s3_src === 'publicidad') {
                $sql_v .= " AND ref_s = 'publicidad'";
                $sql_d .= " AND source = 'publicidad'";
                $f3_c = '#facc15';
            } elseif ($s3_src === 'afiliado') {
                $sql_v .= " AND ref_s = 'afiliado'";
                $sql_d .= " AND source = 'afiliado'";
                $f3_c = '#3b82f6';
            }
            $items = [
                get_conversion_table('pais', 'País: Tasa de Conversión', 'pais', 'pais', false, false, false, '', $f3_c),
                get_bar_chart('pais', 'Registrados por país', 'datos', '', $f3_c),
                get_bar_chart('pais', 'Visitas por país', 'visitas', '', $f3_c),
                get_pie_chart('pais', 'Distribución registrados por país', 'datos', '', $f3_c),
            ];
            $sql_v = $old_v;
            $sql_d = $old_d;
            return [
                'title' => '3. Datos por País',
                'id' => 'sec_country',
                'icon' => 'fa-globe',
                'layout' => 'two_col',
                'quick_filter' => ['key' => 's3_src', 'current' => $s3_src, 'options' => ['todo' => 'TODO', 'organico' => 'ORGÁNICO', 'publicidad' => 'PUBLICIDAD', 'afiliado' => 'AFILIADOS']],
                'items' => $items,
            ];

        case 'sec_medium':
            $s4_src = isset($extra_params['s4_src']) ? sanitize_text_field($extra_params['s4_src']) : 'todo';
            $old_v = $sql_v;
            $old_d = $sql_d;
            $f4_c = '';
            if ($s4_src === 'organico') {
                $sql_v .= " AND ref_s = 'organico'";
                $sql_d .= " AND source = 'organico'";
                $f4_c = '#10b981';
            } elseif ($s4_src === 'publicidad') {
                $sql_v .= " AND ref_s = 'publicidad'";
                $sql_d .= " AND source = 'publicidad'";
                $f4_c = '#facc15';
            }
            $items = [
                get_conversion_table('ref_m', 'Medios: Tasa de Conversión', 'ref_m', 'medium', false, false, true, '', $f4_c),
                get_time_series('medium', 'Registrados por medio temporal', 'datos', '', $f4_c),
                get_time_series('ref_m', 'Sesiones por medio temporal', 'visitas', '', $f4_c),
                get_pie_chart('medium', 'Registrados por medio', 'datos', '', $f4_c),
            ];
            $sql_v = $old_v;
            $sql_d = $old_d;
            return [
                'title' => '4. Medios',
                'id' => 'sec_medium',
                'icon' => 'fa-bullseye',
                'layout' => 'two_col',
                'quick_filter' => ['key' => 's4_src', 'current' => $s4_src, 'options' => ['todo' => 'TODO', 'organico' => 'ORGANICO', 'publicidad' => 'PUBLICIDAD']],
                'items' => $items,
            ];

        case 'sec_pages':
            return [
                'title' => '5. Tasa de Conversión por página',
                'id' => 'sec_pages',
                'icon' => 'fa-file-invoice',
                'layout' => 'two_col',
                'items' => [get_conversion_table('id_pag', 'Conversión por página (Primer registrado)', 'id_pag', 'id_pag', true)],
            ];

        case 'sec_affiliates':
            return [
                'title' => '6. Afiliados',
                'id' => 'sec_affiliates',
                'icon' => 'fa-users',
                'layout' => 'two_col',
                'items' => [
                    get_conversion_table('ref', 'Afiliados: Tasa de Conversión (Solo Pág. Reg)', 'ref', 'afiliado', false, true, false, $af_exclude, $af_blue),
                    get_time_series('afiliado', 'Registrados por afiliado temporal', 'datos', $af_exclude, $af_blue),
                    get_bar_chart('afiliado', 'Registrados por afiliado', 'datos', $af_exclude, $af_blue),
                    get_time_series('ref', 'Sesiones por afiliado temporal', 'visitas', $af_exclude, $af_blue),
                ],
            ];

        case 'sec_campaigns':
            return [
                'title' => '7. Campañas',
                'id' => 'sec_campaigns',
                'icon' => 'fa-flag',
                'layout' => 'two_col',
                'items' => [
                    get_conversion_table('ref_c', 'Campañas: Tasa de Conversión', 'ref_c', 'campaign'),
                    get_time_series('ref_c', 'Sesiones por campaña temporal', 'visitas'),
                    get_time_series('campaign', 'Registrados por campaña temporal', 'datos'),
                    get_pie_chart('campaign', 'Distribución registrados por campaña', 'datos'),
                ],
            ];

        case 'sec_emails':
            global $active_launch;
            $emails_extra = '';
            if (!empty($active_launch) && $active_launch !== 'legacy') {
                $safe_em = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
                $emails_extra = " AND primer_reg_{$safe_em} > 0";
            }
            $regs_data = $wpdb->get_results("SELECT correo, source, medium, afiliado, campaign, url_actual, fecha as fecha_crea, url_anterior, primer_reg FROM $table_datos $sql_d{$emails_extra} ORDER BY fecha DESC LIMIT 100");
            $regs_table_data = [];
            foreach ($regs_data as $r) {
                $regs_table_data[] = [esc_html($r->correo), esc_html($r->source), esc_html($r->medium), esc_html($r->afiliado), esc_html($r->campaign), esc_html($r->fecha_crea), esc_html($r->primer_reg)];
            }
            return [
                'title' => '8. Listado de registrados por correo (Últimos 100)',
                'id' => 'sec_emails',
                'icon' => 'fa-envelope',
                'layout' => 'full_width',
                'items' => [['type' => 'table', 'title' => 'Listado detallado', 'headers' => ['Correo', 'Source', 'Medium', 'Afiliado', 'Campaign', 'Fecha', 'Tipo Reg'], 'data' => $regs_table_data, 'id' => 'table_emails_list']],
            ];
    }

    return null;
}

// =====================================================================
// Query helper functions (shared)
// =====================================================================

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

function q_visits($select, $group_by = "", $order_by = "")
{
    global $wpdb, $table_visitas, $sql_v;
    if (empty($table_visitas))
        $table_visitas = $wpdb->prefix . 'visitas';
    if (empty($sql_v))
        $sql_v = "WHERE 1=1";
    return $wpdb->get_results("SELECT $select FROM $table_visitas $sql_v $group_by $order_by");
}

function q_datos($select, $group_by = "", $order_by = "")
{
    global $wpdb, $table_datos, $sql_d;
    if (empty($table_datos))
        $table_datos = $wpdb->prefix . 'datos';
    if (empty($sql_d))
        $sql_d = "WHERE 1=1";
    return $wpdb->get_results("SELECT $select FROM $table_datos $sql_d $group_by $order_by");
}

function get_source_color($source)
{
    $s = strtolower(trim($source));
    if (strpos($s, 'organic') !== false || strpos($s, 'organico') !== false || $s === 'organico')
        return '#10b981';
    if (strpos($s, 'publicidad') !== false || strpos($s, 'paid') !== false || strpos($s, 'cpc') !== false || $s === 'publicidad')
        return '#facc15';
    if (strpos($s, 'afiliado') !== false || strpos($s, 'affiliate') !== false || $s === 'afiliado')
        return '#3b82f6';
    return '#64748b';
}

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
    $v_cnt = !empty($visit_count_expr) ? str_replace(['ip', 'fecha'], ['v.ip', 'v.fecha'], $visit_count_expr) : "COUNT(DISTINCT v.ip, FLOOR(UNIX_TIMESTAMP(v.fecha) / 1800))";

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
        ["AND fecha", "AND id_pag", "AND source", "AND medium", "AND campaign", "AND afiliado"],
        ["AND d.fecha", "AND d.id_pag", "AND d.source", "AND d.medium", "AND d.campaign", "AND d.afiliado"],
        $sub_sql_d_val
    );

    $sql = "
    SELECT $source_sel $sel_name,
        $v_cnt as visitas,
        (SELECT COUNT(*) FROM $table_datos d WHERE d.$in_datos$dcoll = v.$in_visitas $source_col $sub_sql_d $d_primer_reg_cond AND (d.placement != 'correo' OR d.placement IS NULL)) as registrados,
        (SELECT COUNT(*) FROM $table_datos d WHERE d.$in_datos$dcoll = v.$in_visitas $source_col $sub_sql_d $d_primer_reg_cond AND (d.placement != 'correo' OR d.placement IS NULL) AND (d.medium != 'lista de espera' OR d.medium IS NULL)) as registrados_conv
    FROM $table_visitas v
    $join
    $sql_v $where_v_extra
    GROUP BY $group_by
    ORDER BY visitas DESC
    LIMIT 50";

    $res = $wpdb->get_results($sql);
    $data = [];
    if (!empty($res)) {
        foreach ($res as $r) {
            // Rate uses registrados_conv (excludes 'lista de espera'); display column uses full registrados
            $r_rate = ($r->visitas > 0) ? ($r->registrados_conv / $r->visitas) * 100 : 0;
            $r_dim = $r->dimension ? $r->dimension : 'Sin definir';
            $dim_label = (in_array($by_column, ['afiliado', 'ref'])) ? get_affiliate_name($r_dim) : $r_dim;
            if ($include_source) {
                $r_dim2 = isset($r->dimension2) ? $r->dimension2 : 'Sin definir';
                $dim_label2 = get_affiliate_name($r_dim2);
                $data[] = [$dim_label2, $dim_label, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            } elseif ($has_page_slug) {
                $data[] = [$dim_label, $r->page_slug, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            } else {
                $data[] = [$dim_label, number_format($r->visitas, 0, ',', '.'), number_format($r->registrados, 0, ',', '.'), number_format($r_rate, 2) . '%'];
            }
        }
    }

    // For medium-based tables, append an explicit 'lista de espera' row (count only, no conversion)
    if ($in_datos === 'medium') {
        $lista_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_datos d WHERE LOWER(d.medium) = 'lista de espera' $sub_sql_d $d_primer_reg_cond");
        if ($lista_count > 0) {
            if ($include_source) {
                $data[] = ['—', 'lista de espera', '0', number_format($lista_count, 0, ',', '.'), '—'];
            } elseif ($has_page_slug) {
                $data[] = ['lista de espera', '—', '0', number_format($lista_count, 0, ',', '.'), '—'];
            } else {
                $data[] = ['lista de espera', '0', number_format($lista_count, 0, ',', '.'), '—'];
            }
        }
    }

    $color_class = '';
    if ($force_color === '#10b981')
        $color_class = 'accent-green';
    elseif ($force_color === '#facc15')
        $color_class = 'accent-yellow';
    elseif ($force_color === '#3b82f6')
        $color_class = 'accent-blue';

    return [
        'type' => 'table',
        'title' => $title,
        'id' => 'table_' . sanitize_title($title),
        'class' => $color_class,
        'headers' => ($include_source
            ? ['Fuente', 'Medio', $visit_label_h, 'Registrados', 'Tasa Conversión']
            : ($has_page_slug
                ? ['Página', 'Ruta', $visit_label_h, 'Registrados', 'Tasa Conversión']
                : ['Página / Origen', $visit_label_h, 'Registrados', 'Tasa Conversión'])),
        'data' => $data,
    ];
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
    if ($exclude_val)
        $whr .= " AND $select_column != '" . esc_sql($exclude_val) . "'";
    $cnt = $is_d ? "COUNT(*)" : (!empty($visit_count_expr) ? $visit_count_expr : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))");

    $is_source_col = in_array($select_column, ['ref_s', 'source']);
    $sql = "SELECT DATE(fecha) as date, $select_column as dimension, $cnt as total FROM $tbl $whr GROUP BY DATE(fecha), $select_column ORDER BY DATE(fecha) ASC";
    $res = $wpdb->get_results($sql);

    $labels = [];
    $datasets = [];
    foreach ($res as $r) {
        if (!in_array($r->date, $labels))
            $labels[] = $r->date;
        $dim = $r->dimension ? $r->dimension : 'Sin definir';
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
        foreach ($labels as $l)
            $data_array[] = isset($data[$l]) ? $data[$l] : 0;
        $color = $force_color ?: ($is_source_col ? get_source_color($dim) : $fallback_colors[$i % count($fallback_colors)]);
        $formatted_datasets[] = ['label' => $dim, 'data' => $data_array, 'borderColor' => $color, 'backgroundColor' => $color . '22', 'borderWidth' => 2, 'fill' => true, 'tension' => 0.4];
        $i++;
    }

    return ['type' => 'line', 'title' => $title, 'labels' => $labels, 'datasets' => $formatted_datasets];
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
    if ($exclude_val)
        $whr .= " AND $select_column != '" . esc_sql($exclude_val) . "'";
    $cnt = $is_d ? "COUNT(*)" : (!empty($visit_count_expr) ? $visit_count_expr : "COUNT(DISTINCT ip, FLOOR(UNIX_TIMESTAMP(fecha) / 1800))");

    $is_source_col = in_array($select_column, ['ref_s', 'source']);
    $sql = "SELECT $select_column as dimension, $cnt as total FROM $tbl $whr GROUP BY $select_column ORDER BY total DESC LIMIT 15";
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
        $bar_colors[] = $force_color ?: ($is_source_col ? get_source_color($dim) : $fallback_colors[$i % count($fallback_colors)]);
        $i++;
    }

    return ['type' => 'bar', 'title' => $title, 'labels' => $labels, 'data' => $data, 'colors' => $bar_colors];
}

function get_pie_chart($select_column, $title, $table, $exclude_val = '', $force_color = '')
{
    $bar = get_bar_chart($select_column, $title, $table, $exclude_val, $force_color);
    $bar['type'] = 'pie';
    return $bar;
}
