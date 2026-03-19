<?php

class ENDTrack_Public
{

    public function init()
    {
        add_action('wp_head', array($this, 'tracking_code'));
        add_action('wp_head', array($this, 'inject_ai_css'), 999);
        add_action('wp_footer', array($this, 'inject_floating_ai_button'), 999);
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

        // Temporarily flush rules to ensure -endtrack links work
        if (get_option('endtrack_flush_rewrites') !== 'done') {
            flush_rewrite_rules();
            update_option('endtrack_flush_rewrites', 'done');
        }

        // Redirect affiliates from wp-admin
        add_action('admin_init', array($this, 'redirect_affiliates_from_admin'));
    }

    public function redirect_affiliates_from_admin()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        $user = wp_get_current_user();
        if (in_array('afiliado', (array)$user->roles)) {
            wp_redirect(home_url('/endtrack-panel-afiliado/'));
            exit;
        }
    }

    public function track_last_login($user_login, $user)
    {
        update_user_meta($user->ID, 'endtrack_last_login', current_time('mysql'));
    }

    public function register_rewrites()
    {
        add_rewrite_rule('^endtrack/?$', 'index.php?endtrack_launch_dashboard=admin-main', 'top');
        add_rewrite_rule('^endtrack-estadisticas/?$', 'index.php?endtrack_launch_dashboard=admin-stats', 'top');
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
            if (!current_user_can('manage_options')) {
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
            $is_affiliate = in_array('afiliado', (array)$user->roles);
            $is_admin = current_user_can('manage_options');

            if (!$is_affiliate && !$is_admin) {
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

            // Special Case: Main Admin Fullscreen
            if ($launch_slug === 'admin-main') {
                if (!current_user_can('manage_options')) {
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
                if (!current_user_can('manage_options')) {
                    wp_redirect(admin_url('wp-login.php'));
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
        return ($ip& $mask) == $subnet;
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
            error_log('ENDTrack Bot detectado: ' . $user_agent);
        }

        // Excluir user agents sospechosos
        if ($should_record && $this->is_suspicious_ua($user_agent)) {
            $should_record = false;
            error_log('ENDTrack UA sospechoso: ' . $user_agent);
        }

        // Excluir IPs de servicios conocidos (Google, etc.)
        if ($should_record && $this->is_service_ip($ip)) {
            $should_record = false;
            error_log('ENDTrack IP de servicio: ' . $ip);
        }

        // 2. IP & GeoIP
        $country_code = '';
        $city = '';
        $details = @json_decode(wp_remote_retrieve_body(wp_remote_get("https://ip.guide/{$ip}", array('timeout' => 2))));
        if ($details) {
            $country_code = $details->location->country ?? '';
            $city = $details->location->city ?? '';
        }

        // 3. UTMs & Referral Params
        $ref = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : 'No tiene afiliado';
        $ref_s = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : 'No tiene fuente';
        $ref_m = isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : 'No tiene medio';
        $ref_c = isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : 'No tiene campaña';
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
            }
            elseif (has_category('registro') || has_category('registroPB') || has_category('gracias_registro')) {
                $tipo_cat = 1;
                $is_relevant_page = true;
            }
            elseif (has_category('seminarios')) {
                $tipo_cat = 3;
                $is_relevant_page = true;
            }
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
                }
                else {
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

        // 6. Record Visit in wp_visitas
        if ($should_record && $id_pag > 0 && $is_relevant_page) {
            $table_visitas = $wpdb->prefix . 'visitas';
            $visit_data = array(
                'ref' => $ref,
                'ref_s' => $ref_s,
                'ref_m' => $ref_m,
                'ref_c' => $ref_c,
                'ip' => $ip,
                'session_id' => $session_user_id,
                'url_actual' => $url_actual,
                'url_anterior' => $url_anterior,
                'pais' => $country_code,
                'ciudad' => $city,
                'id_pag' => $id_pag
            );

            // Populate dynamic columns in wp_visitas
            if ($active_launch) {
                $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
                $col_tipo_cat = "tipo_cat_" . $safe_launch;
                $visit_data[$col_tipo_cat] = $tipo_cat;
            }

            $wpdb->insert($table_visitas, $visit_data);
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
        fecha: "<?php echo current_time('mysql'); ?>",
        tc_total: "<?php echo esc_js($tc_total); ?>",
        tc_product: "<?php echo esc_js($tc_product); ?>",
        tc_account_id: "<?php echo esc_js($tc_account_id); ?>",
        email: "<?php echo esc_js($email); ?>",
        tc_hash: "<?php echo esc_js($thrivecart_hash); ?>",
        tipopag: "<?php echo has_category('registroPB') ? 'pub' : ''; ?>"
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
        var url_ref = urlParams.get('ref');
        var url_s = urlParams.get('utm_source');
        var url_m = urlParams.get('utm_medium');
        var url_c = urlParams.get('utm_campaign');

        if (url_ref) $.cookie('endtrack_ref', url_ref, { expires: 30, path: '/' });
        if (url_s) $.cookie('endtrack_utm_s', url_s, { expires: 30, path: '/' });
        if (url_m) $.cookie('endtrack_utm_m', url_m, { expires: 30, path: '/' });
        if (url_c) $.cookie('endtrack_utm_c', url_c, { expires: 30, path: '/' });

        // Fallback to cookies if missing in URL
        if (endtrack_vars.ref === 'No tiene afiliado' || !url_ref) {
            var c_ref = $.cookie('endtrack_ref');
            if (c_ref) endtrack_vars.ref = c_ref;
        }
        if (endtrack_vars.ref_s === 'No tiene fuente' || !url_s) {
            var c_s = $.cookie('endtrack_utm_s');
            if (c_s) endtrack_vars.ref_s = c_s;
        }
        if (endtrack_vars.ref_m === 'No tiene medio' || !url_m) {
            var c_m = $.cookie('endtrack_utm_m');
            if (c_m) endtrack_vars.ref_m = c_m;
        }
        if (endtrack_vars.ref_c === 'No tiene campaña' || !url_c) {
            var c_c = $.cookie('endtrack_utm_c');
            if (c_c) endtrack_vars.ref_c = c_c;
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
        $(document).on('click', '#add_suscrito', function () {
            // Validation: check privacy policy only if the checkbox exists
            var privacyChecked = true;
            if ($('#form-field-politicapriv').length > 0) {
                privacyChecked = $('#form-field-politicapriv').is(':checked');
            }

            var commsChecked = true;
            if ($('#form-field-comunicaciones').length > 0) {
                commsChecked = $('#form-field-comunicaciones').is(':checked');
            }

            if (privacyChecked && commsChecked) {
                $('.cf7-loader').css('display', 'flex');

                // Fallback selectors for name and email
                var nombre_form = $('#form-field-name').val() || $('input[name="your-name"]').val() || '';
                var correo_form = $('#form-field-email').val() || $('.wpcf7-email').val() || $('input[type="email"]').first().val() || '';

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
                        term: '',
                        placement: '',
                        content: '',
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
                    }
                });
            }
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