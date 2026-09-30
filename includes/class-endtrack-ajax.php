<?php

class ENDTrack_Ajax
{

    public function init()
    {
        add_action('wp_ajax_endtrack_datos', array($this, 'save_data'));
        add_action('wp_ajax_nopriv_endtrack_datos', array($this, 'save_data'));

        // Beacon de visita (a prueba de caché): registra en wp_visitas vía AJAX.
        add_action('wp_ajax_endtrack_visit', array($this, 'record_visit'));
        add_action('wp_ajax_nopriv_endtrack_visit', array($this, 'record_visit'));

        add_action('wp_ajax_endtrack_load_launch_stats', array($this, 'load_launch_stats'));
        add_action('wp_ajax_endtrack_delete_affiliate', array($this, 'delete_affiliate'));
        add_action('wp_ajax_endtrack_toggle_visibility', array($this, 'toggle_launch_visibility'));
        add_action('wp_ajax_endtrack_regenerate_copy', array($this, 'regenerate_copy'));
        add_action('wp_ajax_endtrack_regenerate_launch_copy', array($this, 'regenerate_launch_copy'));
        add_action('wp_ajax_endtrack_frontend_ai_edit', array($this, 'frontend_ai_edit'));
        add_action('wp_ajax_endtrack_load_email_list', array($this, 'load_email_list'));
        add_action('wp_ajax_endtrack_load_stats_section', array($this, 'load_stats_section'));
        // ── Mejoras traídas de Visual Business School (ago-2026) ──
        // Detalle de pagos bajo demanda, borrar pago, devolución manual, editar importe
        // manual, marcar Reembolso real, editar nota propia y exportar registros a CSV.
        add_action('wp_ajax_endtrack_edit_pdc_note', array($this, 'edit_pdc_note'));
        add_action('wp_ajax_endtrack_get_pdc_pagos', array($this, 'get_pdc_pagos'));
        add_action('wp_ajax_endtrack_delete_pdc_pago', array($this, 'delete_pdc_pago'));
        add_action('wp_ajax_endtrack_add_pdc_refund', array($this, 'add_pdc_refund'));
        add_action('wp_ajax_endtrack_edit_pdc_manual_importe', array($this, 'edit_pdc_manual_importe'));
        add_action('wp_ajax_endtrack_set_pdc_reembolso_flag', array($this, 'set_pdc_reembolso_flag'));
        add_action('wp_ajax_endtrack_export_datos', array($this, 'export_datos_csv'));

        // /ventas-completas/: productos y credenciales por lanzamiento,
        // notas/color/marca/atribución por comprador, accesos y Stripe.
        add_action('wp_ajax_endtrack_save_launch_products', array($this, 'save_launch_products'));
        add_action('wp_ajax_endtrack_save_launch_credentials', array($this, 'save_launch_credentials'));
        add_action('wp_ajax_endtrack_save_pdc_note', array($this, 'save_pdc_note'));
        add_action('wp_ajax_endtrack_get_pdc_user_data', array($this, 'get_pdc_user_data'));
        add_action('wp_ajax_endtrack_save_pdc_color', array($this, 'save_pdc_color'));
        add_action('wp_ajax_endtrack_save_pdc_color_labels', array($this, 'save_pdc_color_labels'));
        add_action('wp_ajax_endtrack_list_stats_access', array($this, 'list_stats_access'));
        add_action('wp_ajax_endtrack_set_stats_access', array($this, 'set_stats_access'));
        add_action('wp_ajax_endtrack_search_users', array($this, 'search_users'));
        add_action('wp_ajax_endtrack_save_pdc_atribucion', array($this, 'save_pdc_atribucion'));
        add_action('wp_ajax_endtrack_save_pdc_marca', array($this, 'save_pdc_marca'));
        add_action('wp_ajax_endtrack_verify_pdc_stripe', array($this, 'verify_pdc_stripe'));
    }

    public function export_datos_csv()
    {
        if (!current_user_can('manage_options') && !current_user_can('stats_viewer')) {
            wp_die('No tienes permisos.');
        }
        check_ajax_referer('endtrack_load_section', 'nonce');

        require_once ENDTRACK_PLUGIN_DIR . 'includes/endtrack-stats-functions.php';

        global $wpdb, $table_datos, $sql_d, $active_launch;

        // Rebuild the exact same query context (sql_d) the stats page uses.
        endtrack_stats_build_context($_GET);

        if (empty($table_datos))
            $table_datos = $wpdb->prefix . 'datos';
        if (empty($sql_d))
            $sql_d = 'WHERE 1=1';

        $rows = $wpdb->get_results("SELECT * FROM $table_datos $sql_d ORDER BY fecha DESC", ARRAY_A);

        $slug = $active_launch ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $active_launch) : 'datos';
        $filename = 'datos_' . $slug . '_' . date('Ymd_His') . '.csv';

        // Stop WordPress from appending anything to the download.
        while (ob_get_level()) {
            @ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel reads accents correctly.
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
        } else {
            fputcsv($out, array('Sin resultados para el filtro seleccionado'));
        }

