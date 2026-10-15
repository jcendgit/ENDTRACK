<?php
/**
 * Template Name: ENDTrack Ventas Completas (resumen + detalle pdc_pagos)
 * URL: /ventas-completas/
 * Shows registrados + ventas from pdc_pagos (cuota=1). No visits/sessions.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Nivel de acceso: editor (todos los botones) vs visualización (solo lectura)
$_can_edit = current_user_can('manage_options') || current_user_can('edit_endtrack_stats');

// ============================================================
// 1. FILTERS & CONTEXT
// ============================================================
$endtrack_launches_list = get_option('endtrack_launches', array());
$default_launch = !empty($endtrack_launches_list) ? end($endtrack_launches_list) : 'legacy';
$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : $default_launch;

// ── Clasificación ALUMNO / NO ALUMNO ─────────────────────────────────────────
// Dos reglas, cada una configurable por lanzamiento. Basta con que se cumpla UNA:
//  (a) POR FECHA DE CORTE (regla de inscripciones.pablogiltrader.com): la PRIMERA compra
//      Completado/Compra del comprador es ANTERIOR al corte → ya era cliente → alumno.
//      Si el lanzamiento no está en la lista se usa el corte por defecto.
//  (b) POR PRECIO (regla de Visual Business School): cualquier compra Completado/Compra
//      con uno de estos importes redondeados es de tarifa alumno. Sin lista → no aplica.
// Un sitio que no use una regla deja su array vacío (o sin la clave del lanzamiento).
$alumni_cutoffs = [
    'lanz_inversion_junio26' => '2026-06-21 19:38:24',
];
$alumni_cutoff_default = '2026-04-12 19:30:00';
$alumni_cutoff = isset($alumni_cutoffs[strtolower($active_launch)])
    ? $alumni_cutoffs[strtolower($active_launch)]
    : $alumni_cutoff_default;

$alumni_prices = [
    // 'lanz_inversion_junio26' => [900, 345, 195, 1500, 575, 330], // VBS: Alumnos 1 y 2 (único / 3 plazos / 6 plazos)
];
$prices_for_launch = isset($alumni_prices[strtolower($active_launch)])
    ? $alumni_prices[strtolower($active_launch)]
    : [];
$alumni_price_set = array_flip(array_map('intval', $prices_for_launch));

// Plazos por precio (VBS): importe de cuota => nº de cuotas del plan. Vacío → se usa el
// campo `cuotas` de pdc_pagos, que es lo que hace inscripciones.
$plazos_price_map = [];
$filter_date_start = isset($_GET['d_start']) ? sanitize_text_field($_GET['d_start']) : date('Y-m-d', strtotime('-90 days'));
$filter_date_end   = isset($_GET['d_end'])   ? sanitize_text_field($_GET['d_end'])   : date('Y-m-d');

$safe_launch = ($active_launch === 'legacy') ? 'legacy' : preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);

// ============================================================
// 2. EXTERNAL DB CONFIG + PRODUCTOS DEL LANZAMIENTO
// ============================================================
$_pdc_creds = function_exists('endtrack_get_pdc_credentials')
    ? endtrack_get_pdc_credentials($active_launch)
    : ['host' => '', 'user' => '', 'pass' => '', 'name' => ''];
$ext_host    = $_pdc_creds['host'];
$ext_user_db = $_pdc_creds['user'];
$ext_pass    = $_pdc_creds['pass'];
$ext_name    = $_pdc_creds['name'];

// Productos del lanzamiento agrupados (Principal / Downsell / Cross-sell).
$_pgroups = function_exists('endtrack_get_launch_product_groups')
    ? endtrack_get_launch_product_groups($active_launch)
    : array('all' => array(), 'map' => array());
$_prod_cat_map = isset($_pgroups['map']) ? $_pgroups['map'] : array(); // id_producto => categoría
$launch_products = isset($_pgroups['all']) ? $_pgroups['all'] : array(); // UNIÓN de los 3 listados
// Fallback: si aún no hay grupos definidos, usar el listado general clásico.
if (empty($launch_products)) {
    $launch_products = json_decode(get_option('endtrack_ext_products_' . $safe_launch, '[]'), true);
    if (!is_array($launch_products)) $launch_products = [];
}
$_prod_names = array(); // ID_producto => nombre en el campus (pdc_productos), para el filtro "Producto"

$ext_error = null;

// ============================================================
// 3. UNICA QUERY: detalle pdc_pagos del lanzamiento (CACHEADA)
//    Caché de 5 min por launch+fechas que se INVALIDA AL INSTANTE si cambia
//    la "firma" de pdc_pagos (nº de filas + última fecha): una venta nueva
//    aparece en la siguiente recarga sin esperar al TTL.
//    ?refresh=1 (botón "Actualizar datos") fuerza el recálculo completo.
// ============================================================
$pdc_detail_rows  = [];
$pdc_detail_error = null;

$vc_cache_ns = 'endtrack_vc_' . md5($active_launch . '|' . $filter_date_start . '|' . $filter_date_end);
$vc_sig      = '';
if (isset($_GET['refresh'])) {
    delete_transient($vc_cache_ns . '_rows');
    delete_transient($vc_cache_ns . '_tipo');
    delete_transient($vc_cache_ns . '_attr');
    delete_transient($vc_cache_ns . '_etiq');
    if (function_exists('endtrack_pdc_product_names_key')) delete_transient(endtrack_pdc_product_names_key($launch_products));
    if (!headers_sent()) {
        wp_safe_redirect(remove_query_arg('refresh'));
        exit;
    }
}

if (!empty($launch_products) && !empty($ext_host) && !empty($ext_user_db)) {
    try {
        $pdo_det = new PDO(
            "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
            $ext_user_db,
            $ext_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );
        $ph_det = implode(',', array_fill(0, count($launch_products), '?'));

        // Firma rápida (consulta ligera con índice): si no cambia, vale el caché.
        $sql_sig = "SELECT COUNT(*) AS c, IFNULL(MAX(A.fecha), '') AS mf FROM pdc_pagos A WHERE A.ID_producto IN ($ph_det)";
        $params_sig = $launch_products;
        // Mismo WHERE que la consulta de detalle (los reembolsos posteriores al
        // rango entran igual), si no la firma no detectaría una devolución nueva.
        if (!empty($filter_date_start)) { $sql_sig .= " AND DATE(A.fecha) >= ?"; $params_sig[] = $filter_date_start; }
        if (!empty($filter_date_end))   { $sql_sig .= " AND (DATE(A.fecha) <= ? OR A.estado = 'Reembolso')"; $params_sig[] = $filter_date_end; }
        $st_sig = $pdo_det->prepare($sql_sig);
        $st_sig->execute($params_sig);
        $_sigrow = $st_sig->fetch(PDO::FETCH_ASSOC);
        $vc_sig = $_sigrow ? ($_sigrow['c'] . '|' . $_sigrow['mf']) : '';

        if (function_exists('endtrack_pdc_product_names')) {
            $_prod_names = endtrack_pdc_product_names($pdo_det, $launch_products);
        }

        $_vc_rows_cache = get_transient($vc_cache_ns . '_rows');
        if (is_array($_vc_rows_cache) && $vc_sig !== '' && ($_vc_rows_cache['sig'] ?? null) === $vc_sig && isset($_vc_rows_cache['rows'])) {
            $pdc_detail_rows = $_vc_rows_cache['rows'];
            $pdo_det = null;
        } else {

        // Nombre/Apellidos: preferimos los de pdc_pagos (A). Si están vacíos,
        // hacemos fallback a pdc_usuarios (U). NULLIF trata '' como NULL para que
        // COALESCE salte al siguiente. La columna pdc_usuarios.apellidos puede no
        // existir según la instalación, así que la comprobamos antes de referenciarla.
        $u_has_apellidos = false;
        try {
            $_chk = $pdo_det->query("SHOW COLUMNS FROM pdc_usuarios LIKE 'apellidos'");
            $u_has_apellidos = ($_chk && $_chk->fetch()) ? true : false;
        } catch (Exception $_e) {
            $u_has_apellidos = false;
        }
        $nombre_expr    = "COALESCE(NULLIF(TRIM(A.nombre), ''), NULLIF(TRIM(U.nombre), ''), '') AS user_nombre";
        $apellidos_expr = $u_has_apellidos
            ? "COALESCE(NULLIF(TRIM(A.apellidos), ''), NULLIF(TRIM(U.apellidos), ''), '') AS apellidos"
            : "A.apellidos";

        $sql_det = "SELECT A.ID, LOWER(TRIM(U.mail)) AS mail, U.ID AS pdc_usuario_id, {$apellidos_expr},
                           A.ID_producto, A.producto, A.importe, A.moneda, {$nombre_expr},
                           A.tipo, A.cuotas, A.cuota, A.terminado,
                           A.metodo, A.plataforma, A.estado, A.fecha,
                           A.tipo_pago, A.dni_cif, A.direccion, A.ciudad,
                           A.codigo_postal, A.pais, A.cantidad,
                           A.cupon_codigo, A.cupon_descuento,
                           A.source, A.medium, A.campaign, A.ref,
                           A.reembolso, A.no_comision_afiliado,
                           A.payment_intent_id, A.invoice_id, A.subscription_id,
                           A.checkout_id, A.customer, A.ID_transaccion,
                           A.tax_amount, A.tax_percentage, A.tax_name
                    FROM pdc_pagos A
                    INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                    WHERE A.ID_producto IN ($ph_det)";
        $params_det = $launch_products;
        if (!empty($filter_date_start)) {
            $sql_det .= " AND DATE(A.fecha) >= ?";
            $params_det[] = $filter_date_start;
        }
        // El tope del rango NO se aplica a las devoluciones: un reembolso llega
        // semanas después del cierre del carrito y, si se quedaba fuera, la venta
        // seguía contando como cobrada y el comprador no aparecía como devolución.
        // Las filas que entran por esta vía se descartan luego si el comprador no
        // tiene ningún pago dentro del rango (no se cuelan compradores de fuera).
        if (!empty($filter_date_end)) {
            $sql_det .= " AND (DATE(A.fecha) <= ? OR A.estado = 'Reembolso')";
            $params_det[] = $filter_date_end;
        }
        $sql_det .= " ORDER BY A.fecha DESC LIMIT 5000";
        $stmt_det = $pdo_det->prepare($sql_det);
        $stmt_det->execute($params_det);
        $pdc_detail_rows = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
        $pdo_det = null;

        // Solo compradores con al menos un movimiento DENTRO del rango: si alguien
        // compró antes del periodo y le devolvieron el dinero dentro, su reembolso
        // suelto no debe crear una fila fantasma en el listado.
        if (!empty($filter_date_start) || !empty($filter_date_end)) {
            $_emails_en_rango = array();
            foreach ($pdc_detail_rows as $_r) {
                $_d = substr((string) ($_r['fecha'] ?? ''), 0, 10);
                if (!empty($filter_date_start) && $_d < $filter_date_start) continue;
                if (!empty($filter_date_end)   && $_d > $filter_date_end)   continue;
                $_emails_en_rango[(string) ($_r['mail'] ?? '')] = true;
            }
            $pdc_detail_rows = array_values(array_filter($pdc_detail_rows, function ($_r) use ($_emails_en_rango) {
                return isset($_emails_en_rango[(string) ($_r['mail'] ?? '')]);
            }));
        }
        set_transient($vc_cache_ns . '_rows', array('sig' => $vc_sig, 'rows' => $pdc_detail_rows), 5 * MINUTE_IN_SECONDS);
        } // fin caché _rows
    } catch (Exception $e) {
        $pdc_detail_error = $e->getMessage();
        $ext_error = $e->getMessage();
    }
}

$_et_texts = get_option('endtrack_texts', array());
$logo_url = !empty($_et_texts['logo_admin_panel']) ? $_et_texts['logo_admin_panel'] : 'https://visualbusiness.school/wp-content/uploads/2026/03/favicon-bss.png'

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas Completas - ENDTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <style>
        :root {
            --primary: #6366f1;
            --primary-glow: rgba(99,102,241,0.4);
            --secondary: #10b981;
            --accent: #f59e0b;
            --danger: #f43f5e;
            --bg-dark: #0f172a;
            --card-bg: rgba(30,41,59,0.7);
            --card-border: rgba(255,255,255,0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Outfit',sans-serif;
            background-color:var(--bg-dark);
            background-image:
                radial-gradient(at 0% 0%,   rgba(99,102,241,0.15) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(16,185,129,0.10) 0px, transparent 50%);
            color:var(--text-main);
            min-height:100vh;
            overflow-x:hidden;
            overflow-y:auto;
            background-attachment:fixed;
        }
        .container { max-width:1600px; margin:0 auto; padding:16px 20px 0; }
        #sec_detalle_completo { margin-bottom:0 !important; }
        /* Un solo scroll vertical (el del body): la tabla crece a su contenido
           (paginada de 50 en 50) y solo tiene scroll HORIZONTAL propio. */
        .vc-table-wrap { margin-bottom:24px; }
        /* ── Filtros sticky: con un poco de scroll el panel se fija arriba y se
           compacta al mínimo (sin labels, controles pequeños). ── */
        #vc-filters-panel { position:sticky; top:0; z-index:900; }
        body.vc-compact #vc-filters-panel { padding:6px 12px; margin-left:-10px; margin-right:-10px; margin-bottom:10px; border-radius:0 0 12px 12px; background:rgba(15,23,42,.97); box-shadow:0 12px 30px rgba(0,0,0,.55); }
        body.vc-compact #vc-filters-panel .vc-hide-compact { display:none !important; }
        body.vc-compact #vc-filters-panel hr { display:none; }
        body.vc-compact #vc-filters-panel label { display:none !important; }
        body.vc-compact #vc-client-filters { gap:6px !important; align-items:center !important; }
        body.vc-compact #vc-filters-panel select,
        body.vc-compact #vc-filters-panel input { padding:3px 6px !important; font-size:11px !important; }
        body.vc-compact #vc-f-q { width:200px !important; }
        body.vc-compact #vc-filters-panel .btn-filter,
        body.vc-compact #vc-f-clear { padding:4px 10px !important; font-size:10px !important; }
        body.vc-compact #vc-f-count { padding:1px 8px !important; }
        body.vc-compact #vc-f-count span { font-size:13px !important; }
        body.vc-compact #vc-f-count span:first-child { font-size:16px !important; }
        /* Paginación */
        #vc-pager { display:flex; align-items:center; gap:6px; }
        #vc-pager button { background:rgba(255,255,255,.06); color:#cbd5e1; border:1px solid rgba(255,255,255,.15); padding:5px 11px; border-radius:6px; cursor:pointer; font-family:inherit; font-size:12px; font-weight:700; transition:all .15s; }
        #vc-pager button:hover:not(:disabled) { background:var(--primary); border-color:var(--primary); color:#fff; }
        #vc-pager button:disabled { opacity:.35; cursor:default; }
        #vc-pg-info { font-size:11px; color:var(--text-muted); white-space:nowrap; }
        body.vc-compact #vc-pager button { padding:2px 8px !important; font-size:11px !important; }
        .header { display:flex; flex-direction:column; gap:14px; margin-bottom:14px; }
        .header-top { display:flex; justify-content:space-between; align-items:center; }
        .logo-area { display:flex; align-items:center; gap:15px; }
        .logo-area img { height:45px; }
        .logo-area h1 { font-size:24px; font-weight:800; letter-spacing:-1px; }
        .badge-ventas { background:linear-gradient(135deg,#10b981,#059669); color:#fff; font-size:11px; font-weight:700; padding:4px 10px; border-radius:99px; text-transform:uppercase; letter-spacing:1px; }
        .filters-panel { background:var(--card-bg); padding:20px; border-radius:16px; border:1px solid var(--card-border); backdrop-filter:blur(10px); display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:6px; flex:1; min-width:150px; }
        .filter-group label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; }
        select, input[type="date"] { background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:white; padding:10px 12px; border-radius:8px; font-size:13px; font-family:inherit; outline:none; width:100%; }
        .btn-filter { background:var(--primary); color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-family:inherit; font-size:13px; font-weight:600; transition:all 0.2s; box-shadow:0 4px 12px var(--primary-glow); }
        .btn-filter:hover { transform:translateY(-2px); box-shadow:0 6px 16px var(--primary-glow); }
        .btn-export { font-size:11px; background:linear-gradient(135deg,rgba(255,255,255,0.15),rgba(255,255,255,0.05)); color:#fff; border:1px solid rgba(255,255,255,0.2); padding:6px 14px; border-radius:99px; margin-left:10px; cursor:pointer; transition:all 0.3s; display:flex; align-items:center; gap:8px; backdrop-filter:blur(8px); font-weight:600; }
        .btn-export i { font-size:12px; color:var(--secondary); }
        .btn-export:hover { background:var(--primary); border-color:var(--primary); transform:translateY(-2px); box-shadow:0 4px 15px var(--primary-glow); }
        .quick-nav { position:-webkit-sticky; position:sticky; top:0; z-index:9999; background:transparent; padding:15px 0; margin:10px 0 30px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; transition:all 0.3s; }
        .quick-nav.is-sticky { padding:15px 30px; margin-left:-30px; margin-right:-30px; background:rgba(15,23,42,0.95); backdrop-filter:blur(15px); border-bottom:1px solid rgba(255,255,255,0.1); box-shadow:0 10px 30px rgba(0,0,0,0.5); }
        .quick-nav.is-sticky::after { content:''; position:absolute; top:0; left:-50vw; width:200vw; height:100%; background:inherit; backdrop-filter:inherit; z-index:-1; border-bottom:inherit; }
        .btn-nav { font-size:11px; background:rgba(255,255,255,0.05); color:var(--text-muted); border:1px solid var(--card-border); padding:6px 12px; border-radius:6px; text-decoration:none; transition:all 0.2s; display:flex; align-items:center; gap:6px; }
        .btn-nav:hover { background:var(--primary); color:white; border-color:var(--primary); transform:translateY(-2px); box-shadow:0 4px 12px var(--primary-glow); }
        .btn-nav.active { background:var(--primary); color:white; border-color:var(--primary); }
        .dashboard-section { margin-bottom:50px; scroll-margin-top:120px; opacity:0; transform:translateY(20px); animation:fadeIn 0.5s forwards ease-out; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
        .section-title { font-size:22px; font-weight:800; margin-bottom:25px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px; }
        .section-title i { color:var(--primary); }
        .section-header-wrap { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--card-border); padding-bottom:10px; }
        .section-header-wrap h2 { margin-bottom:0; border-bottom:none; }
        .grid-kpi { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; }
        .grid-2col { display:grid; grid-template-columns:repeat(auto-fit,minmax(500px,1fr)); gap:25px; }
        .panel-box { background:var(--card-bg); border:1px solid var(--card-border); padding:25px; border-radius:20px; backdrop-filter:blur(10px); }
        .kpi-card { position:relative; overflow:hidden; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; width:100%; height:4px; background:var(--bg-color); }
        .kpi-label { color:var(--text-muted); font-size:12px; font-weight:700; text-transform:uppercase; margin-bottom:10px; display:block; }
        .kpi-value { font-size:48px; font-weight:800; line-height:1; letter-spacing:-1px; display:block; margin-bottom:5px; }
        .kpi-value.kpi-small { font-size:25px; letter-spacing:-0.5px; }
        .kpi-divider { grid-column:1/-1; padding:6px 0 4px; border-bottom:1px solid rgba(255,255,255,0.08); margin-bottom:4px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:2px; color:var(--text-muted); }
        .kpi-card.pink .kpi-value     { color:#ec4899; text-shadow:0 0 20px rgba(236,72,153,0.3); }
        .kpi-card.purple .kpi-value   { color:#a855f7; text-shadow:0 0 20px rgba(168,85,247,0.3); }
        .kpi-card.orange .kpi-value   { color:#fb923c; text-shadow:0 0 20px rgba(251,146,60,0.3); }
        .kpi-card.green .kpi-value    { color:#10b981; text-shadow:0 0 20px rgba(16,185,129,0.3); }
        .kpi-card.red .kpi-value      { color:#f43f5e; text-shadow:0 0 20px rgba(244,63,94,0.3); }
        .kpi-card.amber .kpi-value    { color:#f59e0b; text-shadow:0 0 20px rgba(245,158,11,0.3); }
        .kpi-card.teal .kpi-value     { color:#06b6d4; text-shadow:0 0 20px rgba(6,182,212,0.3); }
        .kpi-card.featured            { border:2px solid #6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.2), 0 8px 32px rgba(99,102,241,0.15); }
        .kpi-card.featured .kpi-value { color:#818cf8; font-size:56px; text-shadow:0 0 30px rgba(129,140,248,0.5); }
        .kpi-card.featured::before    { background:linear-gradient(90deg,#6366f1,#818cf8); height:5px; }
        .funnel-container { display:flex; flex-direction:column; gap:10px; }
        .funnel-step { background:rgba(255,255,255,0.05); padding:12px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; }
        .funnel-label { font-size:14px; font-weight:600; }
        .funnel-val { font-size:18px; font-weight:800; color:var(--primary); }
        .table-responsive { max-height:450px; overflow-y:auto; border-radius:8px; border:1px solid rgba(255,255,255,0.05); }
        .table-responsive::-webkit-scrollbar { width:6px; }
        .table-responsive::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.1); border-radius:10px; }
        #vc-hscroll::-webkit-scrollbar { height:10px; }
        #vc-hscroll::-webkit-scrollbar-track { background:transparent; }
        #vc-hscroll::-webkit-scrollbar-thumb { background:rgba(99,102,241,.55); border-radius:10px; }
        #vc-hscroll::-webkit-scrollbar-thumb:hover { background:rgba(99,102,241,.85); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { text-align:left; font-size:11px; color:var(--text-muted); text-transform:uppercase; padding:12px; border-bottom:1px solid rgba(255,255,255,0.05); cursor:pointer; user-select:none; white-space:nowrap; position:sticky; top:0; background:#1e293b; z-index:5; }
        td { padding:12px; font-size:13px; border-bottom:1px solid rgba(255,255,255,0.03); }
        .table-pro tr { border-bottom:1px solid var(--card-border); transition:background 0.2s; }
        .panel-title { font-size:16px; font-weight:700; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
        .chart-container { height:300px; position:relative; }
        /* Ad Spend / ROAS */
        .roas-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:24px; }
        .roas-kpi { background:var(--card-bg); border:1px solid var(--card-border); border-radius:16px; padding:20px; text-align:center; position:relative; overflow:hidden; }
        .roas-kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--kpi-accent,var(--primary)); border-radius:16px 16px 0 0; }
        .roas-kpi-label { font-size:11px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:8px; display:block; }
        .roas-kpi-value { font-size:28px; font-weight:800; letter-spacing:-1px; display:block; }
        .roas-loading { text-align:center; padding:60px 20px; opacity:0.6; }
        .roas-loading i { font-size:28px; margin-bottom:12px; display:block; animation:spin 1s linear infinite; }
        @keyframes spin { to{transform:rotate(360deg)} }
        .roas-error { text-align:center; padding:40px 20px; color:var(--danger); background:rgba(244,63,94,0.1); border:1px solid rgba(244,63,94,0.2); border-radius:12px; }
        .roas-table-wrap { max-height:500px; overflow-y:auto; border-radius:8px; border:1px solid rgba(255,255,255,0.05); }
        .roas-table-wrap th { box-shadow:0 2px 4px rgba(0,0,0,0.2); }
        .ext-error-banner { background:#fff3cd; border:1px solid #ffc107; color:#333; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:13px; }
        .section-quick-filters { display:flex; gap:5px; background:rgba(0,0,0,0.2); padding:4px; border-radius:8px; }
        .btn-sfilter { background:rgba(255,255,255,0.05); color:var(--text-muted); border:1px solid var(--card-border); padding:4px 10px; border-radius:6px; cursor:pointer; font-family:inherit; font-size:10px; font-weight:700; text-transform:uppercase; transition:all 0.2s; }
        .btn-sfilter:hover { background:rgba(255,255,255,0.1); }
        .btn-sfilter.active { background:var(--primary); color:white; border-color:var(--primary); box-shadow:0 2px 8px var(--primary-glow); }
        .btn-dfilter { background:rgba(255,255,255,0.05); color:var(--text-muted); border:1px solid var(--card-border); padding:4px 10px; border-radius:6px; cursor:pointer; font-family:inherit; font-size:10px; font-weight:700; text-transform:uppercase; transition:all 0.2s; }
        .btn-dfilter:hover { background:rgba(255,255,255,0.1); }
        .btn-dfilter.active { background:#10b981; color:white; border-color:#10b981; box-shadow:0 2px 8px rgba(16,185,129,0.4); }
        /* Filas marcadas como prueba/error: ocultas por defecto, visibles al activar chip */
        tr.vc-marca-test, tr.vc-marca-test + tr.vc-detail-rows { display:none; }
        tr.vc-marca-error, tr.vc-marca-error + tr.vc-detail-rows { display:none; }
        body.show-test  tr.vc-marca-test  { display:table-row; background:rgba(168,85,247,.06) !important; }
        body.show-error tr.vc-marca-error { display:table-row; background:rgba(239,68,68,.06) !important; }
        .vc-chip[data-active="1"] { background:rgba(255,255,255,.12) !important; color:#fff !important; box-shadow:0 0 0 2px currentColor inset; }
        .vc-chip:hover { transform:translateY(-1px); }
        /* Visualización (solo lectura): ocultar controles de edición */
        body.vc-viewer .vc-color-swatches,
        body.vc-viewer .vc-inline-note,
        body.vc-viewer .vc-act,
        body.vc-viewer .vc-marca-btn,
        body.vc-viewer #vc-access-btn { display:none !important; }
        body.vc-viewer .vc-atrib-input { pointer-events:none; border-color:transparent !important; background:transparent !important; }
        /* Columnas fijas (sticky) en scroll horizontal: Acciones (1ª) + Email */
        #vc-detalle-table th.vc-fz-act, #vc-detalle-table td.vc-fz-act { position:sticky; left:0; z-index:4; width:150px; min-width:150px; max-width:150px; }
        #vc-detalle-table th.vc-fz-mail, #vc-detalle-table td.vc-fz-mail { position:sticky; left:150px; z-index:4; min-width:200px; box-shadow:2px 0 0 rgba(255,255,255,.07); }
        #vc-detalle-table tbody td.vc-fz-act, #vc-detalle-table tbody td.vc-fz-mail { background-color:inherit; }
        #vc-detalle-table thead th.vc-fz-act, #vc-detalle-table thead th.vc-fz-mail { background:#1e293b !important; z-index:8; top:0; }
    </style>
</head>
<body class="<?php echo $_can_edit ? '' : 'vc-viewer'; ?>">
<div class="container">

    <div class="header">
        <div class="header-top">
            <div class="logo-area">
                <img src="<?php echo esc_url($logo_url); ?>" alt="VBS Logo">
                <h1>Ventas Completas <span class="badge-ventas">Conversiones</span></h1>
            </div>
            <a href="/endtrack-estadisticas-ventas/?launch=<?php echo urlencode($active_launch); ?>&d_start=<?php echo urlencode($filter_date_start); ?>&d_end=<?php echo urlencode($filter_date_end); ?>" class="btn-nav" style="font-size:12px;padding:8px 14px;margin-left: auto;background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);color:#a5b4fc;">
                <i class="fas fa-chart-pie"></i> Ver Estadísticas Ventas
            </a>
            <a href="/endtrack-estadisticas/" class="btn-nav" style="font-size:12px;padding:8px 14px;margin-right: 10px;">
                <i class="fas fa-chart-bar"></i> Ver Tráfico
            </a>
            <?php if ($_can_edit): ?>
            <button type="button" id="vc-access-btn" class="btn-nav" style="font-size:12px;padding:8px 14px;background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);color:#fbbf24;cursor:pointer;">
                <i class="fas fa-user-shield"></i> Gestionar accesos
            </button>
            <?php endif; ?>
        </div>

        
    </div><!-- /.header -->

    <?php if ($ext_error): ?>
                                                                                                                                                                    <div class="ext-error-banner"><strong>Aviso:</strong> Error conectando a DB externa — solo se muestran registrados.<br><code><?php echo esc_html($ext_error); ?></code></div>
    <?php endif; ?>



    <!-- ============================================================
         DETALLE DE VENTAS — agrupado por usuario
         ============================================================ -->
    <?php
    // Group rows by user email
    $_users_idx = array();
    $_users_meta = array();
    foreach ($pdc_detail_rows as $r) {
        $em = strtolower(trim((string) ($r['mail'] ?? '')));
        if ($em === '') $em = '(sin email)';
        if (!isset($_users_idx[$em])) {
            $_users_idx[$em] = array();
            $_users_meta[$em] = array(
                'nombre'    => (string)($r['user_nombre'] ?? ''),
                'apellidos' => (string)($r['apellidos'] ?? ''),
                'producto'  => (string)($r['producto'] ?? ''),
                'pais'      => (string)($r['pais'] ?? ''),
                'ciudad'    => (string)($r['ciudad'] ?? ''),
                'customer'  => (string)($r['customer'] ?? ''),
                'subscription' => (string)($r['subscription_id'] ?? ''),
                'tipo_pago' => (string)($r['tipo_pago'] ?? ''),
                'tipo'      => (string)($r['tipo'] ?? ''),
                'plataforma'=> (string)($r['plataforma'] ?? ''),
                'fecha_first' => (string)($r['fecha'] ?? ''),
                'fecha_last'  => (string)($r['fecha'] ?? ''),
                'importe_total' => 0.0,
                'cuotas_pagadas' => 0,
                'cuotas_total' => intval($r['cuotas'] ?? 1),
                'estados' => array(),
                'has_impago' => false,
                'has_reembolso' => false,
                'has_reembolso_real' => false,
                'pdc_usuario_id' => null,
                'cats' => array(),
                'prods' => array(),
            );
        }
        $_users_idx[$em][] = $r;
        $m =& $_users_meta[$em];
        if ($r['fecha'] < $m['fecha_first']) $m['fecha_first'] = $r['fecha'];
        if ($r['fecha'] > $m['fecha_last']) $m['fecha_last'] = $r['fecha'];
        $est = (string) ($r['estado'] ?? '');
        $m['estados'][$est] = ($m['estados'][$est] ?? 0) + 1;
        $_pid_cat = (string) ($r['ID_producto'] ?? '');
        if ($_pid_cat !== '' && isset($_prod_cat_map[$_pid_cat])) {
            $m['cats'][$_prod_cat_map[$_pid_cat]] = true;
        }
        if ($_pid_cat !== '') $m['prods'][$_pid_cat] = true;
        if (in_array($est, array('Completado','Compra'), true)) {
            $m['importe_total'] += (float) ($r['importe'] ?? 0);
            $m['cuotas_pagadas']++;
            // Plataforma del PRIMER pago completado: las filas vienen fecha DESC,
            // así que la última sobreescritura corresponde al cargo más antiguo.
            $_pl_ok = trim((string) ($r['plataforma'] ?? ''));
            if ($_pl_ok !== '') $m['plataforma'] = $_pl_ok;
        } elseif ($est === 'Reembolso') {
            $m['importe_total'] -= abs((float) ($r['importe'] ?? 0)); // REEMBOLSO: resta del total (importe puede venir +/-)
        }
        if ($est === 'Fallido') $m['has_impago'] = true;
        if ($est === 'Reembolso' || (int)($r['reembolso'] ?? 0) === 1) $m['has_reembolso'] = true;
        if ($est === 'Reembolso' && (int)($r['reembolso'] ?? 0) === 1) $m['has_reembolso_real'] = true;
        if ($m['pdc_usuario_id'] === null && !empty($r['pdc_usuario_id'])) $m['pdc_usuario_id'] = (int) $r['pdc_usuario_id'];
        unset($m);
    }

    // ============================================================
    // Estado guardado (color / atribución / marca) + nº de notas
    //   Se carga ANTES del lookup de tipo para poder usar el email de
    //   atribución en la detección de usuario creado.
    // ============================================================
    global $wpdb;
    $_t_estado = $wpdb->prefix . 'endtrack_pdc_estado';
    $_t_notas  = $wpdb->prefix . 'endtrack_pdc_notas';
    $_saved_estado = array();
    $_notas_count  = array();
    $safe_launch_pdc = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    if (!empty($safe_launch_pdc)) {
        $est_rows = $wpdb->get_results($wpdb->prepare("SELECT user_email, color, atribucion_email, marca FROM $_t_estado WHERE launch = %s", $safe_launch_pdc));
        foreach ($est_rows as $e) $_saved_estado[strtolower($e->user_email)] = $e;
        $n_rows = $wpdb->get_results($wpdb->prepare("SELECT user_email, COUNT(*) as n FROM $_t_notas WHERE launch = %s GROUP BY user_email", $safe_launch_pdc));
        foreach ($n_rows as $n) $_notas_count[strtolower($n->user_email)] = (int)$n->n;
    }

    // ============================================================
    // Lookup tipo de usuario en pdc_users / pdc_usermeta (WP en PDC server)
    //   Un usuario se considera CREADO si su correo de COMPRA o su correo
    //   de ATRIBUCIÓN coincide con pdc_users.user_login O pdc_users.user_email
    //   (a veces el alumno cambia de email → se comprueban los 4 cruces).
    //   pdc_capabilities = WP capabilities con prefijo pdc_
    //   Roles posibles: 'alumno', 'noacceso', 'sin_rol', 'otro'
    // ============================================================
    $_pdc_user_tipo = array();
    $_changed_email = array(); // correo de compra (user_login) => user_email distinto en pdc_users
    $_t2_sin_pago   = array(); // alumnos sin pago añadidos (para cachear)
    // Caché del lookup de tipo (validada por la firma de pdc_pagos)
    $_vc_t2 = get_transient($vc_cache_ns . '_tipo');
    if (!is_array($_vc_t2) || $vc_sig === '' || ($_vc_t2['sig'] ?? null) !== $vc_sig) $_vc_t2 = null;
    if ($_vc_t2 === null && !empty($_users_meta) && !empty($ext_host) && !empty($ext_user_db)) {
        // Mapa: cualquier correo (compra o atribución) -> correo principal del comprador
        $_lookup_mail = array();
        foreach ($_users_meta as $_bmail => $_bm) {
            if ($_bmail === '' || $_bmail === '(sin email)') continue;
            if (!isset($_lookup_mail[$_bmail])) $_lookup_mail[$_bmail] = $_bmail;
            $_atr = isset($_saved_estado[$_bmail]) ? strtolower(trim((string)$_saved_estado[$_bmail]->atribucion_email)) : '';
            if ($_atr !== '' && !isset($_lookup_mail[$_atr])) $_lookup_mail[$_atr] = $_bmail;
        }
        if (!empty($_lookup_mail)) {
            try {
                $pdo_u = new PDO(
                    "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                    $ext_user_db, $ext_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
                );
                $_keys = array_keys($_lookup_mail);
                $ph_u = implode(',', array_fill(0, count($_keys), '?'));
                // Coincidencia por user_login O user_email (mismos valores en ambos IN)
                $stmt_u = $pdo_u->prepare(
                    "SELECT LOWER(TRIM(U.user_login)) AS ulogin,
                            LOWER(TRIM(U.user_email)) AS uemail,
                            M.meta_value
                     FROM pdc_users U
                     LEFT JOIN pdc_usermeta M ON M.user_id = U.ID AND M.meta_key = 'pdc_capabilities'
                     WHERE LOWER(TRIM(U.user_login)) IN ($ph_u)
                        OR LOWER(TRIM(U.user_email)) IN ($ph_u)"
                );
                $stmt_u->execute(array_merge($_keys, $_keys));
                $_prio = array('alumno'=>4, 'noacceso'=>3, 'sin_rol'=>2, 'otro'=>1);
                foreach ($stmt_u->fetchAll(PDO::FETCH_ASSOC) as $_ur) {
                    $caps_raw = (string)($_ur['meta_value'] ?? '');
                    $tipo = 'otro';
                    if ($caps_raw !== '') {
                        $caps = @unserialize($caps_raw);
                        if (is_array($caps)) {
                            if (!empty($caps['alumno']))       $tipo = 'alumno';
                            elseif (!empty($caps['noacceso'])) $tipo = 'noacceso';
                            else { $first_key = array_key_first($caps); if ($first_key) $tipo = (string)$first_key; }
                        }
                    } else {
                        $tipo = 'sin_rol'; // existe en pdc_users pero sin pdc_capabilities
                    }
                    // Resolver a qué comprador corresponde (por login y/o email)
                    foreach (array((string)($_ur['ulogin'] ?? ''), (string)($_ur['uemail'] ?? '')) as $_cand) {
                        if ($_cand === '' || !isset($_lookup_mail[$_cand])) continue;
                        $_owner = $_lookup_mail[$_cand];
                        $_cur = $_pdc_user_tipo[$_owner] ?? '';
                        // conservar el rol más informativo si ya había uno
                        if ($_cur === '' || ($_prio[$tipo] ?? 0) > ($_prio[$_cur] ?? 0)) {
                            $_pdc_user_tipo[$_owner] = $tipo;
                        }
                    }
                    // Email cambiado en plataforma: el correo de compra coincide con user_login
                    // O con user_email; si difieren se muestra el OTRO (en cualquier sentido).
                    $_ul = (string)($_ur['ulogin'] ?? '');
                    $_ue = (string)($_ur['uemail'] ?? '');
                    if ($_ul !== '' && $_ue !== '' && $_ul !== $_ue) {
                        if (isset($_users_meta[$_ul])) $_changed_email[$_ul] = $_ue;
                        if (isset($_users_meta[$_ue])) $_changed_email[$_ue] = $_ul;
                    }
                }
                $pdo_u = null;
            } catch (Exception $_e) {
                // si falla, deja el mapa vacío -> todos saldrán como "no creado"
            }
        }
    }

    // ============================================================
    // 3a-bis. Lookup FIABLE por ID (pdc_usuarios.ID == pdc_users.ID: es el
    //     mismo usuario aunque el correo de compra y el de la cuenta del
    //     campus sean distintos). Corre DESPUÉS del lookup por email y
    //     tiene prioridad: si encuentra la cuenta por ID, fija el tipo real
    //     y marca el correo del campus como "cambiado" — así ya no sale
    //     "No creado" cuando en realidad sí existe, solo con otro correo.
    // ============================================================
    if ($_vc_t2 === null && !empty($_users_meta) && !empty($ext_host) && !empty($ext_user_db)) {
        $_ids_por_email = array();
        foreach ($_users_meta as $_bmail => $_bm) {
            if (!empty($_bm['pdc_usuario_id'])) $_ids_por_email[(int) $_bm['pdc_usuario_id']] = $_bmail;
        }
        if (!empty($_ids_por_email)) {
            try {
                $pdo_uid = new PDO(
                    "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                    $ext_user_db, $ext_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
                );
                $_ids = array_keys($_ids_por_email);
                $ph_id = implode(',', array_fill(0, count($_ids), '?'));
                $stmt_uid = $pdo_uid->prepare(
                    "SELECT U.ID,
                            LOWER(TRIM(U.user_login)) AS ulogin,
                            LOWER(TRIM(U.user_email)) AS uemail,
                            M.meta_value
                     FROM pdc_users U
                     LEFT JOIN pdc_usermeta M ON M.user_id = U.ID AND M.meta_key = 'pdc_capabilities'
                     WHERE U.ID IN ($ph_id)"
                );
                $stmt_uid->execute($_ids);
                foreach ($stmt_uid->fetchAll(PDO::FETCH_ASSOC) as $_ur) {
                    $_owner = $_ids_por_email[(int) $_ur['ID']] ?? null;
                    if ($_owner === null) continue;
                    $caps_raw = (string) ($_ur['meta_value'] ?? '');
                    $tipo = 'otro';
                    if ($caps_raw !== '') {
                        $caps = @unserialize($caps_raw);
                        if (is_array($caps)) {
                            if (!empty($caps['alumno']))       $tipo = 'alumno';
                            elseif (!empty($caps['noacceso'])) $tipo = 'noacceso';
                            else { $first_key = array_key_first($caps); if ($first_key) $tipo = (string) $first_key; }
                        }
                    } else {
                        $tipo = 'sin_rol';
                    }
                    // Coincidencia por ID: fiable al 100%, prioridad sobre el lookup por email.
                    $_pdc_user_tipo[$_owner] = $tipo;
                    // Correo real de la cuenta del campus (puede diferir del de compra).
                    $_ue = (string) ($_ur['uemail'] ?? '');
                    $_ul = (string) ($_ur['ulogin'] ?? '');
                    $_campus_mail = $_ue !== '' ? $_ue : $_ul;
                    if ($_campus_mail !== '' && $_campus_mail !== $_owner) {
                        $_changed_email[$_owner] = $_campus_mail;
                    }
                }
                $pdo_uid = null;
            } catch (Exception $_e) {
                // si falla, se queda con lo que haya resuelto el lookup por email
            }
        }
    }

    // ============================================================
    // 3b. SOLO LANZabril2026: incluir TODOS los alumnos de la plataforma
    //     (capability 'alumno' en pdc_usermeta) aunque NO tengan ningún
    //     registro en pdc_pagos. Sirve para detectar accesos sin compra.
    //     El cruce "tiene pago" comprueba user_login Y user_email del alumno
    //     contra pdc_usuarios.mail (el alumno puede haber pagado con un
    //     correo distinto al actual de la plataforma).
    // ============================================================
    $_alumnos_sin_pago = 0;
    if ($_vc_t2 === null && strcasecmp($active_launch, 'LANZabril2026') === 0 && !empty($ext_host) && !empty($ext_user_db)) {
        try {
            $pdo_al = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user_db, $ext_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]
            );

            // 1) Todos los usuarios con capability 'alumno' (login + email)
            $alumno_rows = $pdo_al->query(
                "SELECT U.ID,
                        LOWER(TRIM(U.user_login)) AS ulogin,
                        LOWER(TRIM(U.user_email)) AS uemail,
                        U.display_name,
                        MAX(CASE WHEN M.meta_key='first_name'        THEN M.meta_value END) AS first_name,
                        MAX(CASE WHEN M.meta_key='last_name'         THEN M.meta_value END) AS last_name,
                        MAX(CASE WHEN M.meta_key='pdc_capabilities'  THEN M.meta_value END) AS caps
                 FROM pdc_users U
                 INNER JOIN pdc_usermeta M ON M.user_id = U.ID
                 WHERE U.ID IN (
                     SELECT user_id FROM pdc_usermeta
                     WHERE meta_key='pdc_capabilities' AND meta_value LIKE '%\"alumno\"%'
                 )
                 GROUP BY U.ID, U.user_login, U.user_email, U.display_name"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Indexar alumnos por ID; recopilar TODOS sus correos (login + email)
            $alumno_by_id      = array();   // id  => info (+ 'emails')
            $_all_alumno_mails = array();   // email => true
            foreach ($alumno_rows as $ar) {
                $caps = @unserialize((string)($ar['caps'] ?? ''));
                if (!is_array($caps) || empty($caps['alumno'])) continue;
                $id = (int)$ar['ID'];
                $emails = array();
                foreach (array((string)($ar['uemail'] ?? ''), (string)($ar['ulogin'] ?? '')) as $cand) {
                    $cand = strtolower(trim($cand));
                    if ($cand !== '' && strpos($cand, '@') !== false) $emails[$cand] = true;
                }
                if (empty($emails)) continue;
                $ar['emails'] = array_keys($emails);   // [user_email, user_login] (preferimos email)
                $alumno_by_id[$id] = $ar;
                foreach ($ar['emails'] as $em) $_all_alumno_mails[$em] = true;
            }

            // 2) ¿Qué correos de alumno tienen ALGÚN registro en pdc_pagos?
            //    Cruza pdc_usuarios.mail con CUALQUIERA de sus correos.
            $alumnos_con_pago = array();   // email => true
            if (!empty($_all_alumno_mails)) {
                foreach (array_chunk(array_keys($_all_alumno_mails), 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $st = $pdo_al->prepare(
                        "SELECT DISTINCT LOWER(TRIM(U.mail)) AS mail
                         FROM pdc_usuarios U
                         INNER JOIN pdc_pagos P ON P.ID_usuario = U.ID
                         WHERE LOWER(TRIM(U.mail)) IN ($ph)"
                    );
                    $st->execute($chunk);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mm) {
                        $alumnos_con_pago[(string)$mm] = true;
                    }
                }
            }

            // 2b) ¿Qué IDs de alumno tienen pago? (pdc_pagos.ID_usuario = pdc_users.ID)
            //     El alumno y el comprador comparten ID aunque el correo difiera.
            $ids_con_pago = array();   // id => true
            $_alumno_ids = array_keys($alumno_by_id);
            if (!empty($_alumno_ids)) {
                foreach (array_chunk($_alumno_ids, 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $st = $pdo_al->prepare(
                        "SELECT DISTINCT ID_usuario FROM pdc_pagos WHERE ID_usuario IN ($ph)"
                    );
                    $st->execute($chunk);
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $iid) {
                        $ids_con_pago[(int)$iid] = true;
                    }
                }
            }
            $pdo_al = null;

            // 3) Añadir como filas sintéticas los alumnos SIN ningún pago
            //    (ninguno de sus correos aparece en pdc_pagos ni en el listado)
            foreach ($alumno_by_id as $id => $info) {
                $omitir = isset($ids_con_pago[$id]); // mismo ID de usuario con pago en pdc_pagos
                foreach ($info['emails'] as $em) {
                    if ($omitir) break;
                    if (isset($alumnos_con_pago[$em]) || isset($_users_meta[$em])) { $omitir = true; break; }
                }
                if ($omitir) continue;
                $em_key = $info['emails'][0]; // correo representativo (prefiere user_email)
                $fn     = trim((string)($info['first_name'] ?? ''));
                $ln     = trim((string)($info['last_name'] ?? ''));
                $nombre = $fn !== '' ? $fn : (string)($info['display_name'] ?? '');
                $_users_meta[$em_key] = array(
                    'nombre'        => $nombre,
                    'apellidos'     => $ln,
                    'producto'      => '',
                    'pais'          => '',
                    'ciudad'        => '',
                    'customer'      => '',
                    'subscription'  => '',
                    'tipo_pago'     => '',
                    'tipo'          => '',
                    'plataforma'    => '',
                    'fecha_first'   => '',
                    'fecha_last'    => '',
                    'importe_total' => 0.0,
                    'cuotas_pagadas'=> 0,
                    'cuotas_total'  => 0,
                    'estados'       => array(),
                    'has_impago'    => false,
                    'has_reembolso' => false,
                'has_reembolso_real' => false,
                    'no_pago'       => true,
                    'pdc_usuario_id'=> (int) $id,
                );
                $_users_idx[$em_key]     = array();
                $_pdc_user_tipo[$em_key] = 'alumno';
                $_t2_sin_pago[$em_key]   = $_users_meta[$em_key];
                $_alumnos_sin_pago++;
            }
        } catch (Exception $_e) {
            // si falla, no añadimos nada
        }
    }

    // Aplicar (o guardar) el caché del lookup de tipo + alumnos sin pago
    if ($_vc_t2 !== null) {
        $_pdc_user_tipo = is_array($_vc_t2['tipo'] ?? null) ? $_vc_t2['tipo'] : array();
        $_changed_email = is_array($_vc_t2['changed'] ?? null) ? $_vc_t2['changed'] : array();
        foreach ((array) ($_vc_t2['sin_pago'] ?? array()) as $_sp_key => $_sp_meta) {
            if (isset($_users_meta[$_sp_key])) continue;
            $_users_meta[$_sp_key]    = $_sp_meta;
            $_users_idx[$_sp_key]     = array();
            $_pdc_user_tipo[$_sp_key] = 'alumno';
            $_alumnos_sin_pago++;
        }
    } elseif ($vc_sig !== '') {
        set_transient($vc_cache_ns . '_tipo', array('sig' => $vc_sig, 'tipo' => $_pdc_user_tipo, 'changed' => $_changed_email, 'sin_pago' => $_t2_sin_pago), 5 * MINUTE_IN_SECONDS);
    }

    // ============================================================
    // 3c. ETIQUETAS PDC del lanzamiento
    //   Cadena: id_producto (prod_…) -> pdc_productos.ID -> pdc_segmentaciones
    //   (etiquetas_poner por escenario: Compra, Reembolso, Impago de cuota…)
    //   -> pdc_etiquetas (nombre). Por comprador: pdc_historial_etiquetas
    //   ACTIVAS (fecha_eliminacion IS NULL) entre esas etiquetas. Cacheado
    //   5 min junto al resto (mismo namespace, invalidado por firma).
    // ============================================================
    $_etiq_catalogo = array();   // ID_etiqueta => ['nombre'=>, 'categoria'=>, 'escenarios'=>[nombre escenario,…]]
    $_etiq_por_email = array();  // email comprador => [ID_etiqueta,…] activas
    $_etiq_error = null;
    $_vc_t3 = get_transient($vc_cache_ns . '_etiq');
    if (!is_array($_vc_t3) || $vc_sig === '' || ($_vc_t3['sig'] ?? null) !== $vc_sig) $_vc_t3 = null;
    if ($_vc_t3 !== null) {
        $_etiq_catalogo  = is_array($_vc_t3['catalogo'] ?? null) ? $_vc_t3['catalogo'] : array();
        $_etiq_por_email = is_array($_vc_t3['por_email'] ?? null) ? $_vc_t3['por_email'] : array();
    } elseif (!empty($launch_products) && !empty($ext_host) && !empty($ext_user_db)) {
        try {
            $pdo_et = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user_db, $ext_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            // a) productos del lanzamiento -> IDs internos PDC
            $ph_p = implode(',', array_fill(0, count($launch_products), '?'));
            $st_p = $pdo_et->prepare("SELECT ID FROM pdc_productos WHERE ID_producto IN ($ph_p)");
            $st_p->execute(array_values($launch_products));
            $_pdc_prod_ids = array_map('intval', $st_p->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($_pdc_prod_ids)) {
                // b) segmentaciones activas: etiquetas que se PONEN en cada escenario
                $ph_s = implode(',', array_fill(0, count($_pdc_prod_ids), '?'));
                $st_s = $pdo_et->prepare(
                    "SELECT S.ID_escenario, S.etiquetas_poner, E.nombre AS escenario
                       FROM pdc_segmentaciones S
                       LEFT JOIN pdc_escenarios E ON E.ID = S.ID_escenario
                      WHERE S.activa = 1 AND S.ID_producto IN ($ph_s)
                        AND S.etiquetas_poner IS NOT NULL AND S.etiquetas_poner <> ''"
                );
                $st_s->execute(array_map('strval', $_pdc_prod_ids));
                $_tag_escenarios = array(); // ID_etiqueta => [escenario => true]
                foreach ($st_s->fetchAll(PDO::FETCH_ASSOC) as $_sg) {
                    $_esc = trim((string) ($_sg['escenario'] ?? '')) ?: ('Escenario ' . (int) $_sg['ID_escenario']);
                    foreach (preg_split('/[\s,]+/', (string) $_sg['etiquetas_poner'], -1, PREG_SPLIT_NO_EMPTY) as $_tid) {
                        $_tid = (int) $_tid;
                        if ($_tid <= 0) continue;
                        $_tag_escenarios[$_tid][$_esc] = true;
                    }
                }
                if (!empty($_tag_escenarios)) {
                    // c) nombres de las etiquetas
                    $_tids = array_keys($_tag_escenarios);
                    $ph_t = implode(',', array_fill(0, count($_tids), '?'));
                    $st_t = $pdo_et->prepare("SELECT ID, nombre, categoria FROM pdc_etiquetas WHERE ID IN ($ph_t)");
                    $st_t->execute($_tids);
                    foreach ($st_t->fetchAll(PDO::FETCH_ASSOC) as $_tg) {
                        $_etiq_catalogo[(int) $_tg['ID']] = array(
                            'nombre'     => (string) $_tg['nombre'],
                            'categoria'  => (string) ($_tg['categoria'] ?? ''),
                            'escenarios' => array_keys($_tag_escenarios[(int) $_tg['ID']]),
                        );
                    }
                    // d) etiquetas ACTIVAS de cada comprador (por ID de usuario PDC), en lotes
                    $_uid_mail = array();
                    foreach ($_users_meta as $_bmail => $_bm) {
                        if (!empty($_bm['pdc_usuario_id'])) $_uid_mail[(int) $_bm['pdc_usuario_id']] = $_bmail;
                    }
                    if (!empty($_uid_mail) && !empty($_etiq_catalogo)) {
                        $_cat_ids = array_keys($_etiq_catalogo);
                        $ph_c = implode(',', array_fill(0, count($_cat_ids), '?'));
                        foreach (array_chunk(array_keys($_uid_mail), 500) as $_chunk) {
                            $ph_u2 = implode(',', array_fill(0, count($_chunk), '?'));
                            $st_h = $pdo_et->prepare(
                                "SELECT DISTINCT ID_usuario, ID_etiqueta
                                   FROM pdc_historial_etiquetas
                                  WHERE fecha_eliminacion IS NULL
                                    AND ID_etiqueta IN ($ph_c)
                                    AND ID_usuario IN ($ph_u2)"
                            );
                            $st_h->execute(array_merge($_cat_ids, $_chunk));
                            foreach ($st_h->fetchAll(PDO::FETCH_ASSOC) as $_hr) {
                                $_uid = (int) $_hr['ID_usuario'];
                                if (!isset($_uid_mail[$_uid])) continue;
                                $_etiq_por_email[$_uid_mail[$_uid]][] = (int) $_hr['ID_etiqueta'];
                            }
                        }
                    }
                }
            }
            $pdo_et = null;
            if ($vc_sig !== '') {
                set_transient($vc_cache_ns . '_etiq', array('sig' => $vc_sig, 'catalogo' => $_etiq_catalogo, 'por_email' => $_etiq_por_email), 5 * MINUTE_IN_SECONDS);
            }
        } catch (Exception $_e) {
            $_etiq_error = $_e->getMessage();
        }
    }
    // Orden del desplegable: por escenario (Compra primero) y nombre
    $_etiq_orden_esc = array('Compra' => 1, 'Pago de cuota' => 2, 'Impago de cuota' => 3, 'Reembolso' => 4, 'Suscripcion cancelada' => 5, 'Suscripcion pausada' => 6, 'Suscripcion resumida' => 7, 'Acceso Expirado o Cancelado' => 8);
    $_etiq_grupos = array(); // escenario => [ID_etiqueta => nombre]
    foreach ($_etiq_catalogo as $_tid => $_tg) {
        foreach ($_tg['escenarios'] as $_esc) $_etiq_grupos[$_esc][$_tid] = $_tg['nombre'];
    }
    uksort($_etiq_grupos, function ($a, $b) use ($_etiq_orden_esc) {
        return (isset($_etiq_orden_esc[$a]) ? $_etiq_orden_esc[$a] : 99) <=> (isset($_etiq_orden_esc[$b]) ? $_etiq_orden_esc[$b] : 99);
    });
    foreach ($_etiq_grupos as &$_g) { asort($_g, SORT_NATURAL | SORT_FLAG_CASE); } unset($_g);
    $_etiq_usuarios_con = 0;
    foreach ($_etiq_por_email as $_em => $_ids) { if (!empty($_ids)) $_etiq_usuarios_con++; }

    // ============================================================
    // 3d. ETIQUETA DE ACCESO del lanzamiento (mapa aparte del rol)
    //   El tipo de "Usuario PDC" por ROL (pdc_capabilities: alumno / noacceso /
    //   sin_rol / otro / no creado) se mantiene tal cual. Además, si los productos
    //   del lanzamiento ponen alguna etiqueta de categoría 'acceso' (ej.
    //   "[FPVM] Acceso"), se calcula por comprador:
    //     '1' = usuario creado en el campus CON la etiqueta de acceso activa
    //     '0' = usuario creado en el campus SIN ella (se la quitaron o nunca la tuvo)
    //     ''  = no creado en el campus (no aplica)
    //   Se usa en el filtro "Usuario PDC" como opciones adicionales.
    // ============================================================
    $_etiq_acceso_ids = array();
    foreach ($_etiq_catalogo as $_tid => $_tg) {
        if (strtolower(trim((string) $_tg['categoria'])) === 'acceso') $_etiq_acceso_ids[(int) $_tid] = $_tg['nombre'];
    }
    $_acceso_etiq = array(); // email => '1' | '0'
    if (!empty($_etiq_acceso_ids)) {
        foreach ($_users_meta as $_bmail => $_bm) {
            $_tipo_prev = isset($_pdc_user_tipo[$_bmail]) ? $_pdc_user_tipo[$_bmail] : '';
            $_creado = ($_tipo_prev !== '') || !empty($_bm['pdc_usuario_id']);
            if (!$_creado) continue; // no existe en el campus: no aplica
            $_tiene_acceso = false;
            foreach ((array) ($_etiq_por_email[$_bmail] ?? array()) as $_tid) {
                if (isset($_etiq_acceso_ids[(int) $_tid])) { $_tiene_acceso = true; break; }
            }
            $_acceso_etiq[$_bmail] = $_tiene_acceso ? '1' : '0';
        }
    }
    $_acceso_nombre = implode(' / ', $_etiq_acceso_ids);

    // Per-user state aggregation (excluye filas marcadas como test/error)
    $_state_pagado = 0;
    $_state_curso = 0;
    $_state_impago = 0;
    $_state_parcial = 0;
    $_state_dup = 0; // compradores con alguna línea marcada 'pago duplicado' (con saldo 0 o no)
    $_state_devolucion = 0;
    $_state_solo_acceso = 0;
    $_imp_total = 0;
    $_count_test = 0;
    $_count_error = 0;
    $_count_alumno = 0;
    $_count_noacceso = 0;
    $_count_no_creado = 0;
    // Compradores creados en el campus que NO tienen la etiqueta de acceso del
    // lanzamiento. Es distinto del ROL 'noacceso' de pdc_capabilities: se puede
    // tener rol alumno y haber perdido la etiqueta del curso (impago, devolución,
    // cancelación) — que es lo que de verdad decide si entra al curso.
    $_count_sin_etq_acceso = 0;
    foreach ($_users_meta as $email_k => $m) {
        $marca_u = isset($_saved_estado[$email_k]) ? (string)$_saved_estado[$email_k]->marca : '';
        if ($marca_u === 'test')  { $_count_test++;  continue; }
        if ($marca_u === 'error') { $_count_error++; continue; }
        $_tipo_u = isset($_pdc_user_tipo[$email_k]) ? $_pdc_user_tipo[$email_k] : '';
        if     ($_tipo_u === 'alumno')   $_count_alumno++;
        elseif ($_tipo_u === 'noacceso') $_count_noacceso++;
        elseif ($_tipo_u === '')         $_count_no_creado++;
        if (isset($_acceso_etiq[$email_k]) && $_acceso_etiq[$email_k] === '0') $_count_sin_etq_acceso++;
        if (!empty($m['no_pago'])) { $_state_solo_acceso++; continue; } // acceso sin compra: fuera de estados financieros
        $_imp_total += $m['importe_total'];
        // "Pago duplicado" NO es un estado excluyente como los demás: quien pagó
        // dos veces y se quedó con el curso sigue siendo "pagado completo" (saldo
        // > 0) y antes no se contaba en ninguna parte. Se cuenta aparte.
        if (!empty($m['has_reembolso_real'])) $_state_dup++;
        if ($m['has_reembolso'] && $m['importe_total'] < 0.01) {
            if (empty($m['has_reembolso_real'])) { $_state_devolucion++; }
        } elseif ($m['has_impago'] && $m['cuotas_pagadas'] === 0) {
            $_state_impago++;
        } elseif ($m['has_impago']) {
            $_state_parcial++;
        } elseif ($m['cuotas_pagadas'] >= $m['cuotas_total']) {
            $_state_pagado++;
        } else {
            $_state_curso++;
        }
    }
    $_visible_count = count($_users_meta) - $_count_test - $_count_error - $_state_solo_acceso;

    // Listas distintas para los selects de filtro
    $_tipo_set       = array();
    $_plataforma_set = array();
    $_cuotas_set     = array();
    foreach ($_users_meta as $_m_iter) {
        $_tp = trim((string)($_m_iter['tipo'] ?? ''));
        if ($_tp !== '') $_tipo_set[$_tp] = true;
        $_pl = trim((string)($_m_iter['plataforma'] ?? ''));
        if ($_pl !== '') $_plataforma_set[$_pl] = true;
        $_ct = intval($_m_iter['cuotas_total'] ?? 1);
        if ($_ct > 0) $_cuotas_set[$_ct] = true;
    }
    $_estado_set = array();
    foreach ($_users_meta as $_m_iter) {
        foreach (array_keys($_m_iter['estados'] ?? array()) as $_es) {
            $_es = trim((string)$_es);
            if ($_es !== '') $_estado_set[$_es] = true;
        }
    }
    $_estado_list = array_keys($_estado_set);
    sort($_estado_list);
    $_tipo_list = array_keys($_tipo_set);
    sort($_tipo_list);
    $_plataforma_list = array_keys($_plataforma_set);
    sort($_plataforma_list);
    $_cuotas_list = array_keys($_cuotas_set);
    sort($_cuotas_list, SORT_NUMERIC);

    // ============================================================
    // 3c. ATRIBUCIÓN POR COMPRADOR (source/medium/campaign/afiliado)
    //     Misma lógica que stats-ventas-fullscreen.php:
    //       wp_datos (primer_reg) → enriquecido con UTM de pdc_pagos →
    //       fallback por IP (pdc_accesos → wp_visitas).
    //     Se usa para el botón "Exportar CSV con atribución".
    // ============================================================
    $col_primer_reg = ($active_launch === 'legacy') ? 'primer_reg' : ('primer_reg_' . $safe_launch);
    $table_datos    = $wpdb->prefix . 'datos';
    $table_visitas  = $wpdb->prefix . 'visitas';
    $_attrib_map    = array();   // email => ['source','medium','campaign','afiliado']
    // Caché de la atribución (wp_datos + pdc_accesos + wp_visitas), validada por firma
    $_vc_t3 = get_transient($vc_cache_ns . '_attr');
    if (!is_array($_vc_t3) || $vc_sig === '' || ($_vc_t3['sig'] ?? null) !== $vc_sig) $_vc_t3 = null;

    $_buyer_emails = array_values(array_filter(array_keys($_users_meta), function ($e) {
        return $e !== '' && $e !== '(sin email)';
    }));

    // (a) Atribución base desde wp_datos (primer_reg del lanzamiento)
    $mail_to_attr = array();
    if ($_vc_t3 === null && !empty($_buyer_emails)) {
        foreach (array_chunk($_buyer_emails, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT LOWER(TRIM(correo)) AS correo, source, medium, campaign, afiliado
                 FROM {$table_datos}
                 WHERE {$col_primer_reg} = 1 AND correo != '' AND correo IS NOT NULL
                   AND LOWER(TRIM(correo)) IN ($ph)",
                ...$chunk
            ));
            foreach ($rows as $r) {
                $k = strtolower(trim((string)$r->correo));
                if ($k === '' || isset($mail_to_attr[$k])) continue;
                $mail_to_attr[$k] = array(
                    'source'   => (string)$r->source,
                    'medium'   => $r->medium ?: 'none',
                    'campaign' => $r->campaign ?: '(sin campaña)',
                    'afiliado' => intval($r->afiliado),
                );
            }
        }
    }

    $_known_sources = array('organico','publicidad','afiliado','email','sms','whatsapp','referral','referido');
    $_needs_enrich = function ($s) use ($_known_sources) {
        return !in_array(strtolower(trim((string)$s)), $_known_sources, true);
    };

    // (b) Por comprador: combinar wp_datos + UTM de pdc_pagos (fila cuota=1 o primera)
    $_ip_candidates = array();
    if ($_vc_t3 === null) foreach ($_users_meta as $bmail => $bm) {
        if ($bmail === '' || $bmail === '(sin email)') continue;
        $pdc_src = ''; $pdc_med = ''; $pdc_cam = ''; $pdc_ref = '';
        if (!empty($_users_idx[$bmail])) {
            $chosen = null;
            foreach ($_users_idx[$bmail] as $row) {
                if ((int)($row['cuota'] ?? 0) === 1) { $chosen = $row; break; }
            }
            if ($chosen === null) $chosen = $_users_idx[$bmail][0];
            $pdc_src = trim((string)($chosen['source']   ?? ''));
            $pdc_med = trim((string)($chosen['medium']   ?? ''));
            $pdc_cam = trim((string)($chosen['campaign'] ?? ''));
            $pdc_ref = trim((string)($chosen['ref']      ?? ''));
        }
        $pdc_ref_int = intval($pdc_ref);
        if ($pdc_src === '' && $pdc_med !== '') {
            $ml = strtolower($pdc_med);
            if (in_array($ml, array('meta','fb','facebook','instagram','ig','googleads','gads','google','cpc','ppc','sem'))) $pdc_src = 'publicidad';
            elseif (in_array($ml, array('email','newsletter','mailing'))) $pdc_src = 'email';
            elseif (in_array($ml, array('organico','organic','seo'))) $pdc_src = 'organico';
        }
        if ($pdc_src === '' && $pdc_ref !== '') $pdc_src = 'afiliado';

        $attr = isset($mail_to_attr[$bmail]) ? $mail_to_attr[$bmail] : null;
        if (!$attr) {
            $attr = array(
                'source'   => $pdc_src !== '' ? $pdc_src : 'directo',
                'medium'   => $pdc_med !== '' ? $pdc_med : 'none',
                'campaign' => $pdc_cam !== '' ? $pdc_cam : '(sin campaña)',
                'afiliado' => $pdc_ref_int,
            );
        } elseif ($_needs_enrich($attr['source'])) {
            if ($pdc_src !== '') $attr['source'] = $pdc_src;
            if ($pdc_med !== '') $attr['medium'] = $pdc_med;
            if ($pdc_cam !== '') $attr['campaign'] = $pdc_cam;
            if ($pdc_ref_int > 0 && (int)$attr['afiliado'] === 0) $attr['afiliado'] = $pdc_ref_int;
            if (trim((string)$attr['source']) === '' || strtolower((string)$attr['source']) === 'sin registro') $attr['source'] = 'directo';
        }
        $_attrib_map[$bmail] = $attr;
        if ($_needs_enrich($attr['source']) && empty($bm['no_pago'])) $_ip_candidates[$bmail] = true;
    }

    // (c) Fallback por IP: pdc_accesos (DB externa) → wp_visitas UTM
    if ($_vc_t3 === null && !empty($_ip_candidates) && !empty($ext_host) && !empty($ext_user_db)) {
        try {
            $cand = array_keys($_ip_candidates);
            $mail_to_ip = array();
            $pdo_acc = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user_db, $ext_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]
            );
            foreach (array_chunk($cand, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo_acc->prepare(
                    "SELECT LOWER(TRIM(U.mail)) AS mail, A.IP
                     FROM pdc_usuarios U INNER JOIN pdc_accesos A ON A.ID_alumno = U.ID
                     WHERE LOWER(TRIM(U.mail)) IN ($ph) AND A.IP IS NOT NULL AND A.IP != ''
                     ORDER BY A.hora DESC"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $mm = strtolower(trim((string)$a['mail']));
                    if ($mm !== '' && !isset($mail_to_ip[$mm])) $mail_to_ip[$mm] = trim((string)$a['IP']);
                }
            }
            $pdo_acc = null;
            if (!empty($mail_to_ip)) {
                $ips = array_values(array_unique(array_values($mail_to_ip)));
                $ip_to_utm = array();
                foreach (array_chunk($ips, 500) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '%s'));
                    $vis = $wpdb->get_results($wpdb->prepare(
                        "SELECT V.ip, V.ref_s, V.ref_m, V.ref_c
                         FROM {$table_visitas} V
                         INNER JOIN (SELECT ip, MAX(fecha) AS mf FROM {$table_visitas}
                                     WHERE ip IN ($ph) AND (ref_s != '' OR ref_m != '' OR ref_c != '')
                                     GROUP BY ip) T ON V.ip = T.ip AND V.fecha = T.mf
                         WHERE V.ip IN ($ph)",
                        ...array_merge($chunk, $chunk)
                    ));
                    foreach ($vis as $vr) {
                        $ip_to_utm[$vr->ip] = array('source' => $vr->ref_s ?: '', 'medium' => $vr->ref_m ?: '', 'campaign' => $vr->ref_c ?: '');
                    }
                }
                foreach ($mail_to_ip as $mm => $ip) {
                    if (!isset($_attrib_map[$mm]) || !isset($ip_to_utm[$ip])) continue;
                    if (!$_needs_enrich($_attrib_map[$mm]['source'])) continue;
                    $u = $ip_to_utm[$ip];
                    if ($u['source']   !== '') $_attrib_map[$mm]['source']   = $u['source'];
                    if ($u['medium']   !== '') $_attrib_map[$mm]['medium']   = $u['medium'];
                    if ($u['campaign'] !== '') $_attrib_map[$mm]['campaign'] = $u['campaign'];
                }
            }
        } catch (Exception $_e) { /* sin fallback por IP */ }
    }

    // Aplicar (o guardar) el caché de atribución
    if ($_vc_t3 !== null) {
        $_attrib_map = is_array($_vc_t3['map'] ?? null) ? $_vc_t3['map'] : array();
    } elseif ($vc_sig !== '') {
        set_transient($vc_cache_ns . '_attr', array('sig' => $vc_sig, 'map' => $_attrib_map), 5 * MINUTE_IN_SECONDS);
    }

    // (d) Resolver nombres de afiliado (WP user IDs)
    $_aff_names = array();
    foreach ($_attrib_map as $am) {
        $aid = intval($am['afiliado'] ?? 0);
        if ($aid > 0 && !isset($_aff_names[$aid])) {
            $u = get_userdata($aid);
            $_aff_names[$aid] = $u ? $u->display_name : ('ID: ' . $aid);
        }
    }
    ?>

    <!-- ════ FILTRO ÚNICO ════ -->
    <div id="vc-filters-panel" style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 18px; margin-bottom:18px; backdrop-filter:blur(10px);">
        <form method="GET" class="vc-hide-compact" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Lanzamiento</label>
                <select name="launch" onchange="this.form.submit()" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:13px;">
                    <option value="legacy" <?php selected($active_launch, 'legacy'); ?>>Anteriores</option>
                    <?php
                    $_lset = get_option('endtrack_launches', array());
                    foreach (array_reverse($_lset) as $_l): ?>
                        <option value="<?php echo esc_attr($_l); ?>" <?php selected($active_launch, $_l); ?>><?php echo esc_html(ucfirst($_l)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Desde</label>
                <input type="date" name="d_start" value="<?php echo esc_attr($filter_date_start); ?>" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:13px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Hasta</label>
                <input type="date" name="d_end" value="<?php echo esc_attr($filter_date_end); ?>" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:13px;">
            </div>
            <button type="submit" class="btn-filter" style="font-size:12px; padding:7px 16px;"><i class="fas fa-sliders-h"></i> Aplicar</button>
            <button type="submit" name="refresh" value="1" class="btn-filter" title="Recalcular ignorando el caché" style="font-size:12px; padding:7px 16px; background:#0ea5e9; box-shadow:0 4px 12px rgba(14,165,233,.4);"><i class="fas fa-rotate"></i> Actualizar datos</button>
            <div style="margin-left:auto; display:flex; flex-direction:column; gap:4px; align-items:flex-end;">
                <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">URL</span>
                <code style="font-size:10px; color:#a5b4fc;">/ventas-completas/?launch=<?php echo esc_html($active_launch); ?></code>
            </div>
        </form>

        <!-- Productos (id_producto) añadidos a este lanzamiento -->
        <div class="vc-hide-compact" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:12px; align-items:center;">
            <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:.5px;">Productos del lanzamiento (<?php echo count($launch_products); ?>):</span>
            <?php
            $_lp_chip_sty = array('principal' => array('Principal', '#6366f1'), 'downsell' => array('Downsell', '#f59e0b'), 'crosssell' => array('Cross-sell', '#06b6d4'));
            if (empty($launch_products)) {
                echo '<span style="font-size:11px; color:#f59e0b;">⚠ Sin productos configurados para este lanzamiento</span>';
            } else {
                $_lp_total = count($launch_products);
                $_lp_limit = 8;
                $_lp_i = 0;
                foreach ($launch_products as $_lp) {
                    $_lp_i++;
                    $_lpcat = isset($_prod_cat_map[$_lp]) ? $_prod_cat_map[$_lp] : '';
                    $_lplbl = isset($_lp_chip_sty[$_lpcat]) ? $_lp_chip_sty[$_lpcat][0] : 'Sin grupo';
                    $_lpcol = isset($_lp_chip_sty[$_lpcat]) ? $_lp_chip_sty[$_lpcat][1] : '#64748b';
                    $_lp_extra = ($_lp_total > $_lp_limit && $_lp_i > $_lp_limit);
                    $_lp_tit = isset($_prod_names[(string) $_lp]) ? $_prod_names[(string) $_lp] . ' · ' . $_lplbl : $_lplbl;
                    echo '<span class="' . ($_lp_extra ? 'vc-lp-extra' : '') . '" style="' . ($_lp_extra ? 'display:none; ' : 'display:inline-block; ') . 'background:' . $_lpcol . '22; border:1px solid ' . $_lpcol . '; color:' . $_lpcol . '; padding:2px 8px; border-radius:99px; font-size:10px; font-weight:600; font-family:monospace;" title="' . esc_attr($_lp_tit) . '">' . esc_html($_lp) . '</span>';
                }
                if ($_lp_total > $_lp_limit) {
                    echo '<button type="button" id="vc-lp-toggle" data-more="' . ($_lp_total - $_lp_limit) . '" style="background:rgba(255,255,255,.06); border:1px dashed rgba(255,255,255,.3); color:#94a3b8; padding:2px 10px; border-radius:99px; font-size:10px; font-weight:700; cursor:pointer; font-family:inherit;">+' . ($_lp_total - $_lp_limit) . ' más…</button>';
                }
            }
            if (current_user_can('manage_options')) {
                echo '<a href="' . esc_url(admin_url('admin.php?page=endtrack&tab=launches')) . '" target="_blank" style="font-size:11px; color:#a5b4fc; text-decoration:none; border:1px dashed rgba(165,180,252,.5); padding:2px 10px; border-radius:99px;"><i class="fas fa-pen" style="font-size:9px;"></i> Modificar en el panel admin</a>';
            }
            ?>
        </div>
        <script>
        (function () {
            var btn = document.getElementById('vc-lp-toggle');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var extras = document.querySelectorAll('.vc-lp-extra');
                var collapsed = extras.length > 0 && extras[0].style.display === 'none';
                extras.forEach(function (el) { el.style.display = collapsed ? 'inline-block' : 'none'; });
                btn.textContent = collapsed ? 'Mostrar menos' : ('+' + btn.dataset.more + ' más…');
            });
        })();
        </script>

        <div id="vc-ocultas-wrap" class="vc-hide-compact" style="display:<?php echo ($_count_test > 0 || $_count_error > 0) ? 'flex' : 'none'; ?>; gap:8px; margin-top:10px; align-items:center; flex-wrap:wrap;">
            <span style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:.5px;">Ocultas:</span>
            <button type="button" id="vc-chip-test" data-active="0" class="vc-chip" style="display:<?php echo $_count_test > 0 ? 'inline-block' : 'none'; ?>; background:rgba(168,85,247,.08); border:1px solid rgba(168,85,247,.3); color:#c4b5fd; padding:3px 9px; border-radius:99px; cursor:pointer; font-size:11px; font-family:inherit; transition:all .15s;">🧪 Pruebas (<span id="vc-chip-test-n"><?php echo (int)$_count_test; ?></span>)</button>
            <button type="button" id="vc-chip-error" data-active="0" class="vc-chip" style="display:<?php echo $_count_error > 0 ? 'inline-block' : 'none'; ?>; background:rgba(239,68,68,.08); border:1px solid rgba(239,68,68,.3); color:#fca5a5; padding:3px 9px; border-radius:99px; cursor:pointer; font-size:11px; font-family:inherit; transition:all .15s;">❌ Ocultadas (<span id="vc-chip-error-n"><?php echo (int)$_count_error; ?></span>)</button>
            <span style="color:var(--text-muted); font-size:10px;">No cuentan en KPIs. Click para mostrar/ocultar.</span>
        </div>

        <hr style="border:none; border-top:1px solid rgba(255,255,255,.06); margin:14px 0;">

        <!-- Filtros cliente (tabla) -->
        <div id="vc-client-filters" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Buscar (correo, atribución, nombre, apellidos, producto, IDs)</label>
                <input type="search" id="vc-f-q" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px; width:360px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Estado</label>
                <select id="vc-f-estado" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <option value="ok">Solo OK (pagados)</option>
                    <option value="reembolso">🔁 Pago duplicado</option>
                    <option value="devolucion">↩ Devolución</option>
                    <option value="nopago">🎓 Acceso sin pago</option>
                    <option value="plazos_ok">📆 Plazos completados</option>
                    <option value="plazos_no">📆 Plazos NO completados</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Color</label>
                <select id="vc-f-color" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <option value="rojo">🔴 Rojo</option>
                    <option value="amarillo">🟡 Amarillo</option>
                    <option value="verde">🟢 Verde</option>
                    <option value="morado">🟣 Morado</option>
                    <option value="azul">🔵 Azul</option>
                    <option value="none">⚪ Sin marcar</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Usuario PDC</label>
                <select id="vc-f-utipo" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <optgroup label="Por ROL del usuario en el campus (pdc_capabilities)">
                        <option value="alumno" title="Rol 'alumno' en el campus">🎓 Alumno (rol alumno)</option>
                        <option value="noacceso" title="Rol 'noacceso' en el campus: el usuario existe pero se le retiró el acceso general">🔒 Sin acceso (rol noacceso)</option>
                        <option value="sin_rol" title="Usuario creado en el campus sin ningún rol">⚪ Sin rol</option>
                        <option value="no_creado" title="El correo no existe como usuario en el campus">❓ No creado</option>
                        <option value="otro" title="Cualquier otro rol (admin, editor…)">⚠ Otro rol</option>
                    </optgroup>
                    <?php if (!empty($_etiq_acceso_ids)): ?>
                    <optgroup label="Por ETIQUETA de acceso del lanzamiento">
                        <option value="etq_con" title="Usuario creado en el campus que tiene activa la etiqueta <?php echo esc_attr($_acceso_nombre); ?>">🔑 Con <?php echo esc_html($_acceso_nombre); ?></option>
                        <option value="etq_sin" title="Usuario creado en el campus que NO tiene la etiqueta <?php echo esc_attr($_acceso_nombre); ?> (se la quitaron por impago/reembolso/cancelación o nunca la tuvo)">🚫 Sin acceso (sin <?php echo esc_html($_acceso_nombre); ?>)</option>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Tipo producto</label>
                <select id="vc-f-ptipo" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <option value="principal">Principal</option>
                    <option value="downsell">Downsell</option>
                    <option value="crosssell">Cross-sell</option>
                </select>
            </div>
            <?php if (count($launch_products) > 1):
                $_prod_labels = function_exists('endtrack_product_option_labels')
                    ? endtrack_product_option_labels($launch_products, $_prod_names)
                    : array_combine(array_map('strval', $launch_products), array_map('strval', $launch_products)); ?>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Producto</label>
                <select id="vc-f-prod" title="Compradores con algún pago de este producto (nombre del campus)" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px; max-width:260px;">
                    <option value="">Todos</option>
                    <?php foreach ($launch_products as $_lp): $_lp = (string) $_lp; ?>
                        <option value="<?php echo esc_attr($_lp); ?>" data-label="<?php echo esc_attr($_prod_labels[$_lp]); ?>" title="<?php echo esc_attr($_lp); ?>"><?php echo esc_html($_prod_labels[$_lp]); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Tipo</label>
                <select id="vc-f-tipopago" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <?php foreach ($_tipo_list as $_tp): ?>
                        <option value="<?php echo esc_attr($_tp); ?>"><?php echo esc_html($_tp); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Plataforma</label>
                <select id="vc-f-plataforma" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todas</option>
                    <?php foreach ($_plataforma_list as $_pl): ?>
                        <option value="<?php echo esc_attr($_pl); ?>"><?php echo esc_html($_pl); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Estado pago</label>
                <select id="vc-f-estadopago" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <?php foreach ($_estado_list as $_es): ?>
                        <option value="<?php echo esc_attr($_es); ?>"><?php echo esc_html($_es); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Alumno (compra)</label>
                <select id="vc-f-alumnofecha" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todos</option>
                    <option value="si">🎓 Alumno (cliente previo)</option>
                    <option value="no">🆕 No alumno</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px; position:relative;" id="vc-f-etiq-wrap">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Etiquetas PDC</label>
                <button type="button" id="vc-f-etiq-btn" title="Filtrar por etiquetas de acceso del campus (selección múltiple)" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px; cursor:pointer; min-width:150px; text-align:left; display:flex; justify-content:space-between; gap:8px; align-items:center;">
                    <span id="vc-f-etiq-label">Todas</span><span style="opacity:.6; font-size:10px;">▼</span>
                </button>
                <div id="vc-f-etiq-pop" style="display:none; position:absolute; top:100%; left:0; z-index:60; margin-top:4px; background:#0f172a; border:1px solid rgba(99,102,241,.4); border-radius:10px; padding:10px 12px; min-width:300px; max-width:380px; max-height:380px; overflow:auto; box-shadow:0 12px 30px rgba(0,0,0,.5); font-size:12px;">
                    <?php if (empty($_etiq_catalogo)): ?>
                        <div style="color:var(--text-muted); font-size:11px; line-height:1.4;">
                            <?php if ($_etiq_error): ?>⚠ No se pudieron leer las etiquetas: <?php echo esc_html($_etiq_error); ?>
                            <?php elseif (empty($launch_products)): ?>Este lanzamiento no tiene productos PDC configurados (panel admin → Lanzamientos).
                            <?php else: ?>Los productos de este lanzamiento no tienen segmentaciones con etiquetas en el campus.<?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="display:flex; gap:6px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">
                            <span style="color:var(--text-muted); font-size:10px; text-transform:uppercase; font-weight:700;">Modo</span>
                            <label style="cursor:pointer; display:inline-flex; gap:4px; align-items:center;"><input type="radio" name="vc-f-etiq-modo" value="any" checked style="margin:0;"> Cualquiera</label>
                            <label style="cursor:pointer; display:inline-flex; gap:4px; align-items:center;"><input type="radio" name="vc-f-etiq-modo" value="all" style="margin:0;"> Todas</label>
                            <label style="cursor:pointer; display:inline-flex; gap:4px; align-items:center;"><input type="radio" name="vc-f-etiq-modo" value="none" style="margin:0;"> Ninguna de estas</label>
                            <button type="button" id="vc-f-etiq-reset" style="margin-left:auto; background:transparent; border:1px solid rgba(255,255,255,.15); color:var(--text-muted); border-radius:5px; padding:2px 7px; font-size:10px; cursor:pointer;">Limpiar</button>
                        </div>
                        <?php foreach ($_etiq_grupos as $_esc => $_tags): ?>
                            <div style="margin:6px 0 3px; font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#a5b4fc; font-weight:700;"><?php echo esc_html($_esc); ?></div>
                            <?php foreach ($_tags as $_tid => $_tn): ?>
                                <label style="display:flex; gap:6px; align-items:center; padding:3px 2px; cursor:pointer; color:#e2e8f0;" title="ID etiqueta <?php echo (int) $_tid; ?>">
                                    <input type="checkbox" class="vc-f-etiq-cb" value="<?php echo (int) $_tid; ?>" style="margin:0;">
                                    <span style="flex:1;"><?php echo esc_html($_tn); ?></span>
                                    <span class="vc-etiq-n" data-tid="<?php echo (int) $_tid; ?>" style="color:var(--text-muted); font-size:10px;"></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <label style="display:flex; gap:6px; align-items:center; padding:6px 2px 2px; cursor:pointer; color:#fca5a5; border-top:1px solid rgba(255,255,255,.08); margin-top:6px;">
                            <input type="checkbox" id="vc-f-etiq-sin" style="margin:0;"> <span style="flex:1;">Sin ninguna etiqueta del lanzamiento</span><span id="vc-etiq-n-sin" style="color:var(--text-muted); font-size:10px;"></span>
                        </label>
                        <div style="color:var(--text-muted); font-size:10px; margin-top:8px; line-height:1.4;">Etiquetas <em>activas</em> ahora mismo en el campus (pdc_historial_etiquetas), según las segmentaciones de los productos del lanzamiento. Usuarios con alguna: <strong><?php echo (int) $_etiq_usuarios_con; ?></strong>.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Cuotas (plan)</label>
                <select id="vc-f-cuotas" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 10px; border-radius:6px; font-size:12px;">
                    <option value="">Todas</option>
                    <?php foreach ($_cuotas_list as $_ct): ?>
                        <option value="<?php echo esc_attr($_ct); ?>"><?php echo (int)$_ct; ?> cuota<?php echo $_ct == 1 ? '' : 's'; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Importe €</label>
                <div style="display:flex; gap:4px;">
                    <input type="number" id="vc-f-imin" placeholder="mín" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 8px; border-radius:6px; font-size:12px; width:70px;">
                    <input type="number" id="vc-f-imax" placeholder="máx" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:6px 8px; border-radius:6px; font-size:12px; width:70px;">
                </div>
            </div>
            <div style="display:flex; gap:8px; margin-left:auto; align-items:center;">
                <div id="vc-pager">
                    <button type="button" id="vc-pg-prev" title="Página anterior">‹</button>
                    <span id="vc-pg-info"></span>
                    <button type="button" id="vc-pg-next" title="Página siguiente">›</button>
                    <select id="vc-pg-size" title="Filas por página" style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:white; padding:5px 8px; border-radius:6px; font-size:11px;">
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="250">250</option>
                        <option value="0">Todas</option>
                    </select>
                </div>
                <span id="vc-f-count" style="display:inline-flex; align-items:baseline; gap:6px; font-weight:800; font-size:22px; color:var(--text-muted); padding:4px 12px; border-radius:10px; transition:all .18s;"></span>
                <button type="button" id="vc-f-clear" style="background:rgba(255,255,255,.08); color:var(--text-muted); border:1px solid rgba(255,255,255,.15); padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px;">Limpiar</button>
                <button type="button" id="vc-f-csv" class="btn-filter" style="font-size:12px;"><i class="fas fa-download"></i> CSV</button>
                <button type="button" id="vc-f-csv-attr" class="btn-filter" style="font-size:12px; background:#10b981; box-shadow:0 4px 12px rgba(16,185,129,.4);"><i class="fas fa-download"></i> Exportar CSV con atribucion</button>
            </div>
        </div>
    </div>

    <!-- ════ KPIs ════ -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:18px;">
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #6366f1;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">Ventas por usuarios únicos</div>
            <div style="font-size:26px; font-weight:800; color:#a5b4fc; margin-top:4px;" id="kpi-visible"><?php echo number_format($_visible_count, 0, ',', '.'); ?></div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #10b981;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">✓ Pagado completo</div>
            <div style="font-size:26px; font-weight:800; color:#6ee7b7; margin-top:4px;" id="kpi-pagado"><?php echo number_format($_state_pagado, 0, ',', '.'); ?></div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #06b6d4;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">⏳ En curso (Plazos)</div>
            <div style="font-size:26px; font-weight:800; color:#67e8f9; margin-top:4px;" id="kpi-curso"><?php echo number_format($_state_curso, 0, ',', '.'); ?></div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #f43f5e;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;" title="Clientes con algún pago duplicado (pagaron dos veces o compraron con otro correo y se les devolvió lo repetido). Se cuentan todos, sigan o no con el curso.">🔁 Pago duplicado</div>
            <div style="font-size:26px; font-weight:800; color:#fda4af; margin-top:4px;" id="kpi-reembolso"><?php echo number_format($_state_dup, 0, ',', '.'); ?></div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #fb923c;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;" title="Compradores a los que se les devolvió su dinero (Stripe o devolución manual) y quedan a saldo 0.">↩ Devolución</div>
            <div style="font-size:26px; font-weight:800; color:#fdba74; margin-top:4px;" id="kpi-devolucion"><?php echo number_format($_state_devolucion, 0, ',', '.'); ?></div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #14b8a6;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">€ Cobrado total</div>
            <div style="font-size:22px; font-weight:800; color:#5eead4; margin-top:4px;" id="kpi-imp"><?php echo number_format($_imp_total, 2, ',', '.'); ?> €</div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #22c55e;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">🎓 Alumnos PDC</div>
            <div style="font-size:26px; font-weight:800; color:#86efac; margin-top:4px;" id="kpi-alumno"><?php echo number_format($_count_alumno, 0, ',', '.'); ?></div>
        </div>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #eab308;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;" title="<?php echo !empty($_etiq_acceso_ids)
                    ? 'Compradores creados en el campus que NO tienen activa la etiqueta ' . esc_attr($_acceso_nombre) . ' (se la quitaron por impago, devolución o cancelación, o nunca la tuvieron).'
                    : 'Este lanzamiento no tiene ninguna etiqueta de categoría \'acceso\' configurada, así que se cuenta por ROL del campus (pdc_capabilities = noacceso).'; ?>">🔒 Sin acceso<?php echo !empty($_etiq_acceso_ids) ? '' : ' (rol)'; ?></div>
            <div style="font-size:26px; font-weight:800; color:#fde047; margin-top:4px;" id="kpi-noacceso"><?php echo number_format(!empty($_etiq_acceso_ids) ? $_count_sin_etq_acceso : $_count_noacceso, 0, ',', '.'); ?></div>
        </div>
        <?php if ($_count_no_creado > 0): ?>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #94a3b8;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">❓ No creado</div>
            <div style="font-size:26px; font-weight:800; color:#cbd5e1; margin-top:4px;" id="kpi-nocreado"><?php echo number_format($_count_no_creado, 0, ',', '.'); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($_state_solo_acceso > 0): ?>
        <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:14px 16px; backdrop-filter:blur(10px); border-top:3px solid #a855f7;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;">🎓 Alumnos sin pago en pdc_pagos</div>
            <div style="font-size:26px; font-weight:800; color:#c4b5fd; margin-top:4px;" id="kpi-soloacceso"><?php echo number_format($_state_solo_acceso, 0, ',', '.'); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Leyenda editable de los colores de las bolitas (opción global compartida)
    $_color_hex = array('rojo'=>'#ef4444','amarillo'=>'#f59e0b','verde'=>'#10b981','morado'=>'#a855f7','azul'=>'#3b82f6');
    $_color_labels = json_decode(get_option('endtrack_pdc_color_labels', '[]'), true);
    if (!is_array($_color_labels)) $_color_labels = array();
    foreach (array_keys($_color_hex) as $_c) if (!isset($_color_labels[$_c])) $_color_labels[$_c] = '';
    $_can_edit_labels = $_can_edit;
    ?>
    <div style="background:var(--card-bg); border:1px solid var(--card-border); border-radius:12px; padding:12px 16px; margin-bottom:18px; backdrop-filter:blur(10px); display:flex; flex-wrap:wrap; gap:18px; align-items:center;">
        <span style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700;"><i class="fas fa-palette" style="margin-right:6px; color:var(--primary);"></i>Leyenda colores</span>
        <?php foreach ($_color_hex as $_c => $_hex): ?>
        <div style="display:flex; align-items:center; gap:7px;">
            <span style="width:14px; height:14px; border-radius:50%; background:<?php echo $_hex; ?>; flex:0 0 auto; box-shadow:0 0 0 1px rgba(255,255,255,.15);"></span>
            <input type="text" class="vc-color-label" data-color="<?php echo $_c; ?>" value="<?php echo esc_attr($_color_labels[$_c]); ?>" placeholder="<?php echo ucfirst($_c); ?>…" <?php echo $_can_edit_labels ? '' : 'readonly'; ?> style="background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#e2e8f0; padding:4px 8px; border-radius:6px; font-size:12px; font-family:inherit; width:150px;">
        </div>
        <?php endforeach; ?>
        <span id="vc-legend-status" style="font-size:11px; color:var(--secondary); min-width:70px;"></span>
    </div>

    <div class="dashboard-section" id="sec_detalle_completo" style="animation-delay:0.9s; margin-top:0;">
        <?php if (!empty($pdc_detail_error)): ?>
        <div style="background:rgba(244,63,94,.1); border:1px solid rgba(244,63,94,.3); color:#fca5a5; padding:12px 16px; border-radius:10px; margin-bottom:14px;">
            <strong>Aviso:</strong> No se pudo cargar el detalle.<br><code><?php echo esc_html($pdc_detail_error); ?></code>
        </div>
        <?php endif; ?>

        <!-- Tabla -->
        <div class="vc-table-wrap" style="background:rgba(15,23,42,.5); border:1px solid var(--card-border); border-radius:10px; overflow-x:auto;">
            <table id="vc-detalle-table" style="width:100%; border-collapse:separate; border-spacing:0; font-size:11px; color:var(--text-main); font-family:'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;">
                <thead style="position:sticky; top:0; background:#1e293b; z-index:3;">
                    <tr>
                        <th style="padding:8px 6px; border:1px solid rgba(255,255,255,.08); text-align:center; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700; width:28px;"></th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:center; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Color</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Email compra</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Email cambiado en plataforma</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Nombre</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Apellidos</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Producto</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Tipo prod.</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:right; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total €</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:center; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Cuotas</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Tipo</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Plataforma</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Estado pago</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Estado</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:left; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">País</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:center; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Fecha primera</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:center; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Usuario PDC</th>
                        <th style="padding:8px 8px; border:1px solid rgba(255,255,255,.08); text-align:center; font-size:10px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="vc-tbody">
                <?php
                // Fondo de fila a partir de VARIOS colores a la vez: 1 color = tinte plano,
                // 2+ colores = franjas verticales iguales (mismo criterio que en el JS del click).
                if (!function_exists('vc_row_bg_from_colors')) {
                    function vc_row_bg_from_colors($colors, $map, $fallback) {
                        $colors = array_values(array_filter($colors, function ($c) use ($map) { return isset($map[$c]); }));
                        if (empty($colors)) return $fallback;
                        if (count($colors) === 1) return $map[$colors[0]];
                        $n = count($colors);
                        $stops = array();
                        foreach ($colors as $i => $c) {
                            $hex = $map[$c];
                            $start = round($i * 100 / $n, 2);
                            $end = round(($i + 1) * 100 / $n, 2);
                            $stops[] = "{$hex} {$start}%";
                            $stops[] = "{$hex} {$end}%";
                        }
                        return 'linear-gradient(90deg, ' . implode(', ', $stops) . ')';
                    }
                }
                $vc_row_idx = 0;
                foreach ($_users_meta as $email => $m):
                    $vc_row_idx++;
                    $estado_data = isset($_saved_estado[$email]) ? $_saved_estado[$email] : null;
                    // $color puede llevar VARIOS colores a la vez, separados por coma (ej. "rojo,verde").
                    $color = $estado_data ? (string)$estado_data->color : '';
                    $color_arr = $color !== '' ? array_filter(explode(',', $color)) : array();
                    $atrib = $estado_data ? (string)$estado_data->atribucion_email : '';
                    $changed = isset($_changed_email[$email]) ? $_changed_email[$email] : '';
                    $_est_parts = array();
                    foreach (($m['estados'] ?? array()) as $_e => $_c) { $_est_parts[] = $_c > 1 ? ($_e . ' ×' . $_c) : $_e; }
                    $_est_str = implode(', ', $_est_parts);
                    $_est_data = implode('|', array_keys($m['estados'] ?? array()));
                    // Alumno POR PRECIO: alguna compra (Completado/Compra) con un importe de la lista.
                    // En el mismo bucle se cuentan los pagos a precio de PLAZO (filtro Plazos).
                    $_has_buy = false; $_alumno_price_hit = false; $_pl_counts = array(); $_first_buy = '';
                    foreach (($_users_idx[$email] ?? array()) as $_br) {
                        if (!in_array((string)($_br['estado'] ?? ''), array('Completado','Compra'), true)) continue;
                        $_has_buy = true;
                        $_bf = (string)($_br['fecha'] ?? '');
                        if ($_bf !== '' && ($_first_buy === '' || $_bf < $_first_buy)) $_first_buy = $_bf;
                        $_bi = (int) round(abs((float)($_br['importe'] ?? 0)));
                        if (isset($alumni_price_set[$_bi])) $_alumno_price_hit = true;
                        if (isset($plazos_price_map[$_bi])) $_pl_counts[$_bi] = isset($_pl_counts[$_bi]) ? $_pl_counts[$_bi] + 1 : 1;
                    }
                    // Alumno si cumple la regla de precio (VBS) O la de fecha de corte (inscripciones).
                    $_alumno_fecha_hit = ($_first_buy !== '' && $alumni_cutoff !== '' && $_first_buy < $alumni_cutoff);
                    $_alumno_fecha_data = !$_has_buy ? '' : (($_alumno_price_hit || $_alumno_fecha_hit) ? '1' : '0');
                    // Plazos: 'completado' si por cada precio de plazo detectado acumula el nº de
                    // cuotas del plan; 'pendiente' si le faltan; '' si no es compra a plazos.
                    // Sin plan de precios definido para el launch → fallback al campo cuotas.
                    $_plazos_data = '';
                    if (!empty($plazos_price_map)) {
                        if (!empty($_pl_counts)) {
                            $_pl_ok = true;
                            foreach ($_pl_counts as $_pp => $_pn) {
                                if ($_pn < $plazos_price_map[$_pp]) { $_pl_ok = false; break; }
                            }
                            $_plazos_data = $_pl_ok ? 'completado' : 'pendiente';
                        }
                    } elseif (empty($m['no_pago'])) {
                        // Sin tabla de precios para el launch: detectar series a plazos desde
                        // las propias filas. Agrupamos pagos Completado/Compra por IMPORTE;
                        // cada serie necesita tantos pagos de ese importe como indique el
                        // campo cuotas (>1) de sus filas. Así compras de otros productos del
                        // mismo comprador no inflan el conteo de cuotas pagadas.
                        $_series = array(); // importe => ['need' => cuotas del plan, 'paid' => pagos hechos]
                        foreach (($_users_idx[$email] ?? array()) as $_pr) {
                            if (!in_array((string)($_pr['estado'] ?? ''), array('Completado','Compra'), true)) continue;
                            $_pi = (int) round(abs((float)($_pr['importe'] ?? 0)));
                            $_pc = (int) ($_pr['cuotas'] ?? 1);
                            if (!isset($_series[$_pi])) $_series[$_pi] = array('need' => 1, 'paid' => 0);
                            $_series[$_pi]['paid']++;
                            if ($_pc > $_series[$_pi]['need']) $_series[$_pi]['need'] = $_pc;
                        }
                        $_has_plazo = false; $_pl_ok = true;
                        foreach ($_series as $_sv) {
                            if ($_sv['need'] > 1) {
                                $_has_plazo = true;
                                if ($_sv['paid'] < $_sv['need']) { $_pl_ok = false; break; }
                            }
                        }
                        if ($_has_plazo) $_plazos_data = $_pl_ok ? 'completado' : 'pendiente';
                    }
                    $marca = $estado_data ? (string)$estado_data->marca : '';
                    $notas_n = isset($_notas_count[$email]) ? $_notas_count[$email] : 0;
                    $color_bg_map = array('rojo'=>'#3b1820','amarillo'=>'#3a2e0c','verde'=>'#0e2d22','morado'=>'#251a3d','azul'=>'#0e1f3d');
                    $alt_bg = ($vc_row_idx % 2 === 0) ? '#16233c' : '#101c33';
                    $bg = vc_row_bg_from_colors($color_arr, $color_bg_map, $alt_bg);
                    $estado_label = '';
                    $estado_color = '#94a3b8';
                    $is_no_pago = !empty($m['no_pago']);
                    if ($is_no_pago) { $estado_label = '🎓 Acceso sin pago'; $estado_color = '#a855f7'; }
                    elseif ($m['has_reembolso'] && $m['importe_total'] < 0.01) { $estado_label = !empty($m['has_reembolso_real']) ? '🔁 Pago duplicado' : '↩ Devolución'; $estado_color = '#f43f5e'; }
                    elseif ($m['has_impago'] && $m['cuotas_pagadas'] === 0) { $estado_label = '⛔ Impago'; $estado_color = '#ef4444'; }
                    elseif ($m['has_impago']) { $estado_label = '⚠ Parcial'; $estado_color = '#f59e0b'; }
                    elseif ($m['cuotas_pagadas'] >= $m['cuotas_total']) { $estado_label = '✓ Pagado completo'; $estado_color = '#10b981'; }
                    else { $estado_label = '⏳ En curso (Plazos)'; $estado_color = '#06b6d4'; }
                    // Quien pagó dos veces pero se quedó con el curso mantiene su estado
                    // real (Pagado / En curso): se le añade el 🔁 para que se vea de dónde
                    // sale el número de la cajita "Pago duplicado".
                    if (!empty($m['has_reembolso_real']) && strpos($estado_label, '🔁') === false) {
                        $estado_label .= ' <span title="Tiene un pago duplicado devuelto" style="color:#fda4af;">🔁</span>';
                    }
                    $state_key = $is_no_pago ? 'nopago' : (($m['has_reembolso'] && $m['importe_total'] < 0.01) ? (!empty($m['has_reembolso_real']) ? 'reembolso' : 'devolucion') : (($m['has_impago'] && $m['cuotas_pagadas'] === 0) ? 'impago' : ($m['has_impago'] ? 'parcial' : ($m['cuotas_pagadas'] >= $m['cuotas_total'] ? 'pagado' : 'curso'))));
                    $search_blob = strtolower($email . ' ' . $atrib . ' ' . $changed . ' ' . $m['nombre'] . ' ' . $m['apellidos'] . ' ' . $m['producto'] . ' ' . $m['customer']);
                    $_at = isset($_attrib_map[$email]) ? $_attrib_map[$email] : array('source'=>'','medium'=>'','campaign'=>'','afiliado'=>0);
                    $_at_aff_id = intval($_at['afiliado'] ?? 0);
                    $_at_aff_label = $_at_aff_id > 0 ? (isset($_aff_names[$_at_aff_id]) ? $_aff_names[$_at_aff_id] : ('ID: ' . $_at_aff_id)) : 'Sin afiliado';
                ?>
                    <tr class="vc-user-row<?php echo $marca === 'test' ? ' vc-marca-test' : ($marca === 'error' ? ' vc-marca-error' : ''); ?>" data-email="<?php echo esc_attr($email); ?>"
                        data-color="<?php echo esc_attr($color); ?>"
                        data-atrib="<?php echo esc_attr($changed); ?>"
                        data-marca="<?php echo esc_attr($marca); ?>"
                        data-utipo="<?php echo esc_attr(isset($_pdc_user_tipo[$email]) ? $_pdc_user_tipo[$email] : ''); ?>"
                        data-acceso="<?php echo isset($_acceso_etiq[$email]) ? $_acceso_etiq[$email] : ''; ?>"
                        data-cats="<?php echo esc_attr(implode(' ', array_keys($m['cats'] ?? array()))); ?>"
                        data-prods="<?php echo esc_attr(implode('|', array_keys($m['prods'] ?? array()))); ?>"
                        data-tipo="<?php echo esc_attr($m['tipo'] ?? ''); ?>"
                        data-plataforma="<?php echo esc_attr($m['plataforma'] ?? ''); ?>"
                        data-estadopago="<?php echo esc_attr($_est_data); ?>"
                        data-alumnofecha="<?php echo $_alumno_fecha_data; ?>"
                        data-etiq="<?php echo esc_attr(isset($_etiq_por_email[$email]) ? implode(",", array_unique($_etiq_por_email[$email])) : ""); ?>"
                        data-plazos="<?php echo $_plazos_data; ?>"
                        data-asrc="<?php echo esc_attr($_at['source']); ?>"
                        data-amed="<?php echo esc_attr($_at['medium']); ?>"
                        data-acam="<?php echo esc_attr($_at['campaign']); ?>"
                        data-aaff="<?php echo esc_attr($_at_aff_label); ?>"
                        data-cuotastot="<?php echo (int)$m['cuotas_total']; ?>"
                        data-impago="<?php echo $m['has_impago'] ? '1':'0'; ?>"
                        data-reembolso="<?php echo $m['has_reembolso'] ? '1':'0'; ?>"
                        data-dup="<?php echo !empty($m['has_reembolso_real']) ? '1':'0'; ?>"
                        data-nopago="<?php echo $is_no_pago ? '1':'0'; ?>"
                        data-state="<?php echo $state_key; ?>"
                        data-importe="<?php echo esc_attr(number_format($m['importe_total'], 2, '.', '')); ?>"
                        data-search="<?php echo esc_attr($search_blob); ?>"
                        style="background:<?php echo $bg; ?>;">
                        <td style="padding:6px; border:1px solid rgba(255,255,255,.04); text-align:center;">
                            <button type="button" class="vc-toggle" data-target="vc-detail-<?php echo $vc_row_idx; ?>" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:14px;">▶</button>
                        </td>
                        <td style="padding:4px; border:1px solid rgba(255,255,255,.04); text-align:center; white-space:nowrap;">
                            <!-- Toggle múltiple: cada bolita se activa/desactiva de forma independiente,
                                 una fila puede llevar VARIOS colores a la vez (ej. rojo + verde). -->
                            <span class="vc-color-swatches" data-email="<?php echo esc_attr($email); ?>" style="display:inline-flex; gap:3px;">
                                <?php foreach (array('rojo'=>'#ef4444','amarillo'=>'#f59e0b','verde'=>'#10b981','morado'=>'#a855f7','azul'=>'#3b82f6') as $cname => $chex): $_active = in_array($cname, $color_arr, true); ?>
                                    <button type="button" class="vc-sw" data-color="<?php echo $cname; ?>" data-active="<?php echo $_active ? '1' : '0'; ?>" style="width:13px; height:13px; border-radius:50%; background:<?php echo $chex; ?>; border:<?php echo $_active ? '2px solid #fff' : '1px solid rgba(255,255,255,.2)'; ?>; box-shadow:<?php echo $_active ? '0 0 0 1.5px '.$chex : 'none'; ?>; cursor:pointer; padding:0;" title="<?php echo esc_attr($_color_labels[$cname] !== '' ? $_color_labels[$cname] : ucfirst($cname)); ?>"></button>
                                <?php endforeach; ?>
                                <button type="button" class="vc-sw vc-sw-clear" data-color="" style="width:13px; height:13px; border-radius:50%; background:transparent; border:1px solid rgba(255,255,255,.3); cursor:pointer; padding:0; color:#94a3b8; font-size:9px; line-height:1;" title="Quitar todos los colores">✕</button>
                            </span>
                        </td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); white-space:nowrap;"><?php echo esc_html($email); ?><?php if ($notas_n > 0): ?> <button type="button" class="vc-notes-btn vc-inline-note" data-email="<?php echo esc_attr($email); ?>" title="<?php echo (int)$notas_n; ?> nota(s)" style="background:none;border:none;padding:0 0 0 4px;cursor:pointer;font-size:12px;line-height:1;color:#fbbf24;vertical-align:middle;">📝<span style="font-size:9px;margin-left:1px;color:#fbbf24;"><?php echo (int)$notas_n; ?></span></button><?php endif; ?></td>
                        <td class="vc-changed-cell" style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); white-space:nowrap; color:#fcd34d;"><?php echo $changed !== '' ? esc_html($changed) : '<span style="color:#475569;">—</span>'; ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04);"><?php echo esc_html($m['nombre']); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04);"><?php echo esc_html($m['apellidos']); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr($m['producto']); ?>"><?php echo esc_html($m['producto']); ?></td>
                        <td style="padding:6px 6px; border:1px solid rgba(255,255,255,.04); white-space:nowrap;">
                            <?php
                            $_bcats = array_keys($m['cats'] ?? array());
                            $_cat_sty = array('principal'=>array('Principal','#6366f1'),'downsell'=>array('Downsell','#f59e0b'),'crosssell'=>array('Cross-sell','#06b6d4'));
                            if (empty($_bcats)) {
                                echo '<span style="color:#475569;">—</span>';
                            } else {
                                foreach ($_bcats as $_bc) {
                                    if (!isset($_cat_sty[$_bc])) continue;
                                    list($_bl, $_bcol) = $_cat_sty[$_bc];
                                    echo '<span style="display:inline-block; background:' . $_bcol . '22; border:1px solid ' . $_bcol . '; color:' . $_bcol . '; padding:1px 6px; border-radius:99px; font-size:9px; font-weight:700; margin:1px;">' . esc_html($_bl) . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); text-align:right; font-weight:700; color:#10b981;"><?php echo $is_no_pago ? '—' : number_format($m['importe_total'], 2, ',', '.'); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); text-align:center; color:var(--text-muted);"><?php echo $is_no_pago ? '—' : ($m['cuotas_pagadas'] . '/' . $m['cuotas_total']); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04);"><?php echo esc_html($m['tipo'] ?? ''); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04);"><?php echo esc_html($m['plataforma'] ?? ''); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); white-space:nowrap; font-size:10px;"><?php echo $_est_str !== '' ? esc_html($_est_str) : '—'; ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); color:<?php echo $estado_color; ?>; font-weight:600; white-space:nowrap;"><?php echo $estado_label; ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04);"><?php echo esc_html(strtoupper($m['pais'])); ?></td>
                        <td style="padding:6px 8px; border:1px solid rgba(255,255,255,.04); text-align:center; color:var(--text-muted); white-space:nowrap;"><?php echo ($is_no_pago || empty($m['fecha_first'])) ? '—' : esc_html(date('d/m/y H:i', strtotime($m['fecha_first']))); ?></td>
                        <?php
                            $_utipo = isset($_pdc_user_tipo[$email]) ? $_pdc_user_tipo[$email] : '';
                            if ($_utipo === 'alumno')        { $_ubg='rgba(34,197,94,.18)';  $_ubd='rgba(34,197,94,.4)';  $_uc='#86efac'; $_ul='🎓 Alumno'; }
                            elseif ($_utipo === 'noacceso')  { $_ubg='rgba(234,179,8,.18)';  $_ubd='rgba(234,179,8,.4)';  $_uc='#fde047'; $_ul='🔒 Sin acceso'; }
                            elseif ($_utipo === 'sin_rol')   { $_ubg='rgba(99,102,241,.12)'; $_ubd='rgba(99,102,241,.3)'; $_uc='#a5b4fc'; $_ul='⚪ Sin rol'; }
                            elseif ($_utipo !== '')          { $_ubg='rgba(239,68,68,.12)';  $_ubd='rgba(239,68,68,.3)';  $_uc='#fca5a5'; $_ul='⚠ '.ucfirst($_utipo); }
                            else                              { $_ubg='rgba(100,116,139,.12)'; $_ubd='rgba(100,116,139,.3)'; $_uc='#94a3b8'; $_ul='❓ No creado'; }
                        ?>
                        <?php $_acc = isset($_acceso_etiq[$email]) ? $_acceso_etiq[$email] : ''; ?>
                        <td style="padding:6px 6px; border:1px solid rgba(255,255,255,.04); text-align:center; white-space:nowrap;" data-utipo="<?php echo esc_attr($_utipo); ?>" title="Rol en el campus: <?php echo esc_attr($_utipo !== '' ? $_utipo : 'no creado'); ?>">
                            <span style="display:inline-block; background:<?php echo $_ubg; ?>; border:1px solid <?php echo $_ubd; ?>; color:<?php echo $_uc; ?>; padding:2px 8px; border-radius:99px; font-size:10px; font-weight:600;"><?php echo $_ul; ?></span><?php if ($_acc !== ''): ?>
                            <span style="display:inline-block; margin-left:4px; padding:2px 6px; border-radius:99px; font-size:10px; font-weight:700; background:<?php echo $_acc === '1' ? 'rgba(16,185,129,.18)' : 'rgba(244,63,94,.18)'; ?>; border:1px solid <?php echo $_acc === '1' ? 'rgba(16,185,129,.45)' : 'rgba(244,63,94,.45)'; ?>; color:<?php echo $_acc === '1' ? '#6ee7b7' : '#fda4af'; ?>;" title="<?php echo $_acc === '1' ? 'Tiene ' : 'NO tiene '; ?><?php echo esc_attr($_acceso_nombre); ?>"><?php echo $_acc === '1' ? '🔑' : '🚫'; ?></span><?php endif; ?>
                        </td>
                        <td style="padding:4px; border:1px solid rgba(255,255,255,.04); text-align:center; white-space:nowrap;">
                            <button type="button" class="vc-act vc-notes-btn" data-email="<?php echo esc_attr($email); ?>" title="Notas e historial" style="background:rgba(99,102,241,.18); border:1px solid rgba(99,102,241,.4); color:#a5b4fc; padding:3px 7px; border-radius:5px; cursor:pointer; font-size:11px;">
                                📝<?php if ($notas_n > 0) echo ' <span style="font-size:10px;">'.$notas_n.'</span>'; ?>
                            </button>
                            <button type="button" class="vc-act vc-stripe-btn" data-email="<?php echo esc_attr($email); ?>" data-customer="<?php echo esc_attr($m['customer']); ?>" data-subscription="<?php echo esc_attr($m['subscription']); ?>" title="Verificar con Stripe" style="background:rgba(99,91,253,.18); border:1px solid rgba(99,91,253,.4); color:#c7d2fe; padding:3px 7px; border-radius:5px; cursor:pointer; font-size:11px;">⚡</button>
                            <?php if ($marca === ''): ?>
                                <button type="button" class="vc-marca-btn" data-email="<?php echo esc_attr($email); ?>" data-marca="test"  title="Marcar como prueba"  style="background:transparent;border:1px solid rgba(168,85,247,.25);color:#a78bfa;padding:2px 5px;border-radius:4px;cursor:pointer;font-size:10px;opacity:.6;">🧪</button>
                                <button type="button" class="vc-marca-btn" data-email="<?php echo esc_attr($email); ?>" data-marca="error" title="Marcar como errónea" style="background:transparent;border:1px solid rgba(239,68,68,.25);color:#fca5a5;padding:2px 5px;border-radius:4px;cursor:pointer;font-size:10px;opacity:.6;">❌</button>
                            <?php else: ?>
                                <button type="button" class="vc-marca-btn" data-email="<?php echo esc_attr($email); ?>" data-marca="" title="Revertir marca (<?php echo esc_attr($marca); ?>)" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);color:#fde68a;padding:2px 6px;border-radius:4px;cursor:pointer;font-size:10px;">↺</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="vc-detail-rows" id="vc-detail-<?php echo $vc_row_idx; ?>" style="display:none;">
                        <td colspan="18" style="padding:0; border:1px solid rgba(99,102,241,.3); background:rgba(15,23,42,.7);">
                            <div style="padding:10px 18px;">
                                <?php $_et_row = isset($_etiq_por_email[$email]) ? array_unique($_etiq_por_email[$email]) : array(); ?>
                                <div style="display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin-bottom:8px; font-size:11px;">
                                    <span style="color:var(--text-muted); text-transform:uppercase; font-weight:700; font-size:10px; letter-spacing:.5px;">Etiquetas PDC:</span>
                                    <?php if (empty($_et_row)): ?>
                                        <span style="color:var(--text-muted);"><?php echo empty($m['pdc_usuario_id']) ? '— (sin usuario PDC vinculado)' : '— ninguna del lanzamiento'; ?></span>
                                    <?php else: foreach ($_et_row as $_tid): $_tn = isset($_etiq_catalogo[$_tid]['nombre']) ? $_etiq_catalogo[$_tid]['nombre'] : ('#' . $_tid); ?>
                                        <span style="background:rgba(99,102,241,.15); border:1px solid rgba(99,102,241,.35); color:#c4b5fd; padding:1px 8px; border-radius:99px;"><?php echo esc_html($_tn); ?></span>
                                    <?php endforeach; endif; ?>
                                </div>
                                <?php if ($is_no_pago): ?>
                                <div style="font-size:12px; color:#c4b5fd; padding:6px 2px;">🎓 Este usuario tiene acceso <strong>alumno</strong> en la plataforma (pdc_usermeta) pero <strong>no tiene ningún registro en pdc_pagos</strong>.</div>
                                <?php else: ?>
                                <div style="font-size:11px; color:#a5b4fc; margin-bottom:6px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;">Cuotas / cobros (<?php echo count($_users_idx[$email]); ?>)</div>
                                <div class="vc-detail-content" data-email="<?php echo esc_attr($email); ?>" data-loaded="0" style="font-size:12px; color:#94a3b8; padding:4px 0;">Los pagos se cargan al desplegar…</div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($_users_meta)): ?>
                    <tr><td colspan="18" style="text-align:center; padding:30px; color:var(--text-muted);">No hay registros en pdc_pagos para este lanzamiento (o faltan productos / credenciales).</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Barra de scroll horizontal "pegada" abajo del viewport mientras la tabla
             está en pantalla: sin esto, con ratón (sin trackpad/shift+rueda) solo se
             puede arrastrar el scroll horizontal bajando hasta el final de la tabla. -->
        <div id="vc-hscroll" style="position:sticky; bottom:0; z-index:15; overflow-x:auto; overflow-y:hidden; height:12px; margin-top:2px; background:rgba(15,23,42,.85); border:1px solid var(--card-border); border-top:none; border-radius:0 0 10px 10px; backdrop-filter:blur(6px);">
            <div id="vc-hscroll-inner" style="height:1px;"></div>
        </div>
    </div>

    <!-- Modal gestión de accesos -->
    <div id="vc-access-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.78); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#0f172a; border:1px solid rgba(245,158,11,.3); border-radius:14px; padding:24px 28px; width:min(640px,94vw); max-height:88vh; overflow-y:auto; position:relative; color:#f8fafc;">
            <button type="button" id="vc-access-close" style="position:absolute; top:12px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
            <h3 style="margin:0 0 4px; font-size:17px;"><i class="fas fa-user-shield" style="color:#fbbf24;"></i> Gestionar accesos</h3>
            <p style="margin:0 0 16px; color:#94a3b8; font-size:13px;">Da acceso a usuarios de WordPress por su email. <strong>Editor</strong>: puede marcar, notas, colores, etc. <strong>Visualización</strong>: solo lectura (sin botones de editar).</p>
            <div style="margin-bottom:14px;">
                <label style="font-size:10px; text-transform:uppercase; color:#94a3b8; font-weight:700; display:block; margin-bottom:4px;"><i class="fas fa-search" style="margin-right:5px;"></i> Buscar usuario (nombre o email)</label>
                <input type="search" id="vc-access-search" placeholder="Escribe al menos 2 letras…" autocomplete="off" style="width:100%; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:#fff; padding:9px 12px; border-radius:6px; font-size:13px;">
                <div id="vc-access-results" style="margin-top:8px;"></div>
            </div>
            <span id="vc-access-status" style="font-size:12px; color:#fbbf24;"></span>
            <h4 style="margin:14px 0 8px; color:#cbd5e1; font-size:12px; text-transform:uppercase; letter-spacing:.5px;">Con acceso</h4>
            <div id="vc-access-list" style="font-size:13px;"><div style="opacity:.6;">Cargando…</div></div>
        </div>
    </div>

    <!-- Modal de notas e historial -->
    <div id="vc-notes-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.78); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#0f172a; border:1px solid rgba(99,102,241,.3); border-radius:14px; padding:24px 28px; width:min(720px, 94vw); max-height:88vh; overflow-y:auto; position:relative; color:#f8fafc;">
            <button type="button" id="vc-notes-close" style="position:absolute; top:12px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
            <h3 style="margin:0 0 4px; font-size:17px;">📝 Notas e historial</h3>
            <p style="margin:0 0 16px; color:#94a3b8; font-size:13px;">Usuario: <strong id="vc-notes-email" style="color:#a5b4fc;"></strong></p>
            <div style="margin-bottom:18px;">
                <textarea id="vc-notes-input" rows="3" placeholder="Añadir nueva nota…" style="width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); color:#fff; padding:8px 10px; border-radius:6px; font-family:inherit; font-size:13px; resize:vertical;"></textarea>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
                    <span id="vc-notes-status" style="margin-right:auto; align-self:center; font-size:12px;"></span>
                    <button type="button" id="vc-notes-save" style="background:#4f46e5; color:#fff; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Guardar nota</button>
                </div>
            </div>
            <h4 style="margin:10px 0 8px; color:#cbd5e1; font-size:12px; text-transform:uppercase; letter-spacing:.5px;">Notas anteriores</h4>
            <div id="vc-notes-list" style="margin-bottom:18px;"></div>
            <h4 style="margin:16px 0 8px; color:#cbd5e1; font-size:12px; text-transform:uppercase; letter-spacing:.5px;">Historial de cambios</h4>
            <div id="vc-notes-log" style="font-size:12px;"></div>
        </div>
    </div>

    <!-- Modal de resultado Stripe -->
    <div id="vc-stripe-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.78); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#0f172a; border:1px solid rgba(99,91,253,.3); border-radius:14px; padding:24px 28px; width:min(560px, 92vw); max-height:80vh; overflow-y:auto; position:relative; color:#f8fafc;">
            <button type="button" id="vc-stripe-close" style="position:absolute; top:12px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
            <h3 style="margin:0 0 14px; font-size:17px;">⚡ Verificación Stripe</h3>
            <div id="vc-stripe-body" style="font-size:13px; line-height:1.6;"></div>
        </div>
    </div>

    <!-- Modal de confirmación al borrar un pago -->
    <div id="vc-delpago-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.78); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#0f172a; border:1px solid rgba(244,63,94,.35); border-radius:14px; padding:24px 28px; width:min(520px, 92vw); max-height:85vh; overflow-y:auto; position:relative; color:#f8fafc;">
            <button type="button" id="vc-delpago-close" style="position:absolute; top:12px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
            <h3 style="margin:0 0 4px; font-size:17px; color:#fda4af;">🗑️ Eliminar pago</h3>
            <p style="margin:0 0 14px; color:#94a3b8; font-size:13px;">Esta acción borra <strong>solo esta cuota/transacción</strong> (no la venta completa). Antes de borrarla se guarda una copia en la papelera de seguridad junto con tu usuario y el motivo, así que queda registro por si hay que consultarla o revertirla.</p>
            <div id="vc-delpago-info" style="background:rgba(244,63,94,.08); border:1px solid rgba(244,63,94,.25); border-radius:8px; padding:10px 12px; font-size:12px; font-family:monospace; margin-bottom:14px; color:#fecaca;"></div>
            <label style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; display:block; margin-bottom:6px;">Motivo del borrado (obligatorio)</label>
            <textarea id="vc-delpago-motivo" rows="3" placeholder="Ej: pago duplicado, la transferencia de Stripe tardó y se dio acceso manual…" style="width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); color:#fff; padding:8px 10px; border-radius:6px; font-family:inherit; font-size:13px; resize:vertical;"></textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <span id="vc-delpago-status" style="margin-right:auto; align-self:center; font-size:12px;"></span>
                <button type="button" id="vc-delpago-cancel" style="background:rgba(255,255,255,.08); color:#e2e8f0; border:1px solid rgba(255,255,255,.15); padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Cancelar</button>
                <button type="button" id="vc-delpago-confirm" style="background:#e11d48; color:#fff; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Eliminar pago</button>
            </div>
        </div>
    </div>

    <!-- Modal de devolución manual (Stripe bloqueado / devolución hecha fuera de Stripe) -->
    <div id="vc-refund-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.78); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#0f172a; border:1px solid rgba(245,158,11,.35); border-radius:14px; padding:24px 28px; width:min(520px, 92vw); max-height:85vh; overflow-y:auto; position:relative; color:#f8fafc;">
            <button type="button" id="vc-refund-close" style="position:absolute; top:12px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
            <h3 style="margin:0 0 4px; font-size:17px; color:#fde68a;">↩️ Añadir devolución manual</h3>
            <p style="margin:0 0 14px; color:#94a3b8; font-size:13px;">Crea una <strong>línea nueva</strong> en pdc_pagos, en negativo, vinculada a este pago (útil cuando Stripe está bloqueado y la devolución se hace fuera de Stripe). Si el importe cubre el total, el saldo del comprador queda a 0 y se cuenta como devolución.</p>
            <div id="vc-refund-info" style="background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.25); border-radius:8px; padding:10px 12px; font-size:12px; font-family:monospace; margin-bottom:14px; color:#fde68a;"></div>
            <label style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; display:block; margin-bottom:6px;">Importe a devolver (negativo)</label>
            <input type="number" id="vc-refund-importe" step="0.01" max="-0.01" style="width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); color:#fff; padding:8px 10px; border-radius:6px; font-family:inherit; font-size:13px; margin-bottom:14px;">
            <label style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; display:block; margin-bottom:6px;">Motivo (obligatorio)</label>
            <textarea id="vc-refund-motivo" rows="3" placeholder="Ej: Stripe bloqueado, devolución hecha manualmente por transferencia…" style="width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); color:#fff; padding:8px 10px; border-radius:6px; font-family:inherit; font-size:13px; resize:vertical;"></textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <span id="vc-refund-status" style="margin-right:auto; align-self:center; font-size:12px;"></span>
                <button type="button" id="vc-refund-cancel" style="background:rgba(255,255,255,.08); color:#e2e8f0; border:1px solid rgba(255,255,255,.15); padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Cancelar</button>
                <button type="button" id="vc-refund-confirm" style="background:#f59e0b; color:#1a1200; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700;">Guardar devolución</button>
            </div>
        </div>
    </div>

    <!-- Modal de edición de importe (SOLO pagos manuales) -->
    <div id="vc-editimporte-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.78); z-index:99999; align-items:center; justify-content:center;">
        <div style="background:#0f172a; border:1px solid rgba(59,130,246,.35); border-radius:14px; padding:24px 28px; width:min(420px, 92vw); position:relative; color:#f8fafc;">
            <button type="button" id="vc-editimporte-close" style="position:absolute; top:12px; right:18px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">✕</button>
            <h3 style="margin:0 0 4px; font-size:17px; color:#93c5fd;">✏️ Editar importe (pago manual)</h3>
            <p style="margin:0 0 14px; color:#94a3b8; font-size:13px;">Solo se puede editar el importe de pagos <strong>manuales</strong>. El cambio queda registrado en el log.</p>
            <div id="vc-editimporte-info" style="font-size:12px; font-family:monospace; color:#93c5fd; margin-bottom:10px;"></div>
            <label style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; display:block; margin-bottom:6px;">Nuevo importe (€)</label>
            <input type="number" id="vc-editimporte-input" step="0.01" style="width:100%; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.1); color:#fff; padding:8px 10px; border-radius:6px; font-family:inherit; font-size:13px; margin-bottom:6px;">
            <p style="margin:0 0 14px; color:#64748b; font-size:11px; line-height:1.45;">
                <strong style="color:#86efac;">Positivo</strong> = cobro real (venta manual: transferencia, Bizum, efectivo…) ·
                <strong style="color:#fda4af;">negativo</strong> = devolución ·
                <strong style="color:#94a3b8;">0</strong> = acceso dado sin cobro.
            </p>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <span id="vc-editimporte-status" style="margin-right:auto; align-self:center; font-size:12px;"></span>
                <button type="button" id="vc-editimporte-cancel" style="background:rgba(255,255,255,.08); color:#e2e8f0; border:1px solid rgba(255,255,255,.15); padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:600;">Cancelar</button>
                <button type="button" id="vc-editimporte-confirm" style="background:#3b82f6; color:#fff; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700;">Guardar</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const tbody = document.getElementById('vc-tbody');
        if (!tbody) return;
        const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const NONCE = '<?php echo wp_create_nonce('endtrack_pdc_nonce'); ?>';
        const LAUNCH = <?php echo json_encode($active_launch); ?>;
        // true = el lanzamiento tiene etiqueta de acceso, así que la cajita
        // "Sin acceso" se cuenta por etiqueta (data-acceso) y no por rol.
        const ACC_POR_ETIQUETA = <?php echo !empty($_etiq_acceso_ids) ? 'true' : 'false'; ?>;
        const CURRENT_UID = <?php echo (int) get_current_user_id(); ?>;
        const DSTART = <?php echo json_encode($filter_date_start); ?>;
        const DEND   = <?php echo json_encode($filter_date_end); ?>;
        const userRows = Array.from(tbody.querySelectorAll('tr.vc-user-row'));

        // ── Scroll horizontal accesible con ratón (barra sticky abajo del viewport) ──
        (function () {
            const wrap = document.querySelector('.vc-table-wrap');
            const table = document.getElementById('vc-detalle-table');
            const hbar = document.getElementById('vc-hscroll');
            const hinner = document.getElementById('vc-hscroll-inner');
            if (!wrap || !table || !hbar || !hinner) return;
            function syncWidth() { hinner.style.width = table.scrollWidth + 'px'; }
            syncWidth();
            window.addEventListener('resize', syncWidth);
            if (window.ResizeObserver) new ResizeObserver(syncWidth).observe(table);
            let syncing = false;
            wrap.addEventListener('scroll', () => { if (syncing) return; syncing = true; hbar.scrollLeft = wrap.scrollLeft; syncing = false; });
            hbar.addEventListener('scroll', () => { if (syncing) return; syncing = true; wrap.scrollLeft = hbar.scrollLeft; syncing = false; });
        })();
        // Reordenar columnas: Acciones primero + Email, ambas fijas (sticky) en scroll horizontal
        (function(){
            function fz(tr){
                const c = tr.children;
                if (c.length < 4) return; // saltar filas de detalle (colspan)
                const acc = c[c.length - 1];  // Acciones (última)
                const mail = c[2];            // Email compra
                acc.classList.add('vc-fz-act');
                mail.classList.add('vc-fz-mail');
                tr.insertBefore(acc, c[0]);
                tr.insertBefore(mail, acc.nextSibling);
            }
            const head = document.querySelector('#vc-detalle-table thead tr');
            if (head) fz(head);
            userRows.forEach(fz);
        })();

        // Filtros sticky compactos: al pasar el scroll del panel, se fija arriba
        // y se reduce (solo quedan los filtros de la tabla, en pequeño).
        // Un espaciador compensa la altura que pierde el panel al compactarse para
        // que el documento no cambie de alto (evita saltos/parpadeo en el umbral),
        // y la histéresis separa el punto de entrada y el de salida.
        (function(){
            const panel = document.getElementById('vc-filters-panel');
            if (!panel) return;
            const spacer = document.createElement('div');
            spacer.style.height = '0px';
            panel.parentNode.insertBefore(spacer, panel.nextSibling);
            let compact = false;
            let ticking = false;
            function setCompact(c){
                if (c === compact) return;
                compact = c;
                if (c) {
                    const h1 = panel.offsetHeight;
                    document.body.classList.add('vc-compact');
                    const h2 = panel.offsetHeight;
                    spacer.style.height = Math.max(0, h1 - h2) + 'px';
                } else {
                    document.body.classList.remove('vc-compact');
                    spacer.style.height = '0px';
                }
            }
            function onScroll(){
                ticking = false;
                // Se compacta con muy poco scroll; histéresis para no parpadear en el umbral.
                if (!compact && window.scrollY > 40) setCompact(true);
                else if (compact && window.scrollY < 10) setCompact(false);
            }
            window.addEventListener('scroll', function(){
                if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
            }, { passive: true });
            onScroll();
        })();

        // ── Detalle de pagos bajo demanda (AJAX al desplegar) ──
        function vcEsc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
        function vcLoadDetail(target){
            const box = target.querySelector('.vc-detail-content');
            if (!box || box.dataset.loaded === '1' || box.dataset.loading === '1') return;
            box.dataset.loading = '1';
            box.innerHTML = '<span style="opacity:.7">Cargando pagos…</span>';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_get_pdc_pagos', nonce: NONCE, launch: LAUNCH, email: box.dataset.email, d_start: DSTART, d_end: DEND }) })
              .then(r => r.json()).then(j => {
                box.dataset.loading = '0';
                if (!j.success) { box.innerHTML = '<span style="color:#ef4444">Error: ' + vcEsc(j.data || '') + '</span>'; return; }
                box.dataset.loaded = '1';
                const rows = j.data.pagos || [];
                if (!rows.length) { box.innerHTML = '<span style="opacity:.7">Sin pagos en el rango de fechas.</span>'; return; }
                const TH = 'padding:5px 8px; border:1px solid rgba(255,255,255,.05); color:var(--text-muted); font-size:10px; text-transform:uppercase; text-align:left;';
                const TD = 'padding:4px 8px; border:1px solid rgba(255,255,255,.04);';
                let h = '<table style="width:100%; border-collapse:collapse; font-size:11px; font-family:inherit;"><thead><tr style="background:rgba(99,102,241,.15);">'
                    + ['ID','ID Producto','Fecha','Importe','Estado','Tipo','Cuota','Método','Customer','Subscription','Invoice'].map(x => `<th style="${TH}">${x}</th>`).join('')
                    + '</tr></thead><tbody>';
                rows.forEach(p => {
                    const est = p.estado || '';
                    const col = (est === 'Completado' || est === 'Compra') ? '#10b981' : (est === 'Reembolso' ? '#f43f5e' : (est === 'Fallido' ? '#ef4444' : '#94a3b8'));
                    const imp = Number(p.importe || 0).toLocaleString('es-ES', {minimumFractionDigits:2, maximumFractionDigits:2});
                    const m = String(p.fecha || '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}:\d{2})/);
                    const fecha = m ? (m[3] + '/' + m[2] + '/' + m[1].slice(2) + ' ' + m[4]) : String(p.fecha || '');
                    h += '<tr style="background:rgba(255,255,255,.02);">'
                      + `<td style="${TD} color:var(--text-muted); font-family:monospace;">${vcEsc(p.ID)}</td>`
                      + `<td style="${TD} color:#a5b4fc; font-family:monospace; font-size:10px; white-space:nowrap;" title="${vcEsc(p.producto)}">${vcEsc(p.ID_producto)}</td>`
                      + `<td style="${TD} color:var(--text-muted); white-space:nowrap;">${vcEsc(fecha)}</td>`
                      + `<td style="${TD} text-align:right; color:#fff; font-weight:600;">${imp} €`
                      + (String(p.metodo).toLowerCase() === 'manual'
                          ? `<button type="button" class="vc-act vc-pago-editimporte-btn" data-id="${vcEsc(p.ID)}" data-email="${vcEsc(box.dataset.email)}" data-importe-raw="${vcEsc(p.importe)}" title="Editar importe (solo pagos manuales)" style="background:none; border:1px solid rgba(59,130,246,.4); color:#93c5fd; padding:0px 5px; border-radius:4px; cursor:pointer; font-size:9px; margin-left:5px;">✏️</button>`
                          : '')
                      + `</td>`
                      + (est === 'Reembolso'
                          ? `<td style="${TD}"><select class="vc-act vc-reembolso-sel" data-id="${vcEsc(p.ID)}" data-email="${vcEsc(box.dataset.email)}" title="Elige qué fue este dinero devuelto. Solo la primera opción cuenta en el KPI de Devoluciones." style="background:#0f1c33; color:${col}; font-weight:600; font-family:inherit; font-size:11px; border:1px solid rgba(244,63,94,.4); border-radius:5px; padding:2px 4px; cursor:pointer;">`
                            + `<option value="0" ${String(p.reembolso) === '1' ? '' : 'selected'}>↩ Devolución — se arrepintió y se le devolvió</option>`
                            + `<option value="1" ${String(p.reembolso) === '1' ? 'selected' : ''}>🔁 Pago duplicado — no cuenta como devolución</option>`
                            + `</select></td>`
                          : `<td style="${TD} color:${col}; font-weight:600;">${vcEsc(est)}</td>`)
                      + `<td style="${TD}">${vcEsc(p.tipo)}</td>`
                      + `<td style="${TD} text-align:center; color:var(--text-muted);">${vcEsc(p.cuota)}/${vcEsc(p.cuotas)}</td>`
                      + `<td style="${TD} color:var(--text-muted);">${vcEsc(p.metodo)}</td>`
                      + `<td style="${TD} color:var(--text-muted); font-family:monospace; font-size:10px;">${vcEsc(p.customer)}</td>`
                      + `<td style="${TD} color:var(--text-muted); font-family:monospace; font-size:10px;">${vcEsc(p.subscription_id)}</td>`
                      + `<td style="${TD} color:var(--text-muted); font-family:monospace; font-size:10px;">${vcEsc(p.invoice_id)}</td>`
                      + `<td style="${TD} text-align:center; white-space:nowrap;">`
                      + (String(p.metodo).toLowerCase() === 'manual'
                          ? `<button type="button" class="vc-act vc-pago-del-btn" data-id="${vcEsc(p.ID)}" data-email="${vcEsc(box.dataset.email)}" data-importe="${imp}" data-fecha="${vcEsc(fecha)}" data-estado="${vcEsc(est)}" title="Eliminar este pago manual" style="background:rgba(244,63,94,.15); border:1px solid rgba(244,63,94,.4); color:#fda4af; padding:3px 7px; border-radius:5px; cursor:pointer; font-size:11px;">🗑️</button>`
                          : '')
                      + ((est === 'Completado' || est === 'Compra')
                          ? `<button type="button" class="vc-act vc-pago-refund-btn" data-id="${vcEsc(p.ID)}" data-email="${vcEsc(box.dataset.email)}" data-importe="${imp}" data-importe-raw="${vcEsc(p.importe)}" data-fecha="${vcEsc(fecha)}" data-producto="${vcEsc(p.producto)}" title="Añadir devolución manual de este pago" style="background:rgba(245,158,11,.15); border:1px solid rgba(245,158,11,.4); color:#fde68a; padding:3px 7px; border-radius:5px; cursor:pointer; font-size:11px; margin-left:4px;">↩️</button>`
                          : '')
                      + `</td>`
                      + '</tr>';
                });
                h += '</tbody></table>';
                box.innerHTML = h;
              }).catch(() => { box.dataset.loading = '0'; box.innerHTML = '<span style="color:#ef4444">Error de red.</span>'; });
        }
        tbody.addEventListener('click', e => {
            const t = e.target.closest('.vc-toggle');
            if (!t) return;
            const target = document.getElementById(t.dataset.target);
            if (!target) return;
            if (target.style.display === 'none') { target.style.display = ''; t.textContent = '▼'; vcLoadDetail(target); }
            else { target.style.display = 'none'; t.textContent = '▶'; }
        });

        // ── Borrar un pago concreto (una cuota/transacción, no la venta entera) ──
        const $delModal   = document.getElementById('vc-delpago-modal');
        const $delClose   = document.getElementById('vc-delpago-close');
        const $delCancel  = document.getElementById('vc-delpago-cancel');
        const $delConfirm = document.getElementById('vc-delpago-confirm');
        const $delInfo    = document.getElementById('vc-delpago-info');
        const $delMotivo  = document.getElementById('vc-delpago-motivo');
        const $delStatus  = document.getElementById('vc-delpago-status');
        let delPago = null; // { id, email, btn }
        function vcOpenDelPago(btn) {
            delPago = { id: btn.dataset.id, email: btn.dataset.email, btn };
            $delInfo.textContent = `Pago #${btn.dataset.id} · ${btn.dataset.importe} € · ${btn.dataset.fecha} · ${btn.dataset.estado} · ${btn.dataset.email}`;
            $delMotivo.value = '';
            $delStatus.textContent = '';
            $delModal.style.display = 'flex';
            $delMotivo.focus();
        }
        function vcCloseDelPago() { $delModal.style.display = 'none'; delPago = null; }
        $delClose.addEventListener('click', vcCloseDelPago);
        $delCancel.addEventListener('click', vcCloseDelPago);
        $delModal.addEventListener('click', e => { if (e.target === $delModal) vcCloseDelPago(); });
        tbody.addEventListener('click', e => {
            const btn = e.target.closest('.vc-pago-del-btn');
            if (!btn) return;
            vcOpenDelPago(btn);
        });
        $delConfirm.addEventListener('click', () => {
            if (!delPago) return;
            const motivo = $delMotivo.value.trim();
            if (!motivo) {
                $delStatus.textContent = 'Indica un motivo antes de borrar.';
                $delStatus.style.color = '#f43f5e';
                return;
            }
            if (!confirm('¿Seguro que quieres eliminar este pago? Se guardará una copia en la papelera de seguridad, pero se borrará de pdc_pagos.')) return;
            $delConfirm.disabled = true;
            $delStatus.textContent = 'Eliminando…';
            $delStatus.style.color = '#94a3b8';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_delete_pdc_pago', nonce: NONCE, launch: LAUNCH, email: delPago.email, pago_id: delPago.id, motivo }) })
              .then(r => r.json()).then(j => {
                $delConfirm.disabled = false;
                if (!j.success) {
                    $delStatus.textContent = 'Error: ' + (j.data || '');
                    $delStatus.style.color = '#f43f5e';
                    return;
                }
                vcCloseDelPago();
                // Recarga con ?refresh=1: invalida la caché de firma y recalcula
                // totales/cuotas del usuario ya sin el pago borrado.
                const u = new URL(window.location.href);
                u.searchParams.set('refresh', '1');
                window.location.href = u.toString();
              }).catch(() => {
                $delConfirm.disabled = false;
                $delStatus.textContent = 'Error de red.';
                $delStatus.style.color = '#f43f5e';
              });
        });

        // ── Añadir devolución manual (Stripe bloqueado, devolución fuera de Stripe) ──
        const $refModal   = document.getElementById('vc-refund-modal');
        const $refClose   = document.getElementById('vc-refund-close');
        const $refCancel  = document.getElementById('vc-refund-cancel');
        const $refConfirm = document.getElementById('vc-refund-confirm');
        const $refInfo    = document.getElementById('vc-refund-info');
        const $refImporte = document.getElementById('vc-refund-importe');
        const $refMotivo  = document.getElementById('vc-refund-motivo');
        const $refStatus  = document.getElementById('vc-refund-status');
        let refPago = null; // { id, email }
        function vcOpenRefund(btn) {
            refPago = { id: btn.dataset.id, email: btn.dataset.email };
            $refInfo.textContent = `Pago original #${btn.dataset.id} · ${btn.dataset.producto} · ${btn.dataset.importe} € · ${btn.dataset.fecha} · ${btn.dataset.email}`;
            const raw = parseFloat(String(btn.dataset.importeRaw).replace(',', '.')) || 0;
            $refImporte.value = (-Math.abs(raw)).toFixed(2);
            $refMotivo.value = '';
            $refStatus.textContent = '';
            $refModal.style.display = 'flex';
            $refMotivo.focus();
        }
        function vcCloseRefund() { $refModal.style.display = 'none'; refPago = null; }
        $refClose.addEventListener('click', vcCloseRefund);
        $refCancel.addEventListener('click', vcCloseRefund);
        $refModal.addEventListener('click', e => { if (e.target === $refModal) vcCloseRefund(); });
        tbody.addEventListener('click', e => {
            const btn = e.target.closest('.vc-pago-refund-btn');
            if (!btn) return;
            vcOpenRefund(btn);
        });
        $refConfirm.addEventListener('click', () => {
            if (!refPago) return;
            const importe = parseFloat(String($refImporte.value).replace(',', '.'));
            const motivo = $refMotivo.value.trim();
            if (!motivo) {
                $refStatus.textContent = 'Indica un motivo antes de guardar.';
                $refStatus.style.color = '#f43f5e';
                return;
            }
            if (isNaN(importe) || !(importe < 0)) {
                $refStatus.textContent = 'El importe debe ser un número negativo.';
                $refStatus.style.color = '#f43f5e';
                return;
            }
            if (!confirm('¿Añadir esta devolución manual? Se creará un nuevo pago en negativo (estado Reembolso) vinculado a este.')) return;
            $refConfirm.disabled = true;
            $refStatus.textContent = 'Guardando…';
            $refStatus.style.color = '#94a3b8';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_add_pdc_refund', nonce: NONCE, launch: LAUNCH, email: refPago.email, base_pago_id: refPago.id, importe, motivo }) })
              .then(r => r.json()).then(j => {
                $refConfirm.disabled = false;
                if (!j.success) {
                    $refStatus.textContent = 'Error: ' + (j.data || '');
                    $refStatus.style.color = '#f43f5e';
                    return;
                }
                vcCloseRefund();
                // Recarga con ?refresh=1: invalida la caché de firma y recalcula
                // totales/cuotas del usuario con la devolución ya incluida.
                const u = new URL(window.location.href);
                u.searchParams.set('refresh', '1');
                window.location.href = u.toString();
              }).catch(() => {
                $refConfirm.disabled = false;
                $refStatus.textContent = 'Error de red.';
                $refStatus.style.color = '#f43f5e';
              });
        });

        // ── Editar importe de un pago manual (solo metodo=Manual) ──
        const $eiModal   = document.getElementById('vc-editimporte-modal');
        const $eiClose   = document.getElementById('vc-editimporte-close');
        const $eiCancel  = document.getElementById('vc-editimporte-cancel');
        const $eiConfirm = document.getElementById('vc-editimporte-confirm');
        const $eiInfo    = document.getElementById('vc-editimporte-info');
        const $eiInput   = document.getElementById('vc-editimporte-input');
        const $eiStatus  = document.getElementById('vc-editimporte-status');
        let eiPago = null; // { id, email }
        function vcOpenEditImporte(btn) {
            eiPago = { id: btn.dataset.id, email: btn.dataset.email };
            $eiInfo.textContent = `Pago manual #${btn.dataset.id} · ${btn.dataset.email}`;
            const raw = parseFloat(String(btn.dataset.importeRaw).replace(',', '.')) || 0;
            $eiInput.value = raw.toFixed(2);
            $eiStatus.textContent = '';
            $eiModal.style.display = 'flex';
            $eiInput.focus();
        }
        function vcCloseEditImporte() { $eiModal.style.display = 'none'; eiPago = null; }
        $eiClose.addEventListener('click', vcCloseEditImporte);
        $eiCancel.addEventListener('click', vcCloseEditImporte);
        $eiModal.addEventListener('click', e => { if (e.target === $eiModal) vcCloseEditImporte(); });
        tbody.addEventListener('click', e => {
            const btn = e.target.closest('.vc-pago-editimporte-btn');
            if (!btn) return;
            vcOpenEditImporte(btn);
        });
        $eiConfirm.addEventListener('click', () => {
            if (!eiPago) return;
            // Se admite POSITIVO (venta manual cobrada por transferencia/Bizum),
            // negativo (devolución) y 0 (acceso sin cobro). Solo se rechaza el
            // campo vacío o un texto que no sea número: floatval('') sería 0 y
            // borraría el importe sin querer.
            const bruto = String($eiInput.value).trim().replace(',', '.');
            const importe = parseFloat(bruto);
            if (bruto === '' || isNaN(importe)) {
                $eiStatus.textContent = 'Escribe un importe válido (ej. 200 o -200).';
                $eiStatus.style.color = '#f43f5e';
                return;
            }
            const eiTxt = importe.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            if (!confirm('¿Actualizar el importe de este pago manual a ' + eiTxt + '?')) return;
            $eiConfirm.disabled = true;
            $eiStatus.textContent = 'Guardando…';
            $eiStatus.style.color = '#94a3b8';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_edit_pdc_manual_importe', nonce: NONCE, launch: LAUNCH, email: eiPago.email, pago_id: eiPago.id, importe }) })
              .then(r => r.json()).then(j => {
                $eiConfirm.disabled = false;
                if (!j.success) {
                    $eiStatus.textContent = 'Error: ' + (j.data || '');
                    $eiStatus.style.color = '#f43f5e';
                    return;
                }
                vcCloseEditImporte();
                const u = new URL(window.location.href);
                u.searchParams.set('refresh', '1');
                window.location.href = u.toString();
              }).catch(() => {
                $eiConfirm.disabled = false;
                $eiStatus.textContent = 'Error de red.';
                $eiStatus.style.color = '#f43f5e';
              });
        });

        // ── Clasificar una fila Reembolso: "Devolución" (normal, cuenta en el KPI)
        //    o "Pago duplicado" (compró dos veces / con otro correo y se le
        //    devolvió lo repetido: no es una devolución de verdad). No toca ni el
        //    estado ni el importe, solo la etiqueta. ──
        tbody.addEventListener('change', e => {
            const sel = e.target.closest('.vc-reembolso-sel');
            if (!sel) return;
            const flag = sel.value === '1' ? 1 : 0;
            const prev = flag === 1 ? '0' : '1';
            sel.disabled = true;
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_set_pdc_reembolso_flag', nonce: NONCE, launch: LAUNCH, email: sel.dataset.email, pago_id: sel.dataset.id, reembolso: flag }) })
              .then(r => r.json()).then(j => {
                sel.disabled = false;
                if (!j.success) {
                    alert('Error: ' + (j.data || ''));
                    sel.value = prev;
                    return;
                }
                // Recarga para recalcular el estado agregado del comprador (arriba en la tabla principal).
                const u = new URL(window.location.href);
                u.searchParams.set('refresh', '1');
                window.location.href = u.toString();
              }).catch(() => {
                sel.disabled = false;
                sel.value = prev;
                alert('Error de red.');
              });
        });

        // Paleta y orden fijo — igual criterio que el whitelist PHP (class-endtrack-ajax.php)
        const VC_COLOR_ORDER = ['rojo', 'amarillo', 'verde', 'morado', 'azul'];
        const VC_COLOR_HEX   = { rojo:'#ef4444', amarillo:'#f59e0b', verde:'#10b981', morado:'#a855f7', azul:'#3b82f6' };
        const VC_COLOR_BG    = { rojo:'#3b1820', amarillo:'#3a2e0c', verde:'#0e2d22', morado:'#251a3d', azul:'#0e1f3d' };
        function vcRowBgFromColors(colors) {
            if (colors.length === 0) return 'transparent';
            if (colors.length === 1) return VC_COLOR_BG[colors[0]];
            const n = colors.length;
            const stops = [];
            colors.forEach((c, i) => {
                const start = (i * 100 / n).toFixed(2), end = ((i + 1) * 100 / n).toFixed(2);
                stops.push(`${VC_COLOR_BG[c]} ${start}%`, `${VC_COLOR_BG[c]} ${end}%`);
            });
            return `linear-gradient(90deg, ${stops.join(', ')})`;
        }
        tbody.addEventListener('click', e => {
            const sw = e.target.closest('.vc-sw');
            if (!sw) return;
            const wrap = sw.closest('.vc-color-swatches');
            const email = wrap.dataset.email;
            const row = sw.closest('tr');
            // Toggle múltiple: la fila puede llevar varios colores a la vez.
            // El botón "✕" (data-color vacío) los quita todos de golpe.
            let current = (row.dataset.color || '').split(',').filter(Boolean);
            if (sw.dataset.color === '') {
                current = [];
            } else {
                const c = sw.dataset.color;
                current = current.includes(c) ? current.filter(x => x !== c) : current.concat([c]);
                current = VC_COLOR_ORDER.filter(p => current.includes(p));
            }
            wrap.querySelectorAll('.vc-sw:not(.vc-sw-clear)').forEach(b => {
                const active = current.includes(b.dataset.color);
                b.dataset.active = active ? '1' : '0';
                b.style.border = active ? '2px solid #fff' : '1px solid rgba(255,255,255,.2)';
                b.style.boxShadow = active ? ('0 0 0 1.5px ' + VC_COLOR_HEX[b.dataset.color]) : 'none';
            });
            const color = current.join(',');
            row.dataset.color = color;
            row.style.background = vcRowBgFromColors(current);
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action: 'endtrack_save_pdc_color', nonce: NONCE, launch: LAUNCH, email, color }) });
        });

        tbody.addEventListener('blur', e => {
            const inp = e.target.closest('.vc-atrib-input');
            if (!inp) return;
            const email = inp.dataset.email;
            const row = inp.closest('tr');
            const val = inp.value.trim();
            row.dataset.atrib = val;
            row.dataset.search = (row.dataset.search || '') + ' ' + val.toLowerCase();
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action: 'endtrack_save_pdc_atribucion', nonce: NONCE, launch: LAUNCH, email, atribucion: val }) });
        }, true);

        const $nModal = document.getElementById('vc-notes-modal');
        const $nClose = document.getElementById('vc-notes-close');
        const $nEmail = document.getElementById('vc-notes-email');
        const $nInput = document.getElementById('vc-notes-input');
        const $nSave  = document.getElementById('vc-notes-save');
        const $nStatus = document.getElementById('vc-notes-status');
        const $nList = document.getElementById('vc-notes-list');
        const $nLog  = document.getElementById('vc-notes-log');
        let currentEmail = '';

        function loadUserData(email) {
            currentEmail = email; $nEmail.textContent = email;
            $nList.innerHTML = '<div style="opacity:.6">Cargando…</div>'; $nLog.innerHTML = '';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action: 'endtrack_get_pdc_user_data', nonce: NONCE, launch: LAUNCH, email }) })
              .then(r => r.json()).then(j => {
                if (!j.success) { $nList.innerHTML = '<div style="color:#ef4444">Error: '+(j.data||'')+'</div>'; return; }
                const notas = j.data.notas || [];
                const log = j.data.log || [];
                $nList.innerHTML = notas.length ? notas.map(n => {
                    const canEdit = String(n.autor_id) === String(CURRENT_UID);
                    const editBtn = canEdit ? `<button type="button" class="vc-note-edit-btn" data-id="${n.id}" style="background:none; border:none; color:#93c5fd; cursor:pointer; font-size:11px; padding:0;">✏️ editar</button>` : '';
                    return `<div class="vc-note-item" data-id="${n.id}" style="background:rgba(255,255,255,.04); padding:10px 12px; border-radius:6px; margin-bottom:8px;">`
                        + `<div style="font-size:11px; color:#94a3b8; margin-bottom:4px; display:flex; justify-content:space-between; align-items:center;"><span>${n.fecha} · ${n.autor || ''}</span>${editBtn}</div>`
                        + `<div class="vc-note-text" style="white-space:pre-wrap;">${vcEsc(n.nota)}</div>`
                        + `</div>`;
                }).join('') : '<div style="opacity:.6">Sin notas aún.</div>';
                $nLog.innerHTML = log.length ? log.map(l => `<div style="border-bottom:1px solid rgba(255,255,255,.05); padding:5px 0; color:#cbd5e1;"><span style="color:#94a3b8;">${l.fecha}</span> <strong style="color:#a5b4fc;">${l.accion}</strong> <span style="color:#94a3b8;">por</span> ${l.autor || ''} <span style="color:#64748b; font-family:monospace; font-size:11px;">${(l.valor||'').slice(0,140)}</span></div>`).join('') : '<div style="opacity:.6">Sin historial.</div>';
              });
        }
        tbody.addEventListener('click', e => {
            const btn = e.target.closest('.vc-notes-btn'); if (!btn) return;
            $nInput.value = ''; $nStatus.textContent = ''; $nModal.style.display = 'flex'; loadUserData(btn.dataset.email);
        });
        $nClose.addEventListener('click', () => $nModal.style.display = 'none');
        $nModal.addEventListener('click', e => { if (e.target === $nModal) $nModal.style.display = 'none'; });
        $nSave.addEventListener('click', () => {
            const nota = $nInput.value.trim(); if (!nota) return;
            $nSave.disabled = true; $nStatus.textContent = 'Guardando…';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action: 'endtrack_save_pdc_note', nonce: NONCE, launch: LAUNCH, email: currentEmail, nota }) })
              .then(r => r.json()).then(j => {
                $nSave.disabled = false;
                if (j.success) { $nInput.value = ''; $nStatus.textContent = '✓'; loadUserData(currentEmail);
                    const row = tbody.querySelector(`.vc-user-row[data-email="${currentEmail}"]`);
                    if (row) { const btn = row.querySelector('.vc-notes-btn'); const cur = parseInt((btn.textContent.match(/\d+/) || ['0'])[0]) + 1; btn.innerHTML = `📝 <span style="font-size:10px;">${cur}</span>`; }
                } else { $nStatus.textContent = 'Error: ' + (j.data || ''); }
              });
        });

        $nList.addEventListener('click', e => {
            const btn = e.target.closest('.vc-note-edit-btn');
            if (!btn) return;
            const item = btn.closest('.vc-note-item');
            const textDiv = item.querySelector('.vc-note-text');
            const original = textDiv.textContent;
            const ta = document.createElement('textarea');
            ta.value = original;
            ta.rows = 3;
            ta.style.cssText = 'width:100%; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.15); color:#fff; padding:6px 8px; border-radius:6px; font-family:inherit; font-size:13px; resize:vertical;';
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button'; saveBtn.textContent = 'Guardar';
            saveBtn.style.cssText = 'background:#4f46e5; color:#fff; border:none; padding:5px 12px; border-radius:6px; cursor:pointer; font-size:11px; margin-top:6px; margin-right:6px;';
            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button'; cancelBtn.textContent = 'Cancelar';
            cancelBtn.style.cssText = 'background:rgba(255,255,255,.08); color:#e2e8f0; border:1px solid rgba(255,255,255,.15); padding:5px 12px; border-radius:6px; cursor:pointer; font-size:11px; margin-top:6px;';
            textDiv.replaceWith(ta);
            btn.style.display = 'none';
            ta.insertAdjacentElement('afterend', cancelBtn);
            ta.insertAdjacentElement('afterend', saveBtn);
            ta.focus();
            cancelBtn.addEventListener('click', () => loadUserData(currentEmail));
            saveBtn.addEventListener('click', () => {
                const nuevo = ta.value.trim();
                if (!nuevo) return;
                saveBtn.disabled = true;
                fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_edit_pdc_note', nonce: NONCE, nota_id: item.dataset.id, nota: nuevo }) })
                  .then(r => r.json()).then(j => {
                    if (!j.success) { alert('Error: ' + (j.data || '')); saveBtn.disabled = false; return; }
                    loadUserData(currentEmail);
                  }).catch(() => { alert('Error de red.'); saveBtn.disabled = false; });
            });
        });

        const $sModal = document.getElementById('vc-stripe-modal');
        const $sClose = document.getElementById('vc-stripe-close');
        const $sBody  = document.getElementById('vc-stripe-body');
        $sClose.addEventListener('click', () => $sModal.style.display = 'none');
        $sModal.addEventListener('click', e => { if (e.target === $sModal) $sModal.style.display = 'none'; });
        tbody.addEventListener('click', e => {
            const btn = e.target.closest('.vc-stripe-btn'); if (!btn) return;
            const customer = btn.dataset.customer || ''; const subscription = btn.dataset.subscription || ''; const email = btn.dataset.email || '';
            $sBody.innerHTML = '<div style="opacity:.7">Consultando a Stripe…</div>'; $sModal.style.display = 'flex';
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action: 'endtrack_verify_pdc_stripe', nonce: NONCE, launch: LAUNCH, email, customer, subscription }) })
              .then(r => r.json()).then(j => {
                if (!j.success) { $sBody.innerHTML = '<div style="color:#ef4444; padding:10px;"><strong>Error:</strong> '+(j.data||'')+'</div>'; return; }
                const d = j.data;
                let html = `<div style="background:rgba(99,91,253,.1); padding:12px 14px; border-radius:8px; border:1px solid rgba(99,91,253,.3);"><div style="font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px;">${d.kind}</div><div style="font-family:monospace; font-size:12px; margin-top:4px;">${d.id||''}</div>`;
                Object.keys(d).forEach(k => { if (['kind','id','stripe_url'].includes(k)) return; let v = d[k]; if (v === null || v === undefined) v = '—'; if (k.includes('period_end') || k.includes('cancel_at')) { if (v && typeof v === 'number') v = new Date(v*1000).toLocaleString(); } if (typeof v === 'boolean') v = v ? '✓ true' : '✕ false'; html += `<div style="margin-top:6px;"><span style="color:#94a3b8; text-transform:capitalize;">${k.replace(/_/g,' ')}:</span> <strong>${v}</strong></div>`; });
                if (d.stripe_url) html += `<div style="margin-top:14px;"><a href="${d.stripe_url}" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; gap:7px; background:#635bff; color:#fff; text-decoration:none; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:700; box-shadow:0 4px 12px rgba(99,91,253,.4);"><i class="fab fa-stripe-s"></i> Abrir en Stripe ↗</a></div>`;
                html += '</div>'; $sBody.innerHTML = html;
              }).catch(() => $sBody.innerHTML = '<div style="color:#ef4444">Error de red.</div>');
        });

        const $q = document.getElementById('vc-f-q');
        const $estado = document.getElementById('vc-f-estado');
        const $color = document.getElementById('vc-f-color');
        const $utipo = document.getElementById('vc-f-utipo');
        const $ptipo = document.getElementById('vc-f-ptipo');
        const $prod = document.getElementById('vc-f-prod'); // solo existe si el lanzamiento tiene >1 producto
        const $tipopago = document.getElementById('vc-f-tipopago');
        const $plataforma = document.getElementById('vc-f-plataforma');
        const $estadopago = document.getElementById('vc-f-estadopago');
        const $alumnofecha = document.getElementById('vc-f-alumnofecha');
        // Etiquetas PDC (multi-selección)
        const $etiqBtn = document.getElementById('vc-f-etiq-btn');
        const $etiqPop = document.getElementById('vc-f-etiq-pop');
        const $etiqLabel = document.getElementById('vc-f-etiq-label');
        const $etiqSin = document.getElementById('vc-f-etiq-sin');
        const etiqCbs = Array.from(document.querySelectorAll('.vc-f-etiq-cb'));
        const etiqNombres = {};
        etiqCbs.forEach(cb => { etiqNombres[cb.value] = cb.parentElement.querySelector('span').textContent; });
        function etiqSel() { return etiqCbs.filter(cb => cb.checked).map(cb => cb.value); }
        function etiqModo() { const r = document.querySelector('input[name="vc-f-etiq-modo"]:checked'); return r ? r.value : 'any'; }
        function etiqRefreshLabel() {
            const sel = etiqSel();
            let t = 'Todas';
            if ($etiqSin && $etiqSin.checked) t = 'Sin etiquetas' + (sel.length ? ' +' + sel.length : '');
            else if (sel.length === 1) t = etiqNombres[sel[0]] || '1 etiqueta';
            else if (sel.length > 1) t = sel.length + ' etiquetas (' + (etiqModo() === 'all' ? 'todas' : (etiqModo() === 'none' ? 'ninguna' : 'cualquiera')) + ')';
            if ($etiqLabel) $etiqLabel.textContent = t;
            if ($etiqBtn) $etiqBtn.style.borderColor = (sel.length || ($etiqSin && $etiqSin.checked)) ? 'rgba(99,102,241,.8)' : 'rgba(255,255,255,.1)';
        }
        const $cuotas = document.getElementById('vc-f-cuotas');
        const $imin = document.getElementById('vc-f-imin');
        const $imax = document.getElementById('vc-f-imax');
        const $count = document.getElementById('vc-f-count');
        const $clear = document.getElementById('vc-f-clear');
        const $csv = document.getElementById('vc-f-csv');
        const $csvAttr = document.getElementById('vc-f-csv-attr');

        // ── Paginación client-side sobre el resultado filtrado ──
        let vcPage = 1;
        let vcPerPage = 50;

        function apply() {
            const q = ($q.value || '').toLowerCase().trim();
            const fE = $estado.value, fC = $color.value;
            const fU = $utipo.value, fTP = $tipopago.value, fCu = $cuotas.value, fPlat = $plataforma.value, fEst = $estadopago.value, fAl = $alumnofecha.value;
            const fEtq = etiqSel(), fEtqModo = etiqModo(), fEtqSin = !!($etiqSin && $etiqSin.checked);
            const imin = parseFloat($imin.value); const imax = parseFloat($imax.value);
            const showTest = document.body.classList.contains('show-test');
            const showError = document.body.classList.contains('show-error');
            const matched = [];
            userRows.forEach(r => {
                let ok = true;
                const marca = r.dataset.marca || '';
                // Filas marcadas: solo visibles si su chip está activo (el CSS las oculta por defecto).
                if (marca === 'test'  && !showTest)  ok = false;
                if (marca === 'error' && !showError) ok = false;
                if (q && !(r.dataset.search || '').includes(q)) ok = false;
                if (ok && fE === 'ok' && (r.dataset.impago === '1' || r.dataset.nopago === '1')) ok = false;
                if (ok && fE === 'impago' && r.dataset.impago !== '1') ok = false;
                if (ok && fE === 'reembolso' && r.dataset.dup !== '1') ok = false;
                if (ok && fE === 'devolucion' && r.dataset.state !== 'devolucion') ok = false;
                if (ok && fE === 'nopago' && r.dataset.nopago !== '1') ok = false;
                if (ok && fE === 'plazos_ok' && r.dataset.plazos !== 'completado') ok = false;
                if (ok && fE === 'plazos_no' && r.dataset.plazos !== 'pendiente') ok = false;
                if (ok && fC) {
                    // Una fila puede llevar varios colores a la vez: "contiene X", no "es exactamente X".
                    const rColors = (r.dataset.color || '').split(',').filter(Boolean);
                    if (fC === 'none') { if (rColors.length > 0) ok = false; }
                    else if (!rColors.includes(fC)) ok = false;
                }
                if (ok && fU) {
                    const ut = r.dataset.utipo || '';
                    if (fU === 'etq_con') { if ((r.dataset.acceso || '') !== '1') ok = false; }
                    else if (fU === 'etq_sin') { if ((r.dataset.acceso || '') !== '0') ok = false; }
                    else if (fU === 'no_creado') { if (ut !== '') ok = false; }
                    else if (fU === 'otro') { if (ut === '' || ut === 'alumno' || ut === 'noacceso' || ut === 'sin_rol') ok = false; }
                    else if (ut !== fU) ok = false;
                }
                if (ok && $ptipo && $ptipo.value && (r.dataset.cats || '').split(' ').indexOf($ptipo.value) === -1) ok = false;
                if (ok && $prod && $prod.value && (r.dataset.prods || '').split('|').indexOf($prod.value) === -1) ok = false;
                if (ok && fTP && (r.dataset.tipo || '') !== fTP) ok = false;
                if (ok && fPlat && (r.dataset.plataforma || '') !== fPlat) ok = false;
                if (ok && fEst && (r.dataset.estadopago || '').split('|').indexOf(fEst) === -1) ok = false;
                if (ok && fAl === 'si' && r.dataset.alumnofecha !== '1') ok = false;
                if (ok && fAl === 'no' && r.dataset.alumnofecha !== '0') ok = false;
                if (ok && (fEtq.length || fEtqSin)) {
                    const rt = (r.dataset.etiq || '').split(',').filter(Boolean);
                    if (fEtqSin && rt.length > 0) ok = false;
                    if (ok && fEtq.length) {
                        if (fEtqModo === 'all')       { if (!fEtq.every(t => rt.includes(t))) ok = false; }
                        else if (fEtqModo === 'none') { if (fEtq.some(t => rt.includes(t))) ok = false; }
                        else                          { if (!fEtq.some(t => rt.includes(t))) ok = false; }
                    }
                }
                if (ok && fCu && (r.dataset.cuotastot || '') !== fCu) ok = false;
                const imp = parseFloat(r.dataset.importe || '0');
                if (ok && !isNaN(imin) && imp < imin) ok = false;
                if (ok && !isNaN(imax) && imp > imax) ok = false;
                r.dataset.match = ok ? '1' : '0';
                if (ok) {
                    matched.push(r);
                } else {
                    r.style.display = 'none';
                    const detail = r.nextElementSibling;
                    if (detail && detail.classList.contains('vc-detail-rows')) detail.style.display = 'none';
                }
            });
            const visible = matched.length;

            // ── Paginación: solo se pinta la página actual del resultado filtrado ──
            const per = vcPerPage;
            const totalPages = per > 0 ? Math.max(1, Math.ceil(visible / per)) : 1;
            if (vcPage > totalPages) vcPage = totalPages;
            if (vcPage < 1) vcPage = 1;
            const pStart = per > 0 ? (vcPage - 1) * per : 0;
            const pEnd = per > 0 ? pStart + per : visible;
            matched.forEach((r, i) => {
                if (i < pStart || i >= pEnd) {
                    r.style.display = 'none';
                    const detail = r.nextElementSibling;
                    if (detail && detail.classList.contains('vc-detail-rows')) detail.style.display = 'none';
                } else {
                    // Filas marcadas usan table-row directamente (el CSS las oculta si el chip no está activo)
                    r.style.display = (r.dataset.marca || '') ? 'table-row' : '';
                }
            });
            const $pgInfo = document.getElementById('vc-pg-info');
            const $pgPrev = document.getElementById('vc-pg-prev');
            const $pgNext = document.getElementById('vc-pg-next');
            if ($pgInfo) {
                $pgInfo.textContent = visible === 0 ? '0 filas' : ((pStart + 1) + '–' + Math.min(pEnd, visible) + ' · pág ' + vcPage + '/' + totalPages);
                if ($pgPrev) $pgPrev.disabled = vcPage <= 1;
                if ($pgNext) $pgNext.disabled = vcPage >= totalPages;
            }
            const _vcTotal = userRows.length;
            const _vcFiltering = visible !== _vcTotal;
            $count.innerHTML = '<span style="font-size:26px;">' + visible.toLocaleString('de-DE') + '</span>' +
                               '<span style="font-size:13px; font-weight:600; opacity:.7;">/ ' + _vcTotal.toLocaleString('de-DE') + ' usuarios</span>';
            if (_vcFiltering) {
                $count.style.color = '#a5b4fc';
                $count.style.background = 'rgba(99,102,241,.20)';
                $count.style.boxShadow = '0 0 0 1px rgba(129,140,248,.45)';
                $count.style.transform = 'scale(1.04)';
            } else {
                $count.style.color = 'var(--text-muted)';
                $count.style.background = 'transparent';
                $count.style.boxShadow = 'none';
                $count.style.transform = 'none';
            }
        }

        // ── Chips de marca (mostrar/ocultar pruebas / erróneas) ─────────
        const chipTest  = document.getElementById('vc-chip-test');
        const chipError = document.getElementById('vc-chip-error');
        function bindChip(chip, bodyClass) {
            if (!chip) return;
            chip.addEventListener('click', () => {
                const active = chip.dataset.active === '1';
                chip.dataset.active = active ? '0' : '1';
                document.body.classList.toggle(bodyClass, !active);
                vcPage = 1;
                apply();
            });
        }
        bindChip(chipTest,  'show-test');
        bindChip(chipError, 'show-error');

        // ── Recalcular KPIs y chips "Ocultas" en vivo al marcar test/error ──
        function vcFmtInt(n){ return Number(n).toLocaleString('de-DE'); }
        function vcFmtEur(n){ return Number(n).toLocaleString('es-ES',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €'; }
        function vcSet(id, txt){ const el = document.getElementById(id); if (el) el.textContent = txt; }
        function recomputeKPIs(){
            let visible=0,pagado=0,curso=0,reemb=0,devol=0,imp=0,alumno=0,noacceso=0,nocreado=0,solo=0,nTest=0,nError=0;
            userRows.forEach(r => {
                const marca = r.dataset.marca || '';
                if (marca === 'test')  { nTest++;  return; }
                if (marca === 'error') { nError++; return; }
                const ut = r.dataset.utipo || '';
                if (ut === 'alumno') alumno++; else if (ut === '') nocreado++;
                if (ACC_POR_ETIQUETA) { if (r.dataset.acceso === '0') noacceso++; }
                else if (ut === 'noacceso') noacceso++;
                if (r.dataset.nopago === '1') { solo++; return; }
                visible++;
                imp += parseFloat(r.dataset.importe || '0') || 0;
                const st = r.dataset.state || '';
                if (r.dataset.dup === '1') reemb++; // duplicados: contador aparte, no excluyente
                if (st === 'devolucion') devol++; else if (st === 'pagado') pagado++; else if (st === 'curso') curso++;
            });
            vcSet('kpi-visible', vcFmtInt(visible));
            vcSet('kpi-pagado', vcFmtInt(pagado));
            vcSet('kpi-curso', vcFmtInt(curso));
            vcSet('kpi-reembolso', vcFmtInt(reemb));
            vcSet('kpi-devolucion', vcFmtInt(devol));
            vcSet('kpi-imp', vcFmtEur(imp));
            vcSet('kpi-alumno', vcFmtInt(alumno));
            vcSet('kpi-noacceso', vcFmtInt(noacceso));
            vcSet('kpi-nocreado', vcFmtInt(nocreado));
            vcSet('kpi-soloacceso', vcFmtInt(solo));
            const wrap = document.getElementById('vc-ocultas-wrap');
            const ct = document.getElementById('vc-chip-test'), ce = document.getElementById('vc-chip-error');
            const ctn = document.getElementById('vc-chip-test-n'), cen = document.getElementById('vc-chip-error-n');
            if (ctn) ctn.textContent = nTest;
            if (cen) cen.textContent = nError;
            if (ct) ct.style.display = nTest > 0 ? 'inline-block' : 'none';
            if (ce) ce.style.display = nError > 0 ? 'inline-block' : 'none';
            if (wrap) wrap.style.display = (nTest > 0 || nError > 0) ? 'flex' : 'none';
        }

        // ── Botones marcar/desmarcar (test / error / revertir) ──────────
        tbody.addEventListener('click', e => {
            const btn = e.target.closest('.vc-marca-btn'); if (!btn) return;
            e.preventDefault(); e.stopPropagation();
            const email = btn.dataset.email;
            const marca = btn.dataset.marca || '';
            const row = tbody.querySelector(`.vc-user-row[data-email="${email}"]`);
            if (!row) return;
            const prev = row.dataset.marca || '';
            row.dataset.marca = marca;
            row.classList.remove('vc-marca-test', 'vc-marca-error');
            if (marca === 'test')  row.classList.add('vc-marca-test');
            if (marca === 'error') row.classList.add('vc-marca-error');
            recomputeKPIs();
            // Reemplaza los botones inline tras guardar
            const cell = btn.parentElement;
            const stripe = cell.querySelector('.vc-stripe-btn');
            // Limpia botones de marca actuales (los hermanos del Stripe)
            Array.from(cell.querySelectorAll('.vc-marca-btn')).forEach(b => b.remove());
            if (marca === '') {
                cell.insertAdjacentHTML('beforeend',
                  `<button type="button" class="vc-marca-btn" data-email="${email}" data-marca="test"  title="Marcar como prueba"  style="background:transparent;border:1px solid rgba(168,85,247,.25);color:#a78bfa;padding:2px 5px;border-radius:4px;cursor:pointer;font-size:10px;opacity:.6;">🧪</button>
                   <button type="button" class="vc-marca-btn" data-email="${email}" data-marca="error" title="Marcar como errónea" style="background:transparent;border:1px solid rgba(239,68,68,.25);color:#fca5a5;padding:2px 5px;border-radius:4px;cursor:pointer;font-size:10px;opacity:.6;">❌</button>`);
            } else {
                cell.insertAdjacentHTML('beforeend',
                  `<button type="button" class="vc-marca-btn" data-email="${email}" data-marca="" title="Revertir marca (${marca})" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);color:#fde68a;padding:2px 6px;border-radius:4px;cursor:pointer;font-size:10px;">↺</button>`);
            }
            fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action: 'endtrack_save_pdc_marca', nonce: NONCE, launch: LAUNCH, email, marca }) })
              .then(r => r.json()).then(j => {
                if (!j.success) {
                    alert('Error al guardar marca: ' + (j.data || ''));
                    row.dataset.marca = prev;
                    row.classList.remove('vc-marca-test', 'vc-marca-error');
                    if (prev === 'test')  row.classList.add('vc-marca-test');
                    if (prev === 'error') row.classList.add('vc-marca-error');
                    recomputeKPIs();
                }
                apply();
              }).catch(() => { row.dataset.marca = prev; recomputeKPIs(); apply(); });
            apply();
        });
        // Cambiar cualquier filtro vuelve a la página 1
        const onFilterChange = () => { vcPage = 1; apply(); };
        [$q,$estado,$color,$utipo,$ptipo,$prod,$tipopago,$plataforma,$estadopago,$alumnofecha,$cuotas,$imin,$imax].filter(Boolean).forEach(el => { el.addEventListener('input', onFilterChange); el.addEventListener('change', onFilterChange); });
        // ── Producto: nº de compradores por producto (sin pruebas/ocultadas) y
        //    preselección desde ?var_producto= (enlace de /endtrack-estadisticas-ventas/) ──
        if ($prod) {
            const pn = {};
            userRows.forEach(r => { const mk = r.dataset.marca || ''; if (mk === 'test' || mk === 'error') return; (r.dataset.prods || '').split('|').filter(Boolean).forEach(p => { pn[p] = (pn[p] || 0) + 1; }); });
            Array.from($prod.options).forEach(o => { if (o.value) o.textContent = (o.dataset.label || o.value) + ' (' + (pn[o.value] || 0) + ')'; });
            const qsProd = new URLSearchParams(window.location.search).get('var_producto');
            if (qsProd && Array.from($prod.options).some(o => o.value === qsProd)) $prod.value = qsProd;
        }
        // ── Etiquetas PDC: popover + recuento por etiqueta ──
        if ($etiqBtn && $etiqPop) {
            $etiqBtn.addEventListener('click', (e) => { e.stopPropagation(); $etiqPop.style.display = $etiqPop.style.display === 'none' ? 'block' : 'none'; });
            $etiqPop.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', () => { $etiqPop.style.display = 'none'; });
            const onEtiq = () => { etiqRefreshLabel(); onFilterChange(); };
            etiqCbs.forEach(cb => cb.addEventListener('change', onEtiq));
            if ($etiqSin) $etiqSin.addEventListener('change', onEtiq);
            document.querySelectorAll('input[name="vc-f-etiq-modo"]').forEach(r => r.addEventListener('change', onEtiq));
            const $etiqReset = document.getElementById('vc-f-etiq-reset');
            if ($etiqReset) $etiqReset.addEventListener('click', () => { etiqCbs.forEach(cb => cb.checked = false); if ($etiqSin) $etiqSin.checked = false; const a = document.querySelector('input[name="vc-f-etiq-modo"][value="any"]'); if (a) a.checked = true; onEtiq(); });
            // Recuento de usuarios (no test/error) por etiqueta
            const cnt = {}; let sinN = 0;
            userRows.forEach(r => { const mk = r.dataset.marca || ''; if (mk === 'test' || mk === 'error') return; const rt = (r.dataset.etiq || '').split(',').filter(Boolean); if (!rt.length) sinN++; rt.forEach(t => { cnt[t] = (cnt[t] || 0) + 1; }); });
            document.querySelectorAll('.vc-etiq-n').forEach(el => { el.textContent = cnt[el.dataset.tid] || 0; });
            const sinEl = document.getElementById('vc-etiq-n-sin'); if (sinEl) sinEl.textContent = sinN;
        }
        $clear.addEventListener('click', () => { $q.value=''; $estado.value=''; $color.value=''; $utipo.value=''; if ($ptipo) $ptipo.value=''; if ($prod) $prod.value=''; $tipopago.value=''; $plataforma.value=''; $estadopago.value=''; $alumnofecha.value=''; $cuotas.value=''; $imin.value=''; $imax.value=''; etiqCbs.forEach(cb => cb.checked = false); if ($etiqSin) $etiqSin.checked = false; etiqRefreshLabel(); vcPage = 1; apply(); });

        // ── Controles de paginación ──
        function vcScrollTable(){ const s = document.getElementById('sec_detalle_completo'); if (s) s.scrollIntoView({ behavior:'smooth', block:'start' }); }
        document.getElementById('vc-pg-prev').addEventListener('click', () => { if (vcPage > 1) { vcPage--; apply(); vcScrollTable(); } });
        document.getElementById('vc-pg-next').addEventListener('click', () => { vcPage++; apply(); vcScrollTable(); });
        document.getElementById('vc-pg-size').addEventListener('change', function () { vcPerPage = parseInt(this.value, 10) || 0; vcPage = 1; apply(); });
        $csv.addEventListener('click', () => {
            const table = document.getElementById('vc-detalle-table');
            const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim()).filter(h => h !== '' && h !== 'Acciones');
            const lines = [headers.map(h => `"${h}"`).join(',')];
            userRows.filter(r => r.dataset.match === '1').forEach(r => {
                const cells = Array.from(r.querySelectorAll('td')).filter(td => !td.querySelector('.vc-toggle') && !td.classList.contains('vc-fz-act'));
                const out = cells.map(td => { let v; const inp = td.querySelector('input'); if (inp) v = inp.value; else v = td.innerText.replace(/\s+/g, ' ').trim(); return `"${(v || '').replace(/"/g,'""')}"`; }).join(',');
                lines.push(out);
            });
            const blob = new Blob([lines.join('\n')], { type:'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'ventas-completas-' + LAUNCH + '.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        });

        // -- Exportar CSV CON ATRIBUCION (source/medium/campaign/afiliado) --
        $csvAttr.addEventListener('click', () => {
            const table = document.getElementById('vc-detalle-table');
            const baseHeaders = Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim()).filter(h => h !== '' && h !== 'Acciones');
            const headers = baseHeaders.concat(['Source', 'Medium', 'Campaign', 'Afiliado']);
            const lines = [headers.map(h => `"${h}"`).join(',')];
            userRows.filter(r => r.dataset.match === '1').forEach(r => {
                const cells = Array.from(r.querySelectorAll('td')).filter(td => !td.querySelector('.vc-toggle') && !td.classList.contains('vc-fz-act'));
                const base = cells.map(td => { let v; const inp = td.querySelector('input'); if (inp) v = inp.value; else v = td.innerText.replace(/\s+/g, ' ').trim(); return v || ''; });
                const extra = [r.dataset.asrc || '', r.dataset.amed || '', r.dataset.acam || '', r.dataset.aaff || ''];
                const out = base.concat(extra).map(v => `"${(v || '').replace(/"/g,'""')}"`).join(',');
                lines.push(out);
            });
            const blob = new Blob(['\ufeff' + lines.join('\n')], { type:'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'ventas-completas-atribucion-' + LAUNCH + '.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        });

        // -- Leyenda de colores editable + tooltips en vivo --
        document.querySelectorAll('.vc-color-label').forEach(inp => {
            inp.addEventListener('change', () => {
                const labels = {};
                document.querySelectorAll('.vc-color-label').forEach(i => labels[i.dataset.color] = i.value.trim());
                const st = document.getElementById('vc-legend-status');
                if (st) st.textContent = 'Guardando…';
                fetch(ajaxurl, { method:'POST', body: new URLSearchParams({ action:'endtrack_save_pdc_color_labels', nonce:NONCE, labels: JSON.stringify(labels) }) })
                  .then(r => r.json()).then(j => {
                    if (st) { st.textContent = j.success ? '✓ Guardado' : ('Error: ' + (j.data||'')); setTimeout(() => { st.textContent=''; }, 2000); }
                    Object.keys(labels).forEach(c => {
                        const t = labels[c] || (c.charAt(0).toUpperCase() + c.slice(1));
                        document.querySelectorAll('.vc-sw[data-color="' + c + '"]').forEach(b => b.title = t);
                    });
                  }).catch(() => { if (st) st.textContent = 'Error de red.'; });
            });
        });

        // -- Gestión de accesos (solo editores): buscador + listado --
        (function(){
            const $btn = document.getElementById('vc-access-btn');
            if (!$btn) return;
            const $modal   = document.getElementById('vc-access-modal');
            const $close   = document.getElementById('vc-access-close');
            const $search  = document.getElementById('vc-access-search');
            const $results = document.getElementById('vc-access-results');
            const $status  = document.getElementById('vc-access-status');
            const $list    = document.getElementById('vc-access-list');
            const esc = x => (x||'').replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
            function userRow(u){
                const info = `<div style="flex:1 1 auto; min-width:0;"><div style="font-weight:600; line-height:1.3; word-break:break-word;">${esc(u.name)}</div><div style="font-size:11px; color:#94a3b8; word-break:break-all;">${esc(u.email)}</div></div>`;
                const ctrl = (u.level === 'admin')
                  ? `<span style="flex:0 0 auto; font-size:11px; color:#fbbf24; padding:4px 10px; border:1px solid rgba(245,158,11,.3); border-radius:6px;">Administrador</span>`
                  : `<select data-id="${u.id}" class="vc-access-lvl" style="background:rgba(30,41,59,.95); border:1px solid rgba(255,255,255,.15); color:#fff; padding:5px 8px; border-radius:6px; font-size:12px; width:150px; flex:0 0 auto;">
                        <option value="none"${u.level==='none'||!u.level?' selected':''}>Sin acceso</option>
                        <option value="viewer"${u.level==='viewer'?' selected':''}>Visualización</option>
                        <option value="editor"${u.level==='editor'?' selected':''}>Editor</option>
                     </select>`;
                return `<div style="display:flex; align-items:center; gap:12px; padding:9px 0; border-bottom:1px solid rgba(255,255,255,.06);">${info}${ctrl}</div>`;
            }
            function load(){
                $list.innerHTML='<div style="opacity:.6;">Cargando…</div>';
                fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'endtrack_list_stats_access',nonce:NONCE})})
                  .then(r=>r.json()).then(j=>{
                    if(!j.success){ $list.innerHTML='<div style="color:#ef4444;">Error: '+(j.data||'')+'</div>'; return; }
                    $list.innerHTML = (j.data && j.data.length) ? j.data.map(userRow).join('') : '<div style="opacity:.6;">Nadie con acceso asignado todavía.</div>';
                  });
            }
            let searchTimer=null, lastQ='';
            function doSearch(){
                const q=($search.value||'').trim(); lastQ=q;
                if(q.length<2){ $results.innerHTML=''; return; }
                $results.innerHTML='<div style="opacity:.6;">Buscando…</div>';
                fetch(ajaxurl,{method:'POST',body:new URLSearchParams({action:'endtrack_search_users',nonce:NONCE,q})})
                  .then(r=>r.json()).then(j=>{
                    if(($search.value||'').trim()!==q) return; // resultado obsoleto
                    if(!j.success){ $results.innerHTML='<div style="color:#ef4444;">Error: '+(j.data||'')+'</div>'; return; }
                    $results.innerHTML = (j.data && j.data.length)
                      ? '<div style="font-size:10px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin:6px 0 2px;">Resultados</div>' + j.data.map(userRow).join('')
                      : '<div style="opacity:.6; padding:4px 0;">Sin resultados para “'+esc(q)+'”.</div>';
                  });
            }
            function setAccess(params){
                $status.textContent='Guardando…';
                fetch(ajaxurl,{method:'POST',body:new URLSearchParams(Object.assign({action:'endtrack_set_stats_access',nonce:NONCE},params))})
                  .then(r=>r.json()).then(j=>{
                    $status.textContent = j.success?'✓ Guardado':('Error: '+(j.data||'')); setTimeout(()=>{$status.textContent='';},3000);
                    if(j.success){ load(); if(lastQ.length>=2) doSearch(); }
                  });
            }
            $btn.addEventListener('click',()=>{ $modal.style.display='flex'; $search.value=''; $results.innerHTML=''; load(); });
            $close.addEventListener('click',()=>$modal.style.display='none');
            $modal.addEventListener('click',e=>{ if(e.target===$modal) $modal.style.display='none'; });
            $search.addEventListener('input',()=>{ clearTimeout(searchTimer); searchTimer=setTimeout(doSearch,300); });
            $modal.addEventListener('change',e=>{ const sel=e.target.closest('.vc-access-lvl'); if(!sel)return; setAccess({user_id:sel.dataset.id, level:sel.value}); });
        })();

        apply();
    })();
    </script>


    <?php if (current_user_can('manage_options')): ?>
