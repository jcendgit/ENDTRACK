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
// Filter launches by visibility
$visibility_map = get_option('endtrack_launch_visibility', array());
$launches = array_filter($launches, function ($launch) use ($visibility_map) {
    // Only show launches that are explicitly set to visible (true)
    return isset($visibility_map[$launch]) && $visibility_map[$launch] === true;
});
$launches = array_values($launches);

// Determine Active Launch for data filtering
// If we are in Dashboard or Links, we might want a launch selector.
// Let's assume the user selects a launch from a secondary nav or dropdown inside the Dashboard tab.
// OR: The Sidebar "Dashboard" shows a global overview, and we need a way to filter.
// Requirement: "Refactor to display tabs/sections for each created launch dynamically."
// In the sidebar design, we are currently at "Tabs" level.
// Let's implement a Sub-Tab system (Launches) inside the Dashboard tab.

$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : (!empty($launches) ? $launches[0] : '');

// Selector Removed (Global in Layout)
$comm_rate = get_user_meta($user_id, 'endtrack_commission_rate', true);
if ($comm_rate === '')
    $comm_rate = 25;
$comm_factor = floatval($comm_rate) / 100;

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

    if (!empty($has_l_col)) {
        // 1. Calculate Total Commission Validated (Available) for this launch
        $l_sales_val = $wpdb->get_results($wpdb->prepare(
            "SELECT total FROM $tabla_datos WHERE afiliado = %d AND $l_col = 2 AND fecha <= %s",
            $user_id,
            $val_date_global
        ));

        $total_comm_val = 0;
        foreach ($l_sales_val as $ls) {
            $total_comm_val += (floatval(str_replace(',', '.', $ls->total)) * 0.96 * $comm_factor);
        }

        // 2. Calculate Total Paid for this launch
        $total_paid_launch = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s", $user_id, $active_launch));

        // 3. Pending = Validated - Paid
        $active_launch_pending = $total_comm_val - $total_paid_launch;
    }
}

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

    if (!empty($active_launch)) {
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
                <?php endforeach; ?>
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
                    $sales_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s",
                        $user_id,
                        $date_from,
                        $date_to
                    ));
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
            $leads = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg = 1 OR $col_primer_reg = 2) ORDER BY fecha DESC LIMIT 10", $user_id));
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
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="3" style="padding:10px;">No hay actividad.</td>
                    </tr>
                <?php endif; ?>
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

        // Leads for this launch
        $stats['leads'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND ($col_primer_reg = 1 OR $col_primer_reg = 2) AND DATE(fecha) BETWEEN %s AND %s",
            $user_id,
            $date_from,
            $date_to
        ));

        // Sales for this launch
        $stats['sales'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s",
            $user_id,
            $date_from,
            $date_to
        ));

        // Lead-to-Sale Conversion
        $lts_conversion = ($stats['leads'] > 0) ? ($stats['sales'] / $stats['leads']) * 100 : 0;

        // Commission
        $tabla_afiliado_com = $wpdb->prefix . 'afiliados_a_compras';
        if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_afiliado_com'") == $tabla_afiliado_com) {
            $stats['commission'] = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(A.precio * $comm_factor) FROM $tabla_afiliado_com A INNER JOIN (SELECT correo FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg=2 AND DATE(fecha) BETWEEN %s AND %s GROUP BY correo) P ON P.correo = A.mail",
                $user_id,
                $date_from,
                $date_to
            ));
        }

        // Daily Sales for Charts
        $daily_sales = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(fecha) as day, COUNT(DISTINCT correo) as count FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 AND DATE(fecha) BETWEEN %s AND %s GROUP BY DATE(fecha) ORDER BY day ASC",
            $user_id,
            $date_from,
            $date_to
        ));

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
                <?php endforeach; ?>
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
                    echo number_format($total_comm_future, 2, ',', '.') . ' €';
                    ?>
                </div>
            </div>
            <div class="card stat-card stat-card-sales">
                <i class="fas fa-check-circle stat-icon"></i>
                <h4>Disponible (Validadas)</h4>
                <div class="stat-value">
                    <?php
                    // Sales > 15 days
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
                    echo number_format($total_comm_val, 2, ',', '.') . ' €';
                    ?>
                </div>
            </div>
            <div class="card stat-card stat-card-payments" style="background: var(--color-payments); color: white;">
                <i class="fas fa-hand-holding-usd stat-icon"></i>
                <h4>Pendiente de Cobro</h4>
                <div class="stat-value">
                    <?php
                    // Validated - Paid (Filtered by active launch)
                    $total_paid = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s", $user_id, $active_launch));
                    if (!$total_paid)
                        $total_paid = 0;
                    $pending_payout = $total_comm_val - $total_paid;
                    echo number_format(max(0, $pending_payout), 2, ',', '.') . ' €';
                    ?>
                </div>
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
            $sales_list = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2 ORDER BY fecha DESC LIMIT 50", $user_id));
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
                <?php if ($sales_list):
                    foreach ($sales_list as $s):
                        $email_masked = substr($s->correo, 0, 3) . '***';
                        ?>
                        <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                            onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                            <td style="padding:14px; color:#888; font-size:13px;"><?php echo $s->fecha; ?></td>
                            <td style="padding:14px; font-weight:600; color:#333;">
                                <?php echo esc_html($s->producto ? $s->producto : 'Producto General'); ?>
                            </td>
                            <td style="padding:14px; color:#666;">
                                <?php echo ($s->total !== null && $s->total !== '') ? number_format(floatval(str_replace(',', '.', $s->total)), 2, ',', '.') . ' €' : '-'; ?>
                            </td>
                            <td style="padding:14px; color:#888;"><?php echo $email_masked; ?></td>
                            <td style="padding:14px; font-weight:700; color:#10B981;">
                                <?php
                                if ($s->total !== null && $s->total !== '') {
                                    $val = floatval(str_replace(',', '.', $s->total));
                                    echo number_format(($val * 0.96 * $comm_factor), 2, ',', '.') . ' €';
                                    echo ' <span style="font-size:10px; opacity:0.6; font-weight:400;">(' . esc_html($comm_rate) . '%)</span>';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td style="padding:14px;">
                                <?php
                                $sale_date = strtotime($s->fecha);
                                $now = time();
                                $days_diff = floor(($now - $sale_date) / (60 * 60 * 24));
                                if ($days_diff >= 15) {
                                    $status_label = 'VALIDADO';
                                    $status_bg = 'rgba(16, 185, 129, 0.1)';
                                    $status_color = '#10B981';
                                } else {
                                    $status_label = 'PENDIENTE';
                                    $status_bg = 'rgba(245, 158, 11, 0.1)';
                                    $status_color = '#F59E0B';
                                }
                                ?>
                                <span
                                    style="background:<?php echo $status_bg; ?>; color:<?php echo $status_color; ?>; padding:4px 10px; border-radius:8px; font-size:11px; font-weight:700;">
                                    <?php echo $status_label; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="6" style="padding:10px;">No hay ventas registradas.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php
}

// ------ LINKS TAB ------
if ($active_tab == 'links') {
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
                array(
                    'taxonomy' => 'category',
                    'field' => 'slug',
                    'terms' => $launch_slug,
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
            <?php endforeach; ?>
            <script>
                function copyToClipboard(id) {
                    var copyText = document.getElementById(id);
                    copyText.select();
                    copyText.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(copyText.value);
                    alert("¡Enlace copiado!");
                }
            </script>
        <?php else: ?>
            <p class="description" style="font-style:italic;">No hay enlaces específicos configurados para este lanzamiento.</p>
        <?php endif; ?>

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
            <?php endif; ?>
            <?php if ($affiliate_link_venta): ?>
                <div>
                    <label
                        style="display:block; font-weight:600; margin-bottom:5px;"><?php echo esc_html($txt_links_venta); ?>:</label>
                    <input type="text" value="<?php echo esc_url($affiliate_link_venta); ?>"
                        style="width:100%; padding:15px; background:#f9f9f9; border:1px solid #ddd; font-family:monospace;" readonly
                        onclick="this.select()">
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
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
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="padding:20px; text-align:center; color:#999;">No hay pagos registrados todavía.</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php
}
