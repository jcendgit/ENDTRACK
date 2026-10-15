<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$launches = get_option('endtrack_launches', array());
$launches = array_values($launches);

// Handle POST: admin sets default launch for the user (affiliate) panel
if (
    isset($_POST['endtrack_set_user_default_launch'])
    && isset($_POST['endtrack_user_default_launch_nonce'])
    && wp_verify_nonce($_POST['endtrack_user_default_launch_nonce'], 'endtrack_set_user_default_launch')
    && current_user_can('manage_options')
) {
    $new_default = sanitize_text_field($_POST['endtrack_set_user_default_launch']);
    if (in_array($new_default, $launches, true)) {
        update_option('endtrack_user_panel_default_launch', $new_default);
    }
}
$user_panel_default_launch = get_option('endtrack_user_panel_default_launch', '');

$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : (!empty($launches) ? end($launches) : '');

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

// Tasa de comision efectiva de un afiliado en un lanzamiento: primero la suya para
// ese lanzamiento; si no tiene, la comision general del lanzamiento que fija el
// admin; y si tampoco, 20%. Es la MISMA cadena que usa el panel del afiliado.
// Antes aqui estaba el 20% a fuego y se ignoraba la comision general: el afiliado
// veia un porcentaje en su panel y aqui se calculaba otro distinto.
if (!function_exists('endtrack_tasa_comision')) {
    function endtrack_tasa_comision($user_id, $launch, $launches) {
        $tasa = endtrack_get_user_meta_by_launch($user_id, 'endtrack_commission_rate', $launch, $launches);
        if ($tasa !== '') {
            return $tasa;
        }
        $safe    = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $launch);
        $general = get_option('endtrack_launch_commission_' . $safe, '');
        return ($general !== '' && is_numeric($general)) ? $general : 20;
    }
}

$tabla_datos = $wpdb->prefix . 'datos';
if ($active_launch == 'legacy') {
    $col_primer_reg = "primer_reg";
    $safe_launch = 'legacy';
} else {
    $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $col_primer_reg = "primer_reg_" . $safe_launch;
}

// Credenciales desde Integraciones (endtrack_texts) o constantes wp-config,
// igual que stats-ventas-fullscreen. Los defaults hardcodeados apuntaban a la
// BBDD de otra web y el panel no encontraba las ventas.
$__ext = endtrack_ext_db_config();
$ext_host = $__ext['host'];
$ext_user = $__ext['user'];
$ext_pass = $__ext['pass'];
$ext_name = $__ext['name'];

$opt_key = 'endtrack_ext_products_' . $safe_launch;
$launch_products = json_decode(get_option($opt_key, '[]'), true);
if (!is_array($launch_products))
    $launch_products = [];

$ext_buyers_by_af  = [];
$ext_unassigned_af = [];
$ext_no_comision   = [];
$ext_db_error      = null;
$use_ext_db        = !empty($launch_products) && !empty($active_launch);