<!-- Modal: Limpiar "No tiene fuente" (solo admin) -->
<div id="modal-fix-source" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#0f172a;border:1px solid rgba(245,158,11,.3);border-radius:18px;padding:32px;width:min(700px,95vw);max-height:85vh;overflow-y:auto;position:relative;">
        <button onclick="document.getElementById('modal-fix-source').style.display='none'" style="position:absolute;top:14px;right:18px;background:none;border:none;color:#64748b;font-size:22px;cursor:pointer;line-height:1;">✕</button>
        <h2 style="margin:0 0 6px;color:#e2e8f0;font-size:20px;"><i class="fas fa-broom" style="color:#f59e0b;margin-right:8px;"></i>Limpiar "No tiene fuente"</h2>
        <p style="color:#64748b;font-size:13px;margin:0 0 20px;">Busca registros en wp_datos con <code style="color:#fbbf24">source = "No tiene fuente"</code>, localiza su IP en wp_visitas y copia los UTM reales (source, medium, campaign, afiliado).</p>

        <div style="display:flex;gap:10px;margin-bottom:20px;align-items:center;">
            <button id="fns-btn-run" onclick="fnsRun()" style="background:#f59e0b;color:#000;border:none;padding:10px 22px;border-radius:12px;cursor:pointer;font-weight:700;font-size:13px;">
                <i class="fas fa-play"></i> Iniciar limpieza
            </button>
            <span id="fns-status" style="font-size:13px;color:#94a3b8;"></span>
        </div>

        <div id="fns-summary" style="display:none;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:14px 18px;margin-bottom:10px;font-size:13px;color:#e2e8f0;"></div>
        <div id="fns-error"   style="display:none;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#fca5a5;"></div>

        <div id="fns-results" style="display:none;">
            <p style="color:#64748b;font-size:11px;margin:0 0 8px;">Primeros 50 registros actualizados</p>
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead>
                    <tr style="color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid rgba(255,255,255,.06);">
                        <th style="padding:8px 10px;text-align:left;">Email</th>
                        <th style="padding:8px 10px;text-align:left;">IP</th>
                        <th style="padding:8px 10px;text-align:left;">Source</th>
                        <th style="padding:8px 10px;text-align:left;">Medium</th>
                        <th style="padding:8px 10px;text-align:left;">Campaign</th>
                        <th style="padding:8px 10px;text-align:left;">Afiliado</th>
                    </tr>
                </thead>
                <tbody id="fns-tbody"></tbody>
            </table>
        </div>
    </div>
