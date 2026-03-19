<?php
/**
 * Standalone Full-Screen Admin Dashboard for /endtrack
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ensure the user is still admin
if (!current_user_can('manage_options')) {
    wp_die('No tienes permiso para acceder a esta página.');
}

// Variables for admin-dashboard.php
$is_standalone = true;

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ENDTrack | Administración</title>
    <?php wp_enqueue_style('dashicons'); ?>
    <?php wp_head(); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        body {
            background: #F8FAFC !important;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* Remove WP Margin if admin bar is present but we want clean view */
        html {
            margin-top: 0 !important;
        }

        #wpadminbar {
            display: none !important;
        }

        .endtrack-standalone-wrap {
            padding: 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .back-to-wp {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e293b;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            z-index: 1000;
            opacity: 0.5;
            transition: opacity 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .back-to-wp:hover {
            opacity: 1;
            color: white;
        }
    </style>
</head>

<body>

    <div class="endtrack-standalone-wrap">
        <?php
        // Load the main dashboard content
        include ENDTRACK_PLUGIN_DIR . 'templates/admin-dashboard.php';
        ?>
    </div>

    <a href="<?php echo admin_url(); ?>" class="back-to-wp" title="Volver al Escritorio de WordPress">
        <span class="dashicons dashicons-wordpress"></span> Volver a WordPress
    </a>

    <?php wp_footer(); ?>
</body>

</html>