if ($use_ext_db) {
    try {
        $attributed = $wpdb->get_results(
            "SELECT DISTINCT correo, afiliado
             FROM {$tabla_datos}
             WHERE {$col_primer_reg} = 1
               AND afiliado > 0
               AND correo != '' AND correo IS NOT NULL"
        );
        $correo_to_af = [];
        foreach ($attributed as $_lr) {
            $key = strtolower(trim($_lr->correo));
            if ($key !== '' && !isset($correo_to_af[$key]))
                $correo_to_af[$key] = intval($_lr->afiliado);
        }

        $pdo = new PDO(
            "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
            $ext_user,
            $ext_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
        );

        $ph = implode(',', array_fill(0, count($launch_products), '?'));

        $stmt = $pdo->prepare("
            SELECT
                LOWER(TRIM(U.mail))               AS mail,
                IFNULL(A.importe, 0)              AS importe,
                A.producto,
                IFNULL(A.ref, '')                 AS pdc_ref,
                IFNULL(A.source, '')              AS pdc_source,
                IFNULL(A.no_comision_afiliado, 0) AS no_comision_afiliado
            FROM pdc_pagos A
            INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
            WHERE A.ID_producto IN ({$ph})
              AND A.estado IN ('Completado','Compra')
        ");
        $stmt->execute($launch_products);
        $ext_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo = null;

        $counted_buyers    = [];
        $counted_unassigned = [];
        foreach ($ext_rows as $r) {
            $mail        = strtolower(trim((string) $r['mail']));
            $pdc_ref_int = intval($r['pdc_ref']);
            $pdc_source  = trim((string) $r['pdc_source']);

            $af = isset($correo_to_af[$mail]) ? $correo_to_af[$mail] : 0;
            if ($af <= 0 && $pdc_ref_int > 0)
                $af = $pdc_ref_int;

            // Solo capturar no_comision si la venta tiene afiliado asignado
            if ($af > 0 && intval($r['no_comision_afiliado']) === 1) {
                $ext_no_comision[] = [
                    'correo'     => $mail,
                    'importe'    => floatval($r['importe']),
                    'producto'   => (string) $r['producto'],
                    'pdc_ref'    => (string) $r['pdc_ref'],
                    'pdc_source' => $pdc_source,
                    'af'         => $af,
                ];
            }

            if ($af <= 0) {
                if (
                    !isset($counted_unassigned[$mail]) && (
                        strtolower($pdc_source) === 'afiliado' ||
                        ($r['pdc_ref'] !== '' && !is_numeric($r['pdc_ref']))
                    )
                ) {
                    $ext_unassigned_af[] = [
                        'correo'     => $mail,
                        'importe'    => floatval($r['importe']),
                        'producto'   => (string) $r['producto'],
                        'pdc_ref'    => (string) $r['pdc_ref'],
                        'pdc_source' => $pdc_source,
                    ];
                    $counted_unassigned[$mail] = true;
                }
                continue;
            }

            if (!isset($ext_buyers_by_af[$af]))
                $ext_buyers_by_af[$af] = ['af' => $af, 'pr' => 0, 'precio_t' => 0.0, 'buyers' => []];

            $buyer_key = $af . '|' . $mail;
            if (!isset($counted_buyers[$buyer_key])) {
                $ext_buyers_by_af[$af]['pr']++;
                $counted_buyers[$buyer_key] = true;
            }
            $ext_buyers_by_af[$af]['precio_t'] += floatval($r['importe']);
            $ext_buyers_by_af[$af]['buyers'][] = [
                'correo'   => $mail,
                'importe'  => floatval($r['importe']),
                'producto' => (string) $r['producto'],
            ];
        }

        uasort($ext_buyers_by_af, function ($a, $b) {
            return $b['pr'] - $a['pr'];
        });

    } catch (Exception $e) {
        $ext_db_error = $e->getMessage();
        $use_ext_db   = false;
    }
}

// ── Lanzamiento "Evento" ──────────────────────────────────────────────────────
// Sus ventas no están en pdc_pagos sino en la BBDD de eventos.pablogiltrader.com
// ({prefix}tc_ventas), donde la columna `afiliado` guarda el ID del afiliado (el
// ?ref=<id> del enlace). Se rellena $ext_buyers_by_af con la MISMA forma que el
// bloque de PDC de arriba, así todo el render posterior —totales del dashboard,
// ranking, pestaña de ventas y cálculo de comisiones— funciona sin tocarlo.
// Solo se cuentan las ventas con ID numérico de afiliado: las que llegan con
// `source=afiliado` pero sin ID (p. ej. medium=angelpardo) no se pueden asignar.
$is_eventos_launch = function_exists('endtrack_is_eventos_launch') && endtrack_is_eventos_launch($active_launch);

if ($is_eventos_launch) {
    $ext_buyers_by_af  = [];
    $ext_unassigned_af = [];
    $ext_no_comision   = [];
    $ext_db_error      = null;
    $use_ext_db        = false;

    $pdo_ev = function_exists('endtrack_eventos_pdo') ? endtrack_eventos_pdo() : null;
    if (!$pdo_ev) {
        $ext_db_error = 'No se pudo conectar con la BBDD de eventos (revisa las constantes EVENTOS_DB_* en wp-config.php).';
    } else {
        try {
            $t_ev = endtrack_eventos_prefix() . 'tc_ventas';
            $rows_ev = $pdo_ev->query(
                "SELECT afiliado,
                        LOWER(TRIM(mail)) AS mail,
                        IFNULL(precio, 0) AS importe,
                        producto
                   FROM `{$t_ev}`
                  WHERE (devolucion IS NULL OR devolucion = 0)
                    AND afiliado REGEXP '^[0-9]+$'
                    AND afiliado + 0 > 0"
            )->fetchAll(PDO::FETCH_ASSOC);

            $counted_ev = [];
            foreach ($rows_ev as $r) {
                $af   = intval($r['afiliado']);
                $mail = (string) $r['mail'];

                if (!isset($ext_buyers_by_af[$af])) {
                    $ext_buyers_by_af[$af] = ['af' => $af, 'pr' => 0, 'precio_t' => 0.0, 'buyers' => []];
                }

                // "pr" cuenta compradores únicos, no filas: un mismo comprador con
                // varias entradas o plazos no debe contar como varias ventas.
                $buyer_key = $af . '|' . $mail;
                if (!isset($counted_ev[$buyer_key])) {
                    $ext_buyers_by_af[$af]['pr']++;
                    $counted_ev[$buyer_key] = true;
                }

                $ext_buyers_by_af[$af]['precio_t'] += floatval($r['importe']);
                $ext_buyers_by_af[$af]['buyers'][] = [
                    'correo'   => $mail,
                    'importe'  => floatval($r['importe']),
                    'producto' => (string) $r['producto'],
                ];
            }

            uasort($ext_buyers_by_af, function ($a, $b) {
                return $b['pr'] - $a['pr'];
            });

            $use_ext_db = true;
        } catch (Exception $e) {
            $ext_db_error = $e->getMessage();
            $use_ext_db   = false;
        }
    }
    $pdo_ev = null;
}

// ------ STATS TAB ------
if ($active_tab == 'stats') {
    echo '<div class="glass-box text-center py-12">';
    echo '<h3>Panel de Estadísticas Pro Activo</h3>';
    echo '<p class="opacity-70 mb-6">Las estadísticas nativas ahora se visualizan en una pantalla optimizada de alto rendimiento.</p>';
    echo '<a href="' . home_url('/endtrack-estadisticas/') . '" target="_blank" class="btn btn-primary" style="background:#6366f1; color:white; padding:12px 25px; border-radius:12px; text-decoration:none; font-weight:700;">Abrir Estadísticas Pro <i class="fas fa-external-link-alt ml-2"></i></a>';
    echo '</div>';
}

// ------ DASHBOARD TAB ------
if ($active_tab == 'dashboard') {

    if ($use_ext_db) {
        $total_sales = array_sum(array_column($ext_buyers_by_af, 'pr'));
    } elseif (!empty($active_launch)) {
        $total_sales = $wpdb->get_var("SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE $col_primer_reg = 2 AND afiliado IS NOT NULL AND afiliado != '' AND afiliado != 'No tiene afiliado'");
    } else {
        $total_sales = 0;
    }

    $total_revenue       = 0.0;
    $total_comm_generated = 0;
    if ($use_ext_db) {
        foreach ($ext_buyers_by_af as $af_data) {
            $total_revenue += $af_data['precio_t'];
            $rate = endtrack_get_user_meta_by_launch($af_data['af'], 'endtrack_commission_rate', $active_launch, $launches);
            if ($rate === '')
                $rate = 20;
            $total_comm_generated += $af_data['precio_t'] * 0.96 * (floatval($rate) / 100);
        }
    }

    $tabla_payments   = $wpdb->prefix . 'endtrack_payments';
    $total_comm_paid  = 0;
    $total_upfront    = 0;
    $total_pending    = 0;
    if (!empty($active_launch)) {
        $total_comm_paid = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE lanzamiento = %s", $active_launch));

        $all_afs = $wpdb->get_col("SELECT U.ID FROM {$wpdb->users} U INNER JOIN {$wpdb->usermeta} M ON M.user_id = U.ID AND M.meta_key = '{$wpdb->prefix}capabilities' WHERE M.meta_value LIKE '%afiliado%'");
        foreach ($all_afs as $_afid) {
            $up = endtrack_get_user_meta_by_launch($_afid, 'endtrack_upfront_payment', $active_launch, $launches);
            $up = ($up !== '') ? floatval($up) : 0.0;
            $total_upfront += $up;

            $rate_af = endtrack_get_user_meta_by_launch($_afid, 'endtrack_commission_rate', $active_launch, $launches);
            if ($rate_af === '')
                $rate_af = 20;
            $comm_af = isset($ext_buyers_by_af[$_afid])
                ? $ext_buyers_by_af[$_afid]['precio_t'] * 0.96 * (floatval($rate_af) / 100)
                : 0.0;
            $paid_af = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s",
                $_afid,
                $active_launch
            ));
            $pending_af = $comm_af - $up - $paid_af;
            if ($pending_af > 0)
                $total_pending += $pending_af;
        }
    }

    $leads_by_af = [];
    if (!empty($active_launch)) {
        $_leads_rows = $wpdb->get_results(
            "SELECT afiliado, COUNT(DISTINCT correo) as total
             FROM $tabla_datos
             WHERE ($col_primer_reg = 1 OR $col_primer_reg = 2)
               AND afiliado > 0
             GROUP BY afiliado"
        );
        foreach ($_leads_rows as $_lr)
            $leads_by_af[intval($_lr->afiliado)] = intval($_lr->total);
    }

    $ranking_ventas = [];
    $ranking_conv   = [];
    if ($use_ext_db) {
        foreach ($ext_buyers_by_af as $af_data) {
            $af_id  = $af_data['af'];
            $leads  = isset($leads_by_af[$af_id]) ? $leads_by_af[$af_id] : 0;
            $sales  = $af_data['pr'];
            $conv   = ($leads > 0) ? round($sales / $leads * 100, 1) : 0;
            $uinfo  = get_userdata($af_id);
            $name   = $uinfo ? $uinfo->display_name : 'ID ' . $af_id;
            $ranking_ventas[] = ['id' => $af_id, 'name' => $name, 'sales' => $sales, 'leads' => $leads, 'conv' => $conv];
            $ranking_conv[]   = ['id' => $af_id, 'name' => $name, 'sales' => $sales, 'leads' => $leads, 'conv' => $conv];
        }
        usort($ranking_ventas, function ($a, $b) { return $b['sales'] - $a['sales']; });
        usort($ranking_conv,   function ($a, $b) { return $b['conv'] <=> $a['conv']; });
        $ranking_ventas = array_slice($ranking_ventas, 0, 10);
        $ranking_conv   = array_slice($ranking_conv,   0, 10);
    }
    ?>

    <?php if (current_user_can('manage_options') && !empty($active_launch) && $active_launch !== 'legacy'): ?>
    <div class="card" style="display:flex; justify-content:space-between; align-items:center; gap:14px; margin-bottom:16px; padding:12px 18px; background:rgba(99,102,241,0.06); border:1px solid rgba(99,102,241,0.15);">
        <div style="font-size:13px; color:#444;">
            <i class="fas fa-star" style="color:#F59E0B; margin-right:6px;"></i>
            <strong>Lanzamiento por defecto en el panel de afiliados:</strong>
            <span style="color:#6366F1; font-weight:700; margin-left:6px;">
                <?php echo !empty($user_panel_default_launch) ? esc_html(ucfirst($user_panel_default_launch)) : 'No definido (usa el último creado)'; ?>
            </span>
        </div>
        <?php if ($user_panel_default_launch !== $active_launch): ?>
        <form method="POST" style="margin:0;">
            <?php wp_nonce_field('endtrack_set_user_default_launch', 'endtrack_user_default_launch_nonce'); ?>
            <input type="hidden" name="endtrack_set_user_default_launch" value="<?php echo esc_attr($active_launch); ?>">
            <button type="submit" class="btn-outline" style="font-size:12px; padding:6px 14px; cursor:pointer;">
                <i class="fas fa-check"></i> Marcar "<?php echo esc_html(ucfirst($active_launch)); ?>" como default
            </button>
        </form>
        <?php else: ?>
        <span style="font-size:12px; color:#10B981; font-weight:600;"><i class="fas fa-check-circle"></i> Activo</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns:repeat(6,1fr); gap:14px; margin-bottom:20px;">
    <?php
    $dash_cards = [
        ['icon' => 'fa-shopping-cart',    'label' => 'Ventas Afiliados',     'value' => number_format($total_sales),                            'color' => '#10B981', 'bg' => 'rgba(16,185,129,0.08)'],
        ['icon' => 'fa-euro-sign',         'label' => 'Total Ventas (€)',      'value' => number_format($total_revenue, 2, ',', '.') . ' €',      'color' => '#0EA5E9', 'bg' => 'rgba(14,165,233,0.08)'],
        ['icon' => 'fa-coins',             'label' => 'Comisiones Generadas',  'value' => number_format($total_comm_generated, 2, ',', '.') . ' €','color' => '#6366F1', 'bg' => 'rgba(99,102,241,0.08)'],
        ['icon' => 'fa-hand-holding-usd',  'label' => 'Upfront Pagado',        'value' => number_format($total_upfront, 2, ',', '.') . ' €',       'color' => '#F59E0B', 'bg' => 'rgba(245,158,11,0.08)'],
        ['icon' => 'fa-check-circle',      'label' => 'Comisiones Pagadas',    'value' => number_format($total_comm_paid, 2, ',', '.') . ' €',     'color' => '#14B8A6', 'bg' => 'rgba(20,184,166,0.08)'],
        [
            'icon'  => 'fa-clock',
            'label' => 'Pendiente de Pago',
            'value' => number_format($total_pending, 2, ',', '.') . ' €',
            'color' => ($total_pending > 0 ? '#EF4444' : '#10B981'),
            'bg'    => ($total_pending > 0 ? 'rgba(239,68,68,0.08)' : 'rgba(16,185,129,0.08)'),
        ],
    ];
    foreach ($dash_cards as $dc): ?>
        <div class="card" style="background:<?php echo $dc['bg']; ?>; padding:18px 16px; display:flex; flex-direction:column; gap:6px; min-width:0;">
            <div style="display:flex; align-items:center; gap:7px;">
                <i class="fas <?php echo $dc['icon']; ?>" style="color:<?php echo $dc['color']; ?>; font-size:14px; flex-shrink:0;"></i>
                <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo $dc['label']; ?></span>
            </div>
            <div style="font-size:clamp(14px,1.5vw,24px); font-weight:800; color:<?php echo $dc['color']; ?>; line-height:1.2; word-break:break-word;"><?php echo $dc['value']; ?></div>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($ranking_ventas)): ?>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">
        <div class="card">
            <h3 style="margin-top:0;">Top Ventas</h3>
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <tr style="background:rgba(16,185,129,0.1); color:#10B981; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">
                    <th style="padding:10px 14px;">#</th>
                    <th style="padding:10px 14px;">Afiliado</th>
                    <th style="padding:10px 14px; text-align:right;">Leads</th>
                    <th style="padding:10px 14px; text-align:right;">Ventas</th>
                    <th style="padding:10px 14px; text-align:right;">Conv.</th>
                </tr>
                <?php foreach ($ranking_ventas as $i => $row): ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05);">
                    <td style="padding:10px 14px; color:#999; font-weight:700;"><?php echo $i + 1; ?></td>
                    <td style="padding:10px 14px; font-weight:600; color:#333;"><?php echo esc_html($row['name']); ?></td>
                    <td style="padding:10px 14px; text-align:right; color:#6366F1; font-weight:600;"><?php echo $row['leads']; ?></td>
                    <td style="padding:10px 14px; text-align:right; font-weight:800; color:#10B981; font-size:15px;"><?php echo $row['sales']; ?></td>
                    <td style="padding:10px 14px; text-align:right; color:#888;"><?php echo $row['conv']; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card">
            <h3 style="margin-top:0;">Top Conversión <small style="font-size:12px; color:#999; font-weight:400;">(ventas/leads)</small></h3>
            <table style="width:100%; border-collapse:collapse; text-align:left;">
                <tr style="background:rgba(99,102,241,0.1); color:#6366F1; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">
                    <th style="padding:10px 14px;">#</th>
                    <th style="padding:10px 14px;">Afiliado</th>
                    <th style="padding:10px 14px; text-align:right;">Leads</th>
                    <th style="padding:10px 14px; text-align:right;">Ventas</th>
                    <th style="padding:10px 14px; text-align:right;">Conv.</th>
                </tr>
                <?php foreach ($ranking_conv as $i => $row): ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05);">
                    <td style="padding:10px 14px; color:#999; font-weight:700;"><?php echo $i + 1; ?></td>
                    <td style="padding:10px 14px; font-weight:600; color:#333;"><?php echo esc_html($row['name']); ?></td>
                    <td style="padding:10px 14px; text-align:right; color:#6366F1; font-weight:600;"><?php echo $row['leads']; ?></td>
                    <td style="padding:10px 14px; text-align:right; font-weight:700; color:#10B981;"><?php echo $row['sales']; ?></td>
                    <td style="padding:10px 14px; text-align:right; font-weight:800; color:#6366F1; font-size:15px;"><?php echo $row['conv']; ?>%</td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>
    <?php
}

