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

if ($launch == 'legacy') {
    $col_tipo_cat = "1";
    $col_primer_reg = "primer_reg";
    $safe_launch = 'legacy';
} else {
    $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
    $col_tipo_cat = "tipo_cat_" . $safe_launch;
    $col_primer_reg = "primer_reg_" . $safe_launch;
}
$tabla_datos = $wpdb->prefix . 'datos';

// 1. Registered Affiliates
$afiliados_reg = $wpdb->get_results("SELECT U.ID, U.user_login, U.user_email, U.user_registered FROM $tabla_users U INNER JOIN $tabla_usermeta M ON M.user_id = U.ID WHERE M.meta_value LIKE '%afiliado%'");

// 2. Leads por afiliado for THIS launch
$registrados_por_afiliado = $wpdb->get_results(
    "SELECT afiliado, COUNT(distinct(correo)) as pr
	 FROM $tabla_datos
	 WHERE ($col_primer_reg = 1 OR $col_primer_reg = 2)
	 AND afiliado > 0
	 GROUP by afiliado"
);

// ─── 3. Ventas ────────────────────────────────────────────────────────────────
// Productos del lanzamiento en wp_options:
//   update_option('endtrack_ext_products_abril2026',
//       json_encode(['prod_UFZkRVl8v7vbWy', '[FORMACION] Lanz. Abril 2026']));
// ─────────────────────────────────────────────────────────────────────────────

$ext_host = defined('ENDTRACK_EXT_DB_HOST') ? ENDTRACK_EXT_DB_HOST : '165.140.159.205';
$ext_user = defined('ENDTRACK_EXT_DB_USER') ? ENDTRACK_EXT_DB_USER : 'campusvisualbusinessschool_db';
$ext_pass = defined('ENDTRACK_EXT_DB_PASS') ? ENDTRACK_EXT_DB_PASS : 'UPse83WfyWcg5';
$ext_name = defined('ENDTRACK_EXT_DB_NAME') ? ENDTRACK_EXT_DB_NAME : 'campusvisualbusinessschool_db';

$opt_key = 'endtrack_ext_products_' . $safe_launch;
$launch_products = json_decode(get_option($opt_key, '[]'), true);
if (!is_array($launch_products))
    $launch_products = [];

$ventas_af_ever = [];
$ventas_by_af = [];
$ventas_af_ever_num = 0;
$ventas_af_ever_total = 0.0;
$ext_sales_error = null;
$use_ext_db = !empty($launch_products);

if ($use_ext_db) {
    try {
        // ── Paso 1: leads atribuidos en datos (fuente de verdad) ─────────────
        // Partimos de datos para no depender de que pdc_usuarios.mail sea exacto.
        $attributed = $wpdb->get_results(
            "SELECT DISTINCT correo, afiliado
             FROM {$tabla_datos}
             WHERE {$col_primer_reg} = 1
               AND afiliado > 0
               AND correo != '' AND correo IS NOT NULL"
        );

        if (!empty($attributed)) {
            $correo_to_af = [];
            $all_correos = [];
            foreach ($attributed as $row) {
                $key = strtolower(trim($row->correo));
                if ($key === '')
                    continue;
                $correo_to_af[$key] = intval($row->afiliado);
                $all_correos[] = $key;
            }

            // ── Paso 2: consultar pdc_pagos — TODOS los compradores del lanzamiento ──
            $pdo = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user,
                $ext_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );

            $ph_prods = implode(',', array_fill(0, count($launch_products), '?'));

            $stmt = $pdo->prepare("
                SELECT
                    LOWER(TRIM(U.mail)) AS mail,
                    IFNULL(A.importe, 0) AS importe,
                    A.producto,
                    IFNULL(A.ref, '')    AS pdc_ref,
                    IFNULL(A.source, '') AS pdc_source
                FROM pdc_pagos A
                INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                WHERE A.ID_producto IN ({$ph_prods})
                  AND A.estado IN ('Completado','Compra')
            ");
            $stmt->execute($launch_products);
            $ext_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo = null;

            // ── Paso 3: cruzar resultados ────────────────────────────────────
            // Misma lógica que stats-ventas-fullscreen.php:
            //   Fuente 1: wp_datos.afiliado (lead registrado via afiliado)
            //   Fuente 2: pdc_pagos.ref numérico (buyer sin lead, compra con referido)
            $counted_buyers = []; // evita contar el mismo comprador dos veces
            foreach ($ext_rows as $r) {
                $mail = strtolower(trim((string) $r['mail']));
                $pdc_ref_int = intval($r['pdc_ref']);

                // Fuente 1: wp_datos con afiliado > 0
                $af = isset($correo_to_af[$mail]) ? $correo_to_af[$mail] : 0;

                // Fuente 2: pdc_pagos.ref si no hay afiliado válido en wp_datos
                if ($af <= 0 && $pdc_ref_int > 0)
                    $af = $pdc_ref_int;

                if ($af <= 0)
                    continue;

                if (!isset($ventas_by_af[$af])) {
                    $ventas_by_af[$af] = ['af' => $af, 'pr' => 0, 'precio_t' => 0.0, 'buyers' => []];
                }
                $buyer_key = $af . '|' . $mail;
                if (!isset($counted_buyers[$buyer_key])) {
                    $ventas_by_af[$af]['pr']++;
                    $counted_buyers[$buyer_key] = true;
                }
                $ventas_by_af[$af]['precio_t'] += floatval($r['importe']);
                $ventas_by_af[$af]['buyers'][] = [
                    'importe' => floatval($r['importe']),
                    'producto' => (string) $r['producto'],
                ];
            }

            uasort($ventas_by_af, function ($a, $b) {
                return $b['pr'] - $a['pr'];
            });
        }

        $ventas_af_ever_num = count($ventas_by_af);
        foreach ($ventas_by_af as $v) {
            $ventas_af_ever_total += $v['precio_t'];
        }

    } catch (Exception $e) {
        $ext_sales_error = $e->getMessage();
        $use_ext_db = false;
    }
}

