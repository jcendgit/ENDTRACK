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
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($code >= 200 && $code < 300 && isset($result['url'])) {
            return $grafana_url . $result['url'];
        } else {
            $msg = isset($result['message']) ? $result['message'] : 'Error desconocido de Grafana';
            return new WP_Error('grafana_api_error', "Error $code: $msg");
        }
    }

    private static function get_dashboard_json($title, $slug, $ds_uid, $type)
    {
        $col_tipo = "tipo_cat_" . $slug;
        $dashboard_uid = 'endtrack_' . $slug;

        return array(
            'dashboard' => array(
                'id' => null,
                'uid' => $dashboard_uid,
                'title' => 'Lanzamiento: ' . $title,
                'timezone' => 'browser',
                'schemaVersion' => 41,
                'refresh' => '1m',
                'panels' => self::get_panels($col_tipo, $ds_uid, $type, $title),
                'editable' => true,
                'fiscalYearStartMonth' => 0,
                'graphTooltip' => 0,
                'links' => [],
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
                'tags' => array(),
                'templating' => array('list' => array()),
                'overwrite' => true
            ),
            'folderId' => 0,
            'message' => 'Updated via ENDTrack API',
            'overwrite' => true
        );
    }

    private static function get_panels($col_tipo, $ds_uid, $type, $launch_name)
    {
        global $wpdb;
        $table_visitas = $wpdb->prefix . 'visitas';
        $table_datos = $wpdb->prefix . 'datos';
        $table_users = $wpdb->prefix . 'endtrack_users'; // fallback if needed

        $panels = array();
        $gridY = 0;

        // --- TYPE 1: VENTA DIRECTA ---
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
            $sql_detalle = "SELECT correo, source, medium, campaign, fecha_crea,
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
            // --- TYPE 2: CON REGISTRO ---

            // Row 1: Key Stats
            // 1. Visitas (to Registration Page)
            $panels[] = self::stat_panel(1, "Visitas Totales", $ds_uid, "SELECT count(*) FROM $table_visitas WHERE $col_tipo = 1 AND \$__timeFilter(fecha)", 0, $gridY, 4, 4, 'none', 'blue');

            // 2. Registrados
            $panels[] = self::stat_panel(2, "Registrados", $ds_uid, "SELECT count(*) FROM $table_datos WHERE $col_tipo = 1 AND \$__timeFilter(fecha)", 4, $gridY, 4, 4, 'none', 'purple');

            // 3. Ventas
            $panels[] = self::stat_panel(3, "Ventas", $ds_uid, "SELECT count(*) FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha)", 8, $gridY, 4, 4, 'none', 'green');

            // 4. Conv. Visitas/Registrados
            $sql_conv_v_r = "SELECT (SELECT count(*) FROM $table_datos WHERE $col_tipo = 1 AND \$__timeFilter(fecha)) / 
                            NULLIF((SELECT count(*) FROM $table_visitas WHERE $col_tipo = 1 AND \$__timeFilter(fecha)), 0) * 100";
            $panels[] = self::stat_panel(4, "% Conv. Visitas/Reg.", $ds_uid, $sql_conv_v_r, 12, $gridY, 6, 4, 'percent', 'orange');

            // 5. Conv. Registrados/Ventas
            $sql_conv_r_v = "SELECT (SELECT count(*) FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha)) / 
                            NULLIF((SELECT count(*) FROM $table_datos WHERE $col_tipo = 1 AND \$__timeFilter(fecha)), 0) * 100";
            $panels[] = self::stat_panel(5, "% Conv. Reg./Ventas", $ds_uid, $sql_conv_r_v, 18, $gridY, 6, 4, 'percent', 'orange');

            $gridY += 4;

            // Row 2: Charts
            // 6. Registrados por Fuente
            $panels[] = self::barchart_panel(6, "Registrados por Fuente", $ds_uid, "SELECT source as metric, count(*) as value FROM $table_datos WHERE $col_tipo = 1 AND \$__timeFilter(fecha) GROUP BY source ORDER BY count(*) DESC LIMIT 10", 0, $gridY, 12, 8);

            // 7. Ventas por Fuente
            $panels[] = self::barchart_panel(7, "Ventas por Fuente", $ds_uid, "SELECT source as metric, count(*) as value FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY source ORDER BY count(*) DESC LIMIT 10", 12, $gridY, 12, 8);

            $gridY += 8;

            // Row 3: Evolution
            // 8. Evolución Registros
            $panels[] = self::timeseries_panel(8, "Evolución de Registros", $ds_uid, "SELECT \$__timeGroup(fecha, '24h') as time, count(*) as value FROM $table_datos WHERE $col_tipo = 1 AND \$__timeFilter(fecha) GROUP BY 1 ORDER BY 1", 0, $gridY, 12, 8, 'purple');

            // 9. Evolución Ventas
            $panels[] = self::timeseries_panel(9, "Evolución de Ventas", $ds_uid, "SELECT \$__timeGroup(fecha, '24h') as time, count(*) as value FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY 1 ORDER BY 1", 12, $gridY, 12, 8, 'green');

            $gridY += 8;

            // Row 4: Detail Table
            $sql_detalle_mix = "SELECT correo, source, medium, campaign, fecha_crea,
                        COALESCE(U.user_login, 'No tiene afiliado') as \"Nombre afiliado\", 
                        SUM(CASE WHEN $col_tipo = 1 THEN 1 ELSE 0 END) as Registros,
                        SUM(CASE WHEN $col_tipo = 2 THEN 1 ELSE 0 END) as Ventas
                 FROM $table_datos D 
                 LEFT JOIN {$wpdb->prefix}users U ON D.afiliado = U.ID
                 WHERE $col_tipo IN (1, 2) AND \$__timeFilter(fecha)
                 GROUP BY correo, source, medium, campaign, D.afiliado, U.user_login 
                 ORDER BY Ventas DESC, Registros DESC LIMIT 50";

            $panels[] = self::table_panel(10, "Detalle de Seguimiento", $ds_uid, $sql_detalle_mix, 0, $gridY, 24, 8);

            $gridY += 8;
        }

        // --- Library Panel (CSS) ---
        $panels[] = array(
            'gridPos' => array('h' => 1, 'w' => 5, 'x' => 19, 'y' => $gridY),
            'id' => 99,
            'libraryPanel' => array('name' => 'CSS', 'uid' => 'df9xoubkkil8gd'),
            'title' => '®ENDTRACK',
            'type' => 'library-panel-ref'
        );

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

    private static function conversion_stat_panel($id, $title, $ds_uid, $table_visitas, $table_datos, $col_tipo, $x, $y, $w, $h)
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
                array('refId' => 'A', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT 1 AS k, count(*) as \"Ventas\" FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha)"),
                array('refId' => 'B', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT 1 AS k, count(*) as \"Visitas\" FROM $table_visitas WHERE $col_tipo = 2 AND \$__timeFilter(fecha)")
            ),
            'transformations' => array(
                array('id' => 'joinByField', 'options' => array('byField' => 'k', 'mode' => 'outer')),
                array('id' => 'calculateField', 'options' => array('alias' => '% de Conversión', 'binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => 'Ventas')), 'operator' => '/', 'right' => array('matcher' => array('id' => 'byName', 'options' => 'Visitas'))), 'mode' => 'binary')),
                array('id' => 'calculateField', 'options' => array('binary' => array('left' => array('matcher' => array('id' => 'byName', 'options' => '% de Conversión')), 'operator' => '*', 'right' => array('fixed' => '100')), 'mode' => 'binary')),
                array('id' => 'organize', 'options' => array('excludeByName' => array('% de Conversión' => true, 'Ventas' => true, 'Visitas' => true, 'k' => true), 'renameByName' => array('% de Conversión * 100' => 'Conversión')))
            )
        );
    }

    private static function source_comparison_barchart($id, $title, $ds_uid, $table_visitas, $table_datos, $col_tipo, $x, $y, $w, $h)
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
                array('refId' => 'A', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT source, count(*) as \"ventas\" FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY source"),
                array('refId' => 'B', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT ref_s as source, count(*) as \"visitas\" FROM $table_visitas WHERE $col_tipo = 2 AND \$__timeFilter(fecha) GROUP BY ref_s")
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

    private static function detailed_table_panel($id, $title, $ds_uid, $table_visitas, $table_datos, $col_tipo, $x, $y, $w, $h, $mode)
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
                array('refId' => 'A', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT $group_col_d as label, count(*) as \"ventas\" FROM $table_datos WHERE $col_tipo = 2 AND \$__timeFilter(fecha) $extra_d GROUP BY $group_col_d"),
                array('refId' => 'B', 'format' => 'table', 'editorMode' => 'code', 'rawSql' => "SELECT $group_col_v as label, count(*) as \"visitas\" FROM $table_visitas WHERE $col_tipo = 2 AND \$__timeFilter(fecha) $extra_v GROUP BY $group_col_v")
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
                array('refId' => 'A', 'rawSql' => $sql, 'format' => 'table')
            )
        );
    }

}