// ------ REGISTRADOS TAB ------
if ($active_tab == 'registrados') {

    $afiliados = $wpdb->get_results("SELECT U.ID, U.user_login, U.user_email, U.user_registered FROM {$wpdb->users} U INNER JOIN {$wpdb->usermeta} M ON M.user_id = U.ID AND M.meta_key = '{$wpdb->prefix}capabilities' WHERE M.meta_value LIKE '%afiliado%'");
    ?>
    <div class="card">
        <h3>Afiliados Registrados (Total: <?php echo count($afiliados); ?>)</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr style="background:rgba(0,0,0,0.02); color:#666; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">ID</th>
                <th style="padding:14px;">Usuario</th>
                <th style="padding:14px;">Email</th>
                <th style="padding:14px;">Fecha Registro</th>
                <th style="padding:14px;">Última Conexión</th>
                <th style="padding:14px;">Comisión (%)</th>
                <th style="padding:14px;">Upfront (€)</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($afiliados as $a): ?>
            <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition:background 0.2s;"
                onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                <td style="padding:14px; font-weight:500; color:#888;"><?php echo $a->ID; ?></td>
                <td style="padding:14px; font-weight:700; color:#333;"><?php echo $a->user_login; ?></td>
                <td style="padding:14px; color:#666;"><?php echo $a->user_email; ?></td>
                <td style="padding:14px; color:#888; font-size:13px;"><?php echo date('Y-m-d', strtotime($a->user_registered)); ?></td>
                <td style="padding:14px;">
                    <?php
                    $last_log = get_user_meta($a->ID, 'endtrack_last_login', true);
                    if ($last_log) {
                        echo '<span style="color:#10B981; font-weight:600;">' . date('d/m/Y H:i', strtotime($last_log)) . '</span>';
                    } else {
                        echo '<span style="color:#999; font-style:italic;">Nunca</span>';
                    }
                    ?>
                </td>
                <td style="padding:14px;">
                    <?php $comm = endtrack_get_user_meta_by_launch($a->ID, 'endtrack_commission_rate', $active_launch, $launches);
                    if ($comm === '') $comm = 20; ?>
                    <div style="display:flex; align-items:center; gap:5px;">
                        <input type="number" class="endtrack-comm-input" data-user-id="<?php echo $a->ID; ?>"
                            value="<?php echo esc_attr($comm); ?>"
                            style="width:50px; padding:6px; border-radius:8px; border:1px solid rgba(0,0,0,0.1); background:rgba(255,255,255,0.5); font-weight:600; text-align:center;">
                        <span style="font-weight:600; color:#666;">%</span>
                    </div>
                </td>
                <td style="padding:14px;">
                    <?php $upfront_val = endtrack_get_user_meta_by_launch($a->ID, 'endtrack_upfront_payment', $active_launch, $launches);
                    if ($upfront_val === '') $upfront_val = 0; ?>
                    <div style="display:flex; align-items:center; gap:5px;">
                        <input type="number" step="0.01" class="endtrack-upfront-input" data-user-id="<?php echo $a->ID; ?>"
                            value="<?php echo esc_attr($upfront_val); ?>"
                            style="width:70px; padding:6px; border-radius:8px; border:1px solid rgba(0,0,0,0.1); background:rgba(255,255,255,0.5); font-weight:600; text-align:center;">
                        <span style="font-weight:600; color:#666;">€</span>
                    </div>
                </td>
                <td style="padding:14px; display:flex; gap:8px;">
                    <a href="<?php echo add_query_arg('impersonate', $a->ID, site_url('/endtrack-panel-afiliado/')); ?>"
                        style="background:var(--primary-color); color:#fff; padding:6px 14px; border-radius:12px; text-decoration:none; font-size:11px; font-weight:700; box-shadow:0 2px 8px rgba(79,70,229,0.2);"
                        target="_blank">Ver Panel</a>
                    <button class="btn-delete-affiliate" data-user-id="<?php echo $a->ID; ?>"
                        data-user-login="<?php echo esc_attr($a->user_login); ?>"
                        style="background:rgba(239,68,68,0.1); color:#EF4444; border:none; padding:6px 14px; border-radius:12px; cursor:pointer; font-size:11px; font-weight:700; transition:all 0.2s;">
                        Borrar
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <script>
    jQuery(document).ready(function ($) {
        $('.endtrack-comm-input').on('change', function () {
            var $input = $(this);
            var userId = $input.data('user-id');
            var rate   = $input.val();
            $input.css('background', '#fff3cd');
            $.ajax({
                url: "<?php echo admin_url('admin-ajax.php'); ?>",
                type: "POST",
                data: { action: 'endtrack_save_commission', user_id: userId, rate: rate, launch: <?php echo json_encode($active_launch); ?> },
                success: function (response) {
                    if (response.success) {
                        $input.css('background', '#d4edda');
                        setTimeout(function () { $input.css('background', '#fff'); }, 1000);
                    } else {
                        alert('Error: ' + response.data);
                        $input.css('background', '#f8d7da');
                    }
                }
            });
        });

        $('.endtrack-upfront-input').on('change', function () {
            var $input = $(this);
            var userId = $input.data('user-id');
            var amount = $input.val();
            $input.css('background', '#fff3cd');
            $.ajax({
                url: "<?php echo admin_url('admin-ajax.php'); ?>",
                type: "POST",
                data: { action: 'endtrack_save_upfront', user_id: userId, amount: amount, launch: <?php echo json_encode($active_launch); ?> },
                success: function (response) {
                    if (response.success) {
                        $input.css('background', '#d4edda');
                        setTimeout(function () { $input.css('background', '#fff'); }, 1000);
                    } else {
                        alert('Error: ' + response.data);
                        $input.css('background', '#f8d7da');
                    }
                }
            });
        });

        $('.btn-delete-affiliate').on('click', function () {
            var $btn      = $(this);
            var userId    = $btn.data('user-id');
            var userLogin = $btn.data('user-login');
            if (confirm('¿Estás seguro de que deseas eliminar permanentemente al usuario "' + userLogin + '"? Esta acción no se puede deshacer.')) {
                $btn.prop('disabled', true).text('Borrando...');
                $.ajax({
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    type: "POST",
                    data: {
                        action:    'endtrack_delete_affiliate',
                        user_id:   userId,
                        nonce:     '<?php echo wp_create_nonce("endtrack_delete_user_nonce"); ?>'
                    },
                    success: function (response) {
                        if (response.success) {
                            $btn.closest('tr').fadeOut(500, function () { $(this).remove(); });
                        } else {
                            alert('Error: ' + response.data);
                            $btn.prop('disabled', false).text('Borrar');
                        }
                    },
                    error: function () {
                        alert('Hubo un problema al procesar la solicitud.');
                        $btn.prop('disabled', false).text('Borrar');
                    }
                });
            }
        });
    });
    </script>
    <?php
}

