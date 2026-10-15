<?php
// User Panel Content Handler
// Handles tabs: dashboard, links, creatividades, asignacion

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb, $display_user;
$user_id = (isset($display_user) && isset($display_user->ID)) ? $display_user->ID : get_current_user_id();
$texts = get_option('endtrack_texts', array());
$launches = get_option('endtrack_launches', array());
$launches = array_values($launches);

// Default for affiliate panel is the admin-configured launch (set from admin panel),
// falling back to the last created launch.
$user_panel_default = get_option('endtrack_user_panel_default_launch', '');
if (!empty($user_panel_default) && in_array($user_panel_default, $launches, true)) {
    $default_launch = $user_panel_default;
} else {
    $default_launch = !empty($launches) ? end($launches) : '';
}
$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : $default_launch;

// Solo lanzamientos con "Visible" activado: si se pide uno oculto (o el de por defecto
// está oculto), se abre el que corresponde. Misma función que el selector de arriba.
if (function_exists('endtrack_afiliado_lanzamientos')) {
    $et_vis = endtrack_afiliado_lanzamientos(isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : '');
    $active_launch = $et_vis['activo'];
}

// Lanzamiento especial "Evento" (eventos.pablogiltrader.com): se añade al selector por
// código (no vive en endtrack_launches) y nunca es el lanzamiento por defecto.
if (function_exists('endtrack_eventos_launch_id') && !in_array(endtrack_eventos_launch_id(), $launches, true)) {
    $launches[] = endtrack_eventos_launch_id();
}
$is_eventos_launch = function_exists('endtrack_is_eventos_launch') && endtrack_is_eventos_launch($active_launch);

