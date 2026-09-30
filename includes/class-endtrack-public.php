<?php

class ENDTrack_Public
{

    public function init()
    {
        add_action('wp_head', array($this, 'tracking_code'));
        add_action('wp_head', array($this, 'inject_ai_css'), 999);
        add_action('wp_footer', array($this, 'inject_floating_ai_button'), 999);
        add_action('wp_footer', array($this, 'propagate_utms_to_precios_buttons'), 20);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Shortcodes (Keep them just in case, but template override is primary for these pages now)
        add_shortcode('endtrack_admin_panel', array($this, 'render_admin_panel'));
        add_shortcode('endtrack_affiliate_panel', array($this, 'render_affiliate_panel'));

        // Template Override
        add_filter('template_include', array($this, 'override_template'));

        // Hide Admin Bar on specific pages
        add_filter('show_admin_bar', array($this, 'maybe_hide_admin_bar'));

        // Custom Dashboard Rewrites
        add_action('init', array($this, 'register_rewrites'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'handle_dashboard_redirect'));
        add_action('wp_login', array($this, 'track_last_login'), 10, 2);

        // Flush de rewrites versionado (v3: añade la ruta /ventas-completas/).
        // IMPORTANTE: en init con prioridad 999, DESPUÉS de register_rewrites (init 10);
        // si se llama al cargar el plugin las reglas propias aún no existen y el
        // flush las deja fuera (la URL da 404).
        add_action('init', function () {
            if (get_option('endtrack_flush_rewrites') !== 'v3-ventas-completas') {
                flush_rewrite_rules();
                update_option('endtrack_flush_rewrites', 'v3-ventas-completas');
            }
        }, 999);

        // Redirect affiliates and stats viewers from wp-admin
        add_action('admin_init', array($this, 'redirect_restricted_users_from_admin'));
        add_filter('login_redirect', array($this, 'stats_viewer_login_redirect'), 10, 3);

        // Register custom roles if they don't exist
        $this->register_custom_roles();

        // Migración de BD: ampliar VARCHAR(100) → VARCHAR(500) en columnas UTM
        // Necesario para UTMs largas de Facebook/Google Ads que antes se perdían.
        $this->maybe_migrate_utm_columns();
        $this->maybe_migrate_visitas_utm_columns();
        $this->maybe_create_indexes();
        $this->maybe_create_indexes_v2();
        $this->maybe_create_pdc_tables();
    }

    /**
     * Tablas de apoyo de /ventas-completas/: notas por comprador, estado
     * (color / marca test-error / email de atribución manual) y log de cambios.
     */
    private function maybe_create_pdc_tables()
    {
        if (get_option('endtrack_pdc_tables_v2') === 'done') {
            return;
        }
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $t_notas = $wpdb->prefix . 'endtrack_pdc_notas';
        $sql = "CREATE TABLE $t_notas (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            launch VARCHAR(100) NOT NULL DEFAULT '',
            user_email VARCHAR(255) NOT NULL DEFAULT '',
            nota TEXT NOT NULL,
            autor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_launch_email (launch, user_email)
        ) $charset_collate;";
        dbDelta($sql);