// ------ LEADS TAB ------
if ($active_tab == 'leads') {

    // El evento es venta directa: no hay registro previo, así que no tiene leads
    // propios. Antes caía en la consulta de abajo, que NO filtra por lanzamiento
    // y mostraba el ranking de leads global de inscripciones como si fuera del
    // evento. Mejor decirlo que enseñar un dato que no es.
    if ($is_eventos_launch) {
        ?>
        <div class="card">
            <h3>Leads (Evento)</h3>
            <p style="color:#6c757d; margin:8px 0 0;">
                El evento se vende directamente, sin registro previo, así que no genera leads propios.
                Las ventas de afiliados de este lanzamiento están en la pestaña
                <a href="<?php echo esc_url(add_query_arg(['launch' => $active_launch, 'tab' => 'ventas'])); ?>"
                   style="color:var(--primary-color); font-weight:700;">Ventas</a>.
            </p>
        </div>
        <?php
        return;
    }

    if (!empty($active_launch)) {
        $leads = $wpdb->get_results("
            SELECT afiliado, COUNT(distinct(correo)) as total
            FROM $tabla_datos
            WHERE ($col_primer_reg = 1 OR $col_primer_reg = 2)
            AND afiliado IS NOT NULL AND afiliado != '' AND afiliado != 'No tiene afiliado'
            GROUP by afiliado ORDER BY total DESC
        ");
    } else {
        $leads = [];
    }
    ?>
    <div class="card">
        <h3>Ranking de Leads (<?php echo ($active_launch == 'legacy') ? 'Lanzamientos Anteriores' : ucfirst($active_launch); ?>)</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr style="background:rgba(99,102,241,0.1); color:#6366F1; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">Afiliado ID</th>
                <th style="padding:14px;">Nombre</th>
                <th style="padding:14px;">Total Leads</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($leads as $l):
                $aff_id_raw  = trim($l->afiliado);
                $user_info   = get_userdata($aff_id_raw);
                if (!$user_info) $user_info = get_user_by('login', $aff_id_raw);
                if (!$user_info) $user_info = get_user_by('email', $aff_id_raw);
                $display_name = $user_info ? $user_info->display_name : 'Desconocido';
                $final_aff_id = $user_info ? $user_info->ID : $aff_id_raw;
            ?>
            <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition:background 0.2s;"
                onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                <td style="padding:14px; color:#555;"><?php echo esc_html($final_aff_id); ?></td>
                <td style="padding:14px; color:#333; font-weight:600;"><?php echo esc_html($display_name); ?></td>
                <td style="padding:14px; font-weight:800; color:#6366F1; font-size:16px;"><?php echo $l->total; ?></td>
                <td style="padding:14px;">
                    <a href="<?php echo add_query_arg('impersonate', $final_aff_id, site_url('/endtrack-panel-afiliado/')); ?>"
                        style="background:var(--primary-color); color:#fff; padding:6px 12px; border-radius:10px; text-decoration:none; font-size:11px; font-weight:700;"
                        target="_blank">Ver Panel</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}

// ------ VENTAS TAB ------
if ($active_tab == 'ventas') {

    if ($use_ext_db) {
        $sales_rows = $ext_buyers_by_af;
    } elseif (!empty($active_launch)) {
        $sales_rows = $wpdb->get_results("
            SELECT afiliado, COUNT(distinct(correo)) as total
            FROM $tabla_datos
            WHERE $col_primer_reg = 2
            AND afiliado IS NOT NULL AND afiliado != '' AND afiliado != 'No tiene afiliado'
            GROUP by afiliado ORDER BY total DESC
        ");
    } else {
        $sales_rows = [];
    }
    ?>
    <div class="card">
        <h3>
            Ranking de Ventas (<?php echo ($active_launch == 'legacy') ? 'Lanzamientos Anteriores' : ucfirst($active_launch); ?>)
            <?php if ($use_ext_db): ?><small style="font-size:13px;color:#6c757d;">— <?php echo $is_eventos_launch ? 'eventos.pablogiltrader.com' : 'pdc_pagos'; ?></small><?php endif; ?>
        </h3>
        <?php if ($ext_db_error): ?>
        <div style="background:#fff3cd;border:1px solid #ffc107;padding:8px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;">
            <strong>Aviso:</strong> Error DB externa, mostrando datos locales. <code><?php echo esc_html($ext_db_error); ?></code>
        </div>
        <?php endif; ?>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr style="background:rgba(16,185,129,0.1); color:#10B981; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">Afiliado ID</th>
                <th style="padding:14px;">Nombre</th>
                <th style="padding:14px;">Total Ventas</th>
                <?php if ($use_ext_db): ?><th style="padding:14px;">Importe (€)</th><?php endif; ?>
                <th style="padding:14px;">Comisión (%)</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php if ($use_ext_db): ?>
                <?php foreach ($sales_rows as $af_data):
                    $final_aff_id = $af_data['af'];
                    $user_info    = get_userdata($final_aff_id);
                    $display_name = $user_info ? $user_info->display_name : 'Desconocido';
                    $comm_rate    = endtrack_tasa_comision($final_aff_id, $active_launch, $launches);
                    $comision = $af_data['precio_t'] * 0.96 * (floatval($comm_rate) / 100);
                ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05);">
                    <td style="padding:14px; color:#555;"><?php echo esc_html($final_aff_id); ?></td>
                    <td style="padding:14px; color:#333; font-weight:600;"><?php echo esc_html($display_name); ?></td>
                    <td style="padding:14px; font-weight:800; color:#10B981; font-size:16px;"><?php echo $af_data['pr']; ?></td>
                    <td style="padding:14px; font-weight:700; color:#333;"><?php echo number_format($af_data['precio_t'], 2, ',', '.'); ?> €</td>
                    <td style="padding:14px; font-weight:600; color:#666;">
                        <?php echo esc_html($comm_rate); ?>%
                        <span style="color:#10B981;font-size:12px;">(≈ <?php echo number_format($comision, 2, ',', '.'); ?> €)</span>
                    </td>
                    <td style="padding:14px;">
                        <a href="<?php echo add_query_arg('impersonate', $final_aff_id, site_url('/endtrack-panel-afiliado/')); ?>"
                            style="background:#10B981; color:#fff; padding:6px 12px; border-radius:10px; text-decoration:none; font-size:11px; font-weight:700;"
                            target="_blank">Ver Panel</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($sales_rows as $s):
                    $aff_id_raw  = trim($s->afiliado);
                    $user_info   = get_userdata($aff_id_raw);
                    if (!$user_info) $user_info = get_user_by('login', $aff_id_raw);
                    if (!$user_info) $user_info = get_user_by('email', $aff_id_raw);
                    $display_name = $user_info ? $user_info->display_name : 'Desconocido';
                    $final_aff_id = $user_info ? $user_info->ID : $aff_id_raw;
                    $comm_rate    = endtrack_tasa_comision($final_aff_id, $active_launch, $launches);
                ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05);">
                    <td style="padding:14px; color:#555;"><?php echo esc_html($final_aff_id); ?></td>
                    <td style="padding:14px; color:#333; font-weight:600;"><?php echo esc_html($display_name); ?></td>
                    <td style="padding:14px; font-weight:800; color:#10B981; font-size:16px;"><?php echo $s->total; ?></td>
                    <td style="padding:14px; font-weight:600; color:#666;"><?php echo esc_html($comm_rate); ?>%</td>
                    <td style="padding:14px;">
                        <a href="<?php echo add_query_arg('impersonate', $final_aff_id, site_url('/endtrack-panel-afiliado/')); ?>"
                            style="background:#10B981; color:#fff; padding:6px 12px; border-radius:10px; text-decoration:none; font-size:11px; font-weight:700;"
                            target="_blank">Ver Panel</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($use_ext_db && !empty($ext_unassigned_af)): ?>
    <div class="card" style="margin-top:20px; border-left:4px solid #F59E0B;">
        <h3 style="color:#F59E0B;">
            ⚠ Ventas con source=afiliado sin ID asignado
            <small style="font-size:13px;color:#6c757d;font-weight:400;">— <?php echo count($ext_unassigned_af); ?> comprador(es) pendientes de asignar</small>
        </h3>
        <p style="font-size:13px;color:#555;margin-top:0;">
            Estas ventas vienen de un canal de afiliado (por pdc_pagos.ref o source) pero no se pudo determinar el ID numérico del afiliado en WordPress. Revísalos para asignarlos manualmente.
        </p>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr style="background:rgba(245,158,11,0.1); color:#92400E; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:10px 14px;">Email Comprador</th>
                <th style="padding:10px 14px;">Producto</th>
                <th style="padding:10px 14px;">Importe</th>
                <th style="padding:10px 14px;">pdc_ref (código)</th>
                <th style="padding:10px 14px;">Source (pdc_pagos)</th>
            </tr>
            <?php foreach ($ext_unassigned_af as $u): ?>
            <tr style="border-bottom:1px solid rgba(0,0,0,0.05);">
                <td style="padding:10px 14px; font-weight:600; color:#333;"><?php echo esc_html($u['correo']); ?></td>
                <td style="padding:10px 14px; color:#555;"><?php echo esc_html($u['producto'] ?: '—'); ?></td>
                <td style="padding:10px 14px; color:#333;"><?php echo $u['importe'] > 0 ? number_format($u['importe'], 2, ',', '.') . ' €' : '—'; ?></td>
                <td style="padding:10px 14px; font-family:monospace; color:#92400E;"><?php echo esc_html($u['pdc_ref'] ?: '(vacío)'); ?></td>
                <td style="padding:10px 14px; color:#666;"><?php echo esc_html($u['pdc_source'] ?: '—'); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($use_ext_db && !empty($ext_no_comision)): ?>
    <div class="card" style="margin-top:20px; border-left:4px solid #8B5CF6;">
        <h3 style="color:#8B5CF6;">
            🚫 Ventas sin comisión de afiliado
            <small style="font-size:13px;color:#6c757d;font-weight:400;">— <?php echo count($ext_no_comision); ?> venta(s) con no_comision_afiliado = 1</small>
        </h3>
        <p style="font-size:13px;color:#555;margin-top:0;">
            Estas ventas tienen <code>no_comision_afiliado = 1</code> en pdc_pagos y no generan comisión para el afiliado.
        </p>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr style="background:rgba(139,92,246,0.1); color:#5B21B6; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:10px 14px;">Afiliado</th>
                <th style="padding:10px 14px;">Email Comprador</th>
                <th style="padding:10px 14px;">Producto</th>
                <th style="padding:10px 14px;">Importe</th>
            </tr>
            <?php foreach ($ext_no_comision as $nc):
                $nc_user = get_userdata($nc['af']);
                $nc_name = $nc_user ? $nc_user->display_name : 'ID ' . $nc['af'];
            ?>
            <tr style="border-bottom:1px solid rgba(0,0,0,0.05);">
                <td style="padding:10px 14px; font-weight:600; color:#5B21B6;"><?php echo esc_html($nc_name); ?></td>
                <td style="padding:10px 14px; color:#333;"><?php echo esc_html($nc['correo']); ?></td>
                <td style="padding:10px 14px; color:#555;"><?php echo esc_html($nc['producto'] ?: '—'); ?></td>
                <td style="padding:10px 14px; color:#333;"><?php echo $nc['importe'] > 0 ? number_format($nc['importe'], 2, ',', '.') . ' €' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php
}