// Per-launch helper: reads meta key "<base>_<safe_launch>", falling back to legacy "<base>" only for the first launch ever created.
if (!function_exists('endtrack_get_user_meta_by_launch')) {
    function endtrack_get_user_meta_by_launch($user_id, $base_key, $launch, $launches) {
        if (empty($launch) || $launch === 'legacy') {
            return get_user_meta($user_id, $base_key, true);
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
        $val = get_user_meta($user_id, $base_key . '_' . $safe, true);
        if ($val === '' && !empty($launches) && $launch === $launches[0]) {
            $val = get_user_meta($user_id, $base_key, true);
        }
        return $val;
    }
}

// Selector Removed (Global in Layout)
$comm_rate = endtrack_get_user_meta_by_launch($user_id, 'endtrack_commission_rate', $active_launch, $launches);
if ($comm_rate === '') {
    // Fallback: comisión general del lanzamiento (fijada por admin); si no, 20%.
    $_safe_comm = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $_launch_comm = get_option('endtrack_launch_commission_' . $_safe_comm, '');
    $comm_rate = ($_launch_comm !== '' && is_numeric($_launch_comm)) ? $_launch_comm : 20;
}
$comm_factor = floatval($comm_rate) / 100;

// ─── External DB: pdc_pagos data for this affiliate ──────────────────────────
// Credenciales desde Integraciones (endtrack_texts) o constantes wp-config,
// igual que stats-ventas-fullscreen. Los defaults hardcodeados apuntaban a la
// BBDD de otra web y el panel no encontraba las ventas.
$__ext = endtrack_ext_db_config();
$ext_host = $__ext['host'];
$ext_user = $__ext['user'];
$ext_pass = $__ext['pass'];
$ext_name = $__ext['name'];

$_safe_l_ext = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
$_opt_key_ext = 'endtrack_ext_products_' . $_safe_l_ext;
$_products_ext = json_decode(get_option($_opt_key_ext, '[]'), true);
if (!is_array($_products_ext))
    $_products_ext = [];

$ext_af_sales = [];   // ['mail','importe','producto','fecha']
$ext_af_db_error = null;
$use_ext_af_db = !empty($_products_ext) && !empty($active_launch);

if ($use_ext_af_db) {
    // PERF: cachea 5 min las ventas externas (pdc_pagos, servidor externo). Evita abrir
    // una conexión cross-server + 2 queries con JOIN en CADA carga del panel (y el timeout
    // de 8s si ese servidor está lento/caído). Clave por afiliado+lanzamiento.
    $_ext_cache_key = 'endtrack_extsales_' . $user_id . '_' . $_safe_l_ext;
    $_ext_cached = get_transient($_ext_cache_key);
    if (is_array($_ext_cached) && array_key_exists('sales', $_ext_cached)) {
        $ext_af_sales = $_ext_cached['sales'];
        $use_ext_af_db = $_ext_cached['use'];
    } else {
    $_tabla_datos_ext = $wpdb->prefix . 'datos';
    $_col_ext = ($active_launch == 'legacy') ? 'primer_reg' : 'primer_reg_' . $_safe_l_ext;

    // Leads atribuidos a este afiliado (fuente de verdad de atribución)
    $_attributed = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT correo FROM {$_tabla_datos_ext}
         WHERE afiliado = %d AND {$_col_ext} = 1
           AND correo != '' AND correo IS NOT NULL",
        $user_id
    ));
    $_attributed_set = array_flip(array_map('strtolower', array_map('trim', $_attributed)));

    try {
        $_pdo = new PDO(
            "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
            $ext_user,
            $ext_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );

        $_ph_prods = implode(',', array_fill(0, count($_products_ext), '?'));

        // Fuente 1: compradores que registraron como lead de este afiliado
        if (!empty($_attributed)) {
            $_ph_emails = implode(',', array_fill(0, count($_attributed), '?'));
            $_params1 = array_merge(
                array_map('strtolower', array_map('trim', $_attributed)),
                $_products_ext
            );
            $_stmt1 = $_pdo->prepare("
                SELECT
                    LOWER(TRIM(U.mail)) AS mail,
                    IFNULL(A.importe, 0) AS importe,
                    A.producto,
                    A.fecha
                FROM pdc_pagos A
                INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                WHERE LOWER(TRIM(U.mail)) IN ({$_ph_emails})
                  AND A.ID_producto IN ({$_ph_prods})
                  AND A.estado IN ('Completado','Compra')
                  AND (A.no_comision_afiliado = 0 OR A.no_comision_afiliado IS NULL)
                ORDER BY A.fecha DESC
            ");
            $_stmt1->execute($_params1);
            $ext_af_sales = $_stmt1->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fuente 2: compradores con pdc_pagos.ref = WP user ID de este afiliado
        // que no están ya en wp_datos como leads de este afiliado
        $_stmt2 = $_pdo->prepare("
            SELECT
                LOWER(TRIM(U.mail)) AS mail,
                IFNULL(A.importe, 0) AS importe,
                A.producto,
                A.fecha
            FROM pdc_pagos A
            INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
            WHERE A.ID_producto IN ({$_ph_prods})
              AND A.ref = ?
              AND A.estado IN ('Completado','Compra')
              AND (A.no_comision_afiliado = 0 OR A.no_comision_afiliado IS NULL)
            ORDER BY A.fecha DESC
        ");
        $_params2 = array_merge($_products_ext, [(string) $user_id]);
        $_stmt2->execute($_params2);
        foreach ($_stmt2->fetchAll(PDO::FETCH_ASSOC) as $_r2) {
            $_m2 = strtolower(trim((string) $_r2['mail']));
            // Skip if already counted via wp_datos lead attribution
            if (isset($_attributed_set[$_m2]))
                continue;
            $ext_af_sales[] = $_r2;
            $_attributed_set[$_m2] = true; // prevent double-count
        }

        $_pdo = null;
        if (!empty($ext_af_sales))
            $use_ext_af_db = true;

    } catch (Exception $_e) {
        $ext_af_db_error = $_e->getMessage();
        $use_ext_af_db = false;
    }
    // Cachea el resultado (también si está vacío o falló) 5 min: no repetir el round-trip
    // cross-server ni el timeout de 8s en cada carga del panel.
    set_transient($_ext_cache_key, array('sales' => $ext_af_sales, 'use' => $use_ext_af_db), 5 * MINUTE_IN_SECONDS);
    } // end else (cache miss)
}
// Lanzamiento Evento: las ventas vienen de la BBDD de eventos (no de pdc_pagos).
// Se cargan en el MISMO formato que $ext_af_sales para reutilizar todo el render.
if ($is_eventos_launch) {
    $ext_af_sales = function_exists('endtrack_eventos_sales') ? endtrack_eventos_sales($user_id) : array();
    $use_ext_af_db = true;
}
// ─────────────────────────────────────────────────────────────────────────────


// Active Launch Pending Balance Calculation for Alert
$tabla_payments = $wpdb->prefix . 'endtrack_payments';
$tabla_datos = $wpdb->prefix . 'datos';
$val_date_global = date('Y-m-d H:i:s', strtotime("-15 days"));

$active_launch_pending = 0;

if (!empty($active_launch)) {
    if ($active_launch == 'legacy') {
        $l_col = 'primer_reg';
    } else {
        $safe_l = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $l_col = "primer_reg_$safe_l";
    }

    // Check if column exists to avoid errors
    $has_l_col = $wpdb->get_results("SHOW COLUMNS FROM $tabla_datos LIKE '$l_col'");

    // El Evento no tiene columna local (usa $ext_af_sales de la BBDD de eventos),
    // pero igualmente calculamos su saldo pendiente.
    if (!empty($has_l_col) || $is_eventos_launch) {
        // 1. Calculate Total Commission Validated (Available) for this launch
        $total_comm_val = 0;
        if ($use_ext_af_db) {
            foreach ($ext_af_sales as $_es) {
                if (strtotime($_es['fecha']) <= strtotime($val_date_global)) {
                    $total_comm_val += floatval($_es['importe']) * 0.96 * $comm_factor;
                }
            }
        } else {
            $l_sales_val = $wpdb->get_results($wpdb->prepare(
                "SELECT total FROM $tabla_datos WHERE afiliado = %d AND $l_col = 2 AND fecha <= %s",
                $user_id,
                $val_date_global
            ));
            foreach ($l_sales_val as $ls) {
                $total_comm_val += (floatval(str_replace(',', '.', $ls->total)) * 0.96 * $comm_factor);
            }
        }

        // 2. Calculate Total Paid for this launch
        $total_paid_launch = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s", $user_id, $active_launch));

        // 3. Upfront payment (fixed advance to amortize before commissions are billable)
        $upfront_val = endtrack_get_user_meta_by_launch($user_id, 'endtrack_upfront_payment', $active_launch, $launches);
        if ($upfront_val === '')
            $upfront_val = 0;
        $upfront_val = floatval($upfront_val);

        // 4. Pending = Validated - Upfront - Paid
        $active_launch_pending = $total_comm_val - $upfront_val - $total_paid_launch;
    }
}

if (!$is_admin_panel): // Show commission & upfront info to affiliate
    ?>
                        <div class="card" style="display:flex; gap:20px; align-items:center; padding:18px 24px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <i class="fas fa-percentage" style="color:#6366F1; font-size:18px;"></i>
                                <span style="color:#666; font-size:13px;">Tu comisión:</span>
                                <strong style="color:#333; font-size:16px;"><?php echo esc_html($comm_rate); ?>%</strong>
                            </div>
                            <?php if ($upfront_val > 0): ?>
                                                    <div style="display:flex; align-items:center; gap:8px;">
                                                        <i class="fas fa-hand-holding-usd" style="color:#F59E0B; font-size:18px;"></i>
                                                        <span style="color:#666; font-size:13px;">Upfront recibido:</span>
                                                        <strong style="color:#F59E0B; font-size:16px;"><?php echo number_format($upfront_val, 2, ',', '.'); ?> €</strong>
                                                    </div>
                                                    <div style="display:flex; align-items:center; gap:8px;">
                                                        <i class="fas fa-sync-alt" style="color:<?php echo ($total_comm_val >= $upfront_val) ? '#10B981' : '#EF4444'; ?>; font-size:18px;"></i>
                                                        <span style="color:#666; font-size:13px;">Amortizado:</span>
                                                        <strong style="color:<?php echo ($total_comm_val >= $upfront_val) ? '#10B981' : '#EF4444'; ?>; font-size:16px;">
                                                            <?php echo number_format(min($total_comm_val, $upfront_val), 2, ',', '.'); ?> / <?php echo number_format($upfront_val, 2, ',', '.'); ?> €
                                                        </strong>
                                                    </div>
                                                    <?php
                            endif; ?>
                        </div>
                        <?php
endif;

if ($active_launch_pending > 1 && !$is_admin_panel): // Show alert if > 1 EUR and not admin viewing
    ?>
                            <div class="card"
                                style="background: rgba(79, 70, 229, 0.1) !important; color: #1d1d1f; border: 1px solid rgba(79, 70, 229, 0.3) !important; position: relative; overflow: hidden; backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);">
                                <div style="position: absolute; right: -20px; top: -20px; opacity: 0.05; font-size: 100px; color: #4F46E5;">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div style="position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between;">
                                    <div>
                                        <h3 style="margin: 0; color: #4338ca; font-size: 18px; font-weight: 800;">¡Tienes comisiones pendientes de
                                            cobro!</h3>
                                        <p style="margin: 5px 0 0 0; color: #5f6368; font-size: 14px;">
                                            Tienes un saldo acumulado de <strong
                                                style="color: #4338ca;"><?php echo number_format($active_launch_pending, 2, ',', '.'); ?>
                                                €</strong> listo para ser transferido.
                                        </p>
                                        <?php
                                        // Billing Info in Alert
                                        $billing_key_alert = 'content_billing_methods_' . $active_launch;
                                        $billing_content_alert = isset($texts[$billing_key_alert]) ? $texts[$billing_key_alert] : (isset($texts['content_billing_methods']) ? $texts['content_billing_methods'] : '');
                                        if (!empty($billing_content_alert)) {
                                            echo '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(79, 70, 229, 0.2); font-size: 14px; color: #5f6368;">' . wpautop($billing_content_alert) . '</div>';
                                        }
                                        ?>
                                    </div>
                                    <div
                                        style="background: rgba(79, 70, 229, 0.15); color: #4338ca; padding: 12px 24px; border-radius: 16px; font-weight: 800; border: 1px solid rgba(79, 70, 229, 0.2);">
                                        Pendiente: <?php echo number_format($active_launch_pending, 2, ',', '.'); ?> €
                                    </div>
                                </div>
                            </div>
                            <?php
endif;

// ------ DASHBOARD TAB ------
if ($active_tab == 'dashboard') {


    // Date Filter Logic
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');

    // Stats Logic
    $stats = ['leads' => 0, 'sales' => 0, 'commission' => 0, 'visits' => 0];
    $tabla_datos = $wpdb->prefix . 'datos';
    $tabla_visitas = $wpdb->prefix . 'visitas';

    if ($is_eventos_launch) {
        // Evento: stats desde la BBDD de eventos. Ventas en $ext_af_sales; visitas por ref.
        // Venta directa sin registro → no hay leads.
        $stats['leads'] = 0;
        $visits_info = function_exists('endtrack_eventos_visits') ? endtrack_eventos_visits($user_id, $date_from, $date_to) : array('total' => 0, 'daily' => array());
        $stats['visits'] = (int) $visits_info['total'];

        $daily_sales_map = array();
        foreach ($ext_af_sales as $_es) {
            $_d = substr((string) $_es['fecha'], 0, 10);
            if ($_d >= $date_from && $_d <= $date_to) {
                $stats['sales']++;
                $stats['commission'] += floatval($_es['importe']) * 0.96 * $comm_factor;
                $daily_sales_map[$_d] = ($daily_sales_map[$_d] ?? 0) + 1;
            }
        }
        $conversion_rate = ($stats['visits'] > 0) ? ($stats['sales'] / $stats['visits']) * 100 : 0;

        $chart_labels = array();
        $visits_data = array();
        $leads_data = array();
        $current = strtotime($date_from);
        $last = strtotime($date_to);
        while ($current <= $last) {
            $day = date('Y-m-d', $current);
            $chart_labels[] = $day;
            $visits_data[] = isset($visits_info['daily'][$day]) ? $visits_info['daily'][$day] : 0;
            $leads_data[] = 0;
            $current = strtotime("+1 day", $current);
        }
    } elseif (!empty($active_launch)) {
        if ($active_launch == 'legacy') {
            $col_primer_reg = "primer_reg";
            $col_tipo_cat_vis = "1";
        } else {
            $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
            $col_primer_reg = "primer_reg_" . $safe_launch;
            $col_tipo_cat_vis = "tipo_cat_" . $safe_launch;
        }

        // Leads (Unique emails)
        $stats['leads'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg = 1 OR $col_primer_reg = 2) AND DATE(fecha) BETWEEN %s AND %s",
            $user_id,
            $date_from,
            $date_to
        ));

        // Sales (Buyer status)
        $stats['sales'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s",
            $user_id,
            $date_from,
            $date_to
        ));

        // Visits (Unique IPs for this affiliate)
        $stats['visits'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ip) FROM $tabla_visitas WHERE ref = %d AND $col_tipo_cat_vis > 0 AND DATE(fecha) BETWEEN %s AND %s",
            $user_id,
            $date_from,
            $date_to
        ));

        // Conversion Rate
        $conversion_rate = ($stats['visits'] > 0) ? ($stats['leads'] / $stats['visits']) * 100 : 0;

        // Commission (Mock 25%)
        $tabla_afiliado_com = $wpdb->prefix . 'afiliados_a_compras';
        if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_afiliado_com'") == $tabla_afiliado_com) {
            $stats['commission'] = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(A.precio * $comm_factor) FROM $tabla_afiliado_com A INNER JOIN (SELECT correo FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg=1 OR $col_primer_reg=2) AND DATE(fecha) BETWEEN %s AND %s GROUP BY correo) P ON P.correo = A.mail",
                $user_id,
                $date_from,
                $date_to
            ));
        }

        // Daily Data for Charts
        $daily_visits = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(fecha) as day, COUNT(DISTINCT ip) as count FROM $tabla_visitas WHERE ref = %d AND $col_tipo_cat_vis > 0 AND DATE(fecha) BETWEEN %s AND %s GROUP BY DATE(fecha) ORDER BY day ASC",
            $user_id,
            $date_from,
            $date_to
        ));

        $daily_leads = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(fecha) as day, COUNT(DISTINCT correo) as count FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg = 1 OR $col_primer_reg = 2) AND DATE(fecha) BETWEEN %s AND %s GROUP BY DATE(fecha) ORDER BY day ASC",
            $user_id,
            $date_from,
            $date_to
        ));

        // Prepare chart data objects
        $chart_labels = [];
        $visits_data = [];
        $leads_data = [];

        $current = strtotime($date_from);
        $last = strtotime($date_to);
        while ($current <= $last) {
            $day = date('Y-m-d', $current);
            $chart_labels[] = $day;

            $v_count = 0;
            foreach ($daily_visits as $dv)
                if ($dv->day == $day)
                    $v_count = $dv->count;
            $visits_data[] = $v_count;

            $l_count = 0;
            foreach ($daily_leads as $dl)
                if ($dl->day == $day)
                    $l_count = $dl->count;
            $leads_data[] = $l_count;

            $current = strtotime("+1 day", $current);
        }
    }
    ?>
                            <div class="stats-overview">
                                <!-- Date Filter Bar -->
                                <div class="filter-bar">
                                    <form method="GET" style="display:flex; align-items:center; gap:10px; margin:0;">
                                        <?php foreach ($_GET as $key => $val):
                                            if ($key == 'date_from' || $key == 'date_to')
                                                continue; ?>
                                                                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>">
                                                                <?php
                                        endforeach; ?>
                                        <label>Desde:</label>
                                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                                        <label>Hasta:</label>
                                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                                        <button type="submit" class="card"
                                            style="padding:10px 25px; border:none; background:var(--primary-color) !important; color:#fff !important; cursor:pointer; font-weight:700; border-radius:16px !important; box-shadow:0 4px 15px rgba(79, 70, 229, 0.4) !important;">Filtrar</button>
                                    </form>
                                </div>

                                <div class="stats-grid">
                                    <div class="card stat-card stat-card-visits">
                                        <i class="fas fa-users stat-icon"></i>
                                        <h4>Visitas Únicas</h4>
                                        <div class="stat-value"><?php echo number_format($stats['visits']); ?></div>
                                    </div>
                                    <div class="card stat-card stat-card-leads">
                                        <i class="fas fa-user-plus stat-icon"></i>
                                        <h4>Registrados</h4>
                                        <div class="stat-value"><?php echo number_format($stats['leads']); ?></div>
                                    </div>
                                    <div class="card stat-card stat-card-conversion">
                                        <i class="fas fa-chart-pie stat-icon"></i>
                                        <h4>Conversión</h4>
                                        <div class="stat-value"><?php echo number_format($conversion_rate, 2); ?>%</div>
                                    </div>
                                    <div class="card stat-card stat-card-sales">
                                        <i class="fas fa-shopping-cart stat-icon"></i>
                                        <h4>Número de ventas</h4>
                                        <div class="stat-value">
                                            <?php
                                            if ($use_ext_af_db) {
                                                $_seen = [];
                                                $sales_count = 0;
                                                foreach ($ext_af_sales as $_es) {
                                                    $_d = substr((string) $_es['fecha'], 0, 10);
                                                    if ($_d >= $date_from && $_d <= $date_to && !isset($_seen[$_es['mail']])) {
                                                        $_seen[$_es['mail']] = true;
                                                        $sales_count++;
                                                    }
                                                }
                                            } else {
                                                $sales_count = $wpdb->get_var($wpdb->prepare(
                                                    "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s",
                                                    $user_id,
                                                    $date_from,
                                                    $date_to
                                                ));
                                            }
                                            echo number_format($sales_count);
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="chart-container">
                                    <div class="card">
                                        <h3>Visitas por día</h3>
                                        <canvas id="visitsChart"></canvas>
                                    </div>
                                    <div class="card">
                                        <h3>Registros por día</h3>
                                        <canvas id="leadsChart"></canvas>
                                    </div>
                                </div>

                                <script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        const labels = <?php echo json_encode($chart_labels); ?>;

                                        new Chart(document.getElementById('visitsChart'), {
                                            type: 'line',
                                            data: {
                                                labels: labels,
                                                datasets: [{
                                                    label: 'Visitas',
                                                    data: <?php echo json_encode($visits_data); ?>,
                                                    borderColor: '#0EA5E9',
                                                    backgroundColor: 'rgba(14, 165, 233, 0.1)',
                                                    fill: true,
                                                    tension: 0.3
                                                }]
                                            }
                                        });

                                        new Chart(document.getElementById('leadsChart'), {
                                            type: 'line',
                                            data: {
                                                labels: labels,
                                                datasets: [{
                                                    label: 'Registros',
                                                    data: <?php echo json_encode($leads_data); ?>,
                                                    borderColor: '#6366F1',
                                                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                                                    fill: true,
                                                    tension: 0.3
                                                }]
                                            }
                                        });
                                    });
                                </script>

                                <div class="card" style="margin-top:30px;">
                                    <h3>Histórico (Últimos 10 registros)</h3>
                                    <?php
                                    // El evento es venta directa sin registros (leads); no hay histórico de registros.
                                    $leads = $is_eventos_launch ? array() : $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg = 1 OR $col_primer_reg = 2) ORDER BY fecha DESC LIMIT 10", $user_id));
                                    ?>
                                    <table style="width:100%; text-align:left; border-collapse:collapse;">
                                        <tr
                                            style="background:rgba(0,0,0,0.02); color:#666; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                                            <th style="padding:14px;">Fecha</th>
                                            <th style="padding:14px;">Correo</th>
                                            <th style="padding:14px;">Estado</th>
                                        </tr>
                                        <?php if ($leads):
                                            foreach ($leads as $l):
                                                $email_masked = substr($l->correo, 0, 3) . '***';
                                                $status = ($l->$col_primer_reg == 2) ? 'VENTA' : 'LEAD';
                                                ?>
                                                                                                <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                                                                                                    onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                                                                                                    <td style="padding:14px; color:#888;"><?php echo $l->fecha; ?></td>
                                                                                                    <td style="padding:14px; font-weight:600; color:#333;"><?php echo $email_masked; ?></td>
                                                                                                    <td style="padding:14px;">
                                                                                                        <span
                                                                                                            style="background:<?php echo $status == 'VENTA' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(99, 102, 241, 0.1)'; ?>; color:<?php echo $status == 'VENTA' ? '#10B981' : '#6366F1'; ?>; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:700;">
                                                                                                            <?php echo $status; ?>
                                                                                                        </span>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            <?php
                                            endforeach;
                                        else: ?>
                                                                    <tr>
                                                                        <td colspan="3" style="padding:10px;">No hay actividad.</td>
                                                                    </tr>
                                                                <?php
                                        endif; ?>
                                    </table>
                                </div>
                            </div>
                            <?php
}

