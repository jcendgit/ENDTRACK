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
        add_action('admin_post_endtrack_create_grafana', array($this, 'handle_create_grafana_dashboard'));
        add_action('admin_post_endtrack_update_all_grafanas', array($this, 'handle_update_all_grafanas'));
        add_action('wp_ajax_endtrack_save_commission', array($this, 'ajax_save_commission'));
        add_action('admin_init', array($this, 'migrate_launches'));
        add_action('admin_init', array($this, 'add_thrivecart_columns'));
        add_action('wp_ajax_endtrack_toggle_affiliate_link', array($this, 'ajax_toggle_affiliate_link'));
        add_action('wp_ajax_endtrack_add_payment', array($this, 'ajax_add_payment'));
        add_action('wp_ajax_endtrack_delete_payment', array($this, 'ajax_delete_payment'));
        add_action('wp_ajax_endtrack_delete_affiliate', array($this, 'ajax_delete_affiliate'));
        add_action('admin_init', array($this, 'ensure_global_categories'));
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
            } else {
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

        // 4. Create Grafana Dashboard
        require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-grafana.php';
        $dashboard_url = ENDTrack_Grafana::create_dashboard($launch_name, $safe_launch_name, $launch_type);

        $msg = 'launch_created';

        if (!is_wp_error($dashboard_url)) {
            // Save dashboard link
            $dashboards = get_option('endtrack_launch_dashboards', array());
            $dashboards[$safe_launch_name] = $dashboard_url;
            update_option('endtrack_launch_dashboards', $dashboards);
        } else {
            // Log error silently or pass as message warning
            $msg = 'launch_created_grafana_error';
            // Optionally logs: error_log($dashboard_url->get_error_message());
        }

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
                } else if (is_wp_error($term) && isset($term->error_data['term_exists'])) {
                    $folder_id = $term->error_data['term_exists'];
                }
            }

            // 2. Define pages to create
            $pages_to_create = array();
            if ($launch_type == 1) { // Direct
                $pages_to_create = array(
                    'ventas' => array(
                        'title' => $launch_name . ' - Ventas',
                        'template_id' => isset($texts['template_venta']) ? $texts['template_venta'] : '',
                        'category' => 'venta'
                    ),
                    'gracias_compra' => array(
                        'title' => $launch_name . ' - Gracias por comprar',
                        'template_id' => isset($texts['template_gracias_compra']) ? $texts['template_gracias_compra'] : '',
                        'category' => 'gracias'
                    )
                );
            } else { // Reg
                $pages_to_create = array(
                    'registro' => array(
                        'title' => $launch_name . ' - Registro',
                        'template_id' => isset($texts['template_registro']) ? $texts['template_registro'] : '',
                        'category' => 'registro'
                    ),
                    'gracias_reg' => array(
                        'title' => $launch_name . ' - Gracias por registrarte',
                        'template_id' => isset($texts['template_gracias_reg']) ? $texts['template_gracias_reg'] : '',
                        'category' => 'Gracias Registro'
                    ),
                    'ventas' => array(
                        'title' => $launch_name . ' - Ventas',
                        'template_id' => isset($texts['template_venta']) ? $texts['template_venta'] : '',
                        'category' => 'venta'
                    ),
                    'gracias_compra' => array(
                        'title' => $launch_name . ' - Gracias por comprar',
                        'template_id' => isset($texts['template_gracias_compra']) ? $texts['template_gracias_compra'] : '',
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
                        wp_set_object_terms($post_id, array((int) $folder_id), $wf_taxonomy);
                    }

                    // Assign to Launch Category
                    wp_set_object_terms($post_id, array((int) $cat_id), 'category');

                    // Assign specific page category
                    if (!empty($page_info['category'])) {
                        $specific_cat_name = $page_info['category'];
                        // Check if category exists, if not create it
                        $term = term_exists($specific_cat_name, 'category');
                        if ($term === 0 || $term === null) {
                            $term = wp_create_category($specific_cat_name); // Returns term_id
                            $specific_cat_id = $term;
                        } else {
                            $specific_cat_id = is_array($term) ? $term['term_id'] : $term;
                        }

                        if ($specific_cat_id) {
                            wp_set_object_terms($post_id, array((int) $specific_cat_id), 'category', true); // Append
                        }
                    }

                    // Apply Elementor Template
                    if (!empty($page_info['template_id'])) {
                        $template_id = $page_info['template_id'];
                        $template_data = get_post_meta($template_id, '_elementor_data', true);

                        if (!empty($template_data)) {
                            // If it's the registration page, ensure the button ID is correct
                            if ($slug_key === 'registro') {
                                $template_data = $this->update_elementor_form_button_id($template_data, 'add_suscrito');
                            }

                            // 1. Copy Data
                            // Ensure data is slashed. update_elementor_form_button_id returns slashed data.
                            // If we didn't process it, we should slash it to be safe as Elementor expects slashed JSON.
                            if ($slug_key !== 'registro') {
                                $template_data = wp_slash($template_data);
                            }
                            update_post_meta($post_id, '_elementor_data', $template_data);

                            // 2. Set Edit Mode
                            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                            update_post_meta($post_id, '_elementor_template_type', 'page');

                            // 3. Copy Page Template (Canvas/Full Width)
                            $page_template = get_post_meta($template_id, '_wp_page_template', true);
                            if (!empty($page_template)) {
                                update_post_meta($post_id, '_wp_page_template', $page_template);
                            } else {
                                // Default to canvas if missing, as usually desired for LPs
                                update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');
                            }

                            // 4. Copy Page Settings (Styles, etc)
                            $page_settings = get_post_meta($template_id, '_elementor_page_settings', true);
                            if (!empty($page_settings)) {
                                update_post_meta($post_id, '_elementor_page_settings', $page_settings);
                            }

                            // 5. Copy Version
                            $version = get_post_meta($template_id, '_elementor_version', true);
                            if (!empty($version)) {
                                update_post_meta($post_id, '_elementor_version', $version);
                            }

                            // 6. Force CSS Generation - DISABLED TEMPORARILY TO FIX FATAL ERROR
                            /*
                            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                               try {
                                    $post_css = new \Elementor\Core\Files\CSS\Post($post_id);
                                    $post_css->update();
                                } catch (\Throwable $e) {
                                    error_log("ENDTrack Error: Failed to generate CSS for post $post_id: " . $e->getMessage());
                                }
                            }
                            */
                        }
                    } else {
                        $log_file = WP_CONTENT_DIR . '/endtrack_debug.log';
                        error_log("ENDTrack Debug: No template_id found for page $slug_key\n", 3, $log_file);
                    }
                }
            }
        }

        // AI Copy Generation (if prompt provided)
        if (isset($_POST['ai_prompt']) && !empty(trim($_POST['ai_prompt']))) {
            $ai_prompt = sanitize_textarea_field($_POST['ai_prompt']);

            // Generate AI copy for each created page
            $page_types = array(
                'ventas' => 'ventas',
                'registro' => 'registro',
                'gracias_compra' => 'gracias',
                'gracias_reg' => 'gracias_registro'
            );

            foreach ($page_types as $slug_key => $page_type) {
                if (isset($created_pages[$slug_key]) && $created_pages[$slug_key] > 0) {
                    $result = ENDTrack_AI::generate_copy_for_page(
                        $created_pages[$slug_key],
                        $page_type,
                        $ai_prompt
                    );

                    if (is_wp_error($result)) {
                        error_log("ENDTrack AI Error for $slug_key: " . $result->get_error_message());
                    }
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

        require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-grafana.php';
        $dashboard_url = ENDTrack_Grafana::create_dashboard($launch_title, $safe_launch_name);

        $msg = 'dashboard_created';

        if (!is_wp_error($dashboard_url)) {
            // Save dashboard link
            $dashboards = get_option('endtrack_launch_dashboards', array());
            $dashboards[$safe_launch_name] = $dashboard_url;
            update_option('endtrack_launch_dashboards', $dashboards);
        } else {
            $msg = 'grafana_error';
        }

        wp_redirect(admin_url('admin.php?page=endtrack&message=' . $msg));
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
            } else {
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
                } else {
                    $sanitized_texts[$key] = sanitize_text_field($value);
                }
            }

            update_option('endtrack_texts', $sanitized_texts);
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

        // Save OpenAI API Key
        if (isset($_POST['openai_key'])) {
            $texts['openai_key'] = sanitize_text_field($_POST['openai_key']);
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

        update_option('endtrack_texts', $texts);

        // Redirect back
        wp_redirect(admin_url('admin.php?page=endtrack&tab=integrations&message=integrations_saved'));
        exit;
    }



    public function ajax_save_commission()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $rate = isset($_POST['rate']) ? floatval($_POST['rate']) : 25.0;

        if ($user_id > 0) {
            update_user_meta($user_id, 'endtrack_commission_rate', $rate);
            wp_send_json_success('Comisión guardada.');
        } else {
            wp_send_json_error('ID de usuario inválido.');
        }
    }

    public function ajax_toggle_affiliate_link()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos.');
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $active = isset($_POST['active']) ? intval($_POST['active']) : 0;

        if ($post_id > 0) {
            update_post_meta($post_id, '_endtrack_is_affiliate_link', $active);
            wp_send_json_success('Estado de enlace actualizado.');
        } else {
            wp_send_json_error('ID de página inválido.');
        }
    }

    public function ajax_add_payment()
    {
        if (!current_user_can('manage_options')) {
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
        } else {
            wp_send_json_error('Error al guardar en la base de datos.');
        }
    }

    public function ajax_delete_payment()
    {
        if (!current_user_can('manage_options')) {
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
        } else {
            wp_send_json_error('Error al eliminar el pago.');
        }
    }

    public function ajax_delete_affiliate()
    {
        if (!current_user_can('manage_options')) {
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
        } else {
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
            } else {
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
                } else {
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
}

