<?php
/**
 * Plugin Name: ENDTrack
 * Description: Sistema de afiliados y tracking para Escuela Nómada Digital.
 * Version: 1.0.3
 * Author: END Team
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ENDTRACK_VERSION', '1.0.4');
define('ENDTRACK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ENDTRACK_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-activator.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-admin.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-public.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-ajax.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-grafana.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-ai.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/class-endtrack-adspend.php';
require_once ENDTRACK_PLUGIN_DIR . 'includes/endtrack-product-groups.php'; // grupos Principal/Downsell/Cross-sell + AJAX (portado del mu-plugin VBS)

// --- GitHub Updater (Plugin Update Checker) ---
if (file_exists(ENDTRACK_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php')) {
    require ENDTRACK_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    // Support for namespaced v5.x
    if (class_exists('YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/jcendgit/ENDTRACK',
            __FILE__,
            'ENDTrack'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
    }
    // Fallback for non-namespaced v4.x
    elseif (class_exists('Puc_v4_Factory')) {
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/jcendgit/ENDTRACK',
            __FILE__,
            'ENDTrack'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
    }
}

/**
 * Credenciales de la BBDD externa de ventas (pdc_pagos).
 * Prioridad: ajustes del admin (endtrack_texts) → constantes wp-config → defaults.
 * Configurable desde: ENDTrack admin → pestaña Integraciones.
 */
if (!function_exists('endtrack_ext_db_config')) {
    function endtrack_ext_db_config()
    {
        $t = get_option('endtrack_texts', array());
        return array(
            'host' => !empty($t['ext_db_host']) ? $t['ext_db_host'] : (defined('ENDTRACK_EXT_DB_HOST') ? ENDTRACK_EXT_DB_HOST : '165.140.159.205'),
            'name' => !empty($t['ext_db_name']) ? $t['ext_db_name'] : (defined('ENDTRACK_EXT_DB_NAME') ? ENDTRACK_EXT_DB_NAME : 'campusvisualbusinessschool_db'),
            'user' => !empty($t['ext_db_user']) ? $t['ext_db_user'] : (defined('ENDTRACK_EXT_DB_USER') ? ENDTRACK_EXT_DB_USER : 'campusvisualbusinessschool_db'),
            'pass' => !empty($t['ext_db_pass']) ? $t['ext_db_pass'] : (defined('ENDTRACK_EXT_DB_PASS') ? ENDTRACK_EXT_DB_PASS : 'UPse83WfyWcg5'),
        );
    }
}

/**
 * Credenciales de la BBDD externa PDC con soporte por-lanzamiento y Stripe.
 * Base: endtrack_ext_db_config() (Integraciones) + stripe_secret_key de endtrack_texts.
 * Override por lanzamiento: opción endtrack_launch_credentials_<safe_launch>.
 * Usada por /ventas-completas/ (stats-ventas-completas.php) y verify_pdc_stripe.
 */
if (!function_exists('endtrack_get_pdc_credentials')) {
    function endtrack_get_pdc_credentials($launch = '')
    {
        static $cache = array();
        $key = $launch !== '' ? $launch : '__global__';
        if (isset($cache[$key])) return $cache[$key];

        $base = endtrack_ext_db_config();
        $texts = get_option('endtrack_texts', array());
        $creds = array(
            'host'       => $base['host'],
            'user'       => $base['user'],
            'pass'       => $base['pass'],
            'name'       => $base['name'],
            'stripe_key' => isset($texts['stripe_secret_key']) ? $texts['stripe_secret_key'] : '',
        );

        // Per-launch override: option endtrack_launch_credentials_<safe_launch>
        if (!empty($launch) && $launch !== 'legacy') {
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $per_launch = get_option('endtrack_launch_credentials_' . $safe, array());
            if (is_array($per_launch)) {
                foreach (array('host', 'user', 'pass', 'name', 'stripe_key') as $f) {
                    if (!empty($per_launch[$f])) {
                        $creds[$f] = $per_launch[$f];
                    }
                }
            }
        }

        $cache[$key] = $creds;
        return $creds;
    }
}

