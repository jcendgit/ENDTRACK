<?php
/**
 * Template Name: ENDTrack Dashboard Layout
 */

if (!defined('ABSPATH')) {
    exit;
}

// Context fallback if not set by override_template or shortcode
if (!isset($is_admin_panel)) {
    $is_admin_panel = is_page('endtrack-panel-admin-afiliado');
}
if (!isset($is_shortcode)) {
    $is_shortcode = false;
}

global $post;
$slug = ($post) ? $post->post_name : '';

// Load Settings
$texts = get_option('endtrack_texts', array());

// Logo Logic
$logo_key = $is_admin_panel ? 'logo_admin_panel' : 'logo_user_panel';
$logo_url = isset($texts[$logo_key]) && !empty($texts[$logo_key]) ? $texts[$logo_key] : '';

global $current_user;
wp_get_current_user();

// Impersonation Logic
$is_impersonating = false;
$impersonated_user = null;
if (isset($_GET['impersonate']) && current_user_can('manage_options')) {
    $impersonate_id = intval($_GET['impersonate']);
    $impersonated_user = get_userdata($impersonate_id);
    if ($impersonated_user) {
        $is_impersonating = true;
    }
}

$display_user = $is_impersonating ? $impersonated_user : $current_user;

$welcome_title_key = $is_admin_panel ? 'welcome_title' : 'panel_welcome_title';
$welcome_subtitle_key = $is_admin_panel ? 'welcome_subtitle' : 'panel_welcome_subtitle';

$welcome_title = isset($texts[$welcome_title_key]) ? $texts[$welcome_title_key] : ($is_admin_panel ? 'Hola {user}, bienvenid@' : 'Hola {user}, bienvenid@ a tu área de afiliado');
$welcome_subtitle = isset($texts[$welcome_subtitle_key]) ? $texts[$welcome_subtitle_key] : ($is_admin_panel ? 'Tu email: {email}' : 'Tu email de afiliado: {email}');

$placeholders = array('{user}', '{email}');
$replacements = array($display_user->display_name, $display_user->user_email);

$welcome_title = str_replace($placeholders, $replacements, $welcome_title);
$welcome_subtitle = str_replace($placeholders, $replacements, $welcome_subtitle);

// Nav Items
if ($is_admin_panel) {
    $nav_items = [
        'dashboard' => ['icon' => 'fa-chart-line', 'label' => 'Dashboard'], // Stats Overview
        // 'registrados' => ['icon' => 'fa-users', 'label' => 'Registrados'], // Merged into Dashboard or separate? Request said "Tabs: Registrados, leads, ventas". 
        // Let's keep a simple Dashboard structure that loads tabs internally or separate sidebar items.
        // User request: "En endtrack-panel-admin-afiliado que sean pestañas Registrados, leads, y ventas"
        // This implies the SIDEBAR might effectively be these Tabs, OR there is one page with internal tabs.
        // The screenshot shows Sidebar: Dashboard, Reports, Campaigns...
        // Let's map the user's "Tabs" to Sidebar items for the "Modern Dashboard" look.
        'registrados' => ['icon' => 'fa-users', 'label' => 'Registrados'],
        'leads' => ['icon' => 'fa-user-plus', 'label' => 'Leads'],
        'ventas' => ['icon' => 'fa-shopping-cart', 'label' => 'Ventas'],
        'pagos' => ['icon' => 'fa-money-check-alt', 'label' => 'Pagos'],
    ];
} else {
    // User Panel
    // "pestañas también: creatividades, asignación... texto que has quitado"
    $txt_links = isset($texts['panel_txt_links']) ? $texts['panel_txt_links'] : 'Enlaces';
    $nav_items = [
        'dashboard' => ['icon' => 'fa-home', 'label' => 'Dashboard'],
        'ventas' => ['icon' => 'fa-shopping-cart', 'label' => 'Ventas'],
        'links' => ['icon' => 'fa-link', 'label' => $txt_links],
        'creatividades' => ['icon' => 'fa-images', 'label' => 'Creatividades'],
        'asignacion' => ['icon' => 'fa-clipboard-list', 'label' => 'Asignación'],
        'pagos' => ['icon' => 'fa-history', 'label' => 'Pagos'],
    ];
}

