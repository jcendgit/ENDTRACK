<?php
/**
 * ENDTrack Ad Spend Integration
 * Fetches ad spend data from Google Sheets API v4 (Meta + Google Ads)
 * and calculates CPL based on leads from wp_datos.
 *
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class ENDTrack_AdSpend
{

    // Google Sheets configuration
    const SPREADSHEET_ID = '16KRu4SJZz6RcizT7iEI7YhCIAzogPEU_a5VhDxQEKmU';
    const GID_META        = 221500887;
    const GID_GOOGLE      = 1819655754;
    const TAB_FB_PAIS     = 'gasto_fb_pais';
    const TAB_GOOGLE_PAIS = 'gasto_google_pais';
    const CACHE_TTL = 1800; // 30 min

    /**
     * Register AJAX hooks
     */
    public function init()
    {
        add_action('wp_ajax_endtrack_load_adspend', array($this, 'ajax_load_adspend'));
        add_action('wp_ajax_endtrack_load_adspend_roas', array($this, 'ajax_load_adspend_roas'));
        add_action('wp_ajax_endtrack_check_ventas', array($this, 'ajax_check_ventas'));
        add_action('wp_ajax_endtrack_fill_pais',       array($this, 'ajax_fill_pais'));
        add_action('wp_ajax_endtrack_fix_no_source',   array($this, 'ajax_fix_no_source'));
    }

    /**
     * AJAX handler: returns ad spend + leads + CPL data grouped by day
     */
    public function ajax_load_adspend()
    {
        // Permission check
        if (!current_user_can('manage_options') && !current_user_can('stats_viewer')) {
            wp_send_json_error('No tienes permisos.');
        }

        $date_start = isset($_POST['d_start']) ? sanitize_text_field($_POST['d_start']) : date('Y-m-d', strtotime('-30 days'));
        $date_end = isset($_POST['d_end']) ? sanitize_text_field($_POST['d_end']) : date('Y-m-d');
        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';

        // Check cache first
        $cache_key = 'endtrack_adspend_v4fc_' . md5($date_start . $date_end . $launch);
        $cached = get_transient($cache_key);
        if ($cached !== false && !isset($_POST['nocache'])) {
            wp_send_json_success($cached);
        }

        // --- Fetch spend data from Fan Convert API (replaces Google Sheets) ---
        // Leads still come from wp_datos below; only the SPEND now comes from Fan Convert.
        $fc_meta   = $this->fetch_fanconvert_spend('meta',   $date_start, $date_end, 'campaign_day');
        $fc_google = $this->fetch_fanconvert_spend('google', $date_start, $date_end, 'campaign_day');

        if (!empty($fc_meta['_error']) && !empty($fc_google['_error'])) {
            wp_send_json_error('No se pudo obtener el gasto de Fan Convert: ' . $fc_meta['_error']);
        }

        $meta_rows = $fc_meta['rows'];
        $google_rows = $fc_google['rows'];

        // Aggregate Fan Convert rows → by_day + by_campaign (per platform).
        // Fan Convert has no country dimension, so by_country stays empty.
        $meta_res = array('by_day' => array(), 'by_country' => array(), 'by_campaign' => array());
        $google_res = array('by_day' => array(), 'by_country' => array(), 'by_campaign' => array());
        foreach ($meta_rows as $r) {
            $sp = isset($r['spend']) ? floatval($r['spend']) : 0.0;
            $cn = (isset($r['campaign_name']) && $r['campaign_name'] !== '') ? $r['campaign_name'] : '(sin nombre)';
            if (!empty($r['date']))
                $meta_res['by_day'][$r['date']] = (isset($meta_res['by_day'][$r['date']]) ? $meta_res['by_day'][$r['date']] : 0) + $sp;
            $meta_res['by_campaign'][$cn] = (isset($meta_res['by_campaign'][$cn]) ? $meta_res['by_campaign'][$cn] : 0) + $sp;
        }
        foreach ($google_rows as $r) {
            $sp = isset($r['spend']) ? floatval($r['spend']) : 0.0;
            $cn = (isset($r['campaign_name']) && $r['campaign_name'] !== '') ? $r['campaign_name'] : '(sin nombre)';
            if (!empty($r['date']))
                $google_res['by_day'][$r['date']] = (isset($google_res['by_day'][$r['date']]) ? $google_res['by_day'][$r['date']] : 0) + $sp;
            $google_res['by_campaign'][$cn] = (isset($google_res['by_campaign'][$cn]) ? $google_res['by_campaign'][$cn] : 0) + $sp;
        }
        $meta_by_day = $meta_res['by_day'];
        $google_by_day = $google_res['by_day'];

        // Fan Convert has no country dimension → no spend-by-country available.
        // The "CPL por País" table will show leads (from wp_datos) without spend.
        $by_pais_spend_cpl = array();
        $pais_debug = array('note' => 'Fan Convert no expone gasto por país; la tabla de países solo muestra leads (wp_datos).');

        $leads_by_day = $this->get_leads_by_day($date_start, $date_end, $launch);
        $leads_keys = array_keys($leads_by_day);
        $leads_keys = array_filter($leads_keys, function ($k) {
            return $k !== 'debug_sql';
        });

        // Get leads from DB (grouped by Day, Country, Campaign)
        $leads_by_country = $this->get_leads_by_dimension('pais', $date_start, $date_end, $launch);
        $leads_by_campaign = $this->get_leads_by_dimension('campaign', $date_start, $date_end, $launch);

        // Merge all dates for daily table
        $all_dates = array_unique(array_merge(
            array_keys($meta_by_day),
            array_keys($google_by_day),
            $leads_keys
        ));
        sort($all_dates);

        $daily = array();
        $total_meta = 0;
        $total_google = 0;
        $total_leads_sum = 0;
        $total_meta_leads = 0;
        $total_google_leads = 0;

        foreach ($all_dates as $date) {
            if ($date < $date_start || $date > $date_end)
                continue;

            $m = isset($meta_by_day[$date]) ? $meta_by_day[$date] : 0;
            $g = isset($google_by_day[$date]) ? $google_by_day[$date] : 0;
            $l_meta = isset($leads_by_day[$date]['meta']) ? (int) $leads_by_day[$date]['meta'] : 0;
            $l_google = isset($leads_by_day[$date]['google']) ? (int) $leads_by_day[$date]['google'] : 0;
            $l_total = isset($leads_by_day[$date]['total']) ? (int) $leads_by_day[$date]['total'] : 0;

            $total_meta += $m;
            $total_google += $g;
            $total_leads_sum += $l_total;
            $total_meta_leads += $l_meta;
            $total_google_leads += $l_google;

            $daily[] = array(
                'date' => $date,
                'meta_spend' => round($m, 2),
                'google_spend' => round($g, 2),
                'total_spend' => round($m + $g, 2),
                'leads' => $l_total,
                'meta_leads' => $l_meta,
                'google_leads' => $l_google,
                'cpl_meta' => ($l_meta > 0) ? round($m / $l_meta, 2) : 0,
                'cpl_google' => ($l_google > 0) ? round($g / $l_google, 2) : 0,
                'cpl' => ($l_total > 0) ? round(($m + $g) / $l_total, 2) : 0,
            );
        }

        // --- Breakdown by Country ---
        $all_countries = array_unique(array_merge(array_keys($meta_res['by_country']), array_keys($google_res['by_country']), array_keys($leads_by_country)));
        $by_country = array();
        foreach ($all_countries as $c) {
            $m = isset($meta_res['by_country'][$c]) ? $meta_res['by_country'][$c] : 0;
            $g = isset($google_res['by_country'][$c]) ? $google_res['by_country'][$c] : 0;
            $l = isset($leads_by_country[$c]['total']) ? $leads_by_country[$c]['total'] : 0;
            $by_country[] = array('label' => $c, 'spend' => round($m + $g, 2), 'leads' => $l, 'cpl' => ($l > 0) ? round(($m + $g) / $l, 2) : 0);
        }
        usort($by_country, function ($a, $b) {
            if ($a['spend'] == $b['spend'])
                return 0;
            return ($a['spend'] > $b['spend']) ? -1 : 1;
        });

        // --- Breakdown by Campaign ---
        $all_camps = array_unique(array_merge(array_keys($meta_res['by_campaign']), array_keys($google_res['by_campaign']), array_keys($leads_by_campaign)));
        $by_campaign = array();
        foreach ($all_camps as $camp) {
            $m = isset($meta_res['by_campaign'][$camp]) ? $meta_res['by_campaign'][$camp] : 0;
            $g = isset($google_res['by_campaign'][$camp]) ? $google_res['by_campaign'][$camp] : 0;
            $l = isset($leads_by_campaign[$camp]['total']) ? $leads_by_campaign[$camp]['total'] : 0;
            $by_campaign[] = array('label' => (string) $camp, 'spend' => round($m + $g, 2), 'leads' => $l, 'cpl' => ($l > 0) ? round(($m + $g) / $l, 2) : 0);
        }
        usort($by_campaign, function ($a, $b) {
            if ($a['spend'] == $b['spend'])
                return 0;
            return ($a['spend'] > $b['spend']) ? -1 : 1;
        });

        // Flatten leads_by_country (name → total) for CPL table, keeping all countries
        $leads_by_pais_flat = array();
        foreach ($leads_by_country as $country_name => $v) {
            $leads_by_pais_flat[$country_name] = isset($v['total']) ? (int) $v['total'] : 0;
        }

        $response = array(
            'daily'          => $daily,
            'by_country'     => array_slice($by_country, 0, 15),
            'leads_by_pais'  => $leads_by_pais_flat,
            'by_pais_cpl'    => $by_pais_spend_cpl,
            'by_campaign'    => array_slice($by_campaign, 0, 30),
            'totals' => array(
                'meta_spend' => round($total_meta, 2),
                'google_spend' => round($total_google, 2),
                'total_spend' => round($total_meta + $total_google, 2),
                'total_leads' => $total_leads_sum,
                'meta_leads' => $total_meta_leads,
                'google_leads' => $total_google_leads,
                'cpl_meta' => ($total_meta_leads > 0) ? round($total_meta / $total_meta_leads, 2) : 0,
                'cpl_google' => ($total_google_leads > 0) ? round($total_google / $total_google_leads, 2) : 0,
                'cpl' => ($total_leads_sum > 0) ? round(($total_meta + $total_google) / $total_leads_sum, 2) : 0,
            ),
            'debug_sql'   => isset($leads_by_day['debug_sql']) ? $leads_by_day['debug_sql'] : 'No SQL',
            'pais_debug'  => $pais_debug,
            '_debug' => array(
                'date_start'       => $date_start,
                'date_end'         => $date_end,
                'meta_rows'        => count($meta_rows),
                'google_rows'      => count($google_rows),
                'meta_row0'        => isset($meta_rows[0]) ? $meta_rows[0] : null,
                'meta_days'        => count($meta_by_day),
                'google_days'      => count($google_by_day),
                'meta_total_raw'   => round($total_meta, 2),
                'google_total_raw' => round($total_google, 2),
            ),
        );

        set_transient($cache_key, $response, self::CACHE_TTL);
        wp_send_json_success($response);
    }

    // =====================================================
    //  Fan Convert API helper (spend source)
    // =====================================================

    /**
     * Fetch ad spend from the Fan Convert API (read-only REST, JSON).
     * Returns the decoded body; always has a 'rows' array. On failure sets '_error'.
     * API key/client live in WP options (never hardcoded — plugin syncs to a Git repo).
     */
    private function fetch_fanconvert_spend($platform, $from, $to, $group_by = 'campaign_day')
    {
        $api_key = get_option('endtrack_fanconvert_api_key', '');
        $client  = get_option('endtrack_fanconvert_client', 'pablo-gil');
        if (empty($api_key)) {
            return array('rows' => array(), '_error' => 'Falta la API key de Fan Convert (opción endtrack_fanconvert_api_key).');
        }

        $params = array(
            'client'   => $client,
            'funnel'   => 'launch',
            'group_by' => $group_by,
        );
        if ($platform) $params['platform'] = $platform;
        if ($from)     $params['from'] = $from;
        if ($to)       $params['to'] = $to;

        $url = 'https://dashboard.fanconvert.com/api/v1/spend?' . http_build_query($params);
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => array('X-API-Key' => $api_key),
        ));

        if (is_wp_error($response)) {
            error_log('ENDTrack FanConvert: ' . $response->get_error_message());
            return array('rows' => array(), '_error' => $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body)) {
            $msg = (is_array($body) && isset($body['error'])) ? $body['error'] : ('HTTP ' . $code);
            error_log('ENDTrack FanConvert: ' . $msg);
            return array('rows' => array(), '_error' => $msg);
        }
        if (!isset($body['rows']) || !is_array($body['rows'])) {
            $body['rows'] = array();
        }
        return $body;
    }

    // =====================================================
    //  Google Sheets API v4 helpers (legacy: still used by ROAS / ventas page)
    // =====================================================

    private function get_tab_names($api_key)
    {
        $cache_key = 'endtrack_sheet_tabs_' . md5(self::SPREADSHEET_ID);
        $tabs = get_transient($cache_key);
        if (is_array($tabs) && !empty($tabs)) {
            return $tabs;
        }

        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . self::SPREADSHEET_ID
            . '?key=' . urlencode($api_key)
            . '&fields=sheets.properties';

        $response = wp_remote_get($url, array('timeout' => 15, 'sslverify' => true));

        if (is_wp_error($response)) {
            error_log('ENDTrack AdSpend: metadata error: ' . $response->get_error_message());
            return array();
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            error_log('ENDTrack AdSpend: metadata HTTP ' . wp_remote_retrieve_response_code($response));
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $tabs = array();

        if (isset($body['sheets']) && is_array($body['sheets'])) {
            foreach ($body['sheets'] as $sheet) {
                if (isset($sheet['properties']['sheetId'], $sheet['properties']['title'])) {
                    $tabs[(int) $sheet['properties']['sheetId']] = $sheet['properties']['title'];
                }
            }
        }

        if (!empty($tabs)) {
            set_transient($cache_key, $tabs, 86400);
        }

        return $tabs;
    }

    private function fetch_sheet_rows($api_key, $tab_name)
    {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . self::SPREADSHEET_ID
            . '/values/' . urlencode($tab_name)
            . '?key=' . urlencode($api_key)
            . '&valueRenderOption=UNFORMATTED_VALUE'
            . '&dateTimeRenderOption=FORMATTED_STRING';

        $response = wp_remote_get($url, array('timeout' => 30, 'sslverify' => true));

        if (is_wp_error($response)) {
            error_log('ENDTrack AdSpend: fetch error for tab "' . $tab_name . '": ' . $response->get_error_message());
            return array();
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            error_log('ENDTrack AdSpend: HTTP ' . wp_remote_retrieve_response_code($response) . ' for tab "' . $tab_name . '"');
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['values']) && is_array($body['values'])) {
            return $body['values'];
        }

        return array();
    }

    // =====================================================
    //  CSV/Row Parsing
    // =====================================================

    private function parse_spend_rows($rows, $date_start, $date_end, $platform = 'meta', $opts = array())
    {
        $result = array(
            'by_day' => array(),
            'by_country' => array(),
            'by_campaign' => array()
        );

        if (empty($rows)) {
            return $result;
        }

        $date_col = -1;
        $spend_col = -1;
        $country_col = -1;
        $campaign_col = -1;

        // Auto-detect header: if first cell is a date-like value, there is no header row
        $first_cell = isset($rows[0][0]) ? trim((string) $rows[0][0]) : '';
        $has_header = !preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $first_cell);
        if (array_key_exists('has_header', $opts)) {
            $has_header = (bool) $opts['has_header'];
        }

        if ($has_header) {
            $header = array_shift($rows);

            // Map column names (lowercase) to index
            foreach ($header as $i => $col_name) {
                $lower = mb_strtolower(trim((string) $col_name));
                // Strip parenthetical suffix like " (EUR)" or " (USD)" for flexible matching
                $lower_base = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $lower));

                if (in_array($lower, array('día', 'dia', 'day', 'fecha', 'date')))
                    $date_col = $i;

                if ($platform === 'meta') {
                    if (in_array($lower_base, array('importe gastado', 'amount spent', 'spend', 'gasto')))
                        $spend_col = $i;
                    if (in_array($lower_base, array('país', 'pais', 'country')))
                        $country_col = $i;
                    if (in_array($lower_base, array('nombre de la campaña', 'nombre campaña', 'nombre de campaña', 'campaign name', 'campaign', 'campaña')))
                        $campaign_col = $i;
                } else {
                    if (in_array($lower_base, array('cost', 'coste', 'gasto', 'costo')))
                        $spend_col = $i;
                    if (in_array($lower_base, array('país', 'pais', 'country')))
                        $country_col = $i;
                    if (in_array($lower_base, array('campaña', 'campaign', 'nombre de la campaña', 'nombre campaña', 'nombre de campaña')))
                        $campaign_col = $i;
                }
            }
        }

        // Fallbacks
        if ($date_col === -1)
            $date_col = 0;
        if ($spend_col === -1)
            $spend_col = isset($opts['spend_fallback']) ? (int) $opts['spend_fallback'] : (($platform === 'meta') ? 7 : 5);
        if ($country_col === -1 && isset($opts['country_fallback']))
            $country_col = (int) $opts['country_fallback'];

        foreach ($rows as $row) {
            if (!isset($row[$date_col]) || !isset($row[$spend_col]))
                continue;

            $date_raw = trim((string) $row[$date_col]);

            // Normalize date to YYYY-MM-DD
            $date = $this->normalize_date($date_raw);
            if (!$date)
                continue;
            if ($date < $date_start || $date > $date_end)
                continue;

            // Parse spend value
            $spend = $this->parse_number($row[$spend_col]);

            // Daily aggregation
            if (!isset($result['by_day'][$date]))
                $result['by_day'][$date] = 0;
            $result['by_day'][$date] += $spend;

            // Country aggregation
            if ($country_col !== -1 && isset($row[$country_col])) {
                $c = trim((string) $row[$country_col]);
                if (!empty($c)) {
                    if (!isset($result['by_country'][$c]))
                        $result['by_country'][$c] = 0;
                    $result['by_country'][$c] += $spend;
                }
            }

            // Campaign aggregation
            if ($campaign_col !== -1 && isset($row[$campaign_col])) {
                $camp = trim((string) $row[$campaign_col]);
                if (!empty($camp)) {
                    if (!isset($result['by_campaign'][$camp]))
                        $result['by_campaign'][$camp] = 0;
                    $result['by_campaign'][$camp] += $spend;
                }
            }
        }

        return $result;
    }

    private function parse_number($value)
    {
        if (is_numeric($value))
            return (float) $value;

        $value = trim((string) $value, "\"' \t\n\r");
        if (empty($value) || in_array($value, array('#NUM!', '-', '#N/A')))
            return 0;

        if (strpos($value, '.') !== false && strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    private function normalize_date($raw)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw))
            return $raw;
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m))
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        if (preg_match('#^(\d{1,2})-(\d{1,2})-(\d{4})$#', $raw, $m))
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return null;
    }

    private function get_leads_by_day($date_start, $date_end, $launch = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'datos';
        $by_day = array();

        $target_val = 1;
        $primer_reg_cond = "primer_reg = $target_val";
        if (!empty($launch) && $launch !== 'legacy') {
            $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $primer_reg_cond = "primer_reg_$safe_launch = $target_val";
        }

        $sql = $wpdb->prepare(
            "SELECT DATE(fecha) AS dia, 
                    SUM(CASE WHEN (LOWER(medium) = 'meta' OR LOWER(medium) = 'fb' OR LOWER(medium) = 'facebook') THEN 1 ELSE 0 END) as meta_leads,
                    SUM(CASE WHEN (LOWER(medium) = 'googleads' OR LOWER(medium) = 'gads' OR LOWER(medium) = 'google' OR LOWER(medium) = 'cpc') THEN 1 ELSE 0 END) as google_leads,
                    COUNT(*) AS total
             FROM `$table`
             WHERE (source = 'publicidad' OR source = 'Publicidad')
               AND $primer_reg_cond
               AND fecha >= %s AND fecha < DATE_ADD(%s, INTERVAL 1 DAY)
             GROUP BY dia
             ORDER BY dia ASC",
            $date_start . ' 00:00:00',
            $date_end . ' 00:00:00'
        );

        $results = $wpdb->get_results($sql);
        if ($results) {
            foreach ($results as $row) {
                $d_key = date('Y-m-d', strtotime($row->dia));
                $by_day[$d_key] = array(
                    'meta' => (int) $row->meta_leads,
                    'google' => (int) $row->google_leads,
                    'total' => (int) $row->total
                );
            }
        }
        $by_day['debug_sql'] = "[Launch: $launch] SQL: " . $sql;
        return $by_day;
    }

    /**
     * AJAX handler: returns ad spend + revenue + ROAS data (for stats-ventas page)
     */
    public function ajax_load_adspend_roas()
    {
        if (!current_user_can('manage_options') && !current_user_can('stats_viewer') && !current_user_can('view_endtrack_stats') && !current_user_can('manage_endtrack_affiliates')) {
            wp_send_json_error('No tienes permisos.');
            return;
        }

        $date_start = isset($_POST['d_start']) ? sanitize_text_field($_POST['d_start']) : date('Y-m-d', strtotime('-30 days'));
        $date_end = isset($_POST['d_end']) ? sanitize_text_field($_POST['d_end']) : date('Y-m-d');
        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';

        $cache_key = 'endtrack_adspend_roas_v7fc_' . md5($date_start . $date_end . $launch);
        $cached = get_transient($cache_key);
        if ($cached !== false && !isset($_POST['nocache'])) {
            wp_send_json_success($cached);
            return;
        }

        // ── 1. Gasto desde Fan Convert ───────────────────────────────────────
        //   Misma fuente que ajax_load_adspend (página de tráfico). El Google
        //   Sheets antiguo dejó de alimentarse el 2026-04-17 y daba gasto 0.
        $fc_meta   = $this->fetch_fanconvert_spend('meta',   $date_start, $date_end, 'campaign_day');
        $fc_google = $this->fetch_fanconvert_spend('google', $date_start, $date_end, 'campaign_day');

        if (!empty($fc_meta['_error']) && !empty($fc_google['_error'])) {
            wp_send_json_error('No se pudo obtener el gasto de Fan Convert: ' . $fc_meta['_error']);
            return;
        }

        $meta_rows = $fc_meta['rows'];
        $google_rows = $fc_google['rows'];

        $meta_res = array('by_day' => array(), 'by_campaign' => array());
        $google_res = array('by_day' => array(), 'by_campaign' => array());
        foreach ($meta_rows as $r) {
            $sp = isset($r['spend']) ? floatval($r['spend']) : 0.0;
            $cn = (isset($r['campaign_name']) && $r['campaign_name'] !== '') ? $r['campaign_name'] : '(sin nombre)';
            if (!empty($r['date']))
                $meta_res['by_day'][$r['date']] = (isset($meta_res['by_day'][$r['date']]) ? $meta_res['by_day'][$r['date']] : 0) + $sp;
            $meta_res['by_campaign'][$cn] = (isset($meta_res['by_campaign'][$cn]) ? $meta_res['by_campaign'][$cn] : 0) + $sp;
        }
        foreach ($google_rows as $r) {
            $sp = isset($r['spend']) ? floatval($r['spend']) : 0.0;
            $cn = (isset($r['campaign_name']) && $r['campaign_name'] !== '') ? $r['campaign_name'] : '(sin nombre)';
            if (!empty($r['date']))
                $google_res['by_day'][$r['date']] = (isset($google_res['by_day'][$r['date']]) ? $google_res['by_day'][$r['date']] : 0) + $sp;
            $google_res['by_campaign'][$cn] = (isset($google_res['by_campaign'][$cn]) ? $google_res['by_campaign'][$cn] : 0) + $sp;
        }
        $meta_by_day = $meta_res['by_day'];
        $google_by_day = $google_res['by_day'];

        // Fan Convert no expone gasto por país → la tabla "ROAS por País" no se
        // renderiza (el JS la omite si by_pais_spend llega vacío).
        $spend_by_pais = array();

        // ── 2. Publicidad emails from wp_datos (all-time attribution) ────────
        global $wpdb;
        $table_datos = $wpdb->prefix . 'datos';

        $primer_reg_cond = 'primer_reg = 1';
        if (!empty($launch) && $launch !== 'legacy') {
            $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $primer_reg_cond = "primer_reg_{$safe_launch} = 1";
        }

        $pub_rows = $wpdb->get_results(
            "SELECT DISTINCT correo, medium, campaign
             FROM {$table_datos}
             WHERE (source = 'publicidad' OR source = 'Publicidad')
               AND {$primer_reg_cond}
               AND correo != '' AND correo IS NOT NULL"
        );

        $mail_to_medium = array();
        $mail_to_campaign = array();
        foreach ($pub_rows as $r) {
            $mail = strtolower(trim($r->correo));
            if ($mail === '')
                continue;
            $mail_to_medium[$mail] = strtolower(trim((string) $r->medium));
            $mail_to_campaign[$mail] = trim((string) $r->campaign) ?: '(sin campaña)';
        }

        // ── 3. Revenue from pdc_pagos ────────────────────────────────────────
        $revenue_by_day = array();
        $revenue_by_campaign = array();
        $total_meta_rev = 0.0;
        $total_google_rev = 0.0;

        if (!empty($mail_to_medium)) {
            $safe_l = (!empty($launch) && $launch !== 'legacy') ? preg_replace('/[^a-zA-Z0-9_]/', '', $launch) : 'legacy';
            $launch_products = json_decode(get_option('endtrack_ext_products_' . $safe_l, '[]'), true);
            if (!is_array($launch_products))
                $launch_products = array();

            if (!empty($launch_products)) {
                $__ext = endtrack_ext_db_config();
                $ext_host = $__ext['host'];
                $ext_user = $__ext['user'];
                $ext_pass = $__ext['pass'];
                $ext_name = $__ext['name'];

                try {
                    $pdo = new PDO(
                        "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                        $ext_user,
                        $ext_pass,
                        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)
                    );

                    $all_mails = array_keys($mail_to_medium);
                    $ph_mails = implode(',', array_fill(0, count($all_mails), '?'));
                    $ph_prods = implode(',', array_fill(0, count($launch_products), '?'));
                    $params = array_merge($launch_products, $all_mails, array($date_start, $date_end));

                    $stmt = $pdo->prepare("
                        SELECT
                            LOWER(TRIM(U.mail)) AS mail,
                            IFNULL(A.importe, 0) AS importe,
                            DATE(A.fecha) AS fecha_venta
                        FROM pdc_pagos A
                        INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                        WHERE A.ID_producto IN ({$ph_prods})
                          AND LOWER(TRIM(U.mail)) IN ({$ph_mails})
                          AND A.estado IN ('Completado','Compra')
                          AND A.cuota = 1
                          AND DATE(A.fecha) >= ?
                          AND DATE(A.fecha) <= ?
                    ");
                    $stmt->execute($params);
                    $sale_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $pdo = null;

                    foreach ($sale_rows as $s) {
                        $mail = strtolower(trim($s['mail']));
                        $day = $s['fecha_venta'];
                        $imp = floatval($s['importe']);
                        $med = isset($mail_to_medium[$mail]) ? $mail_to_medium[$mail] : '';
                        $cam = isset($mail_to_campaign[$mail]) ? $mail_to_campaign[$mail] : '(sin campaña)';

                        if (!isset($revenue_by_day[$day]))
                            $revenue_by_day[$day] = array('meta' => 0.0, 'google' => 0.0);
                        if (!isset($revenue_by_campaign[$cam]))
                            $revenue_by_campaign[$cam] = 0.0;

                        if (in_array($med, array('meta', 'fb', 'facebook'))) {
                            $revenue_by_day[$day]['meta'] += $imp;
                            $total_meta_rev += $imp;
                        } elseif (in_array($med, array('googleads', 'gads', 'google', 'cpc'))) {
                            $revenue_by_day[$day]['google'] += $imp;
                            $total_google_rev += $imp;
                        } else {
                            $revenue_by_day[$day]['meta'] += $imp / 2;
                            $revenue_by_day[$day]['google'] += $imp / 2;
                        }
                        $revenue_by_campaign[$cam] += $imp;
                    }
                } catch (Exception $e) {
                    error_log('ENDTrack ROAS: ' . $e->getMessage());
                }
            }
        }

        // ── 4. Build daily rows ──────────────────────────────────────────────
        $all_dates = array_unique(array_merge(
            array_keys($meta_by_day),
            array_keys($google_by_day),
            array_keys($revenue_by_day)
        ));
        sort($all_dates);

        $daily = array();
        $total_meta_spend = 0.0;
        $total_google_spend = 0.0;
        $total_revenue = $total_meta_rev + $total_google_rev;

        foreach ($all_dates as $date) {
            if ($date < $date_start || $date > $date_end)
                continue;

            $ms = isset($meta_by_day[$date]) ? floatval($meta_by_day[$date]) : 0.0;
            $gs = isset($google_by_day[$date]) ? floatval($google_by_day[$date]) : 0.0;
            $rm = isset($revenue_by_day[$date]['meta']) ? $revenue_by_day[$date]['meta'] : 0.0;
            $rg = isset($revenue_by_day[$date]['google']) ? $revenue_by_day[$date]['google'] : 0.0;
            $rv = $rm + $rg;

            $total_meta_spend += $ms;
            $total_google_spend += $gs;

            $daily[] = array(
                'date' => $date,
                'meta_spend' => round($ms, 2),
                'google_spend' => round($gs, 2),
                'total_spend' => round($ms + $gs, 2),
                'total_revenue' => round($rv, 2),
                'roas' => ($ms + $gs > 0) ? round($rv / ($ms + $gs), 2) : 0,
                'roas_meta' => ($ms > 0) ? round($rm / $ms, 2) : 0,
                'roas_google' => ($gs > 0) ? round($rg / $gs, 2) : 0,
            );
        }

        // ── 5. Build by_campaign (spend from Sheets + revenue from pdc_pagos) ──
        $all_camps = array_unique(array_merge(
            array_keys($meta_res['by_campaign']),
            array_keys($google_res['by_campaign']),
            array_keys($revenue_by_campaign)
        ));
        $by_campaign_roas = array();
        foreach ($all_camps as $camp) {
            $cs = isset($meta_res['by_campaign'][$camp]) ? floatval($meta_res['by_campaign'][$camp]) : 0.0;
            $gs = isset($google_res['by_campaign'][$camp]) ? floatval($google_res['by_campaign'][$camp]) : 0.0;
            $sp = $cs + $gs;
            $rv = isset($revenue_by_campaign[$camp]) ? floatval($revenue_by_campaign[$camp]) : 0.0;
            // Las claves numéricas de PHP se vuelven int y json_encode las emite
            // como número; la leyenda de Chart.js requiere strings en labels.
            $by_campaign_roas[] = array(
                'campaign' => (string) $camp,
                'spend' => round($sp, 2),
                'revenue' => round($rv, 2),
                'roas' => ($sp > 0) ? round($rv / $sp, 2) : 0,
            );
        }
        usort($by_campaign_roas, function ($a, $b) {
            return $b['spend'] <=> $a['spend'];
        });
        $by_campaign_roas = array_values(array_filter($by_campaign_roas, function ($c) {
            return $c['revenue'] > 0;
        }));

        $total_spend = $total_meta_spend + $total_google_spend;
        $response = array(
            'daily'          => $daily,
            'by_campaign'    => $by_campaign_roas,
            'by_pais_spend'  => $spend_by_pais,
            'totals' => array(
                'meta_spend' => round($total_meta_spend, 2),
                'google_spend' => round($total_google_spend, 2),
                'total_spend' => round($total_spend, 2),
                'total_revenue' => round($total_revenue, 2),
                'roas' => ($total_spend > 0) ? round($total_revenue / $total_spend, 2) : 0,
                'roas_meta' => ($total_meta_spend > 0) ? round($total_meta_rev / $total_meta_spend, 2) : 0,
                'roas_google' => ($total_google_spend > 0) ? round($total_google_rev / $total_google_spend, 2) : 0,
            ),
            '_debug' => array(
                'date_start'     => $date_start,
                'date_end'       => $date_end,
                'meta_rows'      => count($meta_rows),
                'google_rows'    => count($google_rows),
                'meta_row0'      => isset($meta_rows[0]) ? $meta_rows[0] : null,
                'meta_days'      => count($meta_by_day),
                'google_days'    => count($google_by_day),
                'meta_total_raw' => round($total_meta_spend, 2),
                'google_total_raw' => round($total_google_spend, 2),
            ),
        );

        set_transient($cache_key, $response, self::CACHE_TTL);
        wp_send_json_success($response);
    }

    private function get_leads_by_dimension($dimension, $date_start, $date_end, $launch = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'datos';
        $by_dim = array();

        $target_val = 1;
        $primer_reg_cond = "primer_reg = $target_val";
        if (!empty($launch) && $launch !== 'legacy') {
            $safe_launch = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
            $primer_reg_cond = "primer_reg_$safe_launch = $target_val";
        }

        // Validate dimension to avoid SQL injection (only allow known columns)
        $allowed = array('pais', 'campaign');
        if (!in_array($dimension, $allowed))
            return $by_dim;

        $sql = $wpdb->prepare(
            "SELECT `$dimension` as dim, 
                    SUM(CASE WHEN (LOWER(medium) = 'meta' OR LOWER(medium) = 'fb' OR LOWER(medium) = 'facebook') THEN 1 ELSE 0 END) as meta_leads,
                    SUM(CASE WHEN (LOWER(medium) = 'googleads' OR LOWER(medium) = 'gads' OR LOWER(medium) = 'google' OR LOWER(medium) = 'cpc') THEN 1 ELSE 0 END) as google_leads,
                    COUNT(*) AS total
             FROM `$table`
             WHERE (source = 'publicidad' OR source = 'Publicidad')
               AND $primer_reg_cond
               AND fecha >= %s AND fecha < DATE_ADD(%s, INTERVAL 1 DAY)
             GROUP BY `$dimension`",
            $date_start . ' 00:00:00',
            $date_end . ' 00:00:00'
        );

        $results = $wpdb->get_results($sql);
        if ($results) {
            foreach ($results as $row) {
                $d = !empty($row->dim) ? $row->dim : 'Desconocido';
                $by_dim[$d] = array(
                    'meta' => (int) $row->meta_leads,
                    'google' => (int) $row->google_leads,
                    'total' => (int) $row->total
                );
            }
        }
        return $by_dim;
    }

    /**
     * AJAX handler: compare CSV emails against platform buyers for a launch.
     */
    public function ajax_check_ventas()
    {
        if (!current_user_can('manage_options') && !current_user_can('stats_viewer')) {
            wp_send_json_error('No tienes permisos.');
        }
        check_ajax_referer('endtrack_load_section', 'nonce');

        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        $raw_emails = isset($_POST['emails']) ? wp_unslash($_POST['emails']) : '[]';
        $csv_emails = json_decode($raw_emails, true);
        if (!is_array($csv_emails)) {
            wp_send_json_error('Datos inválidos.');
        }

        $csv_set = array();
        foreach ($csv_emails as $e) {
            $e = strtolower(trim((string) $e));
            if ($e !== '')
                $csv_set[$e] = true;
        }

        $safe_l = (!empty($launch) && $launch !== 'legacy') ? preg_replace('/[^a-zA-Z0-9_]/', '', $launch) : '';
        if (empty($safe_l)) {
            wp_send_json_error('Lanzamiento no válido.');
        }

        $launch_products = json_decode(get_option('endtrack_ext_products_' . $safe_l, '[]'), true);
        if (!is_array($launch_products) || empty($launch_products)) {
            wp_send_json_error('No hay productos configurados para este lanzamiento.');
        }

        $__ext = endtrack_ext_db_config();
        $ext_host = $__ext['host'];
        $ext_user = $__ext['user'];
        $ext_pass = $__ext['pass'];
        $ext_name = $__ext['name'];

        // ── 1. Get buyer emails from external DB via pdc_users ─────────
        // user_login = original purchase email; user_email = updated email
        $platform_orig = array(); // user_login => true
        $platform_nick = array(); // user_email => user_login
        $orig_to_nick = array(); // user_login => user_email

        $login_flags = array(); // login => ['lic'=>0,'rem'=>0]
        try {
            $pdo = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user,
                $ext_pass,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)
            );
            $ph = implode(',', array_fill(0, count($launch_products), '?'));
            // Single query: buyers + pago_licencia + reembolso flags (no extra connection needed)
            $stmt = $pdo->prepare(
                "SELECT
                     LOWER(TRIM(U.user_login)) AS login,
                     LOWER(TRIM(U.user_email)) AS email_updated,
                     MAX(CASE WHEN A.estado IN ('Completado','Compra') AND (A.no_comision_afiliado = 0 OR A.no_comision_afiliado IS NULL) THEN 1 ELSE 0 END) AS is_buyer,
                     MAX(CASE WHEN A.estado IN ('Completado','Compra') AND A.pago_licencia = 1 AND (A.no_comision_afiliado = 0 OR A.no_comision_afiliado IS NULL) THEN 1 ELSE 0 END) AS has_licencia,
                     MAX(CASE WHEN A.estado = 'Reembolso' THEN 1 ELSE 0 END) AS has_reembolso
                 FROM pdc_pagos A
                 INNER JOIN pdc_users U ON A.ID_usuario = U.ID
                 WHERE A.ID_producto IN ({$ph})
                 GROUP BY U.ID"
            );
            $stmt->execute($launch_products);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $login = strtolower(trim((string) $row['login']));
                $upd = strtolower(trim((string) $row['email_updated']));
                if ($login === '' || !intval($row['is_buyer']))
                    continue;
                $platform_orig[$login] = true;
                $login_flags[$login] = array('lic' => intval($row['has_licencia']), 'rem' => intval($row['has_reembolso']));
                if ($upd !== '' && $upd !== $login) {
                    $platform_nick[$upd] = $login;
                    $orig_to_nick[$login] = $upd;
                }
            }
            $pdo = null;
        } catch (PDOException $ex) {
            wp_send_json_error('Error de conexión: ' . $ex->getMessage());
        }

        $matched = 0;
        $matched_updated = array();
        $unmatched_csv = array();

        foreach (array_keys($csv_set) as $csv_email) {
            if (isset($platform_orig[$csv_email])) {
                $matched++;
            } elseif (isset($platform_nick[$csv_email])) {
                $matched++;
                $matched_updated[] = array(
                    'csv' => $csv_email,
                    'original' => $platform_nick[$csv_email],
                );
            } else {
                $unmatched_csv[] = $csv_email;
            }
        }

        // ── Secondary fallback: emails not found via product filter ─────
        // Check directly in pdc_usuarios (and pdc_usermeta nicknames) to
        // catch buyers of products not yet configured in the plugin.
        $in_csv_not_platform = array();
        $in_csv_verify = array(); // found in platform, product not configured

        if (!empty($unmatched_csv)) {
            try {
                $pdo2 = new PDO(
                    "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                    $ext_user,
                    $ext_pass,
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)
                );
                $ph2 = implode(',', array_fill(0, count($unmatched_csv), '?'));

                // By original mail (catches buyers whose product wasn't in the config)
                $s2 = $pdo2->prepare(
                    "SELECT DISTINCT LOWER(TRIM(U.mail)) AS mail
                     FROM pdc_usuarios U
                     INNER JOIN pdc_pagos P ON P.ID_usuario = U.ID
                     WHERE P.estado IN ('Completado','Compra')
                       AND LOWER(TRIM(U.mail)) IN ({$ph2})"
                );
                $s2->execute($unmatched_csv);
                $fallback_found = array();
                while ($r = $s2->fetch(PDO::FETCH_ASSOC)) {
                    $fallback_found[strtolower(trim((string) $r['mail']))] = true;
                }

                // By pdc_users.user_login or user_email (catches both email variants)
                $unmatched_set = array_flip($unmatched_csv);
                $params_doubled = array_merge($unmatched_csv, $unmatched_csv);
                $stmt_pu = $pdo2->prepare(
                    "SELECT DISTINCT
                         LOWER(TRIM(user_login)) AS login,
                         LOWER(TRIM(user_email)) AS upd
                     FROM pdc_users
                     WHERE LOWER(TRIM(user_login)) IN ({$ph2})
                        OR LOWER(TRIM(user_email)) IN ({$ph2})"
                );
                $stmt_pu->execute($params_doubled);
                while ($r = $stmt_pu->fetch(PDO::FETCH_ASSOC)) {
                    $fbl = strtolower(trim((string) $r['login']));
                    $fbu = strtolower(trim((string) $r['upd']));
                    if (isset($unmatched_set[$fbl]))
                        $fallback_found[$fbl] = true;
                    if ($fbu !== '' && isset($unmatched_set[$fbu]))
                        $fallback_found[$fbu] = true;
                }

                $pdo2 = null;

                foreach ($unmatched_csv as $email) {
                    if (isset($fallback_found[$email])) {
                        $in_csv_verify[] = $email;   // yellow: on platform, product not in config
                    } else {
                        $in_csv_not_platform[] = $email; // red: truly not found
                    }
                }
            } catch (PDOException $ex) {
                // If fallback fails just show all as not found
                $in_csv_not_platform = $unmatched_csv;
            }
        }

        // Platform buyers not found in CSV (check both original and nickname)
        $in_platform_not_csv = array();
        $not_in_csv_raw = array(); // orig_email => display_string
        foreach (array_keys($platform_orig) as $orig) {
            $nick = isset($orig_to_nick[$orig]) ? $orig_to_nick[$orig] : '';
            if (!isset($csv_set[$orig]) && ($nick === '' || !isset($csv_set[$nick]))) {
                $display = $orig . ($nick !== '' ? ' (→ ' . $nick . ')' : '');
                $in_platform_not_csv[] = $display;
                $not_in_csv_raw[$orig] = $display;
            }
        }

        // Split not-in-CSV buyers using $login_flags built in the first query — no extra connection
        $in_platform_not_csv_licencia = array();
        $in_platform_not_csv_no_licencia = array();
        $in_platform_not_csv_no_lic_reembolso = array();
        $in_platform_not_csv_no_lic_normal = array();
        foreach ($not_in_csv_raw as $orig => $display) {
            $f = isset($login_flags[$orig]) ? $login_flags[$orig] : ['lic' => 0, 'rem' => 0];
            if ($f['lic']) {
                $in_platform_not_csv_licencia[] = $display;
            } else {
                $in_platform_not_csv_no_licencia[] = $display;
                if ($f['rem']) {
                    $in_platform_not_csv_no_lic_reembolso[] = $display;
                } else {
                    $in_platform_not_csv_no_lic_normal[] = $display;
                }
            }
        }
        sort($in_platform_not_csv_licencia);
        sort($in_platform_not_csv_no_licencia);
        sort($in_platform_not_csv_no_lic_reembolso);
        sort($in_platform_not_csv_no_lic_normal);

        // ── Fuzzy typo detection ────────────────────────────────────────
        // For remaining red emails, compare against all platform mails
        // using Levenshtein. Distance ≤ 2 → flag as possible typo.
        $possible_typos = array();
        $truly_not_platform = array();

        if (!empty($in_csv_not_platform) && count($in_csv_not_platform) <= 500) {
            $all_plat = array_keys($platform_orig);
            foreach ($orig_to_nick as $nick) {
                $all_plat[] = $nick;
            }

            foreach ($in_csv_not_platform as $csv_email) {
                $best_match = '';
                $best_dist = 3; // only care about dist 1 or 2

                foreach ($all_plat as $plat_email) {
                    if (abs(strlen($csv_email) - strlen($plat_email)) >= $best_dist)
                        continue;
                    $d = levenshtein($csv_email, $plat_email);
                    if ($d < $best_dist) {
                        $best_dist = $d;
                        $best_match = $plat_email;
                        if ($d === 1)
                            break;
                    }
                }

                if ($best_match !== '') {
                    $possible_typos[] = array(
                        'csv' => $csv_email,
                        'platform' => $best_match,
                        'dist' => $best_dist,
                    );
                } else {
                    $truly_not_platform[] = $csv_email;
                }
            }
        } else {
            $truly_not_platform = $in_csv_not_platform;
        }

        sort($truly_not_platform);
        sort($in_csv_verify);
        sort($in_platform_not_csv);
        usort($matched_updated, function ($a, $b) {
            return strcmp($a['csv'], $b['csv']);
        });
        usort($possible_typos, function ($a, $b) {
            return strcmp($a['csv'], $b['csv']);
        });

        wp_send_json_success(array(
            'csv_total' => count($csv_set),
            'platform_total' => count($platform_orig),
            'matched' => $matched,
            'matched_updated' => $matched_updated,
            'in_csv_verify' => $in_csv_verify,
            'possible_typos' => $possible_typos,
            'in_csv_not_platform' => $truly_not_platform,
            'in_platform_not_csv' => $in_platform_not_csv,
            'in_platform_not_csv_licencia' => $in_platform_not_csv_licencia,
            'in_platform_not_csv_no_licencia' => $in_platform_not_csv_no_licencia,
            'in_platform_not_csv_no_lic_reembolso' => $in_platform_not_csv_no_lic_reembolso,
            'in_platform_not_csv_no_lic_normal' => $in_platform_not_csv_no_lic_normal,
        ));
    }

    /**
     * AJAX: Rellena pais para compradores sin país asignado.
     * Fuente: pdc_accesos (IP) → ip.guide GeoIP.
     * Destino: wp_datos.pais si tiene fila, sino pdc_pagos.pais.
     */
    public function ajax_fill_pais()
    {
        check_ajax_referer('endtrack_load_section', 'nonce');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Sin permisos de administrador');

        $launch = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        if (!$launch)
            wp_send_json_error('Parámetro launch requerido');

        $safe_launch     = preg_replace('/[^a-zA-Z0-9_]/', '', $launch);
        global $wpdb;
        $table_datos = $wpdb->prefix . 'datos';

        $launch_products = json_decode(get_option('endtrack_ext_products_' . $safe_launch, '[]'), true);
        if (!is_array($launch_products) || empty($launch_products))
            wp_send_json_error('No hay productos configurados para este lanzamiento');

        $__ext = endtrack_ext_db_config();
        $ext_host = $__ext['host'];
        $ext_user = $__ext['user'];
        $ext_pass = $__ext['pass'];
        $ext_name = $__ext['name'];

        try {
            $pdo = new PDO(
                "mysql:host={$ext_host};dbname={$ext_name};charset=utf8mb4",
                $ext_user, $ext_pass,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15)
            );

            $ph = implode(',', array_fill(0, count($launch_products), '?'));

            // Paso 1: todos los compradores del lanzamiento (incluyendo pais de pdc_pagos)
            // pdc_pagos.ID_usuario → pdc_usuarios.ID  (igual que en stats-ventas-fullscreen.php)
            $stmt = $pdo->prepare("
                SELECT
                    LOWER(TRIM(U.mail))     AS mail,
                    U.ID                    AS pdc_user_id,
                    MAX(IFNULL(A.pais, '')) AS pdc_pais
                FROM pdc_pagos A
                INNER JOIN pdc_usuarios U ON A.ID_usuario = U.ID
                WHERE A.ID_producto IN ({$ph})
                  AND A.estado IN ('Completado','Compra')
                  AND (A.no_comision_afiliado = 0 OR A.no_comision_afiliado IS NULL)
                GROUP BY U.ID
            ");
            $stmt->execute($launch_products);
            $all_buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $ex) {
            wp_send_json_error('Error DB externa (compradores): ' . $ex->getMessage());
        }

        if (empty($all_buyers))
            wp_send_json_error('No se encontraron compradores en pdc_pagos para este lanzamiento');

        // Paso 2: pais actuales en wp_datos (una sola query masiva por correo)
        $all_mails = array();
        foreach ($all_buyers as $b) {
            $m = strtolower(trim((string) $b['mail']));
            if ($m !== '') $all_mails[] = $m;
        }
        $all_mails = array_unique($all_mails);

        $ph_mails  = implode(',', array_fill(0, count($all_mails), '%s'));
        $wp_rows   = $wpdb->get_results(
            $wpdb->prepare("SELECT correo, pais, ip FROM {$table_datos} WHERE correo IN ({$ph_mails})", $all_mails)
        );
        $mail_wp_data = array(); // mail => ['pais' => '', 'ip' => '']
        foreach ($wp_rows as $r)
            $mail_wp_data[strtolower(trim($r->correo))] = array(
                'pais' => trim((string)($r->pais ?? '')),
                'ip'   => trim((string)($r->ip   ?? '')),
            );

        // Paso 3: filtrar compradores sin pais en ninguna fuente
        $needs_pais   = array(); // necesitan GeoIP
        $sync_to_pdc  = array(); // tienen pais en wp_datos pero pdc_pagos.pais vacío → copiar sin GeoIP
        $uid_to_mail  = array();
        $diag_skip_wp  = 0; // saltados porque ambas fuentes tienen pais
        $diag_skip_pdc = 0; // saltados porque pdc_pagos.pais no vacío (wp vacío)
        $diag_sample_skipped_wp  = array();
        $diag_sample_skipped_pdc = array();
        foreach ($all_buyers as $b) {
            $mail     = strtolower(trim((string) $b['mail']));
            $pdc_uid  = intval($b['pdc_user_id']);
            $pdc_pais = trim((string)($b['pdc_pais'] ?? ''));
            if ($mail === '') continue;
            $wp      = isset($mail_wp_data[$mail]) ? $mail_wp_data[$mail] : null;
            $wp_pais = $wp ? $wp['pais'] : '';

            if ($wp_pais !== '' && $pdc_pais !== '') {
                // Ambas fuentes tienen país → saltar completamente
                $diag_skip_wp++;
                if (count($diag_sample_skipped_wp) < 3)
                    $diag_sample_skipped_wp[] = $mail . ' (wp_datos.pais=' . $wp_pais . ')';
                continue;
            }
            if ($wp_pais !== '' && $pdc_pais === '') {
                // wp_datos tiene país pero pdc_pagos no → sincronizar (sin GeoIP)
                $sync_to_pdc[] = array('pdc_user_id' => $pdc_uid, 'pais' => $wp_pais);
                $diag_skip_wp++;
                continue;
            }
            if ($pdc_pais !== '') {
                // pdc_pagos tiene país, wp no → el fallback del stats ya lo cubre
                $diag_skip_pdc++;
                if (count($diag_sample_skipped_pdc) < 3)
                    $diag_sample_skipped_pdc[] = $mail . ' (pdc_pagos.pais=' . $pdc_pais . ')';
                continue;
            }
            // Ninguna fuente tiene país → procesar con GeoIP
            $needs_pais[$mail]  = array(
                'pdc_user_id' => $pdc_uid,
                'in_wp_datos' => !is_null($wp),
                'wp_ip'       => $wp ? $wp['ip'] : '',
            );
            $uid_to_mail[$pdc_uid] = $mail;
        }

        // Salida anticipada solo si no hay nada que hacer
        if (empty($needs_pais) && empty($sync_to_pdc))
            wp_send_json_success(array(
                'updated' => 0, 'skipped' => 0, 'synced' => 0, 'total_sin_pais' => 0, 'results' => array(),
                'message' => 'Todos los compradores ya tienen país asignado en pdc_pagos',
                'diag' => array(
                    'total_buyers'  => count($all_buyers),
                    'in_wp_datos'   => count($mail_wp_data),
                    'skip_wp_pais'  => $diag_skip_wp,
                    'skip_pdc_pais' => $diag_skip_pdc,
                    'sample_wp'     => $diag_sample_skipped_wp,
                    'sample_pdc'    => $diag_sample_skipped_pdc,
                ),
            ));

        // Paso 4: Preparar UPDATE pdc_pagos + IPs para GeoIP
        $user_to_ip = array();
        $uid_ip_src = array();
        try {
            // Asegurar que pdc_pagos.pais existe
            if (empty($pdo->query("SHOW COLUMNS FROM pdc_pagos LIKE 'pais'")->fetchAll()))
                $pdo->exec("ALTER TABLE pdc_pagos ADD COLUMN pais varchar(30) DEFAULT NULL");

            $ph_prods     = implode(',', array_fill(0, count($launch_products), '?'));
            $stmt_upd_pdc = $pdo->prepare(
                "UPDATE pdc_pagos SET pais = ? WHERE ID_usuario = ? AND ID_producto IN ({$ph_prods})"
            );

            // Paso 4a: Sincronizar wp_datos.pais → pdc_pagos.pais (sin GeoIP, puede ser ~2.000 rows)
            $synced = 0;
            foreach ($sync_to_pdc as $entry) {
                $stmt_upd_pdc->execute(array_merge(array($entry['pais'], $entry['pdc_user_id']), $launch_products));
                $synced++;
            }

            // Paso 4b: IPs para compradores que necesitan GeoIP
            if (!empty($needs_pais)) {
                $pdc_ids = array_unique(array_values(array_map(function($v){ return $v['pdc_user_id']; }, $needs_pais)));
                $ph_ids  = implode(',', array_fill(0, count($pdc_ids), '?'));
                $stmt_ip = $pdo->prepare("
                    SELECT ID_alumno, IP
                    FROM pdc_accesos
                    WHERE ID_alumno IN ({$ph_ids})
                      AND IP IS NOT NULL AND IP != ''
                    ORDER BY hora DESC
                ");
                $stmt_ip->execute($pdc_ids);
                foreach ($stmt_ip->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $uid = intval($row['ID_alumno']);
                    if (!isset($user_to_ip[$uid])) {
                        $user_to_ip[$uid] = trim($row['IP']);
                        $uid_ip_src[$uid] = 'pdc_accesos';
                    }
                }

                // Tercera fuente de IP: pdc_usermeta session_tokens
                $still_no_ip = array_values(array_filter($pdc_ids, function($uid) use ($user_to_ip) {
                    return !isset($user_to_ip[$uid]);
                }));
                if (!empty($still_no_ip)) {
                    $ph_no_ip  = implode(',', array_fill(0, count($still_no_ip), '?'));
                    $stmt_meta = $pdo->prepare("
                        SELECT user_id, meta_value
                        FROM pdc_usermeta
                        WHERE user_id IN ({$ph_no_ip})
                          AND meta_key = 'session_tokens'
                    ");
                    $stmt_meta->execute($still_no_ip);
                    foreach ($stmt_meta->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $uid = intval($row['user_id']);
                        if (isset($user_to_ip[$uid])) continue;
                        if (preg_match('/s:2:"ip";s:\d+:"([^"]+)"/', $row['meta_value'], $m)) {
                            $user_to_ip[$uid] = $m[1];
                            $uid_ip_src[$uid] = 'session_tokens';
                        }
                    }
                }
            }
        } catch (PDOException $ex) {
            wp_send_json_error('Error Paso 4: ' . $ex->getMessage());
        }

        // Paso 5: GeoIP + actualizar
        @set_time_limit(600);
        $updated  = 0;
        $skipped  = 0;
        $results  = array();
        $src_counts = array('wp_datos' => 0, 'pdc_accesos' => 0, 'session_tokens' => 0, 'sin_ip' => 0, 'sin_geoip' => 0);

        foreach ($needs_pais as $mail => $data) {
            $pdc_uid     = $data['pdc_user_id'];
            $in_wp_datos = $data['in_wp_datos'];

            // Prioridad: IP de wp_datos → pdc_accesos → pdc_usermeta session_tokens
            $ip     = '';
            $ip_src = 'sin_ip';
            if (!empty($data['wp_ip'])) {
                $ip     = $data['wp_ip'];
                $ip_src = 'wp_datos';
            } elseif (isset($user_to_ip[$pdc_uid])) {
                $ip     = $user_to_ip[$pdc_uid];
                $ip_src = isset($uid_ip_src[$pdc_uid]) ? $uid_ip_src[$pdc_uid] : 'pdc_accesos';
            }

            if ($ip === '') {
                $src_counts['sin_ip']++;
                $results[] = array('mail' => $mail, 'status' => 'sin_ip');
                $skipped++;
                continue;
            }
            $src_counts[$ip_src]++;
            $geo_key  = 'endtrack_geo_' . md5($ip);
            $cached   = get_transient($geo_key);
            if ($cached !== false) {
                $country_code = $cached['country'];
                $city         = $cached['city'];
            } else {
                $country_code = '';
                $city         = '';
                $details      = @json_decode(wp_remote_retrieve_body(wp_remote_get("https://ip.guide/{$ip}", array('timeout' => 3))));
                if (!empty($details)) {
                    $country_code = isset($details->location->country) ? $details->location->country : '';
                    $city         = isset($details->location->city)    ? $details->location->city    : '';
                }
                set_transient($geo_key, array('country' => $country_code, 'city' => $city), DAY_IN_SECONDS);
            }

            if (empty($country_code)) {
                $src_counts['sin_geoip']++;
                if (count($results) < 300) $results[] = array('mail' => $mail, 'ip' => $ip, 'ip_src' => $ip_src, 'status' => 'sin_pais');
                $skipped++;
                continue;
            }

            if ($in_wp_datos) {
                $wpdb->update($table_datos, array('pais' => $country_code), array('correo' => $mail));
                if (count($results) < 300) $results[] = array('mail' => $mail, 'ip' => $ip, 'ip_src' => $ip_src, 'pais' => $country_code, 'status' => 'wp_datos');
            } else {
                try {
                    $stmt_upd_pdc->execute(array_merge(array($country_code, $pdc_uid), $launch_products));
                    if (count($results) < 300) $results[] = array('mail' => $mail, 'ip' => $ip, 'ip_src' => $ip_src, 'pais' => $country_code, 'status' => 'pdc_pagos');
                } catch (PDOException $ex) {
                    if (count($results) < 300) $results[] = array('mail' => $mail, 'ip' => $ip, 'ip_src' => $ip_src, 'status' => 'error_db');
                    $skipped++;
                    continue;
                }
            }
            $updated++;
        }
        $pdo = null;

        wp_send_json_success(array(
            'updated'        => $updated,
            'synced'         => $synced,
            'skipped'        => $skipped,
            'total_sin_pais' => count($needs_pais),
            'sources'        => $src_counts,
            'results'        => $results,
            'diag'           => array(
                'total_buyers'  => count($all_buyers),
                'in_wp_datos'   => count($mail_wp_data),
                'skip_wp_pais'  => $diag_skip_wp,
                'skip_pdc_pais' => $diag_skip_pdc,
                'sample_wp'     => $diag_sample_skipped_wp,
                'sample_pdc'    => $diag_sample_skipped_pdc,
            ),
        ));
    }

    /**
     * AJAX: Rellena source/medium/campaign/afiliado en wp_datos
     * para filas con source = "No tiene fuente",
     * buscando su IP en wp_visitas para obtener los UTM reales.
     */
    public function ajax_fix_no_source()
    {
        if (!current_user_can('manage_options') && !current_user_can('view_endtrack_stats')) {
            wp_send_json_error('No tienes permisos.');
            return;
        }
        check_ajax_referer('endtrack_load_section', 'nonce');
        set_time_limit(300);

        global $wpdb;
        $table_datos   = $wpdb->prefix . 'datos';
        $table_visitas = $wpdb->prefix . 'visitas';

        $launch    = isset($_POST['launch']) ? sanitize_text_field($_POST['launch']) : '';
        $safe_l    = (!empty($launch) && $launch !== 'legacy') ? preg_replace('/[^a-zA-Z0-9_]/', '', $launch) : '';
        $col_pr    = $safe_l ? "primer_reg_{$safe_l}" : 'primer_reg';

        // 1. Filas en wp_datos con source = "No tiene fuente" y con IP
        $rows = $wpdb->get_results(
            "SELECT id, correo, ip
             FROM {$table_datos}
             WHERE source = 'No tiene fuente'
               AND ip != '' AND ip IS NOT NULL
               AND {$col_pr} IN (1, 2)
             ORDER BY fecha DESC
             LIMIT 5000",
            ARRAY_A
        );

        if (empty($rows)) {
            wp_send_json_success(array('updated' => 0, 'skipped' => 0, 'sin_visita' => 0, 'total' => 0));
            return;
        }

        // Agrupar por IP única → así hacemos una sola consulta a wp_visitas por IP
        $ip_to_rows = array();
        foreach ($rows as $r) {
            $ip = trim($r['ip']);
            if ($ip === '') continue;
            if (!isset($ip_to_rows[$ip])) $ip_to_rows[$ip] = array();
            $ip_to_rows[$ip][] = $r;
        }

        $all_ips = array_keys($ip_to_rows);
        $ph      = implode(',', array_fill(0, count($all_ips), '%s'));

        // 2. Buscar en wp_visitas: para cada IP el UTM más reciente que no sea "No tiene fuente"
        //    Subquery para garantizar la fila más reciente por IP (GROUP BY solo no lo garantiza)
        $visitas = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.ip, v.ref, v.ref_s, v.ref_m, v.ref_c
                 FROM {$table_visitas} v
                 INNER JOIN (
                     SELECT ip, MAX(fecha) AS max_fecha
                     FROM {$table_visitas}
                     WHERE ip IN ({$ph})
                       AND ref_s != '' AND ref_s IS NOT NULL
                       AND ref_s != 'No tiene fuente'
                     GROUP BY ip
                 ) latest ON v.ip = latest.ip AND v.fecha = latest.max_fecha
                 WHERE v.ref_s != '' AND v.ref_s IS NOT NULL
                   AND v.ref_s != 'No tiene fuente'",
                ...$all_ips
            ),
            ARRAY_A
        );

        // Indexar por IP
        $ip_utm = array();
        foreach ($visitas as $v) {
            $ip = $v['ip'];
            if (!isset($ip_utm[$ip])) {
                $ip_utm[$ip] = $v; // primera (más reciente) es la que se queda
            }
        }

        // 3. Actualizar wp_datos
        $updated    = 0;
        $sin_visita = 0;
        $sample     = array();

        foreach ($ip_to_rows as $ip => $datos_rows) {
            if (!isset($ip_utm[$ip])) {
                $sin_visita += count($datos_rows);
                continue;
            }
            $utm = $ip_utm[$ip];
            foreach ($datos_rows as $dr) {
                $result = $wpdb->update(
                    $table_datos,
                    array(
                        'afiliado' => $utm['ref'],
                        'source'   => $utm['ref_s'],
                        'medium'   => $utm['ref_m'],
                        'campaign' => $utm['ref_c'],
                    ),
                    array('id' => intval($dr['id'])),
                    array('%s', '%s', '%s', '%s'),
                    array('%d')
                );
                if ($result !== false) {
                    $updated++;
                    if (count($sample) < 50) {
                        $sample[] = array(
                            'correo'   => $dr['correo'],
                            'ip'       => $ip,
                            'source'   => $utm['ref_s'],
                            'medium'   => $utm['ref_m'],
                            'campaign' => $utm['ref_c'],
                            'afiliado' => $utm['ref'],
                        );
                    }
                }
            }
        }

        $skipped = count($rows) - $updated - $sin_visita;

        wp_send_json_success(array(
            'total'       => count($rows),
            'updated'     => $updated,
            'sin_visita'  => $sin_visita,
            'skipped'     => $skipped,
            'sample'      => $sample,
        ));
    }
}
