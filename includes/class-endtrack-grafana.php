<?php

class ENDTrack_Grafana
{

    /**
     * Creates or updates a Grafana Dashboard for a specific launch.
     *
     * @param string $launch_name The display name of the launch.
     * @param string $launch_slug The sanitized slug used in DB columns (e.g., 'marzo2025').
     * @param int $launch_type 1 for Direct Sale, 2 for Pre-Registration.
     * @return string|WP_Error Returns the Dashboard URL on success, or WP_Error on failure.
     */
    public static function create_dashboard($launch_name, $launch_slug, $launch_type = 1)
    {
        $texts = get_option('endtrack_texts', array());

        $grafana_url = isset($texts['grafana_url']) ? untrailingslashit($texts['grafana_url']) : '';
        $grafana_token = isset($texts['grafana_token']) ? $texts['grafana_token'] : '';
        $datasource_uid = isset($texts['grafana_datasource_uid']) ? $texts['grafana_datasource_uid'] : '';

        if (empty($grafana_url) || empty($grafana_token) || empty($datasource_uid)) {
            return new WP_Error('missing_config', 'Falta configuración de Grafana (URL, Token o DataSource UID).');
        }

        // Clean up IP based URLs if they were misconfigured (Dynamic fix)
        /* 
        $wrong_base = '194.163.129.230:3000';
        // Use the configured URL host as the right base if needed, or just skip this fix for new installs
        */

        $safe_launch_name = preg_replace('/[^a-zA-Z0-9_]/', '', $launch_slug);

        // Construct the Dashboard JSON Model
        $dashboard_data = self::get_dashboard_json($launch_name, $safe_launch_name, $datasource_uid, $launch_type);

        // API Request
        $response = wp_remote_post($grafana_url . '/api/dashboards/db', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $grafana_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => json_encode($dashboard_data),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            error_log("ENDTrack Grafana Error (Request): " . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($code >= 200 && $code < 300 && isset($result['url'])) {
            return $grafana_url . $result['url'];
        } else {
            $msg = isset($result['message']) ? $result['message'] : 'Error desconocido de Grafana';
            // Detailed error from Grafana if possible
            if (isset($result['errors']) && is_array($result['errors'])) {
                $msg .= ' | ' . json_encode($result['errors']);
            } elseif (isset($result['message']) && !empty($result['message'])) {
                $msg = $result['message'];
            }

            error_log("ENDTrack Grafana Error (API): Code $code - " . $body);
            return new WP_Error('grafana_api_error', "Error $code: $msg");
        }
    }

    private static function get_dashboard_json($title, $slug, $ds_uid, $type)
    {
        $col_tipo = "tipo_cat_" . $slug;
        $col_primer = "primer_reg_" . $slug;
        $dashboard_uid = 'endtrack_' . $slug;

        return array(
            'dashboard' => array(
                'id' => null,
                'uid' => $dashboard_uid,
                'title' => 'Lanzamiento: ' . $title,
                'description' => 'Dashboard generado por ENDTrack para ' . $title,
                'timezone' => 'browser',
                'schemaVersion' => 41,
                'refresh' => '1m',
                'panels' => self::get_panels($col_tipo, $col_primer, $ds_uid, $type, $title),
                'editable' => true,
                'fiscalYearStartMonth' => 0,
                'graphTooltip' => 1,
                'liveNow' => true,
                'links' => array(
                    array(
                        'asDropdown' => true,
                        'icon' => 'dashboard',
                        'includeVars' => false,
                        'keepTime' => true,
                        'tags' => array('endtrack'),
                        'targetBlank' => false,
                        'title' => 'CAMBIAR DE PANEL',
                        'type' => 'dashboards',
                        'url' => ''
                    )
                ),
                'annotations' => array(
                    'list' => array(
                        array(
                            'builtIn' => 1,
                            'datasource' => array('type' => 'grafana', 'uid' => '-- Grafana --'),
                            'enable' => true,
                            'hide' => true,
                            'iconColor' => 'rgba(0, 211, 255, 1)',
                            'name' => 'Annotations & Alerts',
                            'type' => 'dashboard'
                        )
                    )
                ),
                'time' => array('from' => 'now-30d', 'to' => 'now'),
                'timepicker' => array(),
                'tags' => array('endtrack', $slug),
                'templating' => array(
                    'list' => array(
                        array(
                            'name' => 'source',
                            'type' => 'query',
                            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
                            'definition' => "SELECT DISTINCT source FROM " . $GLOBALS['wpdb']->prefix . "datos WHERE $col_tipo > 0",
                            'query' => "SELECT DISTINCT source FROM " . $GLOBALS['wpdb']->prefix . "datos WHERE $col_tipo > 0",
                            'refresh' => 1,
                            'multi' => true,
                            'includeAll' => true,
                            'allValue' => null,
                            'current' => array('text' => 'All', 'value' => '$__all'),
                            'sort' => 1
                        ),
                        array(
                            'name' => 'medium',
                            'type' => 'query',
                            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
                            'definition' => "SELECT DISTINCT medium FROM " . $GLOBALS['wpdb']->prefix . "datos WHERE $col_tipo > 0",
                            'query' => "SELECT DISTINCT medium FROM " . $GLOBALS['wpdb']->prefix . "datos WHERE $col_tipo > 0",
                            'refresh' => 1,
                            'multi' => true,
                            'includeAll' => true,
                            'allValue' => null,
                            'current' => array('text' => 'All', 'value' => '$__all'),
                            'sort' => 1
                        ),
                        array(
                            'name' => 'campaign',
                            'type' => 'query',
                            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
                            'definition' => "SELECT DISTINCT campaign FROM " . $GLOBALS['wpdb']->prefix . "datos WHERE $col_tipo > 0",
                            'query' => "SELECT DISTINCT campaign FROM " . $GLOBALS['wpdb']->prefix . "datos WHERE $col_tipo > 0",
                            'refresh' => 1,
                            'multi' => true,
                            'includeAll' => true,
                            'allValue' => null,
                            'current' => array('text' => 'All', 'value' => '$__all'),
                            'sort' => 1
                        ),
                        array(
                            'name' => 'afiliado',
                            'type' => 'query',
                            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
                            'definition' => "SELECT DISTINCT COALESCE(U.user_login, D.afiliado) FROM " . $GLOBALS['wpdb']->prefix . "datos D LEFT JOIN " . $GLOBALS['wpdb']->prefix . "users U ON CASE WHEN D.afiliado REGEXP '^[0-9]+$' THEN D.afiliado = U.ID ELSE FALSE END WHERE $col_tipo > 0",
                            'query' => "SELECT DISTINCT COALESCE(U.user_login, D.afiliado) FROM " . $GLOBALS['wpdb']->prefix . "datos D LEFT JOIN " . $GLOBALS['wpdb']->prefix . "users U ON CASE WHEN D.afiliado REGEXP '^[0-9]+$' THEN D.afiliado = U.ID ELSE FALSE END WHERE $col_tipo > 0",
                            'refresh' => 1,
                            'multi' => true,
                            'includeAll' => true,
                            'allValue' => null,
                            'current' => array('text' => 'All', 'value' => '$__all'),
                            'sort' => 1
                        ),
                        array(
                            'name' => 'id_pag',
                            'type' => 'query',
                            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
                            'definition' => "SELECT DISTINCT id_pag FROM " . $GLOBALS['wpdb']->prefix . "visitas WHERE $col_tipo > 0",
                            'query' => "SELECT DISTINCT id_pag FROM " . $GLOBALS['wpdb']->prefix . "visitas WHERE $col_tipo > 0",
                            'refresh' => 1,
                            'multi' => true,
                            'includeAll' => true,
                            'allValue' => null,
                            'current' => array('text' => 'All', 'value' => '$__all'),
                            'sort' => 1
                        )
                    )
                ),
                'overwrite' => true
            ),
            'folderId' => 0,
            'message' => 'Lanzamiento: ' . $title,
            'overwrite' => true
        );
    }

    private static function get_panels($col_tipo, $col_primer, $ds_uid, $type, $launch_name)
    {
        global $wpdb;
        $table_visitas = $wpdb->prefix . 'visitas';
        $table_datos = $wpdb->prefix . 'datos';
        $table_posts = $wpdb->prefix . 'posts';
        $table_users = $wpdb->prefix . 'users';

        $panels = array();
        $gridY = 0;

        // CSS Library Panel removed because it caused Error 500 if the panel UID didn't exist in the Grafana instance.

        // --- TYPE 1: VENTA DIRECTA (KEEPING PREVIOUS MODERN LOGIC) ---
        if ($type == 1) {
            // Panels based on user provided JSON (Modern Layout)

            // 1. Visitas Totales
            $panels[] = self::stat_panel(1, "Visitas Totales", $ds_uid, "SELECT count(*) FROM $table_visitas WHERE $col_tipo = 2 AND \$__timeFilter(fecha)", 0, $gridY, 4, 5, 'none', 'blue');

            // 100. Conversión (Complex Transform Panel)
            $panels[] = self::conversion_stat_panel(100, "Conversión", $ds_uid, $table_visitas, $table_datos, $col_tipo, 4, $gridY, 4, 5);

            // 3. Ventas
            $panels[] = self::stat_panel(3, "Ventas", $ds_uid, "SELECT count(*) FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha)", 8, $gridY, 4, 5, 'none', 'green');

            // 6. Ventas por Fuente (BarChart) - Simple
            $panels[] = self::barchart_panel(6, "Ventas por Fuente (Source)", $ds_uid, "SELECT source as metric, count(*) as value FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY source ORDER BY count(*) DESC LIMIT 10", 12, $gridY, 12, 5);

            $gridY += 5;

            // 4. Evolución de Visitas (Timeseries)
            $panels[] = self::timeseries_panel(4, "Evolución de Visitas", $ds_uid, "SELECT \$__timeGroup(fecha, '24h') as time, count(*) as value FROM $table_visitas WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY 1 ORDER BY 1", 0, $gridY, 12, 8, 'blue');

            // 5. Evolución de Ventas (Timeseries)
            $panels[] = self::timeseries_panel(5, "Evolución de Ventas", $ds_uid, "SELECT \$__timeGroup(fecha, '24h') as time, count(*) as value FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY 1 ORDER BY 1", 12, $gridY, 12, 8, 'green');

            $gridY += 8;

            // 101. Visitas, Conversión y Ventas por Fuente (BarChart with Transforms)
            $panels[] = self::source_comparison_barchart(101, "Visitas, Conversión y Ventas por Fuente (Source)", $ds_uid, $table_visitas, $table_datos, $col_tipo, 0, $gridY, 24, 9);

            $gridY += 9;

            // 102, 105, 104, 103: Detailed Tables (Medium Organico, Medium, Campaign, Afiliado)
            $panels[] = self::detailed_table_panel(102, "Ventas por Medio ORGANICO (Medium)", $ds_uid, $table_visitas, $table_datos, $col_tipo, 0, $gridY, 12, 8, 'medium_organico');
            $panels[] = self::detailed_table_panel(105, "Ventas por Medio (Medium)", $ds_uid, $table_visitas, $table_datos, $col_tipo, 12, $gridY, 12, 8, 'medium');

            $gridY += 8;

            $panels[] = self::detailed_table_panel(104, "Ventas por Campaña (Campaign)", $ds_uid, $table_visitas, $table_datos, $col_tipo, 0, $gridY, 24, 8, 'campaign');

            $gridY += 8;

            $panels[] = self::detailed_table_panel(103, "Ventas por Afiliado (Ref)", $ds_uid, $table_visitas, $table_datos, $col_tipo, 0, $gridY, 24, 8, 'afiliado');

            $gridY += 8;

            // 7. Detalle de Ventas (Final Table)
            $sql_detalle = "SELECT correo, source, medium, campaign, fecha,
                        COALESCE(U.user_login, 'No tiene afiliado') as \"Nombre afiliado\", 
                        count(*) as Ventas 
                 FROM $table_datos D 
                 LEFT JOIN {$wpdb->prefix}users U ON D.afiliado = U.ID
                 WHERE $col_tipo = 2 AND \$__timeFilter(fecha)
                 GROUP BY correo, source, medium, campaign, D.afiliado, U.user_login 
                 ORDER BY count(*) DESC LIMIT 50";

            $panels[] = self::table_panel(7, "Detalle de Ventas", $ds_uid, $sql_detalle, 0, $gridY, 24, 8);

            $gridY += 8;

        } else {
            // --- TYPE 2: CON REGISTRO (FULL PREMIUM OVERHAUL) ---

            // Shared SQL filters and variables
            $filters_v = " AND ref_s IN (\$source) AND ref_m IN (\$medium) AND ref_c IN (\$campaign) AND ref IN (\$afiliado) AND id_pag IN (\$id_pag)";
            $filters_d = " AND source IN (\$source) AND medium IN (\$medium) AND campaign IN (\$campaign) AND afiliado IN (\$afiliado) AND id_pag IN (\$id_pag)";

            // Standard queries for the header stats
            $sql_sesiones_total = "SELECT count(*) as Sessions FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v";
            $sql_registrados_total = "SELECT count(*) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND correo LIKE '%@%' AND $col_primer = 1 $filters_d";
            $sql_ventas_total = "SELECT count(*) as Ventas FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 2 AND correo LIKE '%@%' $filters_d";

            // Dashboard Header Row
            $panels[] = array('id' => 3, 'type' => 'row', 'title' => 'Sesiones y Registrados por fuente.', 'gridPos' => array('h' => 1, 'w' => 24, 'x' => 0, 'y' => 1));
            $gridY = 2;

            // 5. Número de sesiones
            $panels[] = self::stat_panel(5, "Número de sesiones", $ds_uid, $sql_sesiones_total, 0, $gridY, 4, 3, 'none', '#d66cba');

            // 49. % Conversión (Full Transform to match JSON exactly)
            $panels[] = array(
                'id' => 49,
                'title' => '% Conversión (sin oneclick o lista de espera)',
                'type' => 'stat',
                'gridPos' => array('h' => 3, 'w' => 4, 'x' => 4, 'y' => $gridY),
                'datasource' => array('type' => 'mixed', 'uid' => '-- Mixed --'),
                'options' => array('colorMode' => 'value', 'graphMode' => 'area', 'reduceOptions' => array('calcs' => array('sum'), 'fields' => '/^Conversion \* 100$/')),
                'fieldConfig' => array('defaults' => array('unit' => 'percent', 'thresholds' => array('mode' => 'absolute', 'steps' => array(array('color' => 'red', 'value' => 0), array('color' => 'purple', 'value' => 20))))),
                'targets' => array(
                    array('refId' => 'A', 'datasource' => array('uid' => $ds_uid), 'rawSql' => $sql_registrados_total, 'format' => 'table'),
                    array('refId' => 'B', 'datasource' => array('uid' => $ds_uid), 'rawSql' => $sql_sesiones_total, 'format' => 'table')
                ),
                'transformations' => array(
                    array('id' => 'merge', 'options' => array()),
                    array('id' => 'calculateField', 'options' => array('alias' => 'Conversion', 'binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'Registrados')), 'operator' => '/', 'right' => array('matcher' => array('id' => 'byName', 'options' => 'Sessions'))), 'mode' => 'binary')),
                    array('id' => 'calculateField', 'options' => array('alias' => 'Conversion * 100', 'binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'Conversion')), 'operator' => '*', 'right' => array('fixed' => '100')), 'mode' => 'binary'))
                )
            );

            // 50. Número de registrados TOTALES
            $panels[] = self::stat_panel(50, "Número de registrados TOTALES", $ds_uid, $sql_registrados_total, 8, $gridY, 4, 6, 'none', '#8d71b1');

            // 41. Tasa de Conversión por página en % (Primer registrado del lanzamiento).
            $sql_tasa_pag = "SELECT P.post_name as Página, v.Visitas, r.Registrados, CASE WHEN v.Visitas > 0 THEN (r.Registrados / v.Visitas) * 100 ELSE NULL END AS TasaConversion FROM (SELECT id_pag, COUNT(DISTINCT(ip)) as Visitas FROM $table_visitas WHERE \$__timeFilter(fecha) $filters_v AND $col_tipo = 1 GROUP BY id_pag) v LEFT JOIN (SELECT id_pag, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) $filters_d AND $col_tipo = 1 AND correo LIKE '%@%' AND $col_primer = 1 GROUP BY id_pag) r ON v.id_pag = r.id_pag LEFT JOIN $table_posts P ON P.ID = r.id_pag WHERE r.Registrados IS NOT NULL AND v.Visitas > 0 ORDER BY TasaConversion DESC";
            $panels[] = self::table_panel(41, "Tasa de Conversión por página en % (Primer registrado del lanzamiento).", $ds_uid, $sql_tasa_pag, 12, $gridY, 12, 6);

            $gridY += 3;

            // 4. Número de registrados por bloque
            $sql_bloques = "SELECT COUNT(correo) as 'Registrados', SUM(CASE WHEN medium = 'oneclick_newsletter' THEN 1 ELSE 0 END) as 'Oneclick', SUM(CASE WHEN medium = 'lista de espera' THEN 1 ELSE 0 END) as 'Lista de Espera' FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND correo LIKE '%@%' AND $col_primer = 1 $filters_d";
            $panels[] = self::stat_panel(4, "Número de registrados por bloque", $ds_uid, $sql_bloques, 0, $gridY, 8, 3, 'none', '#8d71b1');

            $gridY += 3;

            // 47. Funnel completo (MCKN Funnel Panel)
            $panels[] = array(
                'id' => 47,
                'title' => 'Funnel completo',
                'type' => 'mckn-funnel-panel',
                'gridPos' => array('h' => 8, 'w' => 12, 'x' => 0, 'y' => $gridY),
                'datasource' => array('uid' => '-- Mixed --'),
                'targets' => array(
                    array('refId' => 'Sesiones', 'datasource' => array('uid' => $ds_uid), 'rawSql' => $sql_sesiones_total, 'format' => 'table'),
                    array('refId' => 'Registrados', 'datasource' => array('uid' => $ds_uid), 'rawSql' => $sql_registrados_total, 'format' => 'table'),
                    array('refId' => 'Compradores', 'datasource' => array('uid' => $ds_uid), 'rawSql' => $sql_ventas_total, 'format' => 'table')
                ),
                'transformations' => array(array('id' => 'merge', 'options' => array()), array('id' => 'reduce', 'options' => array('reducers' => array('sum'))))
            );

            // 60. Tasa de Conversión por página en % (Sin primer registrado lanzamiento).
            $sql_tasa_pag_all = "SELECT P.post_name as Página, v.Visitas, r.Registrados, CASE WHEN v.Visitas > 0 THEN (r.Registrados / v.Visitas) * 100 ELSE NULL END AS TasaConversion FROM (SELECT id_pag, COUNT(DISTINCT(ip)) as Visitas FROM $table_visitas WHERE \$__timeFilter(fecha) $filters_v AND $col_tipo = 1 GROUP BY id_pag) v LEFT JOIN (SELECT id_pag, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) $filters_d AND $col_tipo = 1 AND correo LIKE '%@%' GROUP BY id_pag) r ON v.id_pag = r.id_pag LEFT JOIN $table_posts P ON P.ID = r.id_pag WHERE r.Registrados IS NOT NULL AND v.Visitas > 0 ORDER BY TasaConversion DESC";
            $panels[] = self::table_panel(60, "Tasa de Conversión por página en % (Sin primer registrado lanzamiento).", $ds_uid, $sql_tasa_pag_all, 12, $gridY, 12, 6);

            $gridY += 6;

            // 30. Fuente: Tasa de Conversión en %
            $sql_fuente_conv = "SELECT v.ref_s as Fuente, v.Visitas, r.Registrados, (r.Registrados / v.Visitas) * 100 as Conversiones FROM (SELECT ref_s, COUNT(DISTINCT ip) as Visitas FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v GROUP BY ref_s) v LEFT JOIN (SELECT source, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 $filters_d GROUP BY source) r ON v.ref_s = r.source WHERE v.Visitas > 0 ORDER BY Conversiones DESC";
            $panels[] = self::table_panel(30, "Fuente: Tasa de Conversión en %", $ds_uid, $sql_fuente_conv, 12, $gridY, 12, 8);

            $gridY += 2; // Adjusting for overlap

            // 2. Sesiones a la página de registro
            $panels[] = self::timeseries_panel(2, "Sesiones a la página de registro", $ds_uid, "SELECT DATE(fecha) as time, COUNT(DISTINCT ip) as value, ref_s as metric FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v GROUP BY 1, 3 ORDER BY 1", 0, $gridY, 12, 11, 'blue');

            $gridY += 6;

            // 8, 10. Sesiones por fuente
            $panels[] = self::piechart_panel(8, "Sesiones por fuente", $ds_uid, "SELECT ref_s as metric, COUNT(DISTINCT ip) as value FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v GROUP BY 1", 12, $gridY, 6, 8);
            $panels[] = self::table_panel(10, "Sesiones por fuente (Tabla)", $ds_uid, "SELECT ref_s as Fuente, COUNT(DISTINCT ip) as Sesiones FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v GROUP BY 1 ORDER BY 2 DESC", 18, $gridY, 6, 8);

            $gridY += 5;

            // 1. Registrados por Fuente
            $panels[] = self::timeseries_panel(1, "Registrados por Fuente", $ds_uid, "SELECT DATE(fecha) as time, COUNT(DISTINCT correo) as value, source as metric FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND correo LIKE '%@%' $filters_d GROUP BY 1, 3 ORDER BY 1", 0, $gridY, 12, 11, 'green');

            $gridY += 3;

            // 58, 59. TOTALES Registrados por fuente
            $panels[] = self::piechart_panel(58, "TODOS LOS Registrados por fuente", $ds_uid, "SELECT source as metric, COUNT(correo) as value FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND correo LIKE '%@%' $filters_d GROUP BY 1", 12, $gridY, 6, 8);
            $panels[] = self::table_panel(59, "TODOS los Registrados por fuente (Tabla)", $ds_uid, "SELECT source as Fuente, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND correo LIKE '%@%' $filters_d GROUP BY 1 ORDER BY 2 DESC", 18, $gridY, 6, 8);

            $gridY += 8;

            // 7. País por sesiones (GeoMap)
            $panels[] = array(
                'id' => 7,
                'title' => 'País por sesiones',
                'type' => 'geomap',
                'gridPos' => array('h' => 17, 'w' => 12, 'x' => 0, 'y' => $gridY),
                'datasource' => array('uid' => $ds_uid),
                'options' => array('view' => array('lat' => 0, 'lon' => 0, 'zoom' => 1)),
                'layers' => array(array('type' => 'markers', 'location' => array('mode' => 'lookup', 'lookup' => 'pais', 'gazetteer' => 'public/gazetteer/countries.json'), 'config' => array('style' => array('size' => array('field' => 'Sesiones', 'min' => 3, 'max' => 20), 'color' => array('fixed' => '#d66cba'))))),
                'targets' => array(array('refId' => 'A', 'rawSql' => "SELECT pais, COUNT(DISTINCT ip) as 'Sesiones' FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v GROUP BY pais", 'format' => 'table'))
            );

            // 14, 15. Registrados por fuente (No oneclick)
            $panels[] = self::piechart_panel(14, "Registrados por fuente, no oneclick ni lista de espera", $ds_uid, "SELECT source as metric, COUNT(correo) as value FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND medium NOT IN ('oneclick_newsletter', 'lista de espera') $filters_d GROUP BY 1", 12, $gridY, 6, 5);
            $panels[] = self::table_panel(15, "Registrados por fuente, no one click (Tabla)", $ds_uid, "SELECT source as Fuente, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND medium NOT IN ('oneclick_newsletter', 'lista de espera') $filters_d GROUP BY 1 ORDER BY 2 DESC", 18, $gridY, 6, 5);

            $gridY += 5;

            // 32, 33. Visitas y Registrados por país
            $panels[] = self::barchart_panel(32, "Visitas por país", $ds_uid, "SELECT pais as metric, COUNT(DISTINCT ip) as value FROM $table_visitas WHERE \$__timeFilter(fecha) AND $col_tipo = 1 $filters_v GROUP BY pais ORDER BY value DESC LIMIT 20", 12, $gridY, 6, 12, '#d66cba');
            $panels[] = self::barchart_panel(33, "Registrados por país", $ds_uid, "SELECT pais as metric, COUNT(correo) as value FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 $filters_d GROUP BY pais ORDER BY value DESC LIMIT 20", 18, $gridY, 6, 12, '#8d71b1');

            $gridY += 12;

            // Section Afiliados
            $panels[] = array('id' => 28, 'type' => 'row', 'title' => 'Afiliados', 'gridPos' => array('h' => 1, 'w' => 24, 'x' => 0, 'y' => $gridY));
            $gridY += 1;

            $panels[] = self::timeseries_panel(24, "Sesiones con afiliado", $ds_uid, "SELECT DATE(V.fecha) as time, COUNT(DISTINCT V.ip) as value, U.user_login as metric FROM $table_visitas V LEFT JOIN $table_users U ON V.ref = U.ID WHERE \$__timeFilter(fecha) AND V.ref != 'No tiene afiliado' $filters_v GROUP BY 1, 3 ORDER BY 1", 0, $gridY, 12, 7, 'blue');
            $sql_afiliados_tasa = "SELECT U.user_login as Afiliado, v.Visitas, r.Registrados, (r.Registrados / v.Visitas) * 100 as TasaConversion FROM (SELECT ref, COUNT(DISTINCT ip) as Visitas FROM $table_visitas WHERE \$__timeFilter(fecha) AND ref != 'No tiene afiliado' $filters_v GROUP BY ref) v LEFT JOIN (SELECT afiliado, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND afiliado != 'No tiene afiliado' $filters_d GROUP BY afiliado) r ON v.ref = r.afiliado LEFT JOIN $table_users U ON v.ref = U.ID WHERE v.Visitas > 0 AND r.Registrados > 0 ORDER BY TasaConversion DESC";
            $panels[] = self::table_panel(35, "Afiliados: Tasa de Conversión en %", $ds_uid, $sql_afiliados_tasa, 12, $gridY, 12, 7);

            $gridY += 7;

            // 26, 25, 27. Registrados y Sesiones Afiliados
            $panels[] = self::timeseries_panel(26, "Registrados por afiliado", $ds_uid, "SELECT DATE(V.fecha) as time, COUNT(V.correo) as value, U.user_login as metric FROM $table_datos V LEFT JOIN $table_users U ON V.afiliado = U.ID WHERE \$__timeFilter(fecha) AND V.afiliado != 'No tiene afiliado' AND V.correo LIKE '%@%' AND $col_primer = 1 $filters_d GROUP BY 1, 3 ORDER BY 1", 0, $gridY, 12, 9, '#8d71b1');
            $panels[] = self::barchart_panel(25, "Sesiones por afiliado", $ds_uid, "SELECT U.user_login as metric, COUNT(DISTINCT V.ip) as value FROM $table_visitas V LEFT JOIN $table_users U ON V.ref = U.ID WHERE \$__timeFilter(fecha) AND V.ref != 'No tiene afiliado' $filters_v GROUP BY 1 ORDER BY value DESC", 12, $gridY, 6, 9, 'blue');
            $panels[] = self::barchart_panel(27, "Registrados por afiliado (Barras)", $ds_uid, "SELECT U.user_login as metric, COUNT(V.correo) as value FROM $table_datos V LEFT JOIN $table_users U ON V.afiliado = U.ID WHERE \$__timeFilter(fecha) AND V.afiliado != 'No tiene afiliado' AND V.correo LIKE '%@%' AND $col_primer = 1 $filters_d GROUP BY 1 ORDER BY value DESC", 18, $gridY, 6, 9, '#8d71b1');

            $gridY += 9;

            // Row Medios
            $panels[] = array('id' => 12, 'type' => 'row', 'title' => 'Medios', 'gridPos' => array('h' => 1, 'w' => 24, 'x' => 0, 'y' => $gridY));
            $gridY += 1;

            $panels[] = self::timeseries_panel(16, "Sesiones con medio", $ds_uid, "SELECT DATE(fecha) as time, COUNT(DISTINCT ip) as value, ref_m as metric FROM $table_visitas WHERE \$__timeFilter(fecha) AND ref_m != 'No tiene medio' $filters_v GROUP BY 1, 3 ORDER BY 1", 0, $gridY, 12, 11, '#d66cba');
            $sql_medios_conv = "SELECT v.ref_s as Source, v.ref_m as Medio, v.Visitas, r.Registrados, (r.Registrados / v.Visitas) * 100 as TasaConversion FROM (SELECT ref_s, ref_m, COUNT(DISTINCT ip) as Visitas FROM $table_visitas WHERE \$__timeFilter(fecha) AND ref_m != 'No tiene medio' $filters_v GROUP BY 1, 2) v LEFT JOIN (SELECT source, medium, COUNT(correo) as Registrados FROM $table_datos WHERE \$__timeFilter(fecha) AND $col_tipo = 1 AND $col_primer = 1 AND medium != 'No tiene medio' $filters_d GROUP BY 1, 2) r ON v.ref_s = r.source AND v.ref_m = r.medium WHERE v.Visitas > 0 AND r.Registrados > 0 ORDER BY Registrados DESC";
            $panels[] = self::table_panel(31, "Medios: Tasa de Conversión en %", $ds_uid, $sql_medios_conv, 12, $gridY, 12, 7);

            $gridY += 7;

            // 42. Tasa de Conversión por TIPO de VSL
            $sql_vsl_conv = "SELECT P.post_name as Página, ref_m as Medio, COUNT(DISTINCT V.ip) as Visitas, SUM(CASE WHEN $col_primer = 1 THEN 1 ELSE 0 END) as Registrados FROM $table_visitas V LEFT JOIN $table_datos D ON V.ip = D.ip AND V.id_pag = D.id_pag LEFT JOIN $table_posts P ON P.ID = V.id_pag WHERE \$__timeFilter(V.fecha) AND ref_m != 'No tiene medio' $filters_v GROUP BY 1, 2 ORDER BY Visitas DESC";
            $panels[] = self::table_panel(42, "Tasa de Conversión en % por TIPO de VSL", $ds_uid, $sql_vsl_conv, 12, $gridY, 12, 7);

            $gridY += 4;

            $panels[] = self::timeseries_panel(17, "Registrados por medio", $ds_uid, "SELECT DATE(fecha) as time, COUNT(correo) as value, medium as metric FROM $table_datos WHERE \$__timeFilter(fecha) AND medium != 'No tiene medio' AND correo LIKE '%@%' AND $col_primer = 1 $filters_d GROUP BY 1, 3 ORDER BY 1", 0, $gridY, 12, 10, '#8d71b1');
            $panels[] = self::piechart_panel(18, "Sesiones por Medio", $ds_uid, "SELECT ref_m as metric, COUNT(DISTINCT ip) as value FROM $table_visitas WHERE \$__timeFilter(fecha) AND ref_m != 'No tiene medio' $filters_v GROUP BY 1", 12, $gridY, 6, 7);
            $panels[] = self::piechart_panel(19, "Registrados por Medio", $ds_uid, "SELECT medium as metric, COUNT(correo) as value FROM $table_datos WHERE \$__timeFilter(fecha) AND medium != 'No tiene medio' AND correo LIKE '%@%' AND $col_primer = 1 $filters_d GROUP BY 1", 18, $gridY, 6, 7);

            $gridY += 10;

            $panels[] = self::table_panel(1001, "Detalle Últimos Registros", $ds_uid, "SELECT correo, source, medium, campaign, fecha, COALESCE(U.user_login, D.afiliado) as Afiliado FROM $table_datos D LEFT JOIN $table_users U ON CASE WHEN D.afiliado REGEXP '^[0-9]+$' THEN D.afiliado = U.ID ELSE FALSE END WHERE $col_tipo = 1 AND \$__timeFilter(fecha) $filters_d ORDER BY fecha DESC LIMIT 50", 0, $gridY, 24, 10);

            $gridY += 10;
        }

        // Removed specific CSS Library Panel reference that caused errors if not present

        return $panels;
    }

    private static function stat_panel($id, $title, $ds_uid, $sql, $x, $y, $w, $h, $unit = 'none', $color = 'green')
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'stat',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array(
                'colorMode' => 'value',
                'graphMode' => 'area',
                'justifyMode' => 'auto',
                'orientation' => 'auto',
                'reduceOptions' => array('calcs' => array('lastNotNull'), 'fields' => '', 'values' => false),
                'textMode' => 'auto',
                'wideLayout' => true
            ),
            'fieldConfig' => array(
                'defaults' => array(
                    'unit' => $unit,
                    'thresholds' => array(
                        'mode' => 'absolute',
                        'steps' => array(
                            array('color' => $color, 'value' => null)
                        )
                    )
                )
            ),
            'targets' => array(
                array('refId' => 'A', 'rawSql' => $sql, 'format' => 'table', 'editorMode' => 'code')
            )
        );
    }

    private static function conversion_stat_panel($id, $title, $ds_uid, $table_visitas, $table_datos, $col_tipo, $x, $y, $w, $h, $target_tipo = 2)
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'stat',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array(
                'colorMode' => 'value',
                'graphMode' => 'area',
                'justifyMode' => 'auto',
                'orientation' => 'auto',
                'reduceOptions' => array('calcs' => array('lastNotNull'), 'fields' => '', 'values' => false),
                'textMode' => 'auto',
                'wideLayout' => true
            ),
            'fieldConfig' => array(
                'defaults' => array(
                    'unit' => 'percent',
                    'color' => array('mode' => 'fixed', 'fixedColor' => 'orange'),
                    'thresholds' => array(
                        'mode' => 'absolute',
                        'steps' => array(array('color' => 'orange', 'value' => null))
                    )
                )
            ),
            'targets' => array(
                array('refId' => 'A', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT 1 AS k, count(*) as \"Ventas\" FROM $table_datos WHERE $col_tipo = $target_tipo AND \$__timeFilter(fecha)"),
                array('refId' => 'B', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT 1 AS k, count(*) as \"Visitas\" FROM $table_visitas WHERE $col_tipo = $target_tipo AND \$__timeFilter(fecha)")
            ),
            'transformations' => array(
                array('id' => 'joinByField', 'options' => array('byField' => 'k', 'mode' => 'outer')),
                array('id' => 'calculateField', 'options' => array('alias' => '% de Conversión', 'binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'Ventas')), 'operator' => '/', 'right' => array('matcher' => array('id' => 'byName', 'options' => 'Visitas'))), 'mode' => 'binary')),
                array('id' => 'calculateField', 'options' => array('binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => '% de Conversión')), 'operator' => '*', 'right' => array('fixed' => '100')), 'mode' => 'binary')),
                array('id' => 'organize', 'options' => array('excludeByName' => array('% de Conversión' => true, 'Ventas' => true, 'Visitas' => true, 'k' => true), 'renameByName' => array('% de Conversión * 100' => 'Conversión')))
            )
        );
    }

    private static function source_comparison_barchart($id, $title, $ds_uid, $table_visitas, $table_datos, $col_tipo, $x, $y, $w, $h, $target_tipo = 2)
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'barchart',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array('legend' => array('displayMode' => 'list', 'placement' => 'bottom', 'showLegend' => true), 'showValue' => 'auto'),
            'fieldConfig' => array(
                'defaults' => array('thresholds' => array('mode' => 'absolute', 'steps' => array(array('color' => 'green', 'value' => null)))),
                'overrides' => array(
                    array(
                        'matcher' => array('id' => 'byName', 'options' => 'visitas'),
                        'properties' => array(array('id' => 'color', 'value' => array('mode' => 'fixed', 'fixedColor' => 'blue')))
                    ),
                    array(
                        'matcher' => array('id' => 'byName', 'options' => 'ventas'),
                        'properties' => array(array('id' => 'color', 'value' => array('mode' => 'fixed', 'fixedColor' => 'green')))
                    ),
                    array(
                        'matcher' => array('id' => 'byName', 'options' => '% Conversión'),
                        'properties' => array(
                            array('id' => 'color', 'value' => array('mode' => 'fixed', 'fixedColor' => 'orange')),
                            array(
                                'id' => 'mappings',
                                'value' => array(
                                    array('type' => 'special', 'options' => array('match' => 'null+nan', 'result' => array('text' => '0', 'color' => 'orange')))
                                )
                            )
                        )
                    )
                )
            ),
            'targets' => array(
                array('refId' => 'A', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT source as label, count(*) as \"ventas\" FROM $table_datos WHERE $col_tipo = $target_tipo AND \$__timeFilter(fecha) GROUP BY source"),
                array('refId' => 'B', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT ref_s as label, count(*) as \"visitas\" FROM $table_visitas WHERE $col_tipo = $target_tipo AND \$__timeFilter(fecha) GROUP BY ref_s")
            ),
            'transformations' => array(
                array('id' => 'joinByField', 'options' => array('byField' => 'source', 'mode' => 'outer')),
                array('id' => 'calculateField', 'options' => array('binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'ventas')), 'operator' => '/', 'right' => array('matcher' => array('id' => 'byName', 'options' => 'visitas'))), 'mode' => 'binary', 'replaceFields' => false)),
                array('id' => 'calculateField', 'options' => array('alias' => 'Conversión', 'binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'ventas / visitas')), 'operator' => '*', 'right' => array('fixed' => '100')), 'mode' => 'binary', 'replaceFields' => false)),
                array('id' => 'convertFieldType', 'options' => array('conversions' => array(array('destinationType' => 'number', 'targetField' => 'Conversión')), 'fields' => array())),
                array('id' => 'organize', 'options' => array('excludeByName' => array('ventas / visitas' => true), 'renameByName' => array('Conversión' => '% Conversión')))
            )
        );
    }

    private static function detailed_table_panel($id, $title, $ds_uid, $table_visitas, $table_datos, $col_tipo, $x, $y, $w, $h, $mode, $target_tipo = 2)
    {
        $group_col_v = 'ref_s';
        $group_col_d = 'source';
        $extra_v = "";
        $extra_d = "";

        if ($mode == 'medium_organico') {
            $group_col_v = 'ref_m';
            $group_col_d = 'medium';
            $extra_v = " AND ref_s = 'organico'";
            $extra_d = " AND source = 'organico'";
        } elseif ($mode == 'medium') {
            $group_col_v = 'ref_m';
            $group_col_d = 'medium';
        } elseif ($mode == 'campaign') {
            $group_col_v = 'ref_c';
            $group_col_d = 'campaign';
        } elseif ($mode == 'afiliado') {
            $group_col_v = 'ref';
            $group_col_d = 'afiliado';
        }

        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'table',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array('cellHeight' => 'sm', 'showHeader' => true),
            'fieldConfig' => array(
                'defaults' => array(
                    'thresholds' => array('mode' => 'absolute', 'steps' => array(array('color' => 'green', 'value' => null)))
                ),
                'overrides' => array(
                    array(
                        'matcher' => array('id' => 'byName', 'options' => '% Conversión'),
                        'properties' => array(
                            array('id' => 'custom.displayMode', 'value' => 'color-background'),
                            array('id' => 'color', 'value' => array('mode' => 'fixed', 'fixedColor' => 'orange')),
                            array(
                                'id' => 'mappings',
                                'value' => array(
                                    array('type' => 'special', 'options' => array('match' => 'null+nan', 'result' => array('text' => '0', 'color' => 'orange')))
                                )
                            )
                        )
                    ),
                    array(
                        'matcher' => array('id' => 'byName', 'options' => 'visitas'),
                        'properties' => array(array('id' => 'color', 'value' => array('mode' => 'fixed', 'fixedColor' => 'blue')))
                    ),
                    array(
                        'matcher' => array('id' => 'byName', 'options' => 'ventas'),
                        'properties' => array(array('id' => 'color', 'value' => array('mode' => 'fixed', 'fixedColor' => 'green')))
                    )
                )
            ),
            'targets' => array(
                array('refId' => 'A', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT $group_col_d as label, count(*) as \"ventas\" FROM $table_datos WHERE $col_tipo = $target_tipo AND \$__timeFilter(fecha) $extra_d GROUP BY $group_col_d"),
                array('refId' => 'B', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT $group_col_v as label, count(*) as \"visitas\" FROM $table_visitas WHERE $col_tipo = $target_tipo AND \$__timeFilter(fecha) $extra_v GROUP BY $group_col_v")
            ),
            'transformations' => array(
                array('id' => 'joinByField', 'options' => array('byField' => 'label', 'mode' => 'outer')),
                array('id' => 'calculateField', 'options' => array('binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'ventas')), 'operator' => '/', 'right' => array('matcher' => array('id' => 'byName', 'options' => 'visitas'))), 'mode' => 'binary', 'replaceFields' => false)),
                array('id' => 'calculateField', 'options' => array('alias' => 'Conversión', 'binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'ventas / visitas')), 'operator' => '*', 'right' => array('fixed' => '100')), 'mode' => 'binary', 'replaceFields' => false)),
                array('id' => 'convertFieldType', 'options' => array('conversions' => array(array('destinationType' => 'number', 'targetField' => 'Conversión')), 'fields' => array())),
                array('id' => 'organize', 'options' => array('excludeByName' => array('ventas / visitas' => true), 'renameByName' => array('Conversión' => '% Conversión', 'label' => ucfirst($mode))))
            )
        );
    }

    private static function timeseries_panel($id, $title, $ds_uid, $sql, $x, $y, $w, $h, $color = 'green')
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'timeseries',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array(
                'legend' => array('calcs' => array(), 'displayMode' => 'list', 'placement' => 'bottom', 'showLegend' => true),
                'tooltip' => array('mode' => 'single', 'sort' => 'none')
            ),
            'fieldConfig' => array(
                'defaults' => array(
                    'color' => array('mode' => 'fixed', 'fixedColor' => $color),
                    'custom' => array(
                        'drawStyle' => 'line',
                        'fillOpacity' => 0,
                        'lineWidth' => 1,
                        'showPoints' => 'auto',
                    )
                )
            ),
            'targets' => array(
                array('refId' => 'A', 'rawSql' => $sql, 'format' => 'time_series')
            )
        );
    }

    private static function barchart_panel($id, $title, $ds_uid, $sql, $x, $y, $w, $h, $color = 'green')
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'barchart',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array(
                'legend' => array('displayMode' => 'list', 'placement' => 'bottom', 'showLegend' => true),
                'showValue' => 'auto',
            ),
            'fieldConfig' => array(
                'defaults' => array(
                    'color' => array('mode' => 'fixed', 'fixedColor' => $color)
                )
            ),
            'targets' => array(
                array('refId' => 'A', 'rawSql' => $sql, 'format' => 'table')
            )
        );
    }

    private static function table_panel($id, $title, $ds_uid, $sql, $x, $y, $w, $h)
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'table',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array(
                'cellHeight' => 'sm',
                'showHeader' => true
            ),
            'targets' => array(
                array('refId' => 'A', 'rawSql' => $sql, 'format' => 'table', 'editorMode' => 'code')
            )
        );
    }

    private static function piechart_panel($id, $title, $ds_uid, $sql, $x, $y, $w, $h)
    {
        return array(
            'id' => $id,
            'title' => $title,
            'type' => 'piechart',
            'gridPos' => array('h' => $h, 'w' => $w, 'x' => $x, 'y' => $y),
            'datasource' => array('type' => 'mysql', 'uid' => $ds_uid),
            'options' => array(
                'legend' => array('displayMode' => 'list', 'placement' => 'right', 'showLegend' => true),
                'tooltip' => array('mode' => 'single')
            ),
            'targets' => array(
                array('refId' => 'A', 'rawSql' => $sql, 'format' => 'table', 'editorMode' => 'code')
            )
        );
    }

}
