<?php
/**
 * Plugin Name: ENDTrack
 * Description: Sistema de afiliados y tracking para Escuela Nómada Digital.
 * Version: 1.0.0
 * Author: END Team
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ENDTRACK_VERSION', '1.0.0');
define('ENDTRACK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENDTRACK_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-activator.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-admin.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-public.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-ajax.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-grafana.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-ai.php';

// --- GitHub Updater (Plugin Update Checker) ---
if (file_exists(ENDTRACK_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php')) {
    require ENDTRACK_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
    $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
        'https://github.com/jcendgit/ENDTRACK', // Cambiar por la URL real de tu repositorio
        __FILE__,
        'ENDTrack'
    );
    // Optional: Set the branch that contains the stable release.
    $myUpdateChecker->getVcsApi()->enableReleaseAssets();
}

class ENDTrack
{

    public function run()
    {
        $activator = new ENDTrack_Activator();
        register_activation_hook(__FILE__, array($activator, 'activate'));

        $admin = new ENDTrack_Admin();
        $admin->init();

        $public = new ENDTrack_Public();
        $public->init();

        $ajax = new ENDTrack_Ajax();
        $ajax->init();
    }
}

$end_track = new ENDTrack();
$end_track->run();