        $t_estado = $wpdb->prefix . 'endtrack_pdc_estado';
        // color: VARCHAR(100) porque una fila puede llevar varios colores separados por coma ('rojo,verde').
        $sql = "CREATE TABLE $t_estado (
            launch VARCHAR(100) NOT NULL DEFAULT '',
            user_email VARCHAR(255) NOT NULL DEFAULT '',
            color VARCHAR(100) NOT NULL DEFAULT '',
            atribucion_email VARCHAR(255) NOT NULL DEFAULT '',
            marca VARCHAR(20) NOT NULL DEFAULT '',
            autor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            fecha_actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (launch, user_email)
        ) $charset_collate;";
        dbDelta($sql);

        $t_log = $wpdb->prefix . 'endtrack_pdc_log';
        $sql = "CREATE TABLE $t_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            launch VARCHAR(100) NOT NULL DEFAULT '',
            user_email VARCHAR(255) NOT NULL DEFAULT '',
            accion VARCHAR(50) NOT NULL DEFAULT '',
            valor TEXT NOT NULL,
            autor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_launch_email (launch, user_email),
            KEY idx_fecha (fecha)
        ) $charset_collate;";
        dbDelta($sql);

        update_option('endtrack_pdc_tables_v2', 'done');
        error_log('ENDTrack: PDC support tables created (notas, estado, log).');
    }

    private function maybe_migrate_utm_columns()
    {
        if (get_option('endtrack_utm_columns_migrated_v2') === 'done') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'datos';
        $cols = array('term', 'content', 'placement', 'medium', 'tipo', 'source', 'campaign', 'cookie', 'afiliado', 'nombre', 'correo_primer_reg', 'session_id');
        foreach ($cols as $col) {
            $wpdb->query("ALTER TABLE `$table` MODIFY COLUMN `$col` VARCHAR(500) DEFAULT ''");
        }
        update_option('endtrack_utm_columns_migrated_v2', 'done');
        error_log('ENDTrack: Migración de columnas UTM completada (VARCHAR 100 → 500).');
    }

    private function maybe_create_indexes()
    {
        if (get_option('endtrack_indexes_created_v1') === 'done') {
            return;
        }
        global $wpdb;

        $datos = $wpdb->prefix . 'datos';
        $visitas = $wpdb->prefix . 'visitas';
        $db = DB_NAME;

        // Helper: check if index already exists
        $index_exists = function ($table, $index_name) use ($wpdb, $db) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = %s AND table_name = %s AND index_name = %s",
                $db,
                $table,
                $index_name
            )) > 0;
        };

        // --- wp_datos indexes ---
        $datos_indexes = array(
            // Duplicate & debounce checks: WHERE correo = ?
            'idx_et_correo' => "ALTER TABLE `$datos` ADD INDEX idx_et_correo (correo(50))",
            // ORDER BY fecha DESC (email list, debounce)
            'idx_et_fecha' => "ALTER TABLE `$datos` ADD INDEX idx_et_fecha (fecha)",
            // Cookie-based duplicate: WHERE cookie = ? AND ip = ?
            'idx_et_cookie_ip' => "ALTER TABLE `$datos` ADD INDEX idx_et_cookie_ip (cookie(50), ip(20))",
            // Adspend queries: WHERE source = ? AND primer_reg = ? AND fecha BETWEEN ?
            'idx_et_src_reg_fec' => "ALTER TABLE `$datos` ADD INDEX idx_et_src_reg_fec (source(20), primer_reg, fecha)",
            // Affiliate stats: WHERE afiliado = ?
            'idx_et_afiliado' => "ALTER TABLE `$datos` ADD INDEX idx_et_afiliado (afiliado(50))",
        );

        foreach ($datos_indexes as $name => $sql) {
            if (!$index_exists($datos, $name)) {
                $wpdb->query($sql);
            }
        }

        // --- wp_visitas indexes ---
        $visitas_indexes = array(
            // Date-range queries and ORDER BY
            'idx_ev_fecha' => "ALTER TABLE `$visitas` ADD INDEX idx_ev_fecha (fecha)",
            // Session lookups
            'idx_ev_session' => "ALTER TABLE `$visitas` ADD INDEX idx_ev_session (session_id(50))",
        );

        foreach ($visitas_indexes as $name => $sql) {
            if (!$index_exists($visitas, $name)) {
                $wpdb->query($sql);
            }
        }

        update_option('endtrack_indexes_created_v1', 'done');
        error_log('ENDTrack: Índices de base de datos creados correctamente.');
    }

    private function maybe_create_indexes_v2()
    {
        if (get_option('endtrack_indexes_created_v2') === 'done') {
            return;
        }
        global $wpdb;

        $datos = $wpdb->prefix . 'datos';
        $visitas = $wpdb->prefix . 'visitas';
        $db = DB_NAME;

        $index_exists = function ($table, $index_name) use ($wpdb, $db) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = %s AND table_name = %s AND index_name = %s",
                $db,
                $table,
                $index_name
            )) > 0;
        };

        // --- wp_visitas: índices faltantes ---
        // id_pag: GROUP BY, JOIN con wp_posts, subconsultas correlacionadas
        // ref: WHERE ref = ? en panel de afiliados
        // ref_s: GROUP BY y filtros de fuente
        // ip: COUNT(DISTINCT ip) en todas las tablas de conversión
        $visitas_indexes = array(
            'idx_ev_id_pag' => "ALTER TABLE `$visitas` ADD INDEX idx_ev_id_pag (id_pag)",
            'idx_ev_ref' => "ALTER TABLE `$visitas` ADD INDEX idx_ev_ref (ref(50))",
            'idx_ev_ref_s' => "ALTER TABLE `$visitas` ADD INDEX idx_ev_ref_s (ref_s(20))",
            'idx_ev_ip' => "ALTER TABLE `$visitas` ADD INDEX idx_ev_ip (ip(20))",
        );

        foreach ($visitas_indexes as $name => $sql) {
            if (!$index_exists($visitas, $name)) {
                $wpdb->query($sql);
            }
        }

        // --- wp_datos: índices faltantes ---
        // id_pag: subconsulta correlacionada WHERE d.id_pag = v.id_pag
        // medium, campaign, placement: filtros del dashboard de estadísticas
        $datos_indexes = array(
            'idx_et_id_pag' => "ALTER TABLE `$datos` ADD INDEX idx_et_id_pag (id_pag)",
            'idx_et_medium' => "ALTER TABLE `$datos` ADD INDEX idx_et_medium (medium(20))",
            'idx_et_campaign' => "ALTER TABLE `$datos` ADD INDEX idx_et_campaign (campaign(50))",
            'idx_et_placement' => "ALTER TABLE `$datos` ADD INDEX idx_et_placement (placement(20))",
        );

        foreach ($datos_indexes as $name => $sql) {
            if (!$index_exists($datos, $name)) {
                $wpdb->query($sql);
            }
        }

        update_option('endtrack_indexes_created_v2', 'done');
        error_log('ENDTrack: Índices v2 creados (visitas: id_pag, ref, ref_s, ip; datos: id_pag, medium, campaign, placement).');
    }

    private function maybe_migrate_visitas_utm_columns()
    {
        if (get_option('endtrack_visitas_utm_migrated_v1') === 'done') {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'visitas';
        $wpdb->query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `ref_t` VARCHAR(500) DEFAULT NULL");
        $wpdb->query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `ref_co` VARCHAR(500) DEFAULT NULL");
        update_option('endtrack_visitas_utm_migrated_v1', 'done');
        error_log('ENDTrack: Migración wp_visitas — columnas ref_t y ref_co añadidas.');
    }

    private function register_custom_roles()
    {
        if (!get_role('stats_viewer')) {
            add_role('stats_viewer', 'Visualizador de Estadísticas', array(
                'read' => true,
                'view_endtrack_stats' => true
            ));
        }
        if (!get_role('gestor_afiliados')) {
            add_role('gestor_afiliados', 'Gestor de Afiliados', array(
                'read' => true,
                'manage_endtrack_affiliates' => true,
                'view_endtrack_stats' => true
            ));
        }
        if (!get_role('estadisticas')) {
            add_role('estadisticas', 'Estadísticas', array(
                'read' => true,
                'view_endtrack_stats' => true
            ));
        }
    }

    public function redirect_restricted_users_from_admin()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        $user = wp_get_current_user();
        $roles = (array) $user->roles;

        if (in_array('gestor_afiliados', $roles)) {
            wp_redirect(home_url('/endtrack-panel-admin-afiliado/'));
            exit;
        }

        if (in_array('stats_viewer', $roles) || in_array('estadisticas', $roles)) {
            wp_redirect(home_url('/endtrack-estadisticas/'));
            exit;
        }

        if (in_array('afiliado', $roles)) {
            wp_redirect(home_url('/endtrack-panel-afiliado/'));
            exit;
        }
    }

    public function stats_viewer_login_redirect($redirect_to, $request, $user)
    {
        if (isset($user->roles) && is_array($user->roles)) {
            if (in_array('gestor_afiliados', $user->roles)) {
                return home_url('/endtrack-panel-admin-afiliado/');
            }
            if (in_array('stats_viewer', $user->roles) || in_array('estadisticas', $user->roles)) {
                return home_url('/endtrack-estadisticas/');
            }
        }
        return $redirect_to;
    }

    public function track_last_login($user_login, $user)
    {
        update_user_meta($user->ID, 'endtrack_last_login', current_time('mysql'));
    }

    public function register_rewrites()
    {
        add_rewrite_rule('^endtrack/?$', 'index.php?endtrack_launch_dashboard=admin-main', 'top');
        add_rewrite_rule('^endtrack-estadisticas/?$', 'index.php?endtrack_launch_dashboard=admin-stats', 'top');
        add_rewrite_rule('^endtrack-estadisticas-ventas/?$', 'index.php?endtrack_launch_dashboard=admin-stats-ventas', 'top');
        add_rewrite_rule('^ventas-completas/?$', 'index.php?endtrack_launch_dashboard=admin-stats-ventas-completas', 'top');
        add_rewrite_rule('^([a-zA-Z0-9_-]+)-endtrack/?$', 'index.php?endtrack_launch_dashboard=$matches[1]', 'top');
    }

    public function register_query_vars($vars)
    {
        $vars[] = 'endtrack_launch_dashboard';
        return $vars;
    }

    public function override_template($template)
    {
        if (is_page('endtrack-panel-admin-afiliado')) {
            if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
                wp_redirect(home_url());
                exit;
            }
            $new_template = ENDTRACK_PLUGIN_DIR . 'templates/dashboard-layout.php';
            if (file_exists($new_template)) {
                return $new_template;
            }
        }

        if (is_page('endtrack-panel-afiliado')) {
            $user = wp_get_current_user();
            $is_affiliate = in_array('afiliado', (array) $user->roles);
            $is_admin = current_user_can('manage_options');
            $is_gestor = current_user_can('manage_endtrack_affiliates');

            if (!$is_affiliate && !$is_admin && !$is_gestor) {
                wp_redirect(home_url());
                exit;
            }

            $new_template = ENDTRACK_PLUGIN_DIR . 'templates/dashboard-layout.php';
            if (file_exists($new_template)) {
                return $new_template;
            }
        }

        return $template;
    }

    public function handle_dashboard_redirect()
    {
        $launch_slug = get_query_var('endtrack_launch_dashboard');
        if ($launch_slug) {

            // Disable all caching for endtrack panels
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

            // Special Case: Main Admin Fullscreen
            if ($launch_slug === 'admin-main') {
                if (!current_user_can('manage_options') && !current_user_can('manage_endtrack_affiliates')) {
                    wp_redirect(admin_url('wp-login.php'));
                    exit;
                }
                $admin_template = ENDTRACK_PLUGIN_DIR . 'templates/dashboard-layout.php';
                if (file_exists($admin_template)) {
                    status_header(200);
                    header_remove('X-Frame-Options');
                    $is_admin_panel = true;
                    $is_standalone = true;
                    include $admin_template;
                    exit;
                }
            }

            // Special Case: Admin Stats Fullscreen
            if ($launch_slug === 'admin-stats') {
                if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats') && !current_user_can('manage_endtrack_affiliates')) {
                    wp_redirect(admin_url('wp-login.php?redirect_to=' . urlencode(home_url('/endtrack-estadisticas/'))));
                    exit;
                }
                $stats_template = ENDTRACK_PLUGIN_DIR . 'templates/stats-fullscreen.php';
                if (file_exists($stats_template)) {
                    status_header(200);
                    header_remove('X-Frame-Options');
                    include $stats_template;
                    exit;
                }
            }

            // Special Case: Admin Stats Ventas Fullscreen
            if ($launch_slug === 'admin-stats-ventas') {
                if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats') && !current_user_can('manage_endtrack_affiliates')) {
                    wp_redirect(admin_url('wp-login.php?redirect_to=' . urlencode(home_url('/endtrack-estadisticas-ventas/'))));
                    exit;
                }
                $stats_template = ENDTRACK_PLUGIN_DIR . 'templates/stats-ventas-fullscreen.php';
                if (file_exists($stats_template)) {
                    status_header(200);
                    header_remove('X-Frame-Options');
                    include $stats_template;
                    exit;
                }
            }

            // Special Case: Ventas Completas (resumen + detalle pdc_pagos)
            if ($launch_slug === 'admin-stats-ventas-completas') {
                if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats') && !current_user_can('manage_endtrack_affiliates')) {
                    wp_redirect(admin_url('wp-login.php?redirect_to=' . urlencode(home_url('/ventas-completas/'))));
                    exit;
                }
                $stats_template = ENDTRACK_PLUGIN_DIR . 'templates/stats-ventas-completas.php';
                if (file_exists($stats_template)) {
                    status_header(200);
                    header_remove('X-Frame-Options');
                    include $stats_template;
                    exit;
                }
            }

            $dashboard_template = ENDTRACK_PLUGIN_DIR . 'templates/dashboard-view.php';
            if (file_exists($dashboard_template)) {

                // Manually set 200 status to prevent 404 title/body classes if headers haven't sent
                status_header(200);

                // Allow this page to be framed (fix for X-Frame-Options: deny)
                header_remove('X-Frame-Options');

                // Load the template
                include $dashboard_template;

                // Stop WordPress from loading the rest of the page (theme, etc.)
                exit;
            }
        }
    }

    public function maybe_hide_admin_bar($show)
    {
        if (is_page('endtrack-panel-afiliado') || is_page('endtrack-panel-admin-afiliado') || get_query_var('endtrack_launch_dashboard')) {
            return false;
        }
        // Hide admin bar for restricted roles
        $user = wp_get_current_user();
        if (in_array('gestor_afiliados', (array) $user->roles) || in_array('afiliado', (array) $user->roles) || in_array('stats_viewer', (array) $user->roles) || in_array('estadisticas', (array) $user->roles)) {
            return false;
        }
        return $show;
    }

    private function is_bot($userAgent)
    {
        $bots = array(
            // Buscadores
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            // Redes sociales
            'facebookexternalhit',
            'twitterbot',
            'rogerbot',
            'linkedinbot',
            'embedly',
            'quora link preview',
            'showyoubot',
            'outbrain',
            'pinterest',
            // Google servicios
            'developers.google.com',
            'google-adwords',
            'google-ads',
            'googleads',
            'google chat',
            'hangouts',
            // Publicidad
            'publimillenium',
            'publi miles',
            'adsystem',
            'doubleclick',
            // SEO crawlers
            'ahrefsbot',
            'screaming frog',
            'semrushbot',
            'mj12bot',
            'dotbot',
            'petalbot',
            'sistrix',
            'linkdexbot',
            'exabot',
            'gigabot',
            // Monitoring
            'uptimerobot',
            'pingdom',
            'monitis',
            'hetrixtools',
            'statuscake',
            // Herramientas HTTP
            'wget',
            'curl',
            'go-http-client',
            'okhttp',
            'apache-httpclient',
            'axios',
            'requests',
            'urllib',
            // ===== BOTS DE IA =====
            'gptbot',
            'chatgpt-user',
            'oai-searchbot',
            'ccbot',
            'anthropic-ai',
            'claude-web',
            'claudebot',
            'perplexitybot',
            'perplexity-user',
            'cohere-ai',
            'bytespider',
            'amazonbot',
            'meta-externalagent',
            'meta-externalfetcher',
            'applebot',
            'google-extended',
            'friendlycrawler',
            'timpibot',
            'img2dataset',
            'omgili',
            'diffbot',
            'youbot',
            'iaskspider',
            'ai2bot',
            'ai2bot-dolma',
            'webzio-extended'
        );

        $userAgent = strtolower($userAgent);

        // Verificar lista específica de bots
        foreach ($bots as $bot) {
            if (stripos($userAgent, strtolower($bot)) !== false) {
                return true;
            }
        }

        // Patrones MÁS ESPECÍFICOS y seguros (con word boundaries y anclajes)
        $botPatterns = array(
            '/\bbot\b/i', // "bot" como palabra completa (no matchea "about")
            '/\bspider\b/i', // "spider" como palabra completa
            '/\bcrawler\b/i', // "crawler" como palabra completa
            '/^$/i', // User agent vacío
            '/^curl\//i', // Empieza con "curl/"
            '/^wget\//i', // Empieza con "wget/"
            '/^python-requests/i', // Empieza con "python-requests"
            '/^python-urllib/i', // Empieza con "python-urllib"
            '/^java\//i', // Empieza con "java/" (NO matchea "JavaScript")
            '/^go-http-client/i', // Empieza con "go-http-client"
            '/^apache-httpclient/i', // Empieza con "apache-httpclient"
            '/^node-fetch/i', // Empieza con "node-fetch"
            '/^undici/i' // Empieza con "undici" (Node.js HTTP client)
        );

        foreach ($botPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }

    private function is_suspicious_ua($userAgent)
    {
        if (strlen($userAgent) < 10)
            return true;
        $browserKeywords = ['mozilla', 'webkit', 'chrome', 'safari', 'firefox', 'edge', 'opera'];
        $hasValidBrowser = false;
        foreach ($browserKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                $hasValidBrowser = true;
                break;
            }
        }
        return !$hasValidBrowser;
    }

    private function ip_in_range($ip, $range)
    {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }

    private function is_service_ip($ip)
    {
        // Rangos de IP de Google (Googlebot y servicios)
        $googleRanges = [
            '66.249.64.0/19', // Googlebot
            '64.233.160.0/19', // Google services
            '216.239.32.0/19', // Google services
            '74.125.0.0/16' // Google services
        ];

        foreach ($googleRanges as $range) {
            if ($this->ip_in_range($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function is_admin_route($url)
    {
        $adminRoutes = array(
            '/wp-admin/',
            '/wp-login.php',
            '/xmlrpc.php',
            '/wp-cron.php',
            '/wp-content/',
            '/wp-includes/',
            '/endtrack-panel-afiliado',
            '/endtrack-panel-admin-afiliado',
            '/undefined',
            '/?nowprocket=',
            '/preview=',
            '/elementor-preview=',
            '/et_fb=',
            '/fl_builder=',
            '/vc_action=',
            '/builder=true',
            '/ct_builder='
        );
        foreach ($adminRoutes as $route) {
            if (strpos($url, $route) !== false) {
                return true;
            }
        }
        return false;
    }

    public function render_admin_panel()
    {
        ob_start();
        $is_shortcode = true;
        $is_admin_panel = true;
        require ENDTRACK_PLUGIN_DIR . 'templates/dashboard-layout.php';
        return ob_get_clean();
    }

    public function render_affiliate_panel()
    {
        ob_start();
        $is_shortcode = true;
        $is_admin_panel = false;
        require ENDTRACK_PLUGIN_DIR . 'templates/dashboard-layout.php';
        return ob_get_clean();
    }

    public function enqueue_scripts()
    {
        // Enqueue jQuery Cookie as used in original code
        wp_enqueue_script('jquery-cookie', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-cookie/1.4.1/jquery.cookie.min.js', array('jquery'), '1.4.1', true);
    }

    public function propagate_utms_to_precios_buttons()
    {
        ?>
                                <script>
                                (function () {
                                    var utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
                                    var params = new URLSearchParams(window.location.search);
                                    var utms = {};
                                    utmKeys.forEach(function (key) {
                                        var val = params.get(key);
                                        if (val) utms[key] = val;
                                    });
                                    if (Object.keys(utms).length === 0) return;

                                    var container = document.getElementById('precios');
                                    if (!container) return;

                                    container.querySelectorAll('.elementor-button').forEach(function (btn) {
                                        var href = btn.getAttribute('href');
                                        if (!href || href === '#') return;
                                        try {
                                            var url = new URL(href, window.location.origin);
                                            Object.keys(utms).forEach(function (key) {
                                                url.searchParams.set(key, utms[key]);
                                            });
                                            btn.setAttribute('href', url.toString());
                                        } catch (e) {}
                                    });
                                })();
                                </script>
                                <?php
    }

    public function tracking_code()
    {
        global $wpdb;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $ip = $_SERVER['REMOTE_ADDR'];
        $url_actual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $url_anterior = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

        // 1. Exclusion Logic
        $should_record = true;

        // Excluir administradores y editores
        if (is_user_logged_in() && (current_user_can('administrator') || current_user_can('edit_posts'))) {
            $should_record = false;
        }

        // Excluir rutas administrativas
        if ($this->is_admin_route($url_actual)) {
            return;
        }

        // Excluir bots por user agent
        if ($should_record && $this->is_bot($user_agent)) {
            $should_record = false;
            // [PERF] log por bot desactivado en producción (se disparaba en cada crawl).
        }

        // Excluir user agents sospechosos
        if ($should_record && $this->is_suspicious_ua($user_agent)) {
            $should_record = false;
            // [PERF] log UA sospechoso desactivado en producción.
        }

        // Excluir IPs de servicios conocidos (Google, etc.)
        if ($should_record && $this->is_service_ip($ip)) {
            $should_record = false;
            // [PERF] log IP de servicio desactivado en producción.
        }

        // 2. IP & GeoIP — con caché de transient (24h por IP)
        // Evita bloquear el render de la página en cada visita.
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
                $country_code = $details->location->country ?? '';
                $city = $details->location->city ?? '';
            }
            set_transient($geo_cache_key, array('country' => $country_code, 'city' => $city), DAY_IN_SECONDS);
        }


        // 3. UTMs & Referral Params
        $ref = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : 'No tiene afiliado';
        $ref_s = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : 'No tiene fuente';
        $ref_m = isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : 'No tiene medio';
        $ref_c = isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : 'No tiene campaña';
        $ref_t = isset($_GET['utm_term']) ? sanitize_text_field($_GET['utm_term']) : '';
        $ref_co = isset($_GET['utm_content']) ? sanitize_text_field($_GET['utm_content']) : '';
        $id_pag = get_the_ID();

        // 4. Session Logic
        if (!session_id()) {
            @session_start();
        }
        $session_expiration = 60 * 30; // 30 mins
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_start_time']) || (time() - $_SESSION['session_start_time'] > $session_expiration)) {
            $_SESSION['user_id'] = 'visitor_' . bin2hex(random_bytes(16));
            $_SESSION['session_start_time'] = time();
        }
        $session_user_id = $_SESSION['user_id'];

        // 5. Check Categories & Registered URLs
        $launches_mapping = get_option('endtrack_launches_mapping', array());
        $launch_links = get_option('endtrack_launch_links', array());

        $active_launch = false;
        $active_launch_slug = false;
        $is_relevant_page = false;
        $tipo_cat = 0; // 0: Visit, 1: Lead, 2: Sale, 3: Seminar

        $current_url_no_params = untrailingslashit(strtok($url_actual, '?'));

        // PRIORITY 1: Category Matching (Strict)
        // Check if page has a launch category
        foreach ($launches_mapping as $launch_name => $launch_slug) {
            if (has_category($launch_slug)) {
                $active_launch = $launch_name;
                $active_launch_slug = $launch_slug;
                $is_relevant_page = true;
                break;
            }
        }

        // Determine tipo_cat from general categories if not already set by URL
        if ($tipo_cat == 0) {
            if (has_category('gracias') || has_category('venta')) {
                $tipo_cat = 2;
                $is_relevant_page = true;
            } elseif (has_category('registro') || has_category('registroPB') || has_category('gracias_registro')) {
                $tipo_cat = 1;
                $is_relevant_page = true;
            } elseif (has_category('seminarios')) {
                $tipo_cat = 3;
                $is_relevant_page = true;
            }
        }

        // Cache: SOLO se bloquea en gracias/venta (tipo_cat=2), que llevan datos del
        // comprador (thrivecart) en la URL parseados por PHP. Registro (1) y seminarios (3)
        // SÍ se cachean: el tracking es client-side (JS lee UTMs de la URL en vivo) y las
        // visitas/leads se registran por AJAX (beacon), a prueba de caché.
        if ($is_relevant_page && $tipo_cat === 2 && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        $tc_account_id = '';
        $tc_total = '';
        $tc_product = '';
        $email = '';
        $thrivecart_hash = 'No tiene hash';

        // Robust parsing to handle &amp; if present in URL
        $query_string = parse_url($url_actual, PHP_URL_QUERY);
        if ($query_string) {
            $query_string = str_replace('&amp;', '&', $query_string);
            parse_str($query_string, $query);

            if (isset($query['thrivecart']['account_id'])) {
                $tc_account_id = sanitize_text_field($query['thrivecart']['account_id']);
            }

            // Extract order_total (convert cents to euros if needed)
            if (isset($query['thrivecart']['order_total'])) {
                $raw_total = sanitize_text_field($query['thrivecart']['order_total']);
                // If it looks like cents (large integer), divide by 100
                if (is_numeric($raw_total) && strpos($raw_total, '.') === false && floatval($raw_total) >= 100) {
                    $tc_total = strval(floatval($raw_total) / 100.0);
                } else {
                    $tc_total = $raw_total;
                }
            }

            if (isset($query['thrivecart']['order']) && is_array($query['thrivecart']['order'])) {
                $order_items = $query['thrivecart']['order'];
                $product_names = array();
                $calculated_total_cents = 0;
                $has_p_q = false;

                foreach ($order_items as $item) {
                    if (isset($item['n'])) {
                        $product_names[] = sanitize_text_field($item['n']);
                    }
                    if (isset($item['p']) && isset($item['q'])) {
                        $calculated_total_cents += (floatval($item['p']) * intval($item['q']));
                        $has_p_q = true;
                    }
                }

                if ($tc_total === '' && $has_p_q) {
                    $tc_total = strval($calculated_total_cents / 100.0);
                }
                if (!empty($product_names)) {
                    $tc_product = implode(', ', $product_names);
                }
            }

            if (isset($query['thrivecart']['customer']['email'])) {
                $email = urldecode($query['thrivecart']['customer']['email']);
            }
            if (isset($query['thrivecart_hash'])) {
                $thrivecart_hash = sanitize_text_field($query['thrivecart_hash']);
            }
        }

        // 6. Registro de VISITA: movido a un beacon JS (acción AJAX endtrack_visit) para
        // que funcione también con la página CACHEADA. Ya NO se inserta aquí en PHP
        // (si lo hiciéramos, en cache-hit no correría y en cache-miss duplicaría).
        $is_gracias_registro = has_category('gracias_registro') || has_category('gracias-registro');

        // 6.b Píxeles de afiliado (Meta / Google Ads) por lanzamiento.
        // Se inyectan SOLO en páginas marcadas como de afiliado (_endtrack_is_affiliate_link
        // desde el admin, incluidas las de gracias). El píxel concreto se elige CLIENT-SIDE
        // según el ?ref=/cookie del afiliado, por lo que es a prueba de caché (igual que el
        // resto del tracking). $pixel_map = mapa { ref_afiliado => {m, gi, gl} } del lanzamiento.
        $pixel_map = array();
        $pixel_event = ''; // '' = solo PageView; 'lead' = gracias-registro; 'purchase' = gracias-venta
        if ($id_pag && get_post_meta($id_pag, '_endtrack_is_affiliate_link', true) && $active_launch) {
            $safe_px = ($active_launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
            $all_px = get_option('endtrack_affiliate_pixels_' . $safe_px, array());
            if (is_array($all_px)) {
                foreach ($all_px as $aff_id => $px) {
                    if (!is_array($px)) {
                        continue;
                    }
                    $m = isset($px['meta']) ? trim($px['meta']) : '';
                    $gi = isset($px['gads_id']) ? trim($px['gads_id']) : '';
                    $gl = isset($px['gads_label']) ? trim($px['gads_label']) : '';
                    if ($m === '' && $gi === '') {
                        continue;
                    }
                    $pixel_map[(string) $aff_id] = array('m' => $m, 'gi' => $gi, 'gl' => $gl);
                }
            }
            if (has_category('gracias')) {
                $pixel_event = 'purchase';
            } elseif ($is_gracias_registro) {
                $pixel_event = 'lead';
            }
        }

        // 7. Output JS for Conversion Tracking (AJAX to wp_datos)

        // Map ref_s logic for JS
        $fuentes_publicidad = array('facebook', 'pb', 'fb', 'gads');
        $fuente_js = $ref_s;
        if (in_array($ref_s, $fuentes_publicidad)) {
            $fuente_js = 'publicidad';
        }
        $fuentes_organico = array('ActiveCampaign');
        if (in_array($ref_s, $fuentes_organico)) {
            $fuente_js = ($ref == 'No tiene afiliado') ? 'organico' : 'afiliado';
        }

        // 5. Output JS
        ?>
        <?php if (!empty($pixel_map)): ?>
        <!-- ENDTrack: píxeles de afiliado (se dispara solo el del ?ref=/cookie del visitante) -->
        <script>
            (function () {
                var PIX = <?php echo wp_json_encode($pixel_map); ?>;
                var EV = "<?php echo esc_js($pixel_event); ?>";
                var VAL = parseFloat("<?php echo esc_js($tc_total); ?>") || 0;
                function etGetCookie(n) {
                    var parts = ("; " + document.cookie).split("; " + n + "=");
                    return parts.length === 2 ? decodeURIComponent(parts.pop().split(";").shift()) : "";
                }
                var ref = (new URLSearchParams(window.location.search)).get('ref') || etGetCookie('endtrack_ref') || '';
                if (!ref) return;
                var p = PIX[ref];
                if (!p) return;

                // ---- Meta Pixel ----
                if (p.m) {
                    !function (f, b, e, v, n, t, s) {
                        if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments) };
                        if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
                        t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s)
                    }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', p.m);
                    fbq('track', 'PageView');
                    if (EV === 'purchase') { fbq('track', 'Purchase', { value: VAL, currency: 'EUR' }); }
                    else if (EV === 'lead') { fbq('track', 'Lead'); }
                }

                // ---- Google Ads / GA4 ----
                if (p.gi) {
                    var gs = document.createElement('script'); gs.async = true;
                    gs.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(p.gi);
                    document.head.appendChild(gs);
                    window.dataLayer = window.dataLayer || [];
                    function gtag() { dataLayer.push(arguments); }
                    if (!window.gtag) { window.gtag = gtag; }
                    window.gtag('js', new Date());
                    window.gtag('config', p.gi);
                    if ((EV === 'purchase' || EV === 'lead') && p.gl) {
                        var cd = { send_to: p.gi + '/' + p.gl };
                        if (EV === 'purchase') { cd.value = VAL; cd.currency = 'EUR'; }
                        window.gtag('event', 'conversion', cd);
                    }
                }
            })();
        </script>
        <?php endif; ?>
                                                                <script>
                                                                    var endtrack_vars = {
                                                                        ajaxurl: "<?php echo admin_url('admin-ajax.php'); ?>",
                                                                        siteURL: "<?php echo site_url(); ?>",
                                                                        launch: "<?php echo $active_launch ? $active_launch : ''; ?>",
                                                                        ref: "<?php echo esc_js($ref); ?>",
                                                                        ref_s: "<?php echo esc_js($fuente_js); ?>",
                                                                        ref_m: "<?php echo esc_js($ref_m); ?>",
                                                                        ref_c: "<?php echo esc_js($ref_c); ?>",
                                                                        session_id: "<?php echo esc_js($session_user_id); ?>",
                                                                        url_anterior: "<?php echo esc_js($url_anterior); ?>",
                                                                        url_actual: "<?php echo esc_js($url_actual); ?>",
                                                                        pais: "<?php echo esc_js($country_code); ?>",
                                                                        ciudad: "<?php echo esc_js($city); ?>",
                                                                        id_pag: "<?php echo get_the_ID(); ?>",
                                                                        tipo_cat: "<?php echo $tipo_cat; ?>",
                                                                        fecha: "<?php echo wp_date('Y-m-d H:i:s', null, new DateTimeZone('Europe/Madrid')); ?>",
                                                                        tc_total: "<?php echo esc_js($tc_total); ?>",
                                                                        tc_product: "<?php echo esc_js($tc_product); ?>",
                                                                        tc_account_id: "<?php echo esc_js($tc_account_id); ?>",
                                                                        email: "<?php echo esc_js($email); ?>",
                                                                        tc_hash: "<?php echo esc_js($thrivecart_hash); ?>",
                                                                        tipopag: "<?php echo has_category('registroPB') ? 'pub' : ''; ?>",
                                                                        track_visit: "<?php echo ($id_pag > 0 && $is_relevant_page && !$is_gracias_registro) ? '1' : '0'; ?>"
                                                                    };

                                                                    jQuery(document).ready(function ($) {

                                                                        // Cookie logic
                                                                        var random = Math.floor((Math.random() * 10000000) + 1);
                                                                        var date = Date.now();
                                                                        var cookie = date + '' + random;
                                                                        if (typeof $.cookie('registrado') === 'undefined') {
                                                                            $.cookie('registrado', cookie, { expires: 40 });
                                                                        } else {
                                                                            cookie = $.cookie('registrado');
                                                                        }
                                                                        endtrack_vars.cookie = cookie;

                                                                        // Capture and persist UTMs/Ref in cookies
                                                                        var urlParams = new URLSearchParams(window.location.search);
                                                                        var url_ref  = urlParams.get('ref');
                                                                        var url_s    = urlParams.get('utm_source');
                                                                        var url_m    = urlParams.get('utm_medium');
                                                                        var url_c    = urlParams.get('utm_campaign');
                                                                        var url_t    = urlParams.get('utm_term');
                                                                        var url_co   = urlParams.get('utm_content');
                                                                        var url_pl   = urlParams.get('utm_placement') || urlParams.get('placement');

                                                                        if (url_ref) $.cookie('endtrack_ref',   url_ref, { expires: 30, path: '/' });
                                                                        if (url_s)   $.cookie('endtrack_utm_s',  url_s,  { expires: 30, path: '/' });
                                                                        if (url_m)   $.cookie('endtrack_utm_m',  url_m,  { expires: 30, path: '/' });
                                                                        if (url_c)   $.cookie('endtrack_utm_c',  url_c,  { expires: 30, path: '/' });
                                                                        if (url_t)   $.cookie('endtrack_utm_t',  url_t,  { expires: 30, path: '/' });
                                                                        if (url_co)  $.cookie('endtrack_utm_co', url_co, { expires: 30, path: '/' });
                                                                        if (url_pl)  $.cookie('endtrack_utm_pl', url_pl, { expires: 30, path: '/' });

                                                                        // === Resolución de UTMs A PRUEBA DE CACHÉ ===
                                                                        // Si la página se sirve CACHEADA (WP Rocket), endtrack_vars (PHP) tendría
                                                                        // los UTMs del PRIMER visitante. Por eso resolvemos SIEMPRE desde la URL en
                                                                        // vivo y, si falta, desde la cookie — nunca desde el endtrack_vars cacheado.
                                                                        endtrack_vars.ref = url_ref || $.cookie('endtrack_ref') || 'No tiene afiliado';

                                                                        var et_src = url_s || $.cookie('endtrack_utm_s') || 'No tiene fuente';
                                                                        if (['facebook', 'pb', 'fb', 'gads'].indexOf(et_src) !== -1) {
                                                                            et_src = 'publicidad';
                                                                        } else if (et_src === 'ActiveCampaign') {
                                                                            et_src = (endtrack_vars.ref && endtrack_vars.ref !== 'No tiene afiliado') ? 'afiliado' : 'organico';
                                                                        }
                                                                        endtrack_vars.ref_s = et_src;

                                                                        endtrack_vars.ref_m  = url_m  || $.cookie('endtrack_utm_m')  || 'No tiene medio';
                                                                        endtrack_vars.ref_c  = url_c  || $.cookie('endtrack_utm_c')  || 'No tiene campaña';
                                                                        endtrack_vars.ref_t  = url_t  || $.cookie('endtrack_utm_t')  || '';
                                                                        endtrack_vars.ref_co = url_co || $.cookie('endtrack_utm_co') || '';
                                                                        endtrack_vars.ref_pl = url_pl || $.cookie('endtrack_utm_pl') || '';

                                                                        // === Beacon de VISITA (a prueba de caché) ===
                                                                        // Registra la visita en uBCFl_visitas vía AJAX en cada carga (también con la
                                                                        // página cacheada). El servidor añade IP/geo y excluye admins/bots.
                                                                        if (endtrack_vars.track_visit === '1') {
                                                                            $.ajax({
                                                                                type: 'POST',
                                                                                url: endtrack_vars.ajaxurl,
                                                                                data: {
                                                                                    action: 'endtrack_visit',
                                                                                    ref: endtrack_vars.ref,
                                                                                    ref_s: endtrack_vars.ref_s,
                                                                                    ref_m: endtrack_vars.ref_m,
                                                                                    ref_c: endtrack_vars.ref_c,
                                                                                    ref_t: endtrack_vars.ref_t,
                                                                                    ref_co: endtrack_vars.ref_co,
                                                                                    session_id: endtrack_vars.cookie,
                                                                                    url_actual: window.location.href,
                                                                                    url_anterior: document.referrer || '',
                                                                                    id_pag: endtrack_vars.id_pag,
                                                                                    launch_context: endtrack_vars.launch,
                                                                                    tipo_cat: endtrack_vars.tipo_cat
                                                                                }
                                                                            });
                                                                        }

                                                                        // Handle 'gracias' page logic
                                                                        if (endtrack_vars.tipo_cat == '2') {
                                                                            if (endtrack_vars.email !== '') {
                                                                                $.ajax({
                                                                                    type: 'POST',
                                                                                    url: endtrack_vars.ajaxurl,
                                                                                    data: {
                                                                                        action: 'endtrack_datos',
                                                                                        afiliado: endtrack_vars.ref,
                                                                                        nombre: endtrack_vars.tc_hash,
                                                                                        correo: endtrack_vars.email,
                                                                                        fecha: endtrack_vars.fecha,
                                                                                        source: endtrack_vars.ref_s,
                                                                                        venta: 2,
                                                                                        session_id: endtrack_vars.session_id,
                                                                                        medium: endtrack_vars.ref_m,
                                                                                        term: 'COMPRADOR',
                                                                                        campaign: endtrack_vars.ref_c,
                                                                                        cookie: endtrack_vars.cookie,
                                                                                        url_anterior: endtrack_vars.url_anterior,
                                                                                        url_actual: endtrack_vars.url_actual,
                                                                                        pais: endtrack_vars.pais,
                                                                                        ciudad: endtrack_vars.ciudad,
                                                                                        id_pag: endtrack_vars.id_pag,
                                                                                        tipo_cat: endtrack_vars.tipo_cat,
                                                                                        launch_context: endtrack_vars.launch,
                                                                                        total: endtrack_vars.tc_total,
                                                                                        producto: endtrack_vars.tc_product,
                                                                                        account_id: endtrack_vars.tc_account_id
                                                                                    }
                                                                                });
                                                                            }

                                                                            // Handle 'venta' logic
                                                                            var para_thrive = 'passthrough[utm_source]=' + endtrack_vars.ref_s + '&passthrough[utm_medium]=' + endtrack_vars.ref_m + '&passthrough[ref]=' + endtrack_vars.ref;
                                                                            $('.tc-v2-embeddable-target').attr('data-thrivecart-querystring', para_thrive);

                                                                            $('.wpcf7-submit').click(function () {
                                                                                var nombre_form_c = $('input[name="your-name"]').val();
                                                                                var correo_form_c = $('.wpcf7-email').val();

                                                                                $.ajax({
                                                                                    type: 'POST',
                                                                                    url: endtrack_vars.ajaxurl,
                                                                                    data: {
                                                                                        action: 'endtrack_datos',
                                                                                        afiliado: endtrack_vars.ref,
                                                                                        nombre: nombre_form_c,
                                                                                        correo: correo_form_c,
                                                                                        fecha: endtrack_vars.fecha,
                                                                                        source: endtrack_vars.ref_s,
                                                                                        venta: 2,
                                                                                        session_id: endtrack_vars.session_id,
                                                                                        medium: endtrack_vars.ref_m,
                                                                                        term: 'COMPRADOR',
                                                                                        content: '',
                                                                                        placement: '',
                                                                                        campaign: endtrack_vars.ref_c,
                                                                                        cookie: endtrack_vars.cookie,
                                                                                        url_anterior: endtrack_vars.url_anterior,
                                                                                        url_actual: endtrack_vars.url_actual,
                                                                                        pais: endtrack_vars.pais,
                                                                                        ciudad: endtrack_vars.ciudad,
                                                                                        id_pag: endtrack_vars.id_pag,
                                                                                        tipo_cat: endtrack_vars.tipo_cat,
                                                                                        launch_context: endtrack_vars.launch
                                                                                    }
                                                                                });
                                                                            });
                                                                        }
                                                                        // =====================================================================
                                                                        // ENDTRACK: #add_suscrito handler
                                                                        // FIX: NO usar disabled=true en el DOM → rompe el flujo nativo de Elementor.
                                                                        //      Usamos variable JS para debouncing. El botón sigue activo para que
                                                                        //      Elementor pueda procesar su propio submit/validación correctamente.
                                                                        // =====================================================================
                                                                        var endtrack_submitting = false;
                                                                        var endtrack_last_submit_email = '';

                                                                        $(document).on('click', '#add_suscrito', function (e) {
                                                                            var $btn = $(this);
                                                                            var $form = $btn.closest('form');

                                                                            // Validation: check privacy policy only if the checkbox exists
                                                                            var privacyChecked = true;
                                                                            var $privacy = $form.find('#form-field-politicapriv').length > 0 ? $form.find('#form-field-politicapriv') : $('#form-field-politicapriv');
                                                                            if ($privacy.length > 0) {
                                                                                privacyChecked = $privacy.is(':checked');
                                                                            }

                                                                            var commsChecked = true;
                                                                            var $comms = $form.find('#form-field-comunicaciones').length > 0 ? $form.find('#form-field-comunicaciones') : $('#form-field-comunicaciones');
                                                                            if ($comms.length > 0) {
                                                                                commsChecked = $comms.is(':checked');
                                                                            }

                                                                            if (!privacyChecked || !commsChecked) {
                                                                                console.log('ENDTrack: Privacy or Comms not checked');
                                                                                return;
                                                                            }

                                                                            // Fallback selectors for name and email, prioritizing current form
                                                                            var nombre_form = $form.find('#form-field-name').val() || $form.find('input[name="your-name"]').val() || $('#form-field-name').val() || $('input[name="your-name"]').val() || '';
                                                                            var correo_form = $form.find('#form-field-email').val() || $form.find('.wpcf7-email').val() || $form.find('input[type="email"]').val() || $('#form-field-email').val() || $('.wpcf7-email').val() || $('input[type="email"]').first().val() || '';

                                                                            console.log('ENDTrack: #add_suscrito clicked', { nombre: nombre_form, correo: correo_form });

                                                                            if (!correo_form) {
                                                                                console.warn('ENDTrack: Email not found, skipping ENDTrack AJAX');
                                                                                return;
                                                                            }

                                                                            // Debouncing por email — sin tocar el DOM del botón (no disabled)
                                                                            if (endtrack_submitting && endtrack_last_submit_email === correo_form) {
                                                                                console.warn('ENDTrack: Duplicate detected for', correo_form, '— skipping ENDTrack AJAX only');
                                                                                return;
                                                                            }

                                                                            endtrack_submitting = true;
                                                                            endtrack_last_submit_email = correo_form;

                                                                            // Loader visual sin deshabilitar el botón
                                                                            $('.cf7-loader').css('display', 'flex');

                                                                            $.ajax({
                                                                                type: 'POST',
                                                                                url: endtrack_vars.ajaxurl,
                                                                                data: {
                                                                                    action: 'endtrack_datos',
                                                                                    afiliado: endtrack_vars.ref,
                                                                                    nombre: nombre_form,
                                                                                    correo: correo_form,
                                                                                    fecha: endtrack_vars.fecha,
                                                                                    source: endtrack_vars.ref_s,
                                                                                    session_id: endtrack_vars.session_id,
                                                                                    medium: endtrack_vars.ref_m,
                                                                                    term: endtrack_vars.ref_t,
                                                                                    placement: endtrack_vars.ref_pl,
                                                                                    content: endtrack_vars.ref_co,
                                                                                    campaign: endtrack_vars.ref_c,
                                                                                    tipo: '',
                                                                                    cookie: endtrack_vars.cookie,
                                                                                    url_anterior: endtrack_vars.url_anterior,
                                                                                    url_actual: endtrack_vars.url_actual,
                                                                                    tipo_pag: endtrack_vars.tipopag,
                                                                                    pais: endtrack_vars.pais,
                                                                                    ciudad: endtrack_vars.ciudad,
                                                                                    id_pag: endtrack_vars.id_pag,
                                                                                    tipo_cat: endtrack_vars.tipo_cat,
                                                                                    launch_context: endtrack_vars.launch
                                                                                },
                                                                                success: function(response) {
                                                                                    console.log('ENDTrack: AJAX Success', response);
                                                                                },
                                                                                error: function(xhr, status, error) {
                                                                                    console.error('ENDTrack: AJAX Error', status, error);
                                                                                },
                                                                                complete: function() {
                                                                                    setTimeout(function() {
                                                                                        $('.cf7-loader').hide();
                                                                                        endtrack_submitting = false;
                                                                                    }, 5000);
                                                                                }
                                                                            });
                                                                        });

                                                                        // =====================================================================
                                                                        // ENDTRACK: Listener de respaldo — evento nativo de Elementor Forms
                                                                        // Si el click handler no llegó a capturar (e.g. Elementor validó antes),
                                                                        // este listener recoge el envío exitoso de Elementor como backup.
                                                                        // =====================================================================
                                                                        $(document).on('submit_success', '.elementor-form', function(e, response) {
                                                                            var $form = $(this);
                                                                            // Captura ampliada: formularios con #add_suscrito SIEMPRE; además, en
                                                                            // páginas de categoría "registro" (tipo_cat=1) capturamos cualquier
                                                                            // formulario Elementor con email aunque el botón no tenga ese id
                                                                            // (páginas antiguas sin lanzamiento). El anti-duplicado por email (15s)
                                                                            // del lado servidor evita registros repetidos.
                                                                            var et_isRegistro = (String(endtrack_vars.tipo_cat) === '1');
                                                                            if ($form.find('#add_suscrito').length === 0 && !et_isRegistro) return;

                                                                            var correo_form = $form.find('#form-field-email').val() || $form.find('input[type="email"]').first().val() || '';
                                                                            var nombre_form = $form.find('#form-field-name').val() || '';

                                                                            if (!correo_form) return;

                                                                            // No duplicar si el click handler ya capturó este email
                                                                            if (endtrack_last_submit_email === correo_form && endtrack_submitting) {
                                                                                console.log('ENDTrack backup: already captured via click, skipping.');
                                                                                return;
                                                                            }

                                                                            console.log('ENDTrack: Elementor submit_success backup captured', correo_form);

                                                                            $.ajax({
                                                                                type: 'POST',
                                                                                url: endtrack_vars.ajaxurl,
                                                                                data: {
                                                                                    action: 'endtrack_datos',
                                                                                    afiliado: endtrack_vars.ref,
                                                                                    nombre: nombre_form,
                                                                                    correo: correo_form,
                                                                                    fecha: endtrack_vars.fecha,
                                                                                    source: endtrack_vars.ref_s,
                                                                                    session_id: endtrack_vars.session_id,
                                                                                    medium: endtrack_vars.ref_m,
                                                                                    term: endtrack_vars.ref_t,
                                                                                    placement: endtrack_vars.ref_pl,
                                                                                    content: endtrack_vars.ref_co,
                                                                                    campaign: endtrack_vars.ref_c,
                                                                                    tipo: '',
                                                                                    cookie: endtrack_vars.cookie,
                                                                                    url_anterior: endtrack_vars.url_anterior,
                                                                                    url_actual: endtrack_vars.url_actual,
                                                                                    tipo_pag: endtrack_vars.tipopag,
                                                                                    pais: endtrack_vars.pais,
                                                                                    ciudad: endtrack_vars.ciudad,
                                                                                    id_pag: endtrack_vars.id_pag,
                                                                                    tipo_cat: endtrack_vars.tipo_cat,
                                                                                    launch_context: endtrack_vars.launch
                                                                                },
                                                                                success: function(r) { console.log('ENDTrack backup: AJAX Success', r); },
                                                                                error: function(x, s, err) { console.error('ENDTrack backup: AJAX Error', s, err); }
                                                                            });
                                                                        });

                                                                    });
                                                                </script>
                                                                <?php
    }

    public function inject_ai_css()
    {
        if (!is_singular())
            return;
        $post_id = get_the_ID();
        $settings = get_post_meta($post_id, '_elementor_page_settings', true);
        if (is_array($settings) && !empty($settings['custom_css'])) {
            echo "\n<!-- ENDTrack AI Global Styles -->\n";
            echo "<style id='endtrack-ai-styles'>\n" . stripslashes($settings['custom_css']) . "\n</style>\n";
        }
    }

    /**
     * Inject floating AI editor button on frontend for admins
     */
    public function inject_floating_ai_button()
    {
        // Only for logged-in admins on singular pages
        if (!is_user_logged_in() || !current_user_can('manage_options'))
            return;
        if (!is_singular())
            return;

        $post_id = get_the_ID();

        // Only show on pages that belong to a launch category
        $launches_mapping = get_option('endtrack_launches_mapping', array());
        if (!empty($launches_mapping)) {
            $launch_cat_slugs = array_values($launches_mapping);
            $post_categories = wp_get_post_categories($post_id, array('fields' => 'slugs'));
            $has_launch = array_intersect($launch_cat_slugs, $post_categories);
            if (empty($has_launch))
                return;
        } else {
            return;
        }
        $post_title = get_the_title();
        $nonce = wp_create_nonce('endtrack_frontend_ai_nonce');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
                                                                <!-- ENDTrack Floating AI Editor -->
                                                                <style>
                                                                    #endtrack-ai-fab {
                                                                        position: fixed;
                                                                        bottom: 30px;
                                                                        right: 30px;
                                                                        width: 60px;
                                                                        height: 60px;
                                                                        border-radius: 50%;
                                                                        background: linear-gradient(135deg, #d97706, #f59e0b);
                                                                        color: #fff;
                                                                        border: none;
                                                                        cursor: pointer;
                                                                        font-size: 26px;
                                                                        display: flex;
                                                                        align-items: center;
                                                                        justify-content: center;
                                                                        box-shadow: 0 4px 20px rgba(217, 119, 6, 0.5);
                                                                        z-index: 999999;
                                                                        transition: all 0.3s ease;
                                                                    }

                                                                    #endtrack-ai-fab:hover {
                                                                        transform: scale(1.1);
                                                                        box-shadow: 0 6px 30px rgba(217, 119, 6, 0.7);
                                                                    }

                                                                    #endtrack-ai-fab.active {
                                                                        border-radius: 16px;
                                                                        width: 48px;
                                                                        height: 48px;
                                                                        font-size: 20px;
                                                                        bottom: 440px;
                                                                        right: 30px;
                                                                        background: linear-gradient(135deg, #dc2626, #ef4444);
                                                                        box-shadow: 0 4px 20px rgba(220, 38, 38, 0.5);
                                                                    }

                                                                    #endtrack-ai-panel {
                                                                        position: fixed;
                                                                        bottom: 30px;
                                                                        right: 30px;
                                                                        width: 400px;
                                                                        max-height: 400px;
                                                                        background: rgba(15, 23, 42, 0.95);
                                                                        backdrop-filter: blur(20px);
                                                                        -webkit-backdrop-filter: blur(20px);
                                                                        border: 1px solid rgba(255, 255, 255, 0.1);
                                                                        border-radius: 16px;
                                                                        padding: 0;
                                                                        z-index: 999998;
                                                                        display: none;
                                                                        flex-direction: column;
                                                                        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                                                                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                                                                        overflow: hidden;
                                                                    }

                                                                    #endtrack-ai-panel.open {
                                                                        display: flex;
                                                                    }

                                                                    #endtrack-ai-panel-header {
                                                                        padding: 16px 20px;
                                                                        background: rgba(217, 119, 6, 0.15);
                                                                        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
                                                                        display: flex;
                                                                        align-items: center;
                                                                        gap: 10px;
                                                                    }

                                                                    #endtrack-ai-panel-header .ai-icon {
                                                                        font-size: 22px;
                                                                    }

                                                                    #endtrack-ai-panel-header .ai-title {
                                                                        color: #f1f5f9;
                                                                        font-size: 14px;
                                                                        font-weight: 700;
                                                                        flex: 1;
                                                                    }

                                                                    #endtrack-ai-panel-header .ai-page {
                                                                        color: #94a3b8;
                                                                        font-size: 11px;
                                                                        display: block;
                                                                        font-weight: 400;
                                                                        margin-top: 2px;
                                                                    }

                                                                    #endtrack-ai-prompt {
                                                                        flex: 1;
                                                                        padding: 16px 20px;
                                                                        overflow-y: auto;
                                                                    }

                                                                    #endtrack-ai-prompt textarea {
                                                                        width: 100%;
                                                                        min-height: 120px;
                                                                        background: rgba(255, 255, 255, 0.06);
                                                                        border: 1px solid rgba(255, 255, 255, 0.12);
                                                                        border-radius: 10px;
                                                                        color: #f1f5f9;
                                                                        font-size: 14px;
                                                                        line-height: 1.5;
                                                                        padding: 12px 14px;
                                                                        resize: vertical;
                                                                        font-family: inherit;
                                                                        outline: none;
                                                                        transition: border-color 0.2s;
                                                                        box-sizing: border-box;
                                                                    }

                                                                    #endtrack-ai-prompt textarea:focus {
                                                                        border-color: #d97706;
                                                                    }

                                                                    #endtrack-ai-prompt textarea::placeholder {
                                                                        color: #64748b;
                                                                    }

                                                                    #endtrack-ai-actions {
                                                                        padding: 12px 20px 16px;
                                                                        display: flex;
                                                                        gap: 10px;
                                                                    }

                                                                    #endtrack-ai-send {
                                                                        flex: 1;
                                                                        padding: 10px 16px;
                                                                        border: none;
                                                                        border-radius: 10px;
                                                                        background: linear-gradient(135deg, #d97706, #f59e0b);
                                                                        color: #fff;
                                                                        font-size: 14px;
                                                                        font-weight: 700;
                                                                        cursor: pointer;
                                                                        transition: all 0.2s;
                                                                        font-family: inherit;
                                                                    }

                                                                    #endtrack-ai-send:hover {
                                                                        opacity: 0.9;
                                                                        transform: translateY(-1px);
                                                                    }

                                                                    #endtrack-ai-send:disabled {
                                                                        opacity: 0.5;
                                                                        cursor: not-allowed;
                                                                        transform: none;
                                                                    }

                                                                    #endtrack-ai-status {
                                                                        padding: 0 20px 12px;
                                                                        font-size: 13px;
                                                                        display: none;
                                                                    }

                                                                    #endtrack-ai-status.success {
                                                                        color: #10b981;
                                                                        display: block;
                                                                    }

                                                                    #endtrack-ai-status.error {
                                                                        color: #ef4444;
                                                                        display: block;
                                                                    }

                                                                    #endtrack-ai-status.loading {
                                                                        color: #f59e0b;
                                                                        display: block;
                                                                    }

                                                                    .ai-loading-dots::after {
                                                                        content: '';
                                                                        animation: dots 1.5s steps(4, end) infinite;
                                                                    }

                                                                    @keyframes dots {
                                                                        0% {
                                                                            content: '';
                                                                        }

                                                                        25% {
                                                                            content: '.';
                                                                        }

                                                                        50% {
                                                                            content: '..';
                                                                        }

                                                                        75% {
                                                                            content: '...';
                                                                        }
                                                                    }

                                                                    @media (max-width: 480px) {
                                                                        #endtrack-ai-panel {
                                                                            width: calc(100vw - 20px);
                                                                            right: 10px;
                                                                            bottom: 10px;
                                                                        }

                                                                        #endtrack-ai-fab {
                                                                            bottom: 15px;
                                                                            right: 15px;
                                                                            width: 52px;
                                                                            height: 52px;
                                                                            font-size: 22px;
                                                                        }

                                                                        #endtrack-ai-fab.active {
                                                                            bottom: 430px;
                                                                            right: 15px;
                                                                        }
                                                                    }
                                                                </style>

                                                                <button id="endtrack-ai-fab" title="Editar página con Claude AI">🧠</button>

                                                                <div id="endtrack-ai-panel">
                                                                    <div id="endtrack-ai-panel-header">
                                                                        <span class="ai-icon">🧠</span>
                                                                        <div class="ai-title">
                                                                            Claude AI Editor
                                                                            <span class="ai-page">
                                                                                <?php echo esc_html($post_title); ?> (ID:
                                                                                <?php echo $post_id; ?>)
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                    <div id="endtrack-ai-prompt">
                                                                        <textarea id="endtrack-ai-textarea" placeholder="Describe qué quieres cambiar en esta página...