// ------ PAGOS TAB ------
if ($active_tab == 'pagos') {
    $afiliados      = $wpdb->get_results("SELECT U.ID, U.user_login, U.user_email, U.display_name FROM {$wpdb->users} U INNER JOIN {$wpdb->usermeta} M ON M.user_id = U.ID AND M.meta_key = '{$wpdb->prefix}capabilities' WHERE M.meta_value LIKE '%afiliado%'");
    $tabla_payments = $wpdb->prefix . 'endtrack_payments';
    $tabla_datos    = $wpdb->prefix . 'datos';
    $val_days       = 15;
    $val_date       = date('Y-m-d H:i:s', strtotime("-$val_days days"));
    ?>
    <div class="card">
        <h3>Gestión de Pagos a Afiliados</h3>
        <!-- Buscador: filtra la tabla al escribir (nombre, usuario, email o ID), sin recargar -->
        <div style="display:flex; align-items:center; gap:12px; margin:0 0 16px; flex-wrap:wrap;">
            <input type="search" id="et_buscar_afiliado" autocomplete="off"
                placeholder="Buscar afiliado por nombre, usuario o email…"
                style="flex:1; min-width:240px; max-width:460px; padding:12px 16px; border:1px solid rgba(0,0,0,0.12); border-radius:14px; font-family:inherit; font-size:14px; background:#fff;">
            <span id="et_buscar_contador" style="color:#888; font-size:13px;"><?php echo count($afiliados); ?> afiliados</span>
        </div>
        <table id="et_tabla_pagos" style="width:100%; border-collapse:collapse; text-align:left;">
            <tr style="background:rgba(20,184,166,0.1); color:#14B8A6; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">Afiliado</th>
                <th style="padding:14px;">Validadas (>15d)</th>
                <th style="padding:14px;">Upfront</th>
                <th style="padding:14px;">Total Pagado</th>
                <th style="padding:14px;">Pendiente</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($afiliados as $a):
                $comm_rate   = endtrack_tasa_comision($a->ID, $active_launch, $launches);
                $comm_factor = floatval($comm_rate) / 100;

                $total_validated_comm = 0;
                if ($use_ext_db) {
                    if (isset($ext_buyers_by_af[$a->ID]))
                        $total_validated_comm = $ext_buyers_by_af[$a->ID]['precio_t'] * 0.96 * $comm_factor;
                } else {
                    $launch_col = ($active_launch == 'legacy' || empty($active_launch)) ? 'primer_reg' : "primer_reg_$active_launch";
                    $has_col    = $wpdb->get_results("SHOW COLUMNS FROM $tabla_datos LIKE '$launch_col'");
                    if (empty($has_col)) $launch_col = 'primer_reg';

                    $sales_data = $wpdb->get_results($wpdb->prepare(
                        "SELECT total FROM $tabla_datos WHERE afiliado = %d AND $launch_col = 2 AND fecha <= %s",
                        $a->ID, $val_date
                    ));
                    foreach ($sales_data as $s) {
                        $val = floatval(str_replace(',', '.', $s->total));
                        $total_validated_comm += ($val * 0.96 * $comm_factor);
                    }
                }

                $total_paid = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s", $a->ID, $active_launch));
                if (!$total_paid) $total_paid = 0;

                $upfront = endtrack_get_user_meta_by_launch($a->ID, 'endtrack_upfront_payment', $active_launch, $launches);
                if ($upfront === '') $upfront = 0;
                $upfront = floatval($upfront);

                $pending = $total_validated_comm - $upfront - $total_paid;
            ?>
            <tr class="et-fila-afiliado" style="border-bottom:1px solid rgba(0,0,0,0.05); transition:background 0.2s;"
                data-busqueda="<?php echo esc_attr(strtolower($a->ID . ' ' . $a->user_login . ' ' . $a->user_email . ' ' . $a->display_name)); ?>"
                onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                <td style="padding:14px;">
                    <span style="font-weight:700; color:#333;"><?php echo esc_html($a->user_login); ?></span>
                    <?php if ($a->display_name !== '' && strcasecmp($a->display_name, $a->user_login) !== 0): ?>
                    <span style="color:#555; font-size:13px;"> · <?php echo esc_html($a->display_name); ?></span>
                    <?php endif; ?><br>
                    <span style="color:#888; font-size:12px;"><?php echo esc_html($a->user_email); ?></span>
                </td>
                <td style="padding:14px; font-weight:600; color:#555;"><?php echo number_format($total_validated_comm, 2, ',', '.'); ?> €</td>
                <td style="padding:14px; font-weight:600; color:<?php echo $upfront > 0 ? '#F59E0B' : '#999'; ?>;"><?php echo number_format($upfront, 2, ',', '.'); ?> €</td>
                <td style="padding:14px; color:#10B981; font-weight:600;"><?php echo number_format($total_paid, 2, ',', '.'); ?> €</td>
                <td style="padding:14px; font-weight:800; color:<?php echo $pending > 0 ? '#EF4444' : '#10B981'; ?>; font-size:15px;"><?php echo number_format($pending, 2, ',', '.'); ?> €</td>
                <td style="padding:14px;">
                    <button onclick="openPaymentForm(<?php echo $a->ID; ?>, '<?php echo esc_attr($a->user_login); ?>', <?php echo round($pending, 2); ?>)"
                        style="background:var(--primary-color); color:#fff; border:none; padding:8px 16px; border-radius:12px; cursor:pointer; font-size:11px; font-weight:700; box-shadow:0 4px 12px rgba(79,70,229,0.2); transition:all 0.2s;">
                        Registrar Pago
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr id="et_buscar_vacio" style="display:none;">
                <td colspan="6" style="padding:24px; text-align:center; color:#888;">Ningún afiliado coincide con la búsqueda.</td>
            </tr>
        </table>
    </div>
    <script>
    (function () {
        var campo = document.getElementById('et_buscar_afiliado');
        if (!campo) return;
        var filas = Array.prototype.slice.call(document.querySelectorAll('#et_tabla_pagos .et-fila-afiliado'));
        var contador = document.getElementById('et_buscar_contador');
        var vacio = document.getElementById('et_buscar_vacio');
        // Sin tildes ni mayúsculas: "jose" encuentra "José".
        function normalizar(t) {
            return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        }
        filas.forEach(function (f) { f.setAttribute('data-busqueda', normalizar(f.getAttribute('data-busqueda'))); });
        function filtrar() {
            var palabras = normalizar(campo.value).trim().split(/\s+/).filter(Boolean);
            var visibles = 0;
            filas.forEach(function (f) {
                var texto = f.getAttribute('data-busqueda');
                var ok = palabras.every(function (p) { return texto.indexOf(p) !== -1; });
                f.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            contador.textContent = palabras.length ? (visibles + ' de ' + filas.length + ' afiliados') : (filas.length + ' afiliados');
            vacio.style.display = visibles ? 'none' : '';
        }
        campo.addEventListener('input', filtrar);
        campo.focus();
    })();
    </script>

    <!-- Payment Form Modal -->
    <div id="paymentModal"
        style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:rgba(255,255,255,0.7) !important; backdrop-filter:blur(30px); -webkit-backdrop-filter:blur(30px); padding:40px; border-radius:32px; box-shadow:0 20px 50px rgba(0,0,0,0.15), inset 0 0 0 1px rgba(255,255,255,0.5); z-index:1000; width:450px; border:none;">
        <h3 id="modalTitle" style="margin-top:0; font-weight:800; font-size:22px; letter-spacing:-0.5px;">Registrar Pago</h3>
        <form id="paymentForm">
            <input type="hidden" id="pm_afiliado_id">
            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:700; margin-bottom:8px; font-size:13px; color:#555; text-transform:uppercase; letter-spacing:0.5px;">Monto (€):</label>
                <input type="number" step="0.01" id="pm_monto"
                    style="width:100%; padding:14px; border:1px solid rgba(0,0,0,0.1); border-radius:16px; background:rgba(255,255,255,0.5); font-family:inherit; font-weight:600; font-size:16px;"
                    required>
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:700; margin-bottom:8px; font-size:13px; color:#555; text-transform:uppercase; letter-spacing:0.5px;">Referencia/ID Transacción:</label>
                <input type="text" id="pm_referencia"
                    style="width:100%; padding:14px; border:1px solid rgba(0,0,0,0.1); border-radius:16px; background:rgba(255,255,255,0.5); font-family:inherit;"
                    placeholder="Ej: Stripe ch_...">
            </div>
            <div style="margin-bottom:25px;">
                <label style="display:block; font-weight:700; margin-bottom:8px; font-size:13px; color:#555; text-transform:uppercase; letter-spacing:0.5px;">Notas:</label>
                <textarea id="pm_notas"
                    style="width:100%; padding:14px; border:1px solid rgba(0,0,0,0.1); border-radius:16px; background:rgba(255,255,255,0.5); height:80px; font-family:inherit;"></textarea>
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" onclick="closePaymentModal()"
                    style="background:rgba(0,0,0,0.05); color:#333; border:none; padding:12px 24px; border-radius:16px; cursor:pointer; font-weight:600; transition:all 0.2s;">Cancelar</button>
                <button type="submit"
                    style="background:var(--primary-color); color:#fff; border:none; padding:12px 24px; border-radius:16px; cursor:pointer; font-weight:700; box-shadow:0 4px 12px rgba(79,70,229,0.3); transition:all 0.2s;">Guardar Pago</button>
            </div>
        </form>
    </div>
    <div id="modalOverlay" onclick="closePaymentModal()"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.1); backdrop-filter:blur(5px); -webkit-backdrop-filter:blur(5px); z-index:999;"></div>

    <script>
    function openPaymentForm(id, name, pending) {
        document.getElementById('pm_afiliado_id').value = id;
        document.getElementById('modalTitle').innerText  = 'Registrar Pago para ' + name;
        document.getElementById('pm_monto').value        = pending > 0 ? pending : '';
        document.getElementById('paymentModal').style.display  = 'block';
        document.getElementById('modalOverlay').style.display  = 'block';
    }
    function closePaymentModal() {
        document.getElementById('paymentModal').style.display  = 'none';
        document.getElementById('modalOverlay').style.display  = 'none';
        document.getElementById('paymentForm').reset();
    }
    jQuery(document).ready(function ($) {
        $('#paymentForm').on('submit', function (e) {
            e.preventDefault();
            var data = {
                action:      'endtrack_add_payment',
                afiliado_id: $('#pm_afiliado_id').val(),
                monto:       $('#pm_monto').val(),
                referencia:  $('#pm_referencia').val(),
                notas:       $('#pm_notas').val(),
                lanzamiento: '<?php echo esc_js($active_launch); ?>'
            };
            $.post("<?php echo admin_url('admin-ajax.php'); ?>", data, function (response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            });
        });
    });
    </script>
    <?php
}
