<?php

class ENDTrack_Admin
{

    public function init()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_endtrack_create_launch', array($this, 'handle_create_launch'));
        add_action('admin_post_endtrack_delete_launch', array($this, 'handle_delete_launch'));
        add_action('admin_post_endtrack_save_texts', array($this, 'handle_save_texts'));
        add_action('admin_post_endtrack_save_integrations', array($this, 'handle_save_integrations'));
        add_action('admin_post_endtrack_save_products', array($this, 'handle_save_products'));
        add_action('admin_post_endtrack_save_affiliate_pixels', array($this, 'handle_save_affiliate_pixels'));
        add_action('admin_post_endtrack_create_grafana', array($this, 'handle_create_grafana_dashboard'));
        add_action('admin_post_endtrack_delete_grafana', array($this, 'handle_delete_grafana'));
        add_action('admin_post_endtrack_update_all_grafanas', array($this, 'handle_update_all_grafanas'));
        add_action('admin_post_endtrack_flush_rewrites', array($this, 'handle_flush_rewrites'));
        add_action('wp_ajax_endtrack_save_commission', array($this, 'ajax_save_commission'));
        add_action('wp_ajax_endtrack_save_upfront', array($this, 'ajax_save_upfront'));
        add_action('admin_init', array($this, 'migrate_launches'));
        add_action('admin_init', array($this, 'add_thrivecart_columns'));
        add_action('wp_ajax_endtrack_toggle_affiliate_link', array($this, 'ajax_toggle_affiliate_link'));
        add_action('wp_ajax_endtrack_add_payment', array($this, 'ajax_add_payment'));
        add_action('wp_ajax_endtrack_delete_payment', array($this, 'ajax_delete_payment'));
        add_action('wp_ajax_endtrack_delete_affiliate', array($this, 'ajax_delete_affiliate'));
        add_action('admin_init', array($this, 'ensure_global_categories'));
        add_action('init', array($this, 'enable_page_categories'), 99);
    }

    public function enable_page_categories()
    {
        register_taxonomy_for_object_type('category', 'page');
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'ENDTrack',
            'ENDTrack',
            'manage_options',
            'endtrack',
            array($this, 'display_dashboard'),
            'dashicons-chart-area',
            6
        );
    }

    public function display_dashboard()
    {
        require_once ENDTRACK_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    public function handle_create_launch()
    {
        $log_file = WP_CONTENT_DIR . '/endtrack_debug.log';
        if (!file_exists($log_file)) {
            @touch($log_file);
            @chmod($log_file, 0666);
        }
        error_log("ENDTrack Debug: handle_create_launch CALLED at " . date('Y-m-d H:i:s') . "\n", 3, $log_file);

        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('endtrack_create_launch_action', 'endtrack_create_launch_nonce');

        $launch_name = sanitize_text_field($_POST['launch_name']);
        $launch_type = isset($_POST['launch_type']) ? intval($_POST['launch_type']) : 1; // 1: Direct, 2: Reg
        $url_venta = isset($_POST['url_venta']) ? esc_url_raw($_POST['url_venta']) : '';
        $url_gracias_compra = isset($_POST['url_gracias_compra']) ? esc_url_raw($_POST['url_gracias_compra']) : '';
        $url_registro = isset($_POST['url_registro']) ? esc_url_raw($_POST['url_registro']) : '';
        $url_gracias_reg = isset($_POST['url_gracias_registro']) ? esc_url_raw($_POST['url_gracias_registro']) : '';

        if (empty($launch_name)) {
            wp_die('El nombre del lanzamiento no puede estar vacío.');
        }

        // 1. Create WordPress Category
        $cat_id = wp_create_category($launch_name);

        if (is_wp_error($cat_id)) {
            // Try to retrieve it if it exists
            $cat_id = get_cat_ID($launch_name);
        }
        $category = get_category($cat_id);
        $cat_slug = $category->slug;

        // 2. Add columns to DB
        global $wpdb;
        $table_datos = $wpdb->prefix . 'datos';
        $table_visitas = $wpdb->prefix . 'visitas';

        // Sanitize for SQL column name (alphanumeric only)
        $safe_launch_name = preg_replace('/[^a-zA-Z0-9_]/', '', $launch_name);
        $col_tipo_cat = "tipo_cat_" . $safe_launch_name;
        $col_primer_reg = "primer_reg_" . $safe_launch_name;

        // Check and add columns to BOTH tables
        $tables_to_alter = array($table_datos, $table_visitas);
        foreach ($tables_to_alter as $table) {
            // Check for $col_tipo_cat
            $has_tipo = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col_tipo_cat'");
            if (empty($has_tipo)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN $col_tipo_cat TINYINT(1) DEFAULT 0");
            }
            else {
                // Ensure it's TINYINT if it was VARCHAR (migration/fix)
                $wpdb->query("ALTER TABLE $table MODIFY COLUMN $col_tipo_cat TINYINT(1) DEFAULT 0");
            }

            // Check for $col_primer_reg
            $has_primer = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col_primer_reg'");
            if (empty($has_primer)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN $col_primer_reg TINYINT(1) DEFAULT 0");
            }
        }

        // 3. Save to options (Mapping sanitized_name => category_slug)
        $launches_mapping = get_option('endtrack_launches_mapping', array());
        $launches_mapping[$safe_launch_name] = $cat_slug;
        update_option('endtrack_launches_mapping', $launches_mapping);

        // Keep legacy array for compatibility if needed elsewhere
        $launches = get_option('endtrack_launches', array());
        if (!in_array($safe_launch_name, $launches)) {
            $launches[] = $safe_launch_name;
            update_option('endtrack_launches', $launches);
        }

        // 3.1 Save Launch Config
        $launch_configs = get_option('endtrack_launch_configs', array());
        $launch_configs[$safe_launch_name] = array(
            'type' => $launch_type
        );
        update_option('endtrack_launch_configs', $launch_configs);

        // 3.2 Initialize Visibility (Default: Hidden)
        $visibility_map = get_option('endtrack_launch_visibility', array());
        $visibility_map[$launch_name] = false;
        update_option('endtrack_launch_visibility', $visibility_map);

        // Grafana Dashboard Creation Removed (Native Panel Used)
        $msg = 'launch_created';

        // --- CREATE PAGES & FOLDERS ---
        if ($launch_type != 3) {
            $texts = get_option('endtrack_texts', array());
            $wf_taxonomy = isset($texts['wf_taxonomy']) ? $texts['wf_taxonomy'] : 'wf_page_folders';

            // 1. Create Wicked Folder
            $folder_id = 0;
            if (taxonomy_exists($wf_taxonomy)) {
                $term = wp_insert_term($launch_name, $wf_taxonomy);
                if (!is_wp_error($term)) {
                    $folder_id = $term['term_id'];
                }
                else if (is_wp_error($term) && isset($term->error_data['term_exists'])) {
                    $folder_id = $term->error_data['term_exists'];
                }
            }

            // 2. Define pages to create
            $pages_to_create = array();
            if ($launch_type == 1) { // Direct
                $pages_to_create = array(
                    'ventas' => array(
                        'title' => $launch_name . ' - Ventas',
                        'reference_url' => $url_venta,
                        'category' => 'venta'
                    ),
                    'gracias_compra' => array(
                        'title' => $launch_name . ' - Gracias por comprar',
                        'reference_url' => $url_gracias_compra,
                        'category' => 'gracias'
                    )
                );
            }
            else { // Reg
                $pages_to_create = array(
                    'registro' => array(
                        'title' => $launch_name . ' - Registro',
                        'reference_url' => $url_registro,
                        'category' => 'registro'
                    ),
                    'gracias_reg' => array(
                        'title' => $launch_name . ' - Gracias por registrarte',
                        'reference_url' => $url_gracias_reg,
                        'category' => 'Gracias Registro'
                    ),
                    'ventas' => array(
                        'title' => $launch_name . ' - Ventas',
                        'reference_url' => $url_venta,
                        'category' => 'venta'
                    ),
                    'gracias_compra' => array(
                        'title' => $launch_name . ' - Gracias por comprar',
                        'reference_url' => $url_gracias_compra,
                        'category' => 'gracias'
                    )
                );
            }

            $created_pages = array();
            foreach ($pages_to_create as $slug_key => $page_info) {
                $page_args = array(
                    'post_title' => $page_info['title'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_name' => sanitize_title($page_info['title'])
                );

                $post_id = wp_insert_post($page_args);

                if ($post_id) {
                    $created_pages[$slug_key] = $post_id;
                    // Assign to Wicked Folder
                    if ($folder_id) {
                        wp_set_object_terms($post_id, array((int)$folder_id), $wf_taxonomy);
                    }

                    // Assign to Launch Category
                    wp_set_object_terms($post_id, array((int)$cat_id), 'category');

                    // Assign specific page category
                    if (!empty($page_info['category'])) {
                        $specific_cat_name = $page_info['category'];
                        // Check if category exists, if not create it
                        $term = term_exists($specific_cat_name, 'category');
                        if ($term === 0 || $term === null) {
                            $term = wp_create_category($specific_cat_name); // Returns term_id
                            $specific_cat_id = $term;
                        }
                        else {
                            $specific_cat_id = is_array($term) ? $term['term_id'] : $term;
                        }

                        if ($specific_cat_id) {
                            wp_set_object_terms($post_id, array((int)$specific_cat_id), 'category', true); // Append
                        }
                    }

                    // Set up as empty Elementor canvas page (AI will generate the content)
                    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                    update_post_meta($post_id, '_elementor_template_type', 'page');
                    update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');
                }
            }
        }

        // AI Copy Generation (if prompt or reference URLs provided)
        $ai_prompt_raw = isset($_POST['ai_prompt']) ? trim($_POST['ai_prompt']) : '';
        $has_urls = !empty($url_venta) || !empty($url_gracias_compra) || !empty($url_registro) || !empty($url_gracias_reg);

        if (!empty($ai_prompt_raw) || $has_urls) {
            $base_prompt = !empty($ai_prompt_raw)
                ? sanitize_textarea_field($ai_prompt_raw)
                : 'Crea el copy para esta página de lanzamiento.';

            $page_url_map = array(
                'ventas' => $url_venta,
                'registro' => $url_registro,
                'gracias_compra' => $url_gracias_compra,
                'gracias_reg' => $url_gracias_reg,
            );

            $page_types = array(
                'ventas' => 'ventas',
                'registro' => 'registro',
                'gracias_compra' => 'gracias',
                'gracias_reg' => 'gracias_registro',
            );

            foreach ($page_types as $slug_key => $page_type) {
                if (!isset($created_pages[$slug_key]) || !$created_pages[$slug_key])
                    continue;

                $ref_url = !empty($page_url_map[$slug_key]) ? $page_url_map[$slug_key] : '';
                $prompt = $base_prompt;

                if (!empty($ref_url)) {
                    $ref_context = $this->extract_url_context($ref_url);
                    $prompt = "REFERENCIA DE DISEÑO: Imita la estructura y número de bloques de la siguiente URL.\n"
                        . "URL: {$ref_url}\n"
                        . (!empty($ref_context) ? "\n{$ref_context}\n" : '')
                        . "\nINSTRUCCIÓN DEL USUARIO:\n{$base_prompt}";
                }

                $result = ENDTrack_AI::generate_copy_for_page($created_pages[$slug_key], $page_type, $prompt);

                if (is_wp_error($result)) {
                    error_log("ENDTrack AI Error for {$slug_key}: " . $result->get_error_message());
                }
            }
        }

        // Redirect back
        wp_redirect(admin_url('admin.php?page=endtrack&message=' . $msg));
        exit;
    }

    public function handle_delete_launch()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('endtrack_delete_launch_action', 'endtrack_delete_launch_nonce');

        $launch_name = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : '';

        if (empty($launch_name)) {
            wp_die('Lanzamiento no especificado.');
        }

        // 1. Remove from options
        $launches = get_option('endtrack_launches', array());
        if (($key = array_search($launch_name, $launches)) !== false) {
            unset($launches[$key]);
            update_option('endtrack_launches', array_values($launches));
        }

        $launches_mapping = get_option('endtrack_launches_mapping', array());
        if (isset($launches_mapping[$launch_name])) {
            unset($launches_mapping[$launch_name]);
            update_option('endtrack_launches_mapping', $launches_mapping);
        }

        $launch_links = get_option('endtrack_launch_links', array());
        if (isset($launch_links[$launch_name])) {
            unset($launch_links[$launch_name]);
            update_option('endtrack_launch_links', $launch_links);
        }

        // 2. Drop database columns
        global $wpdb;
        $table_datos = $wpdb->prefix . 'datos';
        $table_visitas = $wpdb->prefix . 'visitas';

        $safe_launch_name = preg_replace('/[^a-zA-Z0-9_]/', '', $launch_name);
        $col_tipo_cat = "tipo_cat_" . $safe_launch_name;
        $col_primer_reg = "primer_reg_" . $safe_launch_name;

        $tables_to_alter = array($table_datos, $table_visitas);
        foreach ($tables_to_alter as $table) {
            // Drop $col_tipo_cat
            $has_tipo = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col_tipo_cat'");
            if (!empty($has_tipo)) {
                $wpdb->query("ALTER TABLE $table DROP COLUMN $col_tipo_cat");
            }

            // Drop $col_primer_reg
            $has_primer = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col_primer_reg'");
            if (!empty($has_primer)) {
                $wpdb->query("ALTER TABLE $table DROP COLUMN $col_primer_reg");
            }
        }

        wp_redirect(admin_url('admin.php?page=endtrack&tab=launches&message=launch_deleted'));
        exit;
    }

    public function handle_create_grafana_dashboard()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        $launch_name = isset($_GET['launch']) ? sanitize_text_field(urldecode($_GET['launch'])) : '';

        if (empty($launch_name)) {
            wp_die('Falta el nombre del lanzamiento.');
        }

        // We need the "safe" name for column references
        // We can get it from the mapping or re-sanitize it
        // The safest way is to check the mapping option
        $mapping = get_option('endtrack_launches_mapping', array());
        $safe_launch_name = '';

        // Find safe name by checking if key exists directly or value matches (backwards search not ideal)
        // Actually, $launch_name passed here is the "key" from the $launches array loop in template.
        // In the template loop: foreach ($launches as $launch)
        // $launches array is populated with $safe_launch_name in handle_create_launch.
        // So $launch_name IS $safe_launch_name.
        $safe_launch_name = $launch_name;

        // However, we also need a human readable title for the dashboard.
        // We don't store the original display name separately easily accessibly besides the term name if it matches.
        // Let's use the slug as title or try to fetch category name if available.
        $launch_title = $safe_launch_name;
        if (isset($mapping[$safe_launch_name])) {
            $cat_slug = $mapping[$safe_launch_name];
            // Try to get category to get nice name
            $term = get_term_by('slug', $cat_slug, 'category');
            if ($term) {
                $launch_title = $term->name;
            }
        }

        $launch_configs = get_option('endtrack_launch_configs', array());
        $launch_type = isset($launch_configs[$safe_launch_name]['type']) ? $launch_configs[$safe_launch_name]['type'] : 1;

        require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-grafana.php';
        $dashboard_url = ENDTrack_Grafana::create_dashboard($launch_title, $safe_launch_name, $launch_type);

        $msg = 'dashboard_created';

        if (!is_wp_error($dashboard_url)) {
            // Save dashboard link
            $dashboards = get_option('endtrack_launch_dashboards', array());
            $dashboards[$safe_launch_name] = $dashboard_url;
            update_option('endtrack_launch_dashboards', $dashboards);
        }
        else {
            $msg = 'grafana_error&details=' . urlencode($dashboard_url->get_error_message());
        }

        wp_redirect(admin_url('admin.php?page=endtrack&message=' . $msg));
        exit;
    }

    public function handle_delete_grafana()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('endtrack_delete_grafana_action', 'endtrack_delete_grafana_nonce');

        $launch_name = isset($_GET['launch']) ? sanitize_text_field(urldecode($_GET['launch'])) : '';

        if (empty($launch_name)) {
            wp_die('Falta el nombre del lanzamiento.');
        }

        $dashboards = get_option('endtrack_launch_dashboards', array());
        if (isset($dashboards[$launch_name])) {
            unset($dashboards[$launch_name]);
            update_option('endtrack_launch_dashboards', $dashboards);
        }

        wp_redirect(admin_url('admin.php?page=endtrack&message=dashboard_deleted'));
        exit;
    }

    public function handle_update_all_grafanas()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('endtrack_update_all_grafanas_action', 'endtrack_update_all_grafanas_nonce');

        $password = isset($_POST['grafana_password']) ? sanitize_text_field($_POST['grafana_password']) : '';
        if ($password !== 'chorlitejo') {
            wp_die('Contraseña incorrecta para esta acción.');
        }

        $launches = get_option('endtrack_launches', array());
        $mapping = get_option('endtrack_launches_mapping', array());
        $configs = get_option('endtrack_launch_configs', array());

        if (empty($launches)) {
            wp_redirect(admin_url('admin.php?page=endtrack&message=no_launches'));
            exit;
        }

        require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-grafana.php';

        $success_count = 0;
        $error_count = 0;
        $dashboards = get_option('endtrack_launch_dashboards', array());

        foreach ($launches as $safe_launch_name) {
            $launch_title = $safe_launch_name;
            if (isset($mapping[$safe_launch_name])) {
                $cat_slug = $mapping[$safe_launch_name];
                $term = get_term_by('slug', $cat_slug, 'category');
                if ($term) {
                    $launch_title = $term->name;
                }
            }

            $launch_type = isset($configs[$safe_launch_name]['type']) ? $configs[$safe_launch_name]['type'] : 1;
            $dashboard_url = ENDTrack_Grafana::create_dashboard($launch_title, $safe_launch_name, $launch_type);

            if (!is_wp_error($dashboard_url)) {
                $dashboards[$safe_launch_name] = $dashboard_url;
                $success_count++;
            }
            else {
                $error_count++;
            }
        }

        update_option('endtrack_launch_dashboards', $dashboards);

        $msg = 'grafanas_updated';
        if ($error_count > 0) {
            $msg = 'grafanas_updated_with_errors';
        }

        wp_redirect(admin_url('admin.php?page=endtrack&message=' . $msg . '&success=' . $success_count . '&errors=' . $error_count));
        exit;
    }

    public function handle_flush_rewrites()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permiso.');
        }
        check_admin_referer('endtrack_flush_rewrites_action', 'endtrack_flush_rewrites_nonce');

        flush_rewrite_rules();

        wp_redirect(admin_url('admin.php?page=endtrack&message=rewrites_flushed'));
        exit;
    }

    public function handle_save_texts()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('endtrack_save_texts_action', 'endtrack_save_texts_nonce');

        if (isset($_POST['texts']) && is_array($_POST['texts'])) {
            $texts = $_POST['texts'];

            // Sanitize input
            $sanitized_texts = array();
            foreach ($texts as $key => $value) {
                // Allow HTML for rich text fields, sanitize others strictly
                if (in_array($key, ['content_creatividades', 'content_asignacion', 'content_billing_methods']) || strpos($key, 'content_creatividades_') === 0 || strpos($key, 'content_billing_methods_') === 0) {
                    $sanitized_texts[$key] = wp_kses_post($value);
                }
                else {
                    $sanitized_texts[$key] = sanitize_text_field($value);
                }
            }

            // Fusionar con lo ya guardado, no reemplazar: este mismo option
            // 'endtrack_texts' también guarda ajustes de la pestaña
            // "Integraciones" (ext_db_*, anthropic_key, grafana_*) y
            // update_option() a pelo aquí los borraba en cada guardado de
            // Textos (bug detectado 2026-08-11: las credenciales de la BBDD
            // externa de ventas desaparecían sin motivo aparente).
            $texts_existentes = get_option('endtrack_texts', array());
            update_option('endtrack_texts', array_merge($texts_existentes, $sanitized_texts));
        }

        // Redirect back
        wp_redirect(admin_url('admin.php?page=endtrack&tab=texts&message=texts_saved'));
        exit;
    }

    public function handle_save_integrations()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('endtrack_save_integrations_action', 'endtrack_save_integrations_nonce');

        // Get existing texts option
        $texts = get_option('endtrack_texts', array());

        // Save Anthropic (Claude) API Key
        if (isset($_POST['anthropic_key'])) {
            $texts['anthropic_key'] = sanitize_text_field($_POST['anthropic_key']);
        }

        // Save Claude model
        $allowed_models = array('claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250514', 'claude-sonnet-4-20250514');
        if (isset($_POST['claude_model']) && in_array($_POST['claude_model'], $allowed_models)) {
            $texts['claude_model'] = sanitize_text_field($_POST['claude_model']);
        }

        // Save Grafana settings
        if (isset($_POST['grafana_url'])) {
            $texts['grafana_url'] = esc_url_raw($_POST['grafana_url']);
        }

        if (isset($_POST['grafana_token'])) {
            $texts['grafana_token'] = sanitize_text_field($_POST['grafana_token']);
        }

        if (isset($_POST['grafana_datasource_uid'])) {
            $texts['grafana_datasource_uid'] = sanitize_text_field($_POST['grafana_datasource_uid']);
        }

        // BBDD externa de ventas (pdc_pagos): host / nombre / usuario / contraseña.
        // Si un campo de contraseña llega vacío, se conserva el valor anterior
        // (para no borrarla sin querer al guardar el resto).
        foreach (array('ext_db_host', 'ext_db_name', 'ext_db_user') as $k) {
            if (isset($_POST[$k])) {
                $texts[$k] = sanitize_text_field($_POST[$k]);
            }
        }
        if (isset($_POST['ext_db_pass']) && $_POST['ext_db_pass'] !== '') {
            $texts['ext_db_pass'] = sanitize_text_field($_POST['ext_db_pass']);
        }

        // Stripe global: una o varias Secret Keys (una por línea). La leen
        // endtrack_get_pdc_credentials() y el botón "Verificar con Stripe" de
        // /ventas-completas/ cuando el lanzamiento no tiene override propio.
        // Vacío = conservar la actual (igual que la contraseña).
        if (isset($_POST['stripe_secret_key']) && trim((string) $_POST['stripe_secret_key']) !== '') {
            $texts['stripe_secret_key'] = sanitize_textarea_field(wp_unslash($_POST['stripe_secret_key']));
        }
        if (!empty($_POST['stripe_secret_key_clear'])) {
            $texts['stripe_secret_key'] = '';
        }

        update_option('endtrack_texts', $texts);

        // Redirect back
        wp_redirect(admin_url('admin.php?page=endtrack&tab=integrations&message=integrations_saved'));
        exit;
    }

    /**
     * Guarda los id_producto (ventas) de un lanzamiento en endtrack_ext_products_{slug}.
     * Uno por línea. Los leen stats-ventas-fullscreen.php y class-endtrack-adspend.php.
     */
    public function handle_save_products()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }
        check_admin_referer('endtrack_save_products_action', 'endtrack_save_products_nonce');

        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        $safe_l = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);

        // Solo se reescribe el listado si el formulario lo envía: el panel nuevo
        // guarda productos y comisión por AJAX y por separado.
        if (isset($_POST['ext_products'])) {
            $raw = (string) wp_unslash($_POST['ext_products']);
            $lines = preg_split('/[\r\n,]+/', $raw);
            $prods = array();
            foreach ($lines as $line) {
                $v = sanitize_text_field(trim($line));
                if ($v !== '') {
                    $prods[] = $v;
                }
            }
            $prods = array_values(array_unique($prods));

            update_option('endtrack_ext_products_' . $safe_l, wp_json_encode($prods));
        }

        // Comisión general del lanzamiento (%). Es la que se muestra en la página
        // pública de captación y la que se usa como fallback cuando un afiliado no
        // tiene una comisión propia asignada. Vacío = no fijada.
        if (isset($_POST['launch_commission'])) {
            $comm_raw = trim((string) wp_unslash($_POST['launch_commission']));
            if ($comm_raw === '') {
                delete_option('endtrack_launch_commission_' . $safe_l);
            } else {
                $comm = floatval(str_replace(',', '.', $comm_raw));
                if ($comm < 0) {
                    $comm = 0;
                }
                if ($comm > 100) {
                    $comm = 100;
                }
                update_option('endtrack_launch_commission_' . $safe_l, $comm);
            }
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=endtrack'));
        exit;
    }

    /**
     * Guarda el píxel de Meta/Google Ads que un AFILIADO configura para un lanzamiento
     * desde su panel. Se almacena en la opción endtrack_affiliate_pixels_{safe_launch}
     * como array( user_id => ['meta'=>, 'gads_id'=>, 'gads_label'=>] ). La inyección en
     * frontend la hace ENDTrack_Public::tracking_code() eligiendo client-side por ?ref=.
     */
    public function handle_save_affiliate_pixels()
    {
        if (!is_user_logged_in()) {
            wp_die('Debes iniciar sesión.');
        }
        check_admin_referer('endtrack_save_affiliate_pixels_action', 'endtrack_px_nonce');

        // Por defecto, el afiliado edita SUS propios píxeles. Un admin (impersonando desde
        // el panel) puede guardar en nombre de otro afiliado vía target_user.
        $user_id = get_current_user_id();
        if (isset($_POST['target_user']) && current_user_can('manage_options')) {
            $tu = intval($_POST['target_user']);
            if ($tu > 0) {
                $user_id = $tu;
            }
        }

        $launch = isset($_POST['launch']) ? sanitize_text_field(wp_unslash($_POST['launch'])) : '';
        if ($launch === '') {
            wp_safe_redirect(wp_get_referer() ?: home_url('/endtrack-panel-afiliado/'));
            exit;
        }
        $safe_l = ($launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $launch);

        // Meta Pixel ID: solo dígitos.
        $meta = isset($_POST['meta_pixel']) ? preg_replace('/[^0-9]/', '', (string) wp_unslash($_POST['meta_pixel'])) : '';
        // Google Ads (AW-...) o GA4 (G-...): alfanumérico + guiones.
        $gads_id = isset($_POST['gads_id']) ? strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) wp_unslash($_POST['gads_id']))) : '';
        // Etiqueta de conversión: alfanumérico, guion y guion bajo.
        $gads_label = isset($_POST['gads_label']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) wp_unslash($_POST['gads_label'])) : '';

        $opt_key = 'endtrack_affiliate_pixels_' . $safe_l;
        $all = get_option($opt_key, array());
        if (!is_array($all)) {
            $all = array();
        }
        if ($meta === '' && $gads_id === '') {
            unset($all[$user_id]);
        } else {
            $all[$user_id] = array('meta' => $meta, 'gads_id' => $gads_id, 'gads_label' => $gads_label);
        }
        update_option($opt_key, $all, false);

        // El píxel se inyecta server-side en el HTML, que WP Rocket cachea. Sin purgar,
        // el afiliado guardaría pero las páginas cacheadas seguirían sin su píxel. Purgamos
        // para que el cambio surta efecto de inmediato (autoservicio fiable).
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        $redirect = wp_get_referer() ?: home_url('/endtrack-panel-afiliado/');
        $redirect = add_query_arg(array('tab' => 'pixeles', 'launch' => $launch, 'px_saved' => '1'), $redirect);
        wp_safe_redirect($redirect);
        exit;
    }



    public function ajax_save_commission()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $rate = isset($_POST['rate']) ? floatval($_POST['rate']) : 20.0;
        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';

        if ($user_id > 0) {
            $meta_key = 'endtrack_commission_rate';
            if (!empty($launch) && $launch !== 'legacy') {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
                $meta_key = 'endtrack_commission_rate_' . $safe;
            }
            update_user_meta($user_id, $meta_key, $rate);
            wp_send_json_success('Comisión guardada.');
        }
        else {
            wp_send_json_error('ID de usuario inválido.');
        }
    }

    public function ajax_save_upfront()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';

        if ($user_id > 0) {
            $meta_key = 'endtrack_upfront_payment';
            if (!empty($launch) && $launch !== 'legacy') {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
                $meta_key = 'endtrack_upfront_payment_' . $safe;
            }
            update_user_meta($user_id, $meta_key, $amount);
            wp_send_json_success('Upfront guardado.');
        }
        else {
            wp_send_json_error('ID de usuario inválido.');
        }
    }

    public function ajax_toggle_affiliate_link()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;

        if ($post_id > 0) {
            update_post_meta($post_id, '_endtrack_is_affiliate_link', $active);
            wp_send_json_success('Estado de enlace actualizado.');
        }
        else {
            wp_send_json_error('ID de página inválido.');
        }
    }

    public function ajax_add_payment()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
        }

        global $wpdb;
        $afiliado_id = isset($_POST['afiliado_id']) ? intval($_POST['afiliado_id']) : 0;
        $monto = isset($_POST['monto']) ? floatval($_POST['monto']) : 0;
        $referencia = isset($_POST['referencia']) ? sanitize_text_field($_POST['referencia']) : '';
        $notas = isset($_POST['notas']) ? sanitize_textarea_field($_POST['notas']) : '';
        $lanzamiento = isset($_POST['lanzamiento']) ? sanitize_text_field($_POST['lanzamiento']) : 'legacy';

        if ($afiliado_id <= 0 || $monto <= 0) {
            wp_send_json_error('Datos inválidos.');
        }

        $table_payments = $wpdb->prefix . 'endtrack_payments';
        $result = $wpdb->insert($table_payments, array(
            'fecha' => current_time('mysql'),
            'afiliado_id' => $afiliado_id,
            'monto' => $monto,
            'referencia' => $referencia,
            'notas' => $notas,
            'lanzamiento' => $lanzamiento
        ));

        if ($result) {
            wp_send_json_success('Pago registrado correctamente.');
        }
        else {
            wp_send_json_error('Error al guardar en la base de datos.');
        }
    }

    public function ajax_delete_payment()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
        }

        global $wpdb;
        $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;

        if ($payment_id <= 0) {
            wp_send_json_error('ID de pago inválido.');
        }

        $table_payments = $wpdb->prefix . 'endtrack_payments';
        $result = $wpdb->delete($table_payments, array('id' => $payment_id));

        if ($result) {
            wp_send_json_success('Pago eliminado.');
        }
        else {
            wp_send_json_error('Error al eliminar el pago.');
        }
    }

    public function ajax_delete_affiliate()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
        }

        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'endtrack_delete_user_nonce')) {
            wp_send_json_error('Nonce inválido.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if ($user_id <= 0) {
            wp_send_json_error('ID de usuario inválido.');
        }

        // We don't delete the WordPress user, we just remove the affiliate metadata
        // or potentially the role. The request says "delete affiliate", usually means stop tracking them as one.
        // For safety, let's just remove the flag that makes them an affiliate.
        delete_user_meta($user_id, 'wp_capabilities'); // Careful here, might want to just remove 'afiliado' substring

        // Let's actually check how they are identified in admin-panel-content.php
        // $afiliados = $wpdb->get_results("... WHERE M.meta_value LIKE '%afiliado%'");
        // It's the role.

        $user = get_userdata($user_id);
        if ($user) {
            $user->remove_role('afiliado');
            wp_send_json_success('Afiliado eliminado (rol removido).');
        }
        else {
            wp_send_json_error('Usuario no encontrado.');
        }
    }

    public function add_thrivecart_columns()
    {
        global $wpdb;
        $table_datos = $wpdb->prefix . 'datos';

        $columns_to_add = array(
            'total' => 'VARCHAR(255) DEFAULT NULL',
            'producto' => 'TEXT DEFAULT NULL',
            'account_id' => 'VARCHAR(100) DEFAULT NULL'
        );

        foreach ($columns_to_add as $column => $definition) {
            $exists = $wpdb->get_results("SHOW COLUMNS FROM $table_datos LIKE '$column'");
            if (empty($exists)) {
                $wpdb->query("ALTER TABLE $table_datos ADD COLUMN $column $definition");
            }
        }
    }

    public function migrate_launches()
    {
        $mapping = get_option('endtrack_launches_mapping');
        if ($mapping !== false)
            return;

        global $wpdb;
        $launches = get_option('endtrack_launches', array());
        $new_mapping = array();
        $table_datos = $wpdb->prefix . 'datos';
        $table_visitas = $wpdb->prefix . 'visitas';

        foreach ($launches as $launch_name) {
            $cat_id = get_cat_ID($launch_name);
            $cat_slug = '';
            if ($cat_id) {
                $category = get_category($cat_id);
                $cat_slug = ($category) ? $category->slug : $launch_name;
            }
            else {
                $cat_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $launch_name));
            }
            $new_mapping[$launch_name] = $cat_slug;

            // Also fix columns for this launch
            $tables = array($table_datos, $table_visitas);
            foreach ($tables as $table) {
                $col_tipo = "tipo_cat_" . $launch_name;
                $col_reg = "primer_reg_" . $launch_name;

                $has_tipo = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col_tipo'");
                if (!empty($has_tipo)) {
                    $wpdb->query("ALTER TABLE $table MODIFY COLUMN $col_tipo TINYINT(1) DEFAULT 0");
                }
                else {
                    $wpdb->query("ALTER TABLE $table ADD COLUMN $col_tipo TINYINT(1) DEFAULT 0");
                }

                $has_reg = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$col_reg'");
                if (empty($has_reg)) {
                    $wpdb->query("ALTER TABLE $table ADD COLUMN $col_reg TINYINT(1) DEFAULT 0");
                }
            }
        }
        update_option('endtrack_launches_mapping', $new_mapping);
    }

    public function ensure_global_categories()
    {
        $categories = array(
            'registro' => 'Registro',
            'venta' => 'Venta',
            'gracias' => 'Gracias',
            'gracias_registro' => 'Gracias Registro'
        );

        foreach ($categories as $slug => $name) {
            if (!get_term_by('slug', $slug, 'category')) {
                wp_create_category($name);
            }
        }
    }

    private function update_elementor_form_button_id($data_json, $button_id)
    {
        $data = json_decode($data_json, true);
        if (!$data)
            return $data_json;

        $this->recursive_update_button_id($data, $button_id);

        return wp_slash(json_encode($data));
    }

    private function recursive_update_button_id(&$items, $button_id)
    {
        foreach ($items as &$item) {
            if (isset($item['widgetType']) && $item['widgetType'] === 'form') {
                if (!isset($item['settings'])) {
                    $item['settings'] = array();
                }
                $item['settings']['button_css_id'] = $button_id;
            }
            if (isset($item['elements']) && is_array($item['elements'])) {
                $this->recursive_update_button_id($item['elements'], $button_id);
            }
        }
    }

    private function extract_url_context($url)
    {
        $response = wp_remote_get($url, array('timeout' => 10));
        if (is_wp_error($response))
            return '';

        $html = wp_remote_retrieve_body($response);
        if (empty($html))
            return '';

        // Strip scripts and styles
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);

        // Extract headings
        preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/si', $html, $m);
        $headings = array_slice(array_map('strip_tags', $m[1] ?? array()), 0, 10);
        $headings = array_filter(array_map('trim', $headings));

        // Count sections
        $sections = substr_count(strtolower($html), '<section')
            + substr_count(strtolower($html), 'elementor-top-section');

        $ctx = '';
        if (!empty($headings)) {
            $ctx .= "Títulos encontrados en la página de referencia:\n- " . implode("\n- ", $headings) . "\n";
        }
        if ($sections > 0) {
            $ctx .= "Número aproximado de secciones/bloques: {$sections}\n";
        }

        return $ctx;
    }
}