<?php
// Stats Template
// Expected variables: $launch, $stats (data)
// Logic based on panel_afiliados_admin_lanz.php

if (!defined('ABSPATH')) {
    exit;
}
global $wpdb;
$url = site_url('/panel-de-afiliados/');
$tabla_users = $wpdb->prefix . 'users';
$tabla_usermeta = $wpdb->prefix . 'usermeta';

// We need to fetch data specific to this launch
// Launch context is passed in $launch variable

if ($launch == 'legacy') {
    $col_tipo_cat = "1";
    $col_primer_reg = "primer_reg";
} else {
    $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
    $col_tipo_cat = "tipo_cat_" . $safe_launch;
    $col_primer_reg = "primer_reg_" . $safe_launch;
}
$tabla_datos = $wpdb->prefix . 'datos';

// 1. Registered Affiliates (All time? Or just general list?)
// Original query: SELECT * FROM users ... meta_value LIKE "%afiliado%"
// This doesn't seem launch specific.
$afiliados_reg = $wpdb->get_results("SELECT U.ID, U.user_login, U.user_email, U.user_registered FROM $tabla_users U INNER JOIN $tabla_usermeta M ON M.user_id = U.ID WHERE M.meta_value LIKE '%afiliado%'");

// 2. Leads por afiliado for THIS launch
// Original: SELECT afiliado, COUNT ... FROM datos WHERE (primer_reg=1 OR primer_reg=2) ...
// New: Check $col_primer_reg
$registrados_por_afiliado = $wpdb->get_results(
    "SELECT afiliado, COUNT(distinct(correo)) as pr 
	 FROM $tabla_datos 
	 WHERE ($col_primer_reg = 1 OR $col_primer_reg = 2) 
	 AND afiliado > 0 
	 GROUP by afiliado"
);

// 3. Ventas
// This is tricky. Original used `afiliados_a_compras` table.
// Does this table exist? Yes, referenced in `panel_afiliados_admin.php`.
// Do we duplicate logic for that table? Or does `datos` table serve this now?
// The user asked to "modificar todos los textos... Crear nuevos lanzamientos... se crean dos nueva columnas en $tabla_afiliado".
// The user did NOT say `afiliados_a_compras` changes.
// However, `ventas` logic relies on `afiliados_a_compras`.
// If `afiliados_a_compras` is hardcoded, how does it know about "marzo2025"?
// Maybe `afiliados_a_compras` joins with `datos`?
// Original: `FROM $tabla_afiliado_com A INNER JOIN (SELECT correo, afiliado FROM $tabla_afiliado WHERE primer_reg = 1...) P ON P.correo = A.mail`
// So we just need to update the Subquery to use `$col_primer_reg`.

$tabla_afiliado_com = $wpdb->prefix . 'afiliados_a_compras';
$tabla_afiliado_pagado = $wpdb->prefix . 'afiliados_a_pagado'; // Assuming this exists

// Check if tables exist before query to avoid crash
/*
$ventas_af_ever = $wpdb->get_results("
	SELECT 
		P.afiliado as af,
		COUNT(DISTINCT A.mail) as pr, 
		SUM(A.precio) as precio_t,
		SUM(A.devolucion_total) as dev_total,
		(SUM(A.precio)-SUM(A.devolucion_total))*0.96*0.25 as precio_comision,
		SUM(A.devolucion_total > 0) as num_devoluciones 
	FROM 
		$tabla_afiliado_com A 
		INNER JOIN (
			SELECT correo, afiliado
			FROM $tabla_datos
			WHERE $col_primer_reg = 1
			GROUP BY correo, afiliado
		) P ON P.correo = A.mail 
	WHERE  
		P.afiliado IS NOT NULL
		AND P.afiliado > 0
	GROUP BY 
		P.afiliado 
	ORDER BY 
		pr DESC
");
*/