</div>
<script>
async function fnsRun() {
    const btn    = document.getElementById('fns-btn-run');
    const status = document.getElementById('fns-status');
    const sumEl  = document.getElementById('fns-summary');
    const errEl  = document.getElementById('fns-error');
    const resEl  = document.getElementById('fns-results');
    const tbody  = document.getElementById('fns-tbody');

    btn.disabled = true;
    btn.textContent = 'Procesando…';
    status.textContent = '';
    sumEl.style.display = 'none';
    errEl.style.display = 'none';
    resEl.style.display = 'none';
    tbody.innerHTML = '';

    const body = new FormData();
    body.set('action',  'endtrack_fix_no_source');
    body.set('nonce',   endtrackNonce);
    body.set('launch',  '<?php echo esc_js($active_launch); ?>');

    try {
        const r   = await fetch(endtrackAjax, { method: 'POST', body });
        const txt = await r.text();
        let data;
        try { data = JSON.parse(txt); } catch(e) {
            errEl.textContent = 'Respuesta inválida: ' + txt.substring(0, 300);
            errEl.style.display = 'block';
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Iniciar limpieza';
            return;
        }
        if (!data.success) {
            errEl.textContent = 'Error: ' + (data.data || 'desconocido');
            errEl.style.display = 'block';
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-play"></i> Iniciar limpieza';
            return;
        }
        const d = data.data;
        sumEl.innerHTML = `<b style="color:#fbbf24">${d.updated}</b> actualizados &nbsp;·&nbsp; `
            + `<b style="color:#94a3b8">${d.sin_visita}</b> sin visita en wp_visitas &nbsp;·&nbsp; `
            + `<b style="color:#64748b">${d.skipped}</b> otros &nbsp;·&nbsp; `
            + `<b>${d.total}</b> total con "No tiene fuente"`;
        sumEl.style.display = 'block';

        if (d.sample && d.sample.length > 0) {
            d.sample.forEach(row => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid rgba(255,255,255,.04)';
                tr.innerHTML = `<td style="padding:7px 10px;color:#e2e8f0;">${row.correo}</td>`
                    + `<td style="padding:7px 10px;color:#64748b;font-family:monospace;">${row.ip}</td>`
                    + `<td style="padding:7px 10px;color:#4ade80;">${row.source||'—'}</td>`
                    + `<td style="padding:7px 10px;color:#94a3b8;">${row.medium||'—'}</td>`
                    + `<td style="padding:7px 10px;color:#94a3b8;">${row.campaign||'—'}</td>`
                    + `<td style="padding:7px 10px;color:#fbbf24;">${row.afiliado||'—'}</td>`;
                tbody.appendChild(tr);
            });
            resEl.style.display = 'block';
        }
    } catch(e) {
        errEl.textContent = 'Error de conexión: ' + e;
        errEl.style.display = 'block';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-play"></i> Iniciar limpieza';
}
</script>