// ------ VENTAS TAB ------
if ($active_tab == 'ventas') {
    // Date Filter Logic
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');

    $tabla_datos = $wpdb->prefix . 'datos';
    $stats = ['leads' => 0, 'sales' => 0, 'commission' => 0];

    if (!empty($active_launch)) {
        if ($active_launch == 'legacy') {
            $col_primer_reg = "primer_reg";
        } else {
            $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
            $col_primer_reg = "primer_reg_" . $safe_launch;
        }

        // Leads for this launch (el evento es venta directa: sin leads)
        $stats['leads'] = $is_eventos_launch ? 0 : $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg = 1 OR $col_primer_reg = 2) AND DATE(fecha) BETWEEN %s AND %s",
            $user_id,
            $date_from,
            $date_to
        ));

        // Sales for this launch
        if ($use_ext_af_db) {
            $_buyers_in_range = [];
            $_daily_map = [];
            foreach ($ext_af_sales as $_es) {
                $_d = substr($_es['fecha'], 0, 10);
                if ($_d >= $date_from && $_d <= $date_to) {
                    if (!in_array($_es['mail'], $_buyers_in_range)) {
                        $_buyers_in_range[] = $_es['mail'];
                    }
                    $_daily_map[$_d] = ($_daily_map[$_d] ?? 0) + 1;
                    $stats['commission'] += floatval($_es['importe']) * 0.96 * $comm_factor;
                }
            }
            $stats['sales'] = count($_buyers_in_range);
            // Build $daily_sales compatible structure
            $daily_sales = [];
            foreach ($_daily_map as $_day => $_cnt) {
                $daily_sales[] = (object) ['day' => $_day, 'count' => $_cnt];
            }
        } else {
            $stats['sales'] = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s",
                $user_id,
                $date_from,
                $date_to
            ));

            $tabla_afiliado_com = $wpdb->prefix . 'afiliados_a_compras';
            if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_afiliado_com'") == $tabla_afiliado_com) {
                $stats['commission'] = $wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(A.precio * $comm_factor) FROM $tabla_afiliado_com A INNER JOIN (SELECT correo FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg=2 AND DATE(fecha) BETWEEN %s AND %s GROUP BY correo) P ON P.correo = A.mail",
                    $user_id,
                    $date_from,
                    $date_to
                ));
            }

            $daily_sales = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(fecha) as day, COUNT(DISTINCT correo) as count FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s GROUP BY DATE(fecha) ORDER BY day ASC",
                $user_id,
                $date_from,
                $date_to
            ));
        }

        // Lead-to-Sale Conversion
        $lts_conversion = ($stats['leads'] > 0) ? ($stats['sales'] / $stats['leads']) * 100 : 0;

        // Chart Data
        $chart_labels = [];
        $sales_data = [];
        $current = strtotime($date_from);
        $last = strtotime($date_to);
        while ($current <= $last) {
            $day = date('Y-m-d', $current);
            $chart_labels[] = $day;
            $s_count = 0;
            foreach ($daily_sales as $ds)
                if ($ds->day == $day)
                    $s_count = $ds->count;
            $sales_data[] = $s_count;
            $current = strtotime("+1 day", $current);
        }
    }
    ?>
                            <div class="stats-overview">
                                <div class="filter-bar">
                                    <form method="GET" style="display:flex; align-items:center; gap:10px; margin:0;">
                                        <?php foreach ($_GET as $key => $val):
                                            if ($key == 'date_from' || $key == 'date_to')
                                                continue; ?>
                                                                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>">
                                                                <?php
                                        endforeach; ?>
                                        <label>Desde:</label>
                                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                                        <label>Hasta:</label>
                                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                                        <button type="submit" class="card"
                                            style="padding:10px 25px; border:none; background:var(--primary-color) !important; color:#fff !important; cursor:pointer; font-weight:700; border-radius:16px !important; box-shadow:0 4px 15px rgba(79, 70, 229, 0.4) !important;">Filtrar</button>
                                    </form>
                                </div>

                                <div class="stats-grid">
                                    <div class="card stat-card stat-card-leads">
                                        <i class="fas fa-user-plus stat-icon"></i>
                                        <h4>Registrados</h4>
                                        <div class="stat-value"><?php echo number_format($stats['leads']); ?></div>
                                    </div>
                                    <div class="card stat-card stat-card-sales">
                                        <i class="fas fa-shopping-cart stat-icon"></i>
                                        <h4>Ventas Totales</h4>
                                        <div class="stat-value"><?php echo number_format($stats['sales']); ?></div>
                                    </div>
                                    <div class="card stat-card stat-card-conversion">
                                        <i class="fas fa-clock stat-icon"></i>
                                        <h4>En Validación (Futuras)</h4>
                                        <div class="stat-value">
                                            <?php
                                            // Sales <= 15 days
                                            $val_days = 15;
                                            $val_date = date('Y-m-d H:i:s', strtotime("-$val_days days"));

                                            if ($use_ext_af_db) {
                                                $total_comm_future = 0;
                                                foreach ($ext_af_sales as $_es) {
                                                    if (strtotime($_es['fecha']) > strtotime($val_date)) {
                                                        $total_comm_future += floatval($_es['importe']) * 0.96 * $comm_factor;
                                                    }
                                                }
                                            } else {
                                                $sales_data_future = $wpdb->get_results($wpdb->prepare(
                                                    "SELECT total FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND fecha > %s",
                                                    $user_id,
                                                    $val_date
                                                ));
                                                $total_comm_future = 0;
                                                foreach ($sales_data_future as $sdf) {
                                                    $val = floatval(str_replace(',', '.', $sdf->total));
                                                    $total_comm_future += ($val * 0.96 * $comm_factor);
                                                }
                                            }
                                            echo number_format($total_comm_future, 2, ',', '.') . ' €';
                                            ?>
                                        </div>
                                    </div>
                                    <div class="card stat-card stat-card-sales">
                                        <i class="fas fa-check-circle stat-icon"></i>
                                        <h4>Disponible (Validadas)</h4>
                                        <div class="stat-value">
                                            <?php
                                            if ($use_ext_af_db) {
                                                $total_comm_val = 0;
                                                foreach ($ext_af_sales as $_es) {
                                                    if (strtotime($_es['fecha']) <= strtotime($val_date)) {
                                                        $total_comm_val += floatval($_es['importe']) * 0.96 * $comm_factor;
                                                    }
                                                }
                                            } else {
                                                $sales_data_val = $wpdb->get_results($wpdb->prepare(
                                                    "SELECT total FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND fecha <= %s",
                                                    $user_id,
                                                    $val_date
                                                ));
                                                $total_comm_val = 0;
                                                foreach ($sales_data_val as $sdv) {
                                                    $val = floatval(str_replace(',', '.', $sdv->total));
                                                    $total_comm_val += ($val * 0.96 * $comm_factor);
                                                }
                                            }
                                            echo number_format($total_comm_val, 2, ',', '.') . ' €';
                                            ?>
                                        </div>
                                    </div>
                                    <div class="card stat-card stat-card-payments" style="background: var(--color-payments); color: white;">
                                        <i class="fas fa-hand-holding-usd stat-icon"></i>
                                        <h4>Pendiente de Cobro</h4>
                                        <div class="stat-value">
                                            <?php
                                            // Validated - Upfront - Paid (Filtered by active launch)
                                            $total_paid = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s", $user_id, $active_launch));
                                            if (!$total_paid)
                                                $total_paid = 0;
                                            $pending_payout = $total_comm_val - $upfront_val - $total_paid;
                                            echo number_format(max(0, $pending_payout), 2, ',', '.') . ' €';
                                            ?>
                                        </div>
                                        <?php if ($upfront_val > 0): ?>
                                        <div style="margin-top:8px; font-size:12px; opacity:0.85;">
                                            − Upfront recibido: <strong><?php echo number_format($upfront_val, 2, ',', '.'); ?> €</strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card" style="margin-top:30px;">
                                    <h3>Ventas por día</h3>
                                    <canvas id="salesChart" style="max-height: 300px;"></canvas>
                                </div>

                                <script>
                                    document.addEventListener('DOMContentLoaded', function () {
                                        new Chart(document.getElementById('salesChart'), {
                                            type: 'bar',
                                            data: {
                                                labels: <?php echo json_encode($chart_labels); ?>,
                                                datasets: [{
                                                    label: 'Ventas',
                                                    data: <?php echo json_encode($sales_data); ?>,
                                                    backgroundColor: '#10B981'
                                                }]
                                            },
                                            options: {
                                                maintainAspectRatio: false,
                                                scales: {
                                                    y: {
                                                        beginAtZero: true,
                                                        ticks: {
                                                            stepSize: 1
                                                        }
                                                    }
                                                }
                                            }
                                        });
                                    });
                                </script>

                                <div class="card" style="margin-top:30px;">
                                    <h3>Histórico de Ventas</h3>
                                    <?php
                                    if ($use_ext_af_db) {
                                        $_sales_to_show = array_slice($ext_af_sales, 0, 50);
                                    } else {
                                        $_raw = $wpdb->get_results($wpdb->prepare(
                                            "SELECT * FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 ORDER BY fecha DESC LIMIT 50",
                                            $user_id
                                        ));
                                        $_sales_to_show = [];
                                        foreach ($_raw as $_r) {
                                            $_sales_to_show[] = [
                                                'fecha' => $_r->fecha,
                                                'producto' => $_r->producto,
                                                'importe' => floatval(str_replace(',', '.', $_r->total)),
                                                'mail' => $_r->correo,
                                            ];
                                        }
                                    }
                                    ?>
                                    <table style="width:100%; text-align:left; border-collapse:collapse;">
                                        <tr
                                            style="background:rgba(16, 185, 129, 0.1); color:#10B981; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                                            <th style="padding:14px;">Fecha</th>
                                            <th style="padding:14px;">Producto</th>
                                            <th style="padding:14px;">Total</th>
                                            <th style="padding:14px;">Correo</th>
                                            <th style="padding:14px;">Comisión</th>
                                            <th style="padding:14px;">Estado</th>
                                        </tr>
                                        <?php if (!empty($_sales_to_show)):
                                            foreach ($_sales_to_show as $_s):
                                                $_fecha = is_array($_s) ? $_s['fecha'] : $_s->fecha;
                                                $_producto = is_array($_s) ? $_s['producto'] : $_s->producto;
                                                $_importe = is_array($_s) ? floatval($_s['importe']) : floatval(str_replace(',', '.', $_s->total));
                                                $_mail = is_array($_s) ? $_s['mail'] : $_s->correo;
                                                $_masked = substr($_mail, 0, 3) . '***';
                                                ?>
                                                                                                <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                                                                                                    onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                                                                                                    <td style="padding:14px; color:#888; font-size:13px;"><?php echo esc_html($_fecha); ?></td>
                                                                                                    <td style="padding:14px; font-weight:600; color:#333;">
                                                                                                        <?php echo esc_html($_producto ?: 'Producto General'); ?>
                                                                                                    </td>
                                                                                                    <td style="padding:14px; color:#666;">
                                                                                                        <?php echo $_importe > 0 ? number_format($_importe, 2, ',', '.') . ' €' : '-'; ?>
                                                                                                    </td>
                                                                                                    <td style="padding:14px; color:#888;"><?php echo esc_html($_masked); ?></td>
                                                                                                    <td style="padding:14px; font-weight:700; color:#10B981;">
                                                                                                        <?php
                                                                                                        if ($_importe > 0) {
                                                                                                            echo number_format($_importe * 0.96 * $comm_factor, 2, ',', '.') . ' €';
                                                                                                            echo ' <span style="font-size:10px; opacity:0.6; font-weight:400;">(' . esc_html($comm_rate) . '%)</span>';
                                                                                                        } else {
                                                                                                            echo '-';
                                                                                                        }
                                                                                                        ?>
                                                                                                    </td>
                                                                                                    <td style="padding:14px;">
                                                                                                        <?php
                                                                                                        $_days_diff = floor((time() - strtotime($_fecha)) / 86400);
                                                                                                        if ($_days_diff >= 15) {
                                                                                                            $_sl = 'VALIDADO';
                                                                                                            $_sbg = 'rgba(16, 185, 129, 0.1)';
                                                                                                            $_sc = '#10B981';
                                                                                                        } else {
                                                                                                            $_sl = 'PENDIENTE';
                                                                                                            $_sbg = 'rgba(245, 158, 11, 0.1)';
                                                                                                            $_sc = '#F59E0B';
                                                                                                        }
                                                                                                        ?>
                                                                                                        <span style="background:<?php echo $_sbg; ?>; color:<?php echo $_sc; ?>; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:700;">
                                                                                                            <?php echo $_sl; ?>
                                                                                                        </span>
                                                                                                    </td>
                                                                                                </tr>
                                                                                            <?php
                                            endforeach;
                                        else: ?>
                                                                    <tr>
                                                                        <td colspan="6" style="padding:10px;">No hay ventas registradas.</td>
                                                                    </tr>
                                                                <?php
                                        endif; ?>
                                    </table>
                                </div>
                            </div>
                            <?php
}

