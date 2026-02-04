<?php

class ENDTrack_Public
{

    public function init()
    {
        add_action('wp_head', array($this, 'tracking_code'));
        add_action('wp_head', array($this, 'inject_ai_css'), 999);
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
    }

    public function track_last_login($user_login, $user)
    {
        update_user_meta($user->ID, 'endtrack_last_login', current_time('mysql'));
    }

    public function register_rewrites()
    {
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
            $is_affiliate = in_array('afiliado', (array) $user->roles);
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
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'facebookexternalhit',
            'twitterbot',
            'rogerbot',
            'linkedinbot',
            'embedly',
            'quora link preview',
            'showyoubot',
            'outbrain',
            'pinterest',
            'developers.google.com',
            'google-adwords',
            'google-ads',
            'googleads',
            'google chat',
            'hangouts',
            'publimillenium',
            'publi miles',
            'adsystem',
            'doubleclick',
            'spider',
            'crawler',
            'bot',
            'crawl',
            'scraper',
            'checker',
            'wget',
            'curl',
            'python',
            'java',
            'go-http-client',
            'okhttp',
            'apache-httpclient',
            'node',
            'axios',
            'requests',
            'urllib',
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
            'uptimerobot',
            'pingdom',
            'monitis',
            'hetrixtools',
            'statuscake'
        );

        $userAgent = strtolower($userAgent);
        foreach ($bots as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                return true;
            }
        }

        $botPatterns = array(
            '/bot/i',
            '/spider/i',
            '/crawler/i',
            '/^$/i',
            '/http/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/java/i',
            '/go-http/i',
            '/okhttp/i',
            '/apache/i',
            '/php/i'
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

    private function is_service_ip($ip)
    {
        // Simple range check for common service IPs if needed, or leave minimal
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
        if ($this->is_bot($user_agent) || $this->is_suspicious_ua($user_agent)) {
            $should_record = false;
        }
        // Disabled administrator exclusion to facilitate testing as requested
        // if (current_user_can('administrator') || current_user_can('edit_posts')) {
        //     $should_record = false;
        // }
        if ($this->is_admin_route($url_actual)) {
            return;
        }

        // 2. IP & GeoIP
        $country_code = null;
        $city = null;
        // if (file_exists(get_stylesheet_directory() . '/vendor/autoload.php')) {
        //     require_once(get_stylesheet_directory() . '/vendor/autoload.php');
        //     try {
        //         if (class_exists('GeoIp2\Database\Reader') && file_exists(get_stylesheet_directory() . '/GeoLite2-Country.mmdb')) {
        //             $reader = new \GeoIp2\Database\Reader(get_stylesheet_directory() . '/GeoLite2-Country.mmdb');
        //             $record = $reader->country($ip);
        //             $country_code = $record->country->isoCode;
        //         }
        //     } catch (Exception $e) {
        //     }
        // }

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
            } elseif (has_category('registro') || has_category('registroPB') || has_category('gracias_registro')) {
                $tipo_cat = 1;
                $is_relevant_page = true;
            } elseif (has_category('seminarios')) {
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

        // 6. Record Visit in wp_visitas
        if ($should_record && $id_pag > 0) {
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
                fecha: "<?php echo date('Y-m-d'); ?>",
                tc_total: "<?php echo esc_js($tc_total); ?>",
                tc_product: "<?php echo esc_js($tc_product); ?>",
                tc_account_id: "<?php echo esc_js($tc_account_id); ?>"
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
                <?php if ($tipo_cat == 2) { ?>
                    var email = '<?php echo esc_js($email); ?>';
                    var tc_hash = '<?php echo esc_js($thrivecart_hash); ?>';

                    if (email !== '') {
                        $.ajax({
                            type: 'POST',
                            url: endtrack_vars.ajaxurl,
                            data: {
                                action: 'endtrack_datos',
                                afiliado: endtrack_vars.ref,
                                nombre: tc_hash,
                                correo: email,
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
                <?php } ?>

                // Handle 'venta' logic
                <?php if ($tipo_cat == 2) { ?>
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
                <?php } ?>

                // Handle 'registroPB' or standard
                <?php
                $tipopag = has_category('registroPB') ? 'pub' : '';
                ?>
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
                                tipo_pag: '<?php echo $tipopag; ?>',
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
}