        fclose($out);
        exit;
    }

    public function load_launch_stats()
    {
        if (!current_user_can('administrator') && get_current_user_id() != 15) {
            wp_die('No permission');
        }

        $launch = sanitize_text_field($_POST['launch']);
        require_once ENDTRACK_PLUGIN_DIR . 'templates/affiliate-launch-stats.php';
        wp_die();
    }

    public function save_data()
    {
        global $wpdb;

        $tabla_datos = $wpdb->prefix . "datos";
        $correo = isset($_POST["correo"]) ? urldecode($_POST["correo"]) : '';

        if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {

            $cookie = isset($_POST["cookie"]) ? sanitize_text_field($_POST["cookie"]) : '';
            $ip = isset($_POST["ip"]) && !empty($_POST["ip"]) ? sanitize_text_field($_POST["ip"]) : $_SERVER['REMOTE_ADDR'];
            $launch_context = isset($_POST["launch_context"]) ? sanitize_text_field($_POST["launch_context"]) : '';
            $session_id = isset($_POST["session_id"]) ? sanitize_text_field($_POST["session_id"]) : '';
            $afiliado_id = isset($_POST["afiliado"]) ? sanitize_text_field($_POST["afiliado"]) : '';

            // GeoIP recalculado en SERVIDOR con la IP real de la petición AJAX.
            // El 'pais' posteado venía incrustado en el HTML renderizado y, con la página
            // cacheada (preload de WP Rocket desde el propio servidor Contabo), llegaba
            // como 'Germany' para todos los visitantes. Mismo patrón que record_visit().
            $country_code = '';
            $city = '';
            $geo_cache_key = 'endtrack_geo_' . md5($ip);
            $cached_geo = get_transient($geo_cache_key);
            if ($cached_geo !== false) {
                $country_code = $cached_geo['country'];
                $city = $cached_geo['city'];
            } else {
                $details = @json_decode(wp_remote_retrieve_body(wp_remote_get("https://ip.guide/{$ip}", array('timeout' => 2))));
                if ($details) {
                    $country_code = isset($details->location->country) ? $details->location->country : '';
                    $city = isset($details->location->city) ? $details->location->city : '';
                }
                set_transient($geo_cache_key, array('country' => $country_code, 'city' => $city), DAY_IN_SECONDS);
            }
            // Fallback al valor posteado solo si la geolocalización no devuelve nada
            if ($country_code === '' && isset($_POST['pais'])) {
                $country_code = sanitize_text_field($_POST['pais']);
            }
            if ($city === '' && isset($_POST['ciudad'])) {
                $city = sanitize_text_field($_POST['ciudad']);
            }

            // Debug Log
            // [PERF] error_log por lead desactivado en producción (engordaba debug.log).
            // error_log("ENDTrack AJAX Trace: correo=$correo, launch=$launch_context, affiliado=$afiliado_id, ip=$ip, session=$session_id, geo=$country_code/$city");

            // Duplicate check (Global) - More flexible for shared IPs
            // Only count as duplicate if EMAIL matches, or if BOTH Cookie AND IP match
            $existing_lead_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id from $tabla_datos 
                     WHERE correo = %s 
                     OR (cookie = %s AND cookie != '' AND ip = %s) 
                     LIMIT 1",
                    $correo,
                    $cookie,
                    $ip
                )
            );

            // Debouncing: check if this EXACT email was registered in the last 15 seconds
            $last_reg_time = $wpdb->get_var($wpdb->prepare(
                "SELECT fecha FROM $tabla_datos WHERE correo = %s ORDER BY fecha DESC LIMIT 1",
                $correo
            ));

            if ($last_reg_time) {
                $time_diff = time() - strtotime($last_reg_time);
                if ($time_diff < 15) {
                    error_log("ENDTrack AJAX SKIP: Duplicate click detected for $correo (last registration {$time_diff}s ago)");
                    wp_die('Duplicate');
                }
            }

            $primer_registro = ($existing_lead_id > 0) ? 0 : 1;
            if (isset($_POST["venta"]) && $_POST["venta"] == 2) {
                $primer_registro = 2;
            }

            $correo_primer_reg = $correo;
            if ($existing_lead_id) {
                $correo_primer_reg = $wpdb->get_var($wpdb->prepare("SELECT correo from $tabla_datos WHERE id = %d", $existing_lead_id));
            }

            // Fecha SIEMPRE del servidor (la posted venía de PHP y, con la página
            // cacheada, sería la hora de generación de la caché, no la del registro real).
            $madrid_tz = new DateTimeZone('Europe/Madrid');
            $fecha_val = wp_date('Y-m-d H:i:s', null, $madrid_tz);

            $data = array(
                "afiliado" => $afiliado_id,
                "correo" => $correo,
                "nombre" => isset($_POST["nombre"]) ? sanitize_text_field($_POST["nombre"]) : '',
                "correo_primer_reg" => $correo_primer_reg,
                "fecha" => $fecha_val,
                "cookie" => $cookie,
                "term" => isset($_POST["term"]) ? sanitize_text_field($_POST["term"]) : 'No tiene term',
                "content" => isset($_POST["content"]) ? sanitize_text_field($_POST["content"]) : 'No tiene content',
                "placement" => isset($_POST["placement"]) ? sanitize_text_field($_POST["placement"]) : 'No tiene placement',
                "medium" => isset($_POST["medium"]) ? sanitize_text_field($_POST["medium"]) : 'No tiene medio',
                "tipo" => isset($_POST["tipo"]) ? sanitize_text_field($_POST["tipo"]) : 'No tiene tipo',
                "source" => isset($_POST["source"]) ? sanitize_text_field($_POST["source"]) : 'No tiene fuente',
                "campaign" => isset($_POST["campaign"]) ? sanitize_text_field($_POST["campaign"]) : 'No tiene campaña',
                "ip" => $ip,
                "session_id" => $session_id,
                "primer_reg" => $primer_registro,
                "url_anterior" => isset($_POST["url_anterior"]) ? esc_url_raw($_POST["url_anterior"]) : '',
                "url_actual" => isset($_POST["url_actual"]) ? esc_url_raw($_POST["url_actual"]) : '',
                "ciudad" => $city,
                "pais" => $country_code,
                "id_pag" => isset($_POST["id_pag"]) ? sanitize_text_field($_POST["id_pag"]) : '',
                "thrivecart_hash" => isset($_POST["thrivecart_hash"]) ? sanitize_text_field($_POST["thrivecart_hash"]) : '',
                "total" => isset($_POST["total"]) ? sanitize_text_field($_POST["total"]) : null,
                "producto" => isset($_POST["producto"]) ? sanitize_text_field($_POST["producto"]) : null,
                "account_id" => isset($_POST["account_id"]) ? sanitize_text_field($_POST["account_id"]) : null
            );

            // Handling Launch Specific Columns
            if (!empty($launch_context)) {
                $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch_context);
                $col_tipo_cat = "tipo_cat_" . $safe_launch;
                $col_primer_reg = "primer_reg_" . $safe_launch;

                // ¿Ya se registró ESTE correo en ESTE lanzamiento?
                // Dedup SOLO por email (identificador fiable). Antes el OR con cookie/ip/
                // session daba falsos positivos: cookie y session suelen ir VACÍOS, así que
                // "cookie='' OR session_id=''" casaba con miles de filas y marcaba
                // primer_reg_{lanzamiento}=0 a gente que jamás se registró en el lanzamiento.
                $has_reg_launch = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $tabla_datos WHERE correo = %s AND ($col_primer_reg = 1 OR $col_primer_reg = 2) LIMIT 1",
                    $correo
                ));

                $val_tipo_cat = isset($_POST["tipo_cat"]) ? intval($_POST["tipo_cat"]) : 1;
                if ($val_tipo_cat == 0)
                    $val_tipo_cat = 1;

                $data[$col_tipo_cat] = $val_tipo_cat;
                $data[$col_primer_reg] = ($has_reg_launch) ? 0 : ((isset($_POST["venta"]) && $_POST["venta"] == 2) ? 2 : 1);
            }

            // --- ALWAYS INSERT — never update existing records ---
            // Each visit/registration is a new row. primer_reg controls uniqueness at query level.
            $result = $wpdb->insert($tabla_datos, $data);
            if ($result === false) {
                error_log("ENDTrack INSERT ERROR: " . $wpdb->last_error . " | Data: " . json_encode($data));
            } else {
                // [PERF] log por lead exitoso desactivado en producción.
                // error_log("ENDTrack INSERT SUCCESS: Lead ID " . $wpdb->insert_id . " ($correo)");
            }
        } else {
            error_log("ENDTrack AJAX SKIP: Invalid or empty email: '$correo'");
        }

        wp_die();
    }

    /**
     * Beacon de VISITA — registra en wp_visitas vía AJAX (a prueba de caché de página).
     * El servidor añade IP/GeoIP y excluye admins/editores y bots básicos.
     */
    public function record_visit()
    {
        // Excluir admins/editores logueados (admin-ajax corre con la cookie real del visitante).
        if (is_user_logged_in() && (current_user_can('administrator') || current_user_can('edit_posts'))) {
            wp_die();
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
        if ($ua === '' || strpos($ua, 'bot') !== false || strpos($ua, 'spider') !== false || strpos($ua, 'crawl') !== false) {
            wp_die();
        }

        $id_pag = isset($_POST['id_pag']) ? intval($_POST['id_pag']) : 0;
        if ($id_pag <= 0) {
            wp_die();
        }

        global $wpdb;
        $table_visitas = $wpdb->prefix . 'visitas';
        $ip = $_SERVER['REMOTE_ADDR'];

        // GeoIP con caché de transient 24h por IP (igual que el tracking de página).
        $country_code = '';
        $city = '';
        $geo_cache_key = 'endtrack_geo_' . md5($ip);
        $cached_geo = get_transient($geo_cache_key);
        if ($cached_geo !== false) {
            $country_code = $cached_geo['country'];
            $city = $cached_geo['city'];
        } else {
            $details = @json_decode(wp_remote_retrieve_body(wp_remote_get("https://ip.guide/{$ip}", array('timeout' => 2))));
            if ($details) {
                $country_code = isset($details->location->country) ? $details->location->country : '';
                $city = isset($details->location->city) ? $details->location->city : '';
            }
            set_transient($geo_cache_key, array('country' => $country_code, 'city' => $city), DAY_IN_SECONDS);
        }

        $visit_data = array(
            'ref'          => isset($_POST['ref']) ? sanitize_text_field($_POST['ref']) : 'No tiene afiliado',
            'ref_s'        => isset($_POST['ref_s']) ? sanitize_text_field($_POST['ref_s']) : 'No tiene fuente',
            'ref_m'        => isset($_POST['ref_m']) ? sanitize_text_field($_POST['ref_m']) : 'No tiene medio',
            'ref_c'        => isset($_POST['ref_c']) ? sanitize_text_field($_POST['ref_c']) : 'No tiene campaña',
            'ref_t'        => isset($_POST['ref_t']) ? sanitize_text_field($_POST['ref_t']) : '',
            'ref_co'       => isset($_POST['ref_co']) ? sanitize_text_field($_POST['ref_co']) : '',
            'ip'           => $ip,
            'session_id'   => isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '',
            'url_actual'   => isset($_POST['url_actual']) ? esc_url_raw($_POST['url_actual']) : '',
            'url_anterior' => isset($_POST['url_anterior']) ? esc_url_raw($_POST['url_anterior']) : '',
            'pais'         => $country_code,
            'ciudad'       => $city,
            'id_pag'       => $id_pag,
        );

        // Columna dinámica por lanzamiento (igual que hacía el tracking en PHP).
        $launch = isset($_POST['launch_context']) ? sanitize_text_field($_POST['launch_context']) : '';
        if (!empty($launch)) {
            $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            if ($safe_launch !== '') {
                $tipo_cat = isset($_POST['tipo_cat']) ? intval($_POST['tipo_cat']) : 0;
                $visit_data['tipo_cat_' . $safe_launch] = $tipo_cat;
            }
        }

        $wpdb->insert($table_visitas, $visit_data);
        wp_die();
    }

    public function delete_affiliate()
    {
        if (!current_user_can('administrator')) {
            wp_send_json_error('No tienes permisos suficientes.');
        }

        check_ajax_referer('endtrack_delete_user_nonce', 'nonce');

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if ($user_id <= 0) {
            wp_send_json_error('ID de usuario inválido.');
        }

        // Prevent deleting yourself
        if ($user_id === get_current_user_id()) {
            wp_send_json_error('No puedes borrarte a ti mismo.');
        }

        require_once(ABSPATH . 'wp-admin/includes/user.php');

        if (wp_delete_user($user_id)) {
            wp_send_json_success('Usuario eliminado correctamente.');
        } else {
            wp_send_json_error('No se pudo eliminar al usuario.');
        }
    }

    public function toggle_launch_visibility()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        check_ajax_referer('endtrack_toggle_visibility_nonce', 'nonce');

        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        $visible = isset($_POST['visible']) ? filter_var($_POST['visible'], FILTER_VALIDATE_BOOLEAN) : false;

        if (empty($launch)) {
            wp_send_json_error('Lanzamiento no válido.');
        }

        $visibility_map = get_option('endtrack_launch_visibility', array());
        $visibility_map[$launch] = $visible;

        update_option('endtrack_launch_visibility', $visibility_map);

        wp_send_json_success(array(
            'launch' => $launch,
            'visible' => $visible,
            'message' => 'Visibilidad actualizada correctamente.'
        ));
    }

    public function save_launch_products()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        check_ajax_referer('endtrack_save_launch_products_nonce', 'nonce');

        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        $raw = isset($_POST['products']) ? wp_unslash($_POST['products']) : '';

        if (empty($launch)) {
            wp_send_json_error('Lanzamiento no válido.');
        }

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);

        // Parse: split by comma OR newline, trim, drop empties
        $parts = preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $products = array();
        foreach ($parts as $p) {
            $clean = sanitize_text_field(trim($p));
            if ($clean !== '') {
                $products[] = $clean;
            }
        }
        $products = array_values(array_unique($products));

        update_option('endtrack_ext_products_' . $safe, wp_json_encode($products));

        wp_send_json_success(array(
            'launch' => $safe,
            'count' => count($products),
            'products' => $products,
        ));
    }

    public function save_launch_credentials()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        check_ajax_referer('endtrack_save_launch_credentials_nonce', 'nonce');

        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        if (empty($launch)) {
            wp_send_json_error('Lanzamiento no válido.');
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
        $opt_key = 'endtrack_launch_credentials_' . $safe;

        if (!empty($_POST['clear'])) {
            delete_option($opt_key);
            wp_send_json_success(array('cleared' => true));
        }

        $data = array(
            'host'       => isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '',
            'name'       => isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'user'       => isset($_POST['user']) ? sanitize_text_field($_POST['user']) : '',
            'pass'       => isset($_POST['pass']) ? sanitize_text_field(wp_unslash($_POST['pass'])) : '',
            // Admite VARIAS claves Stripe (una por linea o separadas por comas) - se buscan todas al verificar.
            'stripe_key' => isset($_POST['stripe_key']) ? sanitize_textarea_field(wp_unslash($_POST['stripe_key'])) : '',
        );

        // If all fields empty → remove option
        if (empty(array_filter($data))) {
            delete_option($opt_key);
            wp_send_json_success(array('cleared' => true));
        }

        update_option($opt_key, $data);
        wp_send_json_success(array(
            'launch' => $safe,
            'has_pdc' => !empty($data['host']) || !empty($data['user']) || !empty($data['name']),
            'has_stripe' => !empty($data['stripe_key']),
        ));
    }


    private function pdc_log($launch, $email, $accion, $valor)
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'endtrack_pdc_log', array(
            'launch' => preg_replace('/[^a-zA-Z0-9_]/', '', (string) $launch),
            'user_email' => strtolower(trim((string) $email)),
            'accion' => $accion,
            'valor' => is_scalar($valor) ? (string) $valor : wp_json_encode($valor),
            'autor_id' => get_current_user_id(),
        ));
    }

    public function save_pdc_note()
    {
        if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $nota = trim(wp_unslash((string) ($_POST['nota'] ?? '')));
        if (empty($launch) || empty($email) || $nota === '') {
            wp_send_json_error('Datos incompletos.');
        }
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'endtrack_pdc_notas', array(
            'launch' => $launch,
            'user_email' => $email,
            'nota' => $nota,
            'autor_id' => get_current_user_id(),
        ));
        $this->pdc_log($launch, $email, 'nota_add', $nota);
        $user = wp_get_current_user();
        wp_send_json_success(array(
            'id' => $wpdb->insert_id,
            'nota' => $nota,
            'autor' => $user ? $user->display_name : '',
            'fecha' => current_time('mysql'),
        ));
    }

    /**
     * Edita el texto de una nota, SOLO si el usuario actual es quien la creó
     * (autor_id). No se puede editar la nota de otra persona.
     */
    public function edit_pdc_note()
    {
        if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $nota_id = intval($_POST['nota_id'] ?? 0);
        $nota = trim(wp_unslash((string) ($_POST['nota'] ?? '')));
        if ($nota_id <= 0 || $nota === '') {
            wp_send_json_error('Datos incompletos.');
        }
        global $wpdb;
        $t = $wpdb->prefix . 'endtrack_pdc_notas';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $nota_id));
        if (!$row) {
            wp_send_json_error('Nota no encontrada.');
        }
        if ((int) $row->autor_id !== get_current_user_id()) {
            wp_send_json_error('Solo puedes editar tus propias notas.');
        }
        $wpdb->update($t, array('nota' => $nota), array('id' => $nota_id));
        $this->pdc_log($row->launch, $row->user_email, 'nota_edit', 'ID ' . $nota_id . ': ' . $nota);
        wp_send_json_success(array('id' => $nota_id, 'nota' => $nota));
    }

    public function get_pdc_user_data()
    {
        if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (empty($launch) || empty($email)) {
            wp_send_json_error('Datos incompletos.');
        }
        global $wpdb;
        $notas = $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, n.nota, n.fecha, n.autor_id, u.display_name as autor
             FROM {$wpdb->prefix}endtrack_pdc_notas n
             LEFT JOIN {$wpdb->users} u ON u.ID = n.autor_id
             WHERE n.launch = %s AND n.user_email = %s
             ORDER BY n.fecha DESC LIMIT 200",
            $launch,
            $email
        ));
        $log = $wpdb->get_results($wpdb->prepare(
            "SELECT l.accion, l.valor, l.fecha, u.display_name as autor
             FROM {$wpdb->prefix}endtrack_pdc_log l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.autor_id
             WHERE l.launch = %s AND l.user_email = %s
             ORDER BY l.fecha DESC LIMIT 200",
            $launch,
            $email
        ));
        wp_send_json_success(array('notas' => $notas, 'log' => $log));
    }

    public function save_pdc_color()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        // Lista de colores separada por comas (una fila puede llevar varios a la vez, ej. "rojo,verde").
        $allowed_colors = array('rojo', 'amarillo', 'verde', 'morado', 'azul');
        $color_raw = sanitize_text_field((string) ($_POST['color'] ?? ''));
        $color_list = array_values(array_unique(array_filter(array_map('trim', explode(',', $color_raw)))));
        $color_list = array_values(array_intersect($allowed_colors, $color_list)); // filtra inválidos y fija el orden de la paleta
        $color = implode(',', $color_list);
        if (empty($launch) || empty($email)) {
            wp_send_json_error('Datos incompletos.');
        }
        global $wpdb;
        $t = $wpdb->prefix . 'endtrack_pdc_estado';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT user_email FROM $t WHERE launch=%s AND user_email=%s", $launch, $email));
        if ($exists !== null) {
            $wpdb->update($t, array('color' => $color, 'autor_id' => get_current_user_id()), array('launch' => $launch, 'user_email' => $email));
        } else {
            $wpdb->insert($t, array('launch' => $launch, 'user_email' => $email, 'color' => $color, 'autor_id' => get_current_user_id()));
        }
        $this->pdc_log($launch, $email, 'color_set', $color);
        wp_send_json_success(array('color' => $color));
    }

    /**
     * Guarda la leyenda editable de los colores de las bolitas (global).
     * Opción: endtrack_pdc_color_labels (JSON color => etiqueta).
     */
    public function save_pdc_color_labels()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $raw = (string) ($_POST['labels'] ?? '');
        $decoded = json_decode(wp_unslash($raw), true);
        if (!is_array($decoded)) {
            wp_send_json_error('Formato inválido.');
        }
        $allowed = array('rojo', 'amarillo', 'verde', 'morado', 'azul');
        $labels = array();
        foreach ($allowed as $c) {
            $labels[$c] = isset($decoded[$c]) ? sanitize_text_field((string) $decoded[$c]) : '';
        }
        update_option('endtrack_pdc_color_labels', wp_json_encode($labels));
        wp_send_json_success($labels);
    }

    /**
     * Lista los usuarios con acceso a las estadísticas (visor o editor).
     */
    public function list_stats_access()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $map = get_option('endtrack_stats_access', array());
        if (!is_array($map)) $map = array();
        $list = array();
        $seen = array();
        $users = get_users(array(
            'capability' => 'view_endtrack_stats',
            'fields'     => array('ID', 'display_name', 'user_email'),
        ));
        foreach ($users as $u) {
            $lvl = isset($map[$u->ID]) ? $map[$u->ID] : 'editor'; // acceso previo sin nivel => editor
            $list[] = array('id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'level' => $lvl);
            $seen[(int) $u->ID] = true;
        }
        foreach ($map as $uid => $lvl) {
            if (isset($seen[(int) $uid])) continue;
            $u = get_userdata((int) $uid);
            if ($u) $list[] = array('id' => (int) $uid, 'name' => $u->display_name, 'email' => $u->user_email, 'level' => $lvl);
        }
        usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        wp_send_json_success($list);
    }

    /**
     * Asigna o revoca el acceso a las estadísticas de un usuario.
     * level: 'editor' | 'viewer' | 'none'. Identificación por email o user_id.
     */
    public function set_stats_access()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $email = sanitize_email((string) ($_POST['email'] ?? ''));
        $uid   = intval($_POST['user_id'] ?? 0);
        $level = sanitize_text_field((string) ($_POST['level'] ?? ''));
        if (!in_array($level, array('editor', 'viewer', 'none'), true)) {
            wp_send_json_error('Nivel inválido.');
        }
        $user = $uid ? get_userdata($uid) : ($email ? get_user_by('email', $email) : false);
        if (!$user) {
            wp_send_json_error('Usuario no encontrado (¿existe ese email en WordPress?).');
        }
        if (user_can($user, 'manage_options')) {
            wp_send_json_error('Ese usuario es administrador; su acceso no se gestiona aquí.');
        }
        $map = get_option('endtrack_stats_access', array());
        if (!is_array($map)) $map = array();
        if ($level === 'none') {
            $user->remove_cap('view_endtrack_stats');
            $user->remove_cap('edit_endtrack_stats');
            unset($map[$user->ID]);
        } else {
            $user->add_cap('view_endtrack_stats');
            if ($level === 'editor') $user->add_cap('edit_endtrack_stats');
            else $user->remove_cap('edit_endtrack_stats');
            $map[$user->ID] = $level;
        }
        update_option('endtrack_stats_access', $map);
        wp_send_json_success(array('id' => (int) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email, 'level' => $level));
    }

    /**
     * Busca usuarios de WordPress por nombre/email para darles acceso.
     * Devuelve su nivel actual: 'admin' | 'editor' | 'viewer' | 'none'.
     */
    public function search_users()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $q = trim((string) ($_POST['q'] ?? ''));
        if (strlen($q) < 2) {
            wp_send_json_success(array());
        }
        $map = get_option('endtrack_stats_access', array());
        if (!is_array($map)) $map = array();
        $users = get_users(array(
            'search'         => '*' . $q . '*',
            'search_columns' => array('user_email', 'display_name', 'user_login', 'user_nicename'),
            'number'         => 20,
            'orderby'        => 'display_name',
            'order'          => 'ASC',
            'fields'         => array('ID', 'display_name', 'user_email'),
        ));
        $list = array();
        foreach ($users as $u) {
            if (user_can($u->ID, 'manage_options'))         $lvl = 'admin';
            elseif (isset($map[$u->ID]))                    $lvl = $map[$u->ID];
            elseif (user_can($u->ID, 'view_endtrack_stats')) $lvl = 'editor'; // acceso previo sin nivel
            else                                            $lvl = 'none';
            $list[] = array('id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'level' => $lvl);
        }
        wp_send_json_success($list);
    }

    public function save_pdc_marca()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $marca = sanitize_text_field((string) ($_POST['marca'] ?? ''));
        if (!in_array($marca, array('', 'test', 'error'), true)) {
            $marca = '';
        }
        if (empty($launch) || empty($email)) {
            wp_send_json_error('Datos incompletos.');
        }
        global $wpdb;
        $t = $wpdb->prefix . 'endtrack_pdc_estado';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT user_email FROM $t WHERE launch=%s AND user_email=%s", $launch, $email));
        if ($exists !== null) {
            $wpdb->update($t, array('marca' => $marca, 'autor_id' => get_current_user_id()), array('launch' => $launch, 'user_email' => $email));
        } else {
            $wpdb->insert($t, array('launch' => $launch, 'user_email' => $email, 'marca' => $marca, 'autor_id' => get_current_user_id()));
        }
        $this->pdc_log($launch, $email, 'marca_set', $marca);
        wp_send_json_success(array('marca' => $marca));
    }

    public function save_pdc_atribucion()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $atrib = strtolower(trim(sanitize_email((string) ($_POST['atribucion'] ?? ''))));
        if (empty($launch) || empty($email)) {
            wp_send_json_error('Datos incompletos.');
        }
        global $wpdb;
        $t = $wpdb->prefix . 'endtrack_pdc_estado';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT user_email FROM $t WHERE launch=%s AND user_email=%s", $launch, $email));
        if ($exists !== null) {
            $wpdb->update($t, array('atribucion_email' => $atrib, 'autor_id' => get_current_user_id()), array('launch' => $launch, 'user_email' => $email));
        } else {
            $wpdb->insert($t, array('launch' => $launch, 'user_email' => $email, 'atribucion_email' => $atrib, 'autor_id' => get_current_user_id()));
        }
        $this->pdc_log($launch, $email, 'atrib_set', $atrib);
        wp_send_json_success(array('atribucion' => $atrib));
    }

    /**
     * Pagos de un comprador (detalle bajo demanda en /ventas-completas/).
     */
    public function get_pdc_pagos()
    {
        if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch  = (string) ($_POST['launch'] ?? '');
        $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
        $d_start = sanitize_text_field((string) ($_POST['d_start'] ?? ''));
        $d_end   = sanitize_text_field((string) ($_POST['d_end'] ?? ''));
        if ($email === '') {
            wp_send_json_error('Falta email.');
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
        $groups = function_exists('endtrack_get_launch_product_groups') ? endtrack_get_launch_product_groups($launch) : array();
        $products = !empty($groups['all']) ? $groups['all'] : json_decode(get_option('endtrack_ext_products_' . $safe, '[]'), true);
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('Sin productos configurados para el lanzamiento.');
        }
        $creds = function_exists('endtrack_get_pdc_credentials') ? endtrack_get_pdc_credentials($launch) : array();
        if (empty($creds['host']) || empty($creds['user'])) {
            wp_send_json_error('Credenciales PDC no configuradas.');
        }
        try {
            $pdo = new PDO(
                "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            $ph = implode(',', array_fill(0, count($products), '?'));
            $sql = "SELECT A.ID, A.ID_producto, A.producto, A.fecha, A.importe, A.estado,
                           A.tipo, A.cuota, A.cuotas, A.metodo, A.customer,
                           A.subscription_id, A.invoice_id, A.reembolso
                    FROM pdc_pagos A
                    INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                    WHERE LOWER(TRIM(U.mail)) = ? AND A.ID_producto IN ($ph)";
            $params = array_merge(array($email), $products);
            // Las DEVOLUCIONES no se filtran por fecha: un reembolso de Stripe
            // suele llegar semanas después del cierre del carrito y, si se
            // ocultaba, el equipo creía que no estaba registrado y lo metía a
            // mano otra vez (duplicando el importe en negativo). El rango sigue
            // aplicando a los cobros.
            if ($d_start !== '') { $sql .= " AND (DATE(A.fecha) >= ? OR A.estado = 'Reembolso')"; $params[] = $d_start; }
            if ($d_end !== '')   { $sql .= " AND (DATE(A.fecha) <= ? OR A.estado = 'Reembolso')"; $params[] = $d_end; }
            $sql .= " ORDER BY A.fecha ASC LIMIT 500";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            wp_send_json_success(array('pagos' => $st->fetchAll(PDO::FETCH_ASSOC)));
        } catch (Exception $e) {
            wp_send_json_error('DB: ' . $e->getMessage());
        }
    }

    /**
     * Borra UN pago concreto de pdc_pagos (una cuota/transacción, no la venta
     * entera). Antes del DELETE real: guarda una copia completa de la fila en
     * wp_endtrack_pdc_pagos_eliminados (papelera de seguridad) junto con el
     * motivo obligatorio y quién lo ha borrado, y deja constancia en el log
     * (wp_endtrack_pdc_log). Requiere rol editor (no basta con solo ver).
     */
    public function delete_pdc_pago()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pago_id = intval($_POST['pago_id'] ?? 0);
        $motivo  = trim(wp_unslash((string) ($_POST['motivo'] ?? '')));
        if (empty($launch) || empty($email) || $pago_id <= 0) {
            wp_send_json_error('Datos incompletos.');
        }
        if ($motivo === '') {
            wp_send_json_error('Debes indicar un motivo para eliminar el pago.');
        }
        $groups = function_exists('endtrack_get_launch_product_groups') ? endtrack_get_launch_product_groups($launch) : array();
        $products = !empty($groups['all']) ? $groups['all'] : json_decode(get_option('endtrack_ext_products_' . $launch, '[]'), true);
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('Sin productos configurados para el lanzamiento.');
        }
        $creds = function_exists('endtrack_get_pdc_credentials') ? endtrack_get_pdc_credentials($launch) : array();
        if (empty($creds['host']) || empty($creds['user'])) {
            wp_send_json_error('Credenciales PDC no configuradas.');
        }
        try {
            $pdo = new PDO(
                "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            // El pago debe pertenecer a este comprador Y a un producto del lanzamiento:
            // evita borrar por ID arbitrario fuera del ámbito que el editor está viendo.
            $ph = implode(',', array_fill(0, count($products), '?'));
            $sql = "SELECT A.* FROM pdc_pagos A
                    INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                    WHERE A.ID = ? AND LOWER(TRIM(U.mail)) = ? AND A.ID_producto IN ($ph)
                    LIMIT 1";
            $params = array_merge(array($pago_id, $email), $products);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                wp_send_json_error('Pago no encontrado o fuera de tu ámbito de acceso.');
            }
            if (strtolower((string) $row['metodo']) !== 'manual') {
                wp_send_json_error('Solo se pueden borrar pagos manuales. Los pagos de Stripe/Whop no se pueden eliminar aquí, usa "Añadir devolución manual" en su lugar.');
            }

            global $wpdb;
            $wpdb->insert($wpdb->prefix . 'endtrack_pdc_pagos_eliminados', array(
                'launch'     => $launch,
                'pago_id'    => $pago_id,
                'user_email' => $email,
                'datos_json' => wp_json_encode($row),
                'motivo'     => $motivo,
                'autor_id'   => get_current_user_id(),
            ));

            $del = $pdo->prepare("DELETE FROM pdc_pagos WHERE ID = ?");
            $del->execute(array($pago_id));

            $this->pdc_log($launch, $email, 'pago_delete', 'ID ' . $pago_id . ' — ' . $motivo);
            wp_send_json_success(array('pago_id' => $pago_id));
        } catch (Exception $e) {
            wp_send_json_error('DB: ' . $e->getMessage());
        }
    }

    /**
     * Añade una devolución MANUAL a pdc_pagos (línea nueva, en negativo),
     * para los casos en que Stripe bloqueó la cuenta y la devolución se ha
     * hecho fuera de Stripe. Clona los datos identificativos (usuario,
     * producto, facturación…) del pago original indicado en $base_pago_id,
     * y solo cambia importe (negativo, obligatorio), estado=Reembolso y
     * metodo=Manual. El total del comprador queda a 0 si se devuelve el
     * importe completo (estado=Reembolso resta abs(importe) del total).
     * Requiere rol editor y un motivo obligatorio, que queda en el log.
     * La línea se marca `reembolso = 0` = DEVOLUCIÓN normal (el flag a 1
     * significa "Reembolso: compró otra vez con otro correo y se le devolvió
     * todo", que es lo contrario y la sacaba del KPI de devoluciones).
     */
    public function add_pdc_refund()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch       = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email        = strtolower(trim((string) ($_POST['email'] ?? '')));
        $base_pago_id = intval($_POST['base_pago_id'] ?? 0);
        $importe      = floatval(str_replace(',', '.', (string) ($_POST['importe'] ?? '')));
        $motivo       = trim(wp_unslash((string) ($_POST['motivo'] ?? '')));
        if (empty($launch) || empty($email) || $base_pago_id <= 0) {
            wp_send_json_error('Datos incompletos.');
        }
        if ($motivo === '') {
            wp_send_json_error('Debes indicar un motivo para la devolución.');
        }
        if ($importe >= 0) {
            wp_send_json_error('El importe debe ser negativo (es una devolución).');
        }
        $groups = function_exists('endtrack_get_launch_product_groups') ? endtrack_get_launch_product_groups($launch) : array();
        $products = !empty($groups['all']) ? $groups['all'] : json_decode(get_option('endtrack_ext_products_' . $launch, '[]'), true);
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('Sin productos configurados para el lanzamiento.');
        }
        $creds = function_exists('endtrack_get_pdc_credentials') ? endtrack_get_pdc_credentials($launch) : array();
        if (empty($creds['host']) || empty($creds['user'])) {
            wp_send_json_error('Credenciales PDC no configuradas.');
        }
        try {
            $pdo = new PDO(
                "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            // El pago base debe pertenecer a este comprador y a un producto del
            // lanzamiento, y estar Completado/Compra (no se clona sobre un fallido
            // o sobre otra devolución).
            $ph = implode(',', array_fill(0, count($products), '?'));
            $sql = "SELECT A.* FROM pdc_pagos A
                    INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                    WHERE A.ID = ? AND LOWER(TRIM(U.mail)) = ? AND A.ID_producto IN ($ph)
                    LIMIT 1";
            $params = array_merge(array($base_pago_id, $email), $products);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $base = $st->fetch(PDO::FETCH_ASSOC);
            if (!$base) {
                wp_send_json_error('Pago de referencia no encontrado o fuera de tu ámbito de acceso.');
            }
            if (!in_array((string) $base['estado'], array('Completado', 'Compra'), true)) {
                wp_send_json_error('Solo se puede añadir una devolución manual sobre un pago Completado/Compra.');
            }
            // ── Guardia anti-duplicados ──────────────────────────────────────
            // El panel filtra por rango de fechas, así que un reembolso de Stripe
            // POSTERIOR al cierre del carrito no se ve en pantalla y el equipo
            // acaba metiendo la devolución a mano otra vez (pasó el 11-09-2026:
            // 7 líneas duplicadas, -1.275 €). Aquí se mira el saldo REAL del
            // comprador en estos productos, se vea o no en la pantalla: si ya
            // está todo devuelto, no se deja crear otra línea.
            $sql_saldo = "SELECT SUM(CASE WHEN A.estado IN ('Completado','Compra') THEN A.importe
                                          WHEN A.estado = 'Reembolso' THEN -ABS(A.importe)
                                          ELSE 0 END) AS saldo
                          FROM pdc_pagos A
                          WHERE A.ID_usuario = ? AND A.ID_producto IN ($ph)";
            $st_saldo = $pdo->prepare($sql_saldo);
            $st_saldo->execute(array_merge(array($base['ID_usuario']), $products));
            $saldo = (float) $st_saldo->fetchColumn();
            if ($saldo <= 0.01) {
                wp_send_json_error('Este comprador ya tiene devuelto todo lo que pagó (saldo ' . number_format($saldo, 2, ',', '.') . ' €). Puede que la devolución ya esté registrada por Stripe en una fecha fuera del rango que tienes filtrado arriba: abre el detalle de pagos para verlo. No se ha creado ninguna línea.');
            }
            if (abs($importe) > $saldo + 0.01) {
                wp_send_json_error('El importe (' . number_format(abs($importe), 2, ',', '.') . ' €) supera lo que queda pendiente de devolver a este comprador (' . number_format($saldo, 2, ',', '.') . ' €). No se ha creado ninguna línea.');
            }
            // Algunos pagos antiguos tienen cuota/cuotas vacíos (NULL): forzamos 1/1
            // para que la devolución clonada cuente como pago único en las estadísticas
            // (que filtran por cuota = 1).
            $base_cuota  = (empty($base['cuota']))  ? 1 : $base['cuota'];
            $base_cuotas = (empty($base['cuotas'])) ? 1 : $base['cuotas'];

            $sintetico = 'MANUAL-REEMBOLSO-' . time() . '-' . wp_generate_password(6, false, false);
            $ins = $pdo->prepare(
                "INSERT INTO pdc_pagos
                    (ID_usuario, ID_transaccion, ID_producto, producto, oferta, importe, moneda,
                     tipo, cuotas, cuota, terminado, metodo, plataforma, estado, fecha,
                     customer, checkout_id, tipo_pago, nombre, apellidos, dni_cif, direccion,
                     ciudad, codigo_postal, pais, tax_amount, tax_percentage, cantidad,
                     reembolso, no_comision_afiliado)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, 1, 'manual', ?, 'Reembolso', NOW(),
                     ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, 0, 0, 1,
                     0, 1)"
            );
            $ins->execute(array(
                $base['ID_usuario'], $sintetico, $base['ID_producto'], $base['producto'], $base['oferta'], $importe, $base['moneda'],
                $base['tipo'], $base_cuotas, $base_cuota, $base['plataforma'],
                $base['customer'], $base['checkout_id'], $base['tipo_pago'], $base['nombre'], $base['apellidos'], $base['dni_cif'], $base['direccion'],
                $base['ciudad'], $base['codigo_postal'], $base['pais'],
            ));
            $new_id = $pdo->lastInsertId();

            $this->pdc_log($launch, $email, 'pago_manual_refund', 'Nuevo pago ID ' . $new_id . ' (devolución manual de ' . $base_pago_id . '), importe ' . $importe . ' — ' . $motivo);
            wp_send_json_success(array('pago_id' => (int) $new_id));
        } catch (Exception $e) {
            wp_send_json_error('DB: ' . $e->getMessage());
        }
    }

    /**
     * Edita el importe de un pago YA existente, pero SOLO si metodo='Manual'.
     * Los pagos reales de Stripe/plataforma no se pueden tocar desde aquí.
     * Queda registrado en el log (importe anterior → nuevo).
     *
     * El importe admite CUALQUIER signo: positivo para una venta manual cobrada
     * fuera de la plataforma (transferencia, Bizum, efectivo), negativo para una
     * devolución y 0 para un acceso dado sin cobro — que es como nacen casi todas
     * las altas manuales de la academia. Antes se exigía negativo, y por eso un
     * alta de 200 € acabó guardada como -200.
     */
    public function edit_pdc_manual_importe()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pago_id = intval($_POST['pago_id'] ?? 0);
        // Sin normalizar todavía: hay que distinguir "vacío" de "cero", porque
        // floatval('') es 0 y borraría el importe de un pago sin haberlo pedido.
        $importe_raw = str_replace(',', '.', trim((string) ($_POST['importe'] ?? '')));
        if (empty($launch) || empty($email) || $pago_id <= 0) {
            wp_send_json_error('Datos incompletos.');
        }
        if ($importe_raw === '' || !is_numeric($importe_raw)) {
            wp_send_json_error('Importe no válido.');
        }
        $importe = floatval($importe_raw);
        $groups = function_exists('endtrack_get_launch_product_groups') ? endtrack_get_launch_product_groups($launch) : array();
        $products = !empty($groups['all']) ? $groups['all'] : json_decode(get_option('endtrack_ext_products_' . $launch, '[]'), true);
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('Sin productos configurados para el lanzamiento.');
        }
        $creds = function_exists('endtrack_get_pdc_credentials') ? endtrack_get_pdc_credentials($launch) : array();
        if (empty($creds['host']) || empty($creds['user'])) {
            wp_send_json_error('Credenciales PDC no configuradas.');
        }
        try {
            $pdo = new PDO(
                "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            $ph = implode(',', array_fill(0, count($products), '?'));
            $sql = "SELECT A.ID, A.metodo, A.importe FROM pdc_pagos A
                    INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                    WHERE A.ID = ? AND LOWER(TRIM(U.mail)) = ? AND A.ID_producto IN ($ph)
                    LIMIT 1";
            $params = array_merge(array($pago_id, $email), $products);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                wp_send_json_error('Pago no encontrado o fuera de tu ámbito de acceso.');
            }
            if (strtolower((string) $row['metodo']) !== 'manual') {
                wp_send_json_error('Solo se puede editar el importe de pagos manuales.');
            }
            $importe_anterior = $row['importe'];

            $upd = $pdo->prepare("UPDATE pdc_pagos SET importe = ? WHERE ID = ?");
            $upd->execute(array($importe, $pago_id));

            $this->pdc_log($launch, $email, 'pago_manual_importe_edit', 'Pago ID ' . $pago_id . ': ' . $importe_anterior . ' -> ' . $importe);
            wp_send_json_success(array('pago_id' => $pago_id, 'importe' => $importe));
        } catch (Exception $e) {
            wp_send_json_error('DB: ' . $e->getMessage());
        }
    }

    /**
     * Marca/desmarca una fila con estado=Reembolso como "Reembolso real"
     * (comprador duplicado que compró de nuevo con otro correo y se le
     * devolvió todo) frente a una "Devolución" normal. Es una simple
     * clasificación manual (columna pdc_pagos.reembolso = 1/0) que decide
     * el equipo de soporte fila a fila; no cambia el estado ni el importe.
     */
    public function set_pdc_reembolso_flag()
    {
        if (!current_user_can('manage_options') && !current_user_can('edit_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch  = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($_POST['launch'] ?? ''));
        $email   = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pago_id = intval($_POST['pago_id'] ?? 0);
        $flag    = intval($_POST['reembolso'] ?? 0) === 1 ? 1 : 0;
        if (empty($launch) || empty($email) || $pago_id <= 0) {
            wp_send_json_error('Datos incompletos.');
        }
        $groups = function_exists('endtrack_get_launch_product_groups') ? endtrack_get_launch_product_groups($launch) : array();
        $products = !empty($groups['all']) ? $groups['all'] : json_decode(get_option('endtrack_ext_products_' . $launch, '[]'), true);
        if (!is_array($products) || empty($products)) {
            wp_send_json_error('Sin productos configurados para el lanzamiento.');
        }
        $creds = function_exists('endtrack_get_pdc_credentials') ? endtrack_get_pdc_credentials($launch) : array();
        if (empty($creds['host']) || empty($creds['user'])) {
            wp_send_json_error('Credenciales PDC no configuradas.');
        }
        try {
            $pdo = new PDO(
                "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            $ph = implode(',', array_fill(0, count($products), '?'));
            $sql = "SELECT A.ID, A.estado FROM pdc_pagos A
                    INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                    WHERE A.ID = ? AND LOWER(TRIM(U.mail)) = ? AND A.ID_producto IN ($ph)
                    LIMIT 1";
            $params = array_merge(array($pago_id, $email), $products);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                wp_send_json_error('Pago no encontrado o fuera de tu ámbito de acceso.');
            }
            if ((string) $row['estado'] !== 'Reembolso') {
                wp_send_json_error('Solo se puede clasificar como Reembolso/Devolución una fila con estado Reembolso.');
            }

            $upd = $pdo->prepare('UPDATE pdc_pagos SET reembolso = ? WHERE ID = ?');
            $upd->execute(array($flag, $pago_id));

            $this->pdc_log($launch, $email, 'reembolso_flag_set', 'Pago ID ' . $pago_id . ' marcado como ' . ($flag ? 'Reembolso (duplicado con otro correo)' : 'Devolución'));
            wp_send_json_success(array('pago_id' => $pago_id, 'reembolso' => $flag));
        } catch (Exception $e) {
            wp_send_json_error('DB: ' . $e->getMessage());
        }
    }

    public function verify_pdc_stripe()
    {
        if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats')) {
            wp_send_json_error('Sin permisos.');
        }
        check_ajax_referer('endtrack_pdc_nonce', 'nonce');
        $launch = (string) ($_POST['launch'] ?? '');
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $customer = sanitize_text_field((string) ($_POST['customer'] ?? ''));
        $subscription = sanitize_text_field((string) ($_POST['subscription'] ?? ''));
        $payment_intent = sanitize_text_field((string) ($_POST['payment_intent'] ?? ''));
        if (empty($customer) && empty($subscription) && empty($payment_intent)) {
            wp_send_json_error('Falta customer/subscription/payment_intent.');
        }
        $creds = function_exists('endtrack_get_pdc_credentials') ? endtrack_get_pdc_credentials($launch) : array();
        // Varias cuentas Stripe: las claves pueden venir separadas por saltos de línea,
        // comas o espacios. Se prueba en cada cuenta hasta encontrar el pago.
        $keys_raw = isset($creds['stripe_key']) ? (string) $creds['stripe_key'] : '';
        $keys = preg_split('/[\s,]+/', $keys_raw, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($keys)) {
            wp_send_json_error('Stripe Secret Key no configurada para este lanzamiento.');
        }

        // Choose endpoint
        if (!empty($subscription)) {
            $url = 'https://api.stripe.com/v1/subscriptions/' . rawurlencode($subscription);
            $kind = 'subscription';
        } elseif (!empty($payment_intent)) {
            $url = 'https://api.stripe.com/v1/payment_intents/' . rawurlencode($payment_intent);
            $kind = 'payment_intent';
        } else {
            $url = 'https://api.stripe.com/v1/customers/' . rawurlencode($customer);
            $kind = 'customer';
        }

        $body = null;
        $found_key = '';
        $found_idx = 0;
        $last_err = '';
        foreach ($keys as $ki => $key) {
            $resp = wp_remote_get($url, array(
                'headers' => array('Authorization' => 'Bearer ' . $key),
                'timeout' => 15,
            ));
            if (is_wp_error($resp)) {
                $last_err = 'Stripe error: ' . $resp->get_error_message();
                continue;
            }
            $code = wp_remote_retrieve_response_code($resp);
            $b = json_decode(wp_remote_retrieve_body($resp), true);
            if ($code < 400) {
                $body = $b;
                $found_key = $key;
                $found_idx = $ki + 1;
                break;
            }
            $last_err = 'Stripe ' . $code . ': ' . (isset($b['error']['message']) ? $b['error']['message'] : 'HTTP ' . $code);
        }
        if ($body === null) {
            $this->pdc_log($launch, $email, 'stripe_verify_err', array('kind' => $kind, 'err' => $last_err, 'cuentas' => count($keys)));
            wp_send_json_error(count($keys) > 1
                ? 'No encontrado en ninguna de las ' . count($keys) . ' cuentas Stripe. Último error — ' . $last_err
                : $last_err);
        }

        $summary = array('kind' => $kind, 'id' => isset($body['id']) ? $body['id'] : '');
        if (count($keys) > 1) {
            $summary['cuenta_stripe'] = $found_idx . ' de ' . count($keys) . ' (…' . substr($found_key, -6) . ')';
        }
        // Enlace directo al objeto en el dashboard de Stripe (con /test/ si no es live)
        if (!empty($summary['id'])) {
            $dash = 'https://dashboard.stripe.com/' . ((isset($body['livemode']) && !$body['livemode']) ? 'test/' : '');
            if ($kind === 'subscription') {
                $summary['stripe_url'] = $dash . 'subscriptions/' . $summary['id'];
            } elseif ($kind === 'payment_intent') {
                $summary['stripe_url'] = $dash . 'payments/' . $summary['id'];
            } else {
                $summary['stripe_url'] = $dash . 'customers/' . $summary['id'];
            }
        }
        if ($kind === 'subscription') {
            $summary['status'] = $body['status'] ?? '';
            $summary['current_period_end'] = $body['current_period_end'] ?? null;
            $summary['cancel_at'] = $body['cancel_at'] ?? null;
        } elseif ($kind === 'payment_intent') {
            $summary['status'] = $body['status'] ?? '';
            $summary['amount'] = $body['amount'] ?? null;
            $summary['currency'] = $body['currency'] ?? '';
        } else {
            $summary['email'] = $body['email'] ?? '';
            $summary['delinquent'] = $body['delinquent'] ?? false;
            $summary['name'] = $body['name'] ?? '';
        }
        $this->pdc_log($launch, $email, 'stripe_verify_ok', $summary);
        wp_send_json_success($summary);
    }

    public function regenerate_copy()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        check_ajax_referer('endtrack_regenerate_copy_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $page_type = isset($_POST['page_type']) ? sanitize_text_field($_POST['page_type']) : '';
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';

        if (empty($post_id) || empty($page_type)) {
            wp_send_json_error('Datos inválidos.');
        }

        // Use custom prompt if provided, otherwise use a default
        if (empty($custom_prompt)) {
            $custom_prompt = 'Mejora el copy de esta página manteniendo el mensaje principal pero haciéndolo más persuasivo y profesional.';
        }

        $result = ENDTrack_AI::generate_copy_for_page($post_id, $page_type, $custom_prompt);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Copy regenerado exitosamente con IA.',
            'post_id' => $post_id
        ));
    }

    public function regenerate_launch_copy()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        check_ajax_referer('endtrack_regenerate_copy_nonce', 'nonce');

        $launch_name = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';

        if (empty($launch_name)) {
            wp_send_json_error('Lanzamiento no especificado.');
        }

        if (empty($custom_prompt)) {
            $custom_prompt = 'Mejora el copy de todas las páginas de este lanzamiento para que sean más persuasivas y profesionales.';
        }

        $mapping = get_option('endtrack_launches_mapping', array());
        $launch_cat_slug = isset($mapping[$launch_name]) ? $mapping[$launch_name] : sanitize_title($launch_name);

        $types = array(
            'registro' => 'registro',
            'gracias-registro' => 'gracias_registro',
            'venta' => 'ventas',
            'gracias' => 'gracias'
        );

        $results = array();
        foreach ($types as $cat_slug => $ai_type) {
            $args = array(
                'post_type' => 'page',
                'posts_per_page' => -1,
                'tax_query' => array(
                    'relation' => 'AND',
                    array(
                        'taxonomy' => 'category',
                        'field' => 'slug',
                        'terms' => $launch_cat_slug,
                    ),
                    array(
                        'taxonomy' => 'category',
                        'field' => 'slug',
                        'terms' => $cat_slug,
                    ),
                ),
            );
            $pages = get_posts($args);

            foreach ($pages as $p) {
                $res = ENDTrack_AI::generate_copy_for_page($p->ID, $ai_type, $custom_prompt);
                if (is_wp_error($res)) {
                    $results[] = "Error en {$p->post_title}: " . $res->get_error_message();
                } else {
                    $results[] = "Página {$p->post_title} actualizada correctamente.";
                }
            }
        }

        if (empty($results)) {
            wp_send_json_error('No se encontraron páginas para este lanzamiento.');
        }

        wp_send_json_success(array(
            'message' => implode("\n", $results),
            'details' => $results
        ));
    }

    /**
     * Frontend AI Edit — Called from the floating AI button on public pages
     */
    public function frontend_ai_edit()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos para editar con IA.');
        }

        check_ajax_referer('endtrack_frontend_ai_nonce', 'nonce');

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field($_POST['custom_prompt']) : '';

        if (empty($post_id)) {
            wp_send_json_error('ID de página no válido.');
        }

        if (empty($custom_prompt)) {
            wp_send_json_error('Debes escribir una instrucción para la IA.');
        }

        // Auto-detect page type from categories
        $page_type = 'general';
        $categories = wp_get_post_categories($post_id, array('fields' => 'slugs'));
        if (is_array($categories)) {
            if (in_array('venta', $categories)) {
                $page_type = 'ventas';
            } elseif (in_array('registro', $categories) || in_array('registroPB', $categories)) {
                $page_type = 'registro';
            } elseif (in_array('gracias-registro', $categories)) {
                $page_type = 'gracias_registro';
            } elseif (in_array('gracias', $categories)) {
                $page_type = 'gracias';
            }
        }

        $result = ENDTrack_AI::generate_copy_for_page($post_id, $page_type, $custom_prompt);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Cambios aplicados por Claude. Recarga la página para verlos.',
            'post_id' => $post_id,
            'page_type' => $page_type
        ));
    }

    public function load_stats_section()
    {
        if (!current_user_can('manage_options') && !current_user_can('stats_viewer')) {
            wp_send_json_error('No tienes permisos.');
        }
        check_ajax_referer('endtrack_load_section', 'nonce');

        $section_id = isset($_POST['section_id']) ? sanitize_text_field($_POST['section_id']) : '';
        $allowed = ['sec_source', 'sec_country', 'sec_medium', 'sec_pages', 'sec_affiliates', 'sec_campaigns', 'sec_emails'];
        if (!in_array($section_id, $allowed)) {
            wp_send_json_error('Sección no válida.');
        }

        require_once ENDTRACK_PLUGIN_DIR . 'includes/endtrack-stats-functions.php';

        // Build query context from POST params (same keys as $_GET on the stats page)
        endtrack_stats_build_context($_POST);

        $section = endtrack_stats_compute_section($section_id, $_POST);
        if (!$section) {
            wp_send_json_error('No se pudo calcular la sección.');
        }

        wp_send_json_success(['section' => $section]);
    }

    public function load_email_list()
    {
        if (!current_user_can('administrator') && get_current_user_id() != 15) {
            wp_die('No permission');
        }

        global $wpdb;
        $table_datos = $wpdb->prefix . 'datos';

        $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $limit = 100;
        $offset = ($paged - 1) * $limit;

        $where = "WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $where .= " AND correo LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql = "SELECT correo, source, medium, afiliado, campaign, fecha as fecha_crea, primer_reg 
                FROM $table_datos $where 
                ORDER BY fecha DESC LIMIT %d OFFSET %d";

        $results = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$limit, $offset])));

        // Map affiliate IDs to display names for better readability
        if ($results) {
            foreach ($results as &$r) {
                if (!empty($r->afiliado) && is_numeric($r->afiliado)) {
                    $user = get_userdata((int) $r->afiliado);
                    if ($user) {
                        $r->afiliado = $user->display_name . " ($r->afiliado)";
                    }
                }
            }
        }

        $count_sql = "SELECT COUNT(*) FROM $table_datos $where";
        if (!empty($params)) {
            $total_items = $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total_items = $wpdb->get_var($count_sql);
        }

        wp_send_json_success([
            'data' => $results,
            'total' => (int) $total_items,
            'pages' => ceil((int) $total_items / $limit),
            'current_page' => $paged
        ]);
    }
}