// Active Tab
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

?>
<?php if (!$is_shortcode): ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php wp_title(); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <?php wp_head(); ?>
    <?php endif; ?>
    <style>
        :root {
            --sidebar-width: 260px;
            --bg-color: #f0f2f5;
            --text-color: #1d1d1f;
            --primary-color:
                <?php echo $is_admin_panel ? '#4F46E5' : '#000'; ?>
            ;
            --active-bg: rgba(255, 255, 255, 0.2);
            --color-visits: #0EA5E9;
            --color-leads: #6366F1;
            --color-conversion: #F59E0B;
            --color-sales: #10B981;
            --color-payments: #14B8A6;

            /* Liquid Glass Tokens */
            --glass-bg: rgba(255, 255, 255, 0.4);
            --glass-border: rgba(255, 255, 255, 0.5);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
            --glass-blur: blur(25px) saturate(180%);
            --glass-inset: inset 0 0 12px rgba(255, 255, 255, 0.3);
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(at 0% 0%, rgba(79, 70, 229, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(14, 165, 233, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(16, 185, 129, 0.08) 0px, transparent 50%),
                radial-gradient(at 0% 100%, rgba(245, 158, 11, 0.08) 0px, transparent 50%),
                #f8fafc;
            color: var(--text-color);
            display: flex;
            min-height: 100vh;
            background-attachment: fixed;
        }

        /* Sidebar */
        .dashboard-sidebar {
            width: var(--sidebar-width);
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            padding: 25px;
            box-sizing: border-box;
            z-index: 1000;
            box-shadow: 4px 0 32px rgba(0, 0, 0, 0.05);
        }

        .branding {
            margin-bottom: 45px;
            padding-left: 5px;
        }

        .branding img {
            max-width: 100%;
            height: auto;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        }

        .nav-menu {
            list-style: none;
            padding: 0;
            margin: 0;
            flex: 1;
        }

        .nav-item {
            margin-bottom: 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 14px 18px;
            color: #4b4b4b !important;
            text-decoration: none;
            border-radius: 16px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            font-size: 15px;
            border: 1px solid transparent;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateX(5px);
        }

        .nav-link.active {
            background: white;
            color: var(--primary-color) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-color: rgba(255, 255, 255, 0.8);
        }

        .nav-link i {
            width: 24px;
            margin-right: 12px;
            font-size: 18px;
            opacity: 0.8;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding-top: 20px;
        }

        /* Main Content */
        .dashboard-main {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 50px;
            box-sizing: border-box;
            position: relative;
        }

        .mobile-toggle {
            display: none;
            cursor: pointer;
            color: var(--text-color);
        }

        h1.page-title {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 35px;
            color: #1d1d1f;
        }

        /* Liquid Glass Cards */
        .card,
        .welcome-section,
        .filter-bar {
            background: var(--glass-bg) !important;
            backdrop-filter: var(--glass-blur) !important;
            -webkit-backdrop-filter: var(--glass-blur) !important;
            border-radius: 28px !important;
            padding: 30px !important;
            box-shadow: var(--glass-shadow), var(--glass-inset) !important;
            border: 1px solid var(--glass-border) !important;
            margin-bottom: 30px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        /* Colorful Stat Cards with Glass Effect */
        .stat-card {
            border: 1px solid rgba(255, 255, 255, 0.6) !important;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 160px;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.4) 0%, rgba(255, 255, 255, 0) 100%);
            z-index: 0;
            pointer-events: none;
        }

        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 12px 40px rgba(31, 38, 135, 0.25) !important;
        }

        .stat-card h4 {
            color: rgba(0, 0, 0, 0.6) !important;
            font-size: 13px !important;
            margin-bottom: 8px !important;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700 !important;
            z-index: 1;
        }

        .stat-card .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: #1d1d1f !important;
            letter-spacing: -1px;
            z-index: 1;
        }

        .stat-card .stat-icon {
            position: absolute;
            right: -10px;
            bottom: -10px;
            font-size: 80px;
            opacity: 0.1;
            color: black !important;
            z-index: 0;
            transition: all 0.4s ease;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.1) rotate(-5deg);
            opacity: 0.15;
        }

        /* Modern Glass Metric Overlays */
        .stat-card-visits {
            background: rgba(14, 165, 233, 0.15) !important;
        }

        .stat-card-leads {
            background: rgba(99, 102, 241, 0.15) !important;
        }

        .stat-card-conversion {
            background: rgba(245, 158, 11, 0.15) !important;
        }

        .stat-card-sales {
            background: rgba(16, 185, 129, 0.15) !important;
        }

        .stat-card-payments {
            background: rgba(20, 184, 166, 0.15) !important;
        }

        .stat-card-visits .stat-value {
            color: #0369a1 !important;
        }

        .stat-card-leads .stat-value {
            color: #4338ca !important;
        }

        .stat-card-conversion .stat-value {
            color: #b45309 !important;
        }

        .stat-card-sales .stat-value {
            color: #047857 !important;
        }

        .stat-card-payments .stat-value {
            color: #0f766e !important;
        }

        /* Launch Switcher Liquid Design */
        .launch-switcher {
            background: rgba(255, 255, 255, 0.5) !important;
            backdrop-filter: blur(15px);
            padding: 10px 20px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .launch-switcher label {
            color: #1d1d1f !important;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .launch-switcher select {
            color: var(--primary-color) !important;
            font-weight: 700 !important;
            font-size: 15px !important;
            border: none;
            background: transparent;
            cursor: pointer;
            padding: 5px 12px;
            font-family: inherit;
        }

        /* Animations */
        @keyframes float {
            0% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-10px);
            }

            100% {
                transform: translateY(0px);
            }
        }

        .dashboard-content {
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .chart-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 40px;
        }

        .chart-container .card {
            margin-bottom: 0;
        }

        @media (max-width: 1200px) {

            .stats-grid,
            .chart-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {

            .stats-grid,
            .chart-container {
                grid-template-columns: 1fr;
            }

            .dashboard-sidebar {
                transform: translateX(-100%);
            }

            .dashboard-sidebar.open {
                transform: translateX(0);
            }

            .dashboard-main {
                margin-left: 0;
                padding: 25px;
            }

            .mobile-toggle {
                display: block;
                margin-bottom: 25px;
                font-size: 28px;
            }
        }
    </style>
</head>

<?php if (!$is_shortcode): ?>

    <body <?php body_class($is_admin_panel ? 'is-admin-dashboard' : ''); ?>>
        <!-- Abstract Background Blobs -->
        <div
            style="position: fixed; top: -10%; left: -10%; width: 40%; height: 40%; background: radial-gradient(circle, rgba(79, 70, 229, 0.1) 0%, transparent 70%); z-index: -1; filter: blur(80px); animation: float 25s infinite alternate;">
        </div>
        <div
            style="position: fixed; bottom: -10%; right: -10%; width: 50%; height: 50%; background: radial-gradient(circle, rgba(16, 185, 129, 0.08) 0%, transparent 70%); z-index: -1; filter: blur(100px); animation: float 30s infinite alternate-reverse;">
        </div>
        <div
            style="position: fixed; top: 40%; right: 10%; width: 30%; height: 30%; background: radial-gradient(circle, rgba(245, 158, 11, 0.05) 0%, transparent 70%); z-index: -1; filter: blur(70px); animation: float 22s infinite alternate;">
        </div>
    <?php endif; ?>

    <div class="dashboard-sidebar">
        <div class="branding">
            <?php if ($logo_url): ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="Logo">
            <?php else: ?>
                <h3>ENDTrack</h3>
            <?php endif; ?>

            <?php if ($is_admin_panel): ?>
                <div
                    style="background: #4F46E5; color: white; padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; display: inline-block; margin-top: 10px;">
                    <i class="fas fa-shield-alt" style="margin-right: 4px;"></i> Modo Administrador
                </div>
            <?php endif; ?>
        </div>

        <ul class="nav-menu">
            <?php foreach ($nav_items as $key => $item):
                $active_class = ($active_tab == $key) ? 'active' : '';
                $url = add_query_arg('tab', $key);
                ?>
                <li class="nav-item">
                    <a href="<?php echo esc_url($url); ?>" class="nav-link <?php echo $active_class; ?>">
                        <i class="fas <?php echo $item['icon']; ?>"></i>
                        <?php echo esc_html($item['label']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-footer">
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="<?php echo wp_logout_url(home_url()); ?>" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo home_url('/contacto/'); ?>" class="nav-link">
                        <i class="fas fa-headset"></i> Soporte
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="dashboard-main">
        <?php if ($is_impersonating): ?>
            <div
                style="background: #fff3cd; color: #856404; padding: 15px; text-align: center; border-radius: 12px; border: 1px solid #ffeeba; margin-bottom: 25px; display: flex; align-items: center; justify-content: center; gap: 15px; flex-wrap: wrap;">
                <div>
                    <i class="fas fa-exclamation-triangle" style="margin-right: 10px;"></i>
                    Estás viendo al usuario <strong><?php echo esc_html($display_user->display_name); ?></strong> desde
                    administrador.
                </div>
                <a href="/endtrack-panel-admin-afiliado"
                    style="background: #856404; color: #fff; padding: 8px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: opacity 0.2s;">
                    Volver al panel general
                </a>
            </div>
        <?php endif; ?>
        <?php if ($is_admin_panel): ?>
            <div
                style="height: 4px; background: #4F46E5; position: fixed; top: 0; left: var(--sidebar-width); right: 0; z-index: 100;">
            </div>
        <?php endif; ?>

        <div class="mobile-toggle" onclick="document.querySelector('.dashboard-sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </div>

        <!-- Top Header with Title and Launch Selector -->
        <div class="dashboard-header"
            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h1 class="page-title" style="margin:0;"><?php echo esc_html($nav_items[$active_tab]['label']); ?></h1>
            <?php /* Launch switcher logic continues below */ ?>

            <!-- Global Launch Selector -->
            <?php
            $launches = get_option('endtrack_launches', array());
            // Filter launches by visibility - only show visible ones in dropdown
            $visibility_map = get_option('endtrack_launch_visibility', array());
            $launches = array_filter($launches, function ($launch) use ($visibility_map) {
                return isset($visibility_map[$launch]) && $visibility_map[$launch] === true;
            });
            $launches = array_values($launches);
            $active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : (!empty($launches) ? $launches[0] : '');

            if (!empty($launches)):
                ?>
                <div class="launch-switcher">
                    <form method="GET" style="margin:0; display:flex; align-items:center;">
                        <!-- Keep current page params -->
                        <?php foreach ($_GET as $key => $val):
                            if ($key == 'launch')
                                continue; ?>
                            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>">
                        <?php endforeach; ?>

                        <label for="launch_select">
                            <i class="fas fa-rocket"></i> LANZAMIENTO:
                        </label>
                        <select name="launch" id="launch_select" onchange="this.form.submit()">
                            <option value="legacy" <?php selected($active_launch, 'legacy'); ?>>
                                Lanzamientos Anteriores
                            </option>
                            <?php foreach ($launches as $launch): ?>
                                <option value="<?php echo esc_attr($launch); ?>" <?php selected($active_launch, $launch); ?>>
                                    <?php echo ucfirst($launch); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- Welcome Message Section -->
        <div class="welcome-section">
            <h2 style="margin:0; color: #000; font-size: 20px;"><?php echo esc_html($welcome_title); ?></h2>
            <p style="margin: 5px 0 0; color: #666; font-size: 14px;"><?php echo esc_html($welcome_subtitle); ?></p>
        </div>

        <div class="dashboard-content">
            <?php
            // Pass $active_launch to views logic
            set_query_var('current_launch', $active_launch); // Helper for templates if needed, or they use $_GET
            
            if ($is_admin_panel) {
                include ENDTRACK_PLUGIN_DIR . 'templates/views/admin-panel-content.php';
            } else {
                include ENDTRACK_PLUGIN_DIR . 'templates/views/user-panel-content.php';
            }
            ?>
        </div>
    </div>

    <?php wp_footer(); ?>
</body>

</html>