// ─── Legacy fallback: afiliados_a_compras ────────────────────────────────────
if (!$use_ext_db) {
    $tabla_afiliado_com = $wpdb->prefix . 'afiliados_a_compras';
    if ($wpdb->get_var("SHOW TABLES LIKE '$tabla_afiliado_com'") == $tabla_afiliado_com) {
        $ventas_af_ever = $wpdb->get_results("
            SELECT
                P.afiliado as af,
                COUNT(DISTINCT A.mail) as pr,
                SUM(A.precio) as precio_t,
                SUM(A.devolucion_total) as dev_total,
                SUM(A.devolucion_total > 0) as num_devoluciones
            FROM $tabla_afiliado_com A
            INNER JOIN (
                SELECT correo, afiliado
                FROM $tabla_datos
                WHERE $col_primer_reg = 1
                GROUP BY correo
            ) P ON P.correo = A.mail
            WHERE P.afiliado IS NOT NULL AND P.afiliado > 0
            GROUP BY P.afiliado
            ORDER BY pr DESC
        ");
    }

    $ventas_af_ever_num = count($ventas_af_ever);
    $ventas_af_ever_total = 0.0;
    foreach ($ventas_af_ever as $v) {
        $ventas_af_ever_total += $v->precio_t;
    }
}
// ─────────────────────────────────────────────────────────────────────────────
?>
<!-- Render Content -->
<div style="text-align:left;">

    <h3><?php echo ucfirst($launch); ?> Stats</h3>

    <?php if ($ext_sales_error): ?>
                                <div style="background:#fff3cd;border:1px solid #ffc107;padding:10px;margin-bottom:15px;">
                                    <strong>Aviso:</strong> Error conectando a DB externa, mostrando datos legacy.<br>
                                    <code><?php echo esc_html($ext_sales_error); ?></code>
                                </div>
    <?php endif; ?>

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

    <h2 style="color:#7fb7e5;">
        Ventas (<?php echo number_format($ventas_af_ever_total, 2, ',', '.'); ?> €)
        <small style="font-size:13px;color:#6c757d;">
            — <?php echo $use_ext_db ? 'pdc_pagos' : 'legacy'; ?>
        </small>
    </h2>
    <p><?php echo $ventas_af_ever_num; ?> afiliados con ventas.</p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>ID AFILIADO</th>
                <th>Producto</th>
                <th>Total Pagado</th>
                <th>Comisiones</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($use_ext_db): ?>
                                    <?php foreach ($ventas_by_af as $af_data):
                                        $af = $af_data['af'];
                                        $af_comm_rate = get_user_meta($af, 'endtrack_commission_rate', true);
                                        if ($af_comm_rate === '')
                                            $af_comm_rate = 20;
                                        $af_comm_factor = floatval($af_comm_rate) / 100;

                                        foreach ($af_data['buyers'] as $buyer):
                                            $val = floatval($buyer['importe']);
                                            $comision_calc = $val * 0.96 * $af_comm_factor;
                                            ?>
                                                                                        <tr>
                                                                                            <td><?php echo $af; ?></td>
                                                                                            <td><?php echo esc_html($buyer['producto'] ?: 'Producto General'); ?></td>
                                                                                            <td><?php echo $val > 0 ? number_format($val, 2, ',', '.') . ' €' : '-'; ?></td>
                                                                                            <td><?php echo round($comision_calc, 2); ?> (<?php echo $af_comm_rate; ?>%)</td>
                                                                                        </tr>
                                                            <?php endforeach; ?>
                                    <?php endforeach; ?>

        <?php else: ?>
                                    <?php foreach ($ventas_af_ever as $row):
                                        $af_comm_rate = get_user_meta($row->af, 'endtrack_commission_rate', true);
                                        if ($af_comm_rate === '')
                                            $af_comm_rate = 20;
                                        $af_comm_factor = floatval($af_comm_rate) / 100;

                                        $detailed_sales = $wpdb->get_results($wpdb->prepare(
                                            "SELECT total, producto FROM $tabla_datos WHERE afiliado = %d AND $col_primer_reg = 2",
                                            $row->af
                                        ));

                                        foreach ($detailed_sales as $ds):
                                            $val = floatval(str_replace(',', '.', $ds->total));
                                            $comision_calc = ($val > 0)
                                                ? ($val * 0.96 * $af_comm_factor)
                                                : (($row->precio_t - $row->dev_total) * 0.96 * $af_comm_factor);
                                            ?>
                                                                                        <tr>
                                                                                            <td><?php echo $row->af; ?></td>
                                                                                            <td><?php echo esc_html($ds->producto ?: 'Producto General'); ?></td>
                                                                                            <td><?php echo ($ds->total !== null && $ds->total !== '') ? number_format($val, 2, ',', '.') . ' €' : '-'; ?></td>
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
                                                                                            <td><?php echo round($comision_calc, 2); ?> (<?php echo $af_comm_rate; ?>%)</td>
                                                                                        </tr>
                                                            <?php endif; ?>
                                    <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