/**
 * ─── Lanzamiento "Evento" (eventos.pablogiltrader.com) ───────────────────────
 * Lanzamiento especial de venta directa cuyas ventas/visitas NO viven en esta
 * BBDD sino en la de eventos.pablogiltrader.com (mismo servidor). La atribución
 * se guarda en {prefix}tc_ventas.afiliado = ID del afiliado (el ?ref=<id> que
 * comparte). Credenciales en wp-config.php (EVENTOS_DB_*). Solo lectura, PDO con
 * timeout y try/catch para que el panel nunca se rompa (mismo patrón que pdc_pagos).
 */
if (!function_exists('endtrack_eventos_launch_id')) {
    function endtrack_eventos_launch_id()
    {
        return 'evento';
    }
}
if (!function_exists('endtrack_is_eventos_launch')) {
    function endtrack_is_eventos_launch($launch)
    {
        $safe = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $launch));
        return $safe === endtrack_eventos_launch_id();
    }
}
if (!function_exists('endtrack_eventos_pdo')) {
    function endtrack_eventos_pdo()
    {
        if (!defined('EVENTOS_DB_HOST') || !defined('EVENTOS_DB_NAME') || !defined('EVENTOS_DB_USER') || !defined('EVENTOS_DB_PASS')) {
            return null;
        }
        try {
            return new PDO(
                'mysql:host=' . EVENTOS_DB_HOST . ';dbname=' . EVENTOS_DB_NAME . ';charset=utf8mb4',
                EVENTOS_DB_USER,
                EVENTOS_DB_PASS,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8)
            );
        } catch (Exception $e) {
            error_log('[ENDTRACK_EVENTOS] Conexión BBDD eventos falló: ' . $e->getMessage());
            return null;
        }
    }
}
if (!function_exists('endtrack_eventos_prefix')) {
    function endtrack_eventos_prefix()
    {
        return defined('EVENTOS_DB_PREFIX') ? EVENTOS_DB_PREFIX : '5MwJw_';
    }
}
/**
 * Ventas del evento de un afiliado, en el MISMO formato que $ext_af_sales del panel:
 * cada fila ['mail','importe','producto','fecha']. Excluye devoluciones. Orden por fecha desc.
 */
if (!function_exists('endtrack_eventos_sales')) {
    function endtrack_eventos_sales($user_id)
    {
        $pdo = endtrack_eventos_pdo();
        if (!$pdo) {
            return array();
        }
        $t = endtrack_eventos_prefix() . 'tc_ventas';
        try {
            $stmt = $pdo->prepare(
                "SELECT mail, IFNULL(precio,0) AS importe, producto, fecha_venta AS fecha
                 FROM `{$t}`
                 WHERE afiliado = ? AND (devolucion IS NULL OR devolucion = 0)
                 ORDER BY fecha_venta DESC"
            );
            $stmt->execute(array((string) intval($user_id)));
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[ENDTRACK_EVENTOS] Query ventas falló: ' . $e->getMessage());
            return array();
        }
    }
}
/**
 * Visitas del evento de un afiliado (tabla {prefix}visitas, col ref = ID afiliado).
 * Devuelve ['total' => int (IPs únicas en rango), 'daily' => [Y-m-d => int]].
 */
if (!function_exists('endtrack_eventos_visits')) {
    function endtrack_eventos_visits($user_id, $date_from, $date_to)
    {
        $out = array('total' => 0, 'daily' => array());
        $pdo = endtrack_eventos_pdo();
        if (!$pdo) {
            return $out;
        }
        $t = endtrack_eventos_prefix() . 'visitas';
        try {
            $uid = (string) intval($user_id);
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ip) FROM `{$t}` WHERE ref = ? AND DATE(fecha) BETWEEN ? AND ?");
            $stmt->execute(array($uid, $date_from, $date_to));
            $out['total'] = (int) $stmt->fetchColumn();

            $stmt2 = $pdo->prepare("SELECT DATE(fecha) AS day, COUNT(DISTINCT ip) AS c FROM `{$t}` WHERE ref = ? AND DATE(fecha) BETWEEN ? AND ? GROUP BY DATE(fecha)");
            $stmt2->execute(array($uid, $date_from, $date_to));
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['daily'][$r['day']] = (int) $r['c'];
            }
        } catch (Exception $e) {
            error_log('[ENDTRACK_EVENTOS] Query visitas falló: ' . $e->getMessage());
        }
        return $out;
    }
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

        $adspend = new ENDTrack_AdSpend();
        $adspend->init();
    }
}

$end_track = new ENDTrack();
$end_track->run();