// ------ LINKS TAB ------
if ($active_tab == 'links') {
    if ($is_eventos_launch) {
        // Evento: venta directa en eventos.pablogiltrader.com. Enlace fijo de venta.
        $evento_link = 'https://eventos.pablogiltrader.com/?utm_source=afiliado&ref=' . $user_id;
        ?>
                            <div class="card">
                                <h3><?php echo esc_html($txt_links); ?> (Evento)</h3>
                                <p>Comparte este enlace del evento. La comisión es del <strong><?php echo esc_html($comm_rate); ?>%</strong> sobre cada venta. <em>Es una página de venta directa: no genera registros, solo ventas.</em></p>
                                <div style="margin-bottom:20px;">
                                    <label style="display:block; font-weight:600; margin-bottom:5px;">
                                        Enlace de venta del Evento
                                        <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; text-transform:uppercase; margin-left:10px;">Venta</span>
                                    </label>
                                    <div style="display:flex; gap:10px;">
                                        <input type="text" value="<?php echo esc_url($evento_link); ?>" id="link_evento"
                                            style="flex:1; padding:14px; background:rgba(255,255,255,0.4); border:1px solid rgba(0,0,0,0.05); font-family:monospace; border-radius:16px;"
                                            readonly onclick="this.select()">
                                        <button onclick="(function(){var i=document.getElementById('link_evento');i.select();i.setSelectionRange(0,99999);navigator.clipboard.writeText(i.value);alert('¡Enlace copiado!');})()" class="card"
                                            style="padding:0 25px; border:none; background:var(--primary-color) !important; color:#fff !important; cursor:pointer; font-weight:700; border-radius:16px !important; box-shadow:0 4px 15px rgba(0,0,0,0.1) !important;">Copiar</button>
                                    </div>
                                </div>
                            </div>
                            <?php
    } else {
    $link = site_url() . '/?ref=' . $user_id . '&launch=' . $active_launch;
    ?>
                            <div class="card">
                                <h3><?php echo esc_html($txt_links); ?> (<?php echo ucfirst($active_launch); ?>)</h3>
                                <p>Usa estos enlaces para promocionar:</p>

                                <?php
                                $mapping = get_option('endtrack_launches_mapping', array());
                                $launch_slug = isset($mapping[$active_launch]) ? $mapping[$active_launch] : sanitize_title($active_launch);

                                $args = array(
                                    'post_type' => 'page',
                                    'posts_per_page' => -1,
                                    'tax_query' => array(
                                        'relation' => 'AND',
                                        array(
                                            'taxonomy' => 'category',
                                            'field' => 'slug',
                                            'terms' => $launch_slug,
                                        ),
                                        // Las páginas de gracias pueden estar marcadas como "afiliado"
                                        // para inyectar píxeles, pero NO deben mostrarse como enlaces
                                        // para compartir.
                                        array(
                                            'taxonomy' => 'category',
                                            'field' => 'slug',
                                            'terms' => array('gracias', 'gracias-registro', 'gracias_registro'),
                                            'operator' => 'NOT IN',
                                        ),
                                    ),
                                    'meta_query' => array(
                                        array(
                                            'key' => '_endtrack_is_affiliate_link',
                                            'value' => '1',
                                            'compare' => '=',
                                        ),
                                    ),
                                );
                                $affiliate_pages = get_posts($args);

                                if (!empty($affiliate_pages)):
                                    foreach ($affiliate_pages as $p):
                                        $base_url = get_permalink($p->ID);
                                        $affiliate_link = add_query_arg(array(
                                            'utm_source' => 'afiliado',
                                            'ref' => $user_id
                                        ), $base_url);

                                        $type_badge = '';
                                        if (has_category('registro', $p->ID)) {
                                            $type_badge = '<span style="background:#e0e7ff; color:#4338ca; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; text-transform:uppercase; margin-left:10px;">Registro</span>';
                                        } elseif (has_category('venta', $p->ID)) {
                                            $type_badge = '<span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; text-transform:uppercase; margin-left:10px;">Venta</span>';
                                        }
                                        ?>
                                                                                        <div style="margin-bottom:20px;">
                                                                                            <label style="display:block; font-weight:600; margin-bottom:5px;">
                                                                                                <?php echo esc_html($p->post_title); ?>
                                                                                                <?php echo $type_badge; ?>
                                                                                            </label>
                                                                                            <div style="display:flex; gap:10px;">
                                                                                                <input type="text" value="<?php echo esc_url($affiliate_link); ?>" id="link_<?php echo $p->ID; ?>"
                                                                                                    style="flex:1; padding:14px; background:rgba(255,255,255,0.4); border:1px solid rgba(0,0,0,0.05); font-family:monospace; border-radius:16px;"
                                                                                                    readonly onclick="this.select()">
                                                                                                <button onclick="copyToClipboard('link_<?php echo $p->ID; ?>')" class="card"
                                                                                                    style="padding:0 25px; border:none; background:var(--primary-color) !important; color:#fff !important; cursor:pointer; font-weight:700; border-radius:16px !important; box-shadow:0 4px 15px rgba(0,0,0,0.1) !important;">Copiar</button>
                                                                                            </div>
                                                                                        </div>
                                                                                    <?php
                                    endforeach; ?>
                                                            <script>
                                                                function copyToClipboard(id) {
                                                                    var copyText = document.getElementById(id);
                                                                    copyText.select();
                                                                    copyText.setSelectionRange(0, 99999);
                                                                    navigator.clipboard.writeText(copyText.value);
                                                                    alert("¡Enlace copiado!");
                                                                }
                                                            </script>
                                                        <?php
                                else: ?>
                                                            <p class="description" style="font-style:italic;">No hay enlaces específicos configurados para este lanzamiento.</p>
                                                        <?php
                                endif; ?>

                                <?php
                                // Legacy/Fallback links if needed
                                $launch_links = get_option('endtrack_launch_links', array());
                                $reg_url_legacy = isset($launch_links[$active_launch]['reg']) ? $launch_links[$active_launch]['reg'] : '';
                                $venta_url_legacy = isset($launch_links[$active_launch]['venta']) ? $launch_links[$active_launch]['venta'] : '';

                                if (($reg_url_legacy || $venta_url_legacy) && empty($affiliate_pages)):
                                    $affiliate_link_reg = !empty($reg_url_legacy) ? add_query_arg(array('utm_source' => 'afiliado', 'ref' => $user_id), $reg_url_legacy) : '';
                                    $affiliate_link_venta = !empty($venta_url_legacy) ? add_query_arg(array('utm_source' => 'afiliado', 'ref' => $user_id), $venta_url_legacy) : '';
                                    ?>
                                                            <hr style="border:0; border-top:1px solid #eee; margin:30px 0;">
                                                            <p><strong>Enlaces predeterminados:</strong></p>
                                                            <?php if ($affiliate_link_reg): ?>
                                                                                        <div style="margin-bottom:20px;">
                                                                                            <label
                                                                                                style="display:block; font-weight:600; margin-bottom:5px;"><?php echo esc_html($txt_links_reg); ?>:</label>
                                                                                            <input type="text" value="<?php echo esc_url($affiliate_link_reg); ?>"
                                                                                                style="width:100%; padding:15px; background:#f9f9f9; border:1px solid #ddd; font-family:monospace;" readonly
                                                                                                onclick="this.select()">
                                                                                        </div>
                                                                                    <?php
                                                            endif; ?>
                                                            <?php if ($affiliate_link_venta): ?>
                                                                                        <div>
                                                                                            <label
                                                                                                style="display:block; font-weight:600; margin-bottom:5px;"><?php echo esc_html($txt_links_venta); ?>:</label>
                                                                                            <input type="text" value="<?php echo esc_url($affiliate_link_venta); ?>"
                                                                                                style="width:100%; padding:15px; background:#f9f9f9; border:1px solid #ddd; font-family:monospace;" readonly
                                                                                                onclick="this.select()">
                                                                                        </div>
                                                                                    <?php
                                                            endif; ?>
                                                        <?php
                                endif; ?>
                            </div>
                            <?php
    } // end else (lanzamiento no-Evento)
}