<!-- Modal: Rellenar Países (solo admin) -->
<div id="modal-fill-pais" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#0f172a;border:1px solid rgba(20,184,166,.3);border-radius:18px;padding:32px;width:min(680px,95vw);max-height:85vh;overflow-y:auto;position:relative;">
        <button onclick="document.getElementById('modal-fill-pais').style.display='none'" style="position:absolute;top:14px;right:18px;background:none;border:none;color:#64748b;font-size:22px;cursor:pointer;line-height:1;">✕</button>
        <h2 style="margin:0 0 6px;color:#e2e8f0;font-size:20px;"><i class="fas fa-globe" style="color:#14b8a6;margin-right:8px;"></i>Rellenar Países</h2>
        <p style="color:#64748b;font-size:13px;margin:0 0 20px;">Busca compradores sin país asignado, obtiene su IP (wp_datos → pdc_accesos → session_tokens) y la geolocaliza. Actualiza wp_datos.pais o pdc_pagos.pais según corresponda.</p>

        <div style="display:flex;gap:10px;margin-bottom:20px;align-items:center;">
            <button id="fp-btn-run" onclick="fpRun()" style="background:#14b8a6;color:#fff;border:none;padding:10px 22px;border-radius:12px;cursor:pointer;font-weight:700;font-size:13px;">
                <i class="fas fa-play"></i> Iniciar proceso
            </button>
            <span id="fp-status" style="font-size:13px;color:#94a3b8;"></span>
        </div>

        <div id="fp-summary" style="display:none;background:rgba(20,184,166,.08);border:1px solid rgba(20,184,166,.2);border-radius:12px;padding:14px 18px;margin-bottom:10px;font-size:13px;color:#e2e8f0;"></div>
        <div id="fp-sources" style="display:none;background:rgba(15,23,42,.6);border:1px solid rgba(100,116,139,.2);border-radius:12px;padding:12px 18px;margin-bottom:16px;font-size:12px;color:#94a3b8;"></div>
        <div id="fp-error" style="display:none;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#fca5a5;"></div>

        <div id="fp-results" style="display:none;">
            <p style="color:#64748b;font-size:11px;margin:0 0 8px;">Mostrando primeras 300 filas</p>
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead>
                    <tr style="color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid rgba(255,255,255,.06);">
                        <th style="padding:8px 10px;text-align:left;">Email</th>
                        <th style="padding:8px 10px;text-align:left;">IP</th>
                        <th style="padding:8px 10px;text-align:left;">Fuente IP</th>
                        <th style="padding:8px 10px;text-align:left;">País</th>
                        <th style="padding:8px 10px;text-align:left;">Destino</th>
                    </tr>
                </thead>
                <tbody id="fp-tbody"></tbody>
            </table>
        </div>
    </div>
