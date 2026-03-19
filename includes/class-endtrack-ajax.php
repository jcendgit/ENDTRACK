<?php

class ENDTrack_Ajax
{

    public function init()
    {
        add_action('wp_ajax_endtrack_datos', array($this, 'save_data'));
        add_action('wp_ajax_nopriv_endtrack_datos', array($this, 'save_data'));

        add_action('wp_ajax_endtrack_load_launch_stats', array($this, 'load_launch_stats'));
        add_action('wp_ajax_endtrack_delete_affiliate', array($this, 'delete_affiliate'));
        add_action('wp_ajax_endtrack_toggle_visibility', array($this, 'toggle_launch_visibility'));
        add_action('wp_ajax_endtrack_regenerate_copy', array($this, 'regenerate_copy'));
        add_action('wp_ajax_endtrack_regenerate_launch_copy', array($this, 'regenerate_launch_copy'));
        add_action('wp_ajax_endtrack_frontend_ai_edit', array($this, 'frontend_ai_edit'));
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

            // 2. IP & GeoIP LookUp
            $country_code = '';
            $city = '';
            $details = @json_decode(wp_remote_retrieve_body(wp_remote_get("https://ip.guide/{$ip}", array('timeout' => 2))));
            if ($details) {
                $country_code = $details->location->country ?? '';
                $city = $details->location->city ?? '';
            }

            // Debug Log
            error_log("ENDTrack AJAX Trace: correo=$correo, launch=$launch_context, affiliado=$afiliado_id, ip=$ip, session=$session_id, geo=$country_code/$city");

            // Duplicate check (Global)
            $existing_lead_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id from $tabla_datos WHERE correo = %s OR cookie = %s OR ip = %s LIMIT 1", $correo, $cookie, $ip)
            );

            $primer_registro = ($existing_lead_id > 0) ? 0 : 1;
            if (isset($_POST["venta"]) && $_POST["venta"] == 2) {
                $primer_registro = 2;
            }

            $correo_primer_reg = $correo;
            if ($existing_lead_id) {
                $correo_primer_reg = $wpdb->get_var($wpdb->prepare("SELECT correo from $tabla_datos WHERE id = %d", $existing_lead_id));
            }

            $fecha_val = !empty($_POST["fecha"]) ? sanitize_text_field($_POST["fecha"]) : current_time('mysql');
            if (strlen($fecha_val) === 10) {
                $fecha_val .= ' ' . current_time('H:i:s');
            }

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

                // Check if user has already registered for THIS launch using ALL identifiers
                $has_reg_launch = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $tabla_datos WHERE (correo = %s OR cookie = %s OR ip = %s OR session_id = %s) AND ($col_primer_reg = 1 OR $col_primer_reg = 2) LIMIT 1",
                    $correo,
                    $cookie,
                    $ip,
                    $session_id
                ));

                $val_tipo_cat = isset($_POST["tipo_cat"]) ? intval($_POST["tipo_cat"]) : 1;
                if ($val_tipo_cat == 0)
                    $val_tipo_cat = 1;

                $data[$col_tipo_cat] = $val_tipo_cat;
                $data[$col_primer_reg] = ($has_reg_launch) ? 0 : ((isset($_POST["venta"]) && $_POST["venta"] == 2) ? 2 : 1);
            }

            $wpdb->insert($tabla_datos, $data);
        }

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
        }
        else {
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
                }
                else {
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
            }
            elseif (in_array('registro', $categories) || in_array('registroPB', $categories)) {
                $page_type = 'registro';
            }
            elseif (in_array('gracias-registro', $categories)) {
                $page_type = 'gracias_registro';
            }
            elseif (in_array('gracias', $categories)) {
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
}