<?php
// Admin Panel Content Handler
// Tabs: registrados, leads, ventas

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$launches = get_option('endtrack_launches', array());
// Filter launches by visibility
$visibility_map = get_option('endtrack_launch_visibility', array());
$launches = array_filter($launches, function ($launch) use ($visibility_map) {
    // Only show launches that are explicitly set to visible (true)
    // If not in the map, default to hidden (false)
    return isset($visibility_map[$launch]) && $visibility_map[$launch] === true;
});
// Re-index array
$launches = array_values($launches);

$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : (!empty($launches) ? $launches[0] : '');

// Selector Removed (Global in Layout)

// Pre-calc vars
$tabla_datos = $wpdb->prefix . 'datos';
if ($active_launch == 'legacy') {
    $col_primer_reg = "primer_reg";
} else {
    $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $col_primer_reg = "primer_reg_" . $safe_launch;
}

// ------ DASHBOARD TAB ------
if ($active_tab == 'dashboard') {
    // 1. Total Affiliates (Removed per user request)
    // $afiliados_count = ...

    // 2. Total Sales for active launch (AFFILIATES ONLY)
    if (!empty($active_launch)) {
        // Filter by rows that actually have an affiliate assigned
        $total_sales = $wpdb->get_var("SELECT COUNT(DISTINCT correo) FROM $tabla_datos WHERE $col_primer_reg = 2 AND afiliado IS NOT NULL AND afiliado != '' AND afiliado != 'No tiene afiliado'");
    } else {
        $total_sales = 0;
    }

    // 3. Total Commissions (Generated) for active launch
    $total_comm_generated = 0;
    if (!empty($active_launch)) {
        // Get all sales with affiliate info
        $sales_data = $wpdb->get_results("SELECT total, afiliado FROM $tabla_datos WHERE $col_primer_reg = 2 AND afiliado IS NOT NULL AND afiliado != '' AND afiliado != 'No tiene afiliado'");

        foreach ($sales_data as $sale) {
            $aff_id = $sale->afiliado;
            // Get commission rate
            $rate = get_user_meta($aff_id, 'endtrack_commission_rate', true);
            if ($rate === '')
                $rate = 25;

            $val = floatval(str_replace(',', '.', $sale->total));
            // Formula: Value * 0.96 (fees) * commission_rate
            $commission = $val * 0.96 * (floatval($rate) / 100);
            $total_comm_generated += $commission;
        }
    }

    // 4. Total Commissions (Paid) for active launch
    $total_comm_paid = 0;
    $tabla_payments = $wpdb->prefix . 'endtrack_payments';
    if (!empty($active_launch)) {
        $total_comm_paid = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE lanzamiento = %s", $active_launch));
        if (!$total_comm_paid)
            $total_comm_paid = 0;
    }

    ?>
    <div class="stats-grid">
        <div class="card stat-card stat-card-sales">
            <i class="fas fa-shopping-cart stat-icon"></i>
            <h4>Ventas de Afiliados</h4>
            <div class="stat-value"><?php echo number_format($total_sales); ?></div>
        </div>
        <div class="card stat-card stat-card-conversion">
            <i class="fas fa-coins stat-icon"></i>
            <h4>Comisiones Generadas</h4>
            <div class="stat-value"><?php echo number_format($total_comm_generated, 2, ',', '.'); ?> €</div>
        </div>
        <div class="card stat-card stat-card-payments">
            <i class="fas fa-hand-holding-usd stat-icon"></i>
            <h4>Comisiones Pagadas</h4>
            <div class="stat-value"><?php echo number_format($total_comm_paid, 2, ',', '.'); ?> €</div>
        </div>
    </div>

    <div class="card">
        <h3>Resumen del Lanzamiento:
            <?php echo ($active_launch == 'legacy') ? 'Lanzamientos Anteriores' : ucfirst($active_launch); ?>
        </h3>
        <p>Selecciona un lanzamiento en el menú superior para ver las estadísticas específicas.</p>
    </div>
    <?php
}