// Simplified query execution handling errors
$ventas_af_ever = [];
if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_afiliado_com'") == $tabla_afiliado_com) {
    $ventas_af_ever = $wpdb->get_results("
		SELECT 
			P.afiliado as af,
			COUNT(DISTINCT A.mail) as pr, 
			SUM(A.precio) as precio_t,
			SUM(A.devolucion_total) as dev_total,
			SUM(A.devolucion_total > 0) as num_devoluciones 
		FROM 
			$tabla_afiliado_com A 
			INNER JOIN (
				SELECT correo, afiliado
				FROM $tabla_datos
				WHERE $col_primer_reg = 1
				GROUP BY correo
			) P ON P.correo = A.mail 
		WHERE  
			P.afiliado IS NOT NULL
			AND P.afiliado > 0
		GROUP BY 
			P.afiliado 
		ORDER BY 
			pr DESC
	");
}

$ventas_af_ever_num = count($ventas_af_ever);
$ventas_af_ever_total = 0;
foreach ($ventas_af_ever as $v) {
    $ventas_af_ever_total += $v->precio_t;
}

?>
<!-- Render Content -->
<div style="text-align:left;">

    <h3><?php echo ucfirst($launch); ?> Stats</h3>

    <h2 style="color:#7fb7e5;">Registrados</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>ID AFILIADO</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($afiliados_reg as $buyer): ?>
                <tr>
                    <td><?php echo $buyer->ID . ' - ' . $buyer->user_login . ' - ' . $buyer->user_email; ?></td>
                    <td><?php echo $buyer->user_registered; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="color:#7fb7e5;">Leads Conseguidos (<?php echo $launch; ?>)</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>ID AFILIADO</th>
                <th>Total registrados</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($registrados_por_afiliado as $row): ?>
                <tr>
                    <td><?php echo $row->afiliado; ?></td>
                    <td><?php echo $row->pr; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="color:#7fb7e5;">Ventas (<?php echo $ventas_af_ever_total; ?> €)</h2>
    <p><?php echo $ventas_af_ever_num; ?> afiliados con ventas.</p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>ID AFILIADO</th>
                <th>Producto</th>
                <th>Total Pagado</th>
                <th>Venta generada (Legacy)</th>
                <th>Comisiones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ventas_af_ever as $row):
                $af_comm_rate = get_user_meta($row->af, 'endtrack_commission_rate', true);
                if ($af_comm_rate === '')
                    $af_comm_rate = 25;
                $af_comm_factor = floatval($af_comm_rate) / 100;

                // New logic: fetch detailed sales for this affiliate in this launch to get ThriveCart totals
                $detailed_sales = $wpdb->get_results($wpdb->prepare(
                    "SELECT total, producto FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2",
                    $row->af
                ));

                foreach ($detailed_sales as $ds):
                    $val = floatval(str_replace(',', '.', $ds->total));
                    // Original formula logic: (precio - devolucion) * 0.96 * commission
                    // If we have $val from ThriveCart, we use it. Otherwise we fallback to $row->precio_t (though $row->precio_t comes from a different table join)
                    $comision_calc = ($val > 0) ? ($val * 0.96 * $af_comm_factor) : (($row->precio_t - $row->dev_total) * 0.96 * $af_comm_factor);
                    ?>
                    <tr>
                        <td><?php echo $row->af; ?></td>
                        <td><?php echo esc_html($ds->producto ? $ds->producto : 'Producto General'); ?></td>
                        <td><?php echo ($ds->total !== null && $ds->total !== '') ? number_format($val, 2, ',', '.') . ' €' : '-'; ?>
                        </td>
                        <td><?php echo round($row->precio_t, 2); ?></td>
                        <td><?php echo round($comision_calc, 2); ?> (<?php echo $af_comm_rate; ?>%)</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($detailed_sales)):
                    $comision_calc = ($row->precio_t - $row->dev_total) * 0.96 * $af_comm_factor;
                    ?>
                    <tr>
                        <td><?php echo $row->af; ?></td>
                        <td>Legacy/Fallback</td>
                        <td>-</td>
                        <td><?php echo round($row->precio_t, 2); ?></td>
                        <td><?php echo round($comision_calc, 2); ?> (<?php echo $af_comm_rate; ?>%)</td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>