Ej: Cambia el título principal por 'Nuevo título'
     Pon fondo oscuro con letras blancas
     Añade una nueva sección con testimonios
     Cambia el texto del botón a 'Comprar ahora'"></textarea>
                                                                    </div>
                                                                    <div id="endtrack-ai-status"></div>
                                                                    <div id="endtrack-ai-actions">
                                                                        <button id="endtrack-ai-send">✨ Aplicar con Claude</button>
                                                                    </div>
                                                                </div>

                                                                <script>
                                                                    (function () {
                                                                        var fab = document.getElementById('endtrack-ai-fab');
                                                                        var panel = document.getElementById('endtrack-ai-panel');
                                                                        var textarea = document.getElementById('endtrack-ai-textarea');
                                                                        var sendBtn = document.getElementById('endtrack-ai-send');
                                                                        var status = document.getElementById('endtrack-ai-status');
                                                                        var isOpen = false;

                                                                        fab.addEventListener('click', function () {
                                                                            isOpen = !isOpen;
                                                                            if (isOpen) {
                                                                                panel.classList.add('open');
                                                                                fab.classList.add('active');
                                                                                fab.innerHTML = '✕';
                                                                                textarea.focus();
                                                                            } else {
                                                                                panel.classList.remove('open');
                                                                                fab.classList.remove('active');
                                                                                fab.innerHTML = '🧠';
                                                                            }
                                                                        });

                                                                        sendBtn.addEventListener('click', function () {
                                                                            var prompt = textarea.value.trim();
                                                                            if (!prompt) {
                                                                                setStatus('Escribe una instrucción primero.', 'error');
                                                                                return;
                                                                            }

                                                                            sendBtn.disabled = true;
                                                                            sendBtn.textContent = '⏳ Claude está pensando...';
                                                                            setStatus('Enviando instrucciones a Claude AI', 'loading');

                                                                            var xhr = new XMLHttpRequest();
                                                                            xhr.open('POST', '<?php echo esc_js($ajax_url); ?>', true);
                                                                            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                                                                            xhr.onreadystatechange = function () {
                                                                                if (xhr.readyState !== 4) return;

                                                                                sendBtn.disabled = false;
                                                                                sendBtn.textContent = '✨ Aplicar con Claude';

                                                                                try {
                                                                                    var response = JSON.parse(xhr.responseText);
                                                                                    if (response.success) {
                                                                                        setStatus('✅ ' + response.data.message, 'success');
                                                                                        textarea.value = '';
                                                                                        setTimeout(function () { location.reload(); }, 2000);
                                                                                    } else {
                                                                                        setStatus('❌ ' + (response.data || 'Error desconocido'), 'error');
                                                                                    }
                                                                                } catch (e) {
                                                                                    setStatus('❌ Error de conexión. Inténtalo de nuevo.', 'error');
                                                                                }
                                                                            };

                                                                            var params = 'action=endtrack_frontend_ai_edit'
                                                                                + '&post_id=<?php echo $post_id; ?>'
                                                                                + '&custom_prompt=' + encodeURIComponent(prompt)
                                                                                + '&nonce=<?php echo $nonce; ?>';

                                                                            xhr.send(params);
                                                                        });

                                                                        // Send on Ctrl+Enter
                                                                        textarea.addEventListener('keydown', function (e) {
                                                                            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                                                                                sendBtn.click();
                                                                            }
                                                                        });

                                                                        function setStatus(msg, type) {
                                                                            status.textContent = msg;
                                                                            status.className = type;
                                                                            if (type === 'loading') {
                                                                                status.innerHTML = msg + '<span class="ai-loading-dots"></span>';
                                                                            }
                                                                        }
                                                                    })();
                                                                </script>
                                                                <?php
    }
}