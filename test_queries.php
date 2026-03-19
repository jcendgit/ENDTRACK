<?php
require_once dirname(__FILE__) . '/../../../wp-load.php';
global $wpdb;

$table_datos = $wpdb->prefix . 'datos';
$table_visitas = $wpdb->prefix . 'visitas';

$filter_date_start = date('Y-m-d', strtotime('-30 days'));
$filter_date_end = date('Y-m-d');

$sql_v = $wpdb->prepare("WHERE 1=1 AND fecha >= %s AND fecha <= %s", $filter_date_start . " 00:00:00", $filter_date_end . " 23:59:59");
$sub_sql_d = "AND 1=1 AND d.fecha >= '{$filter_date_start} 00:00:00' AND d.fecha <= '{$filter_date_end} 23:59:59'";

$sql = "
SELECT 
    v.ref_s as dimension,
    COUNT(DISTINCT v.ip, FLOOR(UNIX_TIMESTAMP(v.fecha) / 1800)) as visitas,
    (SELECT COUNT(*) FROM $table_datos d WHERE d.source = v.ref_s $sub_sql_d AND d.primer_reg = 1) as registrados
FROM $table_visitas v
$sql_v AND v.ref_s IS NOT NULL AND v.ref_s != ''
GROUP BY v.ref_s
ORDER BY visitas DESC
LIMIT 50
";

$res = $wpdb->get_results($sql);
if ($wpdb->last_error) {
    echo "ERROR: " . $wpdb->last_error . "\n";
} else {
    echo "SUCCESS\n";
    print_r($res);
}
