<?php
// templates/dashboard-view.php

// Sanitize output variables
$launch_display_name = isset($launch_slug) ? ucfirst(str_replace('-', ' ', $launch_slug)) : 'Dashboard';
// Ensure we have the URL
$found_url = '';
if (!empty($launch_slug)) {
    // Re-fetch logic or rely on query var passed logic if available, 
    // but typically we might need to fetch the URL again if not passed in global scope.
    // However, in our controller we didn't pass variables explicitly to the include scope other than what's available.
    // Let's assume the controller doing the include has not set $found_url. 
    // We should strictly look it up.

    // Actually, in the controller (handle_dashboard_redirect), we didn't set $found_url or $launch_display_name. 
    // We need to fetch them here.

    $launch_slug = get_query_var('endtrack_launch_dashboard');
    $launches = get_option('endtrack_launches', array());
    $found_url = '';
    $launch_display_name = $launch_slug;

    // Find the real name and URL
    $dashboards = get_option('endtrack_launch_dashboards', array());

    // We need to match the slug to the launch name to find the URL in $dashboards (which is keyed by safe_name usually, or we need to check how it was saved).
    // In handle_create_launch: $dashboards[$safe_launch_name] = $url;
    // But we only have the slug here.
    // We need to find the launch that matches this slug.

    $target_launch = null;
    foreach ($launches as $launch) {
        $l_slug = sanitize_title($launch); // This is how the slug is usually generated
        if ($l_slug === $launch_slug) {
            $target_launch = $launch;
            $launch_display_name = $launch;
            break;
        }
    }

    if ($target_launch) {
        $safe_launch_name = preg_replace('/[^a-zA-Z0-9_]/', '', $target_launch);
        if (isset($dashboards[$safe_launch_name])) {
            $found_url = $dashboards[$safe_launch_name];

            // Force HTTPS if the current page is HTTPS
            if (is_ssl() && strpos($found_url, 'http://') === 0) {
                $found_url = str_replace('http://', 'https://', $found_url);
            }

            // Append kiosk mode for cleaner view in anonymous access
            $found_url = add_query_arg('kiosk', '', $found_url);

            // Fix the domain if it's using the IP (Legacy fix)
            /*
            $wrong_base = '194.163.129.230:3000';
            if (strpos($found_url, $wrong_base) !== false) {
                 // Logic to replace or warn
            }
            */
        }
    }
}

// Logo Logic
$texts = get_option('endtrack_texts', array());
$logo_url = isset($texts['logo_admin_panel']) && !empty($texts['logo_admin_panel']) ? $texts['logo_admin_panel'] : '';

// Force HTTPS on logo too
if (is_ssl() && strpos($logo_url, 'http://') === 0) {
    $logo_url = str_replace('http://', 'https://', $logo_url);
}

// Add cache buster to logo
$logo_url = add_query_arg('v', '1.0.1', $logo_url);

if (empty($found_url)) {
    wp_die('Dashboard no encontrado para este lanzamiento.', 'Dashboard no encontrado', array('response' => 404));
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard: <?php echo esc_html($launch_display_name); ?> - ENDTrack</title>
    <?php wp_head(); ?>
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f2f5;
        }

        .et-header {
            height: 70px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .et-header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .et-logo {
            height: 32px;
            width: auto;
        }

        .et-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }

        .et-actions {
            display: flex;
            gap: 10px;
        }

        .et-btn {
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .et-btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .et-btn-secondary:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .et-btn-primary {
            background: #4f46e5;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
        }

        .et-btn-primary:hover {
            background: #4338ca;
        }

        .et-iframe-container {
            position: absolute;
            top: 70px;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: calc(100% - 70px);
            background: #f8fafc;
        }

        .et-iframe {
            width: 100%;
            height: 100%;
            border: none;
            position: relative;
            z-index: 1;
        }

        /* Mixed Content Warning */
        .et-mixed-content-warning {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            z-index: 0;
        }

        .et-warning-icon {
            color: #f59e0b;
            margin-bottom: 15px;
        }
    </style>
</head>

<body>

    <div class="et-header">
        <div class="et-header-left">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="ENDTrack Logo" class="et-logo skip-lazy no-lazy"
                    data-no-lazy="1" loading="eager">
            <?php else: ?>
                <div style="font-weight: 800; color: #4f46e5; font-size: 20px;">ENDTrack</div>
            <?php endif; ?>
            <h1 class="et-title">Lanzamiento: <?php echo esc_html($launch_display_name); ?></h1>
        </div>
        <div class="et-actions">
            <!-- <a href="<?php echo esc_url($found_url); ?>" target="_blank" class="et-btn et-btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
                Abrir Externamente
            </a> -->
            <a href="<?php echo admin_url('admin.php?page=endtrack'); ?>" class="et-btn et-btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                Volver al Menú
            </a>
        </div>
    </div>

    <div class="et-iframe-container">
        <div class="et-mixed-content-warning">
            <div class="et-warning-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z">
                    </path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3 style="margin-top:0; color:#111827;">Contenido Bloqueado por Seguridad</h3>
            <p style="color:#6b7280; margin-bottom:20px; line-height:1.5; font-size: 14px;">
                Tu navegador está impidiendo cargar Grafana porque usa una conexión no segura (HTTP) dentro de esta
                página (HTTPS).
            </p>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <a href="<?php echo esc_url($found_url); ?>" target="_blank" class="et-btn et-btn-primary"
                    style="justify-content: center; width: 100%; box-sizing: border-box;">
                    Abrir Grafana en nueva pestaña
                </a>
                <p style="font-size: 12px; color: #9ca3af; margin: 0;">O permite el "Contenido inseguro" en los ajustes
                    del candado de tu navegador.</p>
            </div>
        </div>
        <?php
        $is_https_page = is_ssl();
        $is_http_iframe = (strpos($found_url, 'http://') === 0);

        if ($is_https_page && $is_http_iframe): ?>
            <div class="et-mixed-content-warning">
                <div class="et-warning-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z">
                        </path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                </div>
                <h3 style="margin-top:0; color:#111827;">Contenido Bloqueado por Seguridad</h3>
                <p style="color:#6b7280; margin-bottom:20px; line-height:1.5; font-size: 14px;">
                    Tu navegador está impidiendo cargar Grafana porque usa una conexión no segura (HTTP) dentro de esta
                    página (HTTPS).
                </p>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="<?php echo esc_url($found_url); ?>" target="_blank" class="et-btn et-btn-primary"
                        style="justify-content: center; width: 100%; box-sizing: border-box;">
                        Abrir Grafana en nueva pestaña
                    </a>
                    <p style="font-size: 12px; color: #9ca3af; margin: 0;">O permite el "Contenido inseguro" en los ajustes
                        del candado de tu navegador.</p>
                </div>
            </div>
        <?php else: ?>
            <iframe src="<?php echo esc_url($found_url); ?>" class="et-iframe" title="Grafana Dashboard"
                allowfullscreen></iframe>
        <?php endif; ?>
    </div>

    <?php wp_footer(); ?>
</body>

</html>