</div>
<script>
async function fpRun() {
    const btn     = document.getElementById('fp-btn-run');
    const status  = document.getElementById('fp-status');
    const sumEl   = document.getElementById('fp-summary');
    const srcEl   = document.getElementById('fp-sources');
    const errEl   = document.getElementById('fp-error');
    const resEl   = document.getElementById('fp-results');
    const tbody   = document.getElementById('fp-tbody');

    btn.disabled    = true;
    btn.textContent = 'Procesando…';
    status.textContent = '';
    sumEl.style.display = 'none';
    srcEl.style.display = 'none';
    errEl.style.display = 'none';
    resEl.style.display = 'none';
    tbody.innerHTML = '';

    const body = new FormData();
    body.set('action',  'endtrack_fill_pais');
    body.set('nonce',   endtrackNonce);
    body.set('launch',  '<?php echo esc_js($active_launch); ?>');

    try {
        const r    = await fetch(endtrackAjax, { method: 'POST', body });
        const txt  = await r.text();
        let data;
        try { data = JSON.parse(txt); } catch(e) {
            errEl.textContent = 'Respuesta inválida del servidor: ' + txt.substring(0, 300);
            errEl.style.display = 'block';
            return;
        }
        if (!data.success) {
            errEl.textContent = 'Error: ' + (data.data || 'desconocido');
            errEl.style.display = 'block';
            return;
        }
        const d = data.data;
        const statusLabels = { wp_datos: 'wp_datos ✓', pdc_pagos: 'pdc_pagos ✓', sin_ip: 'Sin IP', sin_pais: 'Sin GeoIP', error_db: 'Error BD' };
        const statusColors = { wp_datos: '#4ade80', pdc_pagos: '#34d399', sin_ip: '#f59e0b', sin_pais: '#ef4444', error_db: '#f87171' };
        const srcLabels    = { wp_datos: 'wp_datos.ip', pdc_accesos: 'pdc_accesos', session_tokens: 'session_tokens' };

        sumEl.innerHTML = d.message
            ? d.message + (d.synced ? ` &nbsp;·&nbsp; <b style="color:#34d399">${d.synced} sincronizados wp→pdc</b>` : '')
            : `<b style="color:#4ade80">${d.updated} actualizados (GeoIP)</b>`
              + (d.synced ? ` &nbsp;·&nbsp; <b style="color:#34d399">${d.synced} sincronizados wp→pdc</b>` : '')
              + ` &nbsp;·&nbsp; <b style="color:#f59e0b">${d.skipped} omitidos</b>`
              + ` &nbsp;·&nbsp; ${d.total_sin_pais} pendientes GeoIP`;
        sumEl.style.display = 'block';

        if (d.sources || d.diag) {
            const s = d.sources || {};
            const g = d.diag   || {};
            let html = '';
            if (g.total_buyers !== undefined)
                html += `<b style="color:#e2e8f0;">Compradores en pdc_pagos:</b> <span style="color:#cbd5e1">${g.total_buyers}</span>`
                      + ` &nbsp;·&nbsp; <b style="color:#e2e8f0;">En wp_datos:</b> <span style="color:#cbd5e1">${g.in_wp_datos||0}</span><br>`;
            if (g.skip_wp_pais !== undefined)
                html += `<b style="color:#e2e8f0;">Saltados (ya tienen país):</b>`
                      + ` <span style="color:#64748b">wp_datos: ${g.skip_wp_pais||0}</span>`
                      + ` &nbsp;·&nbsp; <span style="color:#64748b">pdc_pagos: ${g.skip_pdc_pais||0}</span>`;
            if (g.sample_pdc && g.sample_pdc.length)
                html += `<br><span style="color:#475569;font-size:11px;">Ej. pdc: ${g.sample_pdc.join(' | ')}</span>`;
            if (g.sample_wp && g.sample_wp.length)
                html += `<br><span style="color:#475569;font-size:11px;">Ej. wp: ${g.sample_wp.join(' | ')}</span>`;
            if (html) html += '<br>';
            html += `<b style="color:#e2e8f0;">Fuente de IP:</b> &nbsp;`
                  + `<span style="color:#4ade80">wp_datos.ip: ${s.wp_datos||0}</span> &nbsp;·&nbsp;`
                  + `<span style="color:#34d399">pdc_accesos: ${s.pdc_accesos||0}</span> &nbsp;·&nbsp;`
                  + `<span style="color:#38bdf8">session_tokens: ${s.session_tokens||0}</span>`
                  + `<br><b style="color:#e2e8f0;">Sin IP:</b> <span style="color:#f59e0b">${s.sin_ip||0}</span>`
                  + ` &nbsp;·&nbsp; <b style="color:#e2e8f0;">GeoIP falló:</b> <span style="color:#ef4444">${s.sin_geoip||0}</span>`;
            srcEl.innerHTML = html;
            srcEl.style.display = 'block';
        }

        if (d.results && d.results.length > 0) {
            const frag = document.createDocumentFragment();
            d.results.forEach(row => {
                const color  = statusColors[row.status] || '#94a3b8';
                const label  = statusLabels[row.status] || row.status;
                const srcLbl = srcLabels[row.ip_src] || (row.ip_src || '—');
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid rgba(255,255,255,.04)';
                tr.innerHTML = `<td style="padding:6px 10px;color:#cbd5e1;font-size:11px;">${row.mail||''}</td>`
                    + `<td style="padding:6px 10px;color:#64748b;font-family:monospace;font-size:11px;">${row.ip||'—'}</td>`
                    + `<td style="padding:6px 10px;color:#94a3b8;font-size:11px;">${srcLbl}</td>`
                    + `<td style="padding:6px 10px;font-weight:700;color:${color};">${row.pais||'—'}</td>`
                    + `<td style="padding:6px 10px;color:${color};font-size:11px;">${label}</td>`;
                frag.appendChild(tr);
            });
            tbody.appendChild(frag);
            resEl.style.display = 'block';
        }
        status.textContent = '✓ Completado';
    } catch(err) {
        errEl.textContent = 'Error de red: ' + (err && err.message ? err.message : String(err));
        errEl.style.display = 'block';
    } finally {
        btn.disabled    = false;
        btn.textContent = '↺ Volver a ejecutar';
    }
}
</script>
<?php endif; ?>

</body>
</html>
