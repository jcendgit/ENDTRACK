<?php

class ENDTrack_Activator
{

	public function activate()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'datos';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			fecha datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			correo varchar(100) NOT NULL,
			afiliado varchar(100) DEFAULT '',
			nombre varchar(100) DEFAULT '',
			correo_primer_reg varchar(100) DEFAULT '',
			cookie varchar(100) DEFAULT '',
			term varchar(100) DEFAULT '',
			content varchar(100) DEFAULT '',
			placement varchar(100) DEFAULT '',
			medium varchar(100) DEFAULT '',
			tipo varchar(100) DEFAULT '',
			source varchar(100) DEFAULT '',
			campaign varchar(100) DEFAULT '',
			ip varchar(100) DEFAULT '',
			session_id varchar(100) DEFAULT '',
			primer_reg tinyint(1) DEFAULT 0,
			url_anterior text DEFAULT '',
			url_actual text DEFAULT '',
			ciudad varchar(100) DEFAULT '',
			pais varchar(100) DEFAULT '',
			id_pag varchar(100) DEFAULT '',
			thrivecart_hash varchar(255) DEFAULT '',
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		// Visitas Table
		$table_visitas = $wpdb->prefix . 'visitas';
		$sql_visitas = "CREATE TABLE $table_visitas (
			id int(11) NOT NULL AUTO_INCREMENT,
			fecha timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip varchar(45) DEFAULT NULL,
			session_id varchar(255) DEFAULT NULL,
			url_actual text,
			url_anterior text,
			pais varchar(100) DEFAULT NULL,
			ciudad varchar(100) DEFAULT NULL,
			id_pag int(11) DEFAULT NULL,
			ref varchar(255) DEFAULT NULL,
			ref_s varchar(255) DEFAULT NULL,
			ref_m varchar(255) DEFAULT NULL,
			ref_c varchar(255) DEFAULT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta($sql_visitas);

		// Payments Table
		$table_payments = $wpdb->prefix . 'endtrack_payments';
		$sql_payments = "CREATE TABLE $table_payments (
			id int(11) NOT NULL AUTO_INCREMENT,
			fecha datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			afiliado_id int(11) NOT NULL,
			monto decimal(10,2) NOT NULL,
			referencia varchar(255) DEFAULT '',
			notas text,
			lanzamiento varchar(100) DEFAULT 'legacy',
			PRIMARY KEY  (id)
		) $charset_collate;";
		dbDelta($sql_payments);

		// Initialize launches option if not exists
		if (!get_option('endtrack_launches')) {
			add_option('endtrack_launches', array());
		}

		// Auto-create pages
		$pages = array(
			'endtrack-panel-afiliado' => array(
				'title' => 'Panel de Afiliados',
				'content' => '[endtrack_affiliate_panel]'
			),
			'endtrack-panel-admin-afiliado' => array(
				'title' => 'Panel Admin Afiliados',
				'content' => '[endtrack_admin_panel]'
			)
		);

		foreach ($pages as $slug => $page_data) {
			$page_check = get_page_by_path($slug);
			if (!$page_check) {
				$new_page = array(
					'post_type' => 'page',
					'post_title' => $page_data['title'],
					'post_content' => $page_data['content'],
					'post_status' => 'publish',
					'post_author' => 1,
					'post_name' => $slug
				);
				wp_insert_post($new_page);
			}
		}

		// Auto-create categories
		$categories = array(
			'registro' => 'Registro',
			'venta' => 'Venta',
			'gracias' => 'Gracias',
			'gracias-registro' => 'Gracias Registro'
		);

		foreach ($categories as $slug => $name) {
			if (!get_term_by('slug', $slug, 'category')) {
				wp_create_category($name);
			}
		}
	}
}
