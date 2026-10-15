<?php
/**
 * Template Name: ENDTrack Ventas Stats Fullscreen
 * URL: /endtrack-estadisticas-ventas/
 * Shows registrados + ventas from pdc_pagos (cuota=1). No visits/sessions.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table_datos = $wpdb->prefix . 'datos';

// ============================================================
// 1. FILTERS & CONTEXT
// ============================================================
$endtrack_launches_list = get_option('endtrack_launches', array());
$default_launch = !empty($endtrack_launches_list) ? end($endtrack_launches_list) : 'legacy';
$active_launch = isset($_GET['launch']) ? sanitize_text_field($_GET['launch']) : $default_launch;
$filter_date_start = isset($_GET['d_start']) ? sanitize_text_field($_GET['d_start']) : date('Y-m-d', strtotime('-90 days'));
$filter_date_end = isset($_GET['d_end']) ? sanitize_text_field($_GET['d_end']) : date('Y-m-d');
$filter_source = isset($_GET['var_source']) ? sanitize_text_field($_GET['var_source']) : '';
$filter_medium = isset($_GET['var_medium']) ? sanitize_text_field($_GET['var_medium']) : '';
$filter_campaign = isset($_GET['var_campaign']) ? sanitize_text_field($_GET['var_campaign']) : '';
$filter_afiliado = isset($_GET['var_afiliado']) ? sanitize_text_field($_GET['var_afiliado']) : '';

// Quick source-filter for País and Medio sections (like stats-fullscreen)
$s_pais = isset($_GET['s_pais']) ? sanitize_text_field($_GET['s_pais']) : 'todo';
$s_medio = isset($_GET['s_medio']) ? sanitize_text_field($_GET['s_medio']) : 'todo';
if (!in_array($s_pais, ['todo', 'organico', 'publicidad', 'afiliado']))
    $s_pais = 'todo';
if (!in_array($s_medio, ['todo', 'organico', 'publicidad', 'afiliado']))
    $s_medio = 'todo';
$_filter_opts = ['todo' => 'TODO', 'organico' => 'ORGÁNICO', 'publicidad' => 'PUBLICIDAD', 'afiliado' => 'AFILIADO'];

if ($active_launch === 'legacy') {
    $col_primer_reg = 'primer_reg';
    $safe_launch = 'legacy';
} else {
    $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $active_launch);
    $col_primer_reg = 'primer_reg_' . $safe_launch;
}

// ============================================================
// 2. BUILD WHERE CLAUSE (wp_datos, no visitas)
// ============================================================
$where_d = "WHERE 1=1";
$args_d = [];

$where_d .= " AND fecha >= %s AND fecha <= %s";
$args_d[] = $filter_date_start . ' 00:00:00';
$args_d[] = $filter_date_end . ' 23:59:59';

if ($filter_source) {
    $where_d .= " AND source = %s";
    $args_d[] = $filter_source;
}
if ($filter_medium) {
    $where_d .= " AND medium = %s";
    $args_d[] = $filter_medium;
}
if ($filter_campaign) {
    $where_d .= " AND campaign = %s";
    $args_d[] = $filter_campaign;
}
if ($filter_afiliado) {
    $where_d .= " AND afiliado = %s";
    $args_d[] = $filter_afiliado;
}

$sql_d = !empty($args_d) ? $wpdb->prepare($where_d, ...$args_d) : $where_d;
if (empty($sql_d))
    $sql_d = "WHERE 1=1";

$reg_cond = " AND {$col_primer_reg} = 1";

// Dropdown data (unfiltered — always show all options)
$filter_options = [
    'sources' => $wpdb->get_col("SELECT DISTINCT source   FROM {$table_datos} WHERE source   != '' AND source   IS NOT NULL ORDER BY source"),
    'mediums' => $wpdb->get_col("SELECT DISTINCT medium   FROM {$table_datos} WHERE medium   != '' AND medium   IS NOT NULL ORDER BY medium"),
    'campaigns' => $wpdb->get_col("SELECT DISTINCT campaign FROM {$table_datos} WHERE campaign != '' AND campaign IS NOT NULL ORDER BY campaign"),
    'afiliados' => $wpdb->get_col("SELECT DISTINCT afiliado FROM {$table_datos} WHERE afiliado != '' AND afiliado IS NOT NULL AND afiliado > 0 ORDER BY afiliado"),
];

// ============================================================
// 3. EXTERNAL DB CONFIG
// ============================================================
$__ext = endtrack_ext_db_config();
$ext_host = $__ext['host'];
$ext_user_db = $__ext['user'];
$ext_pass = $__ext['pass'];
$ext_name = $__ext['name'];

$opt_key = 'endtrack_ext_products_' . $safe_launch;
$launch_products = json_decode(get_option($opt_key, '[]'), true);
if (!is_array($launch_products))
    $launch_products = [];

// Filtro por producto del lanzamiento (?var_producto=<ID_producto>). Solo vale un ID
// de la lista del lanzamiento. Ventas, reembolsos e ingresos se limitan a ese producto;
// los registrados (wp_datos) no dependen del producto y no cambian.
$all_launch_products = array_values(array_map('strval', $launch_products));
$launch_product_names = []; // ID_producto => nombre en el campus (se rellena con la conexión PDC)
$filter_producto = isset($_GET['var_producto']) ? trim((string) wp_unslash($_GET['var_producto'])) : '';
if ($filter_producto !== '' && !in_array($filter_producto, $all_launch_products, true))
    $filter_producto = '';
if ($filter_producto !== '')
    $launch_products = [$filter_producto];

// ============================================================
// 3b. NORMALIZACIÓN DE PAÍS
//   Los países llegan mezclados: nombres en inglés desde wp_datos (ip.guide:
//   'Spain', 'Germany'…) y códigos ISO o nombres desde pdc_pagos ('ES'…).
//   Se unifican a un nombre canónico en español para que agrupen bien.
// ============================================================
if (!function_exists('endtrack_normalize_pais')) {
    function endtrack_normalize_pais($p)
    {
        $p = trim((string) $p);
        if ($p === '')
            return 'Desconocido';
        static $map = null;
        if ($map === null) {
            $canon = [
                'España' => ['es', 'spain', 'españa', 'espana'],
                'México' => ['mx', 'mexico', 'méxico', 'méjico'],
                'Estados Unidos' => ['us', 'usa', 'united states', 'united states of america', 'estados unidos', 'ee.uu.', 'eeuu'],
                'Colombia' => ['co', 'colombia'],
                'Argentina' => ['ar', 'argentina'],
                'Chile' => ['cl', 'chile'],
                'Perú' => ['pe', 'peru', 'perú'],
                'Venezuela' => ['ve', 'venezuela'],
                'Ecuador' => ['ec', 'ecuador'],
                'Bolivia' => ['bo', 'bolivia'],
                'Paraguay' => ['py', 'paraguay'],
                'Uruguay' => ['uy', 'uruguay'],
                'Panamá' => ['pa', 'panama', 'panamá'],
                'Guatemala' => ['gt', 'guatemala'],
                'Honduras' => ['hn', 'honduras'],
                'El Salvador' => ['sv', 'el salvador'],
                'Nicaragua' => ['ni', 'nicaragua'],
                'Costa Rica' => ['cr', 'costa rica'],
                'República Dominicana' => ['do', 'dominican republic', 'república dominicana', 'republica dominicana', 'rep. dominicana', 'rep. dom.'],
                'Cuba' => ['cu', 'cuba'],
                'Puerto Rico' => ['pr', 'puerto rico'],
                'Brasil' => ['br', 'brazil', 'brasil'],
                'Portugal' => ['pt', 'portugal'],
                'Francia' => ['fr', 'france', 'francia'],
                'Alemania' => ['de', 'germany', 'alemania', 'deutschland'],
                'Italia' => ['it', 'italy', 'italia'],
                'Reino Unido' => ['gb', 'uk', 'united kingdom', 'reino unido'],
                'Irlanda' => ['ie', 'ireland', 'irlanda'],
                'Países Bajos' => ['nl', 'netherlands', 'países bajos', 'paises bajos', 'holanda', 'the netherlands'],
                'Bélgica' => ['be', 'belgium', 'bélgica', 'belgica'],
                'Suiza' => ['ch', 'switzerland', 'suiza'],
                'Austria' => ['at', 'austria'],
                'Andorra' => ['ad', 'andorra'],
                'Luxemburgo' => ['lu', 'luxembourg', 'luxemburgo'],
                'Suecia' => ['se', 'sweden', 'suecia'],
                'Noruega' => ['no', 'norway', 'noruega'],
                'Dinamarca' => ['dk', 'denmark', 'dinamarca'],
                'Finlandia' => ['fi', 'finland', 'finlandia'],
                'Polonia' => ['pl', 'poland', 'polonia'],
                'Rumanía' => ['ro', 'romania', 'rumanía', 'rumania'],
                'Grecia' => ['gr', 'greece', 'grecia'],
                'Canadá' => ['ca', 'canada', 'canadá'],
                'Australia' => ['au', 'australia'],
                'Marruecos' => ['ma', 'morocco', 'marruecos'],
                'Emiratos Árabes' => ['ae', 'united arab emirates', 'emiratos árabes', 'emiratos arabes'],
                'Japón' => ['jp', 'japan', 'japón', 'japon'],
                'China' => ['cn', 'china'],
                'Singapur' => ['sg', 'singapore', 'singapur'],
                'Desconocido' => ['desconocido', 'unknown', 'n/a', '-'],
            ];
            $map = [];
            foreach ($canon as $canonical => $variants) {
                foreach ($variants as $vv)
                    $map[$vv] = $canonical;
            }
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($p, 'UTF-8') : strtolower($p);
        return isset($map[$key]) ? $map[$key] : $p;
    }
}

// ============================================================
// 4. FETCH REGISTRADOS FROM wp_datos
// ============================================================
$total_regs = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT correo) FROM {$table_datos} {$sql_d} {$reg_cond}"
);

// Date-filtered rows — used for registrados dimension breakdown
$regs_rows = $wpdb->get_results(
    "SELECT DISTINCT correo, source, medium, campaign, pais, afiliado, fecha
     FROM {$table_datos} {$sql_d} {$reg_cond}
     AND correo != '' AND correo IS NOT NULL
     ORDER BY fecha ASC"
);

// All-time attribution map (no date filter) — covers buyers who registered outside the date range
$table_visitas = $wpdb->prefix . 'visitas';
$all_attr_rows = $wpdb->get_results(
    "SELECT DISTINCT D.correo, D.source, D.medium, D.campaign, D.pais, D.afiliado, D.url_actual,
            IFNULL(
                NULLIF(
                    (SELECT V.ip FROM {$table_visitas} V
                     WHERE V.session_id = D.session_id AND V.ip IS NOT NULL AND V.ip != ''
                     ORDER BY V.fecha DESC LIMIT 1),
                ''),
                NULLIF(D.ip, '')
            ) AS ip
     FROM {$table_datos} D
     WHERE D.{$col_primer_reg} = 1
       AND D.correo != '' AND D.correo IS NOT NULL"
);
$mail_to_attr = [];
foreach ($all_attr_rows as $r) {
    $key = strtolower(trim($r->correo));
    if ($key === '')
        continue;
    $mail_to_attr[$key] = [
        'source' => (string) $r->source,
        'medium' => $r->medium ?: 'none',
        'campaign' => $r->campaign ?: '(sin campaña)',
        'pais' => endtrack_normalize_pais($r->pais),
        'afiliado' => intval($r->afiliado),
        'url_actual' => $r->url_actual ?: '',
        'ip' => $r->ip ?: '',
    ];
}

// ============================================================
// 5. FETCH SALES FROM pdc_pagos
//    — ALL buyers for these products, filtered by purchase date.
//    — Buyers not in datos (direct buyers) use pdc_pagos source/medium/campaign.
// ============================================================
$ventas_rows = [];
$refund_rows = [];
$ext_error = null;
$use_ext_db = !empty($launch_products);

if ($use_ext_db) {
    try {
        $pdo = new PDO(
            "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
            $ext_user_db,
            $ext_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
        );

        if (function_exists('endtrack_pdc_product_names'))
            $launch_product_names = endtrack_pdc_product_names($pdo, $all_launch_products);

        $ph_prods = implode(',', array_fill(0, count($launch_products), '?'));
        $params = array_merge($launch_products, [$filter_date_start, $filter_date_end]);

        $stmt = $pdo->prepare("
            SELECT
                LOWER(TRIM(U.mail)) AS mail,
                IFNULL(A.importe, 0) AS importe,
                A.producto,
                DATE(A.fecha) AS fecha_venta,
                A.fecha        AS fecha_datetime,
                IFNULL(A.source,    '') AS pdc_source,
                IFNULL(A.medium,    '') AS pdc_medium,
                IFNULL(A.campaign,  '') AS pdc_campaign,
                IFNULL(A.ref,        '') AS pdc_ref,
                IFNULL(A.plataforma, '') AS plataforma,
                IFNULL(A.cuotas, 1)    AS cuotas_plan,
                IFNULL(A.metodo,    '') AS metodo,
                IFNULL(A.pago_licencia, 0) AS pago_licencia,
                IFNULL(A.pais, '')         AS pdc_pais
            FROM pdc_pagos A
            INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
            WHERE A.ID_producto IN ({$ph_prods})
              AND A.estado IN ('Completado','Compra')
              AND (A.no_comision_afiliado = 0 OR A.no_comision_afiliado IS NULL)
              AND DATE(A.fecha) >= ?
              AND DATE(A.fecha) <= ?
            ORDER BY A.fecha ASC
        ");
        $stmt->execute($params);
        $ventas_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Reembolsos y Devoluciones (sin filtro de fecha — cualquier reembolso del producto)
        // reembolso=1 → Reembolso real | reembolso=0 o NULL → Devolución
        $stmt_ref = $pdo->prepare("
            SELECT LOWER(TRIM(U.mail)) AS mail,
                   IFNULL(A.importe, 0) AS importe,
                   IFNULL(A.reembolso, 0) AS reembolso
            FROM pdc_pagos A
            INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
            WHERE A.ID_producto IN ({$ph_prods})
              AND A.estado = 'Reembolso'
              AND A.cuota = 1
        ");
        $stmt_ref->execute($launch_products);
        $refund_rows = $stmt_ref->fetchAll(PDO::FETCH_ASSOC);

        $pdo = null;
    } catch (Exception $e) {
        $ext_error = $e->getMessage();
        $use_ext_db = false;
    }
}

// ============================================================
// 5a-bis. EXCLUIR ventas marcadas como prueba/errónea en /ventas-completas/
//   (wp_endtrack_pdc_estado, marca = 'test'|'error', por launch+email).
//   Así las ventas ocultas/test no cuentan tampoco en estas estadísticas.
// ============================================================
$_excluded_mails = array();
$_t_estado_marca = $wpdb->prefix . 'endtrack_pdc_estado';
if (!empty($safe_launch)) {
    $_marca_rows = $wpdb->get_col($wpdb->prepare(
        "SELECT LOWER(TRIM(user_email)) FROM {$_t_estado_marca} WHERE launch = %s AND marca IN ('test','error')",
        $safe_launch
    ));
    foreach ((array) $_marca_rows as $_em) {
        $_em = strtolower(trim((string) $_em));
        if ($_em !== '') $_excluded_mails[$_em] = true;
    }
}
if (!empty($_excluded_mails)) {
    $ventas_rows = array_values(array_filter($ventas_rows, function ($v) use ($_excluded_mails) {
        return !isset($_excluded_mails[strtolower(trim((string) $v['mail']))]);
    }));
    $refund_rows = array_values(array_filter($refund_rows, function ($r) use ($_excluded_mails) {
        return !isset($_excluded_mails[strtolower(trim((string) $r['mail']))]);
    }));
}

// ============================================================
// 5a-diag. DIAGNÓSTICO: discrepancia admin vs stats (+1 venta)
// Busca compradores cuyo pdc_pagos.ref difiere de wp_datos.afiliado
// y compradores con múltiples refs distintos en pdc_pagos
// ============================================================
$_diag_multi_ref   = []; // mismo mail, refs distintos en pdc_pagos
$_diag_ref_vs_wp   = []; // pdc_ref != wp_datos.afiliado
$_diag_mail_refs   = []; // acumula refs por mail
foreach ($ventas_rows as $_dv) {
    $_dm   = strtolower(trim((string) $_dv['mail']));
    $_dref = intval(trim((string) $_dv['pdc_ref']));
    if ($_dref > 0) {
        if (!isset($_diag_mail_refs[$_dm])) $_diag_mail_refs[$_dm] = [];
        if (!in_array($_dref, $_diag_mail_refs[$_dm])) $_diag_mail_refs[$_dm][] = $_dref;
    }
}
foreach ($_diag_mail_refs as $_dm => $_drefs) {
    if (count($_drefs) > 1) {
        $_diag_multi_ref[] = ['mail' => $_dm, 'refs' => implode(',', $_drefs)];
    }
    $_wp_af = isset($mail_to_attr[$_dm]) ? intval($mail_to_attr[$_dm]['afiliado']) : 0;
    foreach ($_drefs as $_dref_val) {
        if ($_wp_af > 0 && $_wp_af !== $_dref_val) {
            $_diag_ref_vs_wp[] = ['mail' => $_dm, 'pdc_ref' => $_dref_val, 'wp_afiliado' => $_wp_af];
        }
    }
}
// También compradores en pdc_pagos SIN ref pero CON wp afiliado (o viceversa)
$_diag_only_pdc_ref  = []; // tiene ref en pdc pero NO en wp_datos
$_diag_only_wp_af    = []; // está en wp_datos con afiliado pero pdc_ref = 0
$_seen_diag = [];
foreach ($ventas_rows as $_dv) {
    $_dm   = strtolower(trim((string) $_dv['mail']));
    if (isset($_seen_diag[$_dm])) continue;
    $_seen_diag[$_dm] = true;
    $_dref = intval(trim((string) $_dv['pdc_ref']));
    $_wp_af = isset($mail_to_attr[$_dm]) ? intval($mail_to_attr[$_dm]['afiliado']) : 0;
    if ($_dref > 0 && $_wp_af === 0)
        $_diag_only_pdc_ref[] = ['mail' => $_dm, 'pdc_ref' => $_dref];
    if ($_dref === 0 && $_wp_af > 0)
        $_diag_only_wp_af[] = ['mail' => $_dm, 'wp_afiliado' => $_wp_af];
}

// ============================================================
// 5b. PRE-FETCH IPs desde pdc_accesos → wp_visitas UTM
//     Para compradores NO en wp_datos y con pdc_pagos UTM vacío:
//     email → wp_users (ID) → pdc_accesos (IP más reciente) → wp_visitas (UTM)
// ============================================================
$wp_ip_attr = [];
$_dbg = [
    'candidatos' => 0, 'ips_accesos' => 0, 'ips_con_utm' => 0, 'aplicados' => 0, 'pdo_error' => '',
    'diag_multi_ref'    => $_diag_multi_ref,   // mismo mail, >1 ref en pdc_pagos
    'diag_ref_vs_wp'    => $_diag_ref_vs_wp,   // pdc_ref != wp_datos.afiliado
    'diag_only_pdc_ref' => array_slice($_diag_only_pdc_ref, 0, 20), // ref en pdc pero sin afiliado en wp_datos
    'diag_only_wp_af'   => array_slice($_diag_only_wp_af,  0, 20),  // afiliado en wp_datos pero sin ref en pdc
];
if (!empty($ventas_rows)) {
    $_ks = ['organico', 'publicidad', 'afiliado', 'email', 'sms', 'whatsapp', 'referral', 'referido'];
    $candidates = [];
    foreach ($ventas_rows as $_v) {
        $_m = strtolower(trim((string) $_v['mail']));
        if (
            trim((string) $_v['pdc_source']) !== '' || trim((string) $_v['pdc_medium']) !== ''
            || trim((string) $_v['pdc_campaign']) !== '' || trim((string) $_v['pdc_ref']) !== ''
        )
            continue;
        if (!isset($mail_to_attr[$_m])) {
            $candidates[$_m] = true;
            continue;
        }
        if (!in_array(strtolower(trim((string) $mail_to_attr[$_m]['source'])), $_ks, true))
            $candidates[$_m] = true;
    }
    $_dbg['candidatos'] = count($candidates);

    if (!empty($candidates)) {
        $cand_list = array_keys($candidates);
        $mail_to_ip_tmp = [];

        // pdc_usuarios.mail → pdc_accesos.IP (todo en la DB externa, sin pasar por wp_users)
        try {
            $pdo_acc = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user_db,
                $ext_pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
            );
            $ph_c = implode(',', array_fill(0, count($cand_list), '?'));
            $stmt_acc = $pdo_acc->prepare(
                "SELECT LOWER(TRIM(U.mail)) AS mail, A.IP
                 FROM pdc_usuarios U
                 INNER JOIN pdc_accesos A ON A.ID_alumno = U.ID
                 WHERE LOWER(TRIM(U.mail)) IN ($ph_c)
                   AND A.IP IS NOT NULL AND A.IP != ''
                 ORDER BY A.hora DESC"
            );
            $stmt_acc->execute($cand_list);
            foreach ($stmt_acc->fetchAll(PDO::FETCH_ASSOC) as $_a) {
                $mail = strtolower(trim($_a['mail']));
                if ($mail !== '' && !isset($mail_to_ip_tmp[$mail]))
                    $mail_to_ip_tmp[$mail] = trim($_a['IP']);
            }
            $pdo_acc = null;
        } catch (Exception $_e) {
            $_dbg['pdo_error'] = $_e->getMessage();
        }
        $_dbg['ips_accesos'] = count($mail_to_ip_tmp);

        if (!empty($mail_to_ip_tmp)) {
            $all_ips = array_unique(array_values($mail_to_ip_tmp));
            $ph_ip = implode(',', array_fill(0, count($all_ips), '%s'));
            $vis_utms = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT V.ip, V.ref_s, V.ref_m, V.ref_c
                     FROM {$table_visitas} V
                     INNER JOIN (
                         SELECT ip, MAX(fecha) AS mf FROM {$table_visitas}
                         WHERE ip IN ($ph_ip) AND (ref_s != '' OR ref_m != '' OR ref_c != '')
                         GROUP BY ip
                     ) T ON V.ip = T.ip AND V.fecha = T.mf
                     WHERE V.ip IN ($ph_ip)",
                    ...array_merge($all_ips, $all_ips)
                )
            );
            $ip_to_utm = [];
            foreach ($vis_utms as $_vr) {
                $ip_to_utm[$_vr->ip] = ['source' => $_vr->ref_s ?: '', 'medium' => $_vr->ref_m ?: '', 'campaign' => $_vr->ref_c ?: ''];
            }
            $_dbg['ips_con_utm'] = count($ip_to_utm);
            foreach ($mail_to_ip_tmp as $mail => $ip) {
                $wp_ip_attr[$mail] = array_merge(
                    ['ip' => $ip, 'source' => '', 'medium' => 'none', 'campaign' => '(sin campaña)'],
                    $ip_to_utm[$ip] ?? []
                );
            }
        }
    }
}

// ============================================================
// 6. AGGREGATE DATA
// ============================================================
$total_ventas_count = 0;
$total_revenue = 0.0;

$by_source = [];
$by_medium = [];
$by_campaign = [];
$by_pais = [];
$by_afiliado = [];
$by_day = [];
$by_producto = [];
$by_plataforma = [];
$by_metodo = [];
$by_cuotas = [];
$by_cuotas_region = ['ES' => [], 'EEUU' => [], 'LATAM' => [], 'Resto' => []];

if (!function_exists('get_region_for_country')) {
    function get_region_for_country($country) {
        $c = strtolower(trim((string) $country));
        $es   = ['spain', 'españa'];
        $eeuu = ['united states', 'estados unidos', 'us'];
        $latam = ['mexico','méxico','colombia','argentina','chile','peru','perú','venezuela',
                  'ecuador','bolivia','paraguay','uruguay','panama','panamá','guatemala',
                  'honduras','el salvador','nicaragua','costa rica','dominican republic',
                  'república dominicana','cuba','puerto rico','belize','belice'];
        if (in_array($c, $es))    return 'ES';
        if (in_array($c, $eeuu))  return 'EEUU';
        if (in_array($c, $latam)) return 'LATAM';
        return 'Resto';
    }
}

$alumni_cutoff = '2026-04-12 19:30:00';
$by_day_alumnos = [];
$by_day_no_alumnos = [];
$counted_alumnos = [];
$counted_no_alumnos = [];

// Init registrados by dimension
foreach ($regs_rows as $r) {
    $src = $r->source ?: 'Sin registro';
    $med = $r->medium ?: 'none';
    $cam = $r->campaign ?: '(sin campaña)';
    $pai = endtrack_normalize_pais($r->pais);
    $aff = intval($r->afiliado);
    $day = substr($r->fecha, 0, 10);

    if (!isset($by_source[$src]))
        $by_source[$src] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_medium[$med]))
        $by_medium[$med] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_campaign[$cam]))
        $by_campaign[$cam] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_pais[$pai]))
        $by_pais[$pai] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_day[$day]))
        $by_day[$day] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];

    $by_source[$src]['regs']++;
    $by_medium[$med]['regs']++;
    $by_campaign[$cam]['regs']++;
    $by_pais[$pai]['regs']++;
    $by_day[$day]['regs']++;

    if ($aff > 0) {
        if (!isset($by_afiliado[$aff]))
            $by_afiliado[$aff] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
        $by_afiliado[$aff]['regs']++;
    }
}

// Process ventas — deduplicate buyers for count, accumulate all revenue rows
$counted_buyers = [];
$counted_licencia = []; // pago_licencia = 1
$mail_enriched_attr = []; // stores final attr per buyer — used to subtract devoluciones from dimension buckets
$mail_paid = []; // total pagado (Completado/Compra) por comprador en el rango — para el criterio de devoluciones
$raw_sales_rows = [];
foreach ($ventas_rows as $v) {
    $mail = strtolower(trim((string) $v['mail']));
    $attr = isset($mail_to_attr[$mail]) ? $mail_to_attr[$mail] : null;
    $pdc_ref_raw = trim((string) $v['pdc_ref']);   // string — puede ser ID numérico o código de afiliado
    $pdc_ref_int = intval($pdc_ref_raw);            // para $by_afiliado (WP user ID numérico)
    $pdc_src = trim((string) $v['pdc_source']);
    $pdc_med = trim((string) $v['pdc_medium']);
    $pdc_cam = trim((string) $v['pdc_campaign']);

    // Si source está vacío, inferir desde medium o ref
    if ($pdc_src === '' && $pdc_med !== '') {
        $med_low = strtolower($pdc_med);
        if (in_array($med_low, ['meta', 'fb', 'facebook', 'instagram', 'ig', 'googleads', 'gads', 'google', 'cpc', 'ppc', 'sem']))
            $pdc_src = 'publicidad';
        elseif (in_array($med_low, ['email', 'newsletter', 'mailing']))
            $pdc_src = 'email';
        elseif (in_array($med_low, ['organico', 'organic', 'seo']))
            $pdc_src = 'organico';
    }
    if ($pdc_src === '' && $pdc_ref_raw !== '')
        $pdc_src = 'afiliado';

    // Helper: source que NO necesita enriquecerse (valores reales de UTM conocidos)
    // Todo lo demás (vacío, 'directo', 'sin registro', 'no tiene fuente', etc.) se enriquece desde pdc_pagos
    $known_sources = ['organico', 'publicidad', 'afiliado', 'email', 'sms', 'whatsapp', 'referral', 'referido'];
    $needs_enrich = function ($s) use ($known_sources) {
        return !in_array(strtolower(trim((string) $s)), $known_sources, true);
    };

    if (!$attr) {
        // Comprador no está en wp_datos — usar pdc_pagos íntegramente
        $attr = [
            'source' => $pdc_src !== '' ? $pdc_src : 'directo',
            'medium' => $pdc_med !== '' ? $pdc_med : 'none',
            'campaign' => $pdc_cam !== '' ? $pdc_cam : '(sin campaña)',
            'pais' => 'Desconocido',
            'afiliado' => $pdc_ref_int,
            'ip' => '',
            'url_actual' => '',
        ];
    } elseif ($needs_enrich($attr['source'])) {
        // En wp_datos pero source vacío/directo/sin registro — enriquecer desde pdc_pagos
        if ($pdc_src !== '')
            $attr['source'] = $pdc_src;
        if ($pdc_med !== '')
            $attr['medium'] = $pdc_med;
        if ($pdc_cam !== '')
            $attr['campaign'] = $pdc_cam;
        if ($pdc_ref_int > 0 && $attr['afiliado'] == 0)
            $attr['afiliado'] = $pdc_ref_int;
        // Si pdc_pagos tampoco tiene source, dejar 'directo' como última opción
        if (trim($attr['source']) === '' || strtolower($attr['source']) === 'sin registro')
            $attr['source'] = 'directo';
    }

    // Última capa: sin UTM en pdc_pagos y source inválido → wp_visitas via pdc_accesos IP
    if (isset($wp_ip_attr[$mail])) {
        $wpa = $wp_ip_attr[$mail];
        if ($needs_enrich($attr['source'])) {
            if ($wpa['source'] !== '') {
                $attr['source'] = $wpa['source'];
                $_dbg['aplicados']++;
            }
            if ($wpa['medium'] !== 'none')
                $attr['medium'] = $wpa['medium'];
            if ($wpa['campaign'] !== '(sin campaña)')
                $attr['campaign'] = $wpa['campaign'];
        }
        if (empty($attr['ip']))
            $attr['ip'] = $wpa['ip'];
    }

    $imp = floatval($v['importe']);
    $day = $v['fecha_venta'];
    $src = $attr['source'];
    $med = $attr['medium'];
    $cam = $attr['campaign'];
    // País del comprador: prioriza pdc_pagos.pais (dato real de la compra);
    // el geolocalizado en wp_datos puede ser falso (IP del servidor por caché → 'Germany').
    $pai = trim((string) $v['pdc_pais']) !== '' ? trim((string) $v['pdc_pais']) : $attr['pais'];
    $pai = endtrack_normalize_pais($pai);
    $aff = $attr['afiliado'];
    $prod = $v['producto'] ?: 'Producto General';

    // Ensure dimension buckets exist (may be absent if source only appears in pdc_pagos)
    if (!isset($by_source[$src]))
        $by_source[$src] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_medium[$med]))
        $by_medium[$med] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_campaign[$cam]))
        $by_campaign[$cam] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
    if (!isset($by_pais[$pai]))
        $by_pais[$pai] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];

    // Ensure $by_day bucket exists for every revenue row (cuotas can have different dates)
    if (!isset($by_day[$day]))
        $by_day[$day] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];

    // Buyer count (once per mail)
    if (!isset($counted_buyers[$mail])) {
        $counted_buyers[$mail] = true;
        $total_ventas_count++;
        if (intval($v['pago_licencia']) === 1)
            $counted_licencia[$mail] = true;
        $mail_enriched_attr[$mail] = ['src' => $src, 'med' => $med, 'cam' => $cam, 'pai' => $pai, 'aff' => $aff, 'day' => $day];
        $by_source[$src]['ventas']++;
        $by_medium[$med]['ventas']++;
        $by_campaign[$cam]['ventas']++;
        $by_pais[$pai]['ventas']++;
        if ($aff > 0) {
            if (!isset($by_afiliado[$aff]))
                $by_afiliado[$aff] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
            $by_afiliado[$aff]['ventas']++;
        }
        $by_day[$day]['ventas']++;
    }

    // Revenue
    $total_revenue += $imp;
    $mail_paid[$mail] = (isset($mail_paid[$mail]) ? $mail_paid[$mail] : 0.0) + $imp;
    $by_source[$src]['revenue'] += $imp;
    $by_medium[$med]['revenue'] += $imp;
    $by_campaign[$cam]['revenue'] += $imp;
    $by_pais[$pai]['revenue'] += $imp;
    if ($aff > 0) {
        if (!isset($by_afiliado[$aff]))
            $by_afiliado[$aff] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
        $by_afiliado[$aff]['revenue'] += $imp;
    }
    $by_day[$day]['revenue'] += $imp;

    // Alumni split — classify each buyer only once (same first-occurrence logic as counted_buyers)
    $fecha_dt = isset($v['fecha_datetime']) ? (string) $v['fecha_datetime'] : ($day . ' 00:00:00');
    $is_alumno = ($fecha_dt < $alumni_cutoff);
    $already_classified = isset($counted_alumnos[$mail]) || isset($counted_no_alumnos[$mail]);
    if ($is_alumno) {
        if (!isset($by_day_alumnos[$day]))
            $by_day_alumnos[$day] = ['ventas' => 0, 'revenue' => 0.0];
        $by_day_alumnos[$day]['revenue'] += $imp;
        if (!$already_classified) {
            $counted_alumnos[$mail] = true;
            $by_day_alumnos[$day]['ventas']++;
        }
    } else {
        if (!isset($by_day_no_alumnos[$day]))
            $by_day_no_alumnos[$day] = ['ventas' => 0, 'revenue' => 0.0];
        $by_day_no_alumnos[$day]['revenue'] += $imp;
        if (!$already_classified) {
            $counted_no_alumnos[$mail] = true;
            $by_day_no_alumnos[$day]['ventas']++;
        }
    }

    if (!isset($by_producto[$prod]))
        $by_producto[$prod] = ['ventas' => 0, 'revenue' => 0.0];
    $by_producto[$prod]['revenue'] += $imp;
    $by_producto[$prod]['ventas']++;

    $plat = $v['plataforma'] ?: 'Desconocida';
    if (!isset($by_plataforma[$plat]))
        $by_plataforma[$plat] = ['ventas' => 0, 'revenue' => 0.0];
    $by_plataforma[$plat]['revenue'] += $imp;
    // Count buyers per plataforma (deduplicate by mail+plat)
    if (!isset($counted_buyers[$mail . '|plat|' . $plat])) {
        $counted_buyers[$mail . '|plat|' . $plat] = true;
        $by_plataforma[$plat]['ventas']++;
    }

    $met = $v['metodo'] ?: 'Desconocido';
    if (!isset($by_metodo[$met]))
        $by_metodo[$met] = ['ventas' => 0, 'revenue' => 0.0];
    $by_metodo[$met]['revenue'] += $imp;
    if (!isset($counted_buyers[$mail . '|met|' . $met])) {
        $counted_buyers[$mail . '|met|' . $met] = true;
        $by_metodo[$met]['ventas']++;
    }

    $cuota_plan = intval($v['cuotas_plan']) ?: 1;
    if ($cuota_plan === 2) $cuota_plan = 3;
    if ($cuota_plan === 5) $cuota_plan = 6;
    if (!isset($by_cuotas[$cuota_plan]))
        $by_cuotas[$cuota_plan] = ['ventas' => 0, 'revenue' => 0.0];
    $by_cuotas[$cuota_plan]['revenue'] += $imp;
    if (!isset($counted_buyers[$mail . '|cuota|' . $cuota_plan])) {
        $counted_buyers[$mail . '|cuota|' . $cuota_plan] = true;
        $by_cuotas[$cuota_plan]['ventas']++;
    }
    $region = get_region_for_country($pai);
    if (!isset($by_cuotas_region[$region][$cuota_plan]))
        $by_cuotas_region[$region][$cuota_plan] = 0;
    if (!isset($counted_buyers[$mail . '|cuota_reg|' . $region . '|' . $cuota_plan])) {
        $counted_buyers[$mail . '|cuota_reg|' . $region . '|' . $cuota_plan] = true;
        $by_cuotas_region[$region][$cuota_plan]++;
    }

    // Fila para la tabla de detalle de ventas (url_actual e ip vienen de wp_datos)
    $url_raw = isset($attr['url_actual']) ? $attr['url_actual'] : '';
    $ip_raw = isset($attr['ip']) ? $attr['ip'] : '';
    $raw_sales_rows[] = [
        $mail,
        esc_html($src),
        esc_html($med),
        esc_html($cam),
        esc_html((string) $attr['afiliado']),
        $day,
        esc_html($url_raw),
        esc_html($ip_raw),
        fmt_eur($imp),
    ];
}

// ── Reembolsos y Devoluciones ────────────────────────────────
// Criterio (igual que /ventas-completas/): un comprador solo cuenta como
// reembolso/devolución si su saldo neto queda a ~0€ (total pagado en el
// rango menos total reembolsado). Los reembolsos de pagos duplicados o
// parciales (el comprador sigue con saldo positivo) restan de los
// ingresos netos pero NO cuentan como devolución.
// reembolso=1 → Reembolso real | reembolso=0/NULL → Devolución
$refund_mails = []; // reembolso = 1
$devolucion_mails = []; // reembolso = 0 / NULL
$refund_revenue = 0.0;
$devolucion_revenue = 0.0;
$ajuste_refund_revenue = 0.0; // reembolsos de duplicados/parciales (comprador con saldo > 0)
$_refunds_by_mail = [];
foreach ($refund_rows as $r) {
    $mail = strtolower(trim((string) $r['mail']));
    if (!isset($counted_buyers[$mail]))
        continue;
    if (!isset($_refunds_by_mail[$mail]))
        $_refunds_by_mail[$mail] = ['imp' => 0.0, 'flag1' => false];
    $_refunds_by_mail[$mail]['imp'] += abs(floatval($r['importe']));
    if (intval($r['reembolso']) === 1)
        $_refunds_by_mail[$mail]['flag1'] = true;
}
foreach ($_refunds_by_mail as $mail => $rf) {
    $paid = isset($mail_paid[$mail]) ? $mail_paid[$mail] : 0.0;
    if ($paid - $rf['imp'] >= 0.01) {
        // Saldo neto positivo → sigue siendo venta (pago duplicado o reembolso parcial)
        $ajuste_refund_revenue += $rf['imp'];
        continue;
    }
    if ($rf['flag1']) {
        $refund_mails[$mail] = true;
        $refund_revenue += $rf['imp'];
    } else {
        $devolucion_mails[$mail] = true;
        $devolucion_revenue += $rf['imp'];
    }
}
$total_reembolsos = count($refund_mails);
$total_devoluciones = count($devolucion_mails);
$total_ventas_grupales = count($counted_licencia);
// Ventas finales: reembolsos cuentan como ventas, solo se restan devoluciones
$ventas_finales = $total_ventas_count - $total_devoluciones;
$ingresos_netos = $total_revenue - $refund_revenue - $devolucion_revenue - $ajuste_refund_revenue;

// Restar devoluciones de todos los buckets de dimensión (gráficas de abajo)
foreach (array_keys($devolucion_mails) as $_dev_mail) {
    if (!isset($mail_enriched_attr[$_dev_mail])) continue;
    $_ea = $mail_enriched_attr[$_dev_mail];
    if (isset($by_source[$_ea['src']])   && $by_source[$_ea['src']]['ventas']   > 0) $by_source[$_ea['src']]['ventas']--;
    if (isset($by_medium[$_ea['med']])   && $by_medium[$_ea['med']]['ventas']   > 0) $by_medium[$_ea['med']]['ventas']--;
    if (isset($by_campaign[$_ea['cam']]) && $by_campaign[$_ea['cam']]['ventas'] > 0) $by_campaign[$_ea['cam']]['ventas']--;
    if (isset($by_pais[$_ea['pai']])     && $by_pais[$_ea['pai']]['ventas']     > 0) $by_pais[$_ea['pai']]['ventas']--;
    if (isset($by_day[$_ea['day']])      && $by_day[$_ea['day']]['ventas']      > 0) $by_day[$_ea['day']]['ventas']--;
    if ($_ea['aff'] > 0 && isset($by_afiliado[$_ea['aff']]) && $by_afiliado[$_ea['aff']]['ventas'] > 0)
        $by_afiliado[$_ea['aff']]['ventas']--;
    if (isset($counted_alumnos[$_dev_mail])) {
        if (isset($by_day_alumnos[$_ea['day']]) && $by_day_alumnos[$_ea['day']]['ventas'] > 0)
            $by_day_alumnos[$_ea['day']]['ventas']--;
    } elseif (isset($counted_no_alumnos[$_dev_mail])) {
        if (isset($by_day_no_alumnos[$_ea['day']]) && $by_day_no_alumnos[$_ea['day']]['ventas'] > 0)
            $by_day_no_alumnos[$_ea['day']]['ventas']--;
    }
}

$total_ventas_alumnos = count($counted_alumnos);
$total_ventas_no_alumnos = count($counted_no_alumnos);

// Reembolsos y devoluciones por alumno/no-alumno
$reembolsos_alumnos = count(array_intersect_key($refund_mails, $counted_alumnos));
$reembolsos_no_alumnos = count(array_intersect_key($refund_mails, $counted_no_alumnos));
$devoluciones_alumnos = count(array_intersect_key($devolucion_mails, $counted_alumnos));
$devoluciones_no_alumnos = count(array_intersect_key($devolucion_mails, $counted_no_alumnos));

$cvr = ($total_regs > 0) ? ($total_ventas_count / $total_regs) * 100 : 0;

// Sort by revenue desc
uasort($by_source, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_medium, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_campaign, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_pais, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_afiliado, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_producto, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_plataforma, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_metodo, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
ksort($by_cuotas);
ksort($by_day);
ksort($by_day_alumnos);
ksort($by_day_no_alumnos);

// ============================================================
// 6b. FILTERED AGGREGATIONS for País and Medio quick-filters
// ============================================================
function src_filter_ok_v($source, $afiliado, $filter)
{
    if ($filter === 'todo')
        return true;
    $s = strtolower(trim((string) $source));
    if ($filter === 'organico')
        return $s === 'organico';
    if ($filter === 'publicidad')
        return in_array($s, ['publicidad', 'Publicidad']);
    if ($filter === 'afiliado')
        return intval($afiliado) > 0;
    return true;
}

$by_pais_f = [];
$by_medium_f = [];

// Registrados contribution
foreach ($regs_rows as $r) {
    $src = $r->source ?: 'Sin registro';
    $aff = intval($r->afiliado);
    if (src_filter_ok_v($src, $aff, $s_pais)) {
        $pai = endtrack_normalize_pais($r->pais);
        if (!isset($by_pais_f[$pai]))
            $by_pais_f[$pai] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
        $by_pais_f[$pai]['regs']++;
    }
    if (src_filter_ok_v($src, $aff, $s_medio)) {
        $med = $r->medium ?: 'none';
        if (!isset($by_medium_f[$med]))
            $by_medium_f[$med] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
        $by_medium_f[$med]['regs']++;
    }
}

// Ventas contribution
$cnt_pf   = [];
$cnt_mf   = [];
$cnt_pub  = [];   // dedup para by_pais_pub
$by_pais_pub = []; // solo ventas con source = publicidad (para ROAS por país)
foreach ($ventas_rows as $v) {
    $mail = strtolower(trim((string) $v['mail']));
    $_pref_r = trim((string) $v['pdc_ref']);
    $_pref = intval($_pref_r);
    $_psrc = trim((string) $v['pdc_source']);
    $_pmed = trim((string) $v['pdc_medium']);
    // Inferir source desde medium o ref
    if ($_psrc === '' && $_pmed !== '') {
        $_ml = strtolower($_pmed);
        if (in_array($_ml, ['meta', 'fb', 'facebook', 'instagram', 'ig', 'googleads', 'gads', 'google', 'cpc', 'ppc', 'sem']))
            $_psrc = 'publicidad';
        elseif (in_array($_ml, ['email', 'newsletter', 'mailing']))
            $_psrc = 'email';
        elseif (in_array($_ml, ['organico', 'organic', 'seo']))
            $_psrc = 'organico';
    }
    if ($_psrc === '' && $_pref_r !== '')
        $_psrc = 'afiliado';
    if (isset($mail_to_attr[$mail])) {
        $attr = $mail_to_attr[$mail];
        $_known = ['organico', 'publicidad', 'afiliado', 'email', 'sms', 'whatsapp', 'referral', 'referido'];
        if (!in_array(strtolower(trim((string) $attr['source'])), $_known, true)) {
            if ($_psrc !== '')
                $attr['source'] = $_psrc;
            if ($_pmed !== '')
                $attr['medium'] = $_pmed;
            if (trim($attr['source']) === '' || !in_array(strtolower($attr['source']), $_known, true))
                $attr['source'] = 'directo';
        }
    } else {
        $attr = ['source' => ($_psrc !== '' ? $_psrc : 'directo'), 'medium' => $_pmed ?: 'none', 'pais' => 'Desconocido', 'afiliado' => $_pref];
    }
    $imp = floatval($v['importe']);

    if (src_filter_ok_v($attr['source'], $attr['afiliado'], $s_pais)) {
        $pai = trim((string) $v['pdc_pais']) !== '' ? trim((string) $v['pdc_pais']) : $attr['pais'];
        $pai = endtrack_normalize_pais($pai);
        if (!isset($by_pais_f[$pai]))
            $by_pais_f[$pai] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
        $by_pais_f[$pai]['revenue'] += $imp;
        if (!isset($cnt_pf[$mail])) {
            $cnt_pf[$mail] = true;
            $by_pais_f[$pai]['ventas']++;
        }
    }
    // Acumular solo publicidad para ROAS por país
    if (in_array(strtolower(trim((string) $attr['source'])), ['publicidad'], true)) {
        $pai_pub = trim((string) $v['pdc_pais']) !== '' ? trim((string) $v['pdc_pais']) : $attr['pais'];
        $pai_pub = endtrack_normalize_pais($pai_pub);
        if (!isset($by_pais_pub[$pai_pub]))
            $by_pais_pub[$pai_pub] = ['ventas' => 0, 'revenue' => 0.0];
        $by_pais_pub[$pai_pub]['revenue'] += $imp;
        if (!isset($cnt_pub[$mail])) {
            $cnt_pub[$mail] = true;
            $by_pais_pub[$pai_pub]['ventas']++;
        }
    }

    if (src_filter_ok_v($attr['source'], $attr['afiliado'], $s_medio)) {
        $med = $attr['medium'];
        if (!isset($by_medium_f[$med]))
            $by_medium_f[$med] = ['regs' => 0, 'ventas' => 0, 'revenue' => 0.0];
        $by_medium_f[$med]['revenue'] += $imp;
        if (!isset($cnt_mf[$mail])) {
            $cnt_mf[$mail] = true;
            $by_medium_f[$med]['ventas']++;
        }
    }
}
uasort($by_pais_f, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});
uasort($by_medium_f, function ($a, $b) {
    return $b['revenue'] <=> $a['revenue'];
});

// ============================================================
// 7. HELPERS
// ============================================================
function get_affiliate_name_v($id)
{
    static $cache = [];
    $id = trim((string) $id);
    if (empty($id) || $id === '0')
        return 'Sin afiliado';
    if (!isset($cache[$id])) {
        $u = get_userdata((int) $id);
        $cache[$id] = $u ? $u->display_name : "ID: {$id}";
    }
    return $cache[$id];
}

function fmt_eur($v)
{
    return number_format(floatval($v), 2, ',', '.') . ' €';
}
function fmt_pct($v)
{
    return number_format(floatval($v), 1, ',', '.') . '%';
}

// ============================================================
// 8. BUILD $dashboard ARRAY
// ============================================================
$dashboard = [];

// Helper: build a table row array for a dimension
function build_dim_table($data_arr, $label_fn = null)
{
    $rows = [];
    foreach ($data_arr as $dim => $d) {
        $lbl = $label_fn ? call_user_func($label_fn, $dim) : $dim;
        $cv = ($d['regs'] > 0) ? ($d['ventas'] / $d['regs']) * 100 : 0;
        $rows[] = [
            $lbl,
            number_format($d['regs'], 0, ',', '.'),
            number_format($d['ventas'], 0, ',', '.'),
            number_format($cv, 1, ',', '.') . '%',
            fmt_eur($d['revenue']),
        ];
    }
    return $rows;
}
$dim_headers = ['Dimensión', 'Registrados', 'Ventas', 'Conversión', 'Ingresos'];

// ── Section 1: KPIs ──────────────────────────────────────────
$dashboard['sections'][] = [
    'id' => 'sec_kpi',
    'title' => '1. KPIs Ventas',
    'icon' => 'fa-tachometer-alt',
    'layout' => 'kpi_grid',
    'items' => [
        // ── Bloque 1: Conteo ventas ──────────────────────────────
        ['type' => 'kpi_divider', 'title' => 'Conteo de ventas'],
        ['type' => 'kpi', 'title' => 'Registrados', 'value' => number_format($total_regs, 0, ',', '.'), 'color' => 'purple'],
        ['type' => 'kpi', 'title' => 'Ventas Brutas', 'value' => number_format($total_ventas_count, 0, ',', '.'), 'color' => 'green'],
        ['type' => 'kpi', 'title' => 'Ventas Grupales', 'value' => number_format($total_ventas_grupales, 0, ',', '.'), 'color' => 'teal'],
        ['type' => 'kpi', 'title' => 'Reembolsos', 'value' => number_format($total_reembolsos, 0, ',', '.'), 'color' => 'red'],
        ['type' => 'kpi', 'title' => 'Devoluciones', 'value' => number_format($total_devoluciones, 0, ',', '.'), 'color' => 'orange'],
        ['type' => 'kpi', 'title' => 'Ventas Finales', 'value' => number_format($ventas_finales, 0, ',', '.'), 'color' => 'featured'],
        ['type' => 'kpi', 'title' => 'Conversión R→V', 'value' => fmt_pct($cvr), 'color' => 'orange'],
        // ── Bloque 2: Alumnos ────────────────────────────────────
        ['type' => 'kpi_divider', 'title' => 'Desglose alumnos'],
        ['type' => 'kpi', 'title' => 'Ventas Alumnos', 'value' => number_format($total_ventas_alumnos, 0, ',', '.'), 'color' => 'amber'],
        ['type' => 'kpi', 'title' => 'Reembolsos Alumnos', 'value' => number_format($reembolsos_alumnos, 0, ',', '.'), 'color' => 'red'],
        ['type' => 'kpi', 'title' => 'Devoluciones Alumnos', 'value' => number_format($devoluciones_alumnos, 0, ',', '.'), 'color' => 'orange'],
        ['type' => 'kpi', 'title' => 'Ventas No Alumnos', 'value' => number_format($total_ventas_no_alumnos, 0, ',', '.'), 'color' => 'teal'],
        ['type' => 'kpi', 'title' => 'Reembolsos No Alumnos', 'value' => number_format($reembolsos_no_alumnos, 0, ',', '.'), 'color' => 'red'],
        ['type' => 'kpi', 'title' => 'Devoluciones No Alumnos', 'value' => number_format($devoluciones_no_alumnos, 0, ',', '.'), 'color' => 'orange'],
        // ── Bloque 3: Importes € ─────────────────────────────────
        ['type' => 'kpi_divider', 'title' => 'Importes €'],
        ['type' => 'kpi', 'title' => 'Ingresos Brutos', 'value' => fmt_eur($total_revenue), 'color' => 'pink', 'small' => true],
        ['type' => 'kpi', 'title' => 'Importe Devoluciones', 'value' => fmt_eur($devolucion_revenue), 'color' => 'orange', 'small' => true],
        ['type' => 'kpi', 'title' => 'Importe Reembolsos', 'value' => fmt_eur($refund_revenue), 'color' => 'red', 'small' => true],
        ['type' => 'kpi', 'title' => 'Ingresos Netos', 'value' => fmt_eur($ingresos_netos), 'color' => 'teal', 'small' => true],
        // ── Funnel ───────────────────────────────────────────────
        [
            'type' => 'funnel',
            'title' => 'Funnel Registrados → Ventas',
            'data' => [
                ['label' => 'Registrados', 'value' => $total_regs],
                ['label' => 'Ventas brutas', 'value' => $total_ventas_count],
                ['label' => 'Ventas finales', 'value' => $ventas_finales],
            ]
        ],
    ],
];

// ── Section 2: Evolución Diaria (moved up, ventas only) ──────
function build_day_series($by_day_arr)
{
    $labels = [];
    $ventas = [];
    $revenue = [];
    foreach ($by_day_arr as $day => $d) {
        $labels[] = $day;
        $ventas[] = $d['ventas'];
        $revenue[] = round($d['revenue'], 2);
    }
    return ['labels' => $labels, 'ventas' => $ventas, 'revenue' => $revenue];
}
$ds_todos = build_day_series($by_day);
$ds_alumnos = build_day_series($by_day_alumnos);
$ds_no_alumnos = build_day_series($by_day_no_alumnos);

$dashboard['sections'][] = [
    'id' => 'sec_daily',
    'title' => '2. Evolución Diaria',
    'icon' => 'fa-chart-line',
    'layout' => 'two_col',
    'day_filter' => true,
    'day_datasets' => [
        'todos' => $ds_todos,
        'alumnos' => $ds_alumnos,
        'no_alumnos' => $ds_no_alumnos,
    ],
    'items' => [
        [
            'type' => 'line',
            'id' => 'chart_daily_ventas',
            'title' => 'Ventas por Día',
            'labels' => $ds_todos['labels'],
            'datasets' => [
                ['label' => 'Ventas', 'data' => $ds_todos['ventas'], 'borderColor' => '#10b981', 'backgroundColor' => '#10b98133', 'borderWidth' => 2, 'fill' => true, 'tension' => 0.4],
            ],
        ],
        [
            'type' => 'bar',
            'id' => 'chart_daily_revenue',
            'title' => 'Ingresos por Día (€)',
            'labels' => $ds_todos['labels'],
            'data' => $ds_todos['revenue'],
            'color' => '#10b981',
        ],
    ],
];

// ── Section 3: Por Fuente ────────────────────────────────────
$src_pie_labels = [];
$src_pie_data = [];
$src_colors = [];
$src_color_map = ['organico' => '#10b981', 'publicidad' => '#facc15', 'Sin registro' => '#6366f1'];
foreach ($by_source as $s => $d) {
    $src_pie_labels[] = $s;
    $src_pie_data[] = $d['ventas'];
    $src_colors[] = $src_color_map[strtolower($s)] ?? '#64748b';
}
$dashboard['sections'][] = [
    'id' => 'sec_source',
    'title' => '3. Por Fuente',
    'icon' => 'fa-compass',
    'layout' => 'two_col',
    'items' => [
        ['type' => 'table', 'id' => 'tbl_source', 'title' => 'Registrados y Ventas por Fuente', 'headers' => $dim_headers, 'data' => build_dim_table($by_source)],
        ['type' => 'pie', 'title' => 'Ventas por Fuente', 'labels' => $src_pie_labels, 'data' => $src_pie_data, 'colors' => $src_colors],
    ],
];

// ── Section 4: Por País (with quick source filter) ───────────
$pais_pie_labels = [];
$pais_pie_data = [];
foreach (array_slice($by_pais_f, 0, 10) as $p => $d) {
    $pais_pie_labels[] = $p;
    $pais_pie_data[] = $d['ventas'];
}
$dashboard['sections'][] = [
    'id' => 'sec_country',
    'title' => '4. Por País',
    'icon' => 'fa-globe',
    'layout' => 'two_col',
    'quick_filter' => ['key' => 's_pais', 'current' => $s_pais, 'options' => $_filter_opts],
    'items' => [
        ['type' => 'table', 'id' => 'tbl_pais', 'title' => 'Registrados y Ventas por País', 'headers' => $dim_headers, 'data' => build_dim_table($by_pais_f)],
        [
            'type' => 'pie',
            'title' => 'Ventas por País',
            'labels' => $pais_pie_labels,
            'data' => $pais_pie_data,
            'colors' => ['#6366f1', '#10b981', '#facc15', '#ec4899', '#3b82f6', '#f97316', '#8b5cf6', '#0ea5e9', '#14b8a6', '#d946ef']
        ],
    ],
];

// ── Section 5: Por Medio (with quick source filter) ──────────
$med_bar_labels = array_keys(array_slice($by_medium_f, 0, 15));
$med_bar_data = [];
foreach (array_slice($by_medium_f, 0, 15) as $d)
    $med_bar_data[] = $d['ventas'];
$dashboard['sections'][] = [
    'id' => 'sec_medium',
    'title' => '5. Por Medio',
    'icon' => 'fa-bullseye',
    'layout' => 'two_col',
    'quick_filter' => ['key' => 's_medio', 'current' => $s_medio, 'options' => $_filter_opts],
    'items' => [
        ['type' => 'table', 'id' => 'tbl_medium', 'title' => 'Registrados y Ventas por Medio', 'headers' => $dim_headers, 'data' => build_dim_table($by_medium_f)],
        ['type' => 'bar', 'title' => 'Ventas por Medio', 'labels' => $med_bar_labels, 'data' => $med_bar_data, 'color' => '#a855f7'],
    ],
];

// ── Section 6: Por Campaña ───────────────────────────────────
$dashboard['sections'][] = [
    'id' => 'sec_campaign',
    'title' => '6. Por Campaña',
    'icon' => 'fa-flag',
    'layout' => 'two_col',
    'items' => [
        ['type' => 'table', 'id' => 'tbl_campaign', 'title' => 'Registrados y Ventas por Campaña', 'headers' => $dim_headers, 'data' => build_dim_table(array_slice($by_campaign, 0, 30))],
    ],
];

// ── Section 7: Por Afiliado ──────────────────────────────────
$aff_rows = [];
foreach ($by_afiliado as $aff_id => $d) {
    $cv = ($d['regs'] > 0) ? ($d['ventas'] / $d['regs']) * 100 : 0;
    $comm_rate = get_user_meta($aff_id, 'endtrack_commission_rate', true);
    if ($comm_rate === '')
        $comm_rate = 20;
    $comm = $d['revenue'] * 0.96 * (floatval($comm_rate) / 100);
    $aff_rows[] = [
        get_affiliate_name_v($aff_id),
        number_format($d['regs'], 0, ',', '.'),
        number_format($d['ventas'], 0, ',', '.'),
        fmt_pct($cv),
        fmt_eur($d['revenue']),
        fmt_eur($comm) . ' (' . $comm_rate . '%)',
    ];
}
$dashboard['sections'][] = [
    'id' => 'sec_affiliates',
    'title' => '7. Afiliados',
    'icon' => 'fa-users',
    'layout' => 'two_col',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_affiliates',
            'title' => 'Registrados, Ventas y Comisiones por Afiliado',
            'headers' => ['Afiliado', 'Registrados', 'Ventas', 'Conversión', 'Ingresos', 'Comisión Est.'],
            'data' => $aff_rows
        ],
    ],
];

// ── Section 8: Por Plataforma ────────────────────────────────
$plat_rows = [];
$plat_labels = [];
$plat_data = [];
$plat_colors = ['#6366f1', '#10b981', '#facc15', '#ec4899', '#3b82f6', '#f97316', '#8b5cf6'];
$plat_idx = 0;
foreach ($by_plataforma as $plat => $d) {
    $plat_rows[] = [esc_html($plat), number_format($d['ventas'], 0, ',', '.'), fmt_eur($d['revenue'])];
    $plat_labels[] = $plat;
    $plat_data[] = $d['ventas'];
    $plat_idx++;
}
$dashboard['sections'][] = [
    'id' => 'sec_plataforma',
    'title' => '8. Por Plataforma',
    'icon' => 'fa-credit-card',
    'layout' => 'two_col',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_plataforma',
            'title' => 'Ventas e Ingresos por Plataforma',
            'headers' => ['Plataforma', 'Ventas', 'Ingresos'],
            'data' => $plat_rows
        ],
        ['type' => 'pie', 'title' => 'Ventas por Plataforma', 'labels' => $plat_labels, 'data' => $plat_data, 'colors' => $plat_colors],
    ],
];

// ── Section 9: Por Plan de Cuotas ────────────────────────────
$cuota_rows = [];
$cuota_labels = [];
$cuota_data = [];
$cuota_colors = ['#10b981', '#facc15', '#6366f1', '#ec4899', '#3b82f6'];
$ci = 0;
$total_cuota_ventas = array_sum(array_column($by_cuotas, 'ventas'));
foreach ($by_cuotas as $plan => $d) {
    $pct = ($total_cuota_ventas > 0) ? round($d['ventas'] / $total_cuota_ventas * 100, 1) : 0;
    $label = ($plan == 1) ? 'Pago único' : "{$plan} cuotas";
    $cuota_rows[] = [$label, number_format($d['ventas'], 0, ',', '.'), $pct . '%', fmt_eur($d['revenue'])];
    $cuota_labels[] = $label;
    $cuota_data[] = $d['ventas'];
    $ci++;
}

// Regional breakdown: columns = plans, rows = regions
ksort($by_cuotas);
$all_plans = array_keys($by_cuotas);
$region_order = ['ES', 'LATAM', 'EEUU', 'Resto'];
$region_label = ['ES' => 'España', 'LATAM' => 'LATAM', 'EEUU' => 'EE.UU.', 'Resto' => 'Resto'];

// Build regional table: header = Región | Plan1 | Plan2 ... each cell = N (X%)
$cuota_region_headers = ['Región'];
foreach ($all_plans as $p)
    $cuota_region_headers[] = ($p == 1) ? 'Pago único' : "{$p} cuotas";
$cuota_region_headers[] = 'Total';

$cuota_region_rows = [];
foreach ($region_order as $reg) {
    ksort($by_cuotas_region[$reg]);
    $total_reg = array_sum($by_cuotas_region[$reg]);
    $row = [$region_label[$reg]];
    foreach ($all_plans as $p) {
        $n = isset($by_cuotas_region[$reg][$p]) ? $by_cuotas_region[$reg][$p] : 0;
        $pct = ($total_reg > 0) ? round($n / $total_reg * 100, 1) : 0;
        $row[] = $total_reg > 0 ? "{$n} ({$pct}%)" : '—';
    }
    $row[] = $total_reg > 0 ? number_format($total_reg, 0, ',', '.') : '—';
    $cuota_region_rows[] = $row;
}

$dashboard['sections'][] = [
    'id' => 'sec_cuotas',
    'title' => '9. Plan de Pago (Cuotas)',
    'icon' => 'fa-layer-group',
    'layout' => 'two_col',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_cuotas',
            'title' => 'Ventas por Plan de Pago',
            'headers' => ['Plan', 'Ventas', '% del total', 'Ingresos'],
            'data' => $cuota_rows
        ],
        ['type' => 'pie', 'title' => 'Distribución de Planes de Pago', 'labels' => $cuota_labels, 'data' => $cuota_data, 'colors' => array_slice($cuota_colors, 0, $ci)],
    ],
];

// Sub-section: regional breakdown (added after sec_cuotas as a full-width table)
$dashboard['sections'][] = [
    'id' => 'sec_cuotas_region',
    'title' => '9b. Cuotas por Región',
    'icon' => 'fa-globe',
    'layout' => 'full',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_cuotas_region',
            'title' => 'Plan de Pago por Región (nº compradores y %)',
            'headers' => $cuota_region_headers,
            'data' => $cuota_region_rows
        ],
    ],
];

// ── Section 10: Por Producto ──────────────────────────────────
$prod_rows = [];
foreach ($by_producto as $prod => $d) {
    $prod_rows[] = [esc_html($prod), number_format($d['ventas'], 0, ',', '.'), fmt_eur($d['revenue'])];
}
$dashboard['sections'][] = [
    'id' => 'sec_producto',
    'title' => '10. Por Producto',
    'icon' => 'fa-box',
    'layout' => 'two_col',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_producto',
            'title' => 'Ventas e Ingresos por Producto',
            'headers' => ['Producto', 'Ventas', 'Ingresos'],
            'data' => $prod_rows
        ],
    ],
];

// ── Section 11: Por Método ────────────────────────────────────
$metodo_rows = [];
foreach ($by_metodo as $met => $d) {
    $metodo_rows[] = [esc_html($met), number_format($d['ventas'], 0, ',', '.'), fmt_eur($d['revenue'])];
}
$dashboard['sections'][] = [
    'id' => 'sec_metodo',
    'title' => '11. Por Método',
    'icon' => 'fa-credit-card',
    'layout' => 'two_col',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_metodo',
            'title' => 'Ventas e Ingresos por Método de Pago',
            'headers' => ['Método', 'Ventas', 'Ingresos'],
            'data' => $metodo_rows
        ],
    ],
];

// ── Section 12: Detalle de Ventas ────────────────────────────
// Ordenar por fecha desc
usort($raw_sales_rows, function ($a, $b) {
    return strcmp($b[5], $a[5]);
});
$dashboard['sections'][] = [
    'id' => 'sec_detalle',
    'title' => '12. Detalle de Ventas',
    'icon' => 'fa-list',
    'layout' => 'two_col',
    'items' => [
        [
            'type' => 'table',
            'id' => 'tbl_detalle',
            'title' => 'Todas las ventas',
            'headers' => ['Correo', 'Source', 'Medium', 'Campaña', 'Afiliado', 'Fecha', 'URL', 'IP', 'Importe'],
            'data' => $raw_sales_rows,
        ],
    ],
];

// ── Section 13: Publi ROAS (async) ───────────────────────────
// Rendered as placeholder; loaded via AJAX (endtrack_load_adspend_roas)

// Logo: usa el ajuste "Logo Admin (URL)" del panel admin; si está vacío, favicon por defecto.
$et_texts = get_option('endtrack_texts', array());
$logo_url = !empty($et_texts['logo_admin_panel']) ? $et_texts['logo_admin_panel'] : 'https://visualbusiness.school/wp-content/uploads/2026/03/favicon-bss.png';
$json_dashboard = json_encode($dashboard);
$endtrack_nonce = wp_create_nonce('endtrack_load_section');

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas Ventas - ENDTrack</title>
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
            background-attachment:fixed;
        }
        .container { max-width:1500px; margin:0 auto; padding:30px 20px; }
        .header { display:flex; flex-direction:column; gap:20px; margin-bottom:40px; }
        .header-top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .header-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-left:auto; justify-content:flex-end; }
        .header-actions .btn-nav { font-size:12px; padding:8px 14px; cursor:pointer; white-space:nowrap; font-family:inherit; line-height:1.2; }
        .header-actions .btn-nav i { margin-right:5px; }
        .header-actions .btn-nav.btn-completas { background:rgba(16,185,129,.15); border:1px solid rgba(16,185,129,.45); color:#6ee7b7; font-weight:700; }
        .header-actions .btn-nav.btn-completas:hover { background:#10b981; color:#fff; border-color:#10b981; box-shadow:0 4px 12px rgba(16,185,129,.4); }
        .header-actions .sep { width:1px; height:22px; background:var(--card-border); margin:0 4px; }
        @media (max-width: 900px) { .header-actions { margin-left:0; width:100%; justify-content:flex-start; } .header-actions .sep { display:none; } }
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
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <div class="header-top">
            <div class="logo-area">
                <img src="<?php echo esc_url($logo_url); ?>" alt="VBS Logo">
                <h1>Estadísticas Ventas <span class="badge-ventas">Conversiones</span></h1>
            </div>
            <div class="header-actions">
                <a href="/ventas-completas/?launch=<?php echo urlencode($active_launch); ?>&d_start=<?php echo urlencode($filter_date_start); ?>&d_end=<?php echo urlencode($filter_date_end); ?><?php echo $filter_producto !== '' ? '&var_producto=' . urlencode($filter_producto) : ''; ?>" class="btn-nav btn-completas" title="Listado completo de compradores: estado de pago, usuario PDC, etiquetas, notas y Stripe">
                    <i class="fas fa-table"></i> Ventas completas
                </a>
                <a href="/endtrack-estadisticas/?launch=<?php echo urlencode($active_launch); ?>" class="btn-nav">
                    <i class="fas fa-chart-bar"></i> Ver Tráfico
                </a>
                <span class="sep"></span>
                <button type="button" onclick="document.getElementById('modal-check-ventas').style.display='flex'" class="btn-nav" style="background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);color:#a5b4fc;">
                    <i class="fas fa-clipboard-check"></i> Comprobar Ventas
                </button>
                <?php if (current_user_can('manage_options')): ?>
                <button type="button" onclick="document.getElementById('modal-fill-pais').style.display='flex'" class="btn-nav" style="background:rgba(20,184,166,.15);border:1px solid rgba(20,184,166,.4);color:#5eead4;">
                    <i class="fas fa-globe"></i> Rellenar Países
                </button>
                <button type="button" onclick="document.getElementById('modal-fix-source').style.display='flex'" class="btn-nav" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);color:#fbbf24;">
                    <i class="fas fa-broom"></i> Limpiar "No tiene fuente"
                </button>
                <?php endif; ?>
            </div>
        </div>

        <form method="GET" class="filters-panel">
            <div class="filter-group">
                <label>Lanzamiento</label>
                <select name="launch" onchange="this.form.submit()">
                    <option value="legacy" <?php selected($active_launch, 'legacy'); ?>>Anteriores</option>
                    <?php
                    $launches = get_option('endtrack_launches', array());
                    foreach (array_reverse($launches) as $l): ?>
                        <option value="<?php echo esc_attr($l); ?>" <?php selected($active_launch, $l); ?>>
                            <?php echo esc_html(ucfirst($l)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Fuente (Source)</label>
                <select name="var_source">
                    <option value="">Todas</option>
                    <?php foreach ($filter_options['sources'] as $s): ?>
                                                                                                                                                                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($filter_source, $s); ?>><?php echo esc_html($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Medio</label>
                <select name="var_medium">
                    <option value="">Todos</option>
                    <?php foreach ($filter_options['mediums'] as $m): ?>
                                                                                                                                                                                    <option value="<?php echo esc_attr($m); ?>" <?php selected($filter_medium, $m); ?>><?php echo esc_html($m); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Campaña</label>
                <select name="var_campaign">
                    <option value="">Todas</option>
                    <?php foreach ($filter_options['campaigns'] as $c): ?>
                                                                                                                                                                                    <option value="<?php echo esc_attr($c); ?>" <?php selected($filter_campaign, $c); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Afiliado</label>
                <select name="var_afiliado">
                    <option value="">Todos</option>
                    <?php foreach ($filter_options['afiliados'] as $a): ?>
                                                                                                                                                                                    <option value="<?php echo esc_attr($a); ?>" <?php selected($filter_afiliado, $a); ?>><?php echo get_affiliate_name_v($a); ?> (<?php echo esc_html($a); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (count($all_launch_products) > 1):
                $_prod_labels = function_exists('endtrack_product_option_labels')
                    ? endtrack_product_option_labels($all_launch_products, $launch_product_names)
                    : array_combine($all_launch_products, $all_launch_products); ?>
            <div class="filter-group">
                <label>Producto</label>
                <select name="var_producto" style="max-width:280px;" title="Productos del lanzamiento, con el nombre que tienen en el campus">
                    <option value="">Todos (<?php echo count($all_launch_products); ?>)</option>
                    <?php foreach ($all_launch_products as $_pid): ?>
                        <option value="<?php echo esc_attr($_pid); ?>" title="<?php echo esc_attr($_pid); ?>" <?php selected($filter_producto, $_pid); ?>><?php echo esc_html($_prod_labels[$_pid]); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-group" style="min-width:120px;">
                <label>Desde</label>
                <input type="date" name="d_start" value="<?php echo esc_attr($filter_date_start); ?>">
            </div>
            <div class="filter-group" style="min-width:120px;">
                <label>Hasta</label>
                <input type="date" name="d_end" value="<?php echo esc_attr($filter_date_end); ?>">
            </div>

            <button type="submit" class="btn-filter">Aplicar Filtros</button>
            <?php if ($filter_source || $filter_medium || $filter_campaign || $filter_afiliado || $filter_producto !== ''): ?>
                                                                                                                                                                            <a href="?launch=<?php echo urlencode($active_launch); ?>" class="btn-filter" style="background:rgba(255,255,255,0.1);box-shadow:none;text-decoration:none;text-align:center;">Limpiar</a>
            <?php endif; ?>
        </form>
    </div><!-- /.header -->

    <?php if ($ext_error): ?>
                                                                                                                                                                    <div class="ext-error-banner"><strong>Aviso:</strong> Error conectando a DB externa — solo se muestran registrados.<br><code><?php echo esc_html($ext_error); ?></code></div>
    <?php endif; ?>

    <?php if ($filter_producto !== ''):
        $_fp_nombre = isset($launch_product_names[$filter_producto]) ? $launch_product_names[$filter_producto] : $filter_producto; ?>
    <div style="background:rgba(99,102,241,.12); border:1px solid rgba(99,102,241,.45); color:#c7d2fe; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:13px; line-height:1.5;">
        <i class="fas fa-box"></i> Filtrando por producto: <strong style="color:#fff;"><?php echo esc_html($_fp_nombre); ?></strong> <code style="color:#a5b4fc;"><?php echo esc_html($filter_producto); ?></code>.
        Ventas, reembolsos e ingresos son solo de este producto. Los registrados son los de todo el lanzamiento (no dependen del producto) y el ROAS sigue con todos los productos.
    </div>
    <?php endif; ?>

    <div class="quick-nav">
        <a href="#sec_kpi"        class="btn-nav"><i class="fas fa-tachometer-alt"></i> KPIs</a>
        <a href="#sec_daily"      class="btn-nav"><i class="fas fa-chart-line"></i> Diario</a>
        <a href="#sec_source"     class="btn-nav"><i class="fas fa-compass"></i> Fuente</a>
        <a href="#sec_country"    class="btn-nav"><i class="fas fa-globe"></i> País</a>
        <a href="#sec_medium"     class="btn-nav"><i class="fas fa-bullseye"></i> Medio</a>
        <a href="#sec_campaign"   class="btn-nav"><i class="fas fa-flag"></i> Campaña</a>
        <a href="#sec_affiliates" class="btn-nav"><i class="fas fa-users"></i> Afiliados</a>
        <a href="#sec_plataforma" class="btn-nav"><i class="fas fa-credit-card"></i> Plataforma</a>
        <a href="#sec_cuotas"     class="btn-nav"><i class="fas fa-layer-group"></i> Cuotas</a>
        <a href="#sec_producto"   class="btn-nav"><i class="fas fa-box"></i> Producto</a>
        <a href="#sec_metodo"     class="btn-nav"><i class="fas fa-credit-card"></i> Método</a>
        <a href="#sec_detalle"    class="btn-nav"><i class="fas fa-list"></i> Detalle</a>
        <a href="#sec_roas"       class="btn-nav"><i class="fas fa-euro-sign"></i> ROAS</a>
        <div style="margin-left:auto;display:flex;gap:15px;background:rgba(30,41,59,0.5);padding:8px 16px;border-radius:10px;border:1px solid var(--card-border);align-items:center;font-size:11px;font-weight:600;color:var(--text-muted);">
            <span style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:50%;background:#a855f7;display:inline-block;"></span>Registrados</span>
            <span style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:50%;background:#10b981;display:inline-block;"></span>Ventas</span>
        </div>
    </div>

    <div id="dashboard-root"></div>

    <!-- Section 9: ROAS Publi (async) -->
    <div class="dashboard-section" id="sec_roas" style="animation-delay:0.8s">
        <div class="section-header-wrap">
            <h2 class="section-title"><i class="fas fa-euro-sign"></i> 12. Publicidad — ROAS</h2>
            <button class="btn-filter" style="font-size:12px;" onclick="loadROAS()">
                <i class="fas fa-sync-alt"></i> Cargar datos
            </button>
            <button onclick="loadROAS(true)" style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);color:var(--text-muted);padding:6px 14px;border-radius:8px;cursor:pointer;font-size:12px;margin-left:8px;">
                <i class="fas fa-redo"></i> Refrescar
            </button>
        </div>
        <div id="roas-content">
            <div style="text-align:center;padding:40px;opacity:0.5;">
                <i class="fas fa-chart-bar" style="font-size:32px;margin-bottom:12px;display:block;color:var(--primary);"></i>
                <p>Haz clic en "Cargar datos" para ver ROAS (Ingresos / Gasto publicitario)</p>
            </div>
        </div>
    </div>

</div><!-- /.container -->

<script>
const dashboardData   = <?php echo $json_dashboard; ?>;
console.table(<?php echo json_encode($_dbg, JSON_PRETTY_PRINT); ?>); // DEBUG ip-attr — quitar cuando funcione
const endtrackNonce   = <?php echo json_encode($endtrack_nonce); ?>;
const endtrackAjax    = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
const endtrackParams  = (new URLSearchParams(window.location.search)).toString();
const byPaisRevPHP    = <?php
    $tmp = array();
    foreach ($by_pais_pub as $pais => $d) {
        $tmp[$pais] = array('ventas' => (int)$d['ventas'], 'revenue' => round($d['revenue'], 2));
    }
    echo json_encode($tmp, JSON_UNESCAPED_UNICODE);
?>;
const root = document.getElementById('dashboard-root');

Chart.register(ChartDataLabels);
Chart.defaults.set('plugins.datalabels', {
    color:'#fff', font:{weight:'bold',size:11},
    formatter:(value,ctx) => {
        let sum = ctx.dataset.data.reduce((a,b)=>Number(a)+Number(b),0);
        if(sum===0) return null;
        let p = (Number(value)*100/sum).toFixed(1)+'%';
        return p==='0.0%'?null:p;
    },
    display:(ctx)=>{
        const t=ctx.chart.config.type;
        if(t!=='doughnut'&&t!=='pie') return false;
        const v=Number(ctx.dataset.data[ctx.dataIndex]);
        const s=ctx.dataset.data.reduce((a,b)=>Number(a)+Number(b),0);
        return (v*100/s)>3;
    },
    anchor:'center', align:'center', offset:0
});
Chart.defaults.color='#94a3b8';
Chart.defaults.font.family="'Outfit',sans-serif";

function shadeColor(c,p){if(!c||c[0]!=='#')return '#6366f1';let f=parseInt(c.slice(1),16),t=p<0?0:255,pr=p<0?-p:p,R=f>>16,G=f>>8&0x00FF,B=f&0x0000FF;return'#'+(0x1000000+(Math.round((t-R)*pr/100)+R)*0x10000+(Math.round((t-G)*pr/100)+G)*0x100+(Math.round((t-B)*pr/100)+B)).toString(16).slice(1);}

function renderKPI(item){
    const colorHex = {pink:'#ec4899',purple:'#a855f7',orange:'#fb923c',green:'#10b981',red:'#f43f5e',amber:'#f59e0b',teal:'#06b6d4',featured:'#6366f1'}[item.color]||'#6366f1';
    const valClass = item.small ? 'kpi-value kpi-small' : 'kpi-value';
    return `<div class="panel-box kpi-card ${item.color||''}" style="--bg-color:${colorHex}">
        <span class="kpi-label">${item.title}</span>
        <span class="${valClass}">${item.value}</span>
    </div>`;
}

function renderFunnel(item){
    let html=`<div class="panel-box kpi-card"><span class="kpi-label">${item.title}</span><div class="funnel-container">`;
    let prev=0;
    item.data.forEach((step,i)=>{
        let pct='';
        if(i>0&&prev>0) pct=`<span style="font-size:11px;color:#10b981;padding:2px 6px;background:rgba(16,185,129,0.1);border-radius:4px;">${((step.value/prev)*100).toFixed(1)}%</span>`;
        html+=`<div class="funnel-step"><span class="funnel-label">${step.label}</span><div style="display:flex;align-items:center;gap:10px;">${pct}<span class="funnel-val">${step.value.toLocaleString()}</span></div></div>`;
        prev=step.value;
    });
    return html+'</div></div>';
}

function sortTable(tableId,colIndex){
    const table=document.getElementById(tableId);if(!table)return;
    const tbody=table.tBodies[0];const rows=Array.from(tbody.rows);
    const dir=table.getAttribute('data-sort-dir')==='asc'?'desc':'asc';
    table.setAttribute('data-sort-dir',dir);table.setAttribute('data-sort-col',colIndex);
    table.querySelectorAll('th i').forEach(i=>{i.className='fas fa-sort';i.style.opacity='0.3';});
    const thIcon=table.querySelectorAll('th')[colIndex].querySelector('i');
    thIcon.className=dir==='asc'?'fas fa-sort-up':'fas fa-sort-down';thIcon.style.opacity='1';
    const sorted=rows.sort((a,b)=>{
        let av=a.cells[colIndex].textContent.trim();let bv=b.cells[colIndex].textContent.trim();
        const isNum=/^[0-9.,%€ ]+$/.test(av.replace(/\s/g,''))&&/^[0-9.,%€ ]+$/.test(bv.replace(/\s/g,''));
        if(isNum){const cl=v=>parseFloat(v.replace(/\./g,'').replace(',','.').replace('%','').replace('€',''))||0;return dir==='asc'?cl(av)-cl(bv):cl(bv)-cl(av);}
        return dir==='asc'?av.localeCompare(bv):bv.localeCompare(av);
    });
    tbody.append(...sorted);
}

function renderTable(item){
    let tid=item.id||'tbl_'+Math.random().toString(36).substr(2,9);
    let html=`<div class="panel-box"><div class="panel-title">${item.title}<button class="btn-export" onclick="exportCSV('${tid}','${item.title.replace(/'/g,'')}')"><i class="fas fa-download"></i> CSV</button></div><div class="table-responsive"><table id="${tid}" class="table-pro"><thead><tr>`;
    item.headers.forEach((h,idx)=>{html+=`<th onclick="sortTable('${tid}',${idx})">${h} <i class="fas fa-sort" style="margin-left:5px;opacity:0.3;font-size:11px;"></i></th>`;});
    html+=`</tr></thead><tbody>`;
    if(!item.data||item.data.length===0){html+=`<tr><td colspan="${item.headers.length}" style="text-align:center;opacity:0.5;">Sin datos</td></tr>`;}
    else{item.data.forEach(row=>{html+=`<tr>`;row.forEach(cell=>html+=`<td>${cell}</td>`);html+=`</tr>`;});}
    html+=`</tbody></table></div></div>`;
    return html;
}

const _chartReg={};

function renderChartContainer(item,idx){
    const cid=item.id||`chart_${idx}`;
    const exportBtn=(item.type==='line'||item.type==='bar')
        ?`<button class="btn-export" onclick="exportChartCSV('${cid}','${item.title.replace(/'/g,'')}')" style="margin-left:auto;"><i class="fas fa-download"></i> CSV</button>`
        :'';
    return `<div class="panel-box"><div class="panel-title" style="display:flex;align-items:center;">${item.title}${exportBtn}</div><div class="chart-container"><canvas id="${cid}"></canvas></div></div>`;
}

function exportChartCSV(chartId, filename) {
    const chart = _chartReg[chartId];
    if (!chart) return;
    const labels = chart.data.labels || [];
    const datasets = chart.data.datasets || [];
    const headers = ['Día', ...datasets.map(ds => ds.label || 'Valor')];
    const rows = labels.map((label, i) => [label, ...datasets.map(ds => ds.data[i] !== undefined ? ds.data[i] : '')]);
    const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8;'}));
    link.setAttribute('download', filename.replace(/\s+/g, '_') + '.csv');
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
}

function initChart(item,idx){
    const cid=item.id||`chart_${idx}`;
    const canvas=document.getElementById(cid);if(!canvas)return;
    const ctx=canvas.getContext('2d');
    let config={type:item.type,data:{},options:{responsive:true,maintainAspectRatio:false,plugins:{tooltip:{callbacks:{label:function(c){let v=Number(c.raw)||0,s=c.dataset.data.reduce((a,b)=>Number(a)+Number(b),0),p=((v/s)*100).toFixed(1)+'%';return c.label+': '+v.toLocaleString()+' ('+p+')';}}}}}};
    if(item.type==='line'){
        config.data={labels:item.labels,datasets:item.datasets};
        config.options.plugins.legend={position:'bottom',labels:{usePointStyle:true,boxWidth:8}};
        config.options.scales={x:{grid:{display:false}},y:{grid:{color:'rgba(255,255,255,0.05)'}}};
    } else if(item.type==='bar'){
        const baseColor=item.color||'#6366f1';
        config.data={labels:item.labels,datasets:[{data:item.data,borderRadius:8,backgroundColor:(c)=>shadeColor(baseColor,20-(c.dataIndex/c.dataset.data.length)*50)}]};
        config.options.plugins.legend={display:false};
        config.options.indexAxis=item.labels.length>5?'y':'x';
        config.options.scales={x:{grid:{color:'rgba(255,255,255,0.05)'}},y:{grid:{display:false}}};
        config.options.plugins.tooltip={callbacks:{label:c=>c.formattedValue}};
    } else if(item.type==='pie'){
        const pieColors=item.colors||['#6366f1','#10b981','#facc15','#ec4899','#3b82f6','#f97316','#8b5cf6','#0ea5e9','#14b8a6','#d946ef'];
        config.data={labels:item.labels,datasets:[{data:item.data,backgroundColor:pieColors,borderWidth:0,hoverOffset:8}]};
        config.options.plugins.legend={position:'right',labels:{usePointStyle:true,boxWidth:8,font:{size:11}}};
    }
    _chartReg[cid]=new Chart(ctx,config);
}

function setSectionFilter(key, val, sectionId) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, val);
    if (sectionId) url.hash = sectionId;
    window.location.href = url.href;
}

function setDailyFilter(filter){
    const ds=dashboardData.sections.find(s=>s.id==='sec_daily')?.day_datasets;
    if(!ds)return;
    const d=ds[filter]||ds.todos;

    // Update ventas line chart
    const cv=_chartReg['chart_daily_ventas'];
    if(cv){
        cv.data.labels=d.labels;
        cv.data.datasets[0].data=d.ventas;
        cv.update();
    }
    // Update revenue bar chart
    const cr=_chartReg['chart_daily_revenue'];
    if(cr){
        cr.data.labels=d.labels;
        cr.data.datasets[0].data=d.revenue;
        cr.update();
    }

    // Toggle active button
    document.querySelectorAll('#sec_daily .btn-dfilter').forEach(b=>{
        b.classList.toggle('active', b.dataset.filter===filter);
    });
}

// Render all sections
let chartIdx=0;
dashboardData.sections.forEach((sec)=>{
    let layoutClass=sec.layout==='kpi_grid'?'grid-kpi':(sec.layout==='two_col'?'grid-2col':'');

    // Quick filter buttons for sections that have them
    let filterHtml='';
    if(sec.quick_filter){
        filterHtml=`<div class="section-quick-filters">`;
        for(const[val,label] of Object.entries(sec.quick_filter.options)){
            const isActive=sec.quick_filter.current===val;
            filterHtml+=`<button class="btn-sfilter${isActive?' active':''}" onclick="setSectionFilter('${sec.quick_filter.key}','${val}','${sec.id}')">${label}</button>`;
        }
        filterHtml+=`</div>`;
    }
    if(sec.day_filter){
        filterHtml+=`<div class="section-quick-filters">
            <button class="btn-dfilter active" data-filter="todos"      onclick="setDailyFilter('todos')">TODOS</button>
            <button class="btn-dfilter"         data-filter="alumnos"   onclick="setDailyFilter('alumnos')">ALUMNOS</button>
            <button class="btn-dfilter"         data-filter="no_alumnos" onclick="setDailyFilter('no_alumnos')">NO ALUMNOS</button>
        </div>`;
    }

    let secHtml=`<div class="dashboard-section" id="${sec.id}">
        <div class="section-header-wrap">
            <h2 class="section-title"><i class="fas ${sec.icon}"></i> ${sec.title}</h2>
            ${filterHtml}
        </div>
        <div class="${layoutClass}">`;
    sec.items.forEach(item=>{
        if(item.type==='kpi')         secHtml+=renderKPI(item);
        else if(item.type==='kpi_divider') secHtml+=`<div class="kpi-divider">${item.title}</div>`;
        else if(item.type==='funnel') secHtml+=renderFunnel(item);
        else if(item.type==='table')  secHtml+=renderTable(item);
        else if(['line','bar','pie'].includes(item.type)){ secHtml+=renderChartContainer(item,chartIdx); chartIdx++; }
    });
    secHtml+=`</div></div>`;
    root.insertAdjacentHTML('beforeend',secHtml);
});

// Init charts after DOM is built
let ci=0;
dashboardData.sections.forEach(sec=>{
    sec.items.forEach(item=>{
        if(['line','bar','pie'].includes(item.type)){initChart(item,ci);ci++;}
    });
});

// Sticky nav
window.addEventListener('scroll',()=>{
    const nav=document.querySelector('.quick-nav');if(!nav)return;
    const threshold=document.body.classList.contains('admin-bar')?33:1;
    nav.classList.toggle('is-sticky',window.scrollY>nav.parentElement.offsetTop+nav.offsetTop-threshold);
});

function exportCSV(tableId,filename){
    let table=document.getElementById(tableId);if(!table)return;
    let csv=Array.from(table.querySelectorAll('tr')).map(row=>Array.from(row.querySelectorAll('th,td')).map(c=>'"'+c.innerText.replace(/"/g,'""')+'"').join(',')).join('\n');
    let link=document.createElement('a');link.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));
    link.setAttribute('download',filename.replace(/\s+/g,'_')+'.csv');document.body.appendChild(link);link.click();document.body.removeChild(link);
}

// ── ROAS Loader ─────────────────────────────────────────────
async function loadROAS(nocache=false){
    const container=document.getElementById('roas-content');
    container.innerHTML=`<div class="roas-loading"><i class="fas fa-spinner"></i>Cargando datos de gasto y ventas…</div>`;
    const body=new URLSearchParams(endtrackParams);
    body.set('action','endtrack_load_adspend_roas');
    body.set('nonce',endtrackNonce);
    // Always use PHP-rendered dates (same as the page filter) to avoid AJAX default mismatch
    body.set('d_start','<?php echo esc_js($filter_date_start); ?>');
    body.set('d_end','<?php echo esc_js($filter_date_end); ?>');
    body.set('launch','<?php echo esc_js($active_launch); ?>');
    if(nocache) body.set('nocache','1');
    try{
        const res=await fetch(endtrackAjax,{method:'POST',body});
        const json=await res.json();
        if(!json.success){container.innerHTML=`<div class="roas-error"><i class="fas fa-exclamation-triangle"></i><br>${json.data||'Error al cargar'}</div>`;return;}
        const d=json.data;
        if(d._debug) console.log('[ROAS debug]', d._debug);
        const t=d.totals;
        const roasColor=v=>v>=2?'#10b981':v>=1?'#facc15':'#f43f5e';

        let html=`<div class="roas-kpis">
            <div class="roas-kpi" style="--kpi-accent:#facc15;"><span class="roas-kpi-label">Gasto Meta</span><span class="roas-kpi-value" style="color:#facc15;">${t.meta_spend.toLocaleString('es-ES',{minimumFractionDigits:2})} €</span></div>
            <div class="roas-kpi" style="--kpi-accent:#3b82f6;"><span class="roas-kpi-label">Gasto Google</span><span class="roas-kpi-value" style="color:#3b82f6;">${t.google_spend.toLocaleString('es-ES',{minimumFractionDigits:2})} €</span></div>
            <div class="roas-kpi" style="--kpi-accent:#6366f1;"><span class="roas-kpi-label">Gasto Total</span><span class="roas-kpi-value" style="color:#6366f1;">${t.total_spend.toLocaleString('es-ES',{minimumFractionDigits:2})} €</span></div>
            <div class="roas-kpi" style="--kpi-accent:#10b981;"><span class="roas-kpi-label">Ingresos (pub.)</span><span class="roas-kpi-value" style="color:#10b981;">${t.total_revenue.toLocaleString('es-ES',{minimumFractionDigits:2})} €</span></div>
            <div class="roas-kpi" style="--kpi-accent:${roasColor(t.roas_meta)};"><span class="roas-kpi-label">ROAS Meta</span><span class="roas-kpi-value" style="color:${roasColor(t.roas_meta)};">${t.roas_meta}x</span></div>
            <div class="roas-kpi" style="--kpi-accent:${roasColor(t.roas_google)};"><span class="roas-kpi-label">ROAS Google</span><span class="roas-kpi-value" style="color:${roasColor(t.roas_google)};">${t.roas_google}x</span></div>
            <div class="roas-kpi" style="--kpi-accent:${roasColor(t.roas)};"><span class="roas-kpi-label">ROAS Total</span><span class="roas-kpi-value" style="color:${roasColor(t.roas)};" >${t.roas}x</span></div>
        </div>`;

        // Gráficos: donut ventas por campaña + bar ROAS por campaña
        html+=`<div class="grid-2col">`;
        html+=`<div class="panel-box"><div class="panel-title">Ventas por Campaña</div><div class="chart-container"><canvas id="chart_camp_donut"></canvas></div></div>`;
        html+=`<div class="panel-box"><div class="panel-title">ROAS por Campaña</div><div class="chart-container"><canvas id="chart_roas_camp"></canvas></div></div>`;
        html+=`</div>`;

        // Tabla ROAS por campaña
        if(d.by_campaign && d.by_campaign.length>0){
            html+=`<div class="panel-box" style="margin-top:25px;">
                <div class="panel-title">Detalle ROAS por Campaña
                    <button class="btn-export" onclick="exportCSV('tbl_roas_camp','ROAS_Campanyas')"><i class="fas fa-download"></i> CSV</button>
                </div>
                <div class="roas-table-wrap"><table class="table-pro" id="tbl_roas_camp">
                <thead><tr><th>Campaña</th><th>Gasto €</th><th>Ingresos €</th><th>ROAS</th></tr></thead><tbody>`;
            d.by_campaign.forEach(r=>{
                const rc=r.roas>=2?'#10b981':r.roas>=1?'#facc15':'#f43f5e';
                const rv=r.roas>0?`<span style="font-weight:700;color:${rc}">${r.roas}x</span>`:`<span style="opacity:0.4">—</span>`;
                html+=`<tr><td>${r.campaign}</td><td>${r.spend}</td><td>${r.revenue}</td><td>${rv}</td></tr>`;
            });
            html+=`</tbody></table></div></div>`;
        }

        // ── ROAS por País ────────────────────────────────────────────────────
        const nameToIso = {
            // English names
            'Spain':'ES','Argentina':'AR','Mexico':'MX','Colombia':'CO','Chile':'CL',
            'Peru':'PE','Venezuela':'VE','Ecuador':'EC','Bolivia':'BO','Paraguay':'PY',
            'Uruguay':'UY','United States':'US','Brazil':'BR','Portugal':'PT',
            'France':'FR','Germany':'DE','Italy':'IT','United Kingdom':'GB',
            'Switzerland':'CH','Netherlands':'NL','Belgium':'BE','Austria':'AT',
            'Costa Rica':'CR','Honduras':'HN','Guatemala':'GT','Nicaragua':'NI',
            'El Salvador':'SV','Panama':'PA','Cuba':'CU','Dominican Republic':'DO',
            'Puerto Rico':'PR','Australia':'AU','Canada':'CA','Japan':'JP','Andorra':'AD',
            // Spanish names
            'España':'ES','Méjico':'MX','México':'MX','Reino Unido':'GB',
            'Estados Unidos':'US','Francia':'FR','Alemania':'DE','Italia':'IT',
            'Suiza':'CH','Países Bajos':'NL','Bélgica':'BE','Perú':'PE',
            'Panamá':'PA','República Dominicana':'DO','Canadá':'CA','Japón':'JP',
            'Brasil':'BR','Irlanda':'IE','Polonia':'PL','Rumanía':'RO','Suecia':'SE',
            'Dinamarca':'DK','Finlandia':'FI','Grecia':'GR','Noruega':'NO',
            'Marruecos':'MA','Luxemburgo':'LU','Emiratos Árabes':'AE','Singapur':'SG',
            // ISO passthrough
            'ES':'ES','AR':'AR','MX':'MX','CO':'CO','CL':'CL','PE':'PE','VE':'VE',
            'EC':'EC','BO':'BO','PY':'PY','UY':'UY','US':'US','BR':'BR','PT':'PT',
            'FR':'FR','DE':'DE','IT':'IT','GB':'GB','CH':'CH','NL':'NL','BE':'BE',
            'AT':'AT','CR':'CR','HN':'HN','GT':'GT','NI':'NI','SV':'SV','PA':'PA',
            'CU':'CU','DO':'DO','PR':'PR','AD':'AD','AU':'AU','CA':'CA','JP':'JP',
            'IE':'IE','PL':'PL','RO':'RO','SE':'SE','DK':'DK','FI':'FI','CZ':'CZ',
            'HU':'HU','GR':'GR','NO':'NO','SI':'SI','LV':'LV','IS':'IS','QA':'QA',
            'CY':'CY','EE':'EE','SA':'SA','SK':'SK','HR':'HR','TH':'TH','NZ':'NZ',
            'HK':'HK','TR':'TR','MA':'MA','ZA':'ZA','CN':'CN','SG':'SG','IL':'IL',
            'LT':'LT','LU':'LU','MT':'MT','BG':'BG','AE':'AE','JP':'JP',
        };
        const isoToName = {
            'ES':'España','AR':'Argentina','MX':'México','CO':'Colombia','CL':'Chile',
            'PE':'Perú','VE':'Venezuela','EC':'Ecuador','BO':'Bolivia','PY':'Paraguay',
            'UY':'Uruguay','US':'EE.UU.','BR':'Brasil','PT':'Portugal','FR':'Francia',
            'DE':'Alemania','IT':'Italia','GB':'Reino Unido','CH':'Suiza','NL':'Países Bajos',
            'BE':'Bélgica','AT':'Austria','CR':'Costa Rica','HN':'Honduras','GT':'Guatemala',
            'NI':'Nicaragua','SV':'El Salvador','PA':'Panamá','CU':'Cuba','DO':'Rep. Dom.',
            'PR':'Puerto Rico','AD':'Andorra','AU':'Australia','CA':'Canadá','JP':'Japón',
        };

        if(d.by_pais_spend && Object.keys(d.by_pais_spend).length>0){
            // Normalize byPaisRevPHP keys to ISO
            const revByISO={};
            Object.entries(byPaisRevPHP).forEach(([name,data])=>{
                const iso=nameToIso[name.trim()]||name.trim();
                if(!revByISO[iso]) revByISO[iso]={ventas:0,revenue:0};
                revByISO[iso].ventas+=data.ventas;
                revByISO[iso].revenue+=data.revenue;
            });

            const paisRows=Object.entries(d.by_pais_spend).map(([rawKey,spend])=>{
                const iso=nameToIso[rawKey]||rawKey;
                const rev=revByISO[iso]||{ventas:0,revenue:0};
                const roas=spend.total>0?+(rev.revenue/spend.total).toFixed(2):0;
                return{iso,name:isoToName[iso]||rawKey,fb:spend.fb,google:spend.google,total:spend.total,revenue:rev.revenue,ventas:rev.ventas,roas};
            }).sort((a,b)=>b.total-a.total);

            html+=`<div class="panel-box" style="margin-top:25px;">
                <div class="panel-title">ROAS por País
                    <button class="btn-export" onclick="exportCSV('tbl_roas_pais','ROAS_Paises')"><i class="fas fa-download"></i> CSV</button>
                </div>
                <div class="roas-table-wrap"><table class="table-pro" id="tbl_roas_pais">
                <thead><tr><th>País</th><th>Gasto FB €</th><th>Gasto Google €</th><th>Gasto Total €</th><th>Ingresos €</th><th>Ventas</th><th>ROAS</th></tr></thead><tbody>`;
            paisRows.forEach(r=>{
                const rc=r.roas>=2?'#10b981':r.roas>=1?'#facc15':'#f43f5e';
                const rv=r.roas>0?`<span style="font-weight:700;color:${rc}">${r.roas}x</span>`:`<span style="opacity:0.4">—</span>`;
                html+=`<tr>
                    <td>${r.name}</td>
                    <td>${r.fb.toLocaleString('es-ES',{minimumFractionDigits:2})}</td>
                    <td>${r.google.toLocaleString('es-ES',{minimumFractionDigits:2})}</td>
                    <td>${r.total.toLocaleString('es-ES',{minimumFractionDigits:2})}</td>
                    <td>${r.revenue.toLocaleString('es-ES',{minimumFractionDigits:2})}</td>
                    <td>${r.ventas}</td>
                    <td>${rv}</td>
                </tr>`;
            });
            html+=`</tbody></table></div></div>`;
        }

        container.innerHTML=html;

        // Donut: ventas por campaña
        const ctxDonut=document.getElementById('chart_camp_donut');
        if(ctxDonut && d.by_campaign && d.by_campaign.length>0){
            const donutColors=['#6366f1','#10b981','#facc15','#ec4899','#3b82f6','#f97316','#8b5cf6','#0ea5e9','#14b8a6','#d946ef'];
            new Chart(ctxDonut.getContext('2d'),{
                type:'doughnut',
                data:{
                    labels:d.by_campaign.map(r=>String(r.campaign)),
                    datasets:[{data:d.by_campaign.map(r=>r.revenue),backgroundColor:donutColors,borderWidth:0,hoverOffset:8}]
                },
                options:{
                    responsive:true,maintainAspectRatio:false,
                    plugins:{
                        legend:{position:'right',labels:{usePointStyle:true,boxWidth:8,font:{size:10}}},
                        datalabels:{display:(ctx)=>{const v=Number(ctx.dataset.data[ctx.dataIndex]),s=ctx.dataset.data.reduce((a,b)=>Number(a)+Number(b),0);return(v*100/s)>3;},formatter:(v,ctx)=>{const s=ctx.dataset.data.reduce((a,b)=>Number(a)+Number(b),0);return((v/s)*100).toFixed(1)+'%';},color:'#fff',font:{weight:'bold',size:11}},
                        tooltip:{callbacks:{label:c=>`${c.label}: ${Number(c.raw).toLocaleString('es-ES',{minimumFractionDigits:2})} €`}}
                    }
                }
            });
        }

        // Bar: ROAS por campaña
        const ctxCamp=document.getElementById('chart_roas_camp');
        if(ctxCamp && d.by_campaign && d.by_campaign.length>0){
            const campRoas=d.by_campaign.map(r=>r.roas);
            new Chart(ctxCamp.getContext('2d'),{
                type:'bar',
                data:{
                    labels:d.by_campaign.map(r=>String(r.campaign)),
                    datasets:[{data:campRoas,backgroundColor:campRoas.map(v=>v>=2?'#10b981':v>=1?'#facc15':'#f43f5e'),borderRadius:6}]
                },
                options:{
                    indexAxis:'y',responsive:true,maintainAspectRatio:false,
                    plugins:{
                        legend:{display:false},
                        datalabels:{display:true,anchor:'end',align:'right',color:'#fff',font:{weight:'bold',size:11},formatter:v=>v>0?v+'x':''},
                        tooltip:{callbacks:{label:c=>`ROAS: ${c.raw}x`}}
                    },
                    scales:{
                        x:{grid:{color:'rgba(255,255,255,0.05)'},min:0,suggestedMax:Math.max(...campRoas,5)+1,ticks:{callback:v=>v+'x'}},
                        y:{grid:{display:false}}
                    }
                }
            });
        }
    }catch(e){container.innerHTML=`<div class="roas-error">Error de conexión: ${e}</div>`;}
}

    function runCheckVentas() {
        const fileInput = document.getElementById('cv-file');
        const resultEl  = document.getElementById('cv-result');
        const loadingEl = document.getElementById('cv-loading');
        const errorEl   = document.getElementById('cv-error');
        resultEl.style.display = 'none';
        errorEl.style.display  = 'none';
        errorEl.textContent    = '';

        if (!fileInput.files.length) {
            errorEl.textContent = 'Selecciona un archivo CSV primero.';
            errorEl.style.display = 'block';
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            const lines  = e.target.result.split(/\r?\n/);
            const emails = [];
            for (const line of lines) {
                for (const part of line.split(/[,;\t]/)) {
                    const val = part.trim().toLowerCase().replace(/^"|"$/g, '');
                    if (val.includes('@') && val.includes('.')) emails.push(val);
                }
            }
            if (!emails.length) {
                errorEl.textContent = 'No se encontraron emails en el CSV.';
                errorEl.style.display = 'block';
                return;
            }

            loadingEl.style.display = 'block';
            const body = new URLSearchParams();
            body.set('action',  'endtrack_check_ventas');
            body.set('nonce',   endtrackNonce);
            body.set('launch',  '<?php echo esc_js($active_launch); ?>');
            body.set('emails',  JSON.stringify(emails));

            fetch(endtrackAjax, { method: 'POST', body })
                .then(r => {
                    const status = r.status;
                    return r.text().then(txt => ({ status, txt }));
                })
                .then(({ status, txt }) => {
                    loadingEl.style.display = 'none';
                    let data;
                    try {
                        data = JSON.parse(txt);
                    } catch(e) {
                        errorEl.innerHTML = `<b>HTTP ${status} — respuesta no válida:</b><br><pre style="font-size:10px;max-height:120px;overflow:auto;margin-top:6px;">${txt.substring(0, 600).replace(/</g,'&lt;')}</pre>`;
                        errorEl.style.display = 'block';
                        return;
                    }
                    if (!data.success) {
                        errorEl.textContent = data.data || 'Error desconocido.';
                        errorEl.style.display = 'block';
                        return;
                    }
                    const d = data.data;
                    const updCount    = d.matched_updated ? d.matched_updated.length : 0;
                    const verifyCount = d.in_csv_verify   ? d.in_csv_verify.length   : 0;
                    const typoCount   = d.possible_typos  ? d.possible_typos.length  : 0;
                    document.getElementById('cv-summary').innerHTML =
                        `CSV: <b>${d.csv_total}</b> emails &nbsp;|&nbsp; Plataforma: <b>${d.platform_total}</b> compradores &nbsp;|&nbsp; Coinciden: <b style="color:#22c55e">${d.matched}</b>`
                        + (updCount    ? ` &nbsp;· <b style="color:#f59e0b">${updCount}</b> email actualizado` : '')
                        + (verifyCount ? ` &nbsp;· <b style="color:#f59e0b">${verifyCount}</b> verificar producto` : '')
                        + (typoCount   ? ` &nbsp;· <b style="color:#f97316">${typoCount}</b> posible typo` : '');
                    const cvSet = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
                    cvSet('cv-cnt-missing',          d.in_csv_not_platform.length);
                    cvSet('cv-cnt-extra',            d.in_platform_not_csv.length);
                    cvSet('cv-cnt-updated',          updCount);
                    cvSet('cv-list-missing',         d.in_csv_not_platform.join('\n') || '(ninguno)');
                    cvSet('cv-cnt-extra-lic',        d.in_platform_not_csv_licencia         ? d.in_platform_not_csv_licencia.length         : 0);
                    cvSet('cv-cnt-extra-nolic',      d.in_platform_not_csv_no_licencia      ? d.in_platform_not_csv_no_licencia.length      : 0);
                    cvSet('cv-cnt-extra-nolic-rem',  d.in_platform_not_csv_no_lic_reembolso ? d.in_platform_not_csv_no_lic_reembolso.length : 0);
                    cvSet('cv-cnt-extra-nolic-norm', d.in_platform_not_csv_no_lic_normal    ? d.in_platform_not_csv_no_lic_normal.length    : 0);
                    cvSet('cv-list-extra-lic',       d.in_platform_not_csv_licencia         ? (d.in_platform_not_csv_licencia.join('\n')         || '(ninguno)') : '(ninguno)');
                    cvSet('cv-list-extra-nolic',     d.in_platform_not_csv_no_licencia      ? (d.in_platform_not_csv_no_licencia.join('\n')      || '(ninguno)') : '(ninguno)');
                    cvSet('cv-list-extra-nolic-rem', d.in_platform_not_csv_no_lic_reembolso ? (d.in_platform_not_csv_no_lic_reembolso.join('\n') || '(ninguno)') : '(ninguno)');
                    cvSet('cv-list-extra-nolic-norm',d.in_platform_not_csv_no_lic_normal    ? (d.in_platform_not_csv_no_lic_normal.join('\n')    || '(ninguno)') : '(ninguno)');
                    if (updCount) {
                        const pairs = d.matched_updated.map(p => `${p.csv}  →  ${p.original}`).join('\n');
                        document.getElementById('cv-list-updated').textContent = pairs;
                        document.getElementById('cv-block-updated').style.display = 'block';
                    } else {
                        document.getElementById('cv-block-updated').style.display = 'none';
                    }
                    if (verifyCount) {
                        document.getElementById('cv-cnt-verify').textContent  = verifyCount;
                        document.getElementById('cv-list-verify').textContent = d.in_csv_verify.join('\n');
                        document.getElementById('cv-block-verify').style.display = 'block';
                    } else {
                        document.getElementById('cv-block-verify').style.display = 'none';
                    }
                    if (typoCount) {
                        document.getElementById('cv-cnt-typo').textContent  = typoCount;
                        document.getElementById('cv-list-typo').textContent =
                            d.possible_typos.map(t => `${t.csv}  →  ${t.platform}  (${t.dist} carácter${t.dist>1?'es':''})`).join('\n');
                        document.getElementById('cv-block-typo').style.display = 'block';
                    } else {
                        document.getElementById('cv-block-typo').style.display = 'none';
                    }
                    resultEl.style.display = 'block';
                })
                .catch(err => {
                    loadingEl.style.display = 'none';
                    errorEl.textContent = 'Error de red: ' + (err && err.message ? err.message : String(err));
                    errorEl.style.display = 'block';
                });
        };
        reader.readAsText(fileInput.files[0]);
    }
</script>

<!-- Modal: Comprobar Ventas -->
<div id="modal-check-ventas" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#1e1e2e;border-radius:14px;padding:32px;max-width:720px;width:92%;max-height:85vh;overflow-y:auto;position:relative;box-shadow:0 25px 60px rgba(0,0,0,.6);">
        <button onclick="document.getElementById('modal-check-ventas').style.display='none'" style="position:absolute;top:14px;right:18px;background:none;border:none;color:#64748b;font-size:22px;cursor:pointer;line-height:1;">✕</button>
        <h2 style="margin:0 0 6px;color:#e2e8f0;font-size:20px;"><i class="fas fa-clipboard-check" style="color:#818cf8;margin-right:8px;"></i>Comprobar Ventas</h2>
        <p style="color:#64748b;margin:0 0 22px;font-size:13px;">Sube un CSV con emails y comparo contra los compradores confirmados en la plataforma para <b style="color:#a5b4fc;"><?php echo esc_html($active_launch); ?></b>.</p>

        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
            <input type="file" id="cv-file" accept=".csv,.txt" style="color:#cbd5e1;font-size:13px;flex:1;min-width:200px;">
            <button onclick="runCheckVentas()" style="background:#6366f1;color:#fff;border:none;padding:9px 20px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;white-space:nowrap;">
                <i class="fas fa-search"></i> Comparar
            </button>
        </div>

        <div id="cv-error"   style="display:none;color:#f43f5e;background:#2d0e14;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;"></div>
        <div id="cv-loading" style="display:none;text-align:center;color:#94a3b8;padding:20px;font-size:14px;"><i class="fas fa-spinner fa-spin"></i> Comparando…</div>

        <div id="cv-result" style="display:none;">
            <div id="cv-summary" style="background:#0f172a;border-radius:8px;padding:12px 16px;font-size:14px;color:#cbd5e1;margin-bottom:18px;"></div>

            <!-- Verificar: en plataforma pero producto no configurado -->
            <div id="cv-block-verify" style="display:none;margin-bottom:16px;">
                <div style="color:#f59e0b;font-weight:600;font-size:13px;margin-bottom:8px;">
                    En plataforma, verificar producto &nbsp;<span style="background:#3d2500;padding:2px 8px;border-radius:10px;" id="cv-cnt-verify">0</span>
                    <span style="font-weight:400;color:#94a3b8;font-size:11px;margin-left:6px;">(comprador confirmado pero producto no configurado en el lanzamiento)</span>
                </div>
                <div id="cv-list-verify" style="font-size:11px;color:#94a3b8;max-height:160px;overflow-y:auto;background:#0f172a;padding:10px;border-radius:8px;white-space:pre-line;line-height:1.7;"></div>
            </div>

            <!-- Posible typo en email -->
            <div id="cv-block-typo" style="display:none;margin-bottom:16px;">
                <div style="color:#f97316;font-weight:600;font-size:13px;margin-bottom:8px;">
                    Posible typo en email &nbsp;<span style="background:#3d1800;padding:2px 8px;border-radius:10px;" id="cv-cnt-typo">0</span>
                    <span style="font-weight:400;color:#94a3b8;font-size:11px;margin-left:6px;">(CSV → email más similar en plataforma, diferencia de 1-2 caracteres)</span>
                </div>
                <div id="cv-list-typo" style="font-size:11px;color:#94a3b8;max-height:160px;overflow-y:auto;background:#0f172a;padding:10px;border-radius:8px;white-space:pre-line;line-height:1.9;font-family:monospace;"></div>
            </div>

            <!-- Email actualizado (visible solo si hay resultados) -->
            <div id="cv-block-updated" style="display:none;margin-bottom:16px;">
                <div style="color:#f59e0b;font-weight:600;font-size:13px;margin-bottom:8px;">
                    Coincide por email actualizado &nbsp;<span style="background:#3d2500;padding:2px 8px;border-radius:10px;" id="cv-cnt-updated">0</span>
                    <span style="font-weight:400;color:#94a3b8;font-size:11px;margin-left:6px;">(CSV → email original en plataforma)</span>
                </div>
                <div id="cv-list-updated" style="font-size:11px;color:#94a3b8;max-height:160px;overflow-y:auto;background:#0f172a;padding:10px;border-radius:8px;white-space:pre-line;line-height:1.9;font-family:monospace;"></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <div style="color:#f43f5e;font-weight:600;font-size:13px;margin-bottom:8px;">
                        En CSV pero no en plataforma &nbsp;<span style="background:#3d0f18;padding:2px 8px;border-radius:10px;" id="cv-cnt-missing">0</span>
                    </div>
                    <div id="cv-list-missing" style="font-size:11px;color:#94a3b8;max-height:220px;overflow-y:auto;background:#0f172a;padding:10px;border-radius:8px;white-space:pre-line;line-height:1.7;"></div>
                </div>
                <div>
                    <div style="color:#22c55e;font-weight:600;font-size:13px;margin-bottom:10px;">
                        En plataforma pero no en CSV &nbsp;<span style="background:#0a2a14;padding:2px 8px;border-radius:10px;" id="cv-cnt-extra">0</span>
                    </div>
                    <div style="margin-bottom:10px;">
                        <div style="color:#a3e635;font-size:12px;font-weight:700;margin-bottom:5px;">
                            Con pago_licencia &nbsp;<span style="background:#1a2e05;padding:1px 7px;border-radius:8px;" id="cv-cnt-extra-lic">0</span>
                        </div>
                        <div id="cv-list-extra-lic" style="font-size:11px;color:#94a3b8;max-height:140px;overflow-y:auto;background:#0f172a;padding:10px;border-radius:8px;white-space:pre-line;line-height:1.7;"></div>
                    </div>
                    <div>
                        <div style="color:#64748b;font-size:12px;font-weight:700;margin-bottom:8px;">
                            Sin pago_licencia &nbsp;<span style="background:#1e293b;padding:1px 7px;border-radius:8px;" id="cv-cnt-extra-nolic">0</span>
                        </div>
                        <div style="margin-bottom:8px;">
                            <div style="color:#f43f5e;font-size:11px;font-weight:700;margin-bottom:4px;">
                                Con devolución / reembolso &nbsp;<span style="background:#2d0e14;padding:1px 6px;border-radius:6px;" id="cv-cnt-extra-nolic-rem">0</span>
                            </div>
                            <div id="cv-list-extra-nolic-rem" style="font-size:11px;color:#94a3b8;max-height:110px;overflow-y:auto;background:#0f172a;padding:8px 10px;border-radius:8px;white-space:pre-line;line-height:1.7;"></div>
                        </div>
                        <div>
                            <div style="color:#475569;font-size:11px;font-weight:700;margin-bottom:4px;">
                                Sin devolución / reembolso &nbsp;<span style="background:#1e293b;padding:1px 6px;border-radius:6px;" id="cv-cnt-extra-nolic-norm">0</span>
                            </div>
                            <div id="cv-list-extra-nolic-norm" style="font-size:11px;color:#94a3b8;max-height:110px;overflow-y:auto;background:#0f172a;padding:8px 10px;border-radius:8px;white-space:pre-line;line-height:1.7;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