// ------ REGISTRADOS TAB ------
if ($active_tab == 'registrados') {

    // List of Affiliates
    $afiliados = $wpdb->get_results("SELECT U.ID, U.user_login, U.user_email, U.user_registered FROM {$wpdb->users} U INNER JOIN {$wpdb->usermeta} M ON M.user_id = U.ID WHERE M.meta_value LIKE '%afiliado%'");

    ?>
    <div class="card">
        <h3>Afiliados Registrados (Total: <?php echo count($afiliados); ?>)</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr
                style="background:rgba(0,0,0,0.02); color:#666; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">ID</th>
                <th style="padding:14px;">Usuario</th>
                <th style="padding:14px;">Email</th>
                <th style="padding:14px;">Fecha Registro</th>
                <th style="padding:14px;">Última Conexión</th>
                <th style="padding:14px;">Comisión (%)</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($afiliados as $a): ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                    onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                    <td style="padding:14px; font-weight:500; color:#888;"><?php echo $a->ID; ?></td>
                    <td style="padding:14px; font-weight:700; color:#333;"><?php echo $a->user_login; ?></td>
                    <td style="padding:14px; color:#666;"><?php echo $a->user_email; ?></td>
                    <td style="padding:14px; color:#888; font-size:13px;">
                        <?php echo date('Y-m-d', strtotime($a->user_registered)); ?>
                    </td>
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
                        <?php $comm = get_user_meta($a->ID, 'endtrack_commission_rate', true);
                        if ($comm === '')
                            $comm = 25; ?>
                        <div style="display:flex; align-items:center; gap:5px;">
                            <input type="number" class="endtrack-comm-input" data-user-id="<?php echo $a->ID; ?>"
                                value="<?php echo esc_attr($comm); ?>"
                                style="width:50px; padding:6px; border-radius:8px; border:1px solid rgba(0,0,0,0.1); background:rgba(255,255,255,0.5); font-weight:600; text-align:center;">
                            <span style="font-weight:600; color:#666;">%</span>
                        </div>
                    </td>
                    <td style="padding:14px; display: flex; gap: 8px;">
                        <a href="<?php echo add_query_arg('impersonate', $a->ID, site_url('/endtrack-panel-afiliado/')); ?>"
                            style="background:var(--primary-color); color:#fff; padding:6px 14px; border-radius:12px; text-decoration:none; font-size:11px; font-weight:700; box-shadow:0 2px 8px rgba(79, 70, 229, 0.2);"
                            target="_blank">Ver Panel</a>
                        <button class="btn-delete-affiliate" data-user-id="<?php echo $a->ID; ?>"
                            data-user-login="<?php echo esc_attr($a->user_login); ?>"
                            style="background:rgba(239, 68, 68, 0.1); color:#EF4444; border:none; padding:6px 14px; border-radius:12px; cursor:pointer; font-size:11px; font-weight:700; transition:all 0.2s;">
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
                var rate = $input.val();

                $input.css('background', '#fff3cd');

                $.ajax({
                    url: "<?php echo admin_url('admin-ajax.php'); ?>",
                    type: "POST",
                    data: {
                        action: 'endtrack_save_commission',
                        user_id: userId,
                        rate: rate
                    },
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
                var $btn = $(this);
                var userId = $btn.data('user-id');
                var userLogin = $btn.data('user-login');

                if (confirm('¿Estás seguro de que deseas eliminar permanentemente al usuario "' + userLogin + '"? Esta acción no se puede deshacer.')) {
                    $btn.prop('disabled', true).text('Borrando...');

                    $.ajax({
                        url: "<?php echo admin_url('admin-ajax.php'); ?>",
                        type: "POST",
                        data: {
                            action: 'endtrack_delete_affiliate',
                            user_id: userId,
                            nonce: '<?php echo wp_create_nonce("endtrack_delete_user_nonce"); ?>'
                        },
                        success: function (response) {
                            if (response.success) {
                                $btn.closest('tr').fadeOut(500, function () {
                                    $(this).remove();
                                });
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
        <h3>Ranking de Leads
            (<?php echo ($active_launch == 'legacy') ? 'Lanzamientos Anteriores' : ucfirst($active_launch); ?>)</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr
                style="background:rgba(99, 102, 241, 0.1); color:#6366F1; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">Afiliado ID</th>
                <th style="padding:14px;">Nombre</th>
                <th style="padding:14px;">Total Leads</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($leads as $l):
                $aff_id_raw = trim($l->afiliado);
                $user_info = get_userdata($aff_id_raw);
                if (!$user_info)
                    $user_info = get_user_by('login', $aff_id_raw);
                if (!$user_info)
                    $user_info = get_user_by('email', $aff_id_raw);
                $display_name = $user_info ? $user_info->display_name : 'Desconocido';
                $final_aff_id = $user_info ? $user_info->ID : $aff_id_raw;
                ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                    onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                    <td style="padding:14px; color:#555;"><?php echo esc_html($final_aff_id); ?></td>
                    <td style="padding:14px; color:#333; font-weight:600;">
                        <?php echo esc_html($display_name); ?>
                    </td>
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

    // Mock Sales Logic (Count 'Buyer' status)
    if (!empty($active_launch)) {
        $sales = $wpdb->get_results("
			SELECT afiliado, COUNT(distinct(correo)) as total 
			FROM $tabla_datos 
			WHERE $col_primer_reg = 2 
			AND afiliado IS NOT NULL AND afiliado != '' AND afiliado != 'No tiene afiliado' 
			GROUP by afiliado ORDER BY total DESC
		");
    } else {
        $sales = [];
    }

    ?>
    <div class="card">
        <h3>Ranking de Ventas
            (<?php echo ($active_launch == 'legacy') ? 'Lanzamientos Anteriores' : ucfirst($active_launch); ?>)</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr
                style="background:rgba(16, 185, 129, 0.1); color:#10B981; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">Afiliado ID</th>
                <th style="padding:14px;">Nombre</th>
                <th style="padding:14px;">Total Ventas</th>
                <th style="padding:14px;">Comisión (%)</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($sales as $s):
                $aff_id_raw = trim($s->afiliado);
                $user_info = get_userdata($aff_id_raw);
                if (!$user_info)
                    $user_info = get_user_by('login', $aff_id_raw);
                if (!$user_info)
                    $user_info = get_user_by('email', $aff_id_raw);
                $display_name = $user_info ? $user_info->display_name : 'Desconocido';
                $final_aff_id = $user_info ? $user_info->ID : $aff_id_raw;
                ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                    onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                    <td style="padding:14px; color:#555;"><?php echo esc_html($final_aff_id); ?></td>
                    <td style="padding:14px; color:#333; font-weight:600;">
                        <?php echo esc_html($display_name); ?>
                    </td>
                    <td style="padding:14px; font-weight:800; color:#10B981; font-size:16px;"><?php echo $s->total; ?></td>
                    <td style="padding:14px; font-weight:600; color:#666;">
                        <?php
                        // Get custom rate
                        $comm_rate = get_user_meta($final_aff_id, 'endtrack_commission_rate', true);
                        if ($comm_rate === '')
                            $comm_rate = 25;
                        echo esc_html($comm_rate) . '%';
                        ?>
                    </td>
                    <td style="padding:14px;">
                        <a href="<?php echo add_query_arg('impersonate', $final_aff_id, site_url('/endtrack-panel-afiliado/')); ?>"
                            style="background:#10B981; color:#fff; padding:6px 12px; border-radius:10px; text-decoration:none; font-size:11px; font-weight:700;"
                            target="_blank">Ver Panel</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}

// ------ PAGOS TAB ------
if ($active_tab == 'pagos') {
    // List of Affiliates
    $afiliados = $wpdb->get_results("SELECT U.ID, U.user_login, U.user_email FROM {$wpdb->users} U INNER JOIN {$wpdb->usermeta} M ON M.user_id = U.ID WHERE M.meta_value LIKE '%afiliado%'");
    $tabla_payments = $wpdb->prefix . 'endtrack_payments';
    $tabla_datos = $wpdb->prefix . 'datos';
    $val_days = 15;
    $val_date = date('Y-m-d H:i:s', strtotime("-$val_days days"));

    ?>
    <div class="card">
        <h3>Gestión de Pagos a Afiliados</h3>
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <tr
                style="background:rgba(20, 184, 166, 0.1); color:#14B8A6; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;">
                <th style="padding:14px;">Afiliado</th>
                <th style="padding:14px;">Validadas (>15d)</th>
                <th style="padding:14px;">Total Pagado</th>
                <th style="padding:14px;">Pendiente</th>
                <th style="padding:14px;">Acciones</th>
            </tr>
            <?php foreach ($afiliados as $a):
                $comm_rate = get_user_meta($a->ID, 'endtrack_commission_rate', true);
                if ($comm_rate === '')
                    $comm_rate = 25;
                $comm_factor = floatval($comm_rate) / 100;

                // Validated Commissions (Total sales > 15 days ago)
                // Filter by ACTIVE LAUNCH
                $launch_col = ($active_launch == 'legacy' || empty($active_launch)) ? 'primer_reg' : "primer_reg_$active_launch";

                // Verify column exists to avoid errors
                $has_col = $wpdb->get_results("SHOW COLUMNS FROM $tabla_datos LIKE '$launch_col'");
                if (empty($has_col))
                    $launch_col = 'primer_reg';

                $sales_query = $wpdb->prepare(
                    "SELECT total FROM $tabla_datos WHERE afiliado = %d AND $launch_col = 2 AND fecha <= %s",
                    $a->ID,
                    $val_date
                );
                $sales_data = $wpdb->get_results($sales_query);

                $total_validated_comm = 0;
                foreach ($sales_data as $s) {
                    $val = floatval(str_replace(',', '.', $s->total));
                    // Apply common fee subtraction (4%) as seen in user-panel-content?
                    // Let's stick to the user-panel-content formula: $val * 0.96 * $comm_factor
                    $total_validated_comm += ($val * 0.96 * $comm_factor);
                }

                // Total Paid (Filtered by launch)
                $total_paid = $wpdb->get_var($wpdb->prepare("SELECT SUM(monto) FROM $tabla_payments WHERE afiliado_id = %d AND lanzamiento = %s", $a->ID, $active_launch));
                if (!$total_paid)
                    $total_paid = 0;

                $pending = $total_validated_comm - $total_paid;
                ?>
                <tr style="border-bottom:1px solid rgba(0,0,0,0.05); transition: background 0.2s;"
                    onmouseover="this.style.background='rgba(0,0,0,0.01)'" onmouseout="this.style.background='transparent'">
                    <td style="padding:14px;">
                        <span style="font-weight:700; color:#333;"><?php echo esc_html($a->user_login); ?></span><br>
                        <span style="color:#888; font-size:12px;"><?php echo esc_html($a->user_email); ?></span>
                    </td>
                    <td style="padding:14px; font-weight:600; color:#555;">
                        <?php echo number_format($total_validated_comm, 2, ',', '.'); ?> €
                    </td>
                    <td style="padding:14px; color: #10B981; font-weight:600;">
                        <?php echo number_format($total_paid, 2, ',', '.'); ?> €
                    </td>
                    <td
                        style="padding:14px; font-weight: 800; color: <?php echo $pending > 0 ? '#EF4444' : '#10B981'; ?>; font-size:15px;">
                        <?php echo number_format($pending, 2, ',', '.'); ?> €
                    </td>
                    <td style="padding:14px;">
                        <button
                            onclick="openPaymentForm(<?php echo $a->ID; ?>, '<?php echo esc_attr($a->user_login); ?>', <?php echo round($pending, 2); ?>)"
                            style="background:var(--primary-color); color:#fff; border:none; padding:8px 16px; border-radius:12px; cursor:pointer; font-size:11px; font-weight:700; box-shadow:0 4px 12px rgba(79, 70, 229, 0.2); transition:all 0.2s;">
                            Registrar Pago
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Payment Form Modal (Liquid Glass) -->
    <div id="paymentModal"
        style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:rgba(255,255,255,0.7) !important; backdrop-filter:blur(30px); -webkit-backdrop-filter:blur(30px); padding:40px; border-radius:32px; box-shadow:0 20px 50px rgba(0,0,0,0.15), inset 0 0 0 1px rgba(255,255,255,0.5); z-index:1000; width:450px; border:none;">
        <h3 id="modalTitle" style="margin-top:0; font-weight:800; font-size:22px; letter-spacing:-0.5px;">Registrar Pago
        </h3>
        <form id="paymentForm">
            <input type="hidden" id="pm_afiliado_id">
            <div style="margin-bottom:20px;">
                <label
                    style="display:block; font-weight:700; margin-bottom:8px; font-size:13px; color:#555; text-transform:uppercase; letter-spacing:0.5px;">Monto
                    (€):</label>
                <input type="number" step="0.01" id="pm_monto"
                    style="width:100%; padding:14px; border:1px solid rgba(0,0,0,0.1); border-radius:16px; background:rgba(255,255,255,0.5); font-family:inherit; font-weight:600; font-size:16px;"
                    required>
            </div>
            <div style="margin-bottom:20px;">
                <label
                    style="display:block; font-weight:700; margin-bottom:8px; font-size:13px; color:#555; text-transform:uppercase; letter-spacing:0.5px;">Referencia/ID
                    Transacción:</label>
                <input type="text" id="pm_referencia"
                    style="width:100%; padding:14px; border:1px solid rgba(0,0,0,0.1); border-radius:16px; background:rgba(255,255,255,0.5); font-family:inherit;"
                    placeholder="Ej: Stripe ch_...">
            </div>
            <div style="margin-bottom:25px;">
                <label
                    style="display:block; font-weight:700; margin-bottom:8px; font-size:13px; color:#555; text-transform:uppercase; letter-spacing:0.5px;">Notas:</label>
                <textarea id="pm_notas"
                    style="width:100%; padding:14px; border:1px solid rgba(0,0,0,0.1); border-radius:16px; background:rgba(255,255,255,0.5); height:80px; font-family:inherit;"></textarea>
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button type="button" onclick="closePaymentModal()"
                    style="background:rgba(0,0,0,0.05); color:#333; border:none; padding:12px 24px; border-radius:16px; cursor:pointer; font-weight:600; transition:all 0.2s;">Cancelar</button>
                <button type="submit"
                    style="background:var(--primary-color); color:#fff; border:none; padding:12px 24px; border-radius:16px; cursor:pointer; font-weight:700; box-shadow:0 4px 12px rgba(79, 70, 229, 0.3); transition:all 0.2s;">Guardar
                    Pago</button>
            </div>
        </form>
    </div>
    <div id="modalOverlay" onclick="closePaymentModal()"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.1); backdrop-filter:blur(5px); -webkit-backdrop-filter:blur(5px); z-index:999;">
    </div>

    <script>
        function openPaymentForm(id, name, pending) {
            document.getElementById('pm_afiliado_id').value = id;
            document.getElementById('modalTitle').innerText = 'Registrar Pago para ' + name;
            document.getElementById('pm_monto').value = pending > 0 ? pending : '';
            document.getElementById('paymentModal').style.display = 'block';
            document.getElementById('modalOverlay').style.display = 'block';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
            document.getElementById('modalOverlay').style.display = 'none';
            document.getElementById('paymentForm').reset();
        }

        jQuery(document).ready(function ($) {
            $('#paymentForm').on('submit', function (e) {
                e.preventDefault();
                var data = {
                    action: 'endtrack_add_payment',
                    afiliado_id: $('#pm_afiliado_id').val(),
                    monto: $('#pm_monto').val(),
                    referencia: $('#pm_referencia').val(),
                    notas: $('#pm_notas').val(),
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