// ------ PÍXELES TAB ------
if ($active_tab == 'pixeles') {
    if ($is_eventos_launch) {
        ?>
                            <div class="card">
                                <h3>Píxeles (Evento)</h3>
                                <p>El lanzamiento <strong>Evento</strong> se vende en otra web (eventos.pablogiltrader.com), así que los píxeles de afiliado no se gestionan desde aquí.</p>
                            </div>
                            <?php
    } else {
        $safe_px = ($active_launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
        $all_px = get_option('endtrack_affiliate_pixels_' . $safe_px, array());
        $my_px = (is_array($all_px) && isset($all_px[$user_id]) && is_array($all_px[$user_id])) ? $all_px[$user_id] : array();
        $px_meta = isset($my_px['meta']) ? $my_px['meta'] : '';
        $px_gads_id = isset($my_px['gads_id']) ? $my_px['gads_id'] : '';
        $px_gads_label = isset($my_px['gads_label']) ? $my_px['gads_label'] : '';
        $px_saved = isset($_GET['px_saved']) && $_GET['px_saved'] == '1';
        $px_input_style = 'width:100%; padding:14px; background:rgba(255,255,255,0.4); border:1px solid rgba(0,0,0,0.08); font-family:monospace; border-radius:16px; box-sizing:border-box;';
        $px_label_style = 'display:block; font-weight:600; margin-bottom:6px;';
        $px_hint_style = 'display:block; color:#666; font-size:12px; margin-top:6px;';
        ?>
                            <div class="card">
                                <h3>Tus píxeles (<?php echo esc_html(ucfirst($active_launch)); ?>)</h3>
                                <p>Configura tu propio píxel para medir tu publicidad. Se insertará automáticamente en las páginas de este lanzamiento (landing y gracias) cuando la visita llegue con <strong>tu</strong> enlace de afiliado (<code>?ref=<?php echo esc_html($user_id); ?></code>). Los datos van a tu cuenta de Meta/Google, nadie más los ve.</p>
                                <?php if ($px_saved): ?>
                                <div style="background:#dcfce7; color:#166534; padding:12px 16px; border-radius:12px; margin-bottom:18px; font-weight:600;">✅ Píxeles guardados.</div>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="endtrack_save_affiliate_pixels">
                                    <input type="hidden" name="launch" value="<?php echo esc_attr($active_launch); ?>">
                                    <?php if ($user_id !== get_current_user_id()): ?>
                                    <input type="hidden" name="target_user" value="<?php echo intval($user_id); ?>">
                                    <?php endif; ?>
                                    <?php wp_nonce_field('endtrack_save_affiliate_pixels_action', 'endtrack_px_nonce'); ?>

                                    <div style="margin-bottom:24px;">
                                        <label style="<?php echo $px_label_style; ?>"><i class="fab fa-facebook" style="color:#1877f2; margin-right:6px;"></i> Pixel ID de Meta (Facebook / Instagram)</label>
                                        <input type="text" name="meta_pixel" value="<?php echo esc_attr($px_meta); ?>" placeholder="Ej: 123456789012345" style="<?php echo $px_input_style; ?>" inputmode="numeric">
                                        <small style="<?php echo $px_hint_style; ?>">Solo el número del píxel. Lo encuentras en Meta Events Manager → Orígenes de datos.</small>
                                    </div>

                                    <div style="margin-bottom:24px;">
                                        <label style="<?php echo $px_label_style; ?>"><i class="fab fa-google" style="color:#ea4335; margin-right:6px;"></i> ID de Google Ads / GA4</label>
                                        <input type="text" name="gads_id" value="<?php echo esc_attr($px_gads_id); ?>" placeholder="Ej: AW-123456789 (o G-XXXXXXX)" style="<?php echo $px_input_style; ?>">
                                        <small style="<?php echo $px_hint_style; ?>">Tu ID de Google Ads (<code>AW-…</code>) o de Google Analytics 4 (<code>G-…</code>).</small>
                                    </div>

                                    <div style="margin-bottom:24px;">
                                        <label style="<?php echo $px_label_style; ?>">Etiqueta de conversión de Google Ads <span style="font-weight:400; color:#666;">(opcional)</span></label>
                                        <input type="text" name="gads_label" value="<?php echo esc_attr($px_gads_label); ?>" placeholder="Ej: AbC-D_efGhIjKl" style="<?php echo $px_input_style; ?>">
                                        <small style="<?php echo $px_hint_style; ?>">Necesaria para registrar la <strong>conversión</strong> (venta/registro) en Google Ads. En Google Ads, dentro de tu acción de conversión, es el texto que va detrás de la barra <code>/</code> en el <code>send_to</code>. Si dejas solo el ID, se medirán las visitas pero no las conversiones.</small>
                                    </div>

                                    <button type="submit" class="card" style="padding:14px 30px; border:none; background:var(--primary-color) !important; color:#fff !important; cursor:pointer; font-weight:700; border-radius:16px !important; box-shadow:0 4px 15px rgba(0,0,0,0.1) !important;">Guardar píxeles</button>
                                </form>
                            </div>
                            <?php
    }
}

// ------ CREATIVIDADES TAB ------
if ($active_tab == 'creatividades') {
    ?>
                            <div class="card content-area">
                                <?php
                                $creatives_key = 'content_creatividades_' . $active_launch;
                                $creatives_content = isset($texts[$creatives_key]) ? $texts[$creatives_key] : (isset($texts['content_creatividades']) ? $texts['content_creatividades'] : '<p>No hay creatividades disponibles para este lanzamiento.</p>');
                                echo wpautop($creatives_content);
                                ?>
                            </div>
                            <?php
}

// ------ ASIGNACIÓN TAB ------
if ($active_tab == 'asignacion') {
    ?>
                            <div class="card content-area">
                                <?php echo wpautop(isset($texts['content_asignacion']) ? $texts['content_asignacion'] : '<p>No hay asignación disponible.</p>'); ?>
                            </div>
                            <?php
}

// ------ PAGOS TAB ------
if ($active_tab == 'pagos') {
    $tabla_payments = $wpdb->prefix . 'endtrack_payments';
    $payments = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s ORDER BY fecha DESC",
        $user_id,
        $active_launch
    ));
    ?>
                            <div class="card" style="margin-bottom: 30px;">
                                <h3>Instrucciones de Facturación</h3>
                                <?php
                                $billing_key = 'content_billing_methods_' . $active_launch;
                                $billing_content = isset($texts[$billing_key]) ? $texts[$billing_key] : (isset($texts['content_billing_methods']) ? $texts['content_billing_methods'] : '<p>No hay información de facturación disponible.</p>');
                                echo wpautop($billing_content);
                                ?>
                            </div>
                            <div class="card">
                                <h3>Historial de Pagos Recibidos</h3>
                                <p style="color:#666; margin-bottom:20px;">Aquí puedes ver el registro de todos los pagos que se te han realizado.
                                </p>

                                <table style="width:100%; border-collapse:collapse; text-align:left;">
                                    <tr
                                        style="background:rgba(20, 184, 166, 0.1); color:#14B8A6; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                                        <th style="padding:14px;">Fecha</th>
                                        <th style="padding:14px;">Monto</th>
                                        <th style="padding:14px;">Referencia</th>
                                        <th style="padding:14px;">Notas</th>
                                    </tr>
                                    <?php if ($payments): ?>
                                                                <?php foreach ($payments as $pay): ?>
                                                                                            <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                                                                                                onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                                                                                                <td style="padding:14px; color:#888; font-size:13px;">
                                                                                                    <?php echo date('d/m/Y H:i', strtotime($pay->fecha)); ?>
                                                                                                </td>
                                                                                                <td style="padding:14px; font-weight:800; color:#14B8A6; font-size:16px;">
                                                                                                    <?php echo number_format($pay->monto, 2, ',', '.'); ?> €
                                                                                                </td>
                                                                                                <td style="padding:14px; font-family:monospace; font-size:12px; color:#666;">
                                                                                                    <?php echo esc_html($pay->referencia); ?>
                                                                                                </td>
                                                                                                <td style="padding:14px; font-style:italic; color:#999; font-size:13px;">
                                                                                                    <?php echo esc_html($pay->notas); ?>
                                                                                                </td>
                                                                                            </tr>
                                                                                        <?php
                                                                endforeach; ?>
                                                            <?php
                                    else: ?>
                                                                <tr>
                                                                    <td colspan="4" style="padding:20px; text-align:center; color:#999;">No hay pagos registrados todavía.</td>
                                                                </tr>
                                                            <?php
                                    endif; ?>
                                </table>
                            </div>
                            <?php
}