<?php
defined( 'ABSPATH' ) || exit;

class IVB_Aviso_Stock_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ivb_as_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_ivb_as_csv', array( __CLASS__, 'csv' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Avisos de stock', 'Avisos de stock', 'manage_woocommerce', 'ivb-aviso-stock', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		global $wpdb;
		$table   = IVB_Aviso_Stock_Core::table();
		$summary = $wpdb->get_results( "SELECT product_id, COUNT(*) AS pending FROM {$table} WHERE notified IS NULL GROUP BY product_id ORDER BY pending DESC, product_id DESC LIMIT 300" );
		$filter  = absint( $_GET['product'] ?? 0 );
		$where   = $filter ? $wpdb->prepare( ' AND product_id=%d', $filter ) : '';
		$pending = $wpdb->get_results( "SELECT * FROM {$table} WHERE notified IS NULL{$where} ORDER BY created DESC LIMIT 500" );
		$sent    = $wpdb->get_results( "SELECT * FROM {$table} WHERE notified IS NOT NULL{$where} ORDER BY notified DESC LIMIT 500" );
		$base    = admin_url( 'admin.php?page=ivb-aviso-stock' );
		?>
		<div class="wrap">
			<h1>Avisos de stock</h1>
			<p>Clientes que han pedido un aviso cuando un producto agotado vuelva a tener stock. Al enviarse el email, el aviso sale de «Esperando» y queda en el historial.</p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ivb_as_csv' ), 'ivb_as_csv' ) ); ?>">Exportar CSV (todo)</a></p>

			<h2>Esperando por producto</h2>
			<table class="widefat striped" style="max-width:700px">
				<thead><tr><th>Producto</th><th>Clientes esperando</th></tr></thead>
				<tbody>
				<?php foreach ( $summary as $s ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'product', $s->product_id, $base ) ); ?>"><?php echo esc_html( self::product_name( $s->product_id ) ); ?></a></td>
						<td><?php echo (int) $s->pending; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $summary ) : ?><tr><td colspan="2">Nadie esperando ahora mismo.</td></tr><?php endif; ?>
				</tbody>
			</table>

			<h2>Esperando aviso<?php echo $filter ? ' · ' . esc_html( self::product_name( $filter ) ) . ' <a href="' . esc_url( $base ) . '" style="font-size:13px">ver todos</a>' : ''; ?></h2>
			<table class="widefat striped">
				<thead><tr><th>Producto</th><th>Cliente</th><th>Email</th><th>Solicitado</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $pending as $r ) : ?>
					<tr>
						<td><?php echo esc_html( self::product_name( $r->product_id ) ); ?></td>
						<td><?php echo self::user_link( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $r->email ); ?></td>
						<td><?php echo esc_html( $r->created ); ?></td>
						<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ivb_as_delete&id=' . $r->id ), 'ivb_as_delete_' . $r->id ) ); ?>" onclick="return confirm('¿Eliminar este aviso sin enviarlo?')">Eliminar</a></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $pending ) : ?><tr><td colspan="5">Sin avisos pendientes.</td></tr><?php endif; ?>
				</tbody>
			</table>

			<h2>Historial de avisos enviados</h2>
			<table class="widefat striped">
				<thead><tr><th>Producto</th><th>Cliente</th><th>Email</th><th>Solicitado</th><th>Aviso enviado</th></tr></thead>
				<tbody>
				<?php foreach ( $sent as $r ) : ?>
					<tr>
						<td><?php echo esc_html( self::product_name( $r->product_id ) ); ?></td>
						<td><?php echo self::user_link( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $r->email ); ?></td>
						<td><?php echo esc_html( $r->created ); ?></td>
						<td><?php echo esc_html( $r->notified ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $sent ) : ?><tr><td colspan="5">Todavía no se ha enviado ningún aviso.</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	protected static function user_link( $row ) {
		$user = $row->user_id ? get_userdata( $row->user_id ) : false;
		if ( ! $user ) {
			return '—';
		}
		$name = trim( $user->first_name . ' ' . $user->last_name );
		$name = $name ? $name : $user->display_name;
		return '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $name ) . '</a>';
	}

	protected static function product_name( $id ) {
		$p = wc_get_product( $id );
		return $p ? wp_strip_all_tags( $p->get_formatted_name() ) : '#' . $id . ' (eliminado)';
	}

	public static function delete() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'ivb_as_delete_' . $id );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			global $wpdb;
			$wpdb->delete( IVB_Aviso_Stock_Core::table(), array( 'id' => $id ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ivb-aviso-stock' ) );
		exit;
	}

	public static function csv() {
		check_admin_referer( 'ivb_as_csv' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . IVB_Aviso_Stock_Core::table() . ' ORDER BY created DESC' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=avisos-stock-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Producto ID', 'Producto', 'Email', 'Solicitado', 'Avisado' ), ';' ); // vacío = sigue esperando
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r->product_id, self::product_name( $r->product_id ), $r->email, $r->created, $r->notified ), ';' );
		}
		exit;